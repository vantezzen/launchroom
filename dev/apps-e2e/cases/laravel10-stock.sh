# Laravel 10 skeleton: no /up route.
# shellcheck shell=sh disable=SC2034
GAPS="G61 G65"

check_main() {
	expect G61 "/ answers 200 (health falls back to /)" code_is 200 "$APP" /
	todo G65 "scaling to 2 replicas warns about file sessions" "needs the scaling dialog's plan (dashboard only)"
}

check_preview() {
	expect G61 "/ answers 200 in the preview" code_is 200 "$PREVIEW" /
}
