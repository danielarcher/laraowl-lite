<?php

require __DIR__.'/bootstrap.php';

// The same warning three times is recorded once.
for ($i = 0; $i < 3; $i++) {
    echo $undefined;
}

echo 'still rendered';
