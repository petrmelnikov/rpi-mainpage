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

# PHP-FPM drops root privileges and initializes the configured user's groups.
# On RK3588 hosts device GIDs can differ between Ubuntu/Armbian and Debian in
# the container, so create matching groups and add the runtime user by GID.
if [ "${APP_ADD_DEVICE_GROUPS:-0}" = "1" ] && id "$APP_RUN_USER" >/dev/null 2>&1; then
  for DEVICE_PATH in /dev/mpp_service /dev/rga /dev/mali0 /dev/dma_heap/* /dev/dri/renderD* /dev/dri/card*; do
    if [ ! -e "$DEVICE_PATH" ]; then
      continue
    fi
    DEVICE_GID="$(stat -c '%g' "$DEVICE_PATH" 2>/dev/null || true)"
    case "$DEVICE_GID" in
      ''|*[!0-9]*) continue ;;
    esac
    DEVICE_GROUP="$(getent group "$DEVICE_GID" | cut -d: -f1 || true)"
    if [ -z "$DEVICE_GROUP" ]; then
      DEVICE_GROUP="rpi-device-${DEVICE_GID}"
      groupadd -g "$DEVICE_GID" "$DEVICE_GROUP" || true
    fi
    usermod -a -G "$DEVICE_GROUP" "$APP_RUN_USER" || true
  done
fi

if [ -n "${UPLOAD_BASE_DIR:-}" ]; then
  TOOLS_DATA_DIR="$UPLOAD_BASE_DIR/hosted-tools"
  mkdir -p "$TOOLS_DATA_DIR/apps" "$TOOLS_DATA_DIR/manifests"
  if id "$APP_RUN_USER" >/dev/null 2>&1; then
    # Docker may create the bind-mounted host directory as root before the app
    # starts. Repair both new and pre-existing hosted-tools directories so the
    # PHP-FPM worker can publish and delete tools after every container restart.
    chown "$APP_RUN_USER:$APP_RUN_USER" "$UPLOAD_BASE_DIR"
    chown -R "$APP_RUN_USER:$APP_RUN_USER" "$TOOLS_DATA_DIR"
    chmod 775 "$UPLOAD_BASE_DIR"
    chmod -R u+rwX,g+rwX,o+rX "$TOOLS_DATA_DIR"
  fi
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
