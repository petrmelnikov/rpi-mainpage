#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\App;
use App\Media\MediaToolchain;
use App\Media\SegmentTimeline;
use App\Media\TranscodeSessionManager;

require_once dirname(__DIR__) . '/vendor/autoload.php';

App::getInstance()->appRoot = dirname(__DIR__);

$options = getopt('', ['session:']);
$sessionId = (string)($options['session'] ?? '');
$sessions = new TranscodeSessionManager();

try {
    $sessionDir = $sessions->sessionDir($sessionId);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

$workerLock = fopen($sessionDir . '/worker.lock', 'c+');
if ($workerLock === false || !flock($workerLock, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker owns this session
}

$workerPid = getmypid();
$sessions->update($sessionId, static function (array $state) use ($workerPid): array {
    // A previous worker may have been killed without executing its finally.
    $abandoned = $state['currentSegment'] ?? null;
    if ($abandoned !== null && ($state['currentGeneration'] ?? null) === ($state['generation'] ?? 0)
        && !in_array($abandoned, $state['completedSegments'] ?? [], true)
        && !in_array($abandoned, $state['requests'] ?? [], true)) {
        array_unshift($state['requests'], $abandoned);
    }
    $state['currentSegment'] = null;
    $state['currentGeneration'] = null;
    $state['workerPid'] = $workerPid;
    $state['status'] = ($state['requests'] ?? []) !== [] ? 'queued' : 'idle';
    return $state;
});

$toolchain = new MediaToolchain();
$idleSeconds = max(3, (int)((string)getenv('MEDIA_WORKER_IDLE_SECONDS') ?: 15));
$idleSince = microtime(true);

try {
    while (true) {
        $state = $sessions->get($sessionId, false);
        if ($state['stopRequested'] ?? false) break;

        if (($state['requests'] ?? []) === []) {
            if (microtime(true) - $idleSince >= $idleSeconds) break;
            usleep(150000);
            continue;
        }

        $segment = $sessions->takeNextRequest($sessionId);
        if ($segment === null) {
            usleep(150000);
            continue;
        }

        $idleSince = microtime(true);
        $state = $sessions->get($sessionId, false);
        $batchSize = SegmentTimeline::batchSize($state);
        $generation = (int)($state['currentGeneration'] ?? 0);
        $requestedMode = ($state['hardwareFailed'] ?? false) ? 'software-transcode' : (string)($state['mode'] ?? 'software-transcode');
        $actualMode = $requestedMode;
        // FFmpeg writes privately. Only complete, atomically published artifacts
        // are visible to nginx; an interrupted seek cannot corrupt an older one.
        $workDir = $sessionDir . '/work-' . bin2hex(random_bytes(8));
        if (!mkdir($workDir, 0755)) throw new RuntimeException('Unable to create transcode work directory');
        $command = buildCommand($toolchain, $state, $workDir, $segment, $batchSize, $actualMode);
        appendCommandLog($sessionDir, $command, $requestedMode);
        $result = runFfmpeg($command, $sessionId, $sessions, $sessionDir, $workDir, $segment, $batchSize, $generation);

        if (!$result['cancelled'] && $result['exitCode'] !== 0 && $requestedMode === 'hardware-transcode'
            && !($sessions->get($sessionId, false)['stopRequested'] ?? false)) {
            $actualMode = 'software-transcode';
            $sessions->update($sessionId, static function (array $current) use ($result): array {
                $current['fallbackReason'] = 'RKMPP failed; retried with libx264: ' . trim(substr($result['stderr'], -400));
                $current['hardwareFailed'] = true;
                return $current;
            });
            clearWorkDirectory($workDir, false);
            $command = buildCommand($toolchain, $state, $workDir, $segment, $batchSize, $actualMode);
            appendCommandLog($sessionDir, $command, $actualMode);
            $result = runFfmpeg($command, $sessionId, $sessions, $sessionDir, $workDir, $segment, $batchSize, $generation);
        }

        clearWorkDirectory($workDir);
        $sessions->update($sessionId, static function (array $current) use ($result, $actualMode, $generation): array {
            if (!$result['cancelled'] && $result['exitCode'] !== 0) {
                if (!($current['stopRequested'] ?? false) && (int)($current['generation'] ?? 0) === $generation) {
                    $current['status'] = 'failed';
                    $current['error'] = 'ffmpeg failed: ' . trim(substr($result['stderr'], -800));
                }
            } else {
                $current['status'] = ($current['requests'] ?? []) !== [] ? 'queued' : 'idle';
            }
            if (!$result['cancelled']) $current['actualMode'] = $actualMode;
            $current['currentSegment'] = null;
            $current['currentGeneration'] = null;
            $current['ffmpegPid'] = null;
            return $current;
        });
        $idleSince = microtime(true);
    }
} catch (Throwable $e) {
    $sessions->update($sessionId, static function (array $state) use ($e): array {
        $state['status'] = 'failed';
        $state['error'] = $e->getMessage();
        $state['ffmpegPid'] = null;
        return $state;
    });
} finally {
    $finalState = $sessions->update($sessionId, static function (array $state): array {
        $state['workerPid'] = null;
        $state['ffmpegPid'] = null;
        $state['currentSegment'] = null;
        $state['status'] = ($state['stopRequested'] ?? false) ? 'stopped' : (($state['status'] ?? '') === 'failed' ? 'failed' : 'idle');
        return $state;
    });
    if ($finalState['stopRequested'] ?? false) {
        foreach (['segment-*.m4s', 'init-*.mp4', 'batch-init-*.mp4', 'batch-*.m3u8', '*.tmp'] as $pattern) {
            foreach (glob($sessionDir . '/' . $pattern) ?: [] as $artifact) {
                if (is_file($artifact)) @unlink($artifact);
            }
        }
    }
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    // A request can arrive between the idle check and clearing workerPid.
    // Once the lock is released, ensure that such queued work gets a new owner.
    $pending = $sessions->get($sessionId, false);
    if (!($pending['stopRequested'] ?? false) && ($pending['status'] ?? '') !== 'failed' && !empty($pending['requests'])) {
        $sessions->requestSegment($sessionId, (int)$pending['requests'][0]);
    }
}

function buildCommand(
    MediaToolchain $toolchain,
    array $state,
    string $sessionDir,
    int $segment,
    int $batchSize,
    string &$actualMode
): array {
    $segmentDuration = max(2, (int)($state['segmentDuration'] ?? 4));
    $starts = SegmentTimeline::starts($state);
    $offset = $starts[$segment];
    $batchDuration = ($starts[$segment + $batchSize] ?? (float)$state['duration']) - $offset;
    $source = (string)($state['sourcePath'] ?? '');
    if ($source === '' || !is_file($source)) throw new RuntimeException('Transcode source disappeared');

    $mode = $actualMode;
    $toneMap = (bool)($state['decision']['toneMap'] ?? false);
    $toneMapMode = (string)($state['decision']['toneMapMode'] ?? 'software');
    $capabilities = $toolchain->capabilities();

    $args = [$toolchain->ffmpegPath(), '-hide_banner', '-nostdin', '-loglevel', 'warning', '-y'];
    if ($mode === 'hardware-transcode') {
        // Keep decoded frames on RKMPP/DRM surfaces. For HDR, RKRGA converts
        // Main10 to P010, OpenCL tone-maps through DRM interop, then the frame
        // is reverse-mapped to RKMPP for a zero-copy H.264 hardware encode.
        array_push(
            $args,
            '-init_hw_device', 'rkmpp=rk',
            '-hwaccel', 'rkmpp',
            '-hwaccel_output_format', 'drm_prime',
            '-noautorotate'
        );
    }
    array_push($args, '-ss', number_format($offset, 6, '.', ''), '-i', $source);
    array_push($args, '-t', number_format($batchDuration, 6, '.', ''));
    array_push($args, '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '-1');

    if ($mode === 'remux' || $mode === 'audio-transcode') {
        // Input seeking may land one GOP earlier for B-frame streams. Trim by
        // presentation time, preserving the first keyframe with negative DTS.
        // The packet filter only drops the next GOP's packets; amount=0 leaves
        // every retained packet byte-for-byte unchanged (no video encoding).
        array_push($args, '-c:v', 'copy', '-copypriorss', '0', '-bsf:v',
            "noise=amount=0:drop='gte(pts*tb," . number_format($batchDuration, 6, '.', '') . ")'");
        if (($state['inspection']['video']['codec'] ?? '') === 'hevc') {
            // Apple HLS expects the out-of-band HEVC sample entry.
            array_push($args, '-tag:v', 'hvc1');
        }
    } elseif ($mode === 'hardware-transcode') {
        if ($toneMap && $toneMapMode === 'opencl') {
            array_push(
                $args,
                '-vf',
                'vpp_rkrga=format=p010,'
                    . 'hwmap=derive_device=opencl,'
                    . 'tonemap_opencl=tonemap=bt2390:format=nv12,'
                    . 'hwmap=derive_device=rkmpp:reverse=1,'
                    . 'format=drm_prime'
            );
        } elseif ($capabilities['rkrga'] ?? false) {
            array_push($args, '-vf', 'scale_rkrga=format=nv12');
        }
        array_push($args, '-c:v', 'h264_rkmpp', '-b:v', (string)((string)getenv('MEDIA_H264_BITRATE') ?: '6000k'));
    } else {
        if ($toneMap && ($capabilities['softwareToneMap'] ?? false)) {
            array_push($args, '-vf', 'zscale=t=linear:npl=100,format=gbrpf32le,tonemap=hable:desat=0,zscale=p=bt709:t=bt709:m=bt709:r=tv,format=yuv420p');
        }
        array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p');
    }

    if ($mode === 'remux') array_push($args, '-c:a', 'copy');
    else array_push($args, '-c:a', 'aac', '-b:a', '192k', '-ac', '2');

    if (!in_array($mode, ['remux', 'audio-transcode'], true)) {
        array_push($args, '-force_key_frames', 'expr:gte(t,n_forced*' . $segmentDuration . ')');
    }

    $suffix = str_pad((string)$segment, 6, '0', STR_PAD_LEFT);
    array_push(
        $args,
        '-max_muxing_queue_size', '2048',
        '-f', 'hls',
        '-hls_time', in_array($mode, ['remux', 'audio-transcode'], true) ? (string)ceil($batchDuration + 1) : (string)$segmentDuration,
        '-hls_list_size', '0',
        '-hls_segment_type', 'fmp4',
        '-hls_flags', 'independent_segments+temp_file',
        '-start_number', (string)$segment,
        '-hls_fmp4_init_filename', 'batch-init-' . $suffix . '.mp4',
        '-hls_segment_filename', $sessionDir . '/segment-%06d.m4s',
        $sessionDir . '/batch-' . $suffix . '.m3u8'
    );
    return $args;
}

/** @return array{exitCode:int,stderr:string,cancelled:bool} */
function runFfmpeg(array $argv, string $sessionId, TranscodeSessionManager $sessions, string $sessionDir, string $workDir, int $segment, int $batchSize, int $generation): array
{
    $pipes = [];
    $process = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['pipe', 'w'],
    ], $pipes, $sessionDir, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['exitCode' => 127, 'stderr' => 'Unable to start ffmpeg', 'cancelled' => false];
    try {
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($process);
        $pid = (int)$status['pid'];
        $sessions->update($sessionId, static function (array $state) use ($pid): array {
            $state['ffmpegPid'] = $pid;
            return $state;
        });

        $stderr = '';
        $cancelled = false;
        while (true) {
            $chunk = (string)stream_get_contents($pipes[2]);
            if ($chunk !== '') {
                $stderr = substr($stderr . $chunk, -8192);
                file_put_contents($sessionDir . '/ffmpeg.log', $chunk, FILE_APPEND | LOCK_EX);
            }
            $state = $sessions->get($sessionId, false);
            if (($state['stopRequested'] ?? false) || (int)($state['generation'] ?? 0) !== $generation) {
                $cancelled = true;
                proc_terminate($process, 15);
                usleep(100000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
            }
            if (!$cancelled) publishBatch($sessions, $sessionId, $sessionDir, $workDir, $segment, $batchSize, $generation);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
            usleep(100000);
        }
        $chunk = (string)stream_get_contents($pipes[2]);
        if ($chunk !== '') {
            $stderr = substr($stderr . $chunk, -8192);
            file_put_contents($sessionDir . '/ffmpeg.log', $chunk, FILE_APPEND | LOCK_EX);
        }
        fclose($pipes[2]);
        $closedCode = proc_close($process);
        if ($exitCode < 0 && $closedCode >= 0) $exitCode = $closedCode;
        if (!$cancelled) publishBatch($sessions, $sessionId, $sessionDir, $workDir, $segment, $batchSize, $generation);
        return ['exitCode' => $exitCode, 'stderr' => $stderr, 'cancelled' => $cancelled];
    } finally {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 15);
                usleep(100000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
            }
            if (is_resource($pipes[2])) fclose($pipes[2]);
            proc_close($process);
        }
    }
}

function publishBatch(TranscodeSessionManager $sessions, string $id, string $dir, string $workDir, int $segment, int $batchSize, int $generation): void
{
    $suffix = sprintf('%06d', $segment);
    $playlist = @file_get_contents($workDir . '/batch-' . $suffix . '.m3u8');
    $init = $workDir . '/batch-init-' . $suffix . '.mp4';
    if ($playlist === false || !is_file($init)) return;
    preg_match_all('/^segment-(\d{6})\.m4s\s*$/m', $playlist, $matches);
    $ready = [];
    foreach ($matches[1] as $number) {
        $index = (int)$number;
        if ($index >= $segment && $index < $segment + $batchSize && is_file($workDir . '/segment-' . $number . '.m4s')) $ready[] = $index;
    }
    if ($ready === []) return;
    $sessions->update($id, static function (array $state) use ($ready, $dir, $workDir, $init, $generation): array {
        if (($state['stopRequested'] ?? false) || (int)($state['generation'] ?? 0) !== $generation) return $state;
        foreach ($ready as $index) {
            if (in_array($index, $state['completedSegments'] ?? [], true)) continue;
            $suffix = sprintf('%06d', $index);
            $tmp = $dir . '/init-' . $suffix . '.mp4.tmp';
            if (!copy($init, $tmp) || !rename($tmp, $dir . '/init-' . $suffix . '.mp4')
                || !rename($workDir . '/segment-' . $suffix . '.m4s', $dir . '/segment-' . $suffix . '.m4s')) {
                throw new RuntimeException('Unable to publish HLS segment');
            }
            $state['completedSegments'][] = $index;
        }
        $state['completedSegments'] = array_values(array_unique($state['completedSegments'] ?? []));
        sort($state['completedSegments'], SORT_NUMERIC);
        $state['requests'] = array_values(array_diff($state['requests'] ?? [], $state['completedSegments']));
        return $state;
    });
}

function clearWorkDirectory(string $dir, bool $remove = true): void
{
    foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isFile()) @unlink($item->getPathname());
    }
    if ($remove) @rmdir($dir);
}

function appendCommandLog(string $sessionDir, array $argv, string $mode): void
{
    $record = json_encode(['at' => date(DATE_ATOM), 'mode' => $mode, 'argv' => $argv], JSON_UNESCAPED_SLASHES);
    file_put_contents($sessionDir . '/command.jsonl', $record . PHP_EOL, FILE_APPEND | LOCK_EX);
}
