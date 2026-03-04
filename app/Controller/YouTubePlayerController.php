<?php

namespace App\Controller;

use App\Router;

class YouTubePlayerController
{
    private static function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }

    private static function getStorageBaseDir(): string
    {
        $candidates = [];

        $customBase = trim((string)getenv('UPLOAD_BASE_DIR'));
        if ($customBase !== '') {
            $candidates[] = rtrim($customBase, '/') . '/rpi-mainpage-upload';
        }

        $tmp = rtrim((string)sys_get_temp_dir(), '/');
        if ($tmp !== '') {
            $candidates[] = $tmp . '/rpi-mainpage-upload';
        }

        $candidates[] = '/dev/shm/rpi-mainpage-upload';

        foreach ($candidates as $dir) {
            if (@is_dir($dir) || @mkdir($dir, 0775, true)) {
                if (@is_writable($dir)) {
                    return $dir;
                }
            }
        }

        $cwd = getcwd();
        $fallback = rtrim((string)$cwd, '/') . '/tmp/rpi-mainpage-upload';
        @mkdir($fallback, 0775, true);
        return $fallback;
    }

    private static function getVideosStoragePath(): string
    {
        return rtrim(self::getStorageBaseDir(), '/') . '/youtube_videos.json';
    }

    private static function getProgressStoragePath(): string
    {
        return rtrim(self::getStorageBaseDir(), '/') . '/youtube_video_progress.json';
    }

    private static function loadJsonMap(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = @file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function saveJsonMap(string $path, array $map): void
    {
        $encoded = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode JSON payload');
        }

        $ok = @file_put_contents($path, $encoded, LOCK_EX);
        if ($ok === false) {
            throw new \RuntimeException('Failed to write JSON payload');
        }
    }

    private static function normalizeVideoId(string $videoId): string
    {
        $videoId = trim($videoId);
        if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
            throw new \InvalidArgumentException('Invalid YouTube video id');
        }
        return $videoId;
    }

    private static function readPayload(): array
    {
        $payload = $_POST;
        if (!empty($payload)) {
            return $payload;
        }

        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function registerRoutes(Router $router, string $appRoot): void
    {
        $router->addRoute('GET', '/youtube-player', [$this, 'index'], $appRoot . '/templates/youtube_player.html.php');
        $router->addRoute('GET', '/youtube-player/videos', [$this, 'listVideos']);
        $router->addRoute('POST', '/youtube-player/videos', [$this, 'addVideo']);
        $router->addRoute('DELETE', '/youtube-player/videos', [$this, 'deleteVideo']);
        $router->addRoute('GET', '/youtube-player/progress', [$this, 'getProgress']);
        $router->addRoute('POST', '/youtube-player/progress', [$this, 'saveProgress']);
    }

    public function index(): array
    {
        return [];
    }

    public function listVideos(): string
    {
        $videosMap = self::loadJsonMap(self::getVideosStoragePath());
        $progressMap = self::loadJsonMap(self::getProgressStoragePath());
        $items = [];

        foreach ($videosMap as $videoId => $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $normalizedId = self::normalizeVideoId((string)($item['id'] ?? $videoId));
            } catch (\InvalidArgumentException) {
                continue;
            }

            $rawProgress = $progressMap[$normalizedId] ?? [];
            $time = max(0, (float)($rawProgress['time'] ?? 0));
            $duration = max(0, (float)($rawProgress['duration'] ?? 0));
            $percent = (int)($rawProgress['percent'] ?? 0);
            if ($percent <= 0 && $duration > 0) {
                $percent = (int)round(($time / $duration) * 100);
            }

            $items[] = [
                'id' => $normalizedId,
                'url' => (string)($item['url'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'time' => $time,
                'duration' => $duration,
                'percent' => min(100, max(0, $percent)),
            ];
        }

        self::jsonResponse(['ok' => true, 'videos' => array_values($items)]);
    }

    public function addVideo(): string
    {
        $payload = self::readPayload();

        $url = trim((string)($payload['url'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));

        try {
            $videoId = self::normalizeVideoId((string)($payload['id'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        if ($url === '') {
            self::jsonResponse(['ok' => false, 'error' => 'URL is required'], 400);
        }

        try {
            $videosMap = self::loadJsonMap(self::getVideosStoragePath());
            $videosMap[$videoId] = [
                'id' => $videoId,
                'url' => $url,
                'title' => $title,
            ];
            self::saveJsonMap(self::getVideosStoragePath(), $videosMap);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        self::jsonResponse([
            'ok' => true,
            'video' => [
                'id' => $videoId,
                'url' => $url,
                'title' => $title,
            ],
        ]);
    }

    public function deleteVideo(): string
    {
        $videoId = (string)($_GET['id'] ?? '');

        try {
            $videoId = self::normalizeVideoId($videoId);
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        try {
            $videosMap = self::loadJsonMap(self::getVideosStoragePath());
            unset($videosMap[$videoId]);
            self::saveJsonMap(self::getVideosStoragePath(), $videosMap);

            $progressMap = self::loadJsonMap(self::getProgressStoragePath());
            unset($progressMap[$videoId]);
            self::saveJsonMap(self::getProgressStoragePath(), $progressMap);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        self::jsonResponse(['ok' => true]);
    }

    public function getProgress(): string
    {
        $videoId = (string)($_GET['id'] ?? '');

        try {
            $videoId = self::normalizeVideoId($videoId);
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $progressMap = self::loadJsonMap(self::getProgressStoragePath());
        $item = is_array($progressMap[$videoId] ?? null) ? $progressMap[$videoId] : [];

        $time = max(0, (float)($item['time'] ?? 0));
        $duration = max(0, (float)($item['duration'] ?? 0));
        $percent = min(100, max(0, (int)($item['percent'] ?? 0)));

        self::jsonResponse([
            'ok' => true,
            'time' => $time,
            'duration' => $duration,
            'percent' => $percent,
        ]);
    }

    public function saveProgress(): string
    {
        $payload = self::readPayload();

        try {
            $videoId = self::normalizeVideoId((string)($payload['id'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        $time = max(0, (float)($payload['time'] ?? 0));
        $duration = max(0, (float)($payload['duration'] ?? 0));
        $percent = 0;
        if ($duration > 0) {
            $percent = (int)round(($time / $duration) * 100);
        }
        $percent = min(100, max(0, $percent));

        try {
            $progressMap = self::loadJsonMap(self::getProgressStoragePath());
            $progressMap[$videoId] = [
                'time' => $time,
                'duration' => $duration,
                'percent' => $percent,
            ];
            self::saveJsonMap(self::getProgressStoragePath(), $progressMap);
        } catch (\RuntimeException $e) {
            self::jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        self::jsonResponse([
            'ok' => true,
            'time' => $time,
            'duration' => $duration,
            'percent' => $percent,
        ]);
    }
}
