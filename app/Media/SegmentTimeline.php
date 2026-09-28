<?php

namespace App\Media;

/** A stable VOD timeline shared by the playlist, seek requests and FFmpeg. */
final class SegmentTimeline
{
    /** @return ?list<float> Stream-copy boundaries from the container's seek index. */
    public static function copyStarts(string $path, array $inspection, int $targetDuration): ?array
    {
        $cues = (new MatroskaKeyframeIndex())->read($path);
        if ($cues === null || $cues === []) return null;

        $duration = (float)($inspection['duration'] ?? 0);
        $origin = (float)($inspection['startTime'] ?? 0);
        $starts = [0.0];
        foreach ($cues as $cue) {
            $time = round($cue - $origin, 6);
            if ($time >= $duration) break;
            if ($time - $starts[count($starts) - 1] >= $targetDuration) $starts[] = $time;
        }
        // A single cue in a long movie is not a useful random-access index.
        if ($duration > $targetDuration * 2 && count($starts) < 2) return null;
        return $starts;
    }

    /** @return list<float> */
    public static function starts(array $state): array
    {
        if (isset($state['segmentStarts']) && is_array($state['segmentStarts']) && $state['segmentStarts'] !== []) {
            return $state['segmentStarts'];
        }
        $duration = max(0.001, (float)($state['duration'] ?? 0));
        $step = max(2, (int)($state['segmentDuration'] ?? 4));
        $starts = [];
        for ($i = 0, $count = (int)ceil($duration / $step); $i < $count; $i++) $starts[] = (float)($i * $step);
        return $starts;
    }

    public static function segmentAt(array $state, float $time): int
    {
        $starts = self::starts($state);
        $lo = 0;
        $hi = count($starts) - 1;
        while ($lo < $hi) {
            $mid = (int)ceil(($lo + $hi) / 2);
            if ($starts[$mid] <= $time) $lo = $mid;
            else $hi = $mid - 1;
        }
        return $lo;
    }

    public static function batchSize(array $state): int
    {
        // Copy uses exact cue-to-cue cuts; FFmpeg's hls_time is only a target
        // and cannot describe those cuts reliably across a multi-segment batch.
        if (in_array($state['mode'] ?? '', ['remux', 'audio-transcode'], true)) return 1;
        return max(1, min(8, (int)((string)getenv('MEDIA_HLS_BATCH_SEGMENTS') ?: 4)));
    }
}
