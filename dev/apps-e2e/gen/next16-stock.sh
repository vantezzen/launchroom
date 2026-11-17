# Next.js 16, App Router, TypeScript: create-next-app's recommended defaults.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-next-app@$LR_FIXTURE_CREATE_NEXT_APP_16" "$FIXTURE" --yes --use-npm --disable-git
	adopt "$FIXTURE"
}
