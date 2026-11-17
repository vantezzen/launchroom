# FastAPI with only /items/{id}: `/` is a 404. The probe branch adds /probe.
# shellcheck shell=sh disable=SC2034
GAPS="G46 G70"
PREVIEW_BRANCH=probe

check_main() {
	expect G46 "/items/1 answers 200 (the implicit / health check accepted its 404)" code_is 200 "$APP" /items/1
}

check_preview() {
	expect G70 "request.url.scheme is https" json_value_is scheme https "$PREVIEW" /probe
}
