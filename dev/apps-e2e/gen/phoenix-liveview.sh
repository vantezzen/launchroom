# Phoenix with LiveView: `mix phx.new` (Ecto on Postgres) and `mix deps.get`
# for mix.lock. The `probe` branch adds what `mix phx.gen.release` writes
# (release module with migrate, rel/overlays/bin/{server,migrate}).
# stage: LR_FIXTURE_ELIXIR_IMAGE ex_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

ex_stage() {
	export MIX_HOME="$HOME/mix" HEX_HOME="$HOME/hex" MIX_ENV=dev
	mix local.hex --force
	mix local.rebar --force
	mix archive.install hex phx_new "$LR_FIXTURE_PHX_NEW" --force
	cd /work/tmp
	mix phx.new "$FIXTURE" --app phoenix_liveview --no-install
	cd "$FIXTURE"
	mix deps.get

	# The release files, as the probe branch overlay.
	cp -a . ../release
	cd ../release
	git config --global --add safe.directory "*"
	git init -q
	git add -A
	git -c user.name=gen -c user.email=gen@launchroom.invalid commit -qm base
	mix phx.gen.release
	git add -A
	mkdir -p /work/branches/probe/1
	git diff --cached --name-only --diff-filter=AM | while read -r f; do
		mkdir -p "/work/branches/probe/1/$(dirname "$f")"
		cp -p "$f" "/work/branches/probe/1/$f"
	done
	cd ..
	rm -rf release
	adopt "$FIXTURE"
}
