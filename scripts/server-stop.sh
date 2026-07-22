#!/usr/bin/env bash
# Stop and remove the app containers.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
. scripts/_compose.sh

compose down
