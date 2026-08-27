# Sourced by server-*.sh scripts. Defines compose() with Docker access fallback
# and selects the Orange Pi/RK3588 override consistently for every operation.

RPI_MAINPAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -f "${RPI_MAINPAGE_ROOT}/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "${RPI_MAINPAGE_ROOT}/.env"
  set +a
fi

RPI_MAINPAGE_USE_OPI="${RPI_MAINPAGE_USE_OPI:-auto}"
RPI_MAINPAGE_COMPOSE_ARGS=(-f "${RPI_MAINPAGE_ROOT}/docker-compose.yml")
RPI_MAINPAGE_OPI_ENABLED=0

case "${RPI_MAINPAGE_USE_OPI}" in
  1|true|yes|on)
    RPI_MAINPAGE_OPI_ENABLED=1
    ;;
  0|false|no|off)
    RPI_MAINPAGE_OPI_ENABLED=0
    ;;
  auto)
    if [ -e /dev/mpp_service ] && [ -e /dev/rga ] && [ -e /dev/mali0 ]; then
      RPI_MAINPAGE_OPI_ENABLED=1
    fi
    ;;
  *)
    echo "Invalid RPI_MAINPAGE_USE_OPI=${RPI_MAINPAGE_USE_OPI}; use auto, 1, or 0." >&2
    return 1 2>/dev/null || exit 1
    ;;
esac

if [ "${RPI_MAINPAGE_OPI_ENABLED}" -eq 1 ]; then
  if [ ! -f "${RPI_MAINPAGE_ROOT}/docker-compose.opi.yml" ]; then
    echo "Orange Pi mode requested, but docker-compose.opi.yml is missing." >&2
    return 1 2>/dev/null || exit 1
  fi
  RPI_MAINPAGE_COMPOSE_ARGS+=(-f "${RPI_MAINPAGE_ROOT}/docker-compose.opi.yml")
fi

compose() {
  if docker info >/dev/null 2>&1; then
    docker compose "${RPI_MAINPAGE_COMPOSE_ARGS[@]}" "$@"
  elif sudo -n docker info >/dev/null 2>&1; then
    sudo -n docker compose "${RPI_MAINPAGE_COMPOSE_ARGS[@]}" "$@"
  else
    echo "Cannot access Docker daemon. Add the current user to the docker group or configure passwordless sudo for Docker." >&2
    return 1
  fi
}

compose_mode() {
  if [ "${RPI_MAINPAGE_OPI_ENABLED}" -eq 1 ]; then
    echo "Orange Pi RK3588 (base + docker-compose.opi.yml)"
  else
    echo "generic Docker (docker-compose.yml)"
  fi
}
