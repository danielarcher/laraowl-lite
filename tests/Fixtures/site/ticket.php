<?php

require __DIR__.'/bootstrap.php';

// As a front controller would after matching /ticket/{code}.
\LaraOwlLite\LaraOwlLite::route('/ticket/{code}', ['code' => 'TICKET-SECRET-42']);

echo 'your ticket';
