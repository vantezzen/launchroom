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
# Env:
#   LR_DB_WAIT_TIMEOUT   seconds to wait for the database (default 60)
#   LR_RUN_MIGRATIONS    auto (default: only when the command is frankenphp) | true | false
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
	php artisan migrate --force --isolated --no-interaction
fi

# Caches live in the container filesystem (bootstrap/cache), so each container
# builds its own from its own environment.
log "warming caches"
php artisan optimize --no-interaction

log "starting: $*"
exec "$@"
