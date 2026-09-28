#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Media\MediaToolchain;
use App\Media\PlaybackPlanner;
use App\Media\MatroskaKeyframeIndex;
use App\Media\SegmentTimeline;
use App\Media\TranscodeSessionManager;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Session process guards reserve PID 1; run fixtures as a normal child when
// this script is the entrypoint of a disposable test container.
if (getmypid() === 1) {
    $process = proc_open([PHP_BINARY, __FILE__, ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes);
    exit(is_resource($process) ? proc_close($process) : 1);
}
App\App::getInstance()->appRoot = dirname(__DIR__);

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function until(callable $condition, string $message, float $seconds = 10): void {
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache();
        if ($condition()) return;
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($message);
}
function removeFixture(string $path): void {
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) removeFixture($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}
function runWorker(string $id): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__ . '/media-transcode-worker.php', '--session', $id],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'worker must start');
    return [$process, $pipes];
}
function stopWorker(TranscodeSessionManager $sessions, string $id, array $worker): void {
    [$process, $pipes] = $worker;
    $sessions->stop($id);
    until(static fn() => !proc_get_status($process)['running'], 'worker must stop promptly', 5);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    check($output === '', 'unexpected worker output: ' . $output);
}
function seedSession(string $root, string $source, array $extra = []): string {
    $id = bin2hex(random_bytes(32));
    mkdir($root . '/' . $id, 0755, true);
    file_put_contents($root . '/' . $id . '/state.json', json_encode(array_merge([
        'id' => $id, 'sourcePath' => $source, 'duration' => 400.0, 'segmentDuration' => 4,
        'mode' => 'software-transcode', 'decision' => ['toneMap' => false],
        'inspection' => ['video' => ['codec' => 'h264']], 'requests' => [0], 'completedSegments' => [],
        'generation' => 0, 'workerPid' => getmypid(), 'stopRequested' => false, 'status' => 'queued',
        'lastAccessAt' => time(), 'createdAt' => time(),
    ], $extra)));
    return $id;
}
function ebml(int $id, string $payload): string {
    $size = strlen($payload);
    $width = 1;
    while ($size >= (1 << (7 * $width)) - 1) $width++;
    $encoded = $size | (1 << (7 * $width));
    $bytes = '';
    for ($i = 0; $i < $width; $i++) { $bytes = chr($encoded & 255) . $bytes; $encoded >>= 8; }
    return hex2bin(dechex($id)) . $bytes . $payload;
}
function cue(int $time, int $track): string {
    return ebml(0xBB, ebml(0xB3, pack('N', $time)) . ebml(0xB7, ebml(0xF7, chr($track)) . ebml(0xF1, "\x00")));
}

$root = sys_get_temp_dir() . '/rpi-playback-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
putenv('MEDIA_FFPROBE_CACHE_DIR=' . $root . '/probe-cache');
putenv('MEDIA_TRANSCODE_DIR=' . $root . '/sessions');
putenv('MEDIA_WORKER_IDLE_SECONDS=3');
putenv('MEDIA_HLS_BATCH_SEGMENTS=4');
putenv('PLAYBACK_TEST_ROOT=' . $root);
$fake = $root . '/media-tool';
file_put_contents($fake, '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$root = getenv('PLAYBACK_TEST_ROOT');
if (in_array('-show_streams', $argv, true)) {
    file_put_contents($root . '/probes', "probe\n", FILE_APPEND | LOCK_EX);
    usleep(150000);
    if (str_contains(end($argv), 'bad-media')) { fwrite(STDERR, 'invalid file'); exit(1); }
    echo json_encode(['format' => ['format_name' => 'matroska,webm', 'duration' => 400], 'streams' => [
        ['codec_type' => 'video', 'codec_name' => 'vp9'], ['codec_type' => 'audio', 'codec_name' => 'opus']
    ]]);
    exit;
}
foreach (['-encoders', '-decoders', '-filters', '-hwaccels'] as $flag) {
    if (in_array($flag, $argv, true)) { echo 'libx264 aac'; exit; }
}
if (in_array('h264_rkmpp', $argv, true)) { fwrite(STDERR, 'simulated unavailable hardware'); exit(1); }
$value = static fn($flag) => $argv[array_search($flag, $argv, true) + 1];
$start = (int)$value('-start_number');
$pattern = $value('-hls_segment_filename');
$dir = dirname($pattern);
file_put_contents($dir . '/' . $value('-hls_fmp4_init_filename'), 'complete init');
$playlist = "#EXTM3U\n";
$count = in_array('copy', $argv, true) ? 1 : 4;
for ($i = $start; $i < $start + $count; $i++) {
    usleep($i === $start ? 100000 : 900000);
    file_put_contents(sprintf($pattern, $i), 'complete media ' . $i);
    $playlist .= '#EXTINF:4,' . "\n" . sprintf('segment-%06d.m4s', $i) . "\n";
    file_put_contents(end($argv) . '.tmp', $playlist);
    rename(end($argv) . '.tmp', end($argv));
}
PHP);
chmod($fake, 0700);
putenv('MEDIA_FFMPEG_BIN=' . $fake);
putenv('MEDIA_FFPROBE_BIN=' . $fake);
$source = $root . '/video.webm';
file_put_contents($source, 'fixture');
$workers = [];
$sessions = new TranscodeSessionManager();
try {
    $tool = new MediaToolchain();
    $tool->probe($source);
    (new MediaToolchain())->probe($source);
    $probeCount = static fn() => substr_count(file_get_contents($root . '/probes'), "probe\n");
    check($probeCount() === 1, 'probe cache must survive separate toolchain instances');
    file_put_contents($source, 'changed size');
    $tool->probe($source);
    check($probeCount() === 2, 'size change must invalidate cache in the same PHP process');
    $mtime = filemtime($source);
    file_put_contents($source . '.replacement', 'changed size');
    touch($source . '.replacement', $mtime);
    rename($source . '.replacement', $source);
    $tool->probe($source);
    check($probeCount() === 3, 'atomic replacement with same size/mtime must invalidate cache');
    touch($fake, time() + 10);
    $tool->probe($source);
    check($probeCount() === 4, 'binary upgrade must invalidate cache');
    $cache = glob($root . '/probe-cache/*.json')[0];
    file_put_contents($cache, '{invalid');
    $tool->probe($source);
    check($probeCount() === 5, 'corrupt cache must be repaired');
    $bad = $root . '/bad-media.webm';
    file_put_contents($bad, 'invalid');
    for ($i = 0; $i < 2; $i++) {
        try { $tool->probe($bad); throw new LogicException('bad probe unexpectedly succeeded'); }
        catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ffprobe failed'), 'preserve probe failure'); }
    }
    check($probeCount() === 7, 'probe failures must never be cached');
    putenv('MEDIA_FFPROBE_CACHE_DIR=' . $source);
    $tool->probe($source);
    check($probeCount() === 8, 'unwritable cache must fall back to probing');
    putenv('MEDIA_FFPROBE_CACHE_DIR=' . $root . '/probe-cache');
    $planner = new PlaybackPlanner($tool);
    check($planner->inspect($source)['source']['mime'] === 'video/webm', 'combined demuxer name must preserve WebM');
    $mkv = $root . '/video.mkv'; copy($source, $mkv);
    check($planner->inspect($mkv)['source']['mime'] === 'video/x-matroska', 'MKV must remain Matroska');
    $ogg = $root . '/video.ogg'; copy($source, $ogg);
    check($planner->inspect($ogg)['source']['mime'] === 'video/ogg', 'Ogg must use video/ogg');
    check((new MatroskaKeyframeIndex())->read($mkv) === null, 'invalid EBML must return no index');
    $info = ebml(0x1549A966, ebml(0x2AD7B1, pack('N', 2000000)));
    $tracks = ebml(0x1654AE6B, ebml(0xAE, ebml(0xD7, "\x01") . ebml(0x83, "\x02"))
        . ebml(0xAE, ebml(0xD7, "\x02") . ebml(0x83, "\x01")));
    $cues = ebml(0x1C53BB6B, cue(5000, 2) . cue(1000, 1) . cue(0, 2) . cue(5000, 2) . cue(10000, 2));
    $seekEntry = static fn($id, $position) => ebml(0x4DBB, ebml(0x53AB, hex2bin(dechex($id))) . ebml(0x53AC, pack('J', $position)));
    $seekHead = ebml(0x114D9B74, $seekEntry(0x1549A966, 0) . $seekEntry(0x1654AE6B, 0) . $seekEntry(0x1C53BB6B, 0));
    $cluster = hex2bin('1f43b675ff') . str_repeat('unparsed cluster', 100);
    $seekHead = ebml(0x114D9B74, $seekEntry(0x1549A966, strlen($seekHead))
        . $seekEntry(0x1654AE6B, strlen($seekHead) + strlen($info))
        . $seekEntry(0x1C53BB6B, strlen($seekHead) + strlen($info) + strlen($tracks) + strlen($cluster)));
    $indexed = ebml(0x1A45DFA3, '') . hex2bin('18538067ff') . $seekHead . $info . $tracks . $cluster . $cues;
    file_put_contents($mkv, $indexed);
    check((new MatroskaKeyframeIndex())->read($mkv) === [0.0, 10.0, 20.0], 'SeekHead must bypass unknown-sized Cluster, honor scale, sort/deduplicate and ignore audio');
    file_put_contents($mkv, substr($indexed, 0, -1));
    check((new MatroskaKeyframeIndex())->read($mkv) === null, 'truncated Cues must be rejected');

    $concurrent = $root . '/concurrent.webm'; file_put_contents($concurrent, 'fixture');
    $beforeProbes = $probeCount();
    $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; (new App\\Media\\MediaToolchain())->probe(' . var_export($concurrent, true) . ');';
    $jobs = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $jobs[] = [$process, $pipes];
    }
    foreach ($jobs as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $output === '', 'concurrent probe must succeed');
    }
    check($probeCount() === $beforeProbes + 1, 'concurrent requests must share one probe');

    $prefetchId = seedSession($root . '/sessions', $source, ['mode' => 'remux', 'requests' => [],
        'currentSegment' => 0, 'currentGeneration' => 0, 'status' => 'transcoding']);
    $prefetch = $sessions->requestSegment($prefetchId, 1);
    check($prefetch['generation'] === 0 && $prefetch['requests'] === [1], 'adjacent native-HLS prefetch must not cancel current remux');
    $sessions->stop($prefetchId);
    $withinId = seedSession($root . '/sessions', $source, ['requests' => [], 'completedSegments' => [0],
        'currentSegment' => 0, 'currentGeneration' => 0, 'status' => 'transcoding']);
    $within = $sessions->seek($withinId, 13, 1);
    check($within['generation'] === 1 && $within['requests'] === [3], 'seek within the active batch must skip earlier unfinished segments');
    $sessions->stop($withinId);
    $crashedId = seedSession($root . '/sessions', $source, ['requests' => [], 'currentSegment' => 4, 'currentGeneration' => 0]);
    $crashedWorker = runWorker($crashedId); $workers[$crashedId] = $crashedWorker;
    until(static fn() => is_file($sessions->sessionDir($crashedId) . '/segment-000004.m4s'), 'replacement worker must reclaim abandoned work');
    stopWorker($sessions, $crashedId, $crashedWorker); unset($workers[$crashedId]);

    $id = seedSession($root . '/sessions', $source, ['mode' => 'hardware-transcode']);
    $worker = runWorker($id); $workers[$id] = $worker;
    $dir = $sessions->sessionDir($id);
    until(static fn() => is_file($dir . '/segment-000000.m4s'), 'first segment should become ready');
    check(is_file($dir . '/init-000000.mp4'), 'init must accompany the first ready segment');
    check(!is_file($dir . '/segment-000003.m4s'), 'first segment must be published before the batch completes');
    $before = microtime(true);
    $sessions->seek($id, 201.0, 2);
    $sessions->seek($id, 0, 1); // Delayed, stale request must not rewind work.
    until(static fn() => is_file($dir . '/segment-000050.m4s'), 'seek target should be generated promptly', 2);
    check(microtime(true) - $before < 2, 'seek must interrupt the old three-second batch');
    check(!is_file($dir . '/segment-000003.m4s'), 'abandoned batch must not finish');
    $commands = array_map(static fn($line) => json_decode($line, true), file($dir . '/command.jsonl', FILE_IGNORE_NEW_LINES));
    check(count(array_filter($commands, static fn($c) => $c['mode'] === 'hardware-transcode')) === 1, 'failed hardware must not be retried on every seek');
    stopWorker($sessions, $id, $worker); unset($workers[$id]);

    $timeline = seedSession($root . '/sessions', $source, ['duration' => 25.0, 'segmentStarts' => [0.0, 10.0, 20.0], 'startTime' => 16.0]);
    $playlist = $sessions->playlist($timeline);
    check(str_contains($playlist, '#EXT-X-TARGETDURATION:10'), 'target duration must reflect cue spacing');
    check(substr_count($playlist, '#EXTINF:10.000000,') === 2 && str_contains($playlist, '#EXTINF:5.000000,'), 'playlist must preserve real cue intervals');
    check(str_contains($playlist, '#EXT-X-START:TIME-OFFSET=16.000000'), 'native HLS must start at saved time');
    check(SegmentTimeline::segmentAt($sessions->get($timeline), 16) === 1, 'seek must use cue boundaries, not a fixed 4s grid');

    if (in_array('--ffmpeg', $argv, true)) {
        $ffmpeg = getenv('TEST_FFMPEG_BIN') ?: '/usr/bin/ffmpeg';
        $ffprobe = getenv('TEST_FFPROBE_BIN') ?: '/usr/bin/ffprobe';
        check(is_executable($ffmpeg) && is_executable($ffprobe), 'real FFmpeg and FFprobe are required');
        putenv('MEDIA_FFMPEG_BIN=' . $ffmpeg); putenv('MEDIA_FFPROBE_BIN=' . $ffprobe);
        $real = new MediaToolchain();
        $movie = $root . '/long-gop.mkv';
        $generated = $real->run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=25',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '35', '-c:v', 'libx264',
            '-preset', 'veryfast', '-g', '250', '-keyint_min', '250', '-sc_threshold', '0', '-c:a', 'aac', '-y', $movie], 30);
        check($generated['exitCode'] === 0, 'fixture generation: ' . $generated['stderr']);
        $inspection = (new PlaybackPlanner($real))->inspect($movie);
        $starts = SegmentTimeline::copyStarts($movie, $inspection, 4);
        check($starts !== null && count($starts) === 4 && abs($starts[1] - 10) < 0.1, 'read keyframes directly from real MKV Cues');
        $id = seedSession($root . '/sessions', $movie, ['mode' => 'remux', 'duration' => $inspection['duration'],
            'inspection' => $inspection, 'segmentStarts' => $starts, 'requests' => [2], 'startTime' => 22]);
        $worker = runWorker($id); $workers[$id] = $worker;
        $dir = $sessions->sessionDir($id);
        until(static fn() => is_file($dir . '/segment-000002.m4s'), 'real remux should start at resume segment');
        check(!is_file($dir . '/segment-000000.m4s'), 'resume must not prepare the movie beginning');
        $fragment = $root . '/joined.mp4';
        file_put_contents($fragment, file_get_contents($dir . '/init-000002.mp4') . file_get_contents($dir . '/segment-000002.m4s'));
        $decoded = $real->run([$ffmpeg, '-v', 'error', '-i', $fragment, '-f', 'null', '-'], 15);
        check($decoded['exitCode'] === 0 && trim($decoded['stderr']) === '', 'remux fragment must decode independently: ' . $decoded['stderr']);
        $probe = $real->probe($fragment);
        $video = array_values(array_filter($probe['streams'], static fn($s) => $s['codec_type'] === 'video'))[0];
        $packets = $real->run([$ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_packets', '-of', 'json', $fragment], 15);
        $packets = json_decode($packets['stdout'], true)['packets'];
        $firstPts = min(array_map(static fn($p) => (float)$p['pts_time'], $packets));
        $lastPts = max(array_map(static fn($p) => (float)$p['pts_time'] + (float)$p['duration_time'], $packets));
        $span = $lastPts - $firstPts;
        check(str_contains($packets[0]['flags'], 'K') && $firstPts < 0.2, 'seek must preserve its first keyframe, not begin at the following GOP');
        check(count($packets) === 250, 'exactly one 10-second GOP must be copied at 25 fps');
        check(abs($span - ($starts[3] - $starts[2])) < 0.002, 'presentation span must match cue interval: ' . $span);
        $originalFrame = $real->run([$ffmpeg, '-v', 'error', '-ss', (string)$starts[2], '-i', $movie, '-frames:v', '1', '-an', '-f', 'framemd5', '-'], 15);
        $copiedFrame = $real->run([$ffmpeg, '-v', 'error', '-i', $fragment, '-frames:v', '1', '-an', '-f', 'framemd5', '-'], 15);
        $frameHash = static function (string $text): string { $lines = preg_grep('/^[^#].*,/', explode("\n", $text)); return trim(substr((string)end($lines), -32)); };
        check($frameHash($originalFrame['stdout']) !== '' && $frameHash($originalFrame['stdout']) === $frameHash($copiedFrame['stdout']), 'first decoded frame must match the requested source position');
        $commands = array_map(static fn($line) => json_decode($line, true), file($dir . '/command.jsonl', FILE_IGNORE_NEW_LINES));
        check($commands[0]['mode'] === 'remux' && in_array('copy', $commands[0]['argv'], true), 'compatible MKV must remain stream copy');
        $sessions->seek($id, 11, 1);
        until(static fn() => is_file($dir . '/segment-000001.m4s'), 'backward seek must produce its own cue-aligned fragment');
        file_put_contents($fragment, file_get_contents($dir . '/init-000001.mp4') . file_get_contents($dir . '/segment-000001.m4s'));
        $decoded = $real->run([$ffmpeg, '-v', 'error', '-i', $fragment, '-f', 'null', '-'], 15);
        check($decoded['exitCode'] === 0 && trim($decoded['stderr']) === '', 'backward seek must decode independently');
        stopWorker($sessions, $id, $worker); unset($workers[$id]);
        echo "real FFmpeg long-GOP MKV: OK\n";
    }
    echo "media playback regression tests: OK\n";
} finally {
    foreach ($workers as $id => $worker) stopWorker($sessions, $id, $worker);
    removeFixture($root);
}
