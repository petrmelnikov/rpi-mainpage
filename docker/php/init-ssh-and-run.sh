#!/usr/bin/env sh
set -eu

SSH_DIR="/tmp/ssh"
KEY_PATH="$SSH_DIR/id_ed25519"
CONFIG_PATH="$SSH_DIR/config"

mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"


ENV_FILE="${SSH_ENV_FILE:-}"
if [ -z "$ENV_FILE" ]; then
  if [ -f "/app/.env.ssh" ]; then
    ENV_FILE="/app/.env.ssh"
  elif [ -f ".env.ssh" ]; then
    ENV_FILE=".env.ssh"
  fi
fi

if [ -n "$ENV_FILE" ] && [ -f "$ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

if [ -n "${SSH_PRIVATE_KEY_B64:-}" ]; then
  printf '%s' "$SSH_PRIVATE_KEY_B64" | base64 -d > "$KEY_PATH"
  chmod 600 "$KEY_PATH"
fi

if [ ! -s "$KEY_PATH" ]; then
  echo "SSH key is missing. Set SSH_PRIVATE_KEY_B64 or provide .env.ssh (auto-loaded from /app/.env.ssh or ./.env.ssh)." >&2
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

# Ensure PHP-FPM worker user can access SSH key/config.
# By default php-fpm runs as www-data inside php:8.2-fpm.
APP_RUN_USER="${APP_RUN_USER:-www-data}"
APP_RUN_GROUPS="${APP_RUN_GROUPS:-www-data}"

if id "$APP_RUN_USER" >/dev/null 2>&1 && [ -n "$APP_RUN_GROUPS" ]; then
  # Allow the runtime user to write into bind-mounted directories owned by
  # service accounts (e.g. www-data:www-data with mode 775).
  usermod -a -G "$APP_RUN_GROUPS" "$APP_RUN_USER" || true
fi

if id "$APP_RUN_USER" >/dev/null 2>&1; then
  chown -R "$APP_RUN_USER:$APP_RUN_USER" "$SSH_DIR"

  # Warm up the shared SSH connection once as runtime user.
  su -s /bin/sh -c "ssh -F '$CONFIG_PATH' -MNf remote-target || true" "$APP_RUN_USER" || true
else
  # Fallback if the runtime user does not exist.
  ssh -F "$CONFIG_PATH" -MNf remote-target || true
fi

if [ "$#" -eq 0 ]; then
  echo "SSH initialization completed (no command provided)."
  exit 0
fi

exec "$@"
