#!/bin/sh
# Unit tests for install/install.sh's host preparation (docs/11 §2): the
# Docker prompt (get.docker.com), the inotify prompt, the update channel
# kept across re-runs and --no-public-ip-detect in .env. Runs in a throwaway busybox container (make
# test-install-sh): docker, curl, sysctl and id are stubs on PATH, the
# terminal is a file (LR_TEST_TTY) and /proc/sys and /etc/sysctl.d live under
# a fake root (LR_TEST_ROOT). Nothing is installed anywhere but the
# container's /tmp.
set -eu

SCRIPT="${1:-/mnt/install/install.sh}"
BUNDLE_DIR="${2:-/mnt}"
WORK="$(mktemp -d)"
STUBS="$WORK/bin"
mkdir -p "$STUBS" "$WORK/nodocker"
FAILED=0

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	FAILED=1
}

# --- stubs --------------------------------------------------------------
cat >"$STUBS/docker" <<'EOF'
#!/bin/sh
printf 'docker %s\n' "$*" >>"$STUB_LOG"
case "$*" in
"version --format {{.Server.Version}}") printf '28.5.1\n' ;;
"compose version") printf 'Docker Compose version v2.40.0\n' ;;
"ps -q"*) ;;
*"bootstrap-key --check"*) exit 1 ;;
"compose"*) ;;
*) printf 'unexpected: docker %s\n' "$*" >&2; exit 1 ;;
esac
EOF
# curl: only get.docker.com, whose "script" puts the docker stub on PATH.
cat >"$STUBS/curl" <<'EOF'
#!/bin/sh
for last; do :; done
case "$last" in
https://get.docker.com)
	while [ "$1" != -o ]; do shift; done
	printf '#!/bin/sh\nprintf "get.docker.com ran\\n" >>"$STUB_LOG"\ncp "$STUB_DOCKER" "$STUB_LATE/docker"\n' >"$2" ;;
*) printf 'unexpected: curl %s\n' "$*" >&2; exit 1 ;;
esac
EOF
cat >"$STUBS/sysctl" <<'EOF'
#!/bin/sh
printf 'sysctl %s\n' "$*" >>"$STUB_LOG"
[ "$1" = -w ] || exit 1
key="${2%%=*}"
mkdir -p "$LR_TEST_ROOT/proc/sys/$(dirname "$(printf %s "$key" | tr . /)")"
printf '%s\n' "${2#*=}" >"$LR_TEST_ROOT/proc/sys/$(printf %s "$key" | tr . /)"
EOF
cat >"$STUBS/id" <<'EOF'
#!/bin/sh
case "$1" in
-u) printf '%s\n' "${STUB_UID:-0}" ;;
*) printf 'me\n' ;;
esac
EOF
# apparmor_parser: records the call; STUB_AA_FAIL=1 makes the load fail.
cat >"$STUBS/apparmor_parser" <<'EOF'
#!/bin/sh
printf 'apparmor_parser %s\n' "$*" >>"$STUB_LOG"
[ "${STUB_AA_FAIL:-}" != 1 ] || { printf 'apparmor_parser: syntax error\n' >&2; exit 1; }
EOF
# Swap stubs: record the call; fallocate/dd create the file (STUB_FALLOCATE_FAIL=1
# makes fallocate fail), df reports STUB_DF_KB free (default 50 GB).
for tool in mkswap swapon; do
	# shellcheck disable=SC2016 # the stub expands $* and $STUB_LOG itself
	printf '#!/bin/sh\nprintf "%s %%s\\n" "$*" >>"$STUB_LOG"\n' "$tool" >"$STUBS/$tool"
done
# shellcheck disable=SC2016 # expanded by the stub
printf '[ "${STUB_SWAPON_FAIL:-}" != 1 ]\n' >>"$STUBS/swapon"
cat >"$STUBS/fallocate" <<'EOF'
#!/bin/sh
printf 'fallocate %s\n' "$*" >>"$STUB_LOG"
[ "${STUB_FALLOCATE_FAIL:-}" != 1 ] || exit 1
printf 'swap\n' >"$3"
EOF
cat >"$STUBS/dd" <<'EOF'
#!/bin/sh
printf 'dd %s\n' "$*" >>"$STUB_LOG"
for a; do case "$a" in of=*) printf 'swap\n' >"${a#of=}" ;; esac; done
EOF
cat >"$STUBS/df" <<'EOF'
#!/bin/sh
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/vda1 60000000 1000 %s 1%% /\n' "${STUB_DF_KB:-50000000}"
EOF
chmod +x "$STUBS"/*
for tool in curl sysctl id apparmor_parser mkswap swapon fallocate dd df; do cp "$STUBS/$tool" "$WORK/nodocker/"; done

# run NAME [args…]: runs install.sh in $WORK/NAME. Output in $WORK/NAME.out,
# docker/sysctl calls in $WORK/NAME/log, exit status in $STATUS.
#   STUB_TTY       set: the terminal answers this line (empty = Enter); unset: no terminal
#   STUB_WATCHES   fs.inotify.max_user_watches (default 524288)
#   STUB_INSTANCES fs.inotify.max_user_instances (default 512)
#   STUB_NO_DOCKER 1 = docker is missing until get.docker.com "installs" it
#   STUB_USERNS    kernel.apparmor_restrict_unprivileged_userns (unset: no such file)
#   STUB_APPARMOR  /sys/module/apparmor/parameters/enabled (default Y)
#   STUB_MEM_KB    MemTotal in /proc/meminfo (unset: no meminfo, the swap step skips)
#   STUB_SWAP_KB   SwapTotal (default 0)
#   STUB_SWAPS     a line for /proc/swaps after its header (default none)
# Without --bundle in the args, a missing bundle stops the run right after
# the host checks.
run() {
	name="$1"
	shift
	case="$WORK/$name"
	mkdir -p "$case/root/proc/sys/fs/inotify" "$case/late" "$case/root/etc"
	mkdir -p "$case/root/proc/sys/kernel" "$case/root/sys/module/apparmor/parameters"
	[ -z "${STUB_USERNS:-}" ] || printf '%s\n' "$STUB_USERNS" >"$case/root/proc/sys/kernel/apparmor_restrict_unprivileged_userns"
	printf '%s\n' "${STUB_APPARMOR:-Y}" >"$case/root/sys/module/apparmor/parameters/enabled"
	printf '%s\n' "${STUB_WATCHES:-524288}" >"$case/root/proc/sys/fs/inotify/max_user_watches"
	printf '%s\n' "${STUB_INSTANCES:-512}" >"$case/root/proc/sys/fs/inotify/max_user_instances"
	if [ -n "${STUB_MEM_KB:-}" ]; then
		printf 'MemTotal:       %s kB\nSwapTotal:      %s kB\n' "$STUB_MEM_KB" "${STUB_SWAP_KB:-0}" >"$case/root/proc/meminfo"
		printf 'Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n%s' "${STUB_SWAPS:+$STUB_SWAPS
}" >"$case/root/proc/swaps"
	fi
	tty="$case/no-tty"
	if [ -n "${STUB_TTY+set}" ]; then
		tty="$case/tty"
		printf '%s\n' "$STUB_TTY" >"$tty"
	fi
	path="$STUBS:/bin:/usr/bin:/sbin:/usr/sbin"
	[ "${STUB_NO_DOCKER:-}" != 1 ] || path="$case/late:$WORK/nodocker:/bin:/usr/bin:/sbin:/usr/sbin"
	STATUS=0
	(cd "$case" && PATH="$path" STUB_LOG="$case/log" STUB_DOCKER="$STUBS/docker" STUB_LATE="$case/late" \
		LR_TEST_TTY="$tty" LR_TEST_ROOT="$case/root" \
		sh "$SCRIPT" --dir "$case/lr" --domain test.example.com --no-public-ip-detect --skip-port-check "$@") \
		>"$WORK/$name.out" 2>&1 || STATUS=$?
	touch "$case/log"
}

has() { grep -q -- "$2" "$1" || fail "$1 lacks '$2': $(cat "$1")"; }
lacks() { ! grep -q -- "$2" "$1" || fail "$1 has '$2'"; }
# checked NAME: the host checks passed (the run stopped at the missing bundle).
checked() { has "$WORK/$1.out" '/missing is not a launchroom bundle'; }
refused() {
	[ "$STATUS" != 0 ] || fail "$1 should have been refused"
	has "$WORK/$1.out" "$2"
	lacks "$WORK/$1.out" 'not a launchroom bundle'
}
asked() { has "$WORK/$1/tty" "$2"; }
not_asked() { [ ! -f "$WORK/$1/tty" ] || lacks "$WORK/$1/tty" '\[Y/n\]'; }
CONF=/etc/sysctl.d/60-launchroom-inotify.conf
DOCKER_Q="Docker is not installed. Install it now with Docker's official script (get.docker.com)? \[Y/n\]"

# --- Docker is missing --------------------------------------------------
for answer in y Y yes ''; do
	STUB_NO_DOCKER=1 STUB_TTY="$answer" run "docker-yes-$answer" --bundle /missing
	checked "docker-yes-$answer"
	asked "docker-yes-$answer" "$DOCKER_Q"
	has "$WORK/docker-yes-$answer/log" 'get.docker.com ran'
	has "$WORK/docker-yes-$answer.out" 'Docker installed: 28.5.1'
done

for answer in n no nope; do
	STUB_NO_DOCKER=1 STUB_TTY="$answer" run "docker-no-$answer" --bundle /missing
	refused "docker-no-$answer" 'Docker is not installed. Install Docker Engine >= 24'
	asked "docker-no-$answer" "$DOCKER_Q"
	lacks "$WORK/docker-no-$answer/log" 'get.docker.com'
done

# No terminal (automation): stop, and name the flag.
STUB_NO_DOCKER=1 run docker-no-tty --bundle /missing
refused docker-no-tty 'run this again with --install-docker'
lacks "$WORK/docker-no-tty/log" 'get.docker.com'

STUB_NO_DOCKER=1 run docker-flag --install-docker --bundle /missing
checked docker-flag
has "$WORK/docker-flag/log" 'get.docker.com ran'

# LR_INSTALL_DOCKER=1 also skips the prompt on a terminal.
STUB_NO_DOCKER=1 STUB_TTY=n LR_INSTALL_DOCKER=1 run docker-env --bundle /missing
checked docker-env
not_asked docker-env
has "$WORK/docker-env/log" 'get.docker.com ran'

# --no-install-docker / LR_NO_INSTALL_DOCKER=1 never install, not even with --install-docker.
STUB_NO_DOCKER=1 STUB_TTY=y run docker-never --no-install-docker --bundle /missing
refused docker-never 'Docker is not installed. Install Docker Engine >= 24'
not_asked docker-never
lacks "$WORK/docker-never/log" 'get.docker.com'

STUB_NO_DOCKER=1 STUB_TTY=y LR_NO_INSTALL_DOCKER=1 run docker-never-env --install-docker --bundle /missing
refused docker-never-env 'Docker is not installed'
lacks "$WORK/docker-never-env/log" 'get.docker.com'

# Not root and no sudo: refused before asking.
STUB_NO_DOCKER=1 STUB_TTY=y STUB_UID=1000 run docker-no-sudo --bundle /missing
refused docker-no-sudo 'installing it needs root'
not_asked docker-no-sudo

# --- inotify limits -------------------------------------------------------
INOTIFY_Q="This server's inotify limits are low (max_user_watches 8192, max_user_instances 128). Raise them to max_user_watches 524288, max_user_instances 512 now? \[Y/n\]"

for answer in y ''; do
	STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY="$answer" run "inotify-yes-$answer" --bundle /missing
	c="$WORK/inotify-yes-$answer"
	checked "inotify-yes-$answer"
	asked "inotify-yes-$answer" "$INOTIFY_Q"
	has "$c/root$CONF" '^fs.inotify.max_user_watches = 524288$'
	has "$c/root$CONF" '^fs.inotify.max_user_instances = 512$'
	has "$c/root$CONF" '^# Undo: delete this file, then reboot or run: sysctl -w fs.inotify.max_user_watches=8192 fs.inotify.max_user_instances=128$'
	has "$c/log" '^sysctl -w fs.inotify.max_user_watches=524288$'
	has "$c/log" '^sysctl -w fs.inotify.max_user_instances=512$'
	has "$c/root/proc/sys/fs/inotify/max_user_instances" '^512$'
	has "$WORK/inotify-yes-$answer.out" "raised the inotify limits to max_user_watches 524288, max_user_instances 512 ($CONF)"
	perms="$(stat -c %A "$c/root$CONF")"
	[ "$perms" = "-rw-r--r--" ] || fail "inotify-yes-$answer: $CONF is $perms"
done

STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=n run inotify-no --bundle /missing
checked inotify-no
asked inotify-no "$INOTIFY_Q"
[ ! -e "$WORK/inotify-no/root$CONF" ] || fail "inotify-no wrote $CONF"
lacks "$WORK/inotify-no/log" 'sysctl'
has "$WORK/inotify-no.out" 'Kept the inotify limits'
has "$WORK/inotify-no.out" "printf '%s\\\\n' 'fs.inotify.max_user_watches = 524288' 'fs.inotify.max_user_instances = 512' | tee $CONF"
has "$WORK/inotify-no.out" '    sysctl -w fs.inotify.max_user_watches=524288 fs.inotify.max_user_instances=512$'

# No terminal: nothing changes; the warning names the commands and the flag.
STUB_WATCHES=8192 STUB_INSTANCES=128 run inotify-no-tty --bundle /missing
checked inotify-no-tty
[ ! -e "$WORK/inotify-no-tty/root$CONF" ] || fail "inotify-no-tty wrote $CONF"
lacks "$WORK/inotify-no-tty/log" 'sysctl'
has "$WORK/inotify-no-tty.out" 'run this again with --raise-inotify'
has "$WORK/inotify-no-tty.out" "| tee $CONF"

STUB_WATCHES=8192 STUB_INSTANCES=128 run inotify-flag --raise-inotify --bundle /missing
checked inotify-flag
has "$WORK/inotify-flag/root$CONF" '^fs.inotify.max_user_instances = 512$'
has "$WORK/inotify-flag/log" '^sysctl -w fs.inotify.max_user_instances=512$'

STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=n LR_RAISE_INOTIFY=1 run inotify-env --bundle /missing
checked inotify-env
not_asked inotify-env
has "$WORK/inotify-env/root$CONF" '^fs.inotify.max_user_watches = 524288$'

# Not root: the sudo commands, nothing written, no prompt.
STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y STUB_UID=1000 run inotify-user --raise-inotify --bundle /missing
checked inotify-user
not_asked inotify-user
[ ! -e "$WORK/inotify-user/root$CONF" ] || fail "inotify-user wrote $CONF"
has "$WORK/inotify-user.out" 'Raise them as root:'
has "$WORK/inotify-user.out" "| sudo tee $CONF"
has "$WORK/inotify-user.out" '    sudo sysctl -w fs.inotify.max_user_watches=524288 fs.inotify.max_user_instances=512$'

# A higher value is never lowered: only the low key is written.
STUB_WATCHES=1048576 STUB_INSTANCES=128 STUB_TTY=y run inotify-partial --bundle /missing
checked inotify-partial
asked inotify-partial "Raise them to max_user_instances 512 now?"
has "$WORK/inotify-partial/root$CONF" '^fs.inotify.max_user_instances = 512$'
lacks "$WORK/inotify-partial/root$CONF" 'max_user_watches ='
lacks "$WORK/inotify-partial/log" 'max_user_watches'
has "$WORK/inotify-partial/root/proc/sys/fs/inotify/max_user_watches" '^1048576$'

# Low watches, instances between the minimum and the raised value: both go up.
STUB_WATCHES=8192 STUB_INSTANCES=300 run inotify-mixed --raise-inotify --bundle /missing
checked inotify-mixed
has "$WORK/inotify-mixed/root$CONF" '^fs.inotify.max_user_watches = 524288$'
has "$WORK/inotify-mixed/root$CONF" '^fs.inotify.max_user_instances = 512$'

# At the minimum: no prompt, no change.
STUB_WATCHES=65536 STUB_INSTANCES=256 STUB_TTY=y run inotify-ok --bundle /missing
checked inotify-ok
not_asked inotify-ok
[ ! -e "$WORK/inotify-ok/root$CONF" ] || fail "inotify-ok wrote $CONF"
lacks "$WORK/inotify-ok.out" 'inotify'

# An update (launchroomd's supervisor runs the installer in a container) never checks.
STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y LR_BUNDLE_VERIFIED=1 run inotify-update --raise-inotify --bundle /missing
checked inotify-update
[ ! -e "$WORK/inotify-update/root$CONF" ] || fail "inotify-update wrote $CONF"
lacks "$WORK/inotify-update.out" 'inotify'

# Docker missing and low limits on one terminal: both prompts.
STUB_NO_DOCKER=1 STUB_WATCHES=8192 STUB_INSTANCES=128 STUB_TTY=y run both --bundle /missing
checked both
asked both "$DOCKER_Q"
asked both "$INOTIFY_Q"
has "$WORK/both/log" 'get.docker.com ran'
has "$WORK/both/root$CONF" '^fs.inotify.max_user_instances = 512$'

# --- AppArmor profile for BuildKit (D58) ---------------------------------
AA=/etc/apparmor.d/launchroom-buildkit
AA_Q="This server restricts user namespaces (Ubuntu 23.10 and later), and builds need one. Allow it for launchroom's build container only (AppArmor profile $AA)? \[Y/n\]"
# The printed command, matched as a fixed string.
aa_cmd() { printf "    printf 'abi <abi/4.0>,\\\\ninclude <tunables/global>\\\\nprofile launchroom-buildkit flags=(unconfined) {\\\\n  userns,\\\\n}\\\\n' | %stee %s >/dev/null && %sapparmor_parser -r -W %s" "$1" "$AA" "$1" "$AA"; }
has_fixed() { grep -qF -- "$2" "$1" || fail "$1 lacks '$2': $(cat "$1")"; }

for answer in y ''; do
	 STUB_USERNS=1 STUB_TTY="$answer" run "aa-yes-$answer" --bundle /missing
	c="$WORK/aa-yes-$answer"
	checked "aa-yes-$answer"
	has "$c/tty" "$AA_Q"
	has "$c/root$AA" '^profile launchroom-buildkit flags=(unconfined) {$'
	has "$c/root$AA" '^  userns,$'
	has "$c/root$AA" '^abi <abi/4.0>,$'
	has "$c/root$AA" '^# Undo: apparmor_parser -R /etc/apparmor.d/launchroom-buildkit, then delete this file.$'
	has "$c/log" "^apparmor_parser -r -W $c/root$AA\$"
	has "$WORK/aa-yes-$answer.out" "allowed user namespaces for launchroom's build container ($AA)"
	perms="$(stat -c %A "$c/root$AA")"
	[ "$perms" = "-rw-r--r--" ] || fail "aa-yes-$answer: $AA is $perms"
done

 STUB_USERNS=1 STUB_TTY=n run aa-no --bundle /missing
checked aa-no
has "$WORK/aa-no/tty" "$AA_Q"
[ ! -e "$WORK/aa-no/root$AA" ] || fail "aa-no wrote $AA"
lacks "$WORK/aa-no/log" 'apparmor_parser'
has "$WORK/aa-no.out" 'Builds on this server will fail until'
has_fixed "$WORK/aa-no.out" "$(aa_cmd '')"

# No terminal: the commands and the flag; nothing written.
 STUB_USERNS=1 run aa-no-tty --bundle /missing
checked aa-no-tty
[ ! -e "$WORK/aa-no-tty/root$AA" ] || fail "aa-no-tty wrote $AA"
has "$WORK/aa-no-tty.out" 'run this again with --allow-buildkit-userns'

 STUB_USERNS=1 run aa-flag --allow-buildkit-userns --bundle /missing
checked aa-flag
has "$WORK/aa-flag/root$AA" '^  userns,$'
has "$WORK/aa-flag/log" 'apparmor_parser -r -W'

 STUB_USERNS=1 STUB_TTY=n LR_BUILDKIT_APPARMOR=1 run aa-env --bundle /missing
checked aa-env
lacks "$WORK/aa-env/tty" 'user namespaces'
has "$WORK/aa-env/root$AA" '^  userns,$'

# Not root: the sudo commands, nothing written, no prompt.
 STUB_USERNS=1 STUB_TTY=y STUB_UID=1000 run aa-user --allow-buildkit-userns --bundle /missing
checked aa-user
[ ! -e "$WORK/aa-user/root$AA" ] || fail "aa-user wrote $AA"
lacks "$WORK/aa-user/log" 'apparmor_parser'
lacks "$WORK/aa-user/tty" 'user namespaces'
has_fixed "$WORK/aa-user.out" "$(aa_cmd 'sudo ')"

# The load fails: a warning, the install goes on.
 STUB_USERNS=1 STUB_AA_FAIL=1 run aa-fail --allow-buildkit-userns --bundle /missing
checked aa-fail
has "$WORK/aa-fail.out" "apparmor_parser could not load $AA"

# Restriction off, missing (older kernels) or AppArmor off: nothing happens.
for v in 0 ''; do
	 STUB_USERNS="$v" STUB_TTY=y run "aa-open-$v" --bundle /missing
	checked "aa-open-$v"
	[ ! -e "$WORK/aa-open-$v/root$AA" ] || fail "aa-open-$v wrote $AA"
	lacks "$WORK/aa-open-$v.out" 'user namespaces'
done
 STUB_USERNS=1 STUB_APPARMOR=N STUB_TTY=y run aa-off --bundle /missing
checked aa-off
[ ! -e "$WORK/aa-off/root$AA" ] || fail "aa-off wrote $AA"

# Already allowed (a re-run): reloaded without asking.
mkdir -p "$WORK/aa-rerun/root/etc/apparmor.d"
cp "$WORK/aa-flag/root$AA" "$WORK/aa-rerun/root$AA"
 STUB_USERNS=1 STUB_TTY=n run aa-rerun --bundle /missing
checked aa-rerun
lacks "$WORK/aa-rerun/tty" 'user namespaces'
has "$WORK/aa-rerun/log" 'apparmor_parser -r -W'
lacks "$WORK/aa-rerun.out" 'Builds on this server will fail'

# An update never touches it.
LR_BUNDLE_VERIFIED=1 STUB_USERNS=1 STUB_TTY=y run aa-update --allow-buildkit-userns --bundle /missing
checked aa-update
[ ! -e "$WORK/aa-update/root$AA" ] || fail "aa-update wrote $AA"
lacks "$WORK/aa-update.out" 'user namespaces'

# --- Swap on small hosts (D59) -----------------------------------------------
SW=/swapfile-launchroom
SW_CONF=/etc/sysctl.d/60-launchroom-swap.conf
SW_Q="This server has 1.9 GB of memory and no swap, so builds can run out of memory. Create a 2 GB swap file ($SW)? \[Y/n\]"
SMALL=2014000
# swap_made NAME: the file, its fstab line (once), swappiness and the calls.
swap_made() {
	c="$WORK/$1"
	[ -f "$c/root$SW" ] || fail "$1: no $SW"
	perms="$(stat -c %A "$c/root$SW")"
	[ "$perms" = "-rw-------" ] || fail "$1: $SW is $perms"
	[ "$(grep -c "^$SW none swap sw 0 0\$" "$c/root/etc/fstab")" = 1 ] || fail "$1: fstab line missing or twice: $(cat "$c/root/etc/fstab")"
	has "$c/root$SW_CONF" '^vm.swappiness = 10$'
	has "$c/log" "^swapon $c/root$SW\$"
	has "$c/log" '^sysctl -w vm.swappiness=10$'
	has "$WORK/$1.out" "created a 2 GB swap file ($SW, vm.swappiness=10)"
}
no_swap_made() {
	[ ! -e "$WORK/$1/root$SW" ] || fail "$1 created $SW"
	lacks "$WORK/$1/log" 'swapon'
}

for answer in y ''; do
	STUB_MEM_KB=$SMALL STUB_TTY="$answer" run "swap-yes-$answer" --bundle /missing
	checked "swap-yes-$answer"
	asked "swap-yes-$answer" "$SW_Q"
	has "$WORK/swap-yes-$answer/log" "^fallocate -l 2048M $WORK/swap-yes-$answer/root$SW\$"
	has "$WORK/swap-yes-$answer/log" "^mkswap $WORK/swap-yes-$answer/root$SW\$"
	swap_made "swap-yes-$answer"
done

STUB_MEM_KB=$SMALL run swap-flag --create-swap --bundle /missing
checked swap-flag
swap_made swap-flag
# A re-run reuses the file: no new file, one fstab line.
mv "$WORK/swap-flag.out" "$WORK/swap-flag-first.out"
: >"$WORK/swap-flag/log"
STUB_MEM_KB=$SMALL run swap-flag --create-swap --bundle /missing
checked swap-flag
lacks "$WORK/swap-flag/log" 'fallocate'
swap_made swap-flag

mkdir -p "$WORK/swap-existing/root"
printf 'keep this file\n' >"$WORK/swap-existing/root$SW"
STUB_MEM_KB=$SMALL STUB_SWAPON_FAIL=1 run swap-existing --create-swap --bundle /missing
checked swap-existing
has "$WORK/swap-existing/root$SW" '^keep this file$'
lacks "$WORK/swap-existing/log" 'mkswap'
has "$WORK/swap-existing.out" 'an existing file is never reformatted'

mkdir -p "$WORK/swap-link/root"
printf 'keep this target\n' >"$WORK/swap-link/target"
ln -s "$WORK/swap-link/target" "$WORK/swap-link/root$SW"
STUB_MEM_KB=$SMALL run swap-link --create-swap --bundle /missing
checked swap-link
lacks "$WORK/swap-link/log" 'swapon'
has "$WORK/swap-link/target" '^keep this target$'
has "$WORK/swap-link.out" 'is not a regular file; skipped'

STUB_MEM_KB=$SMALL STUB_TTY=n LR_CREATE_SWAP=1 run swap-env --bundle /missing
checked swap-env
not_asked swap-env
swap_made swap-env

# fallocate unsupported (some filesystems): dd writes the file instead.
STUB_MEM_KB=$SMALL STUB_FALLOCATE_FAIL=1 run swap-dd --create-swap --bundle /missing
checked swap-dd
has "$WORK/swap-dd/log" "^dd if=/dev/zero of=$WORK/swap-dd/root$SW bs=1M count=2048\$"
swap_made swap-dd

STUB_MEM_KB=$SMALL STUB_TTY=n run swap-no --bundle /missing
checked swap-no
asked swap-no "$SW_Q"
no_swap_made swap-no
has "$WORK/swap-no.out" 'Kept the server without swap'
has "$WORK/swap-no.out" "^    fallocate -l 2G $SW\$"
has "$WORK/swap-no.out" "^    echo '$SW none swap sw 0 0' | tee -a /etc/fstab\$"
has "$WORK/swap-no.out" '^    sysctl -w vm.swappiness=10$'

STUB_MEM_KB=$SMALL run swap-no-tty --bundle /missing
checked swap-no-tty
no_swap_made swap-no-tty
has "$WORK/swap-no-tty.out" 'run this again with --create-swap'
has "$WORK/swap-no-tty.out" "^    mkswap $SW\$"

STUB_MEM_KB=$SMALL STUB_TTY=y STUB_UID=1000 run swap-user --create-swap --bundle /missing
checked swap-user
not_asked swap-user
no_swap_made swap-user
has "$WORK/swap-user.out" 'Add a 2 GB swap file as root:'
has "$WORK/swap-user.out" "^    sudo fallocate -l 2G $SW\$"
has "$WORK/swap-user.out" "^    sudo swapon $SW\$"
has "$WORK/swap-user.out" "^    echo 'vm.swappiness = 10' | sudo tee $SW_CONF\$"

# Not enough disk: a warning, nothing created.
STUB_MEM_KB=$SMALL STUB_DF_KB=2000000 run swap-disk --create-swap --bundle /missing
checked swap-disk
no_swap_made swap-disk
has "$WORK/swap-disk.out" 'not enough free disk space for a 2 GB swap file (1953 MB free, 3072 MB needed); skipped.'

# Enough memory, or swap already on: nothing happens.
STUB_MEM_KB=8000000 STUB_TTY=y run swap-big --bundle /missing
checked swap-big
not_asked swap-big
no_swap_made swap-big
STUB_MEM_KB=$SMALL STUB_SWAP_KB=1048572 STUB_TTY=y run swap-has --bundle /missing
checked swap-has
not_asked swap-has
no_swap_made swap-has
STUB_MEM_KB=$SMALL STUB_SWAPS='/dev/zram0 partition 1048572 0 100' STUB_TTY=y run swap-zram --bundle /missing
checked swap-zram
not_asked swap-zram
no_swap_made swap-zram

# An update never touches it.
LR_BUNDLE_VERIFIED=1 STUB_MEM_KB=$SMALL STUB_TTY=y run swap-update --create-swap --bundle /missing
checked swap-update
no_swap_made swap-update
lacks "$WORK/swap-update.out" 'swap'

# --- update channel -------------------------------------------------------
# The run stops at the local launchroomd's enrollment (the stub dashboard
# returns no key), after .env is written.
run channel-beta --unstable --bundle "$BUNDLE_DIR"
has "$WORK/channel-beta/lr/.env" '^LR_UPDATES_CHANNEL=beta$'
has "$WORK/channel-beta.out" 'did not return its signing key'
mv "$WORK/channel-beta.out" "$WORK/channel-beta-first.out"
run channel-beta --bundle "$BUNDLE_DIR"
has "$WORK/channel-beta/lr/.env" '^LR_UPDATES_CHANNEL=beta$'
[ "$(grep -c '^LR_UPDATES_CHANNEL=' "$WORK/channel-beta/lr/.env")" = 1 ] || fail "LR_UPDATES_CHANNEL twice in .env"

run channel-stable --bundle "$BUNDLE_DIR"
lacks "$WORK/channel-stable/lr/.env" 'LR_UPDATES_CHANNEL'
has "$WORK/channel-stable/lr/compose.yml" 'LAUNCHROOM_UPDATES_CHANNEL: ..LR_UPDATES_CHANNEL[:}]'

# --- public IP detection -------------------------------------------------
# Every run passes --no-public-ip-detect: launchroomd follows it (.env)…
has "$WORK/channel-stable/lr/.env" '^LR_PUBLIC_IP_DETECT=off$'
# …except in an update, where launchroomd's supervisor passes the flag.
LR_BUNDLE_VERIFIED=1 run update-ip --bundle "$BUNDLE_DIR"
lacks "$WORK/update-ip/lr/.env" 'LR_PUBLIC_IP_DETECT'

rm -rf "$WORK"
if [ "$FAILED" != 0 ]; then
	exit 1
fi
printf 'install.sh host preparation tests passed\n'
