#!/bin/sh
# Fails if a release Dockerfile pulls anything by a mutable tag (docs/09 R31,
# 2026-10 review M8): every `# syntax=` frontend and every `FROM` must name an
# image by `@sha256:` digest, an earlier build stage, or an `ARG` whose default
# is digest-pinned. Usage: check-pinned-images.sh [Dockerfile...]
# (default: the Dockerfiles the release workflow builds).
set -eu

cd "$(dirname "$0")/../.."
[ "$#" -gt 0 ] || set -- daemon/Dockerfile deploy/images/*/Dockerfile

status=0
for f in "$@"; do
	awk -v file="$f" '
		function fail(msg) { printf "%s:%d: %s\n", file, NR, msg; bad = 1 }
		function pinned(ref) { return ref ~ /@sha256:[0-9a-f]{64}$/ }
		tolower($0) ~ /^#[ \t]*syntax[ \t]*=/ {
			ref = $0; sub(/^#[ \t]*[sS][yY][nN][tT][aA][xX][ \t]*=[ \t]*/, "", ref); sub(/[ \t]+$/, "", ref)
			if (!pinned(ref)) fail("unpinned Dockerfile frontend: " ref)
			next
		}
		toupper($1) == "ARG" {
			split($2, kv, "="); name = kv[1]; val = substr($2, length(name) + 2)
			args[name] = val
			next
		}
		toupper($1) == "FROM" {
			i = 2
			while ($i ~ /^--/) i++
			ref = $i
			if (toupper($(i + 1)) == "AS") stages[tolower($(i + 2))] = 1
			if (tolower(ref) in stages || ref == "scratch") next
			if (ref ~ /^\$\{?[A-Za-z_][A-Za-z0-9_]*\}?$/) {
				name = ref; gsub(/[${}]/, "", name)
				if (!(name in args)) { fail("FROM uses ARG " name " without a default"); next }
				ref = args[name]
			}
			if (ref ~ /\$/) { fail("FROM interpolates an ARG inside the reference (pin the whole reference in an ARG default): " $i); next }
			if (!pinned(ref)) fail("unpinned base image: " ref)
		}
		END { exit bad }
	' "$f" || status=1
done
[ "$status" -eq 0 ] && echo "All base images and frontends in $* are pinned by digest."
exit "$status"
