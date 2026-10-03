<?php

declare(strict_types=1);

namespace LaraOwlLite;

use Closure;
use Throwable;

/**
 * LaraOwl for plain PHP sites.
 *
 *     LaraOwlLite::start();                    // first thing every page loads
 *     LaraOwlLite::route('/event/{slug}', ['slug' => $slug]);
 *     LaraOwlLite::report($caught);            // an exception you handled
 *     LaraOwlLite::log('error', 'Ticket email failed', ['booking' => 12]);
 *
 * One request becomes one batch: the request itself, its exceptions and its
 * warnings. It is sent from the very last shutdown function, after the
 * response has been handed to the visitor.
 */
final class LaraOwlLite
{
    public const VERSION = '0.1.0';

    private const MAX_LOGS = 50;

    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private static ?Config $config = null;

    /** @var Closure(list<array<string, mixed>>): mixed */
    private static ?Closure $sender = null;

    private static string $traceId = '';

    private static ?string $route = null;

    /** @var array<string, string> */
    private static array $routeParams = [];

    private static ?string $user = null;

    /** @var list<array<string, mixed>> */
    private static array $records = [];

    private static int $exceptions = 0;

    private static int $logs = 0;

    /** @var array<string, true> */
    private static array $seenWarnings = [];

    private static bool $uncaught = false;

    private static ?string $exceptionPreview = null;

    /** @var callable|null */
    private static $previousExceptionHandler = null;

    /** @var callable|null */
    private static $previousErrorHandler = null;

    /**
     * Starts monitoring this request. Does nothing on the command line, a
     * second time, or when LARAOWL_TOKEN / LARAOWL_SERVER_URL aren't set or
     * LARAOWL_ENABLED is false.
     *
     * @param  array<string, mixed>  $options  see Config::resolve()
     */
    public static function start(array $options = []): void
    {
        if (self::$config !== null || PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        try {
            $config = Config::resolve($options);
        } catch (Throwable) {
            return;
        }

        if (! $config->enabled) {
            return;
        }

        self::boot($config, static fn (array $records) => Transport::send($config, $records));

        self::$previousExceptionHandler = set_exception_handler([self::class, 'handleException']);
        self::$previousErrorHandler = set_error_handler([self::class, 'handleError']);

        // Registered from inside shutdown, so it runs after every shutdown
        // function the site registers during the request.
        register_shutdown_function(static fn () => register_shutdown_function([self::class, 'finish']));
    }

    /**
     * Names the route this request matched, e.g. '/ticket/{code}'. Parameters
     * whose name looks secret (code, token, …) are redacted from the URL.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function route(string $pattern, array $params = []): void
    {
        if (self::$config === null) {
            return;
        }

        self::$route = '/'.ltrim($pattern, '/');
        self::$routeParams = [];

        foreach ($params as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                self::$routeParams[$name] = (string) $value;
            }
        }
    }

    /**
     * Who is signed in, if anyone: shown on the request and its errors.
     */
    public static function user(string|int|null $id): void
    {
        self::$user = $id === null ? null : (string) $id;
    }

    /**
     * Records an exception the site caught and handled itself.
     */
    public static function report(Throwable $e): void
    {
        if (self::$config === null) {
            return;
        }

        self::recordException($e, true);
    }

    /**
     * Records a log message: debug, info, notice, warning, error, critical,
     * alert or emergency.
     *
     * @param  array<string, mixed>  $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (self::$config === null || self::$logs >= self::MAX_LOGS) {
            return;
        }

        $level = strtolower($level);
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        self::$logs++;
        self::$records[] = Records::log(in_array($level, $levels, true) ? $level : 'info', $message, $context, self::context());
    }

    public static function enabled(): bool
    {
        return self::$config !== null;
    }

    /**
     * @internal Uncaught exceptions: recorded, then handed on unchanged.
     */
    public static function handleException(Throwable $e): void
    {
        self::$uncaught = true;
        self::recordException($e, false);

        if (self::$previousExceptionHandler !== null) {
            (self::$previousExceptionHandler)($e);

            return;
        }

        // PHP's own handling: logged as uncaught, answered with a 500.
        throw $e;
    }

    /**
     * @internal Warnings and notices: recorded once per place, then passed
     * on to the site's previous handler or PHP's own.
     */
    public static function handleError(int $level, string $message, string $file = '', int $line = 0): mixed
    {
        try {
            if (error_reporting() & $level) {
                self::recordWarning($level, $message, $file, $line);
            }
        } catch (Throwable) {
            //
        }

        if (self::$previousErrorHandler !== null) {
            return (self::$previousErrorHandler)($level, $message, $file, $line);
        }

        return false;
    }

    /**
     * @internal The last shutdown function: builds the batch, lets the
     * visitor go, then sends.
     */
    public static function finish(): void
    {
        if (self::$config === null || self::$sender === null) {
            return;
        }

        try {
            $records = self::collect($_SERVER);

            if ($records === []) {
                return;
            }

            self::releaseVisitor();
            (self::$sender)($records);
        } catch (Throwable) {
            // Monitoring never breaks the site.
        }
    }

    /**
     * @internal Everything this request produced, as one batch.
     *
     * @param  array<string, mixed>  $server
     * @return list<array<string, mixed>>
     */
    public static function collect(array $server): array
    {
        if (self::$config === null) {
            return [];
        }

        $start = (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $duration = (int) round(max(0.0, microtime(true) - $start) * 1_000_000);
        $status = self::status();

        $error = error_get_last();

        if ($error !== null && ($error['type'] & self::FATAL) && ! (self::$uncaught && str_starts_with($error['message'], 'Uncaught '))) {
            self::$exceptions++;
            self::$exceptionPreview ??= Records::text($error['message']);
            self::$records[] = Records::fatal($error, self::context());
        }

        if (self::$uncaught || ($error !== null && ($error['type'] & self::FATAL))) {
            $status = max($status, 500);
        }

        $records = self::$records;

        if (self::shouldRecordRequest($server)) {
            $context = self::context($server) + [
                'url' => Records::url($server, self::$routeParams, self::$config->redact),
                'script' => Records::relativePath((string) ($server['SCRIPT_FILENAME'] ?? ''), self::$config->basePath),
                'duration' => $duration,
                'status' => $status,
                'response_size' => self::responseSize(),
                'exceptions' => self::$exceptions,
                'logs' => self::$logs,
                'peak_memory' => memory_get_peak_usage(),
                'exception_preview' => self::$exceptionPreview,
            ];

            array_unshift($records, Records::request($server, $context));
        }

        return $records;
    }

    /**
     * @internal For tests: starts without handlers, sending through $sender.
     *
     * @param  Closure(list<array<string, mixed>>): mixed  $sender
     */
    public static function boot(Config $config, Closure $sender): void
    {
        self::reset();
        self::$config = $config;
        self::$sender = $sender;
        self::$traceId = self::uuid();
    }

    /**
     * @internal For tests: forgets the current request.
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$sender = null;
        self::$traceId = '';
        self::$route = null;
        self::$routeParams = [];
        self::$user = null;
        self::$records = [];
        self::$exceptions = 0;
        self::$logs = 0;
        self::$seenWarnings = [];
        self::$uncaught = false;
        self::$exceptionPreview = null;
    }

    private static function recordException(Throwable $e, bool $handled): void
    {
        self::$exceptions++;
        self::$exceptionPreview ??= Records::text($e::class.': '.$e->getMessage());
        self::$records[] = Records::exception($e, $handled, self::context());
    }

    private static function recordWarning(int $level, string $message, string $file, int $line): void
    {
        $deprecation = $level & (E_DEPRECATED | E_USER_DEPRECATED);

        if (! self::$config->captureWarnings || ($deprecation && ! self::$config->captureDeprecations)) {
            return;
        }

        $key = $level.'|'.$file.'|'.$line;

        if (isset(self::$seenWarnings[$key])) {
            return;
        }

        self::$seenWarnings[$key] = true;
        $where = Records::relativePath($file, self::$config->basePath);

        self::log(match (true) {
            (bool) ($level & (E_USER_ERROR | E_RECOVERABLE_ERROR)) => 'error',
            (bool) ($level & (E_NOTICE | E_USER_NOTICE)) => 'notice',
            (bool) $deprecation => 'info',
            default => 'warning',
        }, "{$message} ({$where}:{$line})", ['file' => $where, 'line' => $line, 'php_level' => $level]);
    }

    /**
     * @param  array<string, mixed>|null  $server
     * @return array<string, mixed>
     */
    private static function context(?array $server = null): array
    {
        $server ??= $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $route = self::$route ?? Records::normalizePath((string) ($server['REQUEST_URI'] ?? '/'));

        return [
            'trace_id' => self::$traceId,
            'deploy' => self::deploy(),
            'server' => gethostname() ?: null,
            'user' => self::$user,
            'route' => $route,
            'preview' => "{$method} {$route}",
            'base_path' => self::$config?->basePath ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private static function shouldRecordRequest(array $server): bool
    {
        if (! isset($server['REQUEST_METHOD'])) {
            return false;
        }

        $agent = (string) ($server['HTTP_USER_AGENT'] ?? '');

        foreach (self::$config->ignoreUserAgents as $ignored) {
            if ($ignored !== '' && stripos($agent, $ignored) !== false) {
                return false;
            }
        }

        $path = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        foreach (self::$config->ignorePaths as $ignored) {
            if ($ignored !== '' && str_starts_with($path, $ignored)) {
                return false;
            }
        }

        return self::$config->sampleRate >= 1.0 || mt_rand() / mt_getrandmax() < self::$config->sampleRate;
    }

    private static function status(): int
    {
        $status = http_response_code();

        return is_int($status) && $status > 0 ? $status : 200;
    }

    private static function responseSize(): ?int
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-length:') === 0) {
                return (int) trim(substr($header, 15));
            }
        }

        return null;
    }

    /**
     * The Forge release the code runs from (".../releases/<id>/"), if any.
     */
    private static function deploy(): ?string
    {
        return preg_match('#/releases/([^/]+)#', self::$config?->basePath ?? '', $match) === 1 ? $match[1] : null;
    }

    /**
     * Hands the finished response to the visitor before anything is sent.
     */
    private static function releaseVisitor(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
