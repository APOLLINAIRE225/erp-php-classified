<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

// router.php

// Parse requested path
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Construct full path
$fullPath = __DIR__ . $uri;

// If the file exists, serve it normally
if ($uri !== '/' && file_exists($fullPath)) {
    return false; // Serve the requested file
}

// Otherwise, serve 404
require APP_ROOT . '/404.php';
