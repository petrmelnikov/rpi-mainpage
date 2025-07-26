# RPI Mainpage Server Setup

This directory contains scripts to run your PHP application on Orange Pi with autostart capability.

## Files Created

- `start_server.sh` - Main server startup script
- `stop_server.sh` - Server stop script  
- `rpi-mainpage.service` - Systemd service file for autostart
- `install.sh` - Automated installation script

## Quick Installation

Run the installation script as root:

```bash
sudo ./install.sh
```

This will:
- Install PHP if not already installed
- Copy your app to `/opt/rpi-mainpage`
- Install and enable the systemd service
- Start the service automatically

## Manual Installation

If you prefer manual setup:

1. **Install PHP** (if not installed):
   ```bash
   sudo apt update
   sudo apt install php php-cli php-json php-mbstring
   ```

2. **Copy application to system directory**:
   ```bash
   sudo mkdir -p /opt/rpi-mainpage
   sudo cp -r * /opt/rpi-mainpage/
   sudo chmod +x /opt/rpi-mainpage/start_server.sh
   sudo chmod +x /opt/rpi-mainpage/stop_server.sh
   ```

3. **Install systemd service**:
   ```bash
   sudo cp rpi-mainpage.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable rpi-mainpage.service
   ```

4. **Start the service**:
   ```bash
   sudo systemctl start rpi-mainpage.service
   ```

## Service Management

### Basic Commands
```bash
# Start the service
sudo systemctl start rpi-mainpage

# Stop the service
sudo systemctl stop rpi-mainpage

# Restart the service
sudo systemctl restart rpi-mainpage

# Check service status
sudo systemctl status rpi-mainpage

# View logs
sudo journalctl -u rpi-mainpage -f

# Enable autostart (done automatically by install.sh)
sudo systemctl enable rpi-mainpage

# Disable autostart
sudo systemctl disable rpi-mainpage
```

### Direct Script Usage
You can also run the scripts directly:

```bash
# Start server
sudo ./start_server.sh

# Stop server
sudo ./stop_server.sh
```

## Configuration

### Changing Port or Host

Edit `start_server.sh` and modify these variables:
```bash
PHP_PORT=80        # Change to desired port (use 8080 if not root)
PHP_HOST="0.0.0.0" # Change to specific IP if needed
```

### Changing Application Directory

If you want to install to a different location, edit these files:
- `start_server.sh`: Update `APP_DIR` variable
- `rpi-mainpage.service`: Update `WorkingDirectory` and paths
- `install.sh`: Update `APP_DIR` variable

## Logs

The application logs are written to:
- Service logs: `sudo journalctl -u rpi-mainpage`
- Application logs: `/var/log/rpi-mainpage.log`

## Troubleshooting

### Service won't start
```bash
# Check service status
sudo systemctl status rpi-mainpage

# Check detailed logs
sudo journalctl -u rpi-mainpage -n 50

# Check if port 80 is in use
sudo netstat -tulpn | grep :80
```

### Permission issues
Make sure the scripts are executable:
```bash
sudo chmod +x /opt/rpi-mainpage/start_server.sh
sudo chmod +x /opt/rpi-mainpage/stop_server.sh
```

### Port 80 access
If you can't use port 80 (requires root), change to port 8080:
1. Edit `start_server.sh` and change `PHP_PORT=8080`
2. Access your app at `http://your-opi-ip:8080`

## Security Notes

- The service runs as root to bind to port 80
- Security restrictions are applied in the systemd service
- Consider using a reverse proxy (nginx) for production use
- Change the default port if you don't need port 80

## Access Your Application

Once running, access your application at:
- `http://your-opi-ip` (if using port 80)
- `http://your-opi-ip:8080` (if using port 8080)
- `http://localhost` (locally on the Orange Pi)

The service will automatically start when your Orange Pi boots up.
