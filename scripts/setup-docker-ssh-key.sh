#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env.ssh"
KEY_DIR="${ROOT_DIR}/.docker-ssh"
KEY_PATH="${KEY_DIR}/id_ed25519"
PUB_PATH="${KEY_PATH}.pub"

mkdir -p "${KEY_DIR}"
chmod 700 "${KEY_DIR}"

if [ ! -f "${KEY_PATH}" ]; then
  ssh-keygen -t ed25519 -N "" -f "${KEY_PATH}" -C "docker-rpi-mainpage" >/dev/null
fi

chmod 600 "${KEY_PATH}"
chmod 644 "${PUB_PATH}"

DEFAULT_HOST="${SSH_REMOTE_HOST:-}"
DEFAULT_PORT="${SSH_REMOTE_PORT:-22}"
DEFAULT_USER="${SSH_REMOTE_USER:-ubuntu}"

read -r -p "Remote host [${DEFAULT_HOST:-127.0.0.1}]: " INPUT_HOST || true
read -r -p "Remote port [${DEFAULT_PORT}]: " INPUT_PORT || true
read -r -p "Remote user [${DEFAULT_USER}]: " INPUT_USER || true

REMOTE_HOST="${INPUT_HOST:-${DEFAULT_HOST:-127.0.0.1}}"
REMOTE_PORT="${INPUT_PORT:-${DEFAULT_PORT}}"
REMOTE_USER="${INPUT_USER:-${DEFAULT_USER}}"

if [ -z "${REMOTE_HOST}" ] || [ -z "${REMOTE_USER}" ]; then
  echo "Remote host and user are required" >&2
  exit 1
fi

KEY_B64="$(base64 -w0 "${KEY_PATH}")"
cat > "${ENV_FILE}" <<EOF
SSH_REMOTE_HOST=${REMOTE_HOST}
SSH_REMOTE_PORT=${REMOTE_PORT}
SSH_REMOTE_USER=${REMOTE_USER}
SSH_PRIVATE_KEY_B64=${KEY_B64}
EOF

PUB_KEY="$(cat "${PUB_PATH}")"
SSH_OPTS=( -p "${REMOTE_PORT}" -o StrictHostKeyChecking=accept-new )

ssh "${SSH_OPTS[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && grep -qxF '${PUB_KEY}' ~/.ssh/authorized_keys || echo '${PUB_KEY}' >> ~/.ssh/authorized_keys"

echo "Prepared ${ENV_FILE} and ensured public key is present on ${REMOTE_USER}@${REMOTE_HOST}"
