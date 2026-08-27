<?php

namespace App\Media;

use App\App;

final class TranscodeSessionManager
{
    private string $root;
    private int $segmentDuration;
    private int $sessionTtl;
    private int $maxSessions;

    public function __construct(?string $root = null)
    {
        $configuredRoot = trim((string)getenv('MEDIA_TRANSCODE_DIR'));
        $this->root = rtrim($root ?: ($configuredRoot ?: '/media/.rpi-mainpage-data/transcodes'), '/');
        $this->segmentDuration = max(2, min(10, (int)((string)getenv('MEDIA_HLS_SEGMENT_SECONDS') ?: 4)));
        $this->sessionTtl = max(60, (int)((string)getenv('MEDIA_SESSION_TTL_SECONDS') ?: 1800));
        $this->maxSessions = max(1, (int)((string)getenv('MEDIA_MAX_SESSIONS') ?: 3));
    }

    public function create(string $sourcePath, string $relativePath, array $inspection, array $decision): array
    {
        $this->ensureRoot();
        $rootLock = fopen($this->root . '/sessions.lock', 'c+');
        if ($rootLock === false || !flock($rootLock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock transcode session registry');
        }
        try {
            $this->cleanupExpired();
            if ($this->activeSessionCount() >= $this->maxSessions) {
                throw new \RuntimeException('Too many active transcode sessions; try again shortly');
            }

            $id = bin2hex(random_bytes(32));
            $dir = $this->sessionDir($id);
            if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create transcode session');
            }

            $now = time();
            $state = [
                'version' => 1,
                'id' => $id,
                'sourcePath' => $sourcePath,
                'relativePath' => $relativePath,
                'duration' => (float)($inspection['duration'] ?? 0),
                'inspection' => $inspection,
                'decision' => $decision,
                'mode' => (string)($decision['mode'] ?? 'software-transcode'),
                'actualMode' => null,
                'status' => 'starting',
                'createdAt' => $now,
                'lastAccessAt' => $now,
                'workerPid' => null,
                'ffmpegPid' => null,
                'requests' => [0],
                'currentSegment' => null,
                'completedSegments' => [],
                'stopRequested' => false,
                'error' => null,
                'fallbackReason' => null,
                'segmentDuration' => $this->segmentDuration,
            ];
            $this->writeStateFile($dir, $state);
        } finally {
            flock($rootLock, LOCK_UN);
            fclose($rootLock);
        }
        $this->spawnWorker($id);

        return $state;
    }

    public function get(string $id, bool $touch = true): array
    {
        $id = $this->validateId($id);
        $state = $this->readStateFile($this->sessionDir($id));
        if ($touch && !($state['stopRequested'] ?? false)) {
            $state = $this->update($id, static function (array $current): array {
                $current['lastAccessAt'] = time();
                return $current;
            });
        }
        return $state;
    }

    public function update(string $id, callable $mutator): array
    {
        $id = $this->validateId($id);
        $dir = $this->sessionDir($id);
        $lock = fopen($dir . '/state.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock transcode session');
        }
        try {
            $state = $this->readStateFile($dir);
            $updated = $mutator($state);
            if (!is_array($updated)) {
                throw new \RuntimeException('Invalid transcode state update');
            }
            $this->writeStateFile($dir, $updated);
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function requestSegment(string $id, int $segment): array
    {
        $state = $this->update($id, static function (array $current) use ($segment): array {
            if ($current['stopRequested'] ?? false) {
                throw new \RuntimeException('Transcode session has stopped');
            }
            $segmentDuration = max(2, (int)($current['segmentDuration'] ?? 4));
            $segmentCount = max(1, (int)ceil((float)($current['duration'] ?? 0) / $segmentDuration));
            if ($segment < 0 || $segment >= $segmentCount) {
                throw new \InvalidArgumentException('HLS segment is outside the media duration');
            }
            $requests = is_array($current['requests'] ?? null) ? $current['requests'] : [];
            if (!in_array($segment, $requests, true)) {
                $requests[] = $segment;
            }
            sort($requests, SORT_NUMERIC);
            $current['requests'] = $requests;
            $current['lastAccessAt'] = time();
            if (($current['status'] ?? '') !== 'transcoding') {
                $current['status'] = 'queued';
            }
            return $current;
        });
        $this->spawnWorker($id);
        return $state;
    }

    public function takeNextRequest(string $id): ?int
    {
        $next = null;
        $this->update($id, static function (array $current) use (&$next): array {
            $requests = is_array($current['requests'] ?? null) ? $current['requests'] : [];
            if ($requests !== []) {
                sort($requests, SORT_NUMERIC);
                $next = max(0, (int)array_shift($requests));
                $current['requests'] = $requests;
                $current['currentSegment'] = $next;
                $current['status'] = 'transcoding';
                $current['error'] = null;
            }
            return $current;
        });
        return $next;
    }

    public function stop(string $id): array
    {
        return $this->update($id, static function (array $current): array {
            $current['stopRequested'] = true;
            $current['status'] = 'stopping';
            $current['requests'] = [];
            $current['lastAccessAt'] = time();
            return $current;
        });
    }

    public function sessionDir(string $id): string
    {
        return $this->root . '/' . $this->validateId($id);
    }

    public function artifactPath(string $id, string $name): string
    {
        if (!preg_match('/^(?:segment|init)-\d{6}\.(?:m4s|mp4)$/', $name)) {
            throw new \InvalidArgumentException('Invalid HLS artifact name');
        }
        return $this->sessionDir($id) . '/' . $name;
    }

    public function playlist(string $id): string
    {
        $state = $this->get($id);
        $duration = max(0.001, (float)($state['duration'] ?? 0));
        $segmentDuration = max(2, (int)($state['segmentDuration'] ?? $this->segmentDuration));
        $count = max(1, (int)ceil($duration / $segmentDuration));
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:7',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-TARGETDURATION:' . $segmentDuration,
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-INDEPENDENT-SEGMENTS',
        ];
        for ($i = 0; $i < $count; $i++) {
            $name = str_pad((string)$i, 6, '0', STR_PAD_LEFT);
            $length = min((float)$segmentDuration, max(0.001, $duration - ($i * $segmentDuration)));
            // Each independently generated batch has its own init fragment/timeline.
            $lines[] = '#EXT-X-DISCONTINUITY';
            $lines[] = '#EXT-X-MAP:URI="/file-index/transcode/segment?sessionId=' . rawurlencode($id) . '&name=init-' . $name . '.mp4"';
            $lines[] = '#EXTINF:' . number_format($length, 3, '.', '') . ',';
            $lines[] = '/file-index/transcode/segment?sessionId=' . rawurlencode($id) . '&name=segment-' . $name . '.m4s';
        }
        $lines[] = '#EXT-X-ENDLIST';
        return implode("\n", $lines) . "\n";
    }

    public function validateId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid transcode session id');
        }
        return $id;
    }

    public function cleanupExpired(): void
    {
        if (!is_dir($this->root)) return;
        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{64}$/', $id)) continue;
            try {
                $state = $this->readStateFile($dir);
            } catch (\Throwable) {
                continue;
            }
            $last = (int)($state['lastAccessAt'] ?? $state['createdAt'] ?? 0);
            $pid = (int)($state['workerPid'] ?? 0);
            if (time() - $last > $this->sessionTtl && !$this->isProcessRunning($pid)) {
                $this->removeTree($dir);
            }
        }
    }

    private function spawnWorker(string $id): void
    {
        $state = $this->get($id, false);
        if ($this->isProcessRunning((int)($state['workerPid'] ?? 0))) return;

        $script = rtrim(App::getInstance()->appRoot, '/') . '/scripts/media-transcode-worker.php';
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : 'setsid');
        $pipes = [];
        $process = @proc_open(
            [$setsid, '--fork', PHP_BINARY, $script, '--session', $id],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            App::getInstance()->appRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start transcode worker');
        }
        proc_close($process);
    }

    private function readStateFile(string $dir): array
    {
        $path = $dir . '/state.json';
        $raw = @file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            throw new \RuntimeException('Transcode session not found');
        }
        return $state;
    }

    private function writeStateFile(string $dir, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Unable to encode transcode state');
        $tmp = $dir . '/.state-' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $dir . '/state.json')) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write transcode state');
        }
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Transcode directory is not writable');
        }
        if (!is_writable($this->root)) throw new \RuntimeException('Transcode directory is not writable');
    }

    private function activeSessionCount(): int
    {
        $count = 0;
        foreach (glob($this->root . '/*/state.json') ?: [] as $stateFile) {
            $state = json_decode((string)@file_get_contents($stateFile), true);
            if (is_array($state) && !($state['stopRequested'] ?? false)
                && time() - (int)($state['lastAccessAt'] ?? 0) <= $this->sessionTtl) {
                $count++;
            }
        }
        return $count;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 1) return false;
        if (function_exists('posix_kill')) return @posix_kill($pid, 0);
        return is_dir('/proc/' . $pid);
    }

    private function removeTree(string $dir): void
    {
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) $this->removeTree($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
