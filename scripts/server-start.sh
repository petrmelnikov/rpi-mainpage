#!/usr/bin/env bash
# Start the app on the server. Orange Pi override selection is automatic.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

. scripts/_compose.sh

if [ ! -f .env.ssh ]; then
  echo "No .env.ssh found — SSH bridge to the host is not configured yet."
  read -r -p "Run scripts/setup-docker-ssh-key.sh now? [Y/n] " answer
  case "${answer:-Y}" in
    [Yy]*) ./scripts/setup-docker-ssh-key.sh ;;
    *) echo "Cannot start without .env.ssh (docker-compose.yml requires it)." >&2; exit 1 ;;
  esac
fi

state_root="${HOST_MEDIA_ROOT:-/media}/.rpi-mainpage-data"
if [ ! -d "${state_root}/transcodes" ]; then
  echo "Missing ${state_root}/transcodes; run ./scripts/server-install.sh first." >&2
  exit 1
fi

echo "Deployment mode: $(compose_mode)"
compose build app
if [ ! -f vendor/autoload.php ]; then
  compose run --rm --no-deps --user 1000:1000 app \
    composer install --no-dev --optimize-autoloader --no-interaction --working-dir=/app
fi
compose up -d --remove-orphans
echo
HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo "App started: http://${HOST_IP:-localhost}/"
