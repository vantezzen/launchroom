# create-turbo with pnpm (apps/web, apps/docs, packages/ui).
# shellcheck shell=sh disable=SC2034
GAPS="G50"
PREVIEW_BRANCH=""

check_main() {
	expect G50 "/ answers 200" code_is 200 "$APP" /
	todo G50 "service root apps/web builds and imports @repo/ui" "needs a dev import with a root directory (launchroom:dev:deploy-app has no --root)"
	todo G50 "a change in packages/ui rebuilds apps/web, one in apps/docs doesn't" "needs watch paths and a push simulation"
}
