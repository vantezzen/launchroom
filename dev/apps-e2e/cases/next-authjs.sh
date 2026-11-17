# Next.js 16 + Auth.js v5 Credentials (no AUTH_SECRET, no AUTH_TRUST_HOST in the repo).
# shellcheck shell=sh disable=SC2034
GAPS="G51 G48"

check_main() {
	expect "G51 G48" "/api/auth/signin answers 200 (no UntrustedHost, no MissingSecret)" code_is 200 "$APP" /api/auth/signin
	expect G51 "sign-in callback URLs use the environment's https domain" body_matches "https://$APP(:$PORT)?/api/auth/callback/credentials" "$APP" /api/auth/providers
}

check_preview() {
	expect G51 "callback URLs use the preview's domain" body_matches "https://$PREVIEW(:$PORT)?/api/auth/callback/credentials" "$PREVIEW" /api/auth/providers
}
