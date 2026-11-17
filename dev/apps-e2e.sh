#!/bin/sh
# Sample apps end to end (`make test-apps APPS="next16-stock django-startproject"`,
# APPS=all; dev/fixtures/README.md). Needs `make up` with an enrolled server-1.
#
# For every app: a project from dev/fixtures/<app> (served by git-fixtures)
# with no settings at all, deployed; then dev/apps-e2e/cases/<app>.sh asserts
# from outside, over the dev HTTPS port with the web firewall in its default
# Block mode. A case may also ask for
#   - a pull request preview (pr-1) from one of the fixture's branches, and
#   - a second project deployed from the fixture's `probe` branch, whose extra
#     routes report what the app believes (its URL, scheme, client address).
# Every assertion names the gap ids it covers, so a failure goes to the
# owner of that gap. The summary groups failures by gap.
#
# Environment: APPS (required), LR_E2E_TIMEOUT (seconds per wait, default
# 900), KEEP=1 keeps the projects (otherwise they are deleted at the start of
# the next run of the same app, never at the end, so a failure stays
# inspectable in the dashboard).

# pass/fail always succeed, so `A && pass || fail` is a plain if/else here.
# shellcheck disable=SC2015

set -u

cd "$(dirname "$0")/.." || exit 1

COMPOSE="sh dev/bin/compose.sh"
TIMEOUT="${LR_E2E_TIMEOUT:-900}"
PORT="$(sed -n 's/^LR_DEV_HTTPS_PORT=//p' dev/.env 2>/dev/null | tail -n 1)"
PORT="${PORT:-8443}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/launchroom-dev-apps-e2e.XXXXXX")"
RESULTS="$WORK/results"
: >"$RESULTS"
trap 'rm -rf "$WORK"' EXIT

# shellcheck source=dev/bin/checks.sh
. dev/bin/checks.sh
# shellcheck source=dev/apps-e2e/lib.sh
. dev/apps-e2e/lib.sh

if [ -z "$($COMPOSE ps -q --status running dashboard 2>/dev/null)" ]; then
	echo "The dev stack is not running: make up" >&2
	exit 2
fi

# cases: the names of dev/apps-e2e/cases/*.sh.
cases() { find dev/apps-e2e/cases -name '*.sh' | sed 's|.*/||; s|\.sh$||' | sort | tr '\n' ' '; }

APPS="${APPS:-}"
if [ "$APPS" = all ]; then
	APPS="$(cases)"
fi
[ -n "$APPS" ] || { echo "usage: make test-apps APPS=\"<fixture> …\"|all  (cases: $(cases))" >&2; exit 2; }

for app in $APPS; do
	case "$app" in *[!a-z0-9-]*) echo "bad app name $app" >&2; exit 2 ;; esac
	[ -f "dev/apps-e2e/cases/$app.sh" ] || { echo "no case dev/apps-e2e/cases/$app.sh" >&2; exit 2; }
done

for app in $APPS; do
	run_case "$app"
done

summary
