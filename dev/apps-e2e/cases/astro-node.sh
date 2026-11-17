# Astro with @astrojs/node (standalone). The probe branch adds an on-demand
# page that accepts a form POST.
# shellcheck shell=sh disable=SC2034
GAPS="G53 G54"
PREVIEW_BRANCH=""
PROBE=1

check_main() {
	expect G53 "/ answers 200 (dist/server/entry.mjs)" code_is 200 "$APP" /
}

check_probe() {
	expect G54 "form POST answers 200 (no 'Cross-site POST form submissions are forbidden')" code_is 200 "$PROBE_HOST" /probe \
		-X POST -H "Origin: https://$PROBE_HOST:$PORT" --data 'value=hi'
	expect G54 "Astro.url is https" body_matches '<p id="url">https://' "$PROBE_HOST" /probe
}
