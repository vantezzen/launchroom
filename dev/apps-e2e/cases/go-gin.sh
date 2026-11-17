# Gin quick start (r.Run(), :8080 unless PORT), no port configured.
# shellcheck shell=sh disable=SC2034
GAPS="G74"
PREVIEW_BRANCH=""

check_main() {
	expect G74 "/ping answers 200 on the detected port" code_is 200 "$APP" /ping
}
