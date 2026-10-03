<?php

require __DIR__.'/bootstrap.php';

ini_set('memory_limit', '16M');

$blocks = [];

while (true) {
    $blocks[] = str_repeat('x', 1024 * 1024);
}
