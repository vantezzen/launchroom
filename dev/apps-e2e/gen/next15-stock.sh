# Next.js 15.5, App Router, TypeScript: create-next-app's defaults.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-next-app@$LR_FIXTURE_CREATE_NEXT_APP_15" "$FIXTURE" --ts --app --eslint --tailwind --no-src-dir \
		--import-alias '@/*' --turbopack --use-npm --disable-git --yes
	adopt "$FIXTURE"
}
