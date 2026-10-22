#!/bin/sh
# launchroomd installer: joins this server to an existing control plane
# (docs/11 §3, docs/03 §3).
#
#   curl -fsSL https://dash.example.com/install/server.sh | LR_JOIN_TOKEN=lr_jt_… sh
#   … | LR_JOIN_TOKEN=lr_jt_… sh -s -- --rootless     (rootless Docker, docs/11 §3.2)
#
# On a Mac (docs/11 §3.3, D53) the same command installs into ~/launchroom
# and runs launchroomd inside the Linux VM of OrbStack, Docker Desktop or
# Colima. A Mac builds (and, with OrbStack or Colima, runs apps); it never
# serves public traffic.
#
# --rootless   this server runs rootless Docker (as your user). launchroomd
#              then runs in RootlessKit's network namespace: dockerd-guard
#              exposes 80/443 through RootlessKit's port API (needs
#              net.ipv4.ip_unprivileged_port_start <= 80 on the host), and
#              the capability matrix on the server page shows what is
#              degraded (client addresses, firewall scope, mesh).
#
# The dashboard serves this file with its URL, its signing key and the
# launchroomd image filled in (and the launchroomd compose file embedded). Environment variables
# override those defaults:
#
#   LR_INSTALL_DOCKER      1 = when Docker is missing (Linux), install it with
#                          Docker's official script (get.docker.com) without
#                          asking; also --install-docker. Without it the script
#                          asks, and stops when there is no terminal to ask on.
#   LR_NO_INSTALL_DOCKER   1 = never install Docker, stop with instructions;
#                          also --no-install-docker
#   LR_RAISE_INOTIFY       1 = raise low inotify limits (Linux) without asking;
#                          also --raise-inotify
#   LR_BUILDKIT_APPARMOR   1 = on hosts that restrict user namespaces (Ubuntu
#                          23.10+), load launchroom's AppArmor profile for its
#                          build container without asking; also
#                          --allow-buildkit-userns
#   LR_CREATE_SWAP         1 = with less than 4 GB of memory and no swap (Linux),
#                          create a 2 GB swap file without asking; also
#                          --create-swap
#   LR_JOIN_TOKEN          single-use join token from "Add server" (required;
#                          from the environment or typed at the prompt, never
#                          as an argument, so it does not show up in `ps`)
#   LR_CONTROL_PLANE_URL   dashboard URL
#   LR_CP_KEY              pinned control-plane signing key (ed25519:<base64>)
#   LR_DAEMON_IMAGE        launchroomd image (digest-pinned)
#   LAUNCHROOM_DAEMON_DIR  install folder (default ./launchroomd, on macOS
#                          ~/launchroom; an existing ./launchroom-agent from
#                          before D27 is moved there)
#   LR_RELEASE_VERSION     release whose signed bundle is verified and used
#                          (default: the dashboard's version)
#   LR_BUNDLE_DIR          use an unpacked release bundle (<bundle>/daemon/compose.yml)
#                          instead of downloading one; NOT signature-verified
#                          (launchroomd's self-update passes the bundle it verified)
#   LR_ALLOW_UNSIGNED=1    development builds without a signed release: use the
#                          compose file embedded by the dashboard, unverified
#   LAUNCHROOM_RELEASE_BASE  release download base (mirrors), default GitHub
#   LR_PUBLIC_IP_DETECT    off = launchroomd never asks IP echo services for this
#                          server's public IP (recorded in .env)
#   LR_PUBLIC_IPS          this server's public IPs, comma-separated (recorded
#                          in .env; for DNS instructions without detection)
#
# Nothing is written outside the install folder (on macOS too: no launchd
# jobs; containers restart with the engine, which starts at login), with these
# exceptions on Linux that the script asks about first (or a flag allows):
# Docker itself when it is missing,
# /etc/sysctl.d/60-launchroom-inotify.conf when the kernel's inotify limits
# are below what launchroom needs (max_user_watches >= 65536,
# max_user_instances >= 256; raised to 524288 and 512, never lowered; undo:
# delete the file and reboot), and /etc/apparmor.d/launchroom-buildkit when
# AppArmor restricts unprivileged user namespaces (Ubuntu 23.10+): it lets
# only launchroom's build container create the user namespace BuildKit
# needs (undo: apparmor_parser -R the file, then delete it), and
# /swapfile-launchroom (2 GB, in /etc/fstab, plus vm.swappiness = 10 in
# /etc/sysctl.d/60-launchroom-swap.conf) when the server has less than 4 GB
# of memory and no swap (undo: swapoff and delete the file, its fstab line
# and the sysctl file). Docker objects carry the label
# launchroom.managed=true. Re-running it with a new token re-enrolls only
# when launchroomd has not enrolled yet.
set -eu

LR_CONTROL_PLANE_URL="${LR_CONTROL_PLANE_URL:-__LAUNCHROOM_URL__}"
LR_CP_KEY="${LR_CP_KEY:-__LAUNCHROOM_CP_KEY__}"
LR_DAEMON_IMAGE="${LR_DAEMON_IMAGE:-__LAUNCHROOM_DAEMON_IMAGE__}"
LR_RELEASE_VERSION="${LR_RELEASE_VERSION:-__LAUNCHROOM_VERSION__}"
OS="$(uname -s)"
if [ -n "${LAUNCHROOM_DAEMON_DIR:-}" ]; then
	DIR="$LAUNCHROOM_DAEMON_DIR"
elif [ "$OS" = Darwin ]; then
	# Under the home folder: every macOS engine shares it with its VM by
	# default (Colima shares nothing else), and it needs no sudo.
	DIR="${HOME:?HOME is not set}/launchroom"
else
	DIR="./launchroomd"
fi
ROOTLESS=0
# Docker when it is missing: ask (default), or 1 = install without asking.
# NO_INSTALL_DOCKER=1 never installs and wins over both.
INSTALL_DOCKER="${LR_INSTALL_DOCKER:-0}"
NO_INSTALL_DOCKER="${LR_NO_INSTALL_DOCKER:-0}"
RAISE_INOTIFY="${LR_RAISE_INOTIFY:-0}"
ALLOW_BUILDKIT_USERNS="${LR_BUILDKIT_APPARMOR:-0}"
CREATE_SWAP="${LR_CREATE_SWAP:-0}"
# Test seams (deploy/test/server-sh-test.sh): the terminal prompts are read
# from, and a folder standing in for / (for /proc/sys, /proc/meminfo,
# /sys/module, /etc/sysctl.d, /etc/apparmor.d, /etc/fstab and the swap file).
TTY="${LR_TEST_TTY:-/dev/tty}"
HOST_ROOT="${LR_TEST_ROOT:-}"
# macOS engine (docker-desktop, orbstack, colima), set by check_macos.
MAC_ENGINE=""
RELEASE_BASE="${LAUNCHROOM_RELEASE_BASE:-https://github.com/vantezzen/launchroom/releases}"
# Same verification as install.sh (docs/09 R30, docs/11 §2): cosign v3.0.2
# (digest-pinned container) and the exact release identity for the version.
COSIGN_IMAGE="ghcr.io/sigstore/cosign/cosign:v3.0.2@sha256:b29487e48205d875c324c79583e2806d9d269c0fa299e0861bbec023d8430c8b"
SIGNER_IDENTITY="https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
SIGNER_ISSUER="https://token.actions.githubusercontent.com"

# A terminal we can prompt on (also when the script itself comes from a pipe).
has_tty() { (: <"$TTY") 2>/dev/null; }
say() { printf 'launchroom: %s\n' "$*"; }
warn() { printf 'launchroom: warning: %s\n' "$*" >&2; }
die() { printf 'launchroom: error: %s\n' "$*" >&2; exit 1; }

# Placeholders survive only when the script is run from a bundle, not
# downloaded from a dashboard.
unfilled() { case "$1" in *__LAUNCHROOM_*) return 0 ;; *) return 1 ;; esac; }

MAC_ENGINES="OrbStack (orbstack.dev), Docker Desktop (docker.com/products/docker-desktop) or Colima (brew install colima docker docker-compose, then colima start)"

# ask QUESTION: asks on the terminal; Enter, y or yes is yes. No answer
# (end of input) is no.
ask() {
	printf 'launchroom: %s [Y/n] ' "$1" >>"$TTY"
	IFS= read -r answer <"$TTY" || answer=n
	case "$answer" in
	"" | [Yy] | [Yy][Ee][Ss]) return 0 ;;
	*) return 1 ;;
	esac
}

# install_docker: Docker Engine with the compose plugin through Docker's
# official convenience script (https://get.docker.com), when Docker is
# missing on Linux. The script picks the distribution's packages, uses sudo
# itself when we are not root, and installs docker-compose-plugin. Asks
# first; without a terminal only with --install-docker (LR_INSTALL_DOCKER=1).
# --no-install-docker (LR_NO_INSTALL_DOCKER=1) never installs.
install_docker() {
	manual="Install Docker Engine >= 24 with the compose plugin (https://docs.docker.com/engine/install/), then run this again."
	[ "$NO_INSTALL_DOCKER" != 1 ] || die "Docker is not installed. $manual"
	if [ "$(id -u)" != 0 ] && ! command -v sudo >/dev/null 2>&1; then
		die "Docker is not installed, and installing it needs root. Run this as root, or install Docker first (https://docs.docker.com/engine/install/)."
	fi
	if [ "$INSTALL_DOCKER" != 1 ]; then
		has_tty || die "Docker is not installed. $manual To install it with Docker's official script (get.docker.com) instead, run this again with --install-docker."
		ask "Docker is not installed. Install it now with Docker's official script (get.docker.com)?" ||
			die "Docker is not installed. $manual"
	fi
	say "installing Docker Engine with Docker's official script (https://get.docker.com)"
	script="$(mktemp)"
	download https://get.docker.com "$script" || { rm -f "$script"; die "could not download https://get.docker.com"; }
	# The usual umask: the installer runs with 077, and apt's sandbox user must
	# read the keyring and sources file the script writes. stdin is this
	# script when it comes from curl | sh.
	(umask 022 && sh "$script" </dev/null) || { rm -f "$script"; die "Docker's install script failed (see its output above)."; }
	rm -f "$script"
	if command -v systemctl >/dev/null 2>&1; then
		if [ "$(id -u)" = 0 ]; then systemctl enable --now docker >/dev/null 2>&1 || true; else sudo systemctl enable --now docker >/dev/null 2>&1 || true; fi
	fi
	command -v docker >/dev/null 2>&1 || die "Docker's install script finished, but docker is still not on PATH."
	if [ "$(id -u)" != 0 ] && ! docker version >/dev/null 2>&1; then
		die "Docker is installed. Your user can't use it yet: run 'sudo usermod -aG docker $(id -un)', log out and back in, then run this again."
	fi
	say "Docker installed: $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo 'version unknown')"
}

# inotify limits (docs/11 §2.1a). The dashboard's server health warns below
# 65536 watches or 256 instances (config launchroom.health.inotify_min_*);
# containers then fail with "too many open files". Stock Ubuntu has 128
# instances. Raised values: 524288 watches (the common value for container
# and file-watcher hosts; kernel memory is only used by watches in use) and
# 512 instances (twice the minimum). A value at or above these is kept.
INOTIFY_FILE=/etc/sysctl.d/60-launchroom-inotify.conf
INOTIFY_MIN_WATCHES=65536
INOTIFY_MIN_INSTANCES=256
INOTIFY_WATCHES=524288
INOTIFY_INSTANCES=512

# check_inotify: when the limits are low, raises them after asking (or with
# --raise-inotify), else prints the commands. Writes INOTIFY_FILE outside
# the install folder. Never during an update (LR_BUNDLE_VERIFIED=1: the
# installer runs in a helper container then, and the host was checked at install).
check_inotify() {
	[ "${LR_BUNDLE_VERIFIED:-}" != 1 ] || return 0
	proc="$HOST_ROOT/proc/sys/fs/inotify"
	watches="$(cat "$proc/max_user_watches" 2>/dev/null || true)"
	instances="$(cat "$proc/max_user_instances" 2>/dev/null || true)"
	case "$watches" in "" | *[!0-9]*) return 0 ;; esac
	case "$instances" in "" | *[!0-9]*) return 0 ;; esac
	if [ "$watches" -ge "$INOTIFY_MIN_WATCHES" ] && [ "$instances" -ge "$INOTIFY_MIN_INSTANCES" ]; then
		return 0
	fi

	# Only the keys below the raised value; a higher value stays as it is.
	conf="" assign="" want="" undo=""
	[ "$watches" -ge "$INOTIFY_WATCHES" ] || inotify_add max_user_watches "$INOTIFY_WATCHES" "$watches"
	[ "$instances" -ge "$INOTIFY_INSTANCES" ] || inotify_add max_user_instances "$INOTIFY_INSTANCES" "$instances"
	now="max_user_watches $watches, max_user_instances $instances"

	if [ "$(id -u)" != 0 ]; then
		inotify_manual "sudo " "This server's inotify limits are low ($now). Raise them as root:"
		return 0
	fi
	if [ "$RAISE_INOTIFY" != 1 ]; then
		if ! has_tty; then
			inotify_manual "" "This server's inotify limits are low ($now). Raise them with these commands, or run this again with --raise-inotify:"
			return 0
		fi
		if ! ask "This server's inotify limits are low ($now). Raise them to $want now?"; then
			inotify_manual "" "Kept the inotify limits ($now). Containers may fail with \"too many open files\". To raise them later:"
			return 0
		fi
	fi

	file="$HOST_ROOT$INOTIFY_FILE"
	(umask 022 && mkdir -p "${file%/*}" && {
		printf '# Written by the launchroom installer. Before: %s.\n' "$now"
		printf '# Undo: delete this file, then reboot or run: sysctl -w %s\n' "$undo"
		printf '%s\n' "$conf"
	} >"$file") || { warn "could not write $INOTIFY_FILE"; inotify_manual "" "Raise the inotify limits by hand:"; return 0; }
	for kv in $assign; do
		if command -v sysctl >/dev/null 2>&1; then
			sysctl -w "$kv" >/dev/null || warn "sysctl -w $kv failed; it applies after a reboot"
		else
			key="${kv%%=*}"
			printf '%s\n' "${kv#*=}" >"$HOST_ROOT/proc/sys/fs/inotify/${key#fs.inotify.}" || warn "could not set $key; it applies after a reboot"
		fi
	done
	say "raised the inotify limits to $want ($INOTIFY_FILE)"
}

# inotify_add KEY VALUE BEFORE: one line of the sysctl file (conf), its
# sysctl -w argument (assign), its words for the prompt (want) and the
# argument that sets it back (undo).
inotify_add() {
	conf="${conf:+$conf
}fs.inotify.$1 = $2"
	assign="${assign:+$assign }fs.inotify.$1=$2"
	want="${want:+$want, }$1 $2"
	undo="${undo:+$undo }fs.inotify.$1=$3"
}

# inotify_manual SUDO MESSAGE: the warning and the exact commands.
inotify_manual() {
	warn "$2"
	lines="$(printf '%s\n' "$conf" | sed "s/.*/'&'/" | tr '\n' ' ')"
	printf '    printf %s %s| %stee %s\n' "'%s\n'" "$lines" "$1" "$INOTIFY_FILE" >&2
	printf '    %ssysctl -w %s\n' "$1" "$assign" >&2
}

# User namespaces for BuildKit (docs/11 §2.1b, D58). Ubuntu 23.10 and later
# set kernel.apparmor_restrict_unprivileged_userns=1: rootless BuildKit can
# then not create its user namespace and builds fail. Instead of turning the
# restriction off for the whole machine, the installer loads one AppArmor
# profile, launchroom-buildkit: unconfined plus `userns,` (how Ubuntu's own
# per-application profiles grant it), attached to no program by path, so
# only a container started with apparmor=launchroom-buildkit uses it.
# launchroomd starts its build container (lr-system-buildkit) with it when
# the restriction is on, and dockerd-guard allows it for that container only.
APPARMOR_PROFILE=/etc/apparmor.d/launchroom-buildkit
APPARMOR_RULES='abi <abi/4.0>,
include <tunables/global>
profile launchroom-buildkit flags=(unconfined) {
  userns,
}'

# check_buildkit_apparmor: when AppArmor restricts user namespaces, writes
# and loads the profile after asking (or with --allow-buildkit-userns),
# else prints the commands. Never during an update (LR_BUNDLE_VERIFIED=1).
check_buildkit_apparmor() {
	[ "${LR_BUNDLE_VERIFIED:-}" != 1 ] || return 0
	[ "$(cat "$HOST_ROOT/proc/sys/kernel/apparmor_restrict_unprivileged_userns" 2>/dev/null || true)" = 1 ] || return 0
	[ "$(cat "$HOST_ROOT/sys/module/apparmor/parameters/enabled" 2>/dev/null || true)" = Y ] || return 0
	file="$HOST_ROOT$APPARMOR_PROFILE"
	if [ -f "$file" ] && [ "$(grep -v '^#' "$file")" = "$APPARMOR_RULES" ]; then
		# Already allowed: make sure it is loaded (a no-op when it is).
		[ "$(id -u)" != 0 ] || apparmor_parser -r -W "$file" >/dev/null 2>&1 ||
			warn "could not load $APPARMOR_PROFILE; run: apparmor_parser -r -W $APPARMOR_PROFILE"
		return 0
	fi
	why="This server restricts user namespaces (Ubuntu 23.10 and later), and builds need one"
	if [ "$(id -u)" != 0 ]; then
		apparmor_manual "sudo " "$why. Allow it for launchroom's build container only, as root:"
		return 0
	fi
	if [ "$ALLOW_BUILDKIT_USERNS" != 1 ]; then
		if ! has_tty; then
			apparmor_manual "" "$why. Allow it for launchroom's build container only with these commands, or run this again with --allow-buildkit-userns:"
			return 0
		fi
		if ! ask "$why. Allow it for launchroom's build container only (AppArmor profile $APPARMOR_PROFILE)?"; then
			apparmor_manual "" "Builds on this server will fail until launchroom's build container may create a user namespace. To allow it later:"
			return 0
		fi
	fi
	command -v apparmor_parser >/dev/null 2>&1 || { apparmor_manual "" "apparmor_parser is missing (install the apparmor package), then run:"; return 0; }
	(umask 022 && mkdir -p "${file%/*}" && {
		printf '# Written by the launchroom installer: lets launchroom'"'"'s build container\n'
		printf '# (lr-system-buildkit, started with apparmor=launchroom-buildkit) create\n'
		printf '# the user namespace rootless BuildKit needs. Attached to no program.\n'
		printf '# Undo: apparmor_parser -R %s, then delete this file.\n' "$APPARMOR_PROFILE"
		printf '%s\n' "$APPARMOR_RULES"
	} >"$file") || { warn "could not write $APPARMOR_PROFILE"; apparmor_manual "" "Allow it by hand:"; return 0; }
	if ! apparmor_parser -r -W "$file" >/dev/null; then
		warn "apparmor_parser could not load $APPARMOR_PROFILE (see above); builds on this server need it."
		return 0
	fi
	say "allowed user namespaces for launchroom's build container ($APPARMOR_PROFILE)"
}

# apparmor_manual SUDO MESSAGE: the warning and the exact commands.
apparmor_manual() {
	warn "$2"
	rules="$(printf '%s' "$APPARMOR_RULES" | awk '{ printf "%s\\n", $0 }')"
	printf "    printf '%s' | %stee %s >/dev/null && %sapparmor_parser -r -W %s\n" "$rules" "$1" "$APPARMOR_PROFILE" "$1" "$APPARMOR_PROFILE" >&2
}

# Swap on small hosts (docs/11 §2.1c, D59). With less than 4 GB of memory
# and no swap, a build that needs more memory than is free gets killed
# instead of slowing down. The installer offers a 2 GB swap file at
# /swapfile-launchroom (mode 600, in /etc/fstab) and sets vm.swappiness=10
# in /etc/sysctl.d/60-launchroom-swap.conf, so the kernel swaps only under
# pressure. Other swap is never touched. Undo: swapoff /swapfile-launchroom
# && rm /swapfile-launchroom, delete its /etc/fstab line and
# /etc/sysctl.d/60-launchroom-swap.conf.
SWAP_FILE=/swapfile-launchroom
SWAP_SYSCTL=/etc/sysctl.d/60-launchroom-swap.conf
SWAP_MB=2048
SWAP_MAX_MEM_KB=4194304

# check_swap: on small hosts without swap, creates the swap file after
# asking (or with --create-swap), else prints the commands. Never during an
# update (LR_BUNDLE_VERIFIED=1).
check_swap() {
	[ "${LR_BUNDLE_VERIFIED:-}" != 1 ] || return 0
	meminfo="$HOST_ROOT/proc/meminfo"
	mem_kb="$(awk '/^MemTotal:/ { print $2 }' "$meminfo" 2>/dev/null || true)"
	swap_kb="$(awk '/^SwapTotal:/ { print $2 }' "$meminfo" 2>/dev/null || true)"
	case "$mem_kb" in "" | *[!0-9]*) return 0 ;; esac
	case "$swap_kb" in "" | *[!0-9]*) return 0 ;; esac
	[ "$mem_kb" -lt "$SWAP_MAX_MEM_KB" ] && [ "$swap_kb" = 0 ] || return 0
	if [ -r "$HOST_ROOT/proc/swaps" ] && [ "$(sed 1d "$HOST_ROOT/proc/swaps" | grep -c .)" != 0 ]; then
		return 0
	fi
	gb="$(awk -v k="$mem_kb" 'BEGIN { printf "%.1f", k / 1048576 }')"
	why="This server has $gb GB of memory and no swap, so builds can run out of memory"
	if [ "$(id -u)" != 0 ]; then
		swap_manual "sudo " "$why. Add a 2 GB swap file as root:"
		return 0
	fi
	if [ "$CREATE_SWAP" != 1 ]; then
		if ! has_tty; then
			swap_manual "" "$why. Add a 2 GB swap file with these commands, or run this again with --create-swap:"
			return 0
		fi
		if ! ask "$why. Create a 2 GB swap file ($SWAP_FILE)?"; then
			swap_manual "" "Kept the server without swap. To add a swap file later:"
			return 0
		fi
	fi
	file="$HOST_ROOT$SWAP_FILE"
	if [ -L "$file" ] || { [ -e "$file" ] && [ ! -f "$file" ]; }; then
		warn "$SWAP_FILE is not a regular file; skipped."
		return 0
	fi
	if [ ! -f "$file" ]; then
		free_kb="$(df -Pk "$HOST_ROOT/" 2>/dev/null | awk 'NR == 2 { print $4 }')"
		case "$free_kb" in "" | *[!0-9]*) free_kb=0 ;; esac
		if [ "$free_kb" -lt $(((SWAP_MB + 1024) * 1024)) ]; then
			warn "not enough free disk space for a 2 GB swap file ($((free_kb / 1024)) MB free, 3072 MB needed); skipped."
			return 0
		fi
		(umask 077 && { fallocate -l "${SWAP_MB}M" "$file" 2>/dev/null ||
			{ rm -f "$file" && dd if=/dev/zero of="$file" bs=1M count="$SWAP_MB" 2>/dev/null; }; }) ||
			{ rm -f "$file"; warn "could not create $SWAP_FILE"; swap_manual "" "Add the swap file by hand:"; return 0; }
		chmod 600 "$file"
		mkswap "$file" >/dev/null || { rm -f "$file"; warn "mkswap failed for $SWAP_FILE"; return 0; }
	else
		chmod 600 "$file"
	fi
	if ! swapon "$file" 2>/dev/null; then
		warn "could not turn on $SWAP_FILE; an existing file is never reformatted."
		return 0
	fi
	fstab="$HOST_ROOT/etc/fstab"
	if ! grep -q "^${SWAP_FILE}[[:space:]]" "$fstab" 2>/dev/null; then
		printf '%s none swap sw 0 0\n' "$SWAP_FILE" >>"$fstab" || warn "could not add $SWAP_FILE to /etc/fstab; it is off after a reboot"
	fi
	conf="$HOST_ROOT$SWAP_SYSCTL"
	(umask 022 && mkdir -p "${conf%/*}" && {
		printf '# Written by the launchroom installer: swap only under memory pressure.\n'
		printf '# Undo: delete this file (and %s, see docs/11 §2.1c).\n' "$SWAP_FILE"
		printf 'vm.swappiness = 10\n'
	} >"$conf") || warn "could not write $SWAP_SYSCTL"
	if command -v sysctl >/dev/null 2>&1; then
		sysctl -w vm.swappiness=10 >/dev/null || warn "sysctl -w vm.swappiness=10 failed; it applies after a reboot"
	else
		printf '10\n' >"$HOST_ROOT/proc/sys/vm/swappiness" || warn "could not set vm.swappiness; it applies after a reboot"
	fi
	say "created a 2 GB swap file ($SWAP_FILE, vm.swappiness=10)"
}

# swap_manual SUDO MESSAGE: the warning and the exact commands.
swap_manual() {
	warn "$2"
	{
		printf '    %sfallocate -l 2G %s\n' "$1" "$SWAP_FILE"
		printf '    %schmod 600 %s\n' "$1" "$SWAP_FILE"
		printf '    %smkswap %s\n' "$1" "$SWAP_FILE"
		printf '    %sswapon %s\n' "$1" "$SWAP_FILE"
		printf "    echo '%s none swap sw 0 0' | %stee -a /etc/fstab\n" "$SWAP_FILE" "$1"
		printf "    echo 'vm.swappiness = 10' | %stee %s\n" "$1" "$SWAP_SYSCTL"
		printf '    %ssysctl -w vm.swappiness=10\n' "$1"
	} >&2
}

check_prerequisites() {
	case "$OS" in
	Linux)
		# Rootless Docker is set up per user: never install the system daemon for it.
		if ! command -v docker >/dev/null 2>&1; then
			[ "$ROOTLESS" = 0 ] || die "Docker is not installed. Set up rootless Docker first (https://docs.docker.com/engine/security/rootless/)."
			install_docker
		fi
		case "$(command -v docker)" in
		/snap/*) die "Docker from snap is not supported (it cannot see files outside your home folder). Install Docker Engine from docs.docker.com." ;;
		esac
		server_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null)" ||
			die "cannot talk to the Docker daemon. Is it running, and may this user use it (root or the docker group)?"
		;;
	Darwin)
		command -v docker >/dev/null 2>&1 || die "no Docker found. Install and start $MAC_ENGINES."
		server_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null)" ||
			die "cannot talk to Docker. Start OrbStack, Docker Desktop or Colima (colima start) and run this again."
		;;
	*) die "launchroom servers run on Linux or macOS (found $OS)." ;;
	esac
	[ "${server_version%%.*}" -ge 24 ] 2>/dev/null || die "Docker Engine >= 24 is required (found $server_version)."
	docker compose version >/dev/null 2>&1 || die "the Docker compose plugin is missing (https://docs.docker.com/compose/install/)."
	[ "$OS" != Darwin ] || check_macos
}

# check_macos: launchroomd runs inside the Linux VM of a supported engine
# (D53). Finds the engine and the socket the docker CLI uses, and refuses
# anything else: a remote engine, an unknown one, rootless, a folder the
# VM cannot see.
check_macos() {
	[ "$ROOTLESS" != 1 ] || die "--rootless is for Linux servers. On a Mac, Docker runs in the engine's VM; run this without --rootless."
	endpoint="${DOCKER_HOST:-}"
	[ -n "$endpoint" ] || endpoint="$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)"
	case "$endpoint" in
	unix://*) ;;
	*) die "the docker command talks to ${endpoint:-an unknown endpoint}, not to an engine on this Mac. Use the local engine (docker context use orbstack, desktop-linux or colima) and run this again." ;;
	esac
	info="$(docker info --format '{{.OperatingSystem}}|{{.Name}}' 2>/dev/null || true)"
	case "$info" in
	"Docker Desktop|"*) MAC_ENGINE=docker-desktop ;;
	"OrbStack|"*) MAC_ENGINE=orbstack ;;
	*"|colima" | *"|colima-"*) MAC_ENGINE=colima ;;
	*) die "this Docker engine (${info%%|*}) is not supported on macOS. Use $MAC_ENGINES. Rancher Desktop, Podman and others are not supported." ;;
	esac
	arch="$(docker info --format '{{.Architecture}}' 2>/dev/null || true)"
	case "$arch" in
	x86_64 | aarch64) ;;
	*) die "the engine's VM runs ${arch:-an unknown architecture}; launchroom needs x86_64 or aarch64." ;;
	esac
	if docker_is_rootless; then
		die "this engine runs Docker rootless, which launchroom does not support on macOS."
	fi
	case "$DIR" in
	/*) abs="$DIR" ;;
	*) abs="$(pwd)/$DIR" ;;
	esac
	case "$abs" in
	"$HOME"/*) ;;
	*)
		[ "$MAC_ENGINE" != colima ] || die "Colima only shares your home folder with its VM. Install under $HOME (unset LAUNCHROOM_DAEMON_DIR)."
		warn "$abs is outside your home folder; make sure $(mac_engine_name) shares it with its VM (file sharing settings)."
		;;
	esac
	say "macOS: $(mac_engine_name) ($arch VM), Docker $server_version at ${endpoint#unix://}"
}

mac_engine_name() {
	case "$MAC_ENGINE" in
	docker-desktop) printf 'Docker Desktop' ;;
	orbstack) printf 'OrbStack' ;;
	colima) printf 'Colima' ;;
	esac
}

# mac_model: the Mac's model name ("Mac mini", "MacBook Pro") for the device
# picture; the VM cannot see it. -detailLevel mini leaves out serial numbers
# and UUIDs. Prints nothing when unknown.
mac_model() {
	model="$(system_profiler -detailLevel mini SPHardwareDataType 2>/dev/null | sed -n 's/^ *Model Name: *//p' | head -n 1)"
	[ -n "$model" ] || model="$(sysctl -n hw.model 2>/dev/null || true)"
	if printf '%s' "$model" | grep -Eq '^[A-Za-z][A-Za-z0-9 ,.()-]{0,63}$'; then
		printf '%s' "$model"
	fi
}

# setup_macos: the settings of a Mac install (D53). dockerd-guard mounts the
# engine's socket as the VM sees it (/var/run/docker.sock, the default),
# never the Mac-side socket of the docker CLI, so LR_DOCKER_SOCKET stays unset.
setup_macos() {
	set_env LR_HOST_OS darwin
	set_env LR_HOST_MODEL "$(mac_model)"
	unset_env LR_DOCKER_SOCKET
	if [ "$MAC_ENGINE" = docker-desktop ]; then
		warn "Docker Desktop: this Mac can build, but not run apps (Docker Desktop opens published ports on the Mac, not on the private network). Use OrbStack or Colima to run apps here."
	fi
}

# macos_notes: how the containers come back after a restart. They use
# Docker's restart policy; the engine has to start at login.
macos_notes() {
	case "$MAC_ENGINE" in
	orbstack) say "keep OrbStack's \"Start at login\" on (OrbStack > Settings) so launchroomd comes back after a restart." ;;
	docker-desktop) say "keep \"Start Docker Desktop when you sign in\" on (Settings > General) so launchroomd comes back after a restart." ;;
	colima) say "to start Colima at login: brew services start colima. Otherwise run colima start after a restart." ;;
	esac
	say "a sleeping Mac is unreachable: for a build server, turn on \"Prevent automatic sleeping\" (System Settings > Energy)."
}

read_token() {
	if [ -z "${LR_JOIN_TOKEN:-}" ] && has_tty; then
		printf 'Join token (from Servers > Add server): ' >>"$TTY"
		stty -echo <"$TTY" 2>/dev/null || true
		IFS= read -r LR_JOIN_TOKEN <"$TTY" || true
		stty echo <"$TTY" 2>/dev/null || true
		printf '\n' >>"$TTY"
	fi
	[ -n "${LR_JOIN_TOKEN:-}" ] || die "set LR_JOIN_TOKEN (curl … | LR_JOIN_TOKEN=lr_jt_… sh)."
	case "$LR_JOIN_TOKEN" in
	lr_jt_*) ;;
	*) die "LR_JOIN_TOKEN does not look like a launchroom join token (lr_jt_…)." ;;
	esac
}

is_semver() {
	printf '%s' "$1" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
}

download() { # download URL FILE
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --retry 3 -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$2" "$1"
	else
		die "neither curl nor wget is installed"
	fi
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{ print $1 }'; else shasum -a 256 "$1" | awk '{ print $1 }'; fi
}

# fetch_bundle: downloads the signed release bundle of LR_RELEASE_VERSION,
# checks its checksum and cosign signature (exact identity for that tag)
# and sets LR_BUNDLE_DIR to the unpacked bundle. Without a signed release
# (development builds) it stops unless LR_ALLOW_UNSIGNED=1, in which case
# the compose file the dashboard embedded is used unverified.
fetch_bundle() {
	if [ -n "${LR_BUNDLE_DIR:-}" ]; then
		[ -f "$LR_BUNDLE_DIR/daemon/compose.yml" ] || die "$LR_BUNDLE_DIR is not a launchroom bundle (expected daemon/compose.yml)"
		[ "${LR_BUNDLE_VERIFIED:-}" = 1 ] || warn "using the local bundle $LR_BUNDLE_DIR; it is NOT signature-verified."
		return
	fi
	if unfilled "$LR_RELEASE_VERSION" || ! is_semver "$LR_RELEASE_VERSION"; then
		[ "${LR_ALLOW_UNSIGNED:-}" = 1 ] || die "this dashboard does not name a release version, so the bundle cannot be verified. Set LR_RELEASE_VERSION, or LR_ALLOW_UNSIGNED=1 for a development build."
		warn "no release version; using the compose file from the dashboard, NOT signature-verified (LR_ALLOW_UNSIGNED=1)."
		return
	fi
	base="$RELEASE_BASE/download/v$LR_RELEASE_VERSION"
	tmp="$DIR/.tmp"
	rm -rf "$tmp" && mkdir -p "$tmp/bundle"
	say "downloading the release bundle v$LR_RELEASE_VERSION"
	if ! download "$base/launchroom-deploy.tar.gz" "$tmp/bundle.tar.gz" 2>/dev/null ||
		! download "$base/launchroom-deploy.tar.gz.sha256" "$tmp/bundle.tar.gz.sha256" 2>/dev/null; then
		rm -rf "$tmp"
		[ "${LR_ALLOW_UNSIGNED:-}" = 1 ] || die "could not download release v$LR_RELEASE_VERSION from $RELEASE_BASE. Development build? Set LR_ALLOW_UNSIGNED=1 to use the dashboard's compose file unverified."
		warn "release v$LR_RELEASE_VERSION is not available; using the compose file from the dashboard, NOT signature-verified (LR_ALLOW_UNSIGNED=1)."
		return
	fi
	[ "$(sha256_of "$tmp/bundle.tar.gz")" = "$(awk '{ print $1 }' "$tmp/bundle.tar.gz.sha256")" ] ||
		die "the bundle checksum does not match. Not installing."
	if download "$base/launchroom-deploy.tar.gz.sigstore.json" "$tmp/bundle.tar.gz.sigstore.json" 2>/dev/null; then
		bundle_version="$(tar -xzOf "$tmp/bundle.tar.gz" VERSION 2>/dev/null | tr -d ' \r\n' || true)"
		[ "$bundle_version" = "$LR_RELEASE_VERSION" ] || die "the bundle is version '$bundle_version', not $LR_RELEASE_VERSION. Not installing."
		# umask 077 makes the downloads 0600 root; the cosign image runs as a
		# non-root user. The bundle and its signature are public release files.
		chmod 0755 "$tmp" && chmod 0644 "$tmp"/bundle.tar.gz "$tmp"/bundle.tar.gz.sigstore.json
		had_image="$(docker image ls -q "$COSIGN_IMAGE" 2>/dev/null)"
		cosign_out="$(docker run --rm --network bridge -v "$(cd "$tmp" && pwd):/work:ro" "$COSIGN_IMAGE" verify-blob \
			--bundle /work/bundle.tar.gz.sigstore.json \
			--certificate-identity "$SIGNER_IDENTITY$LR_RELEASE_VERSION" \
			--certificate-oidc-issuer "$SIGNER_ISSUER" \
			/work/bundle.tar.gz 2>&1)" || status=$?
		[ -n "$had_image" ] || docker image rm "$COSIGN_IMAGE" >/dev/null 2>&1 || true
		if [ "${status:-0}" != 0 ]; then
			# cosign's own words: a bad signature, no network to Sigstore (TUF), or a pull failure.
			printf '%s\n' "$cosign_out" | tail -n 8 | sed 's/^/launchroom:   cosign: /' >&2
			die "the bundle's cosign signature could not be verified for $SIGNER_IDENTITY$LR_RELEASE_VERSION (cosign's output above). Not installing."
		fi
		say "bundle signature verified: $SIGNER_IDENTITY$LR_RELEASE_VERSION"
	elif [ "${LR_ALLOW_UNSIGNED:-}" = 1 ]; then
		warn "release v$LR_RELEASE_VERSION has no signature; continuing because of LR_ALLOW_UNSIGNED=1 (checksum only)."
	else
		die "release v$LR_RELEASE_VERSION has no cosign signature. Set LR_ALLOW_UNSIGNED=1 to accept a checksum-only bundle (development builds only)."
	fi
	tar -xzf "$tmp/bundle.tar.gz" -C "$tmp/bundle"
	LR_BUNDLE_DIR="$(cd "$tmp/bundle" && pwd)"
	pinned="$(sed -n 's/^LR_DAEMON_IMAGE=//p' "$LR_BUNDLE_DIR/control-plane/images.env" | tail -n 1)"
	if [ -n "$pinned" ] && [ "$pinned" != "$LR_DAEMON_IMAGE" ]; then
		die "the dashboard's launchroomd image ($LR_DAEMON_IMAGE) is not the one release v$LR_RELEASE_VERSION pins ($pinned)."
	fi
}

# write_trust: pins the release identity launchroomd's self-updates are
# verified against (docs/11 §4). Written once, never replaced.
write_trust() {
	[ ! -e "$DIR/data/trust.json" ] || return 0
	printf '{\n  "issuer": "%s",\n  "identity": "%s",\n  "releaseBase": "%s"\n}\n' \
		"$SIGNER_ISSUER" "$SIGNER_IDENTITY" "$RELEASE_BASE" >"$DIR/data/trust.json"
	chmod 0644 "$DIR/data/trust.json"
}

# write_guard_trust: the same pin in guard/trust.json, outside launchroomd's
# data folder (D49): the update supervisor verifies a release again against
# this copy, which launchroomd cannot change. Written once.
write_guard_trust() {
	[ ! -e "$DIR/guard/trust.json" ] || return 0
	mkdir -p "$DIR/guard"
	cp "$DIR/data/trust.json" "$DIR/guard/trust.json"
	chmod 0644 "$DIR/guard/trust.json"
}

write_compose() {
	if [ -n "${LR_BUNDLE_DIR:-}" ]; then
		cp "$LR_BUNDLE_DIR/daemon/compose.yml" "$DIR/compose.yml"
		return
	fi
	# The dashboard replaces the marker line below with deploy/daemon/compose.yml.
	cat >"$DIR/compose.yml" <<'LAUNCHROOM_DAEMON_COMPOSE_EOF'
#__LAUNCHROOM_DAEMON_COMPOSE__
LAUNCHROOM_DAEMON_COMPOSE_EOF
	if grep -q '^#__LAUNCHROOM_DAEMON_COMPOSE__$' "$DIR/compose.yml"; then
		die "no compose file embedded: download this script from your dashboard (/install/server.sh) or set LR_BUNDLE_DIR."
	fi
}

# set_env KEY VALUE: replace or append KEY=VALUE in .env.
set_env() {
	tmp="$DIR/.env.tmp"
	{ grep -v "^$1=" "$DIR/.env" 2>/dev/null || true; printf '%s=%s\n' "$1" "$2"; } >"$tmp"
	mv "$tmp" "$DIR/.env"
}

# unset_env KEY: remove KEY from .env.
unset_env() {
	tmp="$DIR/.env.tmp"
	{ grep -v "^$1=" "$DIR/.env" 2>/dev/null || true; } >"$tmp"
	mv "$tmp" "$DIR/.env"
}

# host_timezone: the host's IANA time zone for launchroomd (its container
# cannot see the host's /etc). Informational: it only suggests the server's
# location in the dashboard. Prints nothing when unknown.
host_timezone() {
	tz=""
	[ ! -r /etc/timezone ] || tz="$(head -n 1 /etc/timezone)"
	[ -n "$tz" ] || [ ! -L /etc/localtime ] || tz="$(readlink /etc/localtime | sed -n 's#.*zoneinfo/##p')"
	if printf '%s' "$tz" | grep -Eq '^[A-Za-z][A-Za-z0-9_+-]{0,31}(/[A-Za-z0-9_+-]{1,32}){0,2}$'; then
		printf '%s' "$tz"
	fi
}

dc() { (cd "$DIR" && docker compose "$@"); }

# record_public_ip_settings: LR_PUBLIC_IP_DETECT and LR_PUBLIC_IPS from the
# environment go into .env (grammar-checked), so restarts and self-updates
# keep them. Unset: .env is left as it is.
record_public_ip_settings() {
	if [ -n "${LR_PUBLIC_IP_DETECT:-}" ]; then
		case "$LR_PUBLIC_IP_DETECT" in
		on | off) set_env LR_PUBLIC_IP_DETECT "$LR_PUBLIC_IP_DETECT" ;;
		*) die "LR_PUBLIC_IP_DETECT must be on or off." ;;
		esac
	fi
	if [ -n "${LR_PUBLIC_IPS:-}" ]; then
		printf '%s' "$LR_PUBLIC_IPS" | grep -Eq '^[0-9A-Fa-f.:]+(,[0-9A-Fa-f.:]+)*$' ||
			die "LR_PUBLIC_IPS must be IP addresses separated by commas (203.0.113.7,2001:db8::7)."
		set_env LR_PUBLIC_IPS "$LR_PUBLIC_IPS"
	fi
}

parse_args() {
	while [ $# -gt 0 ]; do
		case "$1" in
		--rootless) ROOTLESS=1 ;;
		--install-docker) INSTALL_DOCKER=1 ;;
		--no-install-docker) NO_INSTALL_DOCKER=1 ;;
		--raise-inotify) RAISE_INOTIFY=1 ;;
		--allow-buildkit-userns) ALLOW_BUILDKIT_USERNS=1 ;;
		--create-swap) CREATE_SWAP=1 ;;
		-h | --help) sed -n '2,/^set -eu$/p' "$0" 2>/dev/null | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
		*) die "unknown option $1 (see --help)" ;;
		esac
		shift
	done
}

# docker_is_rootless: the Docker daemon runs rootless (docker info).
docker_is_rootless() {
	docker info --format '{{range .SecurityOptions}}{{.}} {{end}}' 2>/dev/null | grep -q 'name=rootless'
}

# setup_rootless: checks and settings for a rootless Docker host (D49):
# the user's Docker socket, RootlessKit's API socket for the edge ports,
# the unprivileged port range, and the compose override that hands the API
# socket to dockerd-guard. Nothing outside the install folder is changed;
# the sysctl is only checked and explained.
setup_rootless() {
	if [ "$ROOTLESS" != 1 ]; then
		if docker_is_rootless; then
			die "this Docker daemon runs rootless. Re-run with --rootless (… | sh -s -- --rootless)."
		fi
		rm -f "$DIR/compose.rootless.yml"
		unset_env COMPOSE_FILE
		return 0
	fi
	docker_is_rootless || die "--rootless was given, but this Docker daemon is not rootless (docker info shows no name=rootless)."
	runtime_dir="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
	socket=""
	case "${DOCKER_HOST:-}" in unix://*) socket="${DOCKER_HOST#unix://}" ;; esac
	[ -n "$socket" ] || socket="$runtime_dir/docker.sock"
	[ -S "$socket" ] || die "the rootless Docker socket $socket does not exist (set DOCKER_HOST=unix:///path/to/docker.sock)."
	api="${LR_ROOTLESSKIT_API:-$runtime_dir/dockerd-rootless/api.sock}"
	[ -S "$api" ] || die "RootlessKit's API socket $api does not exist; launchroom needs it to expose ports 80 and 443 (set LR_ROOTLESSKIT_API)."
	start="$(cat /proc/sys/net/ipv4/ip_unprivileged_port_start 2>/dev/null || echo 1024)"
	if [ "$start" -gt 80 ] 2>/dev/null; then
		die "ports 80 and 443 need net.ipv4.ip_unprivileged_port_start <= 80 (it is $start). As root, once: sysctl -w net.ipv4.ip_unprivileged_port_start=80, and add that line to a file in /etc/sysctl.d/ to keep it after a reboot."
	fi
	[ -e /dev/net/tun ] || warn "/dev/net/tun is missing: this server cannot join the private network to other servers."
	if [ "$(docker info --format '{{.CgroupDriver}}' 2>/dev/null)" = none ]; then
		warn "rootless Docker runs without cgroups here: CPU, memory and process limits of apps are not enforced. Use cgroup v2 with systemd delegation (docs.docker.com/engine/security/rootless/#limiting-resources)."
	fi
	set_env LR_DOCKER_SOCKET "$socket"
	set_env LR_ROOTLESSKIT_API "$api"
	set_env COMPOSE_FILE "compose.yml:compose.rootless.yml"
	# MANAGED FILE (replaced by every run): hands RootlessKit's API to the guard.
	cat >"$DIR/compose.rootless.yml" <<'LAUNCHROOM_ROOTLESS_EOF'
# Rootless Docker (server.sh --rootless, docs/11 §3.2, D49). MANAGED FILE.
services:
  dockerd-guard:
    environment:
      LR_GUARD_ROOTLESSKIT_API: /run/rootlesskit/api.sock
    volumes:
      - ${LR_ROOTLESSKIT_API:?}:/run/rootlesskit/api.sock
LAUNCHROOM_ROOTLESS_EOF
	say "rootless Docker: socket $socket, edge ports through $api"
}

# Before D27 the default folder was ./launchroom-agent (compose project
# launchroom-agent). Stop that project and move its folder (keys, enrollment,
# state) to the new default, so the server keeps its identity.
migrate_legacy_dir() {
	legacy="./launchroom-agent"
	if [ "$OS" = Darwin ] || [ -n "${LAUNCHROOM_DAEMON_DIR:-}" ] || [ ! -d "$legacy" ] || [ -e "$DIR" ]; then
		return 0
	fi
	say "moving $legacy to $DIR (launchroomd is the new name of the launchroom agent)"
	(cd "$legacy" && docker compose down --remove-orphans) || die "could not stop the old compose project in $legacy."
	mv "$legacy" "$DIR"
	if [ -f "$DIR/.env" ]; then
		sed 's/^LR_AGENT_IMAGE=/LR_DAEMON_IMAGE=/' "$DIR/.env" >"$DIR/.env.tmp" && mv "$DIR/.env.tmp" "$DIR/.env"
	fi
}

main() {
	parse_args "$@"
	check_prerequisites
	[ "$OS" != Linux ] || check_inotify
	[ "$OS" != Linux ] || check_buildkit_apparmor
	[ "$OS" != Linux ] || check_swap
	unfilled "$LR_CONTROL_PLANE_URL" && die "set LR_CONTROL_PLANE_URL (the dashboard URL)."
	unfilled "$LR_CP_KEY" && die "set LR_CP_KEY (shown next to the join command in the dashboard)."
	unfilled "$LR_DAEMON_IMAGE" && die "set LR_DAEMON_IMAGE."
	case "$LR_CP_KEY" in ed25519:*) ;; *) die "LR_CP_KEY must look like ed25519:<base64>." ;; esac

	migrate_legacy_dir
	umask 077
	mkdir -p "$DIR/data"
	fetch_bundle
	write_trust
	write_guard_trust
	write_compose
	rm -rf "$DIR/.tmp"
	set_env LR_DAEMON_IMAGE "$LR_DAEMON_IMAGE"
	set_env LR_CONTROL_PLANE_URL "$LR_CONTROL_PLANE_URL"
	set_env LR_CP_KEY "$LR_CP_KEY"
	# Kept when unknown: the self-update runs this script in a container
	# that cannot see the host's zone.
	tz="$(host_timezone)"
	[ -z "$tz" ] || set_env LR_HOST_TIMEZONE "$tz"
	record_public_ip_settings
	if [ "$OS" = Darwin ]; then
		setup_macos
	else
		setup_rootless
	fi

	if [ -e "$DIR/data/enrollment.json" ]; then
		say "this server is already enrolled; updating and restarting launchroomd only."
		dc up -d --remove-orphans
		exit 0
	fi

	read_token
	# The token goes into a 0600 file launchroomd reads once; never into argv.
	printf '%s\n' "$LR_JOIN_TOKEN" >"$DIR/data/join-token"
	set_env LR_JOIN_TOKEN_FILE /data/join-token
	say "starting launchroomd (control plane: $LR_CONTROL_PLANE_URL)"
	dc up -d --remove-orphans

	i=0
	until [ -e "$DIR/data/enrollment.json" ]; do
		i=$((i + 1))
		[ "$i" -le 90 ] || die "launchroomd did not enroll within 3 minutes. Check: (cd $DIR && docker compose logs launchroomd)"
		sleep 2
	done

	# Enrolled: the token is used up; remove it and restart without it.
	rm -f "$DIR/data/join-token"
	set_env LR_JOIN_TOKEN_FILE ""
	dc up -d
	say "enrolled. The server shows up in the dashboard under Servers."
	[ "$OS" != Darwin ] || macos_notes
	say "files: $DIR. Uninstall: (cd $DIR && docker compose down && docker compose run --rm launchroomd firewall remove),"
	say "remove the containers launchroomd started (docker ps -aq --filter label=launchroom.managed=true), then delete the folder."
	if [ -f "$HOST_ROOT$INOTIFY_FILE" ]; then
		say "the raised inotify limits stay after an uninstall; to undo them, delete $INOTIFY_FILE and reboot."
	fi
	if [ -f "$HOST_ROOT$APPARMOR_PROFILE" ]; then
		say "the AppArmor profile $APPARMOR_PROFILE stays after an uninstall (it applies to nothing else); to remove it: apparmor_parser -R $APPARMOR_PROFILE && rm $APPARMOR_PROFILE"
	fi
	if [ -f "$HOST_ROOT$SWAP_FILE" ]; then
		say "the swap file $SWAP_FILE stays after an uninstall; to remove it: swapoff $SWAP_FILE && rm $SWAP_FILE, then delete its line in /etc/fstab and $SWAP_SYSCTL"
	fi
}

main "$@"
