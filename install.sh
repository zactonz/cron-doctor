#!/bin/bash

set -euo pipefail

PLUGIN_SLUG="zcd"
PLUGIN_NAME="Zactonz Cron Doctor"
THEME_DIR="/usr/local/cpanel/base/frontend/jupiter"
PLUGIN_DIR="$THEME_DIR/$PLUGIN_SLUG"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

abort() {
    printf '%s\n' "$1" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || abort "This installer must be run as root."
[ -d /usr/local/cpanel ] || abort "cPanel was not found on this server."
[ -d "$THEME_DIR" ] || abort "The Jupiter theme was not found at $THEME_DIR."
[ -f "$SOURCE_DIR/install.json" ] || abort "Run this script from the directory it was extracted into."
[ -x /usr/local/cpanel/scripts/install_plugin ] || abort "The cPanel plugin installer was not found."

command -v rsync >/dev/null 2>&1 || abort "rsync is required but was not found."

printf 'Installing %s into %s\n' "$PLUGIN_NAME" "$PLUGIN_DIR"

mkdir -p "$PLUGIN_DIR"

rsync -a --delete --delete-excluded \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitignore' \
    --exclude='tests' \
    --exclude='tools' \
    --exclude='docs' \
    --exclude='install.sh' \
    --exclude='uninstall.sh' \
    --exclude='README.md' \
    --exclude='CHANGELOG.md' \
    --exclude='SECURITY.md' \
    --exclude='assets/*.un-compressed.css' \
    "$SOURCE_DIR/" "$PLUGIN_DIR/"

chown -R root:root "$PLUGIN_DIR"
find "$PLUGIN_DIR" -type d -exec chmod 755 {} +
find "$PLUGIN_DIR" -type f -exec chmod 644 {} +
chmod 755 "$PLUGIN_DIR/payload/bin/zcd-run" "$PLUGIN_DIR/payload/bin/zcd-sentinel"

printf 'Registering the plugin with cPanel\n'
/usr/local/cpanel/scripts/install_plugin "$PLUGIN_DIR" --theme jupiter

if [ -x /scripts/restartsrv_cpsrvd ]; then
    printf 'Restarting the cPanel interface\n'
    /scripts/restartsrv_cpsrvd >/dev/null
fi

printf '\n%s is installed.\n' "$PLUGIN_NAME"
printf 'Open cPanel and look for Cron Doctor under Advanced.\n'
printf 'If the icon does not appear, log out and back in so the theme cache refreshes.\n'
