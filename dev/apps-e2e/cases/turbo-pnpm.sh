# create-turbo with pnpm (apps/web, apps/docs, packages/ui), imported with
# the root directory apps/web.
# shellcheck shell=sh disable=SC2034
GAPS="G50"
PREVIEW_BRANCH=""
ROOT_DIR=apps/web

check_main() {
	expect G50 "/ answers 200" code_is 200 "$APP" /
	expect G50 "the page renders @repo/ui's Button" body_matches 'Open alert' "$APP" /
	expect G50 "the build is filtered to apps/web" log_matches 'turbo run build --filter=web\.\.\.'
	expect G50 "standalone server.js sits in the app's folder of the traced tree" log_matches 'standalone output: /app/apps/web/\.next/standalone/apps/web/server\.js'
	todo G50 "a change in packages/ui rebuilds apps/web, one in apps/docs doesn't" "needs watch paths and a push simulation"
}
