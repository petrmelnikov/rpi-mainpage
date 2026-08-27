#!/usr/bin/env bash
# First installation after cloning the repository on the server.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

for required_command in git ssh ssh-keygen base64; do
  if ! command -v "${required_command}" >/dev/null 2>&1; then
    echo "Missing required command: ${required_command}" >&2
    exit 1
  fi
done

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  echo "Docker Engine with the Compose plugin is required." >&2
  echo "Install it first, then run this script again." >&2
  exit 1
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
  echo "Review it now if your media root is not /media."
fi

# shellcheck disable=SC1091
. scripts/_compose.sh

echo "Deployment mode: $(compose_mode)"
echo "Media root: ${HOST_MEDIA_ROOT:-/media}"

if [ "${RPI_MAINPAGE_OPI_ENABLED}" -eq 1 ]; then
  missing_devices=()
  for device_path in /dev/dri /dev/dma_heap /dev/mali0 /dev/rga /dev/mpp_service; do
    if [ ! -e "${device_path}" ]; then
      missing_devices+=("${device_path}")
    fi
  done
  if [ "${#missing_devices[@]}" -gt 0 ]; then
    echo "Missing required RK3588 device paths: ${missing_devices[*]}" >&2
    exit 1
  fi
fi

media_root="${HOST_MEDIA_ROOT:-/media}"
if [ ! -d "${media_root}" ]; then
  echo "Host media root does not exist: ${media_root}" >&2
  exit 1
fi

state_root="${media_root%/}/.rpi-mainpage-data"
if mkdir -p "${state_root}/transcodes" 2>/dev/null; then
  chmod 0775 "${state_root}" "${state_root}/transcodes"
elif command -v sudo >/dev/null 2>&1 && sudo mkdir -p "${state_root}/transcodes"; then
  sudo chown -R 1000:1000 "${state_root}"
  sudo chmod 0775 "${state_root}" "${state_root}/transcodes"
else
  echo "Cannot create ${state_root}. Create it as root and assign it to UID/GID 1000:1000." >&2
  exit 1
fi

if [ ! -f .env.ssh ]; then
  echo
  echo "Configuring the dedicated container-to-host SSH key."
  ./scripts/setup-docker-ssh-key.sh
fi

compose config >/dev/null
echo "Building the application image..."
compose build --pull app

echo "Installing PHP dependencies..."
compose run --rm --no-deps --user 1000:1000 app \
  composer install --no-dev --optimize-autoloader --no-interaction --working-dir=/app

echo "Starting containers..."
compose up -d --force-recreate --remove-orphans

echo
./scripts/server-check.sh
