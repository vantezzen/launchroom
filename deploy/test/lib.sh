# Shared by the end-to-end tests deploy/test/e2e-{install,move,update}.sh,
# sourced from the repo root: the tool pins (deploy/pins.env), progress
# output, polling and building images the way a release does.

# shellcheck source=deploy/pins.env
. deploy/pins.env

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nFAIL: %s\n' "$*" >&2; exit 1; }
ok() { printf '    ok: %s\n' "$*"; }

# retry SECONDS COMMAND...: run until it succeeds or the time is up.
retry() {
	limit=$1
	shift
	start=$(date +%s)
	until "$@" >/dev/null 2>&1; do
		[ $(($(date +%s) - start)) -lt "$limit" ] || return 1
		sleep 3
	done
}

# build_images DASHBOARD CADDY: the dashboard's production target and
# launchroom-caddy (deploy/images/caddy). SKIP_BUILD=1 reuses existing ones.
build_images() {
	if [ "${SKIP_BUILD:-0}" = 1 ]; then
		step "SKIP_BUILD=1: using the existing $1 and $2"
		return
	fi
	step "building images (dashboard production target, launchroom-caddy)"
	docker build -q -f deploy/images/dashboard/Dockerfile --target production \
		--label launchroom.dev=true -t "$1" . >/dev/null
	docker build -q --label launchroom.dev=true -t "$2" deploy/images/caddy >/dev/null
	ok "built $1 and $2"
}

# digest_in VPS IMAGE: IMAGE's index digest in VPS's Docker after a load.
digest_in() {
	d="$(docker exec "$1" docker image inspect "$2" --format '{{index .RepoDigests 0}}' | sed 's/.*@//')"
	printf '%s' "$d" | grep -Eq '^sha256:[0-9a-f]{64}$' || fail "no digest for $2 in $1 ($d)"
	printf '%s' "$d"
}

# build_daemon IMAGE CADDY_DIGEST [VERSION]: launchroomd's image built like a
# release, with that edge Caddy's digest compiled in (daemon/build.sh).
build_daemon() {
	docker build -q ${3:+--build-arg VERSION="$3"} --build-arg CADDY_DIGEST="$2" \
		--label launchroom.dev=true -t "$1" daemon >/dev/null
}
