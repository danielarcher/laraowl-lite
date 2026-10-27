# LaraOwl Lite

A tiny, dependency-free [LaraOwl](https://github.com/danielarcher/laraowl) client for plain PHP sites.

Laravel apps use the full [`laraowl/client`](https://github.com/laraowl/client). Small sites without a framework don't need that, and on a small server they can't spare a framework's memory just to be monitored. LaraOwl Lite gives them the essentials, in about 15 µs and a few KB per request:

- **Requests:** count by status, p50/p95 speed, slowest and most visited pages, 404s.
- **Errors:** uncaught exceptions and fatal errors (out of memory, time limits) become Issues with stack traces and code.
- **Warnings and logs:** PHP warnings and notices, and anything the site reports itself.
- **Security:** probes and scanners, scored per IP address by LaraOwl's usual rules.
- **Memory:** peak memory per request.

It sends the same record format as the Laravel client, so LaraOwl's dashboards, issues, logs, alerts and security analysis work unchanged.

## Install

```bash
composer config repositories.laraowl-lite vcs https://github.com/danielarcher/laraowl-lite
composer require danielarcher/laraowl-lite
```

Then start it at the top of the file every page loads (a bootstrap or config file):

```php
require __DIR__.'/vendor/autoload.php';

\LaraOwlLite\LaraOwlLite::start();
```

And set the project's connection, in the environment or the project's `.env` file. These are the same keys the Laravel client uses:

```dotenv
LARAOWL_ENABLED=true
LARAOWL_SERVER_URL=https://laraowl.example.com
LARAOWL_TOKEN=the-project-token
```

Create the project in LaraOwl with `php artisan laraowl:projects:create <team> "Site name" --url=https://example.com`. Without a token and server URL, or with `LARAOWL_ENABLED=false` (in tests, for example), the client does nothing. It never runs on the command line.

## Use

```php
use LaraOwlLite\LaraOwlLite;

// Name the route a front controller matched. Parameters whose name looks
// secret (code, token, key, …) are replaced by [redacted] in the URL.
LaraOwlLite::route('/ticket/{code}', ['code' => $code]);

// An exception the site caught and handled itself.
try {
    $mailer->send($ticket);
} catch (Throwable $e) {
    LaraOwlLite::report($e);
}

// A log message: debug, info, notice, warning, error, critical, alert, emergency.
LaraOwlLite::log('error', 'Ticket email failed', ['booking' => $booking['id']]);

// Who is signed in, if anyone; a name or email lists them by name in LaraOwl.
LaraOwlLite::user($adminId, $adminName, $adminEmail);
```

Without `route()`, requests are grouped by path, with ids, UUIDs, hashes and long tokens collapsed: `/event/12` and `/event/13` are both `/event/{id}`.

## How it stays light

- **No dependencies, no framework.** Four small classes. With opcache, a request costs about 15 µs of CPU and 3 KB of memory; without opcache, about 180 KB. A batch is usually under 1 KB.
- **Visitors never wait.** Everything a request produced (the request, its exceptions and logs) goes in one batch, sent from the last shutdown function. Before sending, it closes the session and calls `fastcgi_finish_request()`, so the page has already been delivered.
- **It can't take a site down.** Sending never throws, connects within 1 second and gives up after the timeout (2 seconds by default). If LaraOwl is down or busy, the batch is dropped.
- **The site's own handlers keep working.** Exceptions and errors are recorded, then handed to the handler that was there before, or to PHP, which logs them and answers 500 as usual.
- **Uptime checks aren't visits.** Requests from LaraOwl's own uptime monitor aren't recorded.

## Privacy

- Never sent: cookies, the `Authorization` header or any other header outside a short list (User-Agent, Referer, Accept-Language, Host), and request bodies.
- Query values and route parameters whose name contains `pass`, `secret`, `token`, `key`, `code`, `email`, `auth` or `sig` are sent as `[redacted]`. Add names with the `redact` option.
- Code around the lines in a stack trace is sent for the site's own files only, never for `vendor/`.

## Options

`start()` takes an array; every key is optional.

| Option | Default | |
|---|---|---|
| `url`, `token`, `enabled` | from `LARAOWL_SERVER_URL`, `LARAOWL_TOKEN`, `LARAOWL_ENABLED` | Connection settings |
| `app_url` | `LARAOWL_APP_URL` | Sent with each batch |
| `base_path` | the directory holding `vendor/` | Paths in traces are shown relative to it |
| `env_file` | `<base_path>/.env` | Where to look for the `LARAOWL_*` settings |
| `redact` | `[]` | More query and route parameter names to redact |
| `timeout` | `2.0` | Seconds before giving up on LaraOwl |
| `ignore_user_agents` | `['LaraOwlUptimeBot']` | Requests from these aren't recorded (their errors still are) |
| `ignore_paths` | `[]` | Path prefixes whose requests aren't recorded |
| `capture_warnings` | `true` | Record PHP warnings and notices, once per place per request |
| `capture_deprecations` | `false` | Also record deprecation notices |
| `sample_rate` | `1.0` | Share of requests recorded, for busy sites (errors are always recorded) |

## Requirements

PHP 8.1 or newer, behind PHP-FPM (or LiteSpeed) for the send-after-response behavior. `ext-curl` is used when available; otherwise PHP's HTTP stream wrapper.

## Testing

```bash
composer install
composer test
```

The end-to-end tests run real requests through PHP's built-in server against a stand-in LaraOwl.

## Credits and license

The record format follows [`laraowl/client`](https://github.com/laraowl/client) by Abdelmjid Saber and the LaraOwl contributors. LaraOwl Lite is written and maintained by [Daniel Archer](https://github.com/danielarcher) and released under the [MIT License](LICENSE).
