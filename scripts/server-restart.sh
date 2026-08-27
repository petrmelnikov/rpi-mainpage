#!/usr/bin/env bash
# Rebuild and recreate the services without a separate destructive down step.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

. scripts/_compose.sh

echo "Deployment mode: $(compose_mode)"
compose up --build -d --force-recreate --remove-orphans
echo
./scripts/server-check.sh
