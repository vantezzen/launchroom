#!/bin/sh
# launchroom CLI installer (docs/16-api-and-cli.md §5.3).
#
#   curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh
#   curl -fsSL …/cli.sh | sh -s -- --version 0.4.0 --dir /usr/local/bin
#
# Options:
#   --version V        release to install (default: latest)
#   --dir DIR          where to put `launchroom` (default: ~/.local/bin)
#   --allow-unsigned   install on the checksum alone when the release has no
#                      signature or neither cosign nor Docker is there to
#                      check it (development builds only; a signature that is
#                      present but does not verify always stops the install)
#   -h, --help
#
# Downloads the archive for this OS/architecture, its release's checksum file
# and the checksum file's cosign signature from the GitHub release, verifies
# the signature against the exact release identity (with `cosign` when it is
# installed, else with the digest-pinned cosign container through Docker),
# then the archive's SHA-256. Nothing is installed outside --dir.
#
# Environment: LAUNCHROOM_RELEASE_BASE overrides the release host (mirrors,
# tests): <base>/download/v<version>/<file>. LR_ALLOW_UNSIGNED=1 is
# --allow-unsigned.

set -eu

REPO_URL="https://github.com/vantezzen/launchroom"

version="latest"
dir="${HOME:-}/.local/bin"
allow_unsigned="${LR_ALLOW_UNSIGNED:-0}"

say() { printf '%s\n' "$*" >&2; }
die() { say "error: $*"; exit 1; }

# >>> lib/release.sh (generated from deploy/install/lib/release.sh by `make gen-installers`: edit that file)
# Release download and verification shared by install.sh, server.sh and
# cli.sh (docs/09 R30). Spliced into each by `make gen-installers`
# (deploy/install/splice.sh); release tooling (release.sh,
# deploy/release/build-bundle.sh) sources it. Expects die from the includer.

RELEASE_BASE="${LAUNCHROOM_RELEASE_BASE:-https://github.com/vantezzen/launchroom/releases}"
# cosign v3.0.2, multi-arch index digest. Run as a container: nothing to install.
COSIGN_IMAGE="ghcr.io/sigstore/cosign/cosign:v3.0.2@sha256:b29487e48205d875c324c79583e2806d9d269c0fa299e0861bbec023d8430c8b"
# Release identity (docs/09 R30, docs/11 §2): keyless cosign signatures made
# by .github/workflows/release.yml of vantezzen/launchroom on the tag
# v<version>, certified for GitHub Actions' OIDC issuer. The certificate
# must name exactly "$SIGNER_IDENTITY<version>". launchroomd pins the same
# identity for its self-updates (trust.json, daemon/internal/update).
SIGNER_IDENTITY="https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
SIGNER_ISSUER="https://token.actions.githubusercontent.com"

# is_semver V: MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD] without a leading v.
is_semver() {
	printf '%s' "$1" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
}

# is_release_version V: a version launchroom is released under (git tag
# v<V>, image tag <V>): semver without build metadata, which image tags
# cannot carry.
is_release_version() {
	is_semver "$1" || return 1
	case "$1" in *+*) return 1 ;; esac
}

# download URL FILE (FILE - for stdout): HTTPS only, so the release files
# never come over plain HTTP or a downgraded redirect.
download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		case "$1" in https://*) ;; *) die "refusing to download $1 (not https)" ;; esac
		wget -q -O "$2" "$1"
	else
		die "neither curl nor wget is installed"
	fi
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		die "neither sha256sum nor shasum is installed"
	fi
}

# cosign_verify DIR FILE VERSION: verifies DIR/FILE against its keyless
# signature DIR/FILE.sigstore.json for the exact release identity of
# VERSION, with the digest-pinned cosign container. Stops on any failure:
# a bad signature, no network to Sigstore (cosign fetches the trusted root
# through TUF) or a pull failure. Never reports success it did not check.
cosign_verify() {
	# umask 077 makes downloads 0600; the cosign image runs as a non-root
	# user. The file and its signature are public release files.
	chmod 0755 "$1" && chmod 0644 "$1/$2" "$1/$2.sigstore.json"
	had_image="$(docker image ls -q "$COSIGN_IMAGE" 2>/dev/null)"
	status=0
	cosign_out="$(docker run --rm --network bridge -v "$(cd "$1" && pwd):/work:ro" "$COSIGN_IMAGE" verify-blob \
		--bundle "/work/$2.sigstore.json" \
		--certificate-identity "$SIGNER_IDENTITY$3" \
		--certificate-oidc-issuer "$SIGNER_ISSUER" \
		"/work/$2" 2>&1)" || status=$?
	[ -n "$had_image" ] || docker image rm "$COSIGN_IMAGE" >/dev/null 2>&1 || true
	if [ "$status" != 0 ]; then
		printf '%s\n' "$cosign_out" | tail -n 8 | sed 's/^/  cosign: /' >&2
		die "the signature of $2 could not be verified for $SIGNER_IDENTITY$3 (cosign's output above). Not installing."
	fi
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

# write_guard_trust FROM: the same pin in $DIR/guard/trust.json, outside
# launchroomd's data folder (D49): the update supervisor verifies a release
# again against this copy, which launchroomd cannot change. Written once.
write_guard_trust() {
	[ ! -e "$DIR/guard/trust.json" ] || return 0
	mkdir -p "$DIR/guard"
	cp "$1" "$DIR/guard/trust.json"
	chmod 0644 "$DIR/guard/trust.json"
}
# <<< lib/release.sh

usage() {
	sed -n '2,/^set -eu$/p' "$0" 2>/dev/null | sed -e '$d' -e 's/^# \{0,1\}//' >&2 || true
}

while [ $# -gt 0 ]; do
	case "$1" in
		--version) [ $# -ge 2 ] || die "--version needs a value"; version="$2"; shift 2 ;;
		--version=*) version="${1#*=}"; shift ;;
		--dir) [ $# -ge 2 ] || die "--dir needs a value"; dir="$2"; shift 2 ;;
		--dir=*) dir="${1#*=}"; shift ;;
		--allow-unsigned) allow_unsigned=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) die "unknown option: $1 (see --help)" ;;
	esac
done

[ -n "$dir" ] || die "no install directory (set --dir)"

# latest_version: the tag GitHub's /releases/latest redirects to, without "v".
latest_version() {
	location=""
	if command -v curl >/dev/null 2>&1; then
		location="$(curl -fsSLI --proto '=https' -o /dev/null -w '%{url_effective}' "$REPO_URL/releases/latest")" || true
	elif command -v wget >/dev/null 2>&1; then
		location="$(wget -q -S --max-redirect=0 --spider "$REPO_URL/releases/latest" 2>&1 | sed -n 's/^ *[Ll]ocation: *//p' | tail -n 1 | tr -d '\r')" || true
	fi
	tag="${location##*/}"
	case "$tag" in
		v[0-9]*) printf '%s' "${tag#v}" ;;
		*) die "could not find the latest release (pass --version)" ;;
	esac
}

# verify_sums DIR FILE: FILE's keyless signature must name the release
# identity of $version exactly. Fails closed: without a signature, or
# without cosign or Docker to check it, only --allow-unsigned continues.
verify_sums() {
	if ! download "$base/$2.sigstore.json" "$1/$2.sigstore.json" 2>/dev/null; then
		[ "$allow_unsigned" = 1 ] || die "this release has no signature for its checksum file, so it cannot be verified. Pass --allow-unsigned to install on the checksum alone (development builds only)."
		say "warning: this release has no signature; continuing because of --allow-unsigned (checksum only)."
		return
	fi
	if command -v cosign >/dev/null 2>&1; then
		cosign verify-blob \
			--bundle "$1/$2.sigstore.json" \
			--certificate-identity "$SIGNER_IDENTITY$version" \
			--certificate-oidc-issuer "$SIGNER_ISSUER" \
			"$1/$2" >"$1/cosign.log" 2>&1 \
			|| { tail -n 8 "$1/cosign.log" | sed 's/^/  cosign: /' >&2; die "the checksum file's signature could not be verified for $SIGNER_IDENTITY$version (cosign's output above). Not installing."; }
	elif command -v docker >/dev/null 2>&1 && docker version >/dev/null 2>&1; then
		cosign_verify "$1" "$2" "$version"
	elif [ "$allow_unsigned" = 1 ]; then
		say "warning: neither cosign nor Docker is installed, so the signature was not checked; continuing because of --allow-unsigned (checksum only)."
		return
	else
		die "verifying the release signature needs cosign (https://docs.sigstore.dev/cosign/system_config/installation/) or Docker. Install one and run this again, or pass --allow-unsigned to install on the checksum alone."
	fi
	say "Signature verified: $SIGNER_IDENTITY$version"
}

os="$(uname -s)"
case "$os" in
	Linux) os=linux ;;
	Darwin) os=darwin ;;
	*) die "unsupported system $os: download the Windows build from $REPO_URL/releases" ;;
esac
arch="$(uname -m)"
case "$arch" in
	x86_64|amd64) arch=amd64 ;;
	aarch64|arm64) arch=arm64 ;;
	*) die "unsupported architecture $arch" ;;
esac

if [ "$version" = latest ]; then
	version="$(latest_version)"
fi
version="${version#v}"
is_release_version "$version" || die "$version is not a release version"

archive="launchroom_${version}_${os}_${arch}.tar.gz"
sums="launchroom_${version}_SHA256SUMS"
base="$RELEASE_BASE/download/v$version"

tmp="$(mktemp -d 2>/dev/null || mktemp -d -t launchroom-cli)"
trap 'rm -rf "$tmp"' EXIT INT TERM

say "Downloading launchroom $version ($os/$arch)…"
download "$base/$archive" "$tmp/$archive" || die "could not download $base/$archive"
download "$base/$sums" "$tmp/$sums" || die "could not download $base/$sums"
verify_sums "$tmp" "$sums"

expected="$(awk -v f="$archive" '$2 == f || $2 == "*"f { print $1 }' "$tmp/$sums")"
[ -n "$expected" ] || die "$archive is not listed in $sums"
actual="$(sha256_of "$tmp/$archive")"
[ "$expected" = "$actual" ] || die "checksum mismatch for $archive (expected $expected, got $actual)"

tar -xzf "$tmp/$archive" -C "$tmp" launchroom || die "the archive has no launchroom binary"
mkdir -p "$dir"
cp "$tmp/launchroom" "$dir/.launchroom.new"
chmod 0755 "$dir/.launchroom.new"
mv -f "$dir/.launchroom.new" "$dir/launchroom"

say "Installed $dir/launchroom ($("$dir/launchroom" --version 2>/dev/null || echo "launchroom $version"))."
case ":${PATH:-}:" in
	*":$dir:"*) ;;
	*) say "Add $dir to your PATH, e.g.: export PATH=\"$dir:\$PATH\"" ;;
esac
say "Next: launchroom login --url https://your-launchroom"
