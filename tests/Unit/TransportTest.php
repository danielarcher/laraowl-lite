<?php

use LaraOwlLite\Transport;

test('an unreachable LaraOwl costs at most the timeout and never throws', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $started = microtime(true);
    $status = Transport::send(liteConfig(['url' => "http://127.0.0.1:{$port}", 'timeout' => 0.5]), [['t' => 'request']]);

    expect($status)->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

test('a LaraOwl that never answers is given up on at the timeout', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);

    $started = microtime(true);
    $status = Transport::send(liteConfig(['url' => "http://127.0.0.1:{$port}", 'timeout' => 0.3]), [['t' => 'request']]);

    fclose($socket);

    expect($status)->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(0.8);
});
