#!/bin/sh
# Entrypoint of the production dashboard image.
#
# One image runs every control-plane PHP process; only the command differs:
#   web        frankenphp run --config /etc/frankenphp/Caddyfile   (default CMD)
#   horizon    php artisan horizon
#   reverb     php artisan reverb:start
#   scheduler  php artisan schedule:work
#
# Steps: wait for the database → run migrations (web only, by default) →
# warm the config/route/view/event caches → exec the command.
#
# A failed migration prints one line the update supervisor and
# `./launchroom doctor` look for, then exits 1:
#   [launchroom] migration-failed <migration> (<exception class>)
# Horizon, Reverb and the scheduler wait until no migration is pending, so
# they never run new code on the old schema.
#
# Env:
#   LR_DB_WAIT_TIMEOUT         seconds to wait for the database (default 60)
#   LR_RUN_MIGRATIONS          auto (default: only when the command is frankenphp) | true | false
#   LR_MIGRATION_WAIT_TIMEOUT  seconds the other roles wait for the migrations (default 600)
set -eu

log() { printf '[launchroom] %s\n' "$*"; }

cd /var/www/html

php /usr/local/lib/launchroom/wait-for-db.php "${LR_DB_WAIT_TIMEOUT:-60}"

run_migrations="${LR_RUN_MIGRATIONS:-auto}"
if [ "$run_migrations" = "auto" ]; then
	if [ "${1:-}" = "frankenphp" ]; then run_migrations=true; else run_migrations=false; fi
fi

if [ "$run_migrations" = "true" ]; then
	# --isolated takes a cache lock, so concurrent starts (e.g. scaled web
	# containers) never run migrations twice.
	log "running migrations"
	out="$(mktemp)"
	if ! php artisan migrate --force --isolated --no-interaction >"$out" 2>&1; then
		cat "$out"
		# The last migration named in the output is the one that failed.
		name="$(grep -Eo '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+' "$out" | tail -n 1)"
		class="$(grep -Eo '[A-Za-z]+(\\[A-Za-z]+)*(Exception|Error)' "$out" | head -n 1)"
		rm -f "$out"
		log "migration-failed ${name:-unknown migration}${class:+ ($class)}"
		exit 1
	fi
	cat "$out"
	rm -f "$out"
elif [ "${1:-} ${2:-}" = "php artisan" ] && { [ "${3:-}" = horizon ] || [ "${3:-}" = reverb:start ] || [ "${3:-}" = schedule:work ]; }; then
	# The long-running roles wait for the web container's migrations
	# (migrate:status --pending=1 exits 1 while one is pending), then start
	# anyway with a warning. One-off commands (keys, restore) never wait.
	waited=0
	until php artisan migrate:status --pending=1 --no-interaction >/dev/null 2>&1; do
		if [ "$waited" -ge "${LR_MIGRATION_WAIT_TIMEOUT:-600}" ]; then
			log "migrations are still pending after ${waited}s; starting anyway"
			break
		fi
		[ "$waited" -gt 0 ] || log "waiting for the database migrations"
		sleep 5
		waited=$((waited + 5))
	done
fi

# Caches live in the container filesystem (bootstrap/cache), so each container
# builds its own from its own environment.
log "warming caches"
php artisan optimize --no-interaction

log "starting: $*"
exec "$@"
