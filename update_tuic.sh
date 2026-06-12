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

# Use jq to get the download URL precisely
DOWNLOAD_URL=$(echo "$LATEST_RELEASE" | jq -r ".assets[] | select(.name == \"$BINARY_NAME-$ASSET_SUFFIX\") | .browser_download_url")
VERSION=$(echo "$LATEST_RELEASE" | jq -r ".tag_name")

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" == "null" ]; then
    echo "Could not find download URL for $BINARY_NAME-$ASSET_SUFFIX"
    # Try fallback to just name contains if exact match fails
    DOWNLOAD_URL=$(echo "$LATEST_RELEASE" | jq -r ".assets[] | select(.name | contains(\"$BINARY_NAME\") and contains(\"$ASSET_SUFFIX\")) | .browser_download_url" | head -n 1)
fi

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" == "null" ]; then
    echo "Final attempt failed to find download URL."
    exit 1
fi

echo "Latest version found: $VERSION"
echo "Downloading from $DOWNLOAD_URL..."

# Download and install
curl -L "$DOWNLOAD_URL" -o "/tmp/$BINARY_NAME"
chmod +x "/tmp/$BINARY_NAME"

# Move to install directory
if [ -w "$INSTALL_DIR" ]; then
    mv "/tmp/$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"
else
    sudo mv "/tmp/$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"
fi

echo "Successfully updated $BINARY_NAME to $VERSION in $INSTALL_DIR"

# Optional: Restart service if it exists
if systemctl is-active --quiet tuic; then
    echo "Restarting tuic service..."
    sudo systemctl restart tuic
fi
