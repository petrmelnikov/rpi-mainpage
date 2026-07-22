#!/usr/bin/env bash
# Start the app on a dev machine (macOS) without containers: PHP built-in server.
# Usage: ./scripts/dev-start.sh [port]   (default 8080, or PORT env var)
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

command -v php >/dev/null 2>&1 || { echo "php not found (brew install php)" >&2; exit 1; }

if [ ! -f vendor/autoload.php ]; then
  command -v composer >/dev/null 2>&1 || { echo "composer not found (brew install composer)" >&2; exit 1; }
  composer install --no-interaction
fi

PORT="${1:-${PORT:-8080}}"
echo "Dev server: http://127.0.0.1:${PORT}"
exec php -S "127.0.0.1:${PORT}" scripts/dev-router.php
