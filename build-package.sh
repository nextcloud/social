#!/bin/bash
# Build and package Social app as tar.gz archive
# Usage: ./build-package.sh

set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$APP_DIR/build/artifacts"
SIGN_DIR="$BUILD_DIR/sign"

echo "=== Building Social App Package ==="
echo "App directory: $APP_DIR"

# Install PHP dependencies
echo "1. Installing PHP dependencies..."
cd "$APP_DIR"
composer install --no-dev --no-interaction --prefer-dist

# Build JS assets
echo "2. Building JavaScript assets..."
npm run build

# Clean and prepare package directory
echo "3. Preparing package directory..."
rm -rf "$BUILD_DIR"
mkdir -p "$SIGN_DIR"

# Sync app files (exclude dev files)
echo "4. Syncing app files..."
rsync -a \
  --exclude=/build \
  --exclude=/babel.config.js \
  --exclude=/cypress.json \
  --exclude=/.php-cs-fixer.cache \
  --exclude=/.nextcloudignore \
  --exclude=/.php-cs-fixer.dist.php \
  --exclude=/psalm.xml \
  --exclude=/cypress.json \
  --exclude=/cypress \
  --exclude=/docs \
  --exclude=/translationfiles \
  --exclude=/.tx \
  --exclude=/.idea \
  --exclude=/tests \
  --exclude=.git \
  --exclude=/.github \
  --exclude=/.babelrc.js \
  --exclude=/.drone.yml \
  --exclude=/.eslintrc.js \
  --exclude=/cypress.config.js \
  --exclude=/stylelint.config.js \
  --exclude=/composer.json \
  --exclude=/composer.lock \
  --exclude=/src \
  --exclude=/node_modules \
  --exclude=/webpack.*.js \
  --exclude=/package.json \
  --exclude=/package-lock.json \
  --exclude=/l10n/l10n.pl \
  --exclude=/CONTRIBUTING.md \
  --exclude=/issue_template.md \
  --exclude=/krankerl.toml \
  --exclude=/README.md \
  --exclude=/.gitattributes \
  --exclude=/.gitignore \
  --exclude=/.scrutinizer.yml \
  --exclude=/.travis.yml \
  --exclude=/Makefile \
  --exclude=/social.key \
  --exclude=/social.csr \
  "$APP_DIR/" "$SIGN_DIR/social"

# Create tar.gz archive
echo "5. Creating tar.gz archive..."
cd "$BUILD_DIR"
tar -czf social.tar.gz -C "$SIGN_DIR" social

# Display results
echo ""
echo "=== Build Complete ==="
TARBALL="$BUILD_DIR/social.tar.gz"
if [ -f "$TARBALL" ]; then
  SIZE=$(ls -lh "$TARBALL" | awk '{print $5}')
  VERSION=$(tar -xOzf "$TARBALL" social/appinfo/info.xml 2>/dev/null | grep -oP '(?<=<version>)[^<]+' || echo "unknown")
  echo "✓ Package created: $TARBALL"
  echo "  Size: $SIZE"
  echo "  Version: $VERSION"
else
  echo "✗ Error: Package file not created"
  exit 1
fi
