# Existing fixture: Vite SPA served by Railpack's Caddy.
# shellcheck shell=sh disable=SC2034
GAPS="G57"
PREVIEW_BRANCH=""

asset_immutable() { # HOST
	fetch "$1" /
	asset="$(sed -n 's/.*src="\(\/assets\/[^"]*\.js\)".*/\1/p' "$WORK/body" | head -n 1)"
	[ -n "$asset" ] || { DETAIL="no hashed asset in /"; return 1; }
	header_matches '^cache-control:.*immutable' "$1" "$asset"
}

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
	expect G57 "hashed assets are cached immutable" asset_immutable "$APP"
	expect G57 "index.html is not cached" header_matches '^cache-control:.*no-cache' "$APP" /
}
