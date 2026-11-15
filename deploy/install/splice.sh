#!/bin/sh
# Copies deploy/install/lib/*.sh into the installers' marked regions
# (`# >>> lib/NAME.sh …` to `# <<< lib/NAME.sh`). The scripts people
# download (install.sh, server.sh through the dashboard, cli.sh) stay single
# self-contained files, while the shared code has one owner in lib/.
#
#   splice.sh           rewrite install.sh, server.sh and cli.sh (make gen-installers)
#   splice.sh --check   fail when a region differs from its lib file (make lint-deploy)
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
check=0
[ "${1:-}" != --check ] || check=1

status=0
out="$(mktemp)"
trap 'rm -f "$out"' EXIT INT TERM
for script in install.sh server.sh cli.sh; do
	awk -v lib="$here/lib" '
		skip && $0 != end { next }
		skip { print; skip = 0; next }
		/^# >>> lib\/[a-z0-9-]+\.sh( |$)/ {
			print
			name = $3
			path = lib "/" substr(name, 5)
			end = "# <<< " name
			n = 0
			while ((r = (getline line < path)) > 0) { print line; n++ }
			close(path)
			if (r < 0 || n == 0) { print "splice: cannot read " path > "/dev/stderr"; failed = 1; exit 1 }
			skip = 1
			next
		}
		{ print }
		END {
			if (!failed && skip) { print "splice: no \"" end "\" line" > "/dev/stderr"; exit 1 }
		}
	' "$here/$script" >"$out"
	if cmp -s "$here/$script" "$out"; then
		continue
	fi
	if [ "$check" = 1 ]; then
		printf 'deploy/install/%s: a shared region differs from deploy/install/lib (run make gen-installers)\n' "$script" >&2
		status=1
	else
		cat "$out" >"$here/$script"
		printf 'spliced deploy/install/lib into deploy/install/%s\n' "$script"
	fi
done
exit "$status"
