# SvelteKit (sv create, minimal template, TypeScript) with its default adapter-auto.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "sv@$LR_FIXTURE_SV" create "$FIXTURE" --template minimal --types ts --no-add-ons --install npm
	adopt "$FIXTURE"
}
