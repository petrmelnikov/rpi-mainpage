# Browser-compatible video transcoding

The file browser keeps its existing byte-range `/file-index/stream` path for media the browser can play. For unsupported video it creates a private dynamic-HLS session and uses the pinned Jellyfin FFmpeg build in the `app` container. Jellyfin Server is not installed and is not a runtime dependency.

## Orange Pi 5 Plus deployment

Use the hardware-device override on RK3588:

```bash
docker compose -f docker-compose.yml -f docker-compose.opi.yml up -d --build
```

The base compose deliberately has no `/dev` mappings so Docker Desktop and amd64 development machines still start. The OPI override passes `/dev/dri`, `/dev/dma_heap`, `/dev/mali0`, `/dev/rga`, and `/dev/mpp_service` to `app`.

The image pins `jellyfin-ffmpeg` 7.1.4-3 and verifies the official arm64/amd64 Debian artifacts with SHA256. On arm64 it also installs the pinned Mali G610 userspace package used by the official Jellyfin image for OpenCL tone mapping. To upgrade, change the version and both architecture checksums together in `docker/php/Dockerfile`; a stale or wrong checksum fails the build.

Verify the OPI runtime after deployment:

```bash
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ffmpeg -version
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ffmpeg -hide_banner -encoders | grep rkmpp
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ffmpeg -hide_banner -filters | grep rkrga
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ls -l /dev/mpp_service /dev/rga /dev/dri /dev/mali0
```

Both the existing Video Info action and HLS use `/usr/local/bin/ffmpeg` and `/usr/local/bin/ffprobe`, which point to `/usr/lib/jellyfin-ffmpeg`. There is no Debian `ffmpeg` package alongside it.

## Playback flow

1. The browser requests `/file-index/playback-plan`. `ffprobe` reports the real container, codecs, profile, bit depth, HDR metadata, and duration.
2. JavaScript checks the returned MIME type plus codec string with `canPlayType()`. Supported media stays on the original direct Range stream.
3. Unsupported media starts a session through `/file-index/transcode/start`. Safari/iOS uses native HLS; other supported browsers use pinned `hls.js`.
4. A detached CLI worker generates a bounded group of fMP4 segments. A seek to a missing segment queues a new group starting near that position. State updates are atomic and one worker lock prevents duplicate FFmpeg processes.
5. PHP validates every session/artifact request. Nginx serves only validated ready files through an `internal` alias and `X-Accel-Redirect`.
6. Closing/changing the player requests session stop. Idle session data is removed opportunistically after its TTL.

Selection modes are remux, audio-only AAC conversion, RKMPP decode/H.264 encode, and libx264 fallback. If RKMPP fails, the worker records the failure in `state.json`/`ffmpeg.log` and retries that batch in software. HEVC is copied only when the client reports `hvc1` support. HDR selects tone mapping when the installed filters expose it; otherwise the API returns an explicit warning and compatibility fallback.

## Configuration

Environment variables and defaults:

- `MEDIA_FFMPEG_BIN=/usr/local/bin/ffmpeg`
- `MEDIA_FFPROBE_BIN=/usr/local/bin/ffprobe`
- `MEDIA_PHP_CLI_BIN=/usr/local/bin/php`
- `MEDIA_TRANSCODE_DIR=/media/.rpi-mainpage-data/transcodes`
- `MEDIA_HLS_SEGMENT_SECONDS=4`
- `MEDIA_HLS_BATCH_SEGMENTS=4`
- `MEDIA_SEGMENT_WAIT_SECONDS=30`
- `MEDIA_WORKER_IDLE_SECONDS=15`
- `MEDIA_MAX_SESSIONS=3`
- `MEDIA_SESSION_TTL_SECONDS=1800`
- `MEDIA_H264_BITRATE=6000k`

Session directories contain `state.json`, lock files, `command.jsonl`, `ffmpeg.log`, and ready HLS fragments. They must remain writable by the PHP runtime user and readable by the Nginx bind mount.

## Checks and operational limitations

Run local structural tests with:

```bash
php scripts/test-media-transcoding.php
docker compose config
docker compose -f docker-compose.yml -f docker-compose.opi.yml config
```

The dynamic playlist uses independent on-demand batches and discontinuities so seeking does not require transcoding the entire file. Exact copy/remux boundaries still depend on source keyframes; difficult files may fall back to encoding, and real RKMPP/RGA/OpenCL behavior must be validated on the OPI kernel and driver combination. Subtitles are currently omitted from the compatibility stream. Only the first video and audio tracks are selected.
