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
#
# Nothing is written outside the install folder (on macOS too: no launchd
# jobs; containers restart with the engine, which starts at login); Docker
# objects carry the label launchroom.managed=true. Re-running it with a new token re-enrolls
# only when launchroomd has not enrolled yet.
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
# macOS engine (docker-desktop, orbstack, colima), set by check_macos.
MAC_ENGINE=""
RELEASE_BASE="${LAUNCHROOM_RELEASE_BASE:-https://github.com/vantezzen/launchroom/releases}"
# Same verification as install.sh (docs/09 R30, docs/11 §2): cosign v3.0.2
# (digest-pinned container) and the exact release identity for the version.
COSIGN_IMAGE="ghcr.io/sigstore/cosign/cosign:v3.0.2@sha256:b29487e48205d875c324c79583e2806d9d269c0fa299e0861bbec023d8430c8b"
SIGNER_IDENTITY="https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
SIGNER_ISSUER="https://token.actions.githubusercontent.com"

# A terminal we can prompt on (also when the script itself comes from a pipe).
has_tty() { (: </dev/tty) 2>/dev/null; }
say() { printf 'launchroom: %s\n' "$*"; }
warn() { printf 'launchroom: warning: %s\n' "$*" >&2; }
die() { printf 'launchroom: error: %s\n' "$*" >&2; exit 1; }

# Placeholders survive only when the script is run from a bundle, not
# downloaded from a dashboard.
unfilled() { case "$1" in *__LAUNCHROOM_*) return 0 ;; *) return 1 ;; esac; }

MAC_ENGINES="OrbStack (orbstack.dev), Docker Desktop (docker.com/products/docker-desktop) or Colima (brew install colima docker docker-compose, then colima start)"

check_prerequisites() {
	case "$OS" in
	Linux)
		command -v docker >/dev/null 2>&1 || die "Docker is not installed. See https://docs.docker.com/engine/install/ (Docker Engine >= 24 with the compose plugin)."
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
		printf 'Join token (from Servers > Add server): ' >/dev/tty
		stty -echo </dev/tty 2>/dev/null || true
		IFS= read -r LR_JOIN_TOKEN </dev/tty || true
		stty echo </dev/tty 2>/dev/null || true
		printf '\n' >/dev/tty
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
		had_image="$(docker image ls -q "$COSIGN_IMAGE" 2>/dev/null)"
		docker run --rm --network bridge -v "$(cd "$tmp" && pwd):/work:ro" "$COSIGN_IMAGE" verify-blob \
			--bundle /work/bundle.tar.gz.sigstore.json \
			--certificate-identity "$SIGNER_IDENTITY$LR_RELEASE_VERSION" \
			--certificate-oidc-issuer "$SIGNER_ISSUER" \
			/work/bundle.tar.gz >/dev/null 2>&1 || status=$?
		[ -n "$had_image" ] || docker image rm "$COSIGN_IMAGE" >/dev/null 2>&1 || true
		[ "${status:-0}" = 0 ] || die "the bundle's cosign signature does NOT verify for $SIGNER_IDENTITY$LR_RELEASE_VERSION. Not installing."
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

parse_args() {
	while [ $# -gt 0 ]; do
		case "$1" in
		--rootless) ROOTLESS=1 ;;
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
}

main "$@"
