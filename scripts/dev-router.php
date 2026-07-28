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

if (str_starts_with($path, '/tools/hosted/')) {
    require_once $root . '/vendor/autoload.php';

    \App\App::getInstance()->appRoot = $root;
    $relativePath = substr($path, strlen('/tools/hosted/'));
    $hostedFile = (new \App\HostedToolsManager())->resolvePublicFile($relativePath);

    if ($hostedFile === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        return true;
    }

    $extension = strtolower((string)pathinfo($hostedFile, PATHINFO_EXTENSION));
    $knownTypes = [
        'css' => 'text/css; charset=utf-8',
        'gif' => 'image/gif',
        'htm' => 'text/html; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=utf-8',
        'wasm' => 'application/wasm',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $contentType = $knownTypes[$extension] ?? 'application/octet-stream';

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($hostedFile));
    header('X-Content-Type-Options: nosniff');
    readfile($hostedFile);
    return true;
}

$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $root . '/index.php';
