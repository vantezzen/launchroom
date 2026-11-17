# Laravel 12 + Filament v4 panel + Horizon, with Postgres.
# shellcheck shell=sh disable=SC2034
GAPS="G60 G30"
DATABASE=1

check_main() {
	expect G60 "the image has intl and pcntl (deploy green) and /admin/login answers 200" code_is 200 "$APP" /admin/login
	todo G30 "Horizon runs as its own process" "needs a dev import with the suggested processes"
	todo G30 "a 20 s job survives a redeploy" "needs the Horizon process and a job endpoint (probe)"
}

check_preview() {
	expect G60 "/admin/login answers 200 in the preview" code_is 200 "$PREVIEW" /admin/login
}
