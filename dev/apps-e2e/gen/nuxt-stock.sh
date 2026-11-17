# Nuxt (create-nuxt, template "minimal"), npm, no extra modules.
# The template comes from nuxt/starter on GitHub (not versioned by create-nuxt).
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-nuxt@$LR_FIXTURE_CREATE_NUXT" "$FIXTURE" --template minimal --packageManager=npm --no-gitInit --force
	adopt "$FIXTURE"
}
