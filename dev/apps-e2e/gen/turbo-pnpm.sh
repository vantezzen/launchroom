# create-turbo's starter with pnpm: apps/web and apps/docs (Next.js),
# packages/ui, packages/eslint-config, packages/typescript-config.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	export COREPACK_ENABLE_DOWNLOAD_PROMPT=0
	corepack enable --install-directory "$HOME/bin"
	cd /work/tmp
	npx -y "create-turbo@$LR_FIXTURE_CREATE_TURBO" "$FIXTURE" --package-manager pnpm --skip-transforms
	adopt "$FIXTURE"
}
