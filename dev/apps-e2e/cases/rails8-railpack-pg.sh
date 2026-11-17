# `rails new --database=postgresql --skip-docker`, built by Railpack, with Postgres.
# shellcheck shell=sh disable=SC2034
GAPS="G71"
DATABASE=1

check_main() {
	expect G71 "/up answers 200 (RAILS_ENV, SECRET_KEY_BASE, db:prepare)" code_is 200 "$APP" /up
}

check_preview() {
	expect G71 "/up answers 200 in the preview" code_is 200 "$PREVIEW" /up
}
