<?php

// The site's own handlers are registered first and must keep working.
set_error_handler(function (int $level, string $message) {
    echo "[site handler: {$message}]";

    return true;
});

set_exception_handler(function (Throwable $e) {
    http_response_code(503);
    echo "[site error page: {$e->getMessage()}]";
});

require __DIR__.'/bootstrap.php';

echo $undefined;

throw new LogicException('Maintenance');
