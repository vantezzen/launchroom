#!/bin/sh
# Targeted dashboard checks for the files changed since the last staged
# checkpoint (make check-dashboard). FILES holds the changed paths relative to
# dashboard/ (computed on the host from git). Runs Pint, Rector and PHPStan on
# the changed PHP files, tsc on the frontend (whole project: it's fast), and
# Pest on the changed test files plus anything matched by ARGS.
set -eu

php_files=""
stan_files=""
test_files=""
ts_changed=0
for f in ${FILES:-}; do
	[ -f "$f" ] || continue
	case "$f" in
	*.php)
		case "$f" in
		database/migrations/* | bootstrap/cache/*) ;;
		*) php_files="$php_files $f" ;;
		esac
		# PHPStan only on the paths phpstan.neon analyses (tests are untyped Pest).
		case "$f" in
		app/* | config/* | database/* | routes/* | bootstrap/*.php | tests/Fixtures/*) stan_files="$stan_files $f" ;;
		esac
		case "$f" in tests/*Test.php) test_files="$test_files $f" ;; esac
		;;
	*.ts | *.tsx) ts_changed=1 ;;
	esac
done

status=0
if [ -n "$php_files" ]; then
	echo "== pint (changed files)"
	vendor/bin/pint --test $php_files || status=1
	echo "== rector (changed files)"
	vendor/bin/rector process --dry-run --no-progress-bar $php_files || status=1
fi
if [ -n "$stan_files" ]; then
	echo "== phpstan (changed files)"
	vendor/bin/phpstan analyse --no-progress --memory-limit=2G $stan_files || status=1
fi
if [ "$ts_changed" = "1" ]; then
	echo "== tsc"
	npm run types:check || status=1
fi
if [ -n "$test_files" ] || [ -n "${ARGS:-}" ]; then
	echo "== pest${test_files:+ $test_files} ${ARGS:-}"
	# shellcheck disable=SC2086
	php vendor/bin/pest $test_files ${ARGS:-} || status=1
fi
[ -n "$php_files$test_files${ARGS:-}" ] || [ "$ts_changed" = "1" ] || echo "Nothing changed under dashboard/ since the last checkpoint."
exit "$status"
