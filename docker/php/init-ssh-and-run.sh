#!/usr/bin/env sh
set -eu

SSH_DIR="/tmp/ssh"
KEY_PATH="$SSH_DIR/id_ed25519"
CONFIG_PATH="$SSH_DIR/config"

mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"

if [ -n "${SSH_PRIVATE_KEY_B64:-}" ]; then
  printf '%s' "$SSH_PRIVATE_KEY_B64" | base64 -d > "$KEY_PATH"
  chmod 600 "$KEY_PATH"
fi

if [ ! -s "$KEY_PATH" ]; then
  echo "SSH key is missing. Run scripts/setup-docker-ssh-key.sh first." >&2
  exit 1
fi

: "${SSH_REMOTE_HOST:?SSH_REMOTE_HOST is required}"
: "${SSH_REMOTE_PORT:=22}"
: "${SSH_REMOTE_USER:?SSH_REMOTE_USER is required}"
: "${SSH_CONTROL_PATH:=/tmp/ssh/cm-%r@%h:%p}"

cat > "$CONFIG_PATH" <<EOF
Host remote-target
  HostName ${SSH_REMOTE_HOST}
  Port ${SSH_REMOTE_PORT}
  User ${SSH_REMOTE_USER}
  IdentityFile ${KEY_PATH}
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
  UserKnownHostsFile ${SSH_DIR}/known_hosts
  ControlMaster auto
  ControlPersist 10m
  ControlPath ${SSH_CONTROL_PATH}
EOF
chmod 600 "$CONFIG_PATH"

# Warm up the shared SSH connection once.
ssh -F "$CONFIG_PATH" -MNf remote-target || true

exec "$@"
