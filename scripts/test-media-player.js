#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const template = fs.readFileSync(path.join(__dirname, '../templates/file_index.html.php'), 'utf8');
for (const match of template.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
    new vm.Script(match[1], { filename: 'file_index.inline.js' });
}
const start = template.slice(template.indexOf('    async function startHlsPlayback('), template.indexOf('    async function selectVideoSource('));

function fixture(nativeHls = false) {
    const requests = [];
    const stopped = [];
    class Hls {
        static isSupported() { return true; }
        static Events = { ERROR: 'error' };
        static DefaultConfig = { fragLoadPolicy: { default: { maxLoadTimeMs: 120000, timeoutRetry: { maxNumRetry: 4 } } } };
        constructor(config) { this.config = config; }
        on() {}
        loadSource(url) { this.url = url; }
        attachMedia(media) { this.media = media; }
    }
    const context = vm.createContext({
        console, Hls, HLS_MIME_TYPE: 'application/vnd.apple.mpegurl',
        currentMediaPath: '/movie.mkv', playbackRequestId: 1, currentMediaProgressRequest: Promise.resolve(22.5),
        activeTranscodeSessionId: 'old-session', hlsInstance: null, seekSequence: 0, player: {},
        mediaElement: { pause() {}, removeAttribute() {}, querySelectorAll() { return []; }, load() {} },
        browserPlaybackCapabilities() { return { nativeHls, hevc: true, hdr: false }; },
        destroyHlsInstance() {}, showPlaybackError() {},
        stopTranscodeSession(id) { stopped.push(id); return new Promise(() => {}); },
        async fetch(url, options) {
            requests.push({ url, body: JSON.parse(options.body) });
            return { ok: true, async json() { return { sessionId: 'new-session', playlistUrl: '/playlist', startTime: 22.5, segmentWaitSeconds: 45 }; } };
        },
    });
    vm.runInContext(start, context);
    return { context, requests, stopped };
}

(async () => {
    const { context, requests, stopped } = fixture();
    await context.startHlsPlayback('/movie.mkv', 'Movie', 1);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].body.startTime, 22.5, 'send saved position before starting worker');
    assert.equal(context.hlsInstance.config.startPosition, 22.5, 'Hls.js must start fetching at saved position');
    assert.equal(context.hlsInstance.config.fragLoadPolicy.default.maxTimeToFirstByteMs, 50000, 'wait for server generation before retrying');
    assert.equal(context.hlsInstance.config.fragLoadPolicy.default.maxLoadTimeMs, 120000, 'preserve remaining library policy');
    assert.deepEqual(stopped, ['old-session'], 'old stop request must not block new playback');
    const replaced = fixture();
    const replacement = { pause() {}, removeAttribute() {}, querySelectorAll() { return []; }, load() {} };
    replaced.context.player.media = replacement;
    await replaced.context.startHlsPlayback('/movie.mkv', 'Movie', 1);
    assert.equal(replaced.context.hlsInstance.media, replacement, 'attach to the current Plyr node after source replacement');

    const stale = fixture();
    let resolveProgress;
    stale.context.currentMediaProgressRequest = new Promise(resolve => { resolveProgress = resolve; });
    const pending = stale.context.startHlsPlayback('/movie.mkv', 'Movie', 1);
    stale.context.playbackRequestId = 2;
    stale.context.currentMediaPath = '/different.mkv';
    resolveProgress(22.5);
    await pending;
    assert.equal(stale.requests.length, 0, 'do not launch an abandoned selection after awaiting progress');

    const late = fixture();
    late.context.fetch = async () => {
        late.context.playbackRequestId = 2;
        return { ok: true, async json() { return { sessionId: 'late-session', playlistUrl: '/late' }; } };
    };
    await late.context.startHlsPlayback('/movie.mkv', 'Movie', 1);
    assert.ok(late.stopped.includes('late-session'), 'stop a session created after selection was abandoned');
    assert.equal(late.context.hlsInstance, null);

    const native = fixture(true);
    await native.context.startHlsPlayback('/movie.mkv', 'Movie', 1);
    assert.equal(native.context.player.source.sources[0].type, 'application/vnd.apple.mpegurl');
    assert.equal(native.context.hlsInstance, null, 'native HLS must not attach Hls.js');
    console.log('media player startup tests: OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
