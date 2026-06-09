#!/usr/bin/env bash

# Exit if any command fails.
set -e

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..
DIR=$(pwd)
BUILD_DIR="$DIR/build/texty"

# Parse args. --dev skips stamping @since TEXTY_VERSION placeholders;
# a plain run (no args) replaces them with the real version.
DEV=0
for arg in "$@"; do
    case "$arg" in
        --dev) DEV=1 ;;
    esac
done

# Read the plugin version from package.json for the archive filename.
VERSION="$(grep -m1 '"version"' "$DIR/package.json" | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')"

# Enable nicer messaging for build status.
BLUE_BOLD='\033[1;34m'
GREEN_BOLD='\033[1;32m'
RED_BOLD='\033[1;31m'
YELLOW_BOLD='\033[1;33m'
COLOR_RESET='\033[0m'

error() {
    echo -e "\n${RED_BOLD}$1${COLOR_RESET}\n"
}
status() {
    echo -e "\n${BLUE_BOLD}$1${COLOR_RESET}\n"
}
success() {
    echo -e "\n${GREEN_BOLD}$1${COLOR_RESET}\n"
}
warning() {
    echo -e "\n${YELLOW_BOLD}$1${COLOR_RESET}\n"
}

status "💃 Time to build the Texty ZIP file 🕺"

# remove the build directory if exists and create one
rm -rf "$DIR/build"
mkdir -p "$BUILD_DIR"

# Run the build.
status "Installing dependencies... 📦"
npm install

status "Generating build... 👷‍♀️"
npm run build
npm run makepot

# Stamp @since TEXTY_VERSION placeholders with the real version before
# packaging. Skipped on --dev builds.
if [ "$DEV" -eq 0 ]; then
    status "Replacing version placeholders... 🏷️"
    npm run version
else
    warning "Dev build — skipping version placeholder replacement."
fi

# Copy all files
status "Copying files... ✌️"
FILES=(texty.php readme.txt dist dependencies includes assets languages composer.json composer.lock)

for file in ${FILES[@]}; do
    cp -R $file $BUILD_DIR
done

# Install composer dependencies
status "Installing dependencies... 📦"
cd $BUILD_DIR
composer install --optimize-autoloader --no-dev -q

# Remove composer files
rm composer.json composer.lock

# go one up, to the build dir
status "Creating archive... 🎁"
cd ..
zip -r -q "texty-v${VERSION}.zip" texty

# remove the source directory
rm -rf texty

success "Done. You've built Texty! 🎉 "
