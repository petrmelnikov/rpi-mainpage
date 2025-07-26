#!/bin/bash

# RPI Mainpage Server Stop Script

PID_FILE="/var/run/rpi-mainpage.pid"
LOG_FILE="/var/log/rpi-mainpage.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

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

# Check if PID file exists
if [ ! -f "$PID_FILE" ]; then
    print_warning "PID file not found at $PID_FILE"
    print_status "Attempting to kill any running PHP servers..."
    pkill -f "php -S"
    if [ $? -eq 0 ]; then
        print_status "Killed running PHP servers"
    else
        print_warning "No running PHP servers found"
    fi
    exit 0
fi

# Read PID from file
PID=$(cat "$PID_FILE")

# Check if process is running
if ps -p "$PID" > /dev/null 2>&1; then
    print_status "Stopping RPI Mainpage server (PID: $PID)..."
    kill "$PID"
    
    # Wait for graceful shutdown
    sleep 2
    
    # Force kill if still running
    if ps -p "$PID" > /dev/null 2>&1; then
        print_warning "Process still running, force killing..."
        kill -9 "$PID"
    fi
    
    print_status "Server stopped successfully"
else
    print_warning "Process with PID $PID is not running"
fi

# Remove PID file
rm -f "$PID_FILE"

print_status "Cleanup completed"
