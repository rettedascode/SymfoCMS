<?php

/*
 * Router script for PHP's built-in web server (development only):
 *
 *     php -S localhost:8000 -t public bin/dev-router.php
 *
 * Existing files in public/ (uploads, compiled assets) are served directly,
 * everything else is handled by Symfony.
 */

$publicDir = dirname(__DIR__).'/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH));

if ('/' !== $path && !str_contains($path, '..') && is_file($publicDir.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicDir.'/index.php';
