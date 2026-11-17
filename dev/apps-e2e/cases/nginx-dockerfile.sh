# FROM nginx:alpine + COPY: nginx switches user at start.
# shellcheck shell=sh disable=SC2034
GAPS="G72"
PREVIEW_BRANCH=""

check_main() {
	expect G72 "/ answers 200 (capabilities granted or the one-click fix offered)" body_matches 'Served by nginx' "$APP" /
}
