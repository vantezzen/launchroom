# Laravel 10 (composer create-project laravel/laravel:^10 at a pinned release).
# Laravel 10 is past its security support, so Composer's advisory blocking is
# off for this one (an existing Laravel 10 app has its lockfile already).
# stage: LR_FIXTURE_COMPOSER_IMAGE php_stage
# stage: LR_FIXTURE_NODE_IMAGE node_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

php_stage() {
	cd /work/tmp
	export COMPOSER_NO_SECURITY_BLOCKING=1
	composer create-project --no-progress "laravel/laravel:$LR_FIXTURE_LARAVEL_10" "$FIXTURE"
	adopt "$FIXTURE"
}

node_stage() {
	cd /work/app
	npm install
}
