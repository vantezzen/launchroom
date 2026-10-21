#!/bin/sh
# Runs a command while holding the machine-wide "heavy" lock, so only one full
# test suite / integration run uses the CPU at a time (parallel agents queue
# here instead of saturating every core). A lock whose owner process is gone is
# taken over. LR_NO_HEAVY_LOCK=1 skips the lock (CI, where runs are isolated).
#
# Usage: dev/bin/heavy-lock.sh <label> <command...>
set -eu

label=$1
shift

if [ "${LR_NO_HEAVY_LOCK:-0}" = "1" ]; then
	exec "$@"
fi

dir="$(cd "$(dirname "$0")/.." && pwd)/.locks/heavy"
mkdir -p "$(dirname "$dir")"

announced=0
while ! mkdir "$dir" 2>/dev/null; do
	owner=$(cat "$dir/pid" 2>/dev/null || echo "")
	if [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; then
		echo "heavy-lock: taking over a stale lock (pid $owner gone)" >&2
		rm -rf "$dir"
		continue
	fi
	if [ "$announced" = "0" ]; then
		echo "heavy-lock: waiting for '$(cat "$dir/label" 2>/dev/null || echo '?')' (pid ${owner:-?}) to finish before '$label'..." >&2
		announced=1
	fi
	sleep 5
done

echo "$$" >"$dir/pid"
echo "$label" >"$dir/label"
trap 'rm -rf "$dir"' EXIT INT TERM

status=0
"$@" || status=$?
exit "$status"
