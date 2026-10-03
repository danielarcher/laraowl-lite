<?php

require __DIR__.'/../../../vendor/autoload.php';

\LaraOwlLite\LaraOwlLite::start(['base_path' => __DIR__, 'enabled' => false]);

echo \LaraOwlLite\LaraOwlLite::enabled() ? 'on' : 'off';
