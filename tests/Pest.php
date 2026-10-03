<?php

use LaraOwlLite\Config;
use LaraOwlLite\LaraOwlLite;

/*
 * Unit tests boot the client directly with a sender that keeps the batches,
 * so nothing touches the network.
 */
function liteConfig(array $overrides = []): Config
{
    return new Config(...array_merge([
        'enabled' => true,
        'url' => 'https://owl.test',
        'token' => 'test-token',
        'appUrl' => null,
        'basePath' => dirname(__DIR__),
    ], $overrides));
}

function bootLite(array $overrides = []): void
{
    LaraOwlLite::boot(liteConfig($overrides), fn (array $records) => null);
}

function serverFor(string $uri, array $overrides = []): array
{
    return array_merge([
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => $uri,
        'REQUEST_TIME_FLOAT' => microtime(true) - 0.05,
        'HTTP_HOST' => 'site.test',
        'HTTPS' => 'on',
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0',
        'HTTP_COOKIE' => 'PHPSESSID=secret-session',
        'HTTP_AUTHORIZATION' => 'Bearer secret',
        'SCRIPT_FILENAME' => dirname(__DIR__).'/public/index.php',
    ], $overrides);
}

uses()->afterEach(fn () => LaraOwlLite::reset())->in('Unit');
