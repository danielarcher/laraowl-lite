<?php

require __DIR__.'/bootstrap.php';

session_start();
$_SESSION['seen'] = true;

echo 'hello';
