# create-nuxt, minimal template.
# shellcheck shell=sh disable=SC2034
GAPS="G53"

check_main() {
	expect G53 "/ answers 200 (.output/server/index.mjs)" code_is 200 "$APP" /
	expect G53 "the Nuxt preset was applied" log_matches 'Applied preset nuxt'
	expect G53 "no no-start-script warning" log_lacks 'no-start-script'
}
