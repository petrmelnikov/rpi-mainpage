#!/bin/bash

# RPI Mainpage Uninstall Script (systemd)
# Stops and removes rpi-mainpage systemd unit.

set -euo pipefail

APP_NAME="rpi-mainpage"
SERVICE_FILE="/etc/systemd/system/${APP_NAME}.service"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root"
    print_status "Usage: sudo $0"
    exit 1
fi

print_status "Stopping ${APP_NAME} service (if running)..."
if systemctl is-active --quiet "${APP_NAME}.service"; then
    systemctl stop "${APP_NAME}.service"
    print_status "Service stopped"
else
    print_warning "Service is not running"
fi

print_status "Disabling ${APP_NAME} autostart (if enabled)..."
if systemctl is-enabled --quiet "${APP_NAME}.service" 2>/dev/null; then
    systemctl disable "${APP_NAME}.service"
    print_status "Service disabled"
else
    print_warning "Service is not enabled"
fi

if [ -f "$SERVICE_FILE" ]; then
    rm -f "$SERVICE_FILE"
    print_status "Removed $SERVICE_FILE"
else
    print_warning "Service file not found: $SERVICE_FILE"
fi

systemctl daemon-reload
systemctl reset-failed || true

print_status "Uninstall completed"
print_status "Project files were not removed"
