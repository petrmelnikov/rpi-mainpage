<?php

// Router for the PHP built-in dev server: mimics the nginx setup —
// existing files are served/executed directly, everything else goes to index.php.

$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// Never expose dotfiles (.env.ssh, .docker-ssh, .git, ...).
if (str_contains($path, '/.')) {
    http_response_code(403);
    return true;
}

$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $root . '/index.php';
