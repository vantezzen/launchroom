# SvelteKit with adapter-node. The probe branch adds /probe (form action,
# client address, URL).
# shellcheck shell=sh disable=SC2034
GAPS="G53"
PREVIEW_BRANCH=probe
PROBE=1

check_main() {
	expect G53 "/ answers 200 (node build)" code_is 200 "$APP" /
}

check_probe() {
	expect G53 "form action POST answers 200 (no cross-site 403: ORIGIN or the protocol header known)" code_is 200 "$PROBE_HOST" /probe \
		-X POST -H "Origin: https://$PROBE_HOST:$PORT" -H 'Accept: text/html' --data 'value=hi'
	expect G53 "getClientAddress() is the visitor (ADDRESS_HEADER)" body_lacks "<p id=\"client\">($(edge_ips | tr ' ' '|' | sed 's/|*$//'))</p>" "$PROBE_HOST" /probe
	expect G53 "url.href is https" body_matches '<p id="url">https://' "$PROBE_HOST" /probe
}

check_preview() {
	expect G53 "url.href is the preview's https URL" body_matches "<p id=\"url\">https://$PREVIEW" "$PREVIEW" /probe
}
