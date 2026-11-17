# `laravel new --react` (Laravel 13, Inertia, Wayfinder) with Postgres (the
# import preselects it, OD22). The probe branch adds /probe (URL, scheme,
# client address); duplicate-route can't be route:cached.
# shellcheck shell=sh disable=SC2034
GAPS="G47 G62 G63 G64"
DATABASE=1
PREVIEW_BRANCH=probe
PROBE=1

login_post() { # HOST: POST /login with the session's CSRF token answers a redirect (not 419)
	rm -f "$WORK/jar"
	fetch "$1" /login -c "$WORK/jar"
	token="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$WORK/jar" | sed 's/%/\\x/g')"
	token="$(printf '%b' "$token")"
	fetch "$1" /login -b "$WORK/jar" -c "$WORK/jar" -X POST -H "X-XSRF-TOKEN: $token" \
		-H "Origin: https://$1:$PORT" --data 'email=nobody%40example.com&password=wrong'
	[ "$CODE" = 302 ]
}

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
	expect G61 "/up answers 200" code_is 200 "$APP" /up
	expect "G47 G63" "page assets load over https (no http:// script or style URLs)" body_lacks '(src|href)="http://' "$APP" /
	expect "G47 G63" "login form POST over https works (302, not 419)" login_post "$APP"
	todo G62 "import preselects Postgres for an SQLite default" "needs a dev import through the plan (ImportRepository)"
	todo G65 "queue worker suggested" "needs a dev import through the plan"
}

check_probe() {
	expect G47 "config('app.url') is the environment's https domain" body_matches "\"appUrl\":\"https:\\\\/\\\\/$PROBE_HOST\"" "$PROBE_HOST" /probe
	expect G47 "Storage::url() is https" body_matches '"storageUrl":"https:' "$PROBE_HOST" /probe
	expect G63 "request()->secure() is true" json_value_is secure true "$PROBE_HOST" /probe
	expect G63 "request()->ip() is the visitor, not the edge" not_from_edge ip "$PROBE_HOST" /probe
	expect G62 "the app uses the connected Postgres" json_value_is database pgsql "$PROBE_HOST" /probe
	expect G64 "routes are cached at start" json_value_is routesCached true "$PROBE_HOST" /probe
	expect G64 "an app whose routes can't be cached still starts (uncached)" deploy_branch duplicate-route
}

check_preview() {
	expect G47 "config('app.url') follows the preview domain" body_matches "\"appUrl\":\"https:\\\\/\\\\/$PREVIEW\"" "$PREVIEW" /probe
}
