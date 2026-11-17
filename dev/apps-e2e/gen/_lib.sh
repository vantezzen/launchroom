# Shared by every recipe stage (dev/apps-e2e/fixture-gen.sh sources it first).
# Runs inside the stage's container: /work/app is the fixture, /work/tmp is
# scratch, /work/branches/<branch>/<step>/ holds generated overlays.
# shellcheck shell=sh

set -eu
mkdir -p "$HOME/bin"
export PATH="$HOME/bin:$PATH"

# adopt DIR: the generator wrote the app into DIR; make it the fixture.
adopt() {
	find "$1" -mindepth 1 -maxdepth 1 -exec mv {} /work/app/ \;
	rmdir "$1"
}

# export_tracked: the files `git add -A` would take (the app's .gitignore
# decides), exported to /work/out with their modes, and everything handed
# back to the calling user. Runs in the node image.
export_tracked() {
	cd /work/app
	find . -name .git -prune -exec rm -rf {} +
	git config --global --add safe.directory "*"
	git init -q
	git -c core.autocrlf=false add -A
	links="$(git ls-files -s | awk '$1 == "120000" { print $4 }')"
	if [ -n "$links" ]; then
		echo "symlinks are not supported by the git-fixtures server:" >&2
		echo "$links" >&2
		exit 1
	fi
	git checkout-index -a --prefix=/work/out/
	rm -rf .git
	chown -R "$LR_OWNER" /work
}
