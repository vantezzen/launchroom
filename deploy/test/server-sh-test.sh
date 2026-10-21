#!/bin/sh
# Unit tests for install/server.sh's platform branches (docs/11 §3.3, D53).
# Runs in a throwaway busybox container (make test-server-sh): uname, docker,
# system_profiler and sysctl are stubs on PATH, so the macOS branch runs
# without a Mac and without any Docker engine. Nothing is installed anywhere
# but the container's /tmp.
set -eu

SCRIPT="${1:-/mnt/install/server.sh}"
WORK="$(mktemp -d)"
STUBS="$WORK/bin"
mkdir -p "$STUBS"
FAILED=0

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	FAILED=1
}

# --- stubs --------------------------------------------------------------
cat >"$STUBS/uname" <<'EOF'
#!/bin/sh
printf '%s\n' "${STUB_UNAME:-Darwin}"
EOF
cat >"$STUBS/system_profiler" <<'EOF'
#!/bin/sh
[ "$1 $2 $3" = "-detailLevel mini SPHardwareDataType" ] || exit 1
printf 'Hardware:\n\n    Hardware Overview:\n\n'
[ -z "${STUB_MODEL-Mac mini}" ] || printf '      Model Name: %s\n' "${STUB_MODEL-Mac mini}"
printf '      Chip: Apple M2\n'

EOF
cat >"$STUBS/sysctl" <<'EOF'
#!/bin/sh
[ "$*" = "-n hw.model" ] && printf 'Mac14,3\n'
EOF
cat >"$STUBS/docker" <<'EOF'
#!/bin/sh
printf 'docker %s [%s]\n' "$*" "$(pwd)" >>"$STUB_LOG"
case "$*" in
"version --format {{.Server.Version}}")
	[ "${STUB_DOCKER_DOWN:-}" != 1 ] || exit 1
	printf '28.5.1\n' ;;
"compose version") printf 'Docker Compose version v2.40.0\n' ;;
"context inspect --format {{.Endpoints.docker.Host}}") printf '%s\n' "${STUB_ENDPOINT:-unix:///Users/me/.orbstack/run/docker.sock}" ;;
"info --format {{.OperatingSystem}}|{{.Name}}") printf '%s\n' "${STUB_INFO:-OrbStack|orbstack}" ;;
"info --format {{.Architecture}}") printf '%s\n' "${STUB_ARCH:-aarch64}" ;;
"info --format {{range .SecurityOptions}}{{.}} {{end}}") printf 'name=seccomp,profile=builtin name=cgroupns \n' ;;
"info --format {{.CgroupDriver}}") printf 'systemd\n' ;;
"compose up"*)
	# launchroomd "enrolls" as soon as it is started with a token.
	[ ! -e data/join-token ] || printf '{}\n' >data/enrollment.json ;;
"compose"*) ;;
*) printf 'unexpected: docker %s\n' "$*" >&2; exit 1 ;;
esac
EOF
chmod +x "$STUBS"/*

# A minimal unpacked release bundle (LR_BUNDLE_DIR skips the download).
BUNDLE="$WORK/bundle"
mkdir -p "$BUNDLE/daemon"
printf 'name: launchroomd\n' >"$BUNDLE/daemon/compose.yml"

# run NAME [args…]: runs server.sh in a fresh HOME; output in $WORK/NAME.out,
# exit status in $STATUS.
run() {
	name="$1"
	shift
	export HOME="$WORK/$name/home" STUB_LOG="$WORK/$name/docker.log"
	mkdir -p "$HOME"
	STATUS=0
	(cd "$HOME" && PATH="$STUBS:$PATH" \
		LR_BUNDLE_DIR="$BUNDLE" LR_BUNDLE_VERIFIED=1 \
		LR_JOIN_TOKEN=lr_jt_0123456789abcdefghij \
		LR_CONTROL_PLANE_URL=https://dash.example.com \
		LR_CP_KEY=ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc= \
		LR_DAEMON_IMAGE=ghcr.io/vantezzen/launchroomd@sha256:0000000000000000000000000000000000000000000000000000000000000000 \
		sh "$SCRIPT" "$@") >"$WORK/$name.out" 2>&1 || STATUS=$?
}

has() { grep -q -- "$2" "$1" || fail "$1 lacks '$2': $(cat "$1")"; }
lacks() { ! grep -q -- "$2" "$1" || fail "$1 has '$2'"; }
ok() { [ "$STATUS" = 0 ] || fail "$1 exited $STATUS: $(cat "$WORK/$1.out")"; }
refused() {
	[ "$STATUS" != 0 ] || fail "$1 should have been refused"
	has "$WORK/$1.out" "$2"
}

# --- macOS: OrbStack, fresh install --------------------------------------
run orbstack
ok orbstack
env="$WORK/orbstack/home/launchroom/.env"
has "$env" '^LR_HOST_OS=darwin$'
has "$env" '^LR_HOST_MODEL=Mac mini$'
has "$env" '^LR_JOIN_TOKEN_FILE=$'
lacks "$env" 'LR_DOCKER_SOCKET'
lacks "$env" 'COMPOSE_FILE'
lacks "$env" 'LR_ROOTLESSKIT_API'
[ -f "$WORK/orbstack/home/launchroom/compose.yml" ] || fail "no compose.yml in ~/launchroom"
[ ! -e "$WORK/orbstack/home/launchroom/data/join-token" ] || fail "join token left behind"
[ ! -e "$WORK/orbstack/home/launchroomd" ] || fail "installed into ./launchroomd on macOS"
has "$WORK/orbstack/docker.log" "compose up -d --remove-orphans \[$WORK/orbstack/home/launchroom\]"
has "$WORK/orbstack.out" 'macOS: OrbStack (aarch64 VM), Docker 28.5.1 at /Users/me/.orbstack/run/docker.sock'
has "$WORK/orbstack.out" 'Start at login'
has "$WORK/orbstack.out" 'Prevent automatic sleeping'
lacks "$WORK/orbstack.out" 'can build, but not run apps'
[ ! -e "$WORK/orbstack/home/launchroom/compose.rootless.yml" ] || fail "rootless override on macOS"

# Re-run on the enrolled Mac: updates only, keeps the hints and the time zone.
printf 'LR_HOST_TIMEZONE=Europe/Berlin\n' >>"$env"
export HOME="$WORK/orbstack/home" STUB_LOG="$WORK/orbstack/docker.log"
STATUS=0
(cd "$HOME" && PATH="$STUBS:$PATH" LR_BUNDLE_DIR="$BUNDLE" LR_BUNDLE_VERIFIED=1 \
	LR_CONTROL_PLANE_URL=https://dash.example.com LR_CP_KEY=ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc= \
	LR_DAEMON_IMAGE=ghcr.io/vantezzen/launchroomd@sha256:0000000000000000000000000000000000000000000000000000000000000000 \
	sh "$SCRIPT") >"$WORK/rerun.out" 2>&1 || STATUS=$?
ok rerun
has "$WORK/rerun.out" 'already enrolled'
has "$env" '^LR_HOST_TIMEZONE=Europe/Berlin$'
has "$env" '^LR_HOST_OS=darwin$'

# --- macOS: Docker Desktop builds only; the model falls back to hw.model --
STUB_INFO='Docker Desktop|docker-desktop' STUB_ENDPOINT=unix:///Users/me/.docker/run/docker.sock STUB_MODEL='' run desktop
ok desktop
has "$WORK/desktop.out" 'Docker Desktop: this Mac can build, but not run apps'
has "$WORK/desktop.out" 'Start Docker Desktop when you sign in'
has "$WORK/desktop/home/launchroom/.env" '^LR_HOST_MODEL=Mac14,3$'

# --- macOS: Colima, an x86_64 (Intel) Mac and a MacBook --------------------
STUB_INFO='Ubuntu 24.04.2 LTS|colima' STUB_ARCH=x86_64 STUB_MODEL='MacBook Pro' STUB_ENDPOINT=unix:///Users/me/.colima/default/docker.sock run colima
ok colima
has "$WORK/colima.out" 'macOS: Colima (x86_64 VM)'
has "$WORK/colima.out" 'brew services start colima'
has "$WORK/colima/home/launchroom/.env" '^LR_HOST_MODEL=MacBook Pro$'

# A model name that doesn't look like one is dropped.
STUB_MODEL='Mac mini; rm -rf /' run oddmodel
ok oddmodel
has "$WORK/oddmodel/home/launchroom/.env" '^LR_HOST_MODEL=$'

# --- macOS: refused setups ---------------------------------------------
STUB_INFO='Ubuntu 24.04.2 LTS|colima-work' LAUNCHROOM_DAEMON_DIR=/opt/launchroom run colima-outside
refused colima-outside 'Colima only shares your home folder'

STUB_INFO='Alpine Linux v3.22|lima-rancher-desktop' run rancher
refused rancher 'this Docker engine (Alpine Linux v3.22) is not supported on macOS'

DOCKER_HOST=tcp://203.0.113.7:2376 run remote
refused remote 'talks to tcp://203.0.113.7:2376, not to an engine on this Mac'

STUB_ENDPOINT=ssh://me@vps run remote-context
refused remote-context 'talks to ssh://me@vps'

run rootless --rootless
refused rootless '--rootless is for Linux servers'

STUB_ARCH=riscv64 run riscv
refused riscv 'needs x86_64 or aarch64'

STUB_DOCKER_DOWN=1 run stopped
refused stopped 'Start OrbStack, Docker Desktop or Colima'

PATH_NO_DOCKER="$WORK/nodocker"
mkdir -p "$PATH_NO_DOCKER"
cp "$STUBS/uname" "$PATH_NO_DOCKER/"
export HOME="$WORK/nodocker-home" STUB_LOG="$WORK/nodocker.log"
mkdir -p "$HOME"
STATUS=0
(cd "$HOME" && PATH="$PATH_NO_DOCKER:/bin" LR_BUNDLE_DIR="$BUNDLE" sh "$SCRIPT") >"$WORK/nodocker.out" 2>&1 || STATUS=$?
refused nodocker 'no Docker found. Install and start OrbStack'

STUB_UNAME=FreeBSD run freebsd
refused freebsd 'run on Linux or macOS (found FreeBSD)'

# --- Linux keeps its layout ----------------------------------------------
STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' run linux
ok linux
[ -f "$WORK/linux/home/launchroomd/.env" ] || fail "Linux install not in ./launchroomd"
lacks "$WORK/linux/home/launchroomd/.env" 'LR_HOST_OS'
lacks "$WORK/linux/home/launchroomd/.env" 'LR_HOST_MODEL'
lacks "$WORK/linux.out" 'macOS'

rm -rf "$WORK"
if [ "$FAILED" != 0 ]; then
	exit 1
fi
printf 'server.sh platform tests passed\n'
