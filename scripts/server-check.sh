#!/usr/bin/env bash
# Validate configuration and the running Docker/RKMPP installation.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
# shellcheck disable=SC1091
. scripts/_compose.sh

failed=0

pass() { echo "[OK] $*"; }
fail() { echo "[FAIL] $*" >&2; failed=1; }
note() { echo "[INFO] $*"; }

note "Deployment mode: $(compose_mode)"

if [ -f .env ]; then pass ".env exists"; else fail ".env is missing (copy .env.example)"; fi
if [ -f .env.ssh ]; then pass ".env.ssh exists"; else fail ".env.ssh is missing (run scripts/setup-docker-ssh-key.sh)"; fi

if compose config >/dev/null; then pass "Compose configuration is valid"; else fail "Compose configuration is invalid"; fi

media_root="${HOST_MEDIA_ROOT:-/media}"
state_root="${media_root%/}/.rpi-mainpage-data"
if [ -d "${media_root}" ]; then pass "Host media root exists: ${media_root}"; else fail "Host media root is missing: ${media_root}"; fi
if [ -d "${state_root}/transcodes" ]; then pass "Transcode directory exists: ${state_root}/transcodes"; else fail "Transcode directory is missing: ${state_root}/transcodes"; fi

if [ "${RPI_MAINPAGE_OPI_ENABLED}" -eq 1 ]; then
  for device_path in /dev/dri /dev/dma_heap /dev/mali0 /dev/rga /dev/mpp_service; do
    if [ -e "${device_path}" ]; then pass "Device exists: ${device_path}"; else fail "Device is missing: ${device_path}"; fi
  done
fi

if ! compose ps --status running --services 2>/dev/null | grep -qx app; then
  fail "app container is not running"
fi
if ! compose ps --status running --services 2>/dev/null | grep -qx nginx; then
  fail "nginx container is not running"
fi

if [ "${failed}" -eq 0 ]; then
  if compose exec -T app test -x /usr/local/bin/php; then pass "PHP CLI is available"; else fail "PHP CLI is unavailable"; fi
  if compose exec -T app test -x /usr/local/bin/ffmpeg; then pass "jellyfin-ffmpeg is available"; else fail "jellyfin-ffmpeg is unavailable"; fi
  if compose exec -T app test -x /usr/local/bin/ffprobe; then pass "jellyfin-ffprobe is available"; else fail "jellyfin-ffprobe is unavailable"; fi
  if compose exec -T app sh -lc '[ "$(readlink -f /usr/local/bin/ffmpeg)" = /usr/lib/jellyfin-ffmpeg/ffmpeg ]'; then
    pass "ffmpeg points to the Jellyfin toolchain"
  else
    fail "ffmpeg does not point to /usr/lib/jellyfin-ffmpeg/ffmpeg"
  fi
  if compose exec -T -u 1000:1000 app test -w /media/.rpi-mainpage-data/transcodes; then
    pass "PHP runtime can write transcode sessions"
  else
    fail "UID 1000 cannot write /media/.rpi-mainpage-data/transcodes"
  fi
  if compose exec -T -u 1000:1000 app test -w /app/config; then
    pass "PHP runtime can write application config"
  else
    fail "UID 1000 cannot write /app/config"
  fi
  if compose exec -T -u 1000:1000 app \
    ssh -F /tmp/ssh/config -o BatchMode=yes -o ConnectTimeout=5 remote-target true; then
    pass "Container-to-host SSH connection works"
  else
    fail "Container-to-host SSH connection failed"
  fi

  if [ "${RPI_MAINPAGE_OPI_ENABLED}" -eq 1 ]; then
    if compose exec -T app sh -lc 'ffmpeg -hide_banner -encoders 2>&1 | grep -q h264_rkmpp'; then pass "h264_rkmpp encoder is present"; else fail "h264_rkmpp encoder is missing"; fi
    if compose exec -T app sh -lc 'ffmpeg -hide_banner -decoders 2>&1 | grep -q hevc_rkmpp'; then pass "hevc_rkmpp decoder is present"; else fail "hevc_rkmpp decoder is missing"; fi
    if compose exec -T app sh -lc 'ffmpeg -hide_banner -filters 2>&1 | grep -q rkrga'; then pass "RKRGA filters are present"; else fail "RKRGA filters are missing"; fi
    runtime_user="${APP_RUN_USER:-ubuntu}"
    if compose exec -T app su -s /bin/sh -c \
      'test -r /dev/mpp_service && test -w /dev/mpp_service && test -r /dev/rga && test -w /dev/rga && test -r /dev/mali0 && test -w /dev/mali0' \
      "${runtime_user}"; then
      pass "PHP-FPM user can access RKMPP/RGA/Mali devices"
    else
      fail "PHP-FPM user cannot access one or more RK3588 devices"
    fi
    if compose exec -T app su -s /bin/sh -c \
      "ffmpeg -v error -init_hw_device rkmpp=rk -init_hw_device opencl=ocl@rk -f lavfi -i nullsrc=s=16x16 -frames:v 1 -f null -" \
      "${runtime_user}"; then
      pass "RKMPP to OpenCL interop initializes"
    else
      fail "RKMPP to OpenCL interop failed; HDR tone mapping will fall back to software"
    fi
  fi
fi

if [ "${failed}" -ne 0 ]; then
  echo
  echo "One or more checks failed. Inspect logs with: ./scripts/server-logs.sh" >&2
  exit 1
fi

host_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
if command -v curl >/dev/null 2>&1; then
  if curl --fail --silent --show-error --max-time 5 http://127.0.0.1/ >/dev/null; then
    pass "HTTP endpoint responds on port 80"
  else
    fail "HTTP endpoint does not respond on port 80"
  fi
fi

if [ "${failed}" -ne 0 ]; then
  exit 1
fi
echo
echo "Installation looks healthy: http://${host_ip:-localhost}/"
