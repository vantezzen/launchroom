# Rocket hello world (default 127.0.0.1:8000: needs ROCKET_ADDRESS and ROCKET_PORT), no port configured.
# shellcheck shell=sh disable=SC2034
GAPS="G74"
PREVIEW_BRANCH=""

check_main() {
	expect G74 "/ answers 200 on the detected port" code_is 200 "$APP" /
}
