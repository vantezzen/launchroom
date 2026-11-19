# create-next-app 16 defaults. The probe branch adds a form action, a
# client-called action, a cached page (`use cache`, 60 s) and proxy.ts.
# shellcheck shell=sh disable=SC2034
GAPS="G49 G48 G56 G59"
PREVIEW_BRANCH=probe
PROBE=1

client_action() { # HOST ID BODY
	code_is 200 "$1" /probe/actions -X POST -H 'Content-Type: text/plain;charset=UTF-8' -H "Next-Action: $2" \
		-H 'Accept: text/x-component' -H "Origin: https://$1:$PORT" --data "$3"
}

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
	expect REG "public/ is served" code_is 200 "$APP" /next.svg
	# Not public/next.svg: stock Next refuses SVG in the optimizer (400) without
	# images.dangerouslyAllowSVG, and <Image> never asks it for one.
	expect REG "next/image serves a local image" code_is 200 "$APP" "/_next/image?url=%2Ffavicon.ico&w=64&q=75"
	# With RSC: 1 Next 16 redirects (307) until the request carries the _rsc
	# cache-busting parameter; with no router headers its expected value is empty.
	expect G59 "RSC payload comes back as text/x-component" header_matches 'content-type: text/x-component' "$APP" '/?_rsc=' -H 'RSC: 1'
	expect G56 "no ISR warning at one replica" log_lacks 'isr-cache-warning|shared cache handler'
}

check_probe() {
	id="$(action_id "$PROBE_HOST" /probe/actions)"
	expect G49 "the probe page carries a server action id" test -n "$id"
	expect G49 "form action (multipart, no JS) works in firewall Block" code_in "200 303" "$PROBE_HOST" /probe/actions \
		-X POST -H "Origin: https://$PROBE_HOST:$PORT" -F "\$ACTION_ID_$id=" -F 'value=from the form'
	expect G49 "client-called action (text/plain + Next-Action) passes the firewall in Block" client_action "$PROBE_HOST" "$id" '["from the client"]'
	expect G49 "SQL injection in an action body is still blocked (403)" code_is 403 "$PROBE_HOST" /probe/actions -X POST \
		-H 'Content-Type: text/plain;charset=UTF-8' -H "Next-Action: $id" --data "[\"1' OR 1=1--\"]"
	expect REG "proxy.ts runs" header_matches '^x-probe-proxy: 1' "$PROBE_HOST" /
	expect G56 "cached page answers 200" code_is 200 "$PROBE_HOST" /probe/isr
	if expect G48 "redeploy of the same commit goes live" redeploy "$PROJECT_ID" && wait_deployment "$INSTANCE_ID" "$DEPLOYMENT_ID"; then
		expect G48 "an action id from the previous build still works after the redeploy" client_action "$PROBE_HOST" "$id" '["after redeploy"]'
	fi
}

check_preview() {
	id="$(action_id "$PREVIEW" /probe/actions)"
	expect G49 "client-called action passes the firewall in the preview" client_action "$PREVIEW" "$id" '["preview"]'
}
