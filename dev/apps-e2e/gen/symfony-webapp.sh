# Symfony webapp: what `symfony new --webapp` runs (create-project
# symfony/skeleton, then `composer require webapp`).
# stage: LR_FIXTURE_COMPOSER_IMAGE php_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

php_stage() {
	cd /work/tmp
	composer create-project --no-progress "symfony/skeleton:$LR_FIXTURE_SYMFONY" "$FIXTURE"
	cd "$FIXTURE"
	composer require --no-progress webapp
	cd ..
	adopt "$FIXTURE"
}
