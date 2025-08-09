#!/bin/bash

# RPI Mainpage Installation Script
# This script sets up the RPI Mainpage service for autostart

# Configuration
APP_NAME="rpi-mainpage"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}" )" && pwd)"
SERVICE_FILE="$APP_NAME.service"
CURRENT_DIR="$REPO_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    echo -e "${BLUE}=== $1 ===${NC}"
}

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    print_error "This script must be run as root"
    print_status "Usage: sudo $0"
    exit 1
fi

print_header "RPI Mainpage Installation"

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    print_warning "PHP is not installed. Installing PHP..."
    apt update
    apt install -y php php-cli php-json php-mbstring
    
    if ! command -v php &> /dev/null; then
        print_error "Failed to install PHP"
        exit 1
    fi
    print_status "PHP installed successfully"
fi

print_status "Using repository directory: $REPO_DIR"
chmod +x "$REPO_DIR/start_server.sh" || true
chmod +x "$REPO_DIR/stop_server.sh" || true
chmod +x "$REPO_DIR/install.sh" || true

# Install composer dependencies if composer is available
if command -v composer &> /dev/null; then
    print_status "Installing composer dependencies..."
    cd "$REPO_DIR"
    composer install --no-dev --optimize-autoloader
else
    print_warning "Composer not found. If you need to install dependencies, install composer first:"
    echo "  curl -sS https://getcomposer.org/installer | php"
    echo "  sudo mv composer.phar /usr/local/bin/composer"
fi

# Install systemd service
print_status "Installing systemd service..."
TARGET_SERVICE="/etc/systemd/system/$SERVICE_FILE"
cat > "$TARGET_SERVICE" <<EOF
[Unit]
Description=RPI Mainpage PHP Server
After=network.target
Wants=network.target

[Service]
Type=forking
User=root
Group=root
WorkingDirectory=$REPO_DIR
ExecStart=$REPO_DIR/start_server.sh
ExecStop=$REPO_DIR/stop_server.sh
PIDFile=/var/run/rpi-mainpage.pid
Restart=always
RestartSec=10
StandardOutput=append:/var/log/rpi-mainpage.log
StandardError=append:/var/log/rpi-mainpage.log

# Security settings
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths=/var/log /var/run $REPO_DIR
ProtectHome=yes

[Install]
WantedBy=multi-user.target
EOF

# Reload systemd
systemctl daemon-reload

# Enable service for autostart
print_status "Enabling service for autostart..."
systemctl enable "$APP_NAME.service"

# Set proper permissions
chmod 644 "$TARGET_SERVICE"

print_header "Installation Complete"
print_status "Application directory: $REPO_DIR"
print_status "Service file installed to: /etc/systemd/system/$SERVICE_FILE"
print_status "Service enabled for autostart: $APP_NAME.service"

echo ""
print_header "Usage Commands"
echo -e "${YELLOW}Start service:${NC}     sudo systemctl start $APP_NAME"
echo -e "${YELLOW}Stop service:${NC}      sudo systemctl stop $APP_NAME"
echo -e "${YELLOW}Restart service:${NC}   sudo systemctl restart $APP_NAME"
echo -e "${YELLOW}Check status:${NC}      sudo systemctl status $APP_NAME"
echo -e "${YELLOW}View logs:${NC}         sudo journalctl -u $APP_NAME -f"
echo -e "${YELLOW}Disable autostart:${NC} sudo systemctl disable $APP_NAME"

echo ""
print_header "Starting Service"
systemctl start "$APP_NAME.service"

# Wait a moment for startup
sleep 3

# Check service status
if systemctl is-active --quiet "$APP_NAME.service"; then
    print_status "Service started successfully!"
    IP=$(hostname -I | awk '{print $1}')
    print_status "Your application should be available at: http://$IP"
    print_status "Local access: http://localhost"
else
    print_error "Service failed to start. Check status with:"
    echo "  sudo systemctl status $APP_NAME"
    echo "  sudo journalctl -u $APP_NAME"
fi

echo ""
print_status "Installation completed! The service will automatically start on boot."
