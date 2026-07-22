# Sourced by server-*.sh scripts: defines compose() — docker compose with sudo fallback.
compose() {
  if docker info >/dev/null 2>&1; then
    docker compose "$@"
  elif sudo -n docker info >/dev/null 2>&1; then
    sudo -n docker compose "$@"
  else
    echo "Cannot access Docker daemon: add user to 'docker' group or allow passwordless sudo." >&2
    return 1
  fi
}
