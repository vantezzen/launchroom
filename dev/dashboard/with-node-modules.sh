#!/bin/sh
# Dev only. Ensures dashboard/node_modules (a Linux-only named volume that
# shadows the host's node_modules) matches package-lock.json, then execs "$@".
#
# The host's node_modules may hold macOS-native binaries (rolldown, lightningcss,
# tailwind oxide), so the containers never use it. The lock file hash is
# recorded in the volume; `npm ci` only runs when it changes.
set -eu

cd /var/www/html
marker=node_modules/.launchroom-lock.sha256
want="$(sha256sum package-lock.json | cut -d' ' -f1)"
have="$(cat "$marker" 2>/dev/null || true)"

if [ "$want" != "$have" ]; then
	echo "[launchroom-dev] package-lock.json changed; running npm ci (Linux node_modules volume)"
	# npm ci deletes node_modules' contents; the volume mount point itself stays.
	npm ci --no-audit --no-fund
	echo "$want" > "$marker"
fi

exec "$@"
