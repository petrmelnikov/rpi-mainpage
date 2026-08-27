#!/usr/bin/env bash
# Pull, install dependencies, rebuild when needed, and recreate the services.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

git pull --ff-only

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

# Load the compose helper after pull so an update to platform detection is
# applied by this very run, not only by the next invocation.
. scripts/_compose.sh

echo "Deployment mode: $(compose_mode)"
compose config >/dev/null
compose build app
compose run --rm --no-deps --user 1000:1000 app \
  composer install --no-dev --optimize-autoloader --no-interaction --working-dir=/app
compose up -d --force-recreate --remove-orphans

echo
./scripts/server-check.sh
