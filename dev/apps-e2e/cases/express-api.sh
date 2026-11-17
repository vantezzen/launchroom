# express-generator --no-view. The probe branch adds /probe (req.secure, req.ip).
# shellcheck shell=sh disable=SC2034
GAPS="G54"
PREVIEW_BRANCH=probe

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
}

check_preview() {
	expect G54 "req.secure is true (forwarded preload)" json_value_is secure true "$PREVIEW" /probe
}
