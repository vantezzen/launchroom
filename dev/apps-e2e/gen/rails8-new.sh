# Rails 8: `rails new` with its defaults (SQLite, Dockerfile, Kamal, Solid
# Queue/Cache/Cable, importmap). config/master.key is git-ignored, so the
# fixture needs RAILS_MASTER_KEY like any freshly pushed Rails app.
# stage: LR_FIXTURE_RUBY_IMAGE rb_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

rb_stage() {
	export GEM_HOME="$HOME/gems" PATH="$HOME/gems/bin:$PATH"
	gem install rails -v "$LR_FIXTURE_RAILS" --no-document
	cd /work/tmp
	rails "_${LR_FIXTURE_RAILS}_" new "$FIXTURE"
	adopt "$FIXTURE"
}
