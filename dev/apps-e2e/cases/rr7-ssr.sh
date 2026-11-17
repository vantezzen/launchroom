# create-react-router (framework mode, SSR). The probe branch adds /probe (request.url).
# shellcheck shell=sh disable=SC2034
GAPS="G54"
PREVIEW_BRANCH=probe

check_main() {
	expect G53 "/ answers 200" code_is 200 "$APP" /
}

check_preview() {
	expect G54 "request.url starts with https://" body_matches '"url":"https://' "$PREVIEW" /probe
}
