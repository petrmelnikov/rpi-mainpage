#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\App;
use App\Media\MediaToolchain;
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
    $state['workerPid'] = $workerPid;
    $state['status'] = ($state['requests'] ?? []) !== [] ? 'queued' : 'idle';
    return $state;
});

$toolchain = new MediaToolchain();
$idleSeconds = max(3, (int)((string)getenv('MEDIA_WORKER_IDLE_SECONDS') ?: 15));
$batchSize = max(1, min(8, (int)((string)getenv('MEDIA_HLS_BATCH_SEGMENTS') ?: 4)));
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
        $requestedMode = (string)($state['mode'] ?? 'software-transcode');
        $actualMode = $requestedMode;
        $command = buildCommand($toolchain, $state, $sessionDir, $segment, $batchSize, $actualMode);
        appendCommandLog($sessionDir, $command, $requestedMode);
        $result = runFfmpeg($command, $sessionId, $sessions, $sessionDir);

        if ($result['exitCode'] !== 0 && $requestedMode === 'hardware-transcode'
            && !($sessions->get($sessionId, false)['stopRequested'] ?? false)) {
            $actualMode = 'software-transcode';
            $sessions->update($sessionId, static function (array $current) use ($result): array {
                $current['fallbackReason'] = 'RKMPP failed; retried with libx264: ' . trim(substr($result['stderr'], -400));
                return $current;
            });
            $command = buildCommand($toolchain, $state, $sessionDir, $segment, $batchSize, $actualMode);
            appendCommandLog($sessionDir, $command, $actualMode);
            $result = runFfmpeg($command, $sessionId, $sessions, $sessionDir);
        }

        if ($result['exitCode'] !== 0) {
            $sessions->update($sessionId, static function (array $current) use ($result): array {
                if (!($current['stopRequested'] ?? false)) {
                    $current['status'] = 'failed';
                    $current['error'] = 'ffmpeg failed: ' . trim(substr($result['stderr'], -800));
                }
                $current['ffmpegPid'] = null;
                return $current;
            });
            continue;
        }

        $initSource = $sessionDir . '/batch-init-' . str_pad((string)$segment, 6, '0', STR_PAD_LEFT) . '.mp4';
        $completed = [];
        for ($i = $segment; $i < $segment + $batchSize; $i++) {
            $suffix = str_pad((string)$i, 6, '0', STR_PAD_LEFT);
            $mediaPath = $sessionDir . '/segment-' . $suffix . '.m4s';
            if (!is_file($mediaPath)) continue;
            $initPath = $sessionDir . '/init-' . $suffix . '.mp4';
            if (is_file($initSource) && !is_file($initPath)) @copy($initSource, $initPath);
            if (is_file($initPath)) $completed[] = $i;
        }
        @unlink($sessionDir . '/batch-' . str_pad((string)$segment, 6, '0', STR_PAD_LEFT) . '.m3u8');

        $sessions->update($sessionId, static function (array $current) use ($completed, $actualMode): array {
            $existing = is_array($current['completedSegments'] ?? null) ? $current['completedSegments'] : [];
            $current['completedSegments'] = array_values(array_unique(array_merge($existing, $completed)));
            sort($current['completedSegments'], SORT_NUMERIC);
            // Browser requests for init/media files in the same batch can arrive while
            // ffmpeg is running. Drop them now instead of regenerating ready artifacts.
            $current['requests'] = array_values(array_filter(
                is_array($current['requests'] ?? null) ? $current['requests'] : [],
                static fn($requested) => !in_array((int)$requested, $completed, true)
            ));
            $current['actualMode'] = $actualMode;
            $current['currentSegment'] = null;
            $current['ffmpegPid'] = null;
            $current['status'] = ($current['requests'] ?? []) !== [] ? 'queued' : 'idle';
            return $current;
        });
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
    $offset = $segment * $segmentDuration;
    $remaining = max(0.1, (float)($state['duration'] ?? 0) - $offset);
    $batchDuration = min($remaining, $batchSize * $segmentDuration + 0.15);
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
    array_push($args, '-ss', number_format($offset, 3, '.', ''), '-i', $source, '-t', number_format($batchDuration, 3, '.', ''));
    array_push($args, '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '-1');

    if ($mode === 'remux' || $mode === 'audio-transcode') {
        array_push($args, '-c:v', 'copy');
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
        '-hls_time', (string)$segmentDuration,
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

/** @return array{exitCode:int,stderr:string} */
function runFfmpeg(array $argv, string $sessionId, TranscodeSessionManager $sessions, string $sessionDir): array
{
    $pipes = [];
    $process = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['pipe', 'w'],
    ], $pipes, $sessionDir, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return ['exitCode' => 127, 'stderr' => 'Unable to start ffmpeg'];
    stream_set_blocking($pipes[2], false);
    $status = proc_get_status($process);
    $pid = (int)$status['pid'];
    $sessions->update($sessionId, static function (array $state) use ($pid): array {
        $state['ffmpegPid'] = $pid;
        return $state;
    });

    $stderr = '';
    while (true) {
        $chunk = (string)stream_get_contents($pipes[2]);
        if ($chunk !== '') {
            $stderr .= $chunk;
            file_put_contents($sessionDir . '/ffmpeg.log', $chunk, FILE_APPEND | LOCK_EX);
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        $state = $sessions->get($sessionId, false);
        if ($state['stopRequested'] ?? false) {
            proc_terminate($process, 15);
            usleep(300000);
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
        }
        usleep(100000);
    }
    $chunk = (string)stream_get_contents($pipes[2]);
    if ($chunk !== '') {
        $stderr .= $chunk;
        file_put_contents($sessionDir . '/ffmpeg.log', $chunk, FILE_APPEND | LOCK_EX);
    }
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode < 0 && $closedCode >= 0) $exitCode = $closedCode;
    return ['exitCode' => $exitCode, 'stderr' => $stderr];
}

function appendCommandLog(string $sessionDir, array $argv, string $mode): void
{
    $record = json_encode(['at' => date(DATE_ATOM), 'mode' => $mode, 'argv' => $argv], JSON_UNESCAPED_SLASHES);
    file_put_contents($sessionDir . '/command.jsonl', $record . PHP_EOL, FILE_APPEND | LOCK_EX);
}
