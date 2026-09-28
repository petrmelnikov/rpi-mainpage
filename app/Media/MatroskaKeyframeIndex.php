<?php

namespace App\Media;

/**
 * Bounded random-access reader for Matroska keyframe cue timestamps.
 *
 * Reads only EBML metadata (SeekHead/Info/Tracks/Cues) via fopen/fseek,
 * never Cluster payloads and never the whole file.
 */
final class MatroskaKeyframeIndex
{
    private const SEGMENT_ID = 0x18538067;
    private const SEEKHEAD_ID = 0x114D9B74;
    private const SEEK_ID = 0x4DBB;
    private const SEEKID_ID = 0x53AB;
    private const SEEKPOS_ID = 0x53AC;
    private const INFO_ID = 0x1549A966;
    private const TIMESCALE_ID = 0x2AD7B1;
    private const TRACKS_ID = 0x1654AE6B;
    private const TRACKENTRY_ID = 0xAE;
    private const TRACKNUMBER_ID = 0xD7;
    private const TRACKTYPE_ID = 0x83;
    private const CUES_ID = 0x1C53BB6B;
    private const CUEPOINT_ID = 0xBB;
    private const CUETIME_ID = 0xB3;
    private const CUETRACKPOS_ID = 0xB7;
    private const CUETRACK_ID = 0xF7;
    private const CLUSTER_ID = 0x1F43B675;

    private const MAX_META_PAYLOAD = 16777216; // 16 MiB per metadata element
    private const MAX_TOP_ELEMENTS = 5000;
    private const MAX_CHILDREN = 100000;
    private const MAX_SEGMENT_SCAN_BYTES = 16777216; // 16 MiB to locate Segment
    private const MAX_CUES = 100000;

    /** @return list<float>|null */
    public function read(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $stat = @fstat($handle);
            $fileSize = $stat !== false ? (int)($stat['size'] ?? 0) : 0;
            if ($fileSize <= 0 || $fileSize > PHP_INT_MAX) {
                return null;
            }

            $segment = $this->findSegment($handle, $fileSize);
            if ($segment === null) {
                return null;
            }
            [$segDataStart, $segDataEnd] = $segment;

            $scan = $this->scanTopLevel($handle, $segDataStart, $segDataEnd);
            if ($scan === null) {
                return null;
            }
            [$seekMap, $offsets] = $scan;

            // Resolve any missing Info/Tracks/Cues via SeekHead offsets.
            foreach ([self::INFO_ID, self::TRACKS_ID, self::CUES_ID] as $wanted) {
                if (!isset($offsets[$wanted]) && isset($seekMap[$wanted])) {
                    $found = $this->readTargetAt($handle, $seekMap[$wanted], $wanted, $segDataStart, $segDataEnd, $fileSize);
                    if ($found !== null) {
                        $offsets[$wanted] = $found;
                    }
                }
            }

            if (!isset($offsets[self::TRACKS_ID]) || !isset($offsets[self::CUES_ID])) {
                return null;
            }

            $timeScale = 1000000;
            if (isset($offsets[self::INFO_ID])) {
                $buf = $this->readPayload($handle, $offsets[self::INFO_ID], $fileSize);
                if ($buf === null) {
                    return null;
                }
                $timeScale = $this->parseTimecodeScale($buf);
                if ($timeScale === null) {
                    return null;
                }
            }

            $buf = $this->readPayload($handle, $offsets[self::TRACKS_ID], $fileSize);
            if ($buf === null) {
                return null;
            }
            $videoTrack = $this->parseFirstVideoTrack($buf);
            if ($videoTrack === null) {
                return null;
            }

            $buf = $this->readPayload($handle, $offsets[self::CUES_ID], $fileSize);
            if ($buf === null) {
                return null;
            }
            $times = $this->parseCues($buf, $videoTrack, $timeScale);
            if ($times === null || $times === []) {
                return null;
            }

            sort($times, SORT_NUMERIC);
            $out = [];
            $prev = null;
            foreach ($times as $t) {
                if ($prev === null || $t !== $prev) {
                    $out[] = $t;
                }
                $prev = $t;
            }
            return $out;
        } finally {
            @fclose($handle);
        }
    }

    /** @return array{0:int,1:int}|null segment payload [start, end) */
    private function findSegment($handle, int $fileSize): ?array
    {
        $pos = 0;
        $elements = 0;
        while ($pos < $fileSize) {
            if ($pos > self::MAX_SEGMENT_SCAN_BYTES) {
                return null;
            }
            if (++$elements > 32) {
                return null;
            }
            $hdr = $this->readHeaderAt($handle, $pos, $fileSize);
            if ($hdr === null) {
                return null;
            }
            if ($hdr['id'] === self::SEGMENT_ID) {
                $start = $hdr['payloadStart'];
                if ($hdr['unknown']) {
                    return [$start, $fileSize];
                }
                $end = $hdr['payloadEnd'];
                if ($end === null || $end <= $start || $end > $fileSize) {
                    return null;
                }
                return [$start, $end];
            }
            // Only known sizes can be skipped; unknown outside Segment is malformed.
            if ($hdr['unknown'] || $hdr['payloadEnd'] === null || $hdr['payloadEnd'] <= $pos) {
                return null;
            }
            if ($hdr['payloadEnd'] > $fileSize) {
                return null;
            }
            $pos = $hdr['payloadEnd'];
        }
        return null;
    }

    /**
     * Scan Segment children headers only (never reads Cluster payloads).
     * @return array{0:array<int,int>,1:array<int,array{start:int,size:int}>}|null [seekMap, offsets]
     */
    private function scanTopLevel($handle, int $start, int $end): ?array
    {
        $seekMap = [];
        $offsets = [];
        $pos = $start;
        $count = 0;
        while ($pos < $end) {
            if (++$count > self::MAX_TOP_ELEMENTS) {
                break;
            }
            $hdr = $this->readHeaderAt($handle, $pos, $end);
            if ($hdr === null) {
                // Truncated top-level tail: keep what we found; seek jumps may resolve.
                break;
            }
            $id = $hdr['id'];
            if ($hdr['unknown']) return null;
            $size = $hdr['size'];
            if ($id === self::SEEKHEAD_ID) {
                if ($size > self::MAX_META_PAYLOAD) {
                    return null;
                }
                $buf = $this->readPayload($handle, ['start' => $hdr['payloadStart'], 'size' => $size], $end);
                if ($buf === null) {
                    return null;
                }
                $entries = $this->parseSeekHead($buf, $start, $end);
                if ($entries === null) {
                    return null;
                }
                foreach ($entries as $sid => $abs) {
                    if (!isset($seekMap[$sid])) {
                        $seekMap[$sid] = $abs;
                    }
                }
            } elseif ($id === self::INFO_ID || $id === self::TRACKS_ID || $id === self::CUES_ID) {
                if ($size > self::MAX_META_PAYLOAD) {
                    return null;
                }
                if (!isset($offsets[$id])) {
                    $offsets[$id] = ['start' => $hdr['payloadStart'], 'size' => $size];
                }
            }
            // Resolve the seek table before walking media clusters. On ordinary
            // MKVs this jumps directly from the header to the end-of-file Cues.
            foreach ([self::INFO_ID, self::TRACKS_ID, self::CUES_ID] as $wanted) {
                if (!isset($offsets[$wanted]) && isset($seekMap[$wanted])) {
                    $found = $this->readTargetAt($handle, $seekMap[$wanted], $wanted, $start, $end, $end);
                    if ($found !== null) $offsets[$wanted] = $found;
                }
            }
            if (isset($offsets[self::INFO_ID], $offsets[self::TRACKS_ID], $offsets[self::CUES_ID])) break;
            $next = $hdr['payloadEnd'];
            if ($next === null || $next <= $pos || $next > $end) {
                break;
            }
            $pos = $next;
        }
        return [$seekMap, $offsets];
    }

    /** @param array{start:int,size:int} $ref */
    private function readTargetAt($handle, int $abs, int $wanted, int $segStart, int $segEnd, int $fileSize): ?array
    {
        if ($abs < $segStart || $abs >= $segEnd) {
            return null;
        }
        $hdr = $this->readHeaderAt($handle, $abs, $segEnd);
        if ($hdr === null || $hdr['id'] !== $wanted || $hdr['unknown']) {
            return null;
        }
        $size = $hdr['size'];
        if ($size === null || $size < 0 || $size > self::MAX_META_PAYLOAD) {
            return null;
        }
        if ($hdr['payloadEnd'] === null || $hdr['payloadEnd'] > $segEnd || $hdr['payloadEnd'] > $fileSize) {
            return null;
        }
        return ['start' => $hdr['payloadStart'], 'size' => $size];
    }

    /** @param array{start:int,size:int} $ref */
    private function readPayload($handle, array $ref, int $limit): ?string
    {
        $start = $ref['start'];
        $size = $ref['size'];
        if ($start < 0 || $size < 0 || $size > self::MAX_META_PAYLOAD) {
            return null;
        }
        if ($start + $size > $limit || $start + $size < $start) {
            return null;
        }
        if (@fseek($handle, $start, SEEK_SET) !== 0) {
            return null;
        }
        if ($size === 0) {
            return '';
        }
        $buf = @fread($handle, $size);
        if (!is_string($buf) || strlen($buf) !== $size) {
            return null;
        }
        return $buf;
    }

    /** @return array{id:int,idLen:int,size:int|null,sizeLen:int,unknown:bool,payloadStart:int,payloadEnd:int|null}|null */
    private function readHeaderAt($handle, int $pos, int $limit): ?array
    {
        if ($pos < 0 || $pos >= $limit) {
            return null;
        }
        if (@fseek($handle, $pos, SEEK_SET) !== 0) {
            return null;
        }
        $first = @fread($handle, 1);
        if (!is_string($first) || strlen($first) !== 1) {
            return null;
        }
        $b0 = ord($first[0]);
        $idLen = $this->idLength($b0);
        if ($idLen < 1) {
            return null;
        }
        $raw = $first;
        if ($idLen > 1) {
            $rest = @fread($handle, $idLen - 1);
            if (!is_string($rest) || strlen($rest) !== $idLen - 1) {
                return null;
            }
            $raw .= $rest;
        }
        $id = $this->bytesToInt($raw);
        if ($id === null) {
            return null;
        }
        $sb = @fread($handle, 1);
        if (!is_string($sb) || strlen($sb) !== 1) {
            return null;
        }
        $s0 = ord($sb[0]);
        $sizeLen = $this->vintLength($s0);
        if ($sizeLen < 1) {
            return null;
        }
        $sraw = $sb;
        if ($sizeLen > 1) {
            $rest = @fread($handle, $sizeLen - 1);
            if (!is_string($rest) || strlen($rest) !== $sizeLen - 1) {
                return null;
            }
            $sraw .= $rest;
        }
        $size = $this->vintValue($sraw);
        if ($size === null) {
            return null;
        }
        [$value, $unknown] = $size;
        $payloadStart = $pos + $idLen + $sizeLen;
        if ($payloadStart < $pos || $payloadStart > $limit) {
            return null;
        }
        if ($unknown) {
            return ['id' => $id, 'idLen' => $idLen, 'size' => null, 'sizeLen' => $sizeLen, 'unknown' => true, 'payloadStart' => $payloadStart, 'payloadEnd' => null];
        }
        if ($value < 0 || $payloadStart + $value < $payloadStart || $payloadStart + $value > $limit) {
            // Payload may extend past segment end only for Segment itself; here limit is segment end.
            // Allow reading up to file end: caller passes segment end as limit, so overrun is malformed.
            return null;
        }
        return ['id' => $id, 'idLen' => $idLen, 'size' => $value, 'sizeLen' => $sizeLen, 'unknown' => false, 'payloadStart' => $payloadStart, 'payloadEnd' => $payloadStart + $value];
    }

    private function idLength(int $b): int
    {
        if ($b & 0x80) return 1;
        if ($b & 0x40) return 2;
        if ($b & 0x20) return 3;
        if ($b & 0x10) return 4;
        return -1;
    }

    private function vintLength(int $b): int
    {
        if ($b & 0x80) return 1;
        if ($b & 0x40) return 2;
        if ($b & 0x20) return 3;
        if ($b & 0x10) return 4;
        if ($b & 0x08) return 5;
        if ($b & 0x04) return 6;
        if ($b & 0x02) return 7;
        if ($b & 0x01) return 8;
        return -1;
    }

    /** @return array{0:int,1:bool}|null [value, unknown] */
    private function vintValue(string $raw): ?array
    {
        $len = strlen($raw);
        if ($len < 1 || $len > 8) {
            return null;
        }
        $mask = [1 => 0x7F, 2 => 0x3F, 3 => 0x1F, 4 => 0x0F, 5 => 0x07, 6 => 0x03, 7 => 0x01, 8 => 0x00][$len];
        $first = ord($raw[0]) & $mask;
        $allOnes = ($first === $mask);
        $value = $first;
        for ($i = 1; $i < $len; $i++) {
            $byte = ord($raw[$i]);
            if ($byte !== 0xFF) {
                $allOnes = false;
            }
            if ($value > (PHP_INT_MAX >> 8)) {
                return null;
            }
            $value = ($value << 8) | $byte;
        }
        if ($allOnes) {
            // Verify remaining bytes really all 0xFF (first already equals mask).
            return [0, true];
        }
        return [$value, false];
    }

    private function bytesToInt(string $raw): ?int
    {
        $len = strlen($raw);
        if ($len < 1 || $len > 8) {
            return null;
        }
        $v = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($v > (PHP_INT_MAX >> 8)) {
                return null;
            }
            $v = ($v << 8) | ord($raw[$i]);
        }
        return $v;
    }

    /** @return array<int,int>|null map id => absolute segment offset */
    private function parseSeekHead(string $buf, int $segStart, int $segEnd): ?array
    {
        $map = [];
        $children = $this->splitChildren($buf);
        if ($children === null) {
            return null;
        }
        foreach ($children as $child) {
            if ($child['id'] !== self::SEEK_ID) {
                continue;
            }
            $inner = substr($buf, $child['start'], $child['size']);
            $subs = $this->splitChildren($inner);
            if ($subs === null) {
                return null;
            }
            $seekId = null;
            $seekPos = null;
            foreach ($subs as $sub) {
                if ($sub['id'] === self::SEEKID_ID) {
                    $raw = substr($inner, $sub['start'], $sub['size']);
                    if ($sub['size'] < 1 || $sub['size'] > 4) {
                        return null;
                    }
                    $seekId = $this->bytesToInt($raw);
                    if ($seekId === null) {
                        return null;
                    }
                } elseif ($sub['id'] === self::SEEKPOS_ID) {
                    $raw = substr($inner, $sub['start'], $sub['size']);
                    $seekPos = $this->bytesToInt($raw);
                    if ($seekPos === null) {
                        return null;
                    }
                }
            }
            if ($seekId !== null && $seekPos !== null) {
                if ($seekPos < 0 || $segStart + $seekPos < $segStart || $segStart + $seekPos >= $segEnd) {
                    continue; // ignore out-of-range entries, keep scanning
                }
                if (!isset($map[$seekId])) {
                    $map[$seekId] = $segStart + $seekPos;
                }
            }
        }
        return $map;
    }

    private function parseTimecodeScale(string $buf): ?int
    {
        $children = $this->splitChildren($buf);
        if ($children === null) {
            return null;
        }
        foreach ($children as $child) {
            if ($child['id'] === self::TIMESCALE_ID) {
                $raw = substr($buf, $child['start'], $child['size']);
                $v = $this->bytesToInt($raw);
                if ($v === null || $v <= 0) {
                    return null;
                }
                return $v;
            }
        }
        return 1000000;
    }

    private function parseFirstVideoTrack(string $buf): ?int
    {
        $children = $this->splitChildren($buf);
        if ($children === null) {
            return null;
        }
        foreach ($children as $child) {
            if ($child['id'] !== self::TRACKENTRY_ID) {
                continue;
            }
            $inner = substr($buf, $child['start'], $child['size']);
            $subs = $this->splitChildren($inner);
            if ($subs === null) {
                return null;
            }
            $num = null;
            $type = null;
            foreach ($subs as $sub) {
                if ($sub['id'] === self::TRACKNUMBER_ID && $num === null) {
                    $num = $this->bytesToInt(substr($inner, $sub['start'], $sub['size']));
                    if ($num === null || $num <= 0) {
                        return null;
                    }
                } elseif ($sub['id'] === self::TRACKTYPE_ID && $type === null) {
                    $type = $this->bytesToInt(substr($inner, $sub['start'], $sub['size']));
                    if ($type === null) {
                        return null;
                    }
                }
            }
            if ($num !== null && $type === 1) {
                return $num;
            }
        }
        return null;
    }

    /** @return list<float>|null */
    private function parseCues(string $buf, int $videoTrack, int $timeScale): ?array
    {
        if ($timeScale <= 0) {
            return null;
        }
        $children = $this->splitChildren($buf);
        if ($children === null) {
            return null;
        }
        $out = [];
        $points = 0;
        foreach ($children as $child) {
            if ($child['id'] !== self::CUEPOINT_ID) {
                continue;
            }
            if (++$points > self::MAX_CUES) {
                return null;
            }
            $inner = substr($buf, $child['start'], $child['size']);
            $subs = $this->splitChildren($inner);
            if ($subs === null) {
                return null;
            }
            $cueTime = null;
            $isVideo = false;
            foreach ($subs as $sub) {
                if ($sub['id'] === self::CUETIME_ID && $cueTime === null) {
                    $cueTime = $this->bytesToInt(substr($inner, $sub['start'], $sub['size']));
                    if ($cueTime === null || $cueTime < 0) {
                        return null;
                    }
                } elseif ($sub['id'] === self::CUETRACKPOS_ID) {
                    $posBuf = substr($inner, $sub['start'], $sub['size']);
                    $posSubs = $this->splitChildren($posBuf);
                    if ($posSubs === null) {
                        return null;
                    }
                    foreach ($posSubs as $ps) {
                        if ($ps['id'] === self::CUETRACK_ID) {
                            $track = $this->bytesToInt(substr($posBuf, $ps['start'], $ps['size']));
                            if ($track === null) {
                                return null;
                            }
                            if ($track === $videoTrack) {
                                $isVideo = true;
                            }
                        }
                    }
                }
            }
            if ($cueTime !== null && $isVideo) {
                $out[] = (float)($cueTime * $timeScale / 1e9);
            }
        }
        return $out;
    }

    /** @return list<array{id:int,start:int,size:int,next:int}>|null */
    private function splitChildren(string $buf): ?array
    {
        $out = [];
        $off = 0;
        $n = strlen($buf);
        $count = 0;
        while ($off < $n) {
            if (++$count > self::MAX_CHILDREN) {
                return null;
            }
            $b0 = ord($buf[$off]);
            $idLen = $this->idLength($b0);
            if ($idLen < 1 || $off + $idLen > $n) {
                return null;
            }
            $id = $this->bytesToInt(substr($buf, $off, $idLen));
            if ($id === null) {
                return null;
            }
            $off += $idLen;
            if ($off >= $n) {
                return null;
            }
            $s0 = ord($buf[$off]);
            $sizeLen = $this->vintLength($s0);
            if ($sizeLen < 1 || $off + $sizeLen > $n) {
                return null;
            }
            $size = $this->vintValue(substr($buf, $off, $sizeLen));
            if ($size === null) {
                return null;
            }
            [$value, $unknown] = $size;
            if ($unknown) {
                return null; // unknown sizes rejected for metadata payloads
            }
            $off += $sizeLen;
            if ($value < 0 || $off + $value < $off || $off + $value > $n) {
                return null;
            }
            $out[] = ['id' => $id, 'start' => $off, 'size' => $value, 'next' => $off + $value];
            $off += $value;
        }
        return $out;
    }
}
