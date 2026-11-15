# Host preparation shared by install.sh and server.sh (docs/11 §2.1a):
# Docker, inotify limits, the BuildKit AppArmor profile and swap. Spliced
# into both scripts by `make gen-installers` (deploy/install/splice.sh).
# Expects say/warn/die, TTY, HOST_ROOT and the INSTALL_DOCKER,
# NO_INSTALL_DOCKER, RAISE_INOTIFY, ALLOW_BUILDKIT_USERNS and CREATE_SWAP
# switches from the including script.

# A terminal we can prompt on (also when the script itself comes from a pipe).
has_tty() { (: <"$TTY") 2>/dev/null; }

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
	created=0
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
		created=1
	else
		chmod 600 "$file"
	fi
	if ! swapon "$file" 2>/dev/null; then
		if [ "$created" = 1 ]; then
			# Some file systems can't hold a swap file; don't leave 2 GB behind.
			rm -f "$file"
			warn "could not turn on the new swap file $SWAP_FILE (the file system may not support swap files); removed it."
		else
			warn "could not turn on $SWAP_FILE; an existing file is never reformatted."
		fi
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
