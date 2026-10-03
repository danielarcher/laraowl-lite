<?php

/*
 * Real requests through PHP's built-in server: the fixture pages under
 * tests/Fixtures/site start the client, and a stand-in LaraOwl
 * (tests/Fixtures/collector.php) stores every batch it receives.
 */

final class Servers
{
    /** @var list<resource> */
    public static array $processes = [];

    public static string $dir = '';

    public static int $site = 0;

    public static int $siteWithOwlDown = 0;

    public static function start(): void
    {
        self::$dir = sys_get_temp_dir().'/laraowl-lite-e2e-'.bin2hex(random_bytes(4));
        mkdir(self::$dir.'/batches', 0777, true);

        $fixtures = dirname(__DIR__).'/Fixtures';
        $collector = self::serve(['-S', '127.0.0.1:%d', $fixtures.'/collector.php'], ['COLLECTOR_DIR' => self::$dir.'/batches']);

        $site = ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log='.self::$dir.'/php-errors.log', '-S', '127.0.0.1:%d', '-t', $fixtures.'/site'];
        self::$site = self::serve($site, ['LARAOWL_SERVER_URL' => "http://127.0.0.1:{$collector}", 'LARAOWL_TOKEN' => 'e2e-token']);
        self::$siteWithOwlDown = self::serve($site, ['LARAOWL_SERVER_URL' => 'http://127.0.0.1:'.self::freePort(), 'LARAOWL_TOKEN' => 'e2e-token']);
    }

    public static function stop(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }

        self::$processes = [];
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private static function serve(array $args, array $env): int
    {
        $port = self::freePort();
        $command = [PHP_BINARY, ...array_map(fn (string $arg) => sprintf($arg, $port), $args)];

        self::$processes[] = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);

        for ($i = 0; $i < 100; $i++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
                fclose($socket);

                return $port;
            }

            usleep(50_000);
        }

        throw new RuntimeException("The test server on port {$port} did not start.");
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}

/**
 * @return array{status: int, body: string, batches: list<array<string, mixed>>, seconds: float}
 */
function browse(string $path, ?string $userAgent = null, ?int $port = null): array
{
    foreach (glob(Servers::$dir.'/batches/*.json') as $file) {
        unlink($file);
    }

    $started = microtime(true);
    $handle = curl_init('http://127.0.0.1:'.($port ?? Servers::$site).$path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => $userAgent ?? 'Mozilla/5.0 (e2e) Firefox/140.0']);
    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $seconds = microtime(true) - $started;

    // The built-in server has no fastcgi_finish_request, so the batch goes
    // out before the response ends; a short wait covers slow machines.
    for ($i = 0; $i < 20 && glob(Servers::$dir.'/batches/*.json') === []; $i++) {
        usleep(25_000);
    }

    $batches = array_map(fn (string $file) => json_decode(file_get_contents($file), true), glob(Servers::$dir.'/batches/*.json'));

    return compact('status', 'body', 'batches', 'seconds');
}

/**
 * @return list<array<string, mixed>>
 */
function recordsOf(array $visit, string $type): array
{
    return array_values(array_filter($visit['batches'][0]['body']['records'] ?? [], fn (array $record) => $record['t'] === $type));
}

beforeAll(fn () => Servers::start());
afterAll(fn () => Servers::stop());

test('a page view is sent as one request record, with the project token', function () {
    $visit = browse('/ok.php?ref=newsletter&token=abc');
    $request = recordsOf($visit, 'request')[0];

    expect($visit['status'])->toBe(200)
        ->and($visit['body'])->toBe('hello')
        ->and($visit['batches'])->toHaveCount(1)
        ->and($visit['batches'][0])->toMatchArray(['path' => '/api/records', 'authorization' => 'Bearer e2e-token', 'user_agent' => 'laraowl-lite/'.LaraOwlLite\LaraOwlLite::VERSION])
        ->and($request)->toMatchArray([
            'method' => 'GET',
            'status_code' => 200,
            'route_path' => '/ok.php',
            'route_action' => 'ok.php',
            'ip' => '127.0.0.1',
            'server' => gethostname(),
            'url' => 'http://127.0.0.1:'.Servers::$site.'/ok.php?ref=newsletter&token=[redacted]',
        ])
        ->and($request['duration'])->toBeGreaterThan(0)
        ->and($request['peak_memory_usage'])->toBeGreaterThan(0);
});

test('a named route hides the ticket code everywhere', function () {
    $visit = browse('/ticket.php');

    expect(recordsOf($visit, 'request')[0]['route_path'])->toBe('/ticket/{code}')
        ->and(json_encode($visit['batches']))->not->toContain('TICKET-SECRET-42');
});

test('an uncaught exception is recorded and PHP still answers 500 and logs it', function () {
    $visit = browse('/throw.php');
    $exception = recordsOf($visit, 'exception')[0];

    expect($visit['status'])->toBe(500)
        ->and(recordsOf($visit, 'request')[0])->toMatchArray(['status_code' => 500, 'exceptions' => 1, 'exception_preview' => 'RuntimeException: Card declined'])
        ->and($exception)->toMatchArray(['class' => 'RuntimeException', 'message' => 'Card declined', 'file' => 'throw.php', 'line' => 7, 'handled' => false])
        ->and($exception['trace'])->toContain('chargeCard()')
        ->and(file_get_contents(Servers::$dir.'/php-errors.log'))->toContain('Uncaught RuntimeException: Card declined');
});

test('a warning is recorded once and the page still renders', function () {
    $visit = browse('/warn.php');
    $logs = recordsOf($visit, 'log');

    expect($visit['status'])->toBe(200)
        ->and($visit['body'])->toBe('still rendered')
        ->and($logs)->toHaveCount(1)
        ->and($logs[0])->toMatchArray(['level' => 'warning', 'message' => 'Undefined variable $undefined (warn.php:7)']);
});

test('a fatal error is recorded even though no handler sees it', function () {
    $visit = browse('/fatal.php');
    $exception = recordsOf($visit, 'exception')[0];

    expect($visit['status'])->toBe(500)
        ->and(recordsOf($visit, 'request')[0]['status_code'])->toBe(500)
        ->and($exception)->toMatchArray(['class' => 'FatalError', 'file' => 'fatal.php', 'handled' => false])
        ->and($exception['message'])->toContain('Allowed memory size');
});

test('exceptions the site handles itself are reported, with its logs', function () {
    $visit = browse('/report.php');

    expect($visit['body'])->toBe('booked')
        ->and(recordsOf($visit, 'exception')[0])->toMatchArray(['class' => 'DomainException', 'handled' => true])
        ->and(recordsOf($visit, 'log')[0])->toMatchArray(['level' => 'error', 'message' => 'Ticket email failed']);
});

test("the site's own error and exception handlers keep working", function () {
    $visit = browse('/previous-handler.php');

    expect($visit['status'])->toBe(503)
        ->and($visit['body'])->toBe('[site handler: Undefined variable $undefined][site error page: Maintenance]')
        ->and(recordsOf($visit, 'request')[0]['status_code'])->toBe(503)
        ->and(recordsOf($visit, 'exception')[0]['class'])->toBe('LogicException')
        ->and(recordsOf($visit, 'log'))->toHaveCount(1);
});

test('the uptime monitor sends nothing', function () {
    expect(browse('/ok.php', 'Mozilla/5.0 (compatible; LaraOwlUptimeBot/1.0; uptime monitor)')['batches'])->toBe([]);
});

test('disabled, it sends nothing', function () {
    $visit = browse('/disabled.php');

    expect($visit['body'])->toBe('off')->and($visit['batches'])->toBe([]);
});

test('with LaraOwl down, pages still load normally and fast', function () {
    $visit = browse('/ok.php', port: Servers::$siteWithOwlDown);

    expect($visit['status'])->toBe(200)
        ->and($visit['body'])->toBe('hello')
        ->and($visit['seconds'])->toBeLessThan(1.5);
});
