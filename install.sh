#!/bin/bash

# Configuration
REPO="Itsusinn/tuic"
BINARY_NAME="tuic-server"
INSTALL_DIR="/usr/local/bin"
CONFIG_DIR="/var/www"
CONFIG_FILE="$CONFIG_DIR/config.toml"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${GREEN}Installing TUIC Server (Itsusinn fork)...${NC}"

# Dependencies
if ! command -v jq &> /dev/null; then
    echo "Installing jq..."
    sudo apt-get update && sudo apt-get install -y jq
fi

# Detect Architecture
ARCH=$(uname -m)
case $ARCH in
    x86_64)
        ASSET_SUFFIX="x86_64-linux"
        ;;
    aarch64)
        ASSET_SUFFIX="aarch64-linux"
        ;;
    *)
        echo -e "${RED}Unsupported architecture: $ARCH${NC}"
        exit 1
        ;;
esac

# Get latest release info
LATEST_RELEASE=$(curl -s https://api.github.com/repos/$REPO/releases/latest)
DOWNLOAD_URL=$(echo "$LATEST_RELEASE" | jq -r ".assets[] | select(.name == \"$BINARY_NAME-$ASSET_SUFFIX\") | .browser_download_url")
VERSION=$(echo "$LATEST_RELEASE" | jq -r ".tag_name")

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" == "null" ]; then
    DOWNLOAD_URL=$(echo "$LATEST_RELEASE" | jq -r ".assets[] | select(.name | contains(\"$BINARY_NAME\") and contains(\"$ASSET_SUFFIX\")) | .browser_download_url" | head -n 1)
fi

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" == "null" ]; then
    echo -e "${RED}Failed to find download URL.${NC}"
    exit 1
fi

echo "Downloading version $VERSION..."
curl -L "$DOWNLOAD_URL" -o "/tmp/$BINARY_NAME"
chmod +x "/tmp/$BINARY_NAME"
sudo mv "/tmp/$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"

# Setup configuration
if [ ! -d "$CONFIG_DIR" ]; then
    sudo mkdir -p "$CONFIG_DIR"
fi

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Creating default config at $CONFIG_FILE..."
    # Generate a random UUID and password for the first user
    UUID=$(cat /proc/sys/kernel/random/uuid)
    PASS=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 32)
    SECRET=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 32)

    sudo bash -c "cat <<EOF > $CONFIG_FILE
server = \"[::]:8443\"
log_level = \"info\"

[users]
\"$UUID\" = \"$PASS\"

[restful]
addr = \"127.0.0.1:8080\"
secret = \"$SECRET\"

[tls]
self_sign = true
hostname = \"localhost\"

[quic]
congestion_control = { controller = \"bbr\", initial_window = 1048576 }
EOF"
fi

# Set permissions so PHP can edit it
sudo chown www-data:www-data "$CONFIG_FILE"
sudo chmod 664 "$CONFIG_FILE"

# Systemd Service
echo "Setting up systemd service..."
sudo bash -c "cat <<EOF > /etc/systemd/system/tuic.service
[Unit]
Description=TUIC Server
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$CONFIG_DIR
ExecStart=$INSTALL_DIR/$BINARY_NAME -c $CONFIG_FILE
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF"

sudo systemctl daemon-reload
sudo systemctl enable tuic
sudo systemctl start tuic

echo -e "${GREEN}TUIC Server installed and started!${NC}"
echo "Config: $CONFIG_FILE"
echo "Service: systemctl status tuic"
