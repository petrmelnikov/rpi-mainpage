#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\App;
use App\Controller\FileIndexController;
use App\DownloadQueueManager;

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';

$app = App::getInstance();
$app->appRoot = $appRoot;

$lockFile = rtrim((string)sys_get_temp_dir(), '/') . '/rpi-mainpage-download-queue.lock';
$lockFp = @fopen($lockFile, 'c+');
if ($lockFp === false) {
    exit(1);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    exit(0);
}

$queue = new DownloadQueueManager();

try {
    while (true) {
        $job = $queue->claimNextQueued();
        if (!is_array($job)) {
            break;
        }

        $url = (string)($job['url'] ?? '');
        $targetDir = (string)($job['targetDir'] ?? '');
        if ($url === '' || $targetDir === '') {
            $queue->finishJob((string)$job['id'], false, ['error' => 'Invalid job payload']);
            continue;
        }

        $result = FileIndexController::downloadByUrlToDirectory($url, $targetDir);
        $queue->finishJob((string)$job['id'], (bool)($result['ok'] ?? false), $result);
    }
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
