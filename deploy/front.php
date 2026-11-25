<?php
// Apply the application's HTML authorization router under Apache.
require __DIR__ . '/../backend/router.php';
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath(__DIR__ . '/../' . ltrim($path, '/'));
$root = realpath(__DIR__ . '/..');
if (http_response_code() >= 300) exit;
if (!$file || !str_starts_with($file, $root . '/') || !str_ends_with($file, '.html')) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
readfile($file);
