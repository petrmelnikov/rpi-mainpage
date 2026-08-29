<?php

namespace App\Media;

/**
 * The single entry point for ffmpeg/ffprobe in the application.
 * Commands are passed to proc_open as argv arrays, never through a shell.
 */
final class MediaToolchain
{
    private string $ffmpeg;
    private string $ffprobe;
    private ?array $capabilities = null;

    public function __construct(?string $ffmpeg = null, ?string $ffprobe = null)
    {
        $this->ffmpeg = $ffmpeg ?: ((string)getenv('MEDIA_FFMPEG_BIN') ?: '/usr/local/bin/ffmpeg');
        $this->ffprobe = $ffprobe ?: ((string)getenv('MEDIA_FFPROBE_BIN') ?: '/usr/local/bin/ffprobe');
    }

    public function ffmpegPath(): string
    {
        return $this->ffmpeg;
    }

    public function ffprobePath(): string
    {
        return $this->ffprobe;
    }

    public function probe(string $path): array
    {
        $result = $this->run([
            $this->ffprobe,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], 45);

        $data = json_decode($result['stdout'], true);
        if ($result['exitCode'] !== 0 || !is_array($data) || !is_array($data['streams'] ?? null)) {
            $detail = trim(substr($result['stderr'] ?: $result['stdout'], 0, 500));
            throw new \RuntimeException('ffprobe failed' . ($detail !== '' ? ': ' . $detail : ''));
        }

        return $data;
    }

    public function capabilities(bool $refresh = false): array
    {
        if (!$refresh && $this->capabilities !== null) {
            return $this->capabilities;
        }

        $cacheFile = rtrim((string)sys_get_temp_dir(), '/') . '/rpi-mainpage-media-capabilities.json';
        if (!$refresh && is_file($cacheFile) && filemtime($cacheFile) > time() - 300) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)
                && ($cached['schemaVersion'] ?? 0) === 2
                && ($cached['binary'] ?? '') === $this->ffmpeg) {
                return $this->capabilities = $cached;
            }
        }

        $encoders = $this->run([$this->ffmpeg, '-hide_banner', '-encoders'], 20);
        $decoders = $this->run([$this->ffmpeg, '-hide_banner', '-decoders'], 20);
        $filters = $this->run([$this->ffmpeg, '-hide_banner', '-filters'], 20);
        $hwaccels = $this->run([$this->ffmpeg, '-hide_banner', '-hwaccels'], 20);
        $allOutput = static fn(array $result): string => $result['stdout'] . "\n" . $result['stderr'];

        $this->capabilities = [
            'schemaVersion' => 2,
            'binary' => $this->ffmpeg,
            'checkedAt' => time(),
            'available' => $encoders['exitCode'] === 0,
            'h264Rkmpp' => str_contains($allOutput($encoders), 'h264_rkmpp'),
            'h264RkmppDecoder' => str_contains($allOutput($decoders), 'h264_rkmpp'),
            'hevcRkmppDecoder' => str_contains($allOutput($decoders), 'hevc_rkmpp'),
            'vp8RkmppDecoder' => str_contains($allOutput($decoders), 'vp8_rkmpp'),
            'vp9RkmppDecoder' => str_contains($allOutput($decoders), 'vp9_rkmpp'),
            'av1RkmppDecoder' => str_contains($allOutput($decoders), 'av1_rkmpp'),
            'mpeg2RkmppDecoder' => str_contains($allOutput($decoders), 'mpeg2_rkmpp'),
            'mpeg4RkmppDecoder' => str_contains($allOutput($decoders), 'mpeg4_rkmpp'),
            'rkmppHwaccel' => str_contains($allOutput($hwaccels), 'rkmpp'),
            'rkrga' => str_contains($allOutput($filters), 'scale_rkrga'),
            'vppRkrga' => str_contains($allOutput($filters), 'vpp_rkrga'),
            'openclToneMap' => str_contains($allOutput($filters), 'tonemap_opencl'),
            'softwareToneMap' => str_contains($allOutput($filters), 'tonemap') && str_contains($allOutput($filters), 'zscale'),
            'devices' => [
                'mpp_service' => file_exists('/dev/mpp_service'),
                'rga' => file_exists('/dev/rga'),
                'dri' => file_exists('/dev/dri'),
                'mali0' => file_exists('/dev/mali0'),
            ],
        ];
        @file_put_contents($cacheFile, json_encode($this->capabilities, JSON_PRETTY_PRINT), LOCK_EX);

        return $this->capabilities;
    }

    /** @return array{exitCode:int,stdout:string,stderr:string,timedOut:bool} */
    public function run(array $argv, int $timeoutSeconds = 0): array
    {
        if ($argv === [] || !is_string($argv[0]) || $argv[0] === '') {
            throw new \InvalidArgumentException('Empty media command');
        }

        $pipes = [];
        $process = @proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start media command: ' . $argv[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
            if ($timeoutSeconds > 0 && microtime(true) - $startedAt >= $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(250000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                $exitCode = 124;
                break;
            }
            usleep(20000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedCode = proc_close($process);
        if ($exitCode < 0 && $closedCode >= 0) {
            $exitCode = $closedCode;
        }

        return compact('exitCode', 'stdout', 'stderr', 'timedOut');
    }
}
