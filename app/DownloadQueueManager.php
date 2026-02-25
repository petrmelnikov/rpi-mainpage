<?php

namespace App;

class DownloadQueueManager
{
    private string $queueFile;

    public function __construct(?string $queueFile = null)
    {
        $base = rtrim((string)sys_get_temp_dir(), '/');
        if ($base === '') {
            $base = '/tmp';
        }

        $envFile = trim((string)getenv('DOWNLOAD_QUEUE_FILE'));
        $this->queueFile = $queueFile
            ?? ($envFile !== '' ? $envFile : ($base . '/rpi-mainpage-download-queue.json'));
    }

    public function enqueue(string $url, string $targetDir, string $targetPath): string
    {
        return $this->withLocked(function (array $data) use ($url, $targetDir, $targetPath): array {
            $id = bin2hex(random_bytes(12));
            $data['jobs'][] = [
                'id' => $id,
                'url' => $url,
                'targetDir' => $targetDir,
                'targetPath' => $targetPath,
                'status' => 'queued',
                'error' => '',
                'tool' => '',
                'mode' => '',
                'createdAt' => time(),
                'startedAt' => null,
                'finishedAt' => null,
            ];

            return [$data, $id];
        });
    }

    public function listJobs(int $limit = 200): array
    {
        return $this->withLocked(function (array $data) use ($limit): array {
            $jobs = $data['jobs'];
            usort($jobs, static fn(array $a, array $b): int => (int)$b['createdAt'] <=> (int)$a['createdAt']);
            return [$data, array_slice($jobs, 0, max(1, $limit))];
        });
    }

    public function claimNextQueued(): ?array
    {
        return $this->withLocked(function (array $data): array {
            $nextIndex = null;
            $nextCreated = PHP_INT_MAX;

            foreach ($data['jobs'] as $i => $job) {
                if (($job['status'] ?? '') !== 'queued') {
                    continue;
                }

                $created = (int)($job['createdAt'] ?? 0);
                if ($created < $nextCreated) {
                    $nextCreated = $created;
                    $nextIndex = $i;
                }
            }

            if ($nextIndex === null) {
                return [$data, null];
            }

            $data['jobs'][$nextIndex]['status'] = 'running';
            $data['jobs'][$nextIndex]['startedAt'] = time();
            $data['jobs'][$nextIndex]['error'] = '';

            return [$data, $data['jobs'][$nextIndex]];
        });
    }

    public function finishJob(string $id, bool $ok, array $meta = []): void
    {
        $this->withLocked(function (array $data) use ($id, $ok, $meta): array {
            foreach ($data['jobs'] as $i => $job) {
                if (($job['id'] ?? '') !== $id) {
                    continue;
                }

                $data['jobs'][$i]['status'] = $ok ? 'done' : 'failed';
                $data['jobs'][$i]['finishedAt'] = time();
                $data['jobs'][$i]['tool'] = (string)($meta['tool'] ?? '');
                $data['jobs'][$i]['mode'] = (string)($meta['mode'] ?? '');
                $data['jobs'][$i]['error'] = (string)($meta['error'] ?? '');
                break;
            }

            return [$data, null];
        });
    }

    private function withLocked(callable $callback)
    {
        $dir = dirname($this->queueFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = fopen($this->queueFile, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open download queue storage');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock download queue storage');
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = ['jobs' => []];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['jobs']) && is_array($decoded['jobs'])) {
                    $data = $decoded;
                }
            }

            [$newData, $result] = $callback($data);

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);

            return $result;
        } finally {
            fclose($fp);
        }
    }
}
