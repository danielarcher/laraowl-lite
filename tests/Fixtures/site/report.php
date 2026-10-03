<?php

require __DIR__.'/bootstrap.php';

try {
    throw new DomainException('Mailer refused the message');
} catch (DomainException $e) {
    \LaraOwlLite\LaraOwlLite::report($e);
    \LaraOwlLite\LaraOwlLite::log('error', 'Ticket email failed', ['booking' => 12]);
}

echo 'booked';
