#!/bin/bash
# Deployment script for Nextcloud Social app
# Automates steps from DEPLOYMENT.md

set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NC_DIR="$(dirname "$APP_DIR")"
OCC="$NC_DIR/occ"

echo "=== Social App Deployment ==="
echo "App directory: $APP_DIR"
echo "Nextcloud directory: $NC_DIR"
echo ""

# Step 1: Install PHP dependencies
echo "--- Step 1/4: Installing PHP dependencies ---"
cd "$APP_DIR"
composer install --no-dev

# Step 2: Install Node.js dependencies
echo ""
echo "--- Step 2/4: Installing Node.js dependencies ---"
CYPRESS_INSTALL_BINARY=0 npm install

# Step 3: Build JavaScript assets
echo ""
echo "--- Step 3/4: Building JavaScript assets ---"
npm run build

# Step 4: Enable the app
echo ""
echo "--- Step 4/4: Enabling Social app ---"
php "$OCC" app:enable social

echo ""
echo "=== Deployment complete! ==="
