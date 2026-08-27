#!/usr/bin/env bash
# Show recent container logs. Pass -f to follow them.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
# shellcheck disable=SC1091
. scripts/_compose.sh

if [ "${1:-}" = "-f" ]; then
  compose logs --tail=200 -f app nginx
else
  compose logs --tail=200 app nginx
fi
