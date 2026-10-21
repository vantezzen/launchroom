#!/bin/sh
# Dev only. Runs `php artisan <command> [args...]` once that command exists.
#
# horizon and reverb come from packages that may not be installed yet (fresh
# clone without vendor/, or a branch that predates them). Instead of crash-
# looping, the container waits and polls, and starts by itself once
# `composer install` has added the command.
set -eu

cmd="$1"
logged=0
until php artisan help "$cmd" >/dev/null 2>&1; do
	if [ "$logged" -eq 0 ]; then
		echo "[launchroom-dev] 'php artisan $cmd' is not available yet (package not installed or app not bootable); retrying every 10s"
		logged=1
	fi
	sleep 10
done

exec php artisan "$@"
