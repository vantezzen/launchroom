#!/bin/sh
# launchroom control-plane installer (docs/11 §2).
#
#   curl -fsSL https://launchroom.vantezzen.io/install | sh
#   curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --domain dash.example.com
#   curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --unstable
#
# Options:
#   --dir DIR              install folder (default: $LAUNCHROOM_DIR or ./launchroom)
#   --domain HOST          dashboard host name (default: <public-ip>.sslip.io)
#   --version V            release to install (default: latest)
#   --unstable             install the newest release including pre-releases, and
#                          offer pre-releases in Settings → Updates (beta channel)
#   --bundle DIR           use an unpacked bundle (the repo's deploy/ folder) instead
#                          of downloading one. Not signature-verified: for testing,
#                          air-gapped installs and development.
#   --no-public-ip-detect  never contact IP echo services (needs --domain); also
#                          sets LR_PUBLIC_IP_DETECT=off in .env, so launchroomd
#                          does not either. LR_PUBLIC_IPS=<ip,ip> in .env then
#                          names the server's public addresses.
#   --install-docker       when Docker is missing, install it with Docker's official
#                          script (get.docker.com) without asking (also
#                          LR_INSTALL_DOCKER=1). Without it the installer asks,
#                          and stops when there is no terminal to ask on.
#   --no-install-docker    never install Docker: stop with instructions instead
#                          (also LR_NO_INSTALL_DOCKER=1)
#   --raise-inotify        raise low inotify limits without asking (also
#                          LR_RAISE_INOTIFY=1), see below
#   --allow-buildkit-userns  on hosts that restrict user namespaces (Ubuntu
#                          23.10+), load launchroom's AppArmor profile for its
#                          build container without asking (also
#                          LR_BUILDKIT_APPARMOR=1), see below
#   --create-swap          on servers with less than 4 GB of memory and no swap,
#                          create a 2 GB swap file without asking (also
#                          LR_CREATE_SWAP=1), see below
#   --skip-port-check      install even though 80/443 are taken (the dashboard is
#                          then only reachable on 127.0.0.1:8000)
#   --allow-unsigned       accept a downloaded bundle without a cosign signature
#                          (development builds only; a signature that is present
#                          but does not verify is always refused)
#   --restore              disaster recovery from a control-plane backup (docs/14 §5.2);
#                          secrets from LR_RESTORE_* / LR_RECOVERY_PASSPHRASE or prompts
#   --recovery-kit FILE    recovery kit for --restore (implies --restore)
#   --restore-allow-http   accept a plain-http backup endpoint for --restore (LAN
#                          or test S3 only; implies --restore)
#   --move TOKEN           move launchroom here from another server (docs/14 §13.4):
#                          the one-time token from Settings → Move launchroom there.
#                          Run the same command again to continue an interrupted move.
#   --move-url URL         reach the old dashboard at this address instead of the
#                          one in the token (for example over a private network)
#   -h, --help
#
# Environment overrides for image pins (testing / mirrors): LR_DASHBOARD_IMAGE,
# LR_DAEMON_IMAGE, LR_CADDY_IMAGE, LR_POSTGRES_IMAGE, LR_VALKEY_IMAGE, LR_ZOT_IMAGE.
#
# Re-running is safe: .env (secrets) and data/ are kept, compose.yml, the
# helper script and image pins are replaced, the stack is brought up to date,
# and the local launchroomd is only enrolled if it is not already.
#
# Nothing is written outside the install folder, with four exceptions the
# installer asks about first (or that a flag allows):
#   - Docker itself, when it is missing (Docker's official script).
#   - /etc/sysctl.d/60-launchroom-inotify.conf, when the kernel's inotify limits
#     are below what launchroom needs (fs.inotify.max_user_watches >= 65536,
#     fs.inotify.max_user_instances >= 256). It raises them to 524288 and 512,
#     never lowers a value, and needs root. Undo: delete the file and reboot.
#   - /etc/apparmor.d/launchroom-buildkit, when AppArmor restricts unprivileged
#     user namespaces (kernel.apparmor_restrict_unprivileged_userns=1, Ubuntu
#     23.10 and later): a profile that lets only launchroom's build container
#     create the user namespace rootless BuildKit needs; the restriction stays
#     on for everything else. Needs root. Undo: apparmor_parser -R the file,
#     then delete it.
#   - /swapfile-launchroom, when the server has less than 4 GB of memory and no
#     swap: a 2 GB swap file (mode 600) with its line in /etc/fstab, and
#     /etc/sysctl.d/60-launchroom-swap.conf with vm.swappiness = 10. Needs
#     root. Undo: swapoff /swapfile-launchroom && rm /swapfile-launchroom,
#     then delete its /etc/fstab line and the sysctl file.
# Outside it, launchroom only creates Docker objects (containers, networks,
# images, volumes) labelled launchroom.managed=true.
set -eu

RELEASE_BASE="${LAUNCHROOM_RELEASE_BASE:-https://github.com/vantezzen/launchroom/releases}"
# Release list for --unstable (newest first, pre-releases included, no drafts).
RELEASES_API="${LAUNCHROOM_RELEASES_API:-https://api.github.com/repos/vantezzen/launchroom/releases?per_page=20}"
# cosign v3.0.2, multi-arch index digest. Run as a container: nothing to install.
COSIGN_IMAGE="ghcr.io/sigstore/cosign/cosign:v3.0.2@sha256:b29487e48205d875c324c79583e2806d9d269c0fa299e0861bbec023d8430c8b"
# Release identity (docs/09 R30, docs/11 §2): keyless cosign signatures made
# by .github/workflows/release.yml of vantezzen/launchroom on the tag
# v<version>, certified for GitHub Actions' OIDC issuer. The certificate
# must name exactly "$SIGNER_IDENTITY<version>" for the bundle's VERSION.
# The same identity is pinned for launchroomd's self-updates in
# data/daemon/trust.json (written once, never replaced).
SIGNER_IDENTITY="https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
SIGNER_ISSUER="https://token.actions.githubusercontent.com"
# UID of the dashboard image's runtime user (owns data/keys).
DASHBOARD_UID=1000

DIR="${LAUNCHROOM_DIR:-./launchroom}"
DOMAIN=""
VERSION="latest"
BUNDLE=""
DETECT_IP=1
PORT_CHECK=1
ALLOW_UNSIGNED=0
RESTORE=0
RESTORE_HTTP=0
KIT=""
MOVE=0
MOVE_URL=""
UNSTABLE=0
# Docker when it is missing: ask (default), or 1 = install without asking.
# NO_INSTALL_DOCKER=1 never installs and wins over both.
INSTALL_DOCKER="${LR_INSTALL_DOCKER:-0}"
NO_INSTALL_DOCKER="${LR_NO_INSTALL_DOCKER:-0}"
RAISE_INOTIFY="${LR_RAISE_INOTIFY:-0}"
ALLOW_BUILDKIT_USERNS="${LR_BUILDKIT_APPARMOR:-0}"
CREATE_SWAP="${LR_CREATE_SWAP:-0}"

# Test seams (deploy/test/install-sh-test.sh): the terminal prompts are read
# from, and a folder standing in for / (for /proc/sys, /proc/meminfo,
# /sys/module, /etc/sysctl.d, /etc/apparmor.d, /etc/fstab and the swap file).
TTY="${LR_TEST_TTY:-/dev/tty}"
HOST_ROOT="${LR_TEST_ROOT:-}"

# A terminal we can prompt on (also when the script itself comes from a pipe).
has_tty() { (: <"$TTY") 2>/dev/null; }
say() { printf 'launchroom: %s\n' "$*"; }
warn() { printf 'launchroom: warning: %s\n' "$*" >&2; }
die() { printf 'launchroom: error: %s\n' "$*" >&2; exit 1; }

usage() { sed -n '2,/^set -eu$/p' "$0" 2>/dev/null | sed -e '$d' -e 's/^# \{0,1\}//'; }

parse_args() {
	while [ $# -gt 0 ]; do
		case "$1" in
		--dir) DIR="${2:?--dir needs a value}"; shift ;;
		--domain) DOMAIN="${2:?--domain needs a value}"; shift ;;
		--version) VERSION="${2:?--version needs a value}"; shift ;;
		--unstable) UNSTABLE=1 ;;
		--install-docker) INSTALL_DOCKER=1 ;;
		--no-install-docker) NO_INSTALL_DOCKER=1 ;;
		--raise-inotify) RAISE_INOTIFY=1 ;;
		--allow-buildkit-userns) ALLOW_BUILDKIT_USERNS=1 ;;
		--create-swap) CREATE_SWAP=1 ;;
		--bundle) BUNDLE="${2:?--bundle needs a value}"; shift ;;
		--no-public-ip-detect) DETECT_IP=0 ;;
		--skip-port-check) PORT_CHECK=0 ;;
		--allow-unsigned) ALLOW_UNSIGNED=1 ;;
		--restore) RESTORE=1 ;;
		--recovery-kit) KIT="${2:?--recovery-kit needs a file}"; RESTORE=1; shift ;;
		--restore-allow-http) RESTORE_HTTP=1; RESTORE=1 ;;
		--move) LR_MOVE_TOKEN="${2:?--move needs the token from Settings → Move launchroom}"; export LR_MOVE_TOKEN; MOVE=1; shift ;;
		--move-url) MOVE_URL="${2:?--move-url needs an address}"; MOVE=1; shift ;;
		-h | --help) usage; exit 0 ;;
		*) die "unknown option $1 (see --help)" ;;
		esac
		shift
	done
	if [ "$UNSTABLE" = 1 ] && [ "$VERSION" != "latest" ]; then
		die "--unstable picks the newest release itself; use either --unstable or --version"
	fi
}

# ---- Checks --------------------------------------------------------------------

# port_in_use PORT: a TCP socket is listening on PORT in this network namespace.
port_in_use() {
	hex="$(printf ':%04X' "$1")"
	awk -v p="$hex" '$4 == "0A" && substr($2, length($2) - 4) == p { found = 1 } END { exit !found }' \
		/proc/net/tcp /proc/net/tcp6 2>/dev/null
}

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
	[ "$(uname -s)" = "Linux" ] || die "the control plane runs on Linux (found $(uname -s)). Docker Desktop is only supported for build servers and development."
	for tool in awk sed tr head base64 grep; do
		command -v "$tool" >/dev/null 2>&1 || die "missing required tool: $tool"
	done
	command -v docker >/dev/null 2>&1 || install_docker
	case "$(command -v docker)" in
	/snap/*) die "Docker from snap is not supported (it cannot see files outside your home folder). Install Docker Engine from docs.docker.com." ;;
	esac
	server_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null)" ||
		die "cannot talk to the Docker daemon. Is it running, and may this user use it (root or the docker group)?"
	[ "${server_version%%.*}" -ge 24 ] 2>/dev/null || die "Docker Engine >= 24 is required (found $server_version)."
	docker compose version >/dev/null 2>&1 ||
		die "the Docker compose plugin is missing (https://docs.docker.com/compose/install/linux/)."

	mem_kb="$(awk '/^MemTotal:/ { print $2 }' /proc/meminfo 2>/dev/null || echo 0)"
	if [ "${mem_kb:-0}" -lt 1900000 ]; then
		warn "this server has less than 2 GB of memory. The control plane fits, but builds are slow and may run out of memory. Add swap (asked below) or a separate build server."
	fi

	# Our own Caddy holds 80/443 on a re-run.
	if [ "$PORT_CHECK" = 1 ] && [ -z "$(docker ps -q --filter label=launchroom.managed=true --filter name=lr-system-caddy)" ]; then
		for port in 80 443; do
			if port_in_use "$port"; then
				die "port $port is already in use. launchroom's ingress (Caddy) needs 80 and 443. Stop the other web server, or pass --skip-port-check (the dashboard is then only reachable on 127.0.0.1:8000)."
			fi
		done
	fi
}

# ---- Bundle --------------------------------------------------------------------

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

# resolve_unstable: VERSION = the newest published release, pre-releases
# included. GitHub's "latest" skips pre-releases, so read the release list
# (newest first) and take the first tag that is a semantic version.
resolve_unstable() {
	list="$(download "$RELEASES_API" - 2>/dev/null)" || die "could not read the release list from $RELEASES_API"
	newest="$(printf '%s' "$list" | grep -o '"tag_name"[[:space:]]*:[[:space:]]*"v[^"]*"' |
		sed 's/.*"v\([^"]*\)"$/\1/' | while IFS= read -r v; do
		if is_semver "$v"; then printf '%s\n' "$v"; break; fi
	done)"
	[ -n "$newest" ] || die "no release found in $RELEASES_API"
	VERSION="$newest"
	say "--unstable: installing $VERSION, the newest release including pre-releases"
}

# is_semver V: V is MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD] without a leading v.
is_semver() {
	printf '%s' "$1" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
}

# Verifies the bundle's cosign signature (keyless, GitHub Actions identity)
# with a pinned cosign container: the certificate must name exactly the
# release workflow on tag v<VERSION>, where VERSION is the version the
# bundle says it is (read first, grammar-checked, then bound by the
# signature check). Never reports success it did not check.
verify_signature() { # verify_signature DIR (with bundle.tar.gz + bundle.tar.gz.sigstore.json)
	bundle_version="$(tar -xzOf "$1/bundle.tar.gz" VERSION 2>/dev/null | tr -d ' \r\n' || true)"
	is_semver "$bundle_version" || die "the bundle has no valid VERSION. Not installing."
	if [ "$VERSION" != "latest" ] && [ "$bundle_version" != "$VERSION" ]; then
		die "asked for version $VERSION but the bundle is version $bundle_version. Not installing."
	fi
	# umask 077 makes the downloads 0600 root; the cosign image runs as a
	# non-root user. The bundle and its signature are public release files.
	chmod 0755 "$1" && chmod 0644 "$1"/bundle.tar.gz "$1"/bundle.tar.gz.sigstore.json
	had_image="$(docker image ls -q "$COSIGN_IMAGE" 2>/dev/null)"
	# Network: cosign fetches Sigstore's trusted root through TUF.
	cosign_out="$(docker run --rm --network bridge -v "$(cd "$1" && pwd):/work:ro" "$COSIGN_IMAGE" verify-blob \
		--bundle /work/bundle.tar.gz.sigstore.json \
		--certificate-identity "$SIGNER_IDENTITY$bundle_version" \
		--certificate-oidc-issuer "$SIGNER_ISSUER" \
		/work/bundle.tar.gz 2>&1)" || status=$?
	[ -n "$had_image" ] || docker image rm "$COSIGN_IMAGE" >/dev/null 2>&1 || true
	if [ "${status:-0}" != 0 ]; then
		# cosign's own words: a bad signature, or no network to Sigstore
		# (it fetches the trusted root through TUF), or a pull failure.
		printf '%s\n' "$cosign_out" | tail -n 8 | sed 's/^/launchroom:   cosign: /' >&2
		die "the bundle's cosign signature could not be verified for $SIGNER_IDENTITY$bundle_version (cosign's output above). Not installing."
	fi
}

fetch_bundle() {
	if [ -n "$BUNDLE" ]; then
		[ -f "$BUNDLE/control-plane/compose.yml" ] || die "$BUNDLE is not a launchroom bundle (expected control-plane/compose.yml)"
		BUNDLE="$(cd "$BUNDLE" && pwd)"
		if [ "${LR_BUNDLE_VERIFIED:-}" = 1 ]; then
			# Started by launchroomd's update supervisor, which verified this
			# bundle against the pinned release identity (docs/11 §4).
			say "using the release bundle verified by launchroomd ($BUNDLE)"
		else
			warn "using the local bundle $BUNDLE; it is NOT signature-verified."
		fi
		return
	fi

	[ "$UNSTABLE" = 0 ] || resolve_unstable
	if [ "$VERSION" = "latest" ]; then base="$RELEASE_BASE/latest/download"; else base="$RELEASE_BASE/download/v$VERSION"; fi
	tmp="$DIR/.tmp"
	rm -rf "$tmp" && mkdir -p "$tmp/bundle"
	say "downloading the release bundle ($VERSION)"
	download "$base/launchroom-deploy.tar.gz" "$tmp/bundle.tar.gz" || die "could not download $base/launchroom-deploy.tar.gz"
	download "$base/launchroom-deploy.tar.gz.sha256" "$tmp/bundle.tar.gz.sha256" || die "could not download the bundle checksum"
	[ "$(sha256_of "$tmp/bundle.tar.gz")" = "$(awk '{ print $1 }' "$tmp/bundle.tar.gz.sha256")" ] ||
		die "the bundle checksum does not match. Not installing."

	# Releases are signed by .github/workflows/release.yml (cosign keyless +
	# SLSA provenance, docs/09 R30). A signature that is present must verify;
	# only a release without one can be accepted, and only with --allow-unsigned.
	if download "$base/launchroom-deploy.tar.gz.sigstore.json" "$tmp/bundle.tar.gz.sigstore.json" 2>/dev/null; then
		verify_signature "$tmp"
		say "bundle signature verified: $SIGNER_IDENTITY$bundle_version ($SIGNER_ISSUER)."
	elif [ "$ALLOW_UNSIGNED" = 1 ]; then
		warn "this release has no signature; continuing because of --allow-unsigned. The bundle was only checksum-verified."
	else
		die "this release has no cosign signature, so it cannot be verified. Re-run with --allow-unsigned to accept a checksum-only bundle (development builds only)."
	fi
	tar -xzf "$tmp/bundle.tar.gz" -C "$tmp/bundle"
	BUNDLE="$(cd "$tmp/bundle" && pwd)"
}

# ---- Folder & .env ---------------------------------------------------------------

env_get() { sed -n "s/^$1=//p" "$DIR/.env" 2>/dev/null | tail -n 1; }

# env_set KEY VALUE: replace or append KEY=VALUE in .env (stays 0600: umask).
env_set() {
	{ grep -v "^$1=" "$DIR/.env" 2>/dev/null || true; printf '%s=%s\n' "$1" "$2"; } >"$DIR/.env.tmp"
	mv "$DIR/.env.tmp" "$DIR/.env"
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

rand_alnum() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }

detect_public_ip() {
	for url in https://api.ipify.org https://ipv4.icanhazip.com https://ifconfig.me/ip; do
		ip="$(download "$url" - 2>/dev/null | tr -d ' \r\n' || true)"
		if printf '%s' "$ip" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then printf '%s' "$ip"; return; fi
	done
	for url in https://api6.ipify.org https://ipv6.icanhazip.com; do
		ip="$(download "$url" - 2>/dev/null | tr -d ' \r\n' || true)"
		if printf '%s' "$ip" | grep -Eq '^[0-9a-fA-F:]+:[0-9a-fA-F:]+$'; then printf '%s' "$ip"; return; fi
	done
}

dashboard_url() {
	if [ -n "$DOMAIN" ]; then
		printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$' ||
			die "--domain must be a lowercase host name like dash.example.com"
		printf 'https://%s' "$DOMAIN"
		return
	fi
	existing="$(env_get APP_URL)"
	if [ -n "$existing" ]; then printf '%s' "$existing"; return; fi
	[ "$DETECT_IP" = 1 ] || die "pass --domain HOST (public IP detection is off)"
	ip="$(detect_public_ip)"
	[ -n "$ip" ] || die "could not detect this server's public IP. Pass --domain HOST."
	# sslip.io resolves 1-2-3-4.sslip.io to 1.2.3.4 (and dashed IPv6 likewise).
	printf 'https://%s.sslip.io' "$(printf '%s' "$ip" | tr ':.' '--')"
}

# Before D27 launchroomd was "the agent": its data lived in data/agent and its
# compose service was called agent. Stop that container and move the data
# (keys, enrollment, state), so the server keeps its identity.
migrate_legacy_daemon_dir() {
	if [ ! -d "$DIR/data/agent" ] || [ -e "$DIR/data/daemon" ]; then
		return 0
	fi
	say "moving data/agent to data/daemon (launchroomd is the new name of the launchroom agent)"
	if [ -f "$DIR/compose.yml" ]; then
		(cd "$DIR" && docker compose rm --stop --force agent) >/dev/null 2>&1 || true
	fi
	mv "$DIR/data/agent" "$DIR/data/daemon"
	if [ -f "$DIR/.env" ]; then
		{ grep -v '^LR_AGENT_IMAGE=' "$DIR/.env" || true; } >"$DIR/.env.tmp"
		mv "$DIR/.env.tmp" "$DIR/.env"
	fi
}

prepare_folder() {
	migrate_legacy_daemon_dir
	mkdir -p "$DIR/data/keys" "$DIR/data/postgres" "$DIR/data/valkey" "$DIR/data/zot/storage" \
		"$DIR/data/daemon" "$DIR/data/backups" "$DIR/data/transfers" "$DIR/data/headscale/lib"
	cp "$BUNDLE/control-plane/compose.yml" "$DIR/compose.yml"
	cp "$BUNDLE/control-plane/launchroom" "$DIR/launchroom"
	chmod 0700 "$DIR/launchroom"

	if [ ! -f "$DIR/.env" ]; then
		say "generating secrets into $DIR/.env"
		cp "$BUNDLE/control-plane/.env.example" "$DIR/.env"
		env_set APP_KEY "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
		env_set DB_PASSWORD "$(rand_alnum 40)"
		env_set VALKEY_PASSWORD "$(rand_alnum 40)"
		env_set REVERB_APP_ID "$(rand_alnum 12)"
		env_set REVERB_APP_KEY "$(rand_alnum 32)"
		env_set REVERB_APP_SECRET "$(rand_alnum 48)"
	fi

	apply_url "$(dashboard_url)"
	env_set LR_HOST_TIMEZONE "$(host_timezone)"
	# --unstable also offers pre-releases as updates (Settings → Updates can change it).
	[ "$UNSTABLE" = 0 ] || env_set LR_UPDATES_CHANNEL beta
	# --no-public-ip-detect also stops launchroomd's own detection (echo
	# services); LR_PUBLIC_IPS in .env then names the addresses. Kept on a
	# re-run without the flag. Updates pass the flag for the installer only
	# (LR_BUNDLE_VERIFIED=1) and change nothing here.
	if [ "$DETECT_IP" = 0 ] && [ "${LR_BUNDLE_VERIFIED:-}" != 1 ]; then
		env_set LR_PUBLIC_IP_DETECT off
	fi

	# Image pins of this bundle, overridable from the environment.
	grep -E '^LR_[A-Z_]+_IMAGE=' "$BUNDLE/control-plane/images.env" | while IFS= read -r line; do
		key="${line%%=*}" value="${line#*=}"
		eval "override=\${$key:-}"
		[ -z "$override" ] || value="$override"
		case "$value" in *@sha256:*) ;; *) warn "$key=$value is not pinned by digest" ;; esac
		env_set "$key" "$value"
	done

	write_trust "$DIR/data/daemon/trust.json"
	write_guard_trust "$DIR/data/daemon/trust.json"

	if [ -n "$KIT" ]; then
		[ -r "$KIT" ] || die "cannot read the recovery kit $KIT"
		trap cleanup_restore EXIT
		trap 'exit 130' INT TERM HUP
		cp "$KIT" "$DIR/data/backups/recovery-kit"
	fi
}

# cleanup_restore: the restore's plaintext material (the recovery kit with
# the S3 keys and repository password, the restored KEK, signing keys and
# APP_KEY) never outlives the installer, also when it fails (2026-10 review
# L6). Runs on every exit while a restore is in progress.
cleanup_restore() {
	rm -f "$DIR/.restored.env"
	rm -rf "$DIR/data/backups/restore" "$DIR/data/backups/recovery-kit" 2>/dev/null ||
		dc run --rm --no-deps --user 0 --entrypoint rm dashboard -rf /data/backups/restore /data/backups/recovery-kit >/dev/null 2>&1 ||
		warn "could not remove $DIR/data/backups/restore and $DIR/data/backups/recovery-kit; delete them by hand (they hold plaintext keys)"
}

# write_trust FILE: pins the release identity launchroomd's self-updates are
# verified against (docs/09 R30, docs/11 §4). Written once: re-running the
# installer (also from an update) never replaces it, so a later bundle
# cannot change whom future updates must be signed by.
write_trust() {
	[ ! -e "$1" ] || return 0
	printf '{\n  "issuer": "%s",\n  "identity": "%s",\n  "releaseBase": "%s"\n}\n' \
		"$SIGNER_ISSUER" "$SIGNER_IDENTITY" "$RELEASE_BASE" >"$1"
	chmod 0644 "$1"
}

# write_guard_trust FROM: the same pin in guard/trust.json, outside
# launchroomd's data folder (D49): the update supervisor verifies the release
# again against this copy, which launchroomd cannot change. Written once
# (copied from FROM, the pin launchroomd uses).
write_guard_trust() {
	[ ! -e "$DIR/guard/trust.json" ] || return 0
	mkdir -p "$DIR/guard"
	cp "$1" "$DIR/guard/trust.json"
	chmod 0644 "$DIR/guard/trust.json"
}

# apply_url URL: APP_URL, the session cookie flag and the registry's token
# realm (docs/05 §6), which is the dashboard.
apply_url() {
	printf '%s' "$1" | grep -Eq '^https?://[][A-Za-z0-9.:-]+$' || die "invalid dashboard URL: $1"
	env_set APP_URL "$1"
	case "$1" in http://*) env_set SESSION_SECURE_COOKIE false ;; *) env_set SESSION_SECURE_COOKIE true ;; esac
	# Zot's config: the template only for a new install; afterwards the
	# dashboard renders it (registry settings: local disk or S3, docs/14
	# §9.11), see render_registry_config.
	if [ ! -s "$DIR/data/zot/config.json" ]; then
		sed "s#__LAUNCHROOM_URL__#$1#g" "$BUNDLE/images/zot/config.json" >"$DIR/data/zot/config.json"
		chmod 0600 "$DIR/data/zot/config.json"
	fi
	# The mesh's coordination server is the dashboard's origin (docs/05 §2).
	sed "s#__LAUNCHROOM_URL__#$1#g" "$BUNDLE/images/headscale/config.yaml" >"$DIR/data/headscale/config.yaml"
	chmod 0644 "$DIR/data/headscale/config.yaml"
}

# ---- Move (docs/14 §13.4) --------------------------------------------------------

# A move goes into a fresh folder, or continues one it started (its state in
# data/backups/move). It never overwrites a running install.
check_move_target() {
	[ -n "${LR_MOVE_TOKEN:-}" ] || die "--move needs the token from Settings → Move launchroom in the old dashboard"
	[ "$RESTORE" = 0 ] || die "--move and --restore can't be combined"
	if [ -f "$DIR/.env" ] && [ ! -f "$DIR/data/backups/move/token" ] && [ ! -f "$DIR/.move" ]; then
		die "$DIR already holds a launchroom install. A move needs a new server (or an empty --dir)."
	fi
	: >"$DIR/.move"
}

# receive ARGS...: launchroom:move:receive in a one-off dashboard container
# (the token by environment, never argv), output (JSON) on stdout.
receive() {
	set -- dashboard php artisan launchroom:move:receive "$@" --json
	[ -z "$MOVE_URL" ] || set -- "$@" --url="$MOVE_URL"
	LR_MOVE_HOSTNAME="${LR_MOVE_HOSTNAME:-$(hostname 2>/dev/null || echo new-server)}"
	export LR_MOVE_HOSTNAME
	dc run --rm -T -e LR_MOVE_TOKEN -e LR_MOVE_HOSTNAME "$@"
}

move_state_file() { dc run --rm --no-deps --user 0 --entrypoint sh dashboard -c "[ ! -f /data/backups/move/$1 ] || cat /data/backups/move/$1"; }

# move_join: claims the move (both sides show a code the owner compares),
# then enrolls this server's launchroomd with the OLD dashboard as a regular
# server, so every app, database and volume can move here first.
move_join() {
	say "connecting to the old dashboard"
	out="$(receive --join)" || die "could not join the move (see above). Run the same command again to retry."
	url="$(printf '%s' "$out" | json_field url)"
	[ -n "$url" ] || die "the old dashboard's address is unknown"
	key="$(move_state_file cp-key)"
	[ -n "$key" ] || die "the old dashboard did not name its signing key"
	printf '%s\n' "$key" >"$DIR/data/daemon/cp-key"
	token="$(move_state_file join-token)"
	env_set LR_CONTROL_PLANE_URL "$url"
	if [ -n "$token" ] && [ ! -f "$DIR/data/daemon/enrollment.json" ]; then
		printf '%s\n' "$token" >"$DIR/data/daemon/join-token"
		env_set LR_JOIN_TOKEN_FILE /data/join-token
		say "starting launchroomd for $url"
		dc up -d launchroomd
		i=0
		until [ -f "$DIR/data/daemon/enrollment.json" ]; do
			i=$((i + 1))
			[ "$i" -le 90 ] || die "launchroomd did not enroll with the old dashboard within 3 minutes. Check ./launchroom logs launchroomd"
			sleep 2
		done
		rm -f "$DIR/data/daemon/join-token"
		dc run --rm --no-deps --user 0 --entrypoint rm dashboard -f /data/backups/move/join-token
	fi
	env_set LR_JOIN_TOKEN_FILE ""
	dc up -d launchroomd
	say "this server joined launchroom at $url."
}

# move_handover: waits (the apps and data move meanwhile, driven from the old
# dashboard), then restores the old dashboard's final dump and keys here.
move_handover() {
	echo
	say "now continue in the old dashboard: Settings → Move launchroom. Keep this running;"
	say "it finishes the move when you hand over (run the same command again if it stops)."
	receive --handover --force >/dev/null || {
		receive --abort="the new server could not restore the hand-over" >/dev/null 2>&1 || true
		die "the hand-over failed (see above). The old dashboard takes changes again; run the same command to try again."
	}
	say "the old dashboard's data and keys are here"
}

# move_take_over: this server becomes the control plane. launchroomd stops
# talking to the old dashboard (its address and the origin it may have
# adopted from there are dropped) and keeps its identity, which is in the
# restored database.
move_take_over() {
	dc stop launchroomd >/dev/null 2>&1 || true
	rm -f "$DIR/data/daemon/control-plane.json"
	env_set LR_CONTROL_PLANE_URL ""
	install_restored_files /data/backups/move/restore
	start_stack
	say "taking over as the control plane"
	artisan launchroom:move:takeover --json >/dev/null || die "the takeover failed (see ./launchroom logs dashboard). Fix it, then run $DIR/launchroom move-takeover"
	enroll_local_daemon
	i=0
	until receive --complete="$(dashboard_address)" >/dev/null 2>&1; do
		i=$((i + 1))
		if [ "$i" -ge 5 ]; then
			warn "could not tell the old dashboard that the move finished. It stays frozen; delete the old server once DNS points here."
			break
		fi
		sleep 3
	done
	dc run --rm --no-deps --user 0 --entrypoint rm dashboard -rf /data/backups/move
	rm -f "$DIR/.move"
}

move_control_plane() {
	trap 'dc run --rm --no-deps --user 0 --entrypoint rm dashboard -rf /data/backups/move/restore >/dev/null 2>&1 || true' EXIT
	trap 'exit 130' INT TERM HUP
	move_join
	move_handover
	move_take_over
	trap - EXIT INT TERM HUP
}

# ---- Stack -------------------------------------------------------------------------

dc() { (cd "$DIR" && docker compose "$@"); }
artisan() { dc exec -T dashboard php artisan "$@"; }

start_databases() {
	say "starting postgres and valkey"
	# The images' own users must be able to enter their bind-mounted dirs
	# (the folder is created with umask 077).
	dc run --rm --no-deps --quiet-pull --user 0 --entrypoint sh postgres -c \
		"chown postgres:postgres /var/lib/postgresql && chmod 0700 /var/lib/postgresql" >/dev/null
	dc up -d --wait --wait-timeout 300 --quiet-pull postgres valkey
	# data/keys, data/backups and data/transfers (import and download
	# chunks, docs/14 §12.1) belong to the dashboard image's user.
	dc run --rm --no-deps --user 0 --entrypoint sh dashboard -c \
		"chown -R $DASHBOARD_UID:$DASHBOARD_UID /data/keys /data/backups /data/transfers && chmod 0700 /data/keys /data/backups /data/transfers &&
		chown $DASHBOARD_UID:$DASHBOARD_UID /data/zot/config.json && chmod 0600 /data/zot/config.json" >/dev/null
}

# docs/14 §5.2: restore the database, the key files and the .env secrets
# from a control-plane backup with the dashboard's `launchroom:restore`. It
# reads the destination and secrets from the recovery kit (--recovery-kit),
# LR_RESTORE_* / LR_RECOVERY_PASSPHRASE in the environment, or asks on the
# terminal; restores the database into the new Postgres; and writes
# keys/<files> plus restored.env to --keys-out.
restore_control_plane() {
	trap cleanup_restore EXIT
	trap 'exit 130' INT TERM HUP
	dc run --rm --no-deps dashboard php artisan list --raw 2>/dev/null | grep -q '^launchroom:restore ' ||
		die "this release cannot restore yet (no launchroom:restore command in the dashboard image)."
	say "restoring the control plane from a backup"
	set -- dashboard php artisan launchroom:restore --keys-out=/data/backups/restore
	[ -z "$KIT" ] || set -- "$@" --kit=/data/backups/recovery-kit
	[ "$RESTORE_HTTP" = 0 ] || set -- "$@" --allow-http
	# Secrets travel as environment variables (only their names in argv).
	for var in LR_RESTORE_ENDPOINT LR_RESTORE_BUCKET LR_RESTORE_PREFIX LR_RESTORE_REGION LR_RESTORE_ACCESS_KEY_ID \
		LR_RESTORE_SECRET_ACCESS_KEY LR_RESTORE_REPOSITORY_PASSWORD LR_RECOVERY_PASSPHRASE; do
		eval "given=\${$var:-}"
		[ -z "$given" ] || set -- -e "$var" "$@"
	done
	if has_tty; then
		dc run --rm "$@" <"$TTY"
	else
		dc run --rm -T "$@" --no-interaction
	fi || die "the restore failed; nothing else was started. Fix the problem and run the installer with --restore again."

	install_restored_files /data/backups/restore
	dc run --rm --no-deps --user 0 --entrypoint rm dashboard -rf /data/backups/restore /data/backups/recovery-kit
	trap - EXIT INT TERM HUP
}

# install_restored_files DIR: puts what `launchroom:restore` or
# `launchroom:move:receive` wrote to DIR (inside the dashboard container) in
# place: key files into data/keys, the restored secrets into .env (except
# the connection passwords of this new stack), APP_URL (restored_app_url)
# and Headscale's state.
install_restored_files() {
	from="$1"
	# Key files into data/keys (owned by the dashboard user).
	dc run --rm --no-deps --user 0 --entrypoint sh dashboard -c \
		"cp -a $from/keys/. /data/keys/ && chown -R $DASHBOARD_UID:$DASHBOARD_UID /data/keys"
	dc run --rm --no-deps --user 0 --entrypoint cat dashboard "$from/restored.env" >"$DIR/.restored.env"
	while IFS= read -r line; do
		key="${line%%=*}" value="${line#*=}"
		case "$key" in
		APP_KEY | APP_PREVIOUS_KEYS | REVERB_APP_ID | REVERB_APP_KEY | REVERB_APP_SECRET) env_set "$key" "$value" ;;
		APP_URL) [ -n "$DOMAIN" ] || apply_url "$(restored_app_url "$value")" ;;
		esac
	done <"$DIR/.restored.env"
	rm -f "$DIR/.restored.env"
	# Headscale's state (docs/14 §9.6), when there is one: the mesh keeps its
	# identity and nodes, so every server's mesh client reconnects. It goes
	# into data/headscale/lib before Headscale starts (stale database files
	# of an earlier attempt are replaced, never mixed).
	dc stop headscale >/dev/null 2>&1 || true
	dc run --rm --no-deps --user 0 -v "$DIR/data/headscale/lib:/headscale" --entrypoint sh dashboard -c \
		"[ -f $from/headscale/noise_private.key ] || exit 0
		rm -f /headscale/db.sqlite /headscale/db.sqlite-wal /headscale/db.sqlite-shm
		cp $from/headscale/* /headscale/ && chown 0:$DASHBOARD_UID /headscale/* && chmod 0640 /headscale/*" ||
		die "could not put the restored mesh state in place"
}

# restored_app_url URL: the dashboard address after a restore or a move. A
# restore keeps the old address (the server it belonged to is gone). A move
# keeps it when it is a domain of your own (you point it here); an address
# tied to the old server's IP (an IP, or <ip>.sslip.io) becomes this
# server's (docs/14 §13.4).
restored_app_url() {
	host="$(printf '%s' "$1" | sed -e 's#^[a-z]*://##' -e 's#^\[##' -e 's#\].*##' -e 's#[:/].*##')"
	if [ "$MOVE" = 1 ]; then
		case "$host" in
		*.sslip.io) dashboard_url; return ;;
		esac
		if printf '%s' "$host" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$|^[0-9a-fA-F:]+:[0-9a-fA-F:]*$'; then
			dashboard_url
			return
		fi
	fi
	printf '%s' "$1"
}

# Headscale's key files are 0600 root and its folder is created 0700 (umask
# 077); control-plane backups (run as the dashboard user) read the keys and
# the database through a read-only mount (docs/14 §9.6), so the dashboard's
# group may enter the folder and read them. Idempotent.
share_headscale_state() {
	dc run --rm --no-deps --user 0 -v "$DIR/data/headscale/lib:/headscale" --entrypoint sh dashboard -c \
		"chgrp $DASHBOARD_UID /headscale && chmod 0750 /headscale &&
		for f in /headscale/noise_private.key /headscale/derp_server_private.key /headscale/db.sqlite /headscale/db.sqlite-wal; do
			[ ! -f \"\$f\" ] || { chgrp $DASHBOARD_UID \"\$f\" && chmod g+r,o-rwx \"\$f\"; }
		done" >/dev/null || warn "could not share the mesh state with the dashboard; control-plane backups will fail once the mesh is on"
}

start_stack() {
	say "creating keys (kept if they exist)"
	dc run --rm dashboard php artisan launchroom:keys:generate --no-interaction >/dev/null
	say "starting the control plane (first start runs database migrations)"
	dc up -d --wait --wait-timeout 600 --quiet-pull --remove-orphans dashboard horizon reverb scheduler zot headscale postgres valkey
	apply_domain
	render_registry_config
	share_headscale_state
	bootstrap_mesh_key
}

# --domain wins over Settings → Dashboard domain (docs/05 §4.1): when the
# dashboard was moved to another domain in the UI, an explicit --domain makes
# APP_URL canonical again (the earlier address keeps forwarding).
apply_domain() {
	[ -n "$DOMAIN" ] || return 0
	artisan list --raw 2>/dev/null | grep -q '^launchroom:dashboard-domain:reset ' || return 0
	current="$(artisan launchroom:dashboard-url 2>/dev/null | tail -n 1)"
	[ "$current" != "$(env_get APP_URL)" ] || return 0
	say "making $(env_get APP_URL) the dashboard address again (was $current)"
	artisan launchroom:dashboard-domain:reset --force >/dev/null || warn "could not reset the dashboard domain; run $DIR/launchroom dashboard-domain-reset"
}

# dashboard_address: the canonical address (Settings → Dashboard domain, else APP_URL).
dashboard_address() {
	url="$(artisan launchroom:dashboard-url 2>/dev/null | tail -n 1)" || url=""
	case "$url" in http://* | https://*) printf '%s' "$url" ;; *) env_get APP_URL ;; esac
}

# Zot's config from the registry settings (docs/14 §9.11): the dashboard
# writes data/zot/config.json (current URL as token realm; S3 storage when
# configured, which also brings a restored control plane's images back);
# zot restarts only when the file changed.
render_registry_config() {
	artisan list --raw 2>/dev/null | grep -q '^launchroom:registry:config ' || return 0
	out="$(artisan launchroom:registry:config --write)" ||
		{ warn "could not write the registry config; run $DIR/launchroom registry apply"; return 0; }
	[ "$(printf '%s\n' "$out" | tail -n 1)" = changed ] || return 0
	say "restarting the registry with its new config"
	dc restart zot >/dev/null
}

# The first Headscale API key can only be created inside its container. It
# goes straight to the dashboard over stdin (never argv, never printed),
# which replaces it with its own envelope-encrypted key when the mesh turns
# on (docs/05 §2). Only while the dashboard has none.
bootstrap_mesh_key() {
	artisan launchroom:mesh:bootstrap-key --check >/dev/null 2>&1 || return 0
	say "handing the dashboard its first mesh key"
	dc exec -T headscale headscale apikeys create --expiration 87600h 2>/dev/null | tail -n 1 |
		artisan launchroom:mesh:bootstrap-key >/dev/null || warn "could not create the first mesh key; the mesh can't be turned on until the installer runs again"
}

json_field() { sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p"; }

# Enrolls the control-plane host's own daemon (docs/03 §3) with a token the
# dashboard issues; the token only ever travels through a 0600 file.
enroll_local_daemon() {
	# After a restore the old control-plane server is dead: enroll this one and
	# open the dashboard in recovery mode, which replaces it (docs/14 §5.2).
	if [ "$RESTORE" = 1 ]; then
		out="$(artisan launchroom:servers:bootstrap-local --force --recovery)" || die "could not create the local launchroomd's enrollment: $out"
	else
		out="$(artisan launchroom:servers:bootstrap-local)" || die "could not create the local launchroomd's enrollment: $out"
	fi
	server="$(printf '%s' "$out" | json_field serverId)"
	if [ -n "$server" ] && [ ! -f "$DIR/data/daemon/enrollment.json" ]; then
		# The DB knows an enrolled daemon but its identity on disk is gone.
		say "the local launchroomd lost its identity; re-enrolling it"
		out="$(artisan launchroom:servers:bootstrap-local --force)" || die "could not re-enroll the local launchroomd: $out"
	fi
	key="$(printf '%s' "$out" | json_field cpKey)"
	token="$(printf '%s' "$out" | json_field token)"
	[ -n "$key" ] || die "the dashboard did not return its signing key"
	printf '%s\n' "$key" >"$DIR/data/daemon/cp-key"

	if [ -z "$token" ]; then
		dc up -d launchroomd
		return
	fi
	printf '%s\n' "$token" >"$DIR/data/daemon/join-token"
	env_set LR_JOIN_TOKEN_FILE /data/join-token
	say "starting the local launchroomd"
	dc up -d launchroomd
	i=0
	until [ -f "$DIR/data/daemon/enrollment.json" ]; do
		i=$((i + 1))
		[ "$i" -le 90 ] || die "the local launchroomd did not enroll within 3 minutes. Check ./launchroom logs launchroomd"
		sleep 2
	done
	# Used up: remove the token and restart launchroomd without it.
	rm -f "$DIR/data/daemon/join-token"
	env_set LR_JOIN_TOKEN_FILE ""
	dc up -d launchroomd
}

finish() {
	url="$(dashboard_address)"
	echo
	say "the control plane is running."
	echo "  Dashboard:  $url"
	if token="$(artisan launchroom:setup-token --plain 2>/dev/null)"; then
		echo "  Create the owner account (one-time link, valid for 60 minutes):"
		echo "    $url/setup?token=$token"
		echo "  Expired? Run: $DIR/launchroom setup-token"
	else
		echo "  Setup is already complete: sign in at $url"
	fi
	if [ "$RESTORE" = 1 ]; then
		echo "  Restored from a backup: sign in as an instance owner and follow the recovery"
		echo "  checklist ($url/recovery) to replace the old server and restore your data."
	fi
	if [ "$MOVE" = 1 ]; then
		host="$(printf '%s' "$url" | sed -e 's#^[a-z]*://##' -e 's#[:/].*##')"
		ip="$(detect_public_ip 2>/dev/null || true)"
		echo "  launchroom moved here. Point $host${ip:+ (A/AAAA) to $ip} if it isn't yet, then sign"
		echo "  in and open the checklist ($url/settings/move): DNS, images, the old server."
	fi
	echo
	echo "  Back up $DIR/.env and $DIR/data/keys/ now: they decrypt every secret."
	echo "  Helper: $DIR/launchroom status | logs | update | backup | uninstall"
}

main() {
	parse_args "$@"
	umask 077
	check_prerequisites
	check_inotify
	check_buildkit_apparmor
	check_swap
	mkdir -p "$DIR"
	DIR="$(cd "$DIR" && pwd)"
	[ "$MOVE" = 0 ] || check_move_target
	fetch_bundle
	prepare_folder
	start_databases
	if [ "$MOVE" = 1 ]; then
		move_control_plane
	else
		[ "$RESTORE" = 0 ] || restore_control_plane
		start_stack
		enroll_local_daemon
	fi
	rm -rf "$DIR/.tmp"
	finish
}

main "$@"
