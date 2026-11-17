# deno init --serve (deno serve, default port 8000), no port configured.
# shellcheck shell=sh disable=SC2034
GAPS="G74"
PREVIEW_BRANCH=""

check_main() {
	expect G74 "/ answers 200 on the detected port" code_is 200 "$APP" /
}
