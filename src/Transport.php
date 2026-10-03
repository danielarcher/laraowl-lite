<?php

declare(strict_types=1);

namespace LaraOwlLite;

/**
 * Posts one batch to LaraOwl's ingest endpoint. Never throws and never
 * waits longer than the configured timeout: a monitoring outage must not
 * become the site's outage.
 *
 * @internal
 */
final class Transport
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return int|null the HTTP status, or null when nothing was answered
     */
    public static function send(Config $config, array $records): ?int
    {
        $body = Records::json(['app_url' => $config->appUrl, 'records' => $records]);
        $endpoint = $config->url.'/api/records';
        $headers = [
            'Authorization: Bearer '.$config->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: laraowl-lite/'.LaraOwlLite::VERSION,
        ];

        try {
            return function_exists('curl_init')
                ? self::curl($endpoint, $headers, $body, $config->timeout)
                : self::stream($endpoint, $headers, $body, $config->timeout);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private static function curl(string $endpoint, array $headers, string $body, float $timeout): ?int
    {
        $handle = curl_init($endpoint);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
        ]);

        $sent = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $sent === false || $status === 0 ? null : $status;
    }

    /**
     * @param  list<string>  $headers
     */
    private static function stream(string $endpoint, array $headers, string $body, float $timeout): ?int
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        if (@file_get_contents($endpoint, false, $context) === false) {
            return null;
        }

        $response = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $response[0] ?? '', $match) === 1 ? (int) $match[1] : null;
    }
}
