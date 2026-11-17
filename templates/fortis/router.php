<?php
declare(strict_types=1);

/**
 * Router for PHP built-in server:
 * php -S localhost:8080 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
