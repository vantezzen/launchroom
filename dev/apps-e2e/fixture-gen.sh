#!/bin/sh
# Generates a pristine sample app with its framework's own generator
# (`make fixture-gen NAME=<name>`, NAME=all for every recipe; dev/fixtures/README.md).
#
# A recipe is dev/apps-e2e/gen/<name>.sh. Its `# stage: <PIN> <function>`
# lines name the pinned image (a key of deploy/pins.env) and the function that
# runs in it, in order; every stage runs in a fresh `--rm` container labelled
# launchroom.dev=true (as root: generators look up their user's
# passwd entry; the last stage hands the files back), with /work bind-mounted from
# dev/data/fixture-gen/<name>/. A recipe writes the app to /work/app and may
# write generated branch overlays to /work/branches/<branch>/<step>/. $FIXTURE
# is the fixture name (generators name the package after their directory).
#
# The fixture is what a developer would commit right after running the
# generator: the files git tracks under the app's own .gitignore (so no
# node_modules, vendor or build output, but the lockfiles), exported into
# dev/fixtures/<name>/. Nothing in it is edited afterwards. Hand-written
# overlays in dev/fixtures/<name>/.branches/ survive a regeneration.

set -eu

cd "$(dirname "$0")/../.." || exit 1

PINS=deploy/pins.env
pin() { sed -n "s/^$1=//p" "$PINS" | tail -n 1; }

usage() {
	echo "usage: make fixture-gen NAME=<name>|all   (recipes: $(find dev/apps-e2e/gen -name '[a-z0-9]*.sh' | sed 's|.*/||; s|\.sh$||' | sort | tr '\n' ' '))" >&2
	exit 2
}

# run_stage NAME IMAGE FUNCTION: one recipe stage in a throwaway container.
run_stage() {
	docker run --rm --label launchroom.dev=true --name "launchroom-dev-fixture-gen-$1" \
		--user 0:0 --env-file "$PINS" -e LR_OWNER="$(id -u):$(id -g)" -e FIXTURE="$1" \
		-e HOME=/tmp/home -e CI=1 -e NEXT_TELEMETRY_DISABLED=1 -e ASTRO_TELEMETRY_DISABLED=1 \
		-e NUXT_TELEMETRY_DISABLED=1 -e TURBO_TELEMETRY_DISABLED=1 -e DO_NOT_TRACK=1 \
		-e npm_config_before="$(pin LR_FIXTURE_NPM_BEFORE)" -e npm_config_update_notifier=false \
		-e npm_config_fund=false -e npm_config_audit=false \
		-e COMPOSER_HOME=/tmp/home/composer -e COMPOSER_NO_INTERACTION=1 -e COMPOSER_ALLOW_SUPERUSER=1 \
		-v "$PWD/dev/apps-e2e/gen:/gen:ro" -v "$PWD/dev/data/fixture-gen/$1:/work" -w /work \
		--entrypoint sh "$2" -c '. /gen/_lib.sh && . /gen/"$1".sh && "$2"' gen "$1" "$3"
}

generate() {
	name=$1
	recipe="dev/apps-e2e/gen/$name.sh"
	[ -f "$recipe" ] || { echo "No recipe $recipe (hand-written fixtures are edited in place)." >&2; return 2; }
	work="dev/data/fixture-gen/$name"
	echo "== $name"
	rm -rf "$work"
	mkdir -p "$work/app" "$work/branches" "$work/tmp"

	stages="$(sed -n 's/^# stage: \([A-Z0-9_]*\) \([a-z_]*\)$/\1 \2/p' "$recipe")"
	[ -n "$stages" ] || { echo "$recipe has no '# stage: <PIN> <function>' line" >&2; return 1; }
	printf '%s\n' "$stages" | while read -r key fn; do
		image="$(pin "$key")"
		[ -n "$image" ] || { echo "$key is not pinned in $PINS" >&2; exit 1; }
		echo "-- $fn in $image"
		run_stage "$name" "$image" "$fn" || exit 1
	done || return 1

	# What `git add -A` takes under the app's own .gitignore, exported without
	# .git; symlinks are refused (the git-fixtures server can't copy them).
	run_stage "$name" "$(pin LR_FIXTURE_NODE_IMAGE)" export_tracked || return 1

	dest="dev/fixtures/$name"
	if [ -d "$dest/.branches" ]; then
		mv "$dest/.branches" "$work/kept-branches"
	fi
	rm -rf "$dest"
	mv "$work/out" "$dest"
	if [ -d "$work/kept-branches" ]; then
		mv "$work/kept-branches" "$dest/.branches"
	fi
	if [ -n "$(ls -A "$work/branches")" ]; then
		mkdir -p "$dest/.branches"
		cp -R "$work/branches/." "$dest/.branches/"
	fi
	rm -rf "$work"
	echo "   $(find "$dest" -path "$dest/.branches" -prune -o -type f -print | wc -l | tr -d ' ') files in $dest ($(du -sh "$dest" | cut -f1))"
}

[ $# -eq 1 ] && [ -n "$1" ] || usage
if [ "$1" = all ]; then
	status=0
	for recipe in dev/apps-e2e/gen/[a-z0-9]*.sh; do
		generate "$(basename "$recipe" .sh)" || { status=1; echo "!! $(basename "$recipe" .sh) failed" >&2; }
	done
	exit "$status"
fi
case "$1" in *[!a-z0-9-]*) usage ;; esac
generate "$1"
