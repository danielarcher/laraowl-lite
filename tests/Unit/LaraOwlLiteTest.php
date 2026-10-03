<?php

use LaraOwlLite\LaraOwlLite;

// The test runner lowers error_reporting; a site runs with its own php.ini.
beforeEach(fn () => $this->reporting = error_reporting(E_ALL));
afterEach(fn () => error_reporting($this->reporting));

test('a request becomes one batch: the request first, then its errors and logs', function () {
    bootLite();

    LaraOwlLite::report(new RuntimeException('Mailer down'));
    LaraOwlLite::log('error', 'Ticket email failed', ['booking' => 12]);

    $records = LaraOwlLite::collect(serverFor('/book'));

    expect(array_column($records, 't'))->toBe(['request', 'exception', 'log'])
        ->and($records[0])->toMatchArray(['exceptions' => 1, 'logs' => 1, 'exception_preview' => 'RuntimeException: Mailer down'])
        ->and($records[0]['duration'])->toBeGreaterThan(40_000)
        ->and($records[1]['handled'])->toBeTrue()
        ->and($records[2])->toMatchArray(['level' => 'error', 'message' => 'Ticket email failed', 'context' => '{"booking":12}'])
        ->and(array_unique(array_column($records, 'trace_id')))->toHaveCount(1);
});

test('a named route groups the request and hides its secret parameters', function () {
    bootLite();

    LaraOwlLite::route('ticket/{code}', ['code' => 'XK42-SECRET']);

    $request = LaraOwlLite::collect(serverFor('/ticket/XK42-SECRET'))[0];

    expect($request['route_path'])->toBe('/ticket/{code}')
        ->and($request['url'])->toBe('https://site.test/ticket/[redacted]')
        ->and(json_encode($request))->not->toContain('XK42-SECRET');
});

test('the uptime monitor is not counted as a visit, but its errors still are', function () {
    bootLite();

    LaraOwlLite::report(new RuntimeException('Down'));

    $records = LaraOwlLite::collect(serverFor('/', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; LaraOwlUptimeBot/1.0; uptime monitor)']));

    expect(array_column($records, 't'))->toBe(['exception']);
});

test('ignored paths and a zero sample rate skip the request record', function () {
    bootLite(['ignorePaths' => ['/health']]);
    expect(LaraOwlLite::collect(serverFor('/health/check')))->toBe([]);

    bootLite(['sampleRate' => 0.0]);
    expect(LaraOwlLite::collect(serverFor('/')))->toBe([]);
});

test('warnings are recorded once per place and passed on to PHP', function () {
    bootLite();

    $handledByPhp = [];
    foreach ([1, 2, 3] as $attempt) {
        $handledByPhp[] = LaraOwlLite::handleError(E_WARNING, 'Undefined variable $total', __FILE__, 20);
    }
    LaraOwlLite::handleError(E_NOTICE, 'Only variables should be passed by reference', __FILE__, 30);

    $logs = array_values(array_filter(LaraOwlLite::collect(serverFor('/')), fn ($r) => $r['t'] === 'log'));

    expect($handledByPhp)->toBe([false, false, false])
        ->and($logs)->toHaveCount(2)
        ->and($logs[0])->toMatchArray(['level' => 'warning', 'message' => 'Undefined variable $total (tests/Unit/LaraOwlLiteTest.php:20)'])
        ->and($logs[1]['level'])->toBe('notice');
});

test('deprecations are left out unless asked for', function () {
    bootLite();
    LaraOwlLite::handleError(E_DEPRECATED, 'Implicitly nullable parameter', __FILE__, 40);
    expect(LaraOwlLite::collect(serverFor('/'))[0]['logs'])->toBe(0);

    bootLite(['captureDeprecations' => true]);
    LaraOwlLite::handleError(E_DEPRECATED, 'Implicitly nullable parameter', __FILE__, 40);
    expect(LaraOwlLite::collect(serverFor('/'))[1])->toMatchArray(['t' => 'log', 'level' => 'info']);
});

test('errors silenced with @ are not recorded', function () {
    bootLite();

    $previous = error_reporting(E_ERROR);
    LaraOwlLite::handleError(E_WARNING, 'fopen(missing): Failed to open stream', __FILE__, 50);
    error_reporting($previous);

    expect(LaraOwlLite::collect(serverFor('/'))[0]['logs'])->toBe(0);
});

test('logs stop at 50 per request', function () {
    bootLite();

    for ($i = 0; $i < 80; $i++) {
        LaraOwlLite::log('info', "line {$i}");
    }

    expect(LaraOwlLite::collect(serverFor('/'))[0]['logs'])->toBe(50);
});

test('unknown log levels are recorded as info', function () {
    bootLite();
    LaraOwlLite::log('LOUD', 'hello');

    expect(LaraOwlLite::collect(serverFor('/'))[1]['level'])->toBe('info');
});

test('the signed-in user is attached to the request and its errors', function () {
    bootLite();
    LaraOwlLite::user(7);
    LaraOwlLite::report(new RuntimeException('x'));

    expect(array_column(LaraOwlLite::collect(serverFor('/admin')), 'user'))->toBe(['7', '7']);
});

test('the Forge release is the deploy marker', function () {
    bootLite(['basePath' => '/home/forge/site.com/releases/20261003224512']);

    expect(LaraOwlLite::collect(serverFor('/'))[0]['deploy'])->toBe('20261003224512');
});

test('nothing is recorded or sent before start, or on the command line', function () {
    LaraOwlLite::start(['url' => 'https://owl.test', 'token' => 'abc']);
    LaraOwlLite::report(new RuntimeException('x'));
    LaraOwlLite::log('error', 'x');

    expect(LaraOwlLite::enabled())->toBeFalse()
        ->and(LaraOwlLite::collect(serverFor('/')))->toBe([]);
});
