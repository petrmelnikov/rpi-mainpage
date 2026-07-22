#!/usr/bin/env bash
# Full restart: stop containers, rebuild images if needed, start again.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
. scripts/_compose.sh

compose down
compose up --build -d
