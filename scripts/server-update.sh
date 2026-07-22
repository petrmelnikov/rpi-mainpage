#!/usr/bin/env bash
# Update the app: git pull + composer install (inside the app container when possible).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
. scripts/_compose.sh

OLD_HEAD="$(git rev-parse HEAD)"
git pull --ff-only
NEW_HEAD="$(git rev-parse HEAD)"

if compose exec -T -u ubuntu app composer --version >/dev/null 2>&1; then
  compose exec -T -u ubuntu app composer install --no-interaction --working-dir=/app
elif command -v composer >/dev/null 2>&1; then
  composer install --no-interaction
elif [ -d vendor ]; then
  echo "composer not found; skipping (vendor/ exists)"
else
  echo "composer not found and vendor/ is missing — start containers first or install composer." >&2
  exit 1
fi

if [ "$OLD_HEAD" != "$NEW_HEAD" ] && git diff --name-only "$OLD_HEAD" "$NEW_HEAD" -- docker docker-compose.yml | grep -q .; then
  echo
  echo "Docker-related files changed — apply them with: ./scripts/server-restart.sh"
fi
