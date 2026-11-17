# `laravel new --react` today: the React starter kit (Inertia, Wayfinder,
# Laravel 13) at a pinned commit of its main branch, then what the installer
# and `composer create-project` run (install, post-create scripts, npm install).
# Its composer scripts call npm (`artisan install:features`), so this stage
# adds Alpine's Node.js to the composer image.
# stage: LR_FIXTURE_COMPOSER_IMAGE php_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

php_stage() {
	apk add --no-cache nodejs npm >/dev/null
	cd /work/app
	git init -q
	git fetch -q --depth 1 https://github.com/laravel/react-starter-kit.git "$LR_FIXTURE_LARAVEL_REACT_STARTER_SHA"
	git checkout -q FETCH_HEAD
	rm -rf .git
	composer install --no-progress
	composer run-script post-root-package-install
	composer run-script post-create-project-cmd
}
