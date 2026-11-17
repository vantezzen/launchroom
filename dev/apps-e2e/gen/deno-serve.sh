# Deno: `deno init --serve` (a `deno serve` app, port not configured), then
# `deno install` for deno.lock.
# stage: LR_FIXTURE_DENO_IMAGE deno_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

deno_stage() {
	export DENO_DIR="$HOME/deno"
	cd /work/tmp
	deno init --serve "$FIXTURE"
	cd "$FIXTURE"
	deno install
	cd ..
	adopt "$FIXTURE"
}
