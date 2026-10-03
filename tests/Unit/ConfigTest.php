<?php

use LaraOwlLite\Config;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/laraowl-lite-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    if (is_file($this->dir.'/.env')) {
        unlink($this->dir.'/.env');
    }
    rmdir($this->dir);
    putenv('LARAOWL_TOKEN');
    putenv('LARAOWL_ENABLED');
});

test('it reads the connection from the project .env, as Forge writes it', function () {
    file_put_contents($this->dir.'/.env', implode("\n", [
        'APP_NAME="Balfolk"',
        '# LaraOwl',
        'LARAOWL_ENABLED=true',
        'LARAOWL_SERVER_URL="https://owl.example.com/"',
        "export LARAOWL_TOKEN='abc123' # project token",
    ]));

    $config = Config::resolve(['base_path' => $this->dir]);

    expect($config->enabled)->toBeTrue()
        ->and($config->url)->toBe('https://owl.example.com')
        ->and($config->token)->toBe('abc123')
        ->and($config->basePath)->toBe($this->dir);
});

test('the real environment wins over the .env file', function () {
    file_put_contents($this->dir.'/.env', "LARAOWL_SERVER_URL=https://owl.example.com\nLARAOWL_TOKEN=from-file\n");
    putenv('LARAOWL_TOKEN=from-env');

    expect(Config::resolve(['base_path' => $this->dir])->token)->toBe('from-env');
});

test('options win over everything', function () {
    putenv('LARAOWL_TOKEN=from-env');

    $config = Config::resolve(['base_path' => $this->dir, 'url' => 'https://other.test', 'token' => 'from-option']);

    expect($config->token)->toBe('from-option')->and($config->url)->toBe('https://other.test');
});

test('it stays off without a token or url, or when disabled', function (string $env) {
    file_put_contents($this->dir.'/.env', $env);

    expect(Config::resolve(['base_path' => $this->dir])->enabled)->toBeFalse();
})->with([
    'no token' => ["LARAOWL_SERVER_URL=https://owl.example.com\n"],
    'no url' => ["LARAOWL_TOKEN=abc\n"],
    'disabled' => ["LARAOWL_ENABLED=false\nLARAOWL_SERVER_URL=https://owl.example.com\nLARAOWL_TOKEN=abc\n"],
    'disabled with 0' => ["LARAOWL_ENABLED=0\nLARAOWL_SERVER_URL=https://owl.example.com\nLARAOWL_TOKEN=abc\n"],
    'no file' => [''],
]);

test('extra redacted names add to the defaults', function () {
    $config = Config::resolve(['base_path' => $this->dir, 'redact' => ['booking_ref']]);

    expect($config->redact)->toContain('token', 'code', 'email', 'booking_ref');
});

test('the sample rate is kept between 0 and 1', function () {
    expect(Config::resolve(['base_path' => $this->dir, 'sample_rate' => 7])->sampleRate)->toBe(1.0)
        ->and(Config::resolve(['base_path' => $this->dir, 'sample_rate' => -1])->sampleRate)->toBe(0.0);
});
