# Rails 8: `rails new --database=postgresql --skip-docker` (no Dockerfile, so
# the build goes through Railpack).
# stage: LR_FIXTURE_RUBY_IMAGE rb_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

rb_stage() {
	export GEM_HOME="$HOME/gems" PATH="$HOME/gems/bin:$PATH"
	gem install rails -v "$LR_FIXTURE_RAILS" --no-document
	cd /work/tmp
	rails "_${LR_FIXTURE_RAILS}_" new "$FIXTURE" --database=postgresql --skip-docker
	adopt "$FIXTURE"
}
