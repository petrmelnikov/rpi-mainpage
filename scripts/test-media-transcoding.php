#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Media\MediaToolchain;
use App\Media\TranscodeSessionManager;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$run = (new MediaToolchain('/does/not/matter', '/does/not/matter'))->run(['/usr/bin/printf', '%s', 'argv safe']);
assertTrue($run['exitCode'] === 0 && $run['stdout'] === 'argv safe', 'argv-safe process execution failed');

$root = sys_get_temp_dir() . '/rpi-mainpage-transcode-test-' . bin2hex(random_bytes(6));
$id = str_repeat('a', 64);
mkdir($root . '/' . $id, 0770, true);
file_put_contents($root . '/' . $id . '/state.json', json_encode([
    'id' => $id,
    'duration' => 9.5,
    'segmentDuration' => 4,
    'createdAt' => time(),
    'lastAccessAt' => time(),
    'stopRequested' => false,
]));

$sessions = new TranscodeSessionManager($root);
$playlist = $sessions->playlist($id);
assertTrue(substr_count($playlist, '#EXTINF:') === 3, 'playlist must describe every segment');
assertTrue(str_contains($playlist, 'segment-000002.m4s'), 'playlist segment URL is missing');
assertTrue(str_contains($playlist, '#EXT-X-ENDLIST'), 'playlist must be VOD');
assertTrue($sessions->artifactPath($id, 'segment-000001.m4s') === $root . '/' . $id . '/segment-000001.m4s', 'artifact path mismatch');

$rejected = false;
try {
    $sessions->artifactPath($id, '../state.json');
} catch (InvalidArgumentException) {
    $rejected = true;
}
assertTrue($rejected, 'artifact traversal was not rejected');

foreach (new FilesystemIterator($root . '/' . $id) as $item) unlink($item->getPathname());
rmdir($root . '/' . $id);
rmdir($root);

echo "media transcoding smoke tests: OK\n";
