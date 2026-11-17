# `symfony new --webapp` (Doctrine, Postgres).
# shellcheck shell=sh disable=SC2034
GAPS="G67"
DATABASE=1

check_main() {
	expect G67 "the app answers (Symfony's prod 404 for / counts)" code_in "200 404" "$APP" /
	expect G67 "the Symfony preset was applied" log_matches 'Applied preset symfony'
	todo G67 "APP_SECRET generated, migrations run as the release step" "needs the environment's variables and the release log"
}
