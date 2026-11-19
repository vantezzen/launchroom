# create-next-app 15.5 defaults: built through the launchroom adapter.
# shellcheck shell=sh disable=SC2034
GAPS="G52"

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
	expect G52 "no standalone-off warning (the adapter builds standalone output)" log_lacks 'standalone-off|Set output: .standalone'
	expect G52 "the build applies the launchroom Next.js adapter" log_matches 'launchroom.{0,20}adapter|adapter.{0,40}launchroom'
	expect G52 "the build summary counts the app's pages" log_matches '"appPages":[1-9]'
}

check_preview() {
	expect REG "/ answers 200 in the preview" code_is 200 "$PREVIEW" /
}
