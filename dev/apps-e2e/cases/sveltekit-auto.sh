# SvelteKit with its default adapter-auto: works today (regression).
# shellcheck shell=sh disable=SC2034
GAPS="REG"

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
}
