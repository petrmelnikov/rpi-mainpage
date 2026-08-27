<?php

namespace App\Media;

final class PlaybackPlanner
{
    public function __construct(private readonly MediaToolchain $toolchain)
    {
    }

    public function inspect(string $path): array
    {
        $probe = $this->toolchain->probe($path);
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
        $video = $this->firstStream($probe, 'video');
        $audio = $this->firstStream($probe, 'audio');
        if ($video === null) {
            throw new \RuntimeException('No video stream found');
        }

        $container = strtolower((string)($format['format_name'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $videoCodec = strtolower((string)($video['codec_name'] ?? ''));
        $audioCodec = strtolower((string)($audio['codec_name'] ?? ''));
        $bitDepth = $this->bitDepth($video);
        $transfer = strtolower((string)($video['color_transfer'] ?? ''));
        $primaries = strtolower((string)($video['color_primaries'] ?? ''));
        $hdr = in_array($transfer, ['smpte2084', 'arib-std-b67'], true) || str_contains($primaries, 'bt2020');
        $mime = $this->mime($container, $path);
        $codecs = array_values(array_filter([$this->videoCodecString($video), $this->audioCodecString($audio)]));
        $capabilities = $this->toolchain->capabilities();

        return [
            'duration' => max(0.0, (float)($format['duration'] ?? 0)),
            'container' => $container,
            'source' => [
                'mime' => $mime,
                'codecs' => implode(', ', $codecs),
                'type' => $mime . ($codecs !== [] ? '; codecs="' . implode(', ', $codecs) . '"' : ''),
            ],
            'video' => [
                'codec' => $videoCodec,
                'profile' => (string)($video['profile'] ?? ''),
                'level' => $video['level'] ?? null,
                'width' => (int)($video['width'] ?? 0),
                'height' => (int)($video['height'] ?? 0),
                'pixelFormat' => (string)($video['pix_fmt'] ?? ''),
                'bitDepth' => $bitDepth,
                'hdr' => $hdr,
                'colorTransfer' => $transfer,
            ],
            'audio' => $audio === null ? null : [
                'codec' => $audioCodec,
                'channels' => (int)($audio['channels'] ?? 0),
                'sampleRate' => (int)($audio['sample_rate'] ?? 0),
            ],
            'server' => [
                'hardwareTranscode' => $this->hardwareAvailable($capabilities),
                'rkrga' => (bool)($capabilities['rkrga'] ?? false),
                'toneMapping' => (bool)(($capabilities['openclToneMap'] ?? false) || ($capabilities['softwareToneMap'] ?? false)),
                'toneMappingMode' => ($capabilities['openclToneMap'] ?? false)
                    ? 'opencl'
                    : (($capabilities['softwareToneMap'] ?? false) ? 'software' : null),
            ],
        ];
    }

    public function chooseMode(array $inspection, array $client): array
    {
        $video = (string)($inspection['video']['codec'] ?? '');
        $audio = (string)($inspection['audio']['codec'] ?? '');
        $hdr = (bool)($inspection['video']['hdr'] ?? false);
        $hevcSupported = (bool)($client['hevc'] ?? false);
        $videoCanCopy = $video === 'h264' || ($video === 'hevc' && $hevcSupported);
        // AAC is the only audio codec copied into the universal compatibility HLS.
        $audioCanCopy = in_array($audio, ['', 'aac'], true);

        if ($hdr && !(bool)($inspection['server']['toneMapping'] ?? false)) {
            return [
                'mode' => 'software-transcode',
                'reason' => 'HDR source requires SDR conversion; hardware tone mapping is unavailable',
                'toneMap' => false,
                'warning' => 'HDR tone mapping is unavailable; colors may be inaccurate until OpenCL/zscale support is installed.',
            ];
        }
        if ($hdr) {
            $opencl = ($inspection['server']['toneMappingMode'] ?? null) === 'opencl';
            return [
                'mode' => $opencl && (bool)($inspection['server']['hardwareTranscode'] ?? false)
                    ? 'hardware-transcode'
                    : 'software-transcode',
                'reason' => 'HDR source must be tone-mapped for the compatibility stream',
                'toneMap' => true,
                'toneMapMode' => $opencl ? 'opencl' : 'software',
            ];
        }
        if ($videoCanCopy && $audioCanCopy) {
            return ['mode' => 'remux', 'reason' => 'Codecs are HLS-compatible; only the container changes', 'toneMap' => false];
        }
        if ($videoCanCopy) {
            return ['mode' => 'audio-transcode', 'reason' => 'Video can be copied; audio is converted to AAC', 'toneMap' => false];
        }
        if ((bool)($inspection['server']['hardwareTranscode'] ?? false)) {
            return ['mode' => 'hardware-transcode', 'reason' => 'Video is converted to H.264 using RKMPP', 'toneMap' => false];
        }
        return ['mode' => 'software-transcode', 'reason' => 'Video is converted to H.264 in software', 'toneMap' => false];
    }

    private function firstStream(array $probe, string $type): ?array
    {
        foreach (($probe['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === $type) {
                return $stream;
            }
        }
        return null;
    }

    private function hardwareAvailable(array $capabilities): bool
    {
        return (bool)($capabilities['h264Rkmpp'] ?? false)
            && (bool)($capabilities['devices']['mpp_service'] ?? false);
    }

    private function bitDepth(array $video): int
    {
        $depth = (int)($video['bits_per_raw_sample'] ?? 0);
        if ($depth <= 0 && preg_match('/p(\d{2})(?:le|be)?$/', (string)($video['pix_fmt'] ?? ''), $match)) {
            $depth = (int)$match[1];
        }
        return $depth > 0 ? $depth : 8;
    }

    private function mime(string $format, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (str_contains($format, 'matroska') || $ext === 'mkv') return 'video/x-matroska';
        if (str_contains($format, 'webm') || $ext === 'webm') return 'video/webm';
        if (str_contains($format, 'avi') || $ext === 'avi') return 'video/x-msvideo';
        if ($ext === 'mov') return 'video/quicktime';
        return 'video/mp4';
    }

    private function videoCodecString(array $video): string
    {
        return match (strtolower((string)($video['codec_name'] ?? ''))) {
            'h264' => $this->avcCodecString($video),
            'hevc', 'h265' => 'hvc1',
            'vp9' => 'vp09.00.10.08',
            'av1' => 'av01.0.08M.08',
            default => (string)($video['codec_tag_string'] ?? $video['codec_name'] ?? ''),
        };
    }

    private function avcCodecString(array $video): string
    {
        $profile = strtolower((string)($video['profile'] ?? ''));
        $profileHex = str_contains($profile, 'high') ? '6400' : (str_contains($profile, 'main') ? '4d40' : '42e0');
        $level = max(10, min(255, (int)($video['level'] ?? 31)));
        return 'avc1.' . $profileHex . str_pad(strtolower(dechex($level)), 2, '0', STR_PAD_LEFT);
    }

    private function audioCodecString(?array $audio): string
    {
        if ($audio === null) return '';
        return match (strtolower((string)($audio['codec_name'] ?? ''))) {
            'aac' => 'mp4a.40.2',
            'mp3' => 'mp4a.40.34',
            'opus' => 'opus',
            'vorbis' => 'vorbis',
            'ac3' => 'ac-3',
            'eac3' => 'ec-3',
            default => (string)($audio['codec_name'] ?? ''),
        };
    }
}
