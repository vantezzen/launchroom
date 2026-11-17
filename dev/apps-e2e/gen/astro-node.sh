# Astro (create-astro, minimal template) with the Node adapter added by `astro add node`.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-astro@$LR_FIXTURE_CREATE_ASTRO" "$FIXTURE" --template minimal --install --no-git --yes --skip-houston
	cd "$FIXTURE"
	npx astro add "@astrojs/node@$LR_FIXTURE_ASTRO_NODE" --yes
	cd ..
	adopt "$FIXTURE"
}
