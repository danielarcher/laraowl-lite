<?php

/*
 * Stands in for LaraOwl's ingest endpoint: stores each POST, with its
 * Authorization header, as a JSON file in $COLLECTOR_DIR and answers 202.
 */
$dir = getenv('COLLECTOR_DIR');

file_put_contents($dir.'/'.hrtime(true).'.json', json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'body' => json_decode(file_get_contents('php://input'), true),
]));

http_response_code((int) (getenv('COLLECTOR_STATUS') ?: 202));
header('Content-Type: application/json');
echo '{"message":"Accepted."}';
