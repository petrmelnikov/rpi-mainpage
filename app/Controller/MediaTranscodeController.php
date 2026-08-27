<?php

namespace App\Controller;

use App\FileIndexManager;
use App\Media\MediaToolchain;
use App\Media\PlaybackPlanner;
use App\Media\TranscodeSessionManager;
use App\Router;
use App\Support\PathGuard;

final class MediaTranscodeController
{
    private MediaToolchain $toolchain;
    private PlaybackPlanner $planner;
    private TranscodeSessionManager $sessions;

    public function __construct()
    {
        $this->toolchain = new MediaToolchain();
        $this->planner = new PlaybackPlanner($this->toolchain);
        $this->sessions = new TranscodeSessionManager();
    }

    public function registerRoutes(Router $router): void
    {
        $router->addRoute('GET', '/file-index/playback-plan', [$this, 'playbackPlan']);
        $router->addRoute('POST', '/file-index/transcode/start', [$this, 'start']);
        $router->addRoute('GET', '/file-index/transcode/playlist', [$this, 'playlist']);
        $router->addRoute('GET', '/file-index/transcode/segment', [$this, 'segment']);
        $router->addRoute('POST', '/file-index/transcode/stop', [$this, 'stop']);
        $router->addRoute('GET', '/file-index/transcode/status', [$this, 'status']);
    }

    public function playbackPlan(): string
    {
        try {
            [$fullPath, $relativePath] = $this->resolveVideo((string)($_GET['path'] ?? ''));
            $inspection = $this->planner->inspect($fullPath);
            $this->json(['ok' => true, 'path' => $relativePath, 'plan' => $inspection]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function start(): string
    {
        try {
            $body = $this->jsonBody();
            [$fullPath, $relativePath] = $this->resolveVideo((string)($body['path'] ?? ''));
            $client = is_array($body['client'] ?? null) ? $body['client'] : [];
            $inspection = $this->planner->inspect($fullPath);
            if ((float)($inspection['duration'] ?? 0) <= 0) {
                throw new \RuntimeException('Unable to determine video duration for HLS playback');
            }
            $decision = $this->planner->chooseMode($inspection, $client);
            $state = $this->sessions->create($fullPath, $relativePath, $inspection, $decision);
            $id = (string)$state['id'];
            $this->json([
                'ok' => true,
                'sessionId' => $id,
                'playlistUrl' => '/file-index/transcode/playlist?sessionId=' . rawurlencode($id),
                'mode' => $state['mode'],
                'reason' => $decision['reason'] ?? '',
                'warning' => $decision['warning'] ?? null,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 503);
        }
    }

    public function playlist(): string
    {
        try {
            $id = (string)($_GET['sessionId'] ?? '');
            $playlist = $this->sessions->playlist($id);
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Cache-Control: no-store');
            echo $playlist;
            exit;
        } catch (\Throwable $e) {
            http_response_code($e instanceof \InvalidArgumentException ? 400 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }
    }

    public function segment(): string
    {
        try {
            $id = (string)($_GET['sessionId'] ?? '');
            $name = (string)($_GET['name'] ?? '');
            $path = $this->sessions->artifactPath($id, $name);
            if (!is_file($path) && preg_match('/^(?:segment|init)-(\d{6})\./', $name, $match)) {
                $this->sessions->requestSegment($id, (int)$match[1]);
            }

            $waitSeconds = max(5, (int)((string)getenv('MEDIA_SEGMENT_WAIT_SECONDS') ?: 30));
            if (function_exists('set_time_limit')) @set_time_limit($waitSeconds + 5);
            $deadline = microtime(true) + $waitSeconds;
            while (!is_file($path) && microtime(true) < $deadline) {
                $state = $this->sessions->get($id, false);
                if (($state['stopRequested'] ?? false) || (($state['status'] ?? '') === 'failed' && empty($state['requests']))) break;
                usleep(100000);
            }
            if (!is_file($path)) {
                $state = $this->sessions->get($id, false);
                $this->json(['ok' => false, 'error' => $state['error'] ?? 'HLS segment is not ready'], 503);
            }

            header('Content-Type: ' . (str_ends_with($name, '.mp4') ? 'video/mp4' : 'video/iso.segment'));
            header('Cache-Control: private, max-age=3600');
            header('X-Accel-Redirect: /_internal/transcodes/' . $id . '/' . $name);
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    public function stop(): string
    {
        try {
            $body = $this->jsonBody();
            $state = $this->sessions->stop((string)($body['sessionId'] ?? ''));
            $this->json(['ok' => true, 'status' => $state['status']]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    public function status(): string
    {
        try {
            $state = $this->sessions->get((string)($_GET['sessionId'] ?? ''));
            unset($state['sourcePath'], $state['inspection']);
            $this->json(['ok' => true, 'session' => $state]);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    private function resolveVideo(string $relativePath): array
    {
        $segments = PathGuard::toSegments($relativePath);
        $catalog = (new FileIndexManager())->getCatalogPath();
        $catalogReal = realpath($catalog);
        $candidate = PathGuard::joinCatalog($catalog, $segments);
        $real = realpath($candidate);
        if ($catalogReal === false || $real === false || !is_file($real) || !is_readable($real)) {
            throw new \InvalidArgumentException('Video file not found or unreadable');
        }
        $prefix = rtrim($catalogReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) {
            throw new \InvalidArgumentException('Video path is outside the media catalog');
        }
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['mp4', 'm4v', 'webm', 'mov', 'mkv', 'avi', 'ogg'], true)) {
            throw new \InvalidArgumentException('Not a supported video file');
        }
        return [$real, PathGuard::segmentsToRelativePath($segments)];
    }

    private function jsonBody(): array
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) throw new \InvalidArgumentException('Expected a JSON request body');
        return $body;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
