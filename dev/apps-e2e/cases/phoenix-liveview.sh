# `mix phx.new` (LiveView, Postgres). The probe branch adds `mix phx.gen.release`.
# shellcheck shell=sh disable=SC2034
GAPS="G73 G47"
DATABASE=1
PREVIEW_BRANCH=probe
PROBE=1

websocket() { # HOST: the LiveView socket upgrades through the edge (check_origin = PHX_HOST)
	FETCH_TIMEOUT=5 fetch "$1" "/live/websocket?vsn=2.0.0" --http1.1 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
		-H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H "Origin: https://$1:$PORT"
	[ "$CODE" = 101 ]
}

check_main() {
	expect G73 "/ answers 200 (PHX_SERVER, SECRET_KEY_BASE)" code_is 200 "$APP" /
	expect "G47 G73" "LiveView WebSocket upgrade through the edge (PHX_HOST)" websocket "$APP"
	expect G73 "the build warns that there is no release module" log_matches 'phoenix-no-release-module|phx.gen.release'
}

check_probe() {
	expect G73 "with a release module, / answers 200 (migrations ran)" code_is 200 "$PROBE_HOST" /
}

check_preview() {
	expect "G47 G73" "LiveView WebSocket upgrade in the preview" websocket "$PREVIEW"
}
