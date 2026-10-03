<?php

use LaraOwlLite\Config;
use LaraOwlLite\Records;

test('secret-looking query values are redacted, the rest kept', function () {
    $url = Records::url(serverFor('/book?event=12&email=a%40b.ie&api_key=xyz&ref=newsletter'), [], Config::REDACT);

    expect($url)->toBe('https://site.test/book?event=12&email=[redacted]&api_key=[redacted]&ref=newsletter');
});

test('secret route parameters are redacted from the path', function () {
    $url = Records::url(serverFor('/ticket/AB12-CD34/cancel'), ['code' => 'AB12-CD34'], Config::REDACT);

    expect($url)->toBe('https://site.test/ticket/[redacted]/cancel');
});

test('other route parameters stay in the path', function () {
    $url = Records::url(serverFor('/event/summer-ball'), ['slug' => 'summer-ball'], Config::REDACT);

    expect($url)->toBe('https://site.test/event/summer-ball');
});

test('paths are grouped by page', function (string $path, string $route) {
    expect(Records::normalizePath($path))->toBe($route);
})->with([
    ['/', '/'],
    ['/event/12', '/event/{id}'],
    ['/admin/events/7/edit?x=1', '/admin/events/{id}/edit'],
    ['/u/3f2a9c1e-7b4d-4e8f-9a6b-1c2d3e4f5a6b', '/u/{uuid}'],
    ['/file/5d41402abc4b2a76b9719d911017c592', '/file/{hash}'],
    ['/t/aB3dE5fG7hJ9kL1mN2pQ4r', '/t/{token}'],
    ['/about-the-festival-weekend', '/about-the-festival-weekend'],
]);

test('a request record carries what LaraOwl charts, and no cookies', function () {
    $record = Records::request(serverFor('/event/12'), [
        'trace_id' => 'trace-1', 'deploy' => '20261003', 'server' => 'silent-temple', 'user' => null,
        'route' => '/event/{id}', 'url' => 'https://site.test/event/12', 'script' => 'public/index.php',
        'duration' => 51234, 'status' => 200, 'response_size' => null, 'exceptions' => 0, 'logs' => 0,
        'peak_memory' => 2097152, 'exception_preview' => null,
    ]);

    expect($record)->toMatchArray([
        't' => 'request',
        'method' => 'GET',
        'route_path' => '/event/{id}',
        'status_code' => 200,
        'duration' => 51234,
        'ip' => '203.0.113.7',
        'server' => 'silent-temple',
        'trace_id' => 'trace-1',
    ])->and(json_decode($record['headers'], true))->toBe([
        'user-agent' => ['Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0'],
        'host' => ['site.test'],
    ])->and($record['headers'])->not->toContain('secret');
});

test('an exception record groups by where it was thrown and shows the code', function () {
    $context = ['trace_id' => 't', 'deploy' => null, 'server' => 'h', 'user' => '7', 'preview' => 'GET /', 'base_path' => dirname(__DIR__, 2)];
    $make = fn () => new RuntimeException('Card declined', 402);

    $first = Records::exception($make(), false, $context);
    $second = Records::exception($make(), false, $context);
    $trace = json_decode($first['trace'], true);

    expect($first)->toMatchArray([
        't' => 'exception',
        'class' => RuntimeException::class,
        'file' => 'tests/Unit/RecordsTest.php',
        'message' => 'Card declined',
        'code' => '402',
        'handled' => false,
        'user' => '7',
    ])->and($first['_group'])->toBe($second['_group'])
        ->and($trace[0]['file'])->toStartWith('tests/Unit/RecordsTest.php:')
        ->and($trace[0]['code'])->toBeArray()->not->toBeEmpty()
        ->and(implode("\n", $trace[0]['code']))->toContain("new RuntimeException('Card declined', 402)");
});

test('no code is sent from vendor files', function () {
    expect(Records::sourceCode(dirname(__DIR__, 2).'/vendor/autoload.php', 3, dirname(__DIR__, 2)))->toBeNull()
        ->and(Records::sourceCode('/etc/hostname', 1, dirname(__DIR__, 2)))->toBeNull();
});

test('a fatal error becomes an exception record', function () {
    $record = Records::fatal(
        ['type' => E_ERROR, 'message' => 'Allowed memory size of 16777216 bytes exhausted', 'file' => __FILE__, 'line' => 10],
        ['trace_id' => 't', 'deploy' => null, 'server' => 'h', 'user' => null, 'preview' => 'GET /', 'base_path' => dirname(__DIR__, 2)],
    );

    expect($record)->toMatchArray(['t' => 'exception', 'class' => 'FatalError', 'file' => 'tests/Unit/RecordsTest.php', 'line' => 10, 'handled' => false]);
});

test('long messages are cut', function () {
    expect(strlen(Records::text(str_repeat('a', 50_000))))->toBeLessThan(10_010);
});
