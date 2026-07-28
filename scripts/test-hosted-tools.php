<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\App;
use App\HostedToolsManager;

App::getInstance()->appRoot = dirname(__DIR__);

$testRoot = sys_get_temp_dir() . '/rpi-mainpage-tools-test-' . bin2hex(random_bytes(6));
$storageDir = $testRoot . '/storage';

$removeDirectory = static function (string $directory) use (&$removeDirectory): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeDirectory($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

mkdir($testRoot, 0775, true);

try {
    $manager = new HostedToolsManager($storageDir);

    $htmlPath = $testRoot . '/standalone.html';
    file_put_contents($htmlPath, '<!doctype html><title>Standalone</title>');
    $htmlTool = $manager->install($htmlPath, 'standalone.html', 'Standalone tool');

    $assert($htmlTool['entryPath'] === 'index.html', 'Standalone HTML must be published as index.html');
    $assert(count($manager->listTools()) === 1, 'Standalone tool must appear in catalog');
    $resolvedHtml = $manager->resolvePublicFile($htmlTool['id'] . '/index.html');
    $assert($resolvedHtml !== null && str_contains((string)file_get_contents($resolvedHtml), 'Standalone'), 'Published HTML must be readable');

    $zipPath = $testRoot . '/package.zip';
    $zip = new ZipArchive();
    $assert($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Test ZIP must open');
    $zip->addFromString('package/assets/app.js', 'document.body.dataset.ready = "yes";');
    $zip->addFromString('package/index.html', '<!doctype html><script src="assets/app.js"></script>');
    $zip->addFromString('package/other.html', '<!doctype html><title>Other</title>');
    $zip->close();

    $zipTool = $manager->install($zipPath, 'package.zip', 'ZIP tool');
    $assert($zipTool['entryPath'] === 'package/index.html', 'ZIP must prefer the shallowest index.html');
    $assert($zipTool['filesCount'] === 3, 'ZIP file count must be stored');
    $assert($manager->resolvePublicFile($zipTool['id'] . '/package/assets/app.js') !== null, 'ZIP resource must be published');
    $assert(count($manager->listTools()) === 2, 'ZIP tool must appear in catalog');

    $unsafeZipPath = $testRoot . '/unsafe.zip';
    $unsafeZip = new ZipArchive();
    $assert($unsafeZip->open($unsafeZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unsafe test ZIP must open');
    $unsafeZip->addFromString('../outside.html', '<!doctype html>');
    $unsafeZip->close();

    $unsafeRejected = false;
    try {
        $manager->install($unsafeZipPath, 'unsafe.zip', 'Unsafe tool');
    } catch (RuntimeException) {
        $unsafeRejected = true;
    }
    $assert($unsafeRejected, 'ZIP traversal path must be rejected');
    $assert(!is_file($testRoot . '/outside.html'), 'ZIP traversal must not write outside staging directory');

    $manager->delete($htmlTool['id']);
    $assert(count($manager->listTools()) === 1, 'Deleted tool must disappear from catalog');

    echo "Hosted tools tests passed\n";
} finally {
    $removeDirectory($testRoot);
}
