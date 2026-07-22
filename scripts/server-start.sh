#!/usr/bin/env bash
# Start the app on the server: nginx + php-fpm containers with SSH bridge to host.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
. scripts/_compose.sh

if [ ! -f .env.ssh ]; then
  echo "No .env.ssh found — SSH bridge to the host is not configured yet."
  read -r -p "Run scripts/setup-docker-ssh-key.sh now? [Y/n] " answer
  case "${answer:-Y}" in
    [Yy]*) ./scripts/setup-docker-ssh-key.sh ;;
    *) echo "Cannot start without .env.ssh (docker-compose.yml requires it)." >&2; exit 1 ;;
  esac
fi

compose up --build -d
echo
HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo "App started: http://${HOST_IP:-localhost}/"
