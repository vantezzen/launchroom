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
#   --no-public-ip-detect  never contact IP echo services (needs --domain)
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
# Nothing is written outside the install folder. Outside it, launchroom only
# creates Docker objects (containers, networks, images, volumes) labelled
# launchroom.managed=true.
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

# A terminal we can prompt on (also when the script itself comes from a pipe).
has_tty() { (: </dev/tty) 2>/dev/null; }
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

check_prerequisites() {
	[ "$(uname -s)" = "Linux" ] || die "the control plane runs on Linux (found $(uname -s)). Docker Desktop is only supported for build servers and development."
	for tool in awk sed tr head base64 grep; do
		command -v "$tool" >/dev/null 2>&1 || die "missing required tool: $tool"
	done
	command -v docker >/dev/null 2>&1 ||
		die "Docker is not installed. Install Docker Engine >= 24 with the compose plugin (https://docs.docker.com/engine/install/), then run this again."
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
		warn "this server has less than 2 GB of RAM. The control plane fits, but builds may run out of memory; consider a separate build server."
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
	had_image="$(docker image ls -q "$COSIGN_IMAGE" 2>/dev/null)"
	# Network: cosign fetches Sigstore's trusted root through TUF.
	docker run --rm --network bridge -v "$(cd "$1" && pwd):/work:ro" "$COSIGN_IMAGE" verify-blob \
		--bundle /work/bundle.tar.gz.sigstore.json \
		--certificate-identity "$SIGNER_IDENTITY$bundle_version" \
		--certificate-oidc-issuer "$SIGNER_ISSUER" \
		/work/bundle.tar.gz >/dev/null 2>&1 || status=$?
	[ -n "$had_image" ] || docker image rm "$COSIGN_IMAGE" >/dev/null 2>&1 || true
	[ "${status:-0}" = 0 ] || die "the bundle's cosign signature does NOT verify for $SIGNER_IDENTITY$bundle_version. Not installing."
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
		dc run --rm "$@" </dev/tty
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
