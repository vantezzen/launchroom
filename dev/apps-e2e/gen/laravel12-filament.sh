# Laravel 12 (composer create-project) with a Filament v4 panel
# (`filament:install --panels`) and Horizon (`horizon:install`).
# The generator's PHP has neither intl nor pcntl; the app needs them at
# runtime (Filament, Horizon), not to be generated.
# stage: LR_FIXTURE_COMPOSER_IMAGE php_stage
# stage: LR_FIXTURE_NODE_IMAGE node_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

php_stage() {
	cd /work/tmp
	composer create-project --no-progress "laravel/laravel:$LR_FIXTURE_LARAVEL_12" "$FIXTURE"
	cd "$FIXTURE"
	composer require --no-progress -W --ignore-platform-req=ext-intl --ignore-platform-req=ext-pcntl "filament/filament:$LR_FIXTURE_FILAMENT" "laravel/horizon:$LR_FIXTURE_HORIZON"
	php artisan filament:install --panels --no-interaction
	php artisan horizon:install
	cd ..
	adopt "$FIXTURE"
}

node_stage() {
	cd /work/app
	npm install
}
