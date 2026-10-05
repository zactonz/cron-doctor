#!/bin/bash

set -euo pipefail

PLUGIN_SLUG="zcd"
PLUGIN_NAME="Zactonz Cron Doctor"
PLUGIN_DIR="/usr/local/cpanel/base/frontend/jupiter/$PLUGIN_SLUG"

abort() {
    printf '%s\n' "$1" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || abort "This script must be run as root."

if [ -f "$PLUGIN_DIR/install.json" ] && [ -x /usr/local/cpanel/scripts/uninstall_plugin ]; then
    printf 'Unregistering the plugin\n'
    /usr/local/cpanel/scripts/uninstall_plugin "$PLUGIN_DIR" --theme jupiter
fi

if [ -d "$PLUGIN_DIR" ]; then
    printf 'Removing %s\n' "$PLUGIN_DIR"
    rm -rf -- "$PLUGIN_DIR"
fi

if [ -x /scripts/restartsrv_cpsrvd ]; then
    /scripts/restartsrv_cpsrvd >/dev/null
fi

printf '\n%s is removed.\n' "$PLUGIN_NAME"
printf 'Crontabs are untouched: any job Cron Doctor was monitoring still runs through the runner in that\n'
printf 'account'"'"'s home directory. Use Stop monitoring on each job before uninstalling to put the original\n'
printf 'crontab lines back, or edit those lines by hand afterwards.\n'
