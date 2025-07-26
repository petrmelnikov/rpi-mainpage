#!/bin/bash

# RPI Mainpage Server Startup Script
# This script starts the PHP built-in server for the RPI mainpage application

# Configuration
APP_DIR="/opt/rpi-mainpage"  # Change this to your actual app directory
PHP_PORT=80
PHP_HOST="0.0.0.0"
LOG_FILE="/var/log/rpi-mainpage.log"
PID_FILE="/var/run/rpi-mainpage.pid"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root (needed for port 80)
if [ "$EUID" -ne 0 ]; then 
    print_error "This script must be run as root to bind to port 80"
    print_status "Usage: sudo $0"
    exit 1
fi

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    print_error "PHP is not installed. Please install PHP first:"
    echo "sudo apt update && sudo apt install php php-cli"
    exit 1
fi

# Create log directory if it doesn't exist
mkdir -p "$(dirname "$LOG_FILE")"

# Check if server is already running
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        print_warning "Server is already running with PID $PID"
        print_status "You can stop it with: sudo killall php"
        exit 1
    else
        # Remove stale PID file
        rm -f "$PID_FILE"
    fi
fi

# Change to app directory
if [ ! -d "$APP_DIR" ]; then
    print_error "App directory $APP_DIR does not exist!"
    print_status "Please update APP_DIR in this script to point to your app location"
    exit 1
fi

cd "$APP_DIR" || exit 1

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    print_warning "Vendor directory not found. Installing composer dependencies..."
    if command -v composer &> /dev/null; then
        composer install --no-dev --optimize-autoloader
    else
        print_error "Composer not found. Please install dependencies manually or install composer"
        exit 1
    fi
fi

# Start the PHP server
print_status "Starting RPI Mainpage server on $PHP_HOST:$PHP_PORT"
print_status "App directory: $APP_DIR"
print_status "Log file: $LOG_FILE"

# Start PHP server in background and capture PID
nohup php -S "$PHP_HOST:$PHP_PORT" > "$LOG_FILE" 2>&1 &
SERVER_PID=$!

# Save PID to file
echo $SERVER_PID > "$PID_FILE"

# Wait a moment and check if the server started successfully
sleep 2
if ps -p "$SERVER_PID" > /dev/null 2>&1; then
    print_status "Server started successfully with PID $SERVER_PID"
    print_status "Access your application at: http://$(hostname -I | awk '{print $1}'):$PHP_PORT"
    print_status "Or locally at: http://localhost:$PHP_PORT"
    print_status "Logs are written to: $LOG_FILE"
    print_status "To stop the server: sudo kill $SERVER_PID"
else
    print_error "Failed to start server. Check logs at $LOG_FILE"
    rm -f "$PID_FILE"
    exit 1
fi
