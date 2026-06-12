#!/bin/bash

# Configuration
REPO="Itsusinn/tuic"
BINARY_NAME="tuic-server"
INSTALL_DIR="/usr/local/bin"

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
        echo "Unsupported architecture: $ARCH"
        exit 1
        ;;
esac

echo "Updating TUIC from $REPO for $ARCH..."

# Get latest release info from GitHub API
LATEST_RELEASE=$(curl -s https://api.github.com/repos/$REPO/releases/latest)
DOWNLOAD_URL=$(echo "$LATEST_RELEASE" | grep "browser_download_url" | grep "$BINARY_NAME-$ASSET_SUFFIX" | cut -d '"' -f 4)
VERSION=$(echo "$LATEST_RELEASE" | grep "tag_name" | cut -d '"' -f 4)

if [ -z "$DOWNLOAD_URL" ]; then
    echo "Could not find download URL for $BINARY_NAME-$ASSET_SUFFIX"
    exit 1
fi

echo "Latest version found: $VERSION"
echo "Downloading from $DOWNLOAD_URL..."

# Download and install
curl -L "$DOWNLOAD_URL" -o "/tmp/$BINARY_NAME"
chmod +x "/tmp/$BINARY_NAME"

# Move to install directory (might need sudo if run manually, but trying to move)
if [ -w "$INSTALL_DIR" ]; then
    mv "/tmp/$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"
    echo "Successfully updated $BINARY_NAME to $VERSION in $INSTALL_DIR"
else
    echo "Permission denied for $INSTALL_DIR. Attempting to use sudo..."
    sudo mv "/tmp/$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"
    echo "Successfully updated $BINARY_NAME to $VERSION in $INSTALL_DIR"
fi

# Optional: Restart service if it exists
if systemctl is-active --quiet tuic; then
    echo "Restarting tuic service..."
    sudo systemctl restart tuic
fi
