#!/bin/sh
# Unit tests for install/server.sh's platform branches (docs/11 §3.3, D53).
# Runs in a throwaway busybox container (make test-server-sh): uname, docker,
# system_profiler, sysctl, curl and id are stubs on PATH, so the macOS branch
# runs without a Mac and without any Docker engine. On Linux the terminal is a
# file (LR_TEST_TTY) and /proc/sys and /etc/sysctl.d live under a fake root
# (LR_TEST_ROOT), for the Docker and inotify prompts. Nothing is installed
# anywhere but the container's /tmp.
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
case "$1" in
-n) [ "$*" = "-n hw.model" ] && printf 'Mac14,3\n' ;;
-w)
	printf 'sysctl %s\n' "$*" >>"$STUB_LOG"
	key="${2%%=*}"
	printf '%s\n' "${2#*=}" >"$LR_TEST_ROOT/proc/sys/fs/inotify/${key#fs.inotify.}" ;;
*) exit 1 ;;
esac
EOF
# curl: only get.docker.com, whose "script" puts the docker stub on PATH.
cat >"$STUBS/curl" <<'EOF'
#!/bin/sh
for last; do :; done
[ "$last" = https://get.docker.com ] || { printf 'unexpected: curl %s\n' "$*" >&2; exit 1; }
while [ "$1" != -o ]; do shift; done
printf '#!/bin/sh\nprintf "get.docker.com ran\\n" >>"$STUB_LOG"\ncp "$STUB_DOCKER" "$STUB_LATE/docker"\n' >"$2"
EOF
cat >"$STUBS/id" <<'EOF'
#!/bin/sh
case "$1" in
-u) printf '%s\n' "${STUB_UID:-0}" ;;
*) printf 'me\n' ;;
esac
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
# apparmor_parser: records the call; STUB_AA_FAIL=1 makes the load fail.
cat >"$STUBS/apparmor_parser" <<'EOF'
#!/bin/sh
printf 'apparmor_parser %s\n' "$*" >>"$STUB_LOG"
[ "${STUB_AA_FAIL:-}" != 1 ] || { printf 'apparmor_parser: syntax error\n' >&2; exit 1; }
EOF
chmod +x "$STUBS"/*
mkdir -p "$WORK/nodocker-linux"
for tool in uname curl sysctl id apparmor_parser; do cp "$STUBS/$tool" "$WORK/nodocker-linux/"; done

# A minimal unpacked release bundle (LR_BUNDLE_DIR skips the download).
BUNDLE="$WORK/bundle"
mkdir -p "$BUNDLE/daemon"
printf 'name: launchroomd\n' >"$BUNDLE/daemon/compose.yml"

# run NAME [args…]: runs server.sh in a fresh HOME; output in $WORK/NAME.out,
# exit status in $STATUS.
#   STUB_TTY        set: the terminal answers this line (empty = Enter); unset: no terminal
#   STUB_WATCHES    fs.inotify.max_user_watches (default 524288)
#   STUB_INSTANCES  fs.inotify.max_user_instances (default 512)
#   STUB_NO_DOCKER  1 = docker is missing until get.docker.com "installs" it
#   STUB_USERNS     kernel.apparmor_restrict_unprivileged_userns (unset: no such file)
#   STUB_APPARMOR   /sys/module/apparmor/parameters/enabled (default Y)
#   STUB_VERIFIED   LR_BUNDLE_VERIFIED (default 1, as in a self-update; empty = a
#                   fresh install from the dashboard's command)
run() {
	name="$1"
	shift
	export HOME="$WORK/$name/home" STUB_LOG="$WORK/$name/docker.log"
	mkdir -p "$HOME" "$WORK/$name/root/proc/sys/fs/inotify" "$WORK/$name/late"
	printf '%s\n' "${STUB_WATCHES:-524288}" >"$WORK/$name/root/proc/sys/fs/inotify/max_user_watches"
	printf '%s\n' "${STUB_INSTANCES:-512}" >"$WORK/$name/root/proc/sys/fs/inotify/max_user_instances"
	mkdir -p "$WORK/$name/root/proc/sys/kernel" "$WORK/$name/root/sys/module/apparmor/parameters"
	[ -z "${STUB_USERNS:-}" ] || printf '%s\n' "$STUB_USERNS" >"$WORK/$name/root/proc/sys/kernel/apparmor_restrict_unprivileged_userns"
	printf '%s\n' "${STUB_APPARMOR:-Y}" >"$WORK/$name/root/sys/module/apparmor/parameters/enabled"
	export LR_TEST_ROOT="$WORK/$name/root" LR_TEST_TTY="$WORK/$name/no-tty"
	if [ -n "${STUB_TTY+set}" ]; then
		LR_TEST_TTY="$WORK/$name/tty"
		printf '%s\n' "$STUB_TTY" >"$LR_TEST_TTY"
	fi
	path="$STUBS:$PATH"
	[ "${STUB_NO_DOCKER:-}" != 1 ] || path="$WORK/$name/late:$WORK/nodocker-linux:$PATH"
	STATUS=0
	touch "$STUB_LOG"
	(cd "$HOME" && PATH="$path" STUB_DOCKER="$STUBS/docker" STUB_LATE="$WORK/$name/late" \
		LR_BUNDLE_DIR="$BUNDLE" LR_BUNDLE_VERIFIED="${STUB_VERIFIED-1}" \
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

# --- Public IP settings from the environment go into .env -----------------
STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' LR_PUBLIC_IP_DETECT=off LR_PUBLIC_IPS=203.0.113.7,2001:db8::7 run linux-ips
ok linux-ips
has "$WORK/linux-ips/home/launchroomd/.env" '^LR_PUBLIC_IP_DETECT=off$'
has "$WORK/linux-ips/home/launchroomd/.env" '^LR_PUBLIC_IPS=203.0.113.7,2001:db8::7$'
lacks "$WORK/linux/home/launchroomd/.env" 'LR_PUBLIC_IP'

STUB_UNAME=Linux LR_PUBLIC_IPS='203.0.113.7;id' run linux-ips-bad
refused linux-ips-bad 'LR_PUBLIC_IPS must be IP addresses'

STUB_UNAME=Linux LR_PUBLIC_IP_DETECT=maybe run linux-detect-bad
refused linux-detect-bad 'LR_PUBLIC_IP_DETECT must be on or off'

# --- Linux: Docker is missing -------------------------------------------
DOCKER_Q="Docker is not installed. Install it now with Docker's official script (get.docker.com)? \[Y/n\]"
for answer in y ''; do
	STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_NO_DOCKER=1 STUB_TTY="$answer" run "linux-docker-yes-$answer"
	ok "linux-docker-yes-$answer"
	has "$WORK/linux-docker-yes-$answer/tty" "$DOCKER_Q"
	has "$WORK/linux-docker-yes-$answer/docker.log" 'get.docker.com ran'
done

STUB_UNAME=Linux STUB_NO_DOCKER=1 STUB_TTY=n run linux-docker-no
refused linux-docker-no 'Docker is not installed. Install Docker Engine >= 24'
has "$WORK/linux-docker-no/tty" "$DOCKER_Q"
lacks "$WORK/linux-docker-no/docker.log" 'get.docker.com'

STUB_UNAME=Linux STUB_NO_DOCKER=1 run linux-docker-no-tty
refused linux-docker-no-tty 'run this again with --install-docker'
lacks "$WORK/linux-docker-no-tty/docker.log" 'get.docker.com'

STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_NO_DOCKER=1 run linux-docker-flag --install-docker
ok linux-docker-flag
has "$WORK/linux-docker-flag/docker.log" 'get.docker.com ran'

STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_NO_DOCKER=1 STUB_TTY=n LR_INSTALL_DOCKER=1 run linux-docker-env
ok linux-docker-env
lacks "$WORK/linux-docker-env/tty" '\[Y/n\]'

STUB_UNAME=Linux STUB_NO_DOCKER=1 STUB_TTY=y run linux-docker-never --no-install-docker --install-docker
refused linux-docker-never 'Docker is not installed. Install Docker Engine >= 24'
lacks "$WORK/linux-docker-never/tty" '\[Y/n\]'

# Rootless never installs the system Docker, and does not ask.
STUB_UNAME=Linux STUB_NO_DOCKER=1 STUB_TTY=y run linux-docker-rootless --rootless --install-docker
refused linux-docker-rootless 'Set up rootless Docker first'
lacks "$WORK/linux-docker-rootless/tty" '\[Y/n\]'
lacks "$WORK/linux-docker-rootless/docker.log" 'get.docker.com'

# --- Linux: inotify limits ----------------------------------------------
CONF=/etc/sysctl.d/60-launchroom-inotify.conf
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y run linux-inotify-yes
ok linux-inotify-yes
has "$WORK/linux-inotify-yes/tty" "This server's inotify limits are low (max_user_watches 8192, max_user_instances 128). Raise them to max_user_watches 524288, max_user_instances 512 now? \[Y/n\]"
has "$WORK/linux-inotify-yes/root$CONF" '^fs.inotify.max_user_watches = 524288$'
has "$WORK/linux-inotify-yes/root$CONF" '^fs.inotify.max_user_instances = 512$'
has "$WORK/linux-inotify-yes/docker.log" '^sysctl -w fs.inotify.max_user_instances=512$'
has "$WORK/linux-inotify-yes.out" "the raised inotify limits stay after an uninstall; to undo them, delete $CONF and reboot"

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=n run linux-inotify-no
ok linux-inotify-no
[ ! -e "$WORK/linux-inotify-no/root$CONF" ] || fail "linux-inotify-no wrote $CONF"
has "$WORK/linux-inotify-no.out" 'Kept the inotify limits'
lacks "$WORK/linux-inotify-no.out" 'raised inotify limits stay'

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=8192 STUB_INSTANCES=128 run linux-inotify-no-tty
ok linux-inotify-no-tty
[ ! -e "$WORK/linux-inotify-no-tty/root$CONF" ] || fail "linux-inotify-no-tty wrote $CONF"
has "$WORK/linux-inotify-no-tty.out" 'run this again with --raise-inotify'

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=1048576 STUB_INSTANCES=128 run linux-inotify-flag --raise-inotify
ok linux-inotify-flag
has "$WORK/linux-inotify-flag/root$CONF" '^fs.inotify.max_user_instances = 512$'
lacks "$WORK/linux-inotify-flag/root$CONF" 'max_user_watches ='

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_UID=1000 run linux-inotify-user --raise-inotify
ok linux-inotify-user
[ ! -e "$WORK/linux-inotify-user/root$CONF" ] || fail "linux-inotify-user wrote $CONF"
has "$WORK/linux-inotify-user.out" "| sudo tee $CONF"

# A self-update (launchroomd runs this script in a helper container) never checks.
STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y run linux-inotify-update --raise-inotify
ok linux-inotify-update
[ ! -e "$WORK/linux-inotify-update/root$CONF" ] || fail "linux-inotify-update wrote $CONF"
lacks "$WORK/linux-inotify-update.out" 'inotify'

# A Mac never checks (the limits belong to the engine's VM).
STUB_VERIFIED='' STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y run mac-inotify
ok mac-inotify
lacks "$WORK/mac-inotify/tty" '\[Y/n\]'

# --- AppArmor profile for BuildKit (D58) ---------------------------------
AA=/etc/apparmor.d/launchroom-buildkit
AA_Q="This server restricts user namespaces (Ubuntu 23.10 and later), and builds need one. Allow it for launchroom's build container only (AppArmor profile $AA)? \[Y/n\]"
# The printed command, matched as a fixed string.
aa_cmd() { printf "    printf 'abi <abi/4.0>,\\\\ninclude <tunables/global>\\\\nprofile launchroom-buildkit flags=(unconfined) {\\\\n  userns,\\\\n}\\\\n' | %stee %s >/dev/null && %sapparmor_parser -r -W %s" "$1" "$AA" "$1" "$AA"; }
has_fixed() { grep -qF -- "$2" "$1" || fail "$1 lacks '$2': $(cat "$1")"; }

for answer in y ''; do
	STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY="$answer" run "aa-yes-$answer" 
	c="$WORK/aa-yes-$answer"
	ok "aa-yes-$answer"
	has "$c/tty" "$AA_Q"
	has "$c/root$AA" '^profile launchroom-buildkit flags=(unconfined) {$'
	has "$c/root$AA" '^  userns,$'
	has "$c/root$AA" '^abi <abi/4.0>,$'
	has "$c/root$AA" '^# Undo: apparmor_parser -R /etc/apparmor.d/launchroom-buildkit, then delete this file.$'
	has "$c/docker.log" "^apparmor_parser -r -W $c/root$AA\$"
	has "$WORK/aa-yes-$answer.out" "allowed user namespaces for launchroom's build container ($AA)"
	perms="$(stat -c %A "$c/root$AA")"
	[ "$perms" = "-rw-r--r--" ] || fail "aa-yes-$answer: $AA is $perms"
done

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY=n run aa-no 
ok aa-no
has "$WORK/aa-no/tty" "$AA_Q"
[ ! -e "$WORK/aa-no/root$AA" ] || fail "aa-no wrote $AA"
lacks "$WORK/aa-no/docker.log" 'apparmor_parser'
has "$WORK/aa-no.out" 'Builds on this server will fail until'
has_fixed "$WORK/aa-no.out" "$(aa_cmd '')"

# No terminal: the commands and the flag; nothing written.
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 run aa-no-tty 
ok aa-no-tty
[ ! -e "$WORK/aa-no-tty/root$AA" ] || fail "aa-no-tty wrote $AA"
has "$WORK/aa-no-tty.out" 'run this again with --allow-buildkit-userns'

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 run aa-flag --allow-buildkit-userns 
ok aa-flag
has "$WORK/aa-flag/root$AA" '^  userns,$'
has "$WORK/aa-flag/docker.log" 'apparmor_parser -r -W'

STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY=n LR_BUILDKIT_APPARMOR=1 run aa-env 
ok aa-env
lacks "$WORK/aa-env/tty" 'user namespaces'
has "$WORK/aa-env/root$AA" '^  userns,$'

# Not root: the sudo commands, nothing written, no prompt.
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY=y STUB_UID=1000 run aa-user --allow-buildkit-userns 
ok aa-user
[ ! -e "$WORK/aa-user/root$AA" ] || fail "aa-user wrote $AA"
lacks "$WORK/aa-user/docker.log" 'apparmor_parser'
lacks "$WORK/aa-user/tty" 'user namespaces'
has_fixed "$WORK/aa-user.out" "$(aa_cmd 'sudo ')"

# The load fails: a warning, the install goes on.
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_AA_FAIL=1 run aa-fail --allow-buildkit-userns 
ok aa-fail
has "$WORK/aa-fail.out" "apparmor_parser could not load $AA"

# Restriction off, missing (older kernels) or AppArmor off: nothing happens.
for v in 0 ''; do
	STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS="$v" STUB_TTY=y run "aa-open-$v" 
	ok "aa-open-$v"
	[ ! -e "$WORK/aa-open-$v/root$AA" ] || fail "aa-open-$v wrote $AA"
	lacks "$WORK/aa-open-$v.out" 'user namespaces'
done
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_APPARMOR=N STUB_TTY=y run aa-off 
ok aa-off
[ ! -e "$WORK/aa-off/root$AA" ] || fail "aa-off wrote $AA"

# Already allowed (a re-run): reloaded without asking.
mkdir -p "$WORK/aa-rerun/root/etc/apparmor.d"
cp "$WORK/aa-flag/root$AA" "$WORK/aa-rerun/root$AA"
STUB_VERIFIED='' STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY=n run aa-rerun 
ok aa-rerun
lacks "$WORK/aa-rerun/tty" 'user namespaces'
has "$WORK/aa-rerun/docker.log" 'apparmor_parser -r -W'
lacks "$WORK/aa-rerun.out" 'Builds on this server will fail'

# An update never touches it.
STUB_UNAME=Linux STUB_INFO='Ubuntu 24.04.2 LTS|vps-1' STUB_USERNS=1 STUB_TTY=y run aa-update --allow-buildkit-userns 
ok aa-update
[ ! -e "$WORK/aa-update/root$AA" ] || fail "aa-update wrote $AA"
lacks "$WORK/aa-update.out" 'user namespaces'
has "$WORK/aa-yes-y.out" "the AppArmor profile $AA stays after an uninstall"

# A Mac never checks (AppArmor belongs to the engine's VM).
STUB_VERIFIED='' STUB_USERNS=1 STUB_TTY=y run mac-aa
ok mac-aa
lacks "$WORK/mac-aa.out" 'user namespaces'

rm -rf "$WORK"
if [ "$FAILED" != 0 ]; then
	exit 1
fi
printf 'server.sh platform tests passed\n'
