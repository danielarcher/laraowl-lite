<?php

declare(strict_types=1);

namespace LaraOwlLite;

use Throwable;

/**
 * Builds records in the shape the Laravel client sends, so LaraOwl's
 * dashboards, issues, logs and security analysis treat them the same.
 *
 * @internal
 */
final class Records
{
    private const MAX_TEXT = 10_000;

    private const MAX_FRAMES = 50;

    private const CODE_FRAMES = 10;

    private const CODE_CONTEXT = 5;

    private const HEADERS = ['HTTP_USER_AGENT' => 'user-agent', 'HTTP_REFERER' => 'referer', 'HTTP_ACCEPT_LANGUAGE' => 'accept-language', 'HTTP_HOST' => 'host'];

    /**
     * @param  array<string, mixed>  $server  $_SERVER of the request
     * @param  array<string, mixed>  $context  see LaraOwlLite::context()
     * @return array<string, mixed>
     */
    public static function request(array $server, array $context): array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $route = $context['route'];

        return [
            'v' => 1,
            't' => 'request',
            'timestamp' => (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'deploy' => $context['deploy'],
            'server' => $context['server'],
            '_group' => hash('xxh128', "{$method},,{$route}"),
            'trace_id' => $context['trace_id'],
            'user' => $context['user'],
            'method' => $method,
            'url' => $context['url'],
            'route_name' => '',
            'route_methods' => [$method],
            'route_domain' => '',
            'route_path' => $route,
            'route_action' => $context['script'],
            'ip' => (string) ($server['REMOTE_ADDR'] ?? ''),
            'duration' => $context['duration'],
            'status_code' => $context['status'],
            'request_size' => (int) ($server['CONTENT_LENGTH'] ?? 0),
            'response_size' => $context['response_size'],
            'exceptions' => $context['exceptions'],
            'logs' => $context['logs'],
            'queries' => 0,
            'peak_memory_usage' => $context['peak_memory'],
            'exception_preview' => $context['exception_preview'],
            'headers' => self::json(self::headers($server)),
            'client' => 'laraowl-lite/'.LaraOwlLite::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function exception(Throwable $e, bool $handled, array $context): array
    {
        $file = self::relativePath($e->getFile(), $context['base_path']);
        $code = (string) $e->getCode();

        return [
            ...self::execution('exception', 3, $context),
            '_group' => hash('xxh128', $e::class.','.$code.','.$file.','.$e->getLine()),
            'class' => $e::class,
            'file' => $file,
            'line' => $e->getLine(),
            'message' => self::text($e->getMessage()),
            'code' => $code,
            'trace' => self::trace($e, $context['base_path']),
            'handled' => $handled,
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * A fatal error (out of memory, time limit, parse error) from
     * error_get_last(), which no exception handler ever sees.
     *
     * @param  array{type: int, message: string, file: string, line: int}  $error
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function fatal(array $error, array $context): array
    {
        $file = self::relativePath($error['file'], $context['base_path']);
        $message = self::text($error['message']);

        return [
            ...self::execution('exception', 3, $context),
            '_group' => hash('xxh128', 'FatalError,'.$error['type'].','.$file.','.$error['line']),
            'class' => 'FatalError',
            'file' => $file,
            'line' => $error['line'],
            'message' => $message,
            'code' => (string) $error['type'],
            'trace' => self::json([[
                'file' => $file.':'.$error['line'],
                'source' => '',
                'code' => self::sourceCode($error['file'], $error['line'], $context['base_path']),
            ]]),
            'handled' => false,
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function log(string $level, string $message, array $data, array $context): array
    {
        return [
            ...self::execution('log', 1, $context),
            'level' => $level,
            'message' => self::text($message),
            'context' => self::json((object) $data),
            'extra' => '{}',
        ];
    }

    /**
     * The full URL with secret-looking query values and route parameters
     * replaced, so ticket codes and tokens never leave the site.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, string>  $params  route parameters, by name
     * @param  list<string>  $redact
     */
    public static function url(array $server, array $params, array $redact): string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, null);

        foreach ($params as $name => $value) {
            if ($value !== '' && self::isSecret((string) $name, $redact)) {
                $path = str_replace('/'.rawurlencode($value), '/[redacted]', str_replace('/'.$value, '/[redacted]', $path));
            }
        }

        if ($query !== null && $query !== '') {
            $pairs = array_map(static function (string $pair) use ($redact): string {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

                return $value !== null && self::isSecret(urldecode($key), $redact) ? "{$key}=[redacted]" : $pair;
            }, explode('&', $query));

            $path .= '?'.implode('&', $pairs);
        }

        return self::origin($server).$path;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public static function origin(array $server): string
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') || (string) ($server['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        return "{$scheme}://{$host}";
    }

    /**
     * Groups requests to the same page: ids, hashes and long tokens in the
     * path become placeholders, so /event/12 and /event/13 are one route.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        $segments = array_map(static function (string $segment): string {
            return match (true) {
                $segment === '' => $segment,
                ctype_digit($segment) => '{id}',
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1 => '{uuid}',
                preg_match('/^[0-9a-f]{16,}$/i', $segment) === 1 => '{hash}',
                strlen($segment) >= 20 && preg_match('/\d/', $segment) === 1 && preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1 => '{token}',
                default => $segment,
            };
        }, explode('/', $path));

        return implode('/', $segments);
    }

    public static function relativePath(string $file, string $basePath): string
    {
        $prefix = rtrim($basePath, '/').'/';

        return $basePath !== '' && str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    /**
     * @param  list<string>  $redact
     */
    public static function isSecret(string $name, array $redact): bool
    {
        $name = strtolower($name);

        foreach ($redact as $fragment) {
            if ($fragment !== '' && str_contains($name, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The trace as the Laravel client serializes it: the throw site first,
     * then each frame as "file:line" and "Class->method(types)", with a few
     * lines of the site's own code around the first frames.
     */
    public static function trace(Throwable $e, string $basePath): string
    {
        $codeFrames = 0;
        $code = static function (?string $file, ?int $line) use ($basePath, &$codeFrames): ?object {
            if ($codeFrames >= self::CODE_FRAMES || $file === null || $line === null) {
                return null;
            }

            $snippet = self::sourceCode($file, $line, $basePath);

            if ($snippet !== null) {
                $codeFrames++;
            }

            return $snippet;
        };

        $frames = [[
            'file' => self::relativePath($e->getFile(), $basePath).':'.$e->getLine(),
            'source' => '',
            'code' => $code($e->getFile(), $e->getLine()),
        ]];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $file = isset($frame['file']) ? self::relativePath($frame['file'], $basePath) : '[internal function]';

            if (isset($frame['line'])) {
                $file .= ':'.$frame['line'];
            }

            $args = array_map(static fn (mixed $argument): string => match (true) {
                is_object($argument) => $argument::class,
                $argument === null => 'null',
                is_bool($argument) => 'bool',
                is_int($argument) => 'int',
                is_float($argument) => 'float',
                is_array($argument) => 'array',
                is_string($argument) => 'string',
                default => get_debug_type($argument),
            }, $frame['args'] ?? []);

            $frames[] = [
                'file' => $file,
                'source' => ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'('.implode(', ', $args).')',
                'code' => $code($frame['file'] ?? null, $frame['line'] ?? null),
            ];
        }

        return self::json($frames);
    }

    /**
     * Lines around $line, keyed by line number, for the site's own files only.
     */
    public static function sourceCode(string $file, int $line, string $basePath): ?object
    {
        $relative = self::relativePath($file, $basePath);

        if ($relative === $file || str_starts_with($relative, 'vendor/') || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $code = new \stdClass;
        $from = max(1, $line - self::CODE_CONTEXT);
        $to = min(count($lines), $line + self::CODE_CONTEXT);

        for ($number = $from; $number <= $to; $number++) {
            $code->{$number} = rtrim($lines[$number - 1], "\r");
        }

        return $code;
    }

    public static function text(string $value): string
    {
        return strlen($value) > self::MAX_TEXT ? substr($value, 0, self::MAX_TEXT).'…' : $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * Only a short allowlist: no cookies, no authorization.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, list<string>>
     */
    private static function headers(array $server): array
    {
        $headers = [];

        foreach (self::HEADERS as $key => $name) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                $headers[$name] = [self::text((string) $server[$key])];
            }
        }

        return $headers;
    }

    /**
     * The fields every exception and log record shares.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function execution(string $type, int $version, array $context): array
    {
        return [
            'v' => $version,
            't' => $type,
            'timestamp' => microtime(true),
            'deploy' => $context['deploy'],
            'server' => $context['server'],
            'trace_id' => $context['trace_id'],
            'execution_source' => 'request',
            'execution_id' => $context['trace_id'],
            'execution_preview' => $context['preview'],
            'execution_stage' => 'action',
            'user' => $context['user'],
        ];
    }
}
