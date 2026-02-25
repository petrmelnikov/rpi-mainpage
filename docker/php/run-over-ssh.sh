#!/usr/bin/env sh
set -eu

REMOTE_COMMAND="${1:-}"
if [ -z "$REMOTE_COMMAND" ]; then
  exit 0
fi

CONFIG_PATH="/tmp/ssh/config"
if [ ! -f "$CONFIG_PATH" ]; then
  echo "SSH config not found at $CONFIG_PATH" >&2
  exit 1
fi

ssh -F "$CONFIG_PATH" remote-target -- bash -lc "$REMOTE_COMMAND"
