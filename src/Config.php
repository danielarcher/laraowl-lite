<?php

declare(strict_types=1);

namespace LaraOwlLite;

/**
 * Settings for one process, resolved once at start.
 *
 * Every option can be passed to LaraOwlLite::start(); the connection settings
 * also come from the environment or the project's .env file, under the same
 * keys the Laravel client uses.
 */
final class Config
{
    /**
     * Query and route parameters whose name contains one of these is sent as
     * "[redacted]": tokens, ticket codes, emails and the like.
     */
    public const REDACT = ['pass', 'secret', 'token', 'key', 'code', 'email', 'auth', 'sig'];

    /**
     * @param  list<string>  $redact
     * @param  list<string>  $ignoreUserAgents
     * @param  list<string>  $ignorePaths
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $url,
        public readonly string $token,
        public readonly ?string $appUrl,
        public readonly string $basePath,
        public readonly array $redact = self::REDACT,
        public readonly float $timeout = 2.0,
        public readonly array $ignoreUserAgents = ['LaraOwlUptimeBot'],
        public readonly array $ignorePaths = [],
        public readonly bool $captureWarnings = true,
        public readonly bool $captureDeprecations = false,
        public readonly float $sampleRate = 1.0,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function resolve(array $options = []): self
    {
        $basePath = rtrim((string) ($options['base_path'] ?? self::detectBasePath()), '/');
        $file = self::readEnvFile($options['env_file'] ?? $basePath.'/.env');

        $env = static fn (string $key): ?string => self::env($key, $file);

        $url = (string) ($options['url'] ?? $env('LARAOWL_SERVER_URL') ?? '');
        $token = (string) ($options['token'] ?? $env('LARAOWL_TOKEN') ?? '');
        $enabled = $options['enabled'] ?? self::toBool($env('LARAOWL_ENABLED'), true);

        return new self(
            enabled: (bool) $enabled && $url !== '' && $token !== '',
            url: rtrim($url, '/'),
            token: $token,
            appUrl: $options['app_url'] ?? $env('LARAOWL_APP_URL'),
            basePath: $basePath,
            redact: array_values(array_unique([...self::REDACT, ...($options['redact'] ?? [])])),
            timeout: (float) ($options['timeout'] ?? 2.0),
            ignoreUserAgents: array_values($options['ignore_user_agents'] ?? ['LaraOwlUptimeBot']),
            ignorePaths: array_values($options['ignore_paths'] ?? []),
            captureWarnings: (bool) ($options['capture_warnings'] ?? true),
            captureDeprecations: (bool) ($options['capture_deprecations'] ?? false),
            sampleRate: max(0.0, min(1.0, (float) ($options['sample_rate'] ?? 1.0))),
        );
    }

    /**
     * The project root: the directory holding vendor/.
     */
    public static function detectBasePath(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            $root = \Composer\InstalledVersions::getRootPackage()['install_path'] ?? null;

            if (is_string($root) && ($real = realpath($root)) !== false) {
                return $real;
            }
        }

        // src/ → laraowl-lite/ → danielarcher/ → vendor/ → project
        return dirname(__DIR__, 4);
    }

    /**
     * @param  array<string, string>  $file
     */
    private static function env(string $key, array $file): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $file[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The LARAOWL_* lines of a .env file. PHP-FPM doesn't export a site's
     * .env, so plain PHP sites on Forge only have their settings there.
     *
     * @return array<string, string>
     */
    private static function readEnvFile(string $path): array
    {
        $lines = is_readable($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $values = [];

        foreach ($lines ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?(LARAOWL_[A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $value = $match[2];

            if (preg_match('/^(["\'])(.*?)\1(?:\s+#.*)?$/', $value, $quoted) === 1) {
                $value = $quoted[2];
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            $values[$match[1]] = $value;
        }

        return $values;
    }

    private static function toBool(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return ! in_array(strtolower(trim($value, " \t\"'")), ['false', '0', 'off', 'no', 'null'], true);
    }
}
