#!/bin/sh
# launchroom CLI installer (docs/16-api-and-cli.md §5.3).
#
#   curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh
#   curl -fsSL …/cli.sh | sh -s -- --version 0.4.0 --dir /usr/local/bin
#
# Options:
#   --version V        release to install (default: latest)
#   --dir DIR          where to put `launchroom` (default: ~/.local/bin)
#   -h, --help
#
# Downloads the archive for this OS/architecture, its release's checksum file
# and the checksum file's cosign signature from the GitHub release, checks the
# archive's SHA-256 and, when `cosign` is installed, verifies the signature
# against the exact release identity (a signature that is present but does not
# verify always stops the install). Nothing is installed outside --dir.
#
# Environment: LR_CLI_RELEASE_BASE overrides the download base (mirrors,
# tests): <base>/v<version>/<file>.

set -eu

REPO_URL="https://github.com/vantezzen/launchroom"
RELEASE_BASE="${LR_CLI_RELEASE_BASE:-$REPO_URL/releases/download}"
# Release identity (docs/09 R30): the same pin as install.sh and launchroomd.
SIGNER_IDENTITY="https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
SIGNER_ISSUER="https://token.actions.githubusercontent.com"

version="latest"
dir="${HOME:-}/.local/bin"

say() { printf '%s\n' "$*" >&2; }
die() { say "error: $*"; exit 1; }

usage() {
	sed -n '2,19p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//' >&2 || true
}

while [ $# -gt 0 ]; do
	case "$1" in
		--version) [ $# -ge 2 ] || die "--version needs a value"; version="$2"; shift 2 ;;
		--version=*) version="${1#*=}"; shift ;;
		--dir) [ $# -ge 2 ] || die "--dir needs a value"; dir="$2"; shift 2 ;;
		--dir=*) dir="${1#*=}"; shift ;;
		-h|--help) usage; exit 0 ;;
		*) die "unknown option: $1 (see --help)" ;;
	esac
done

[ -n "$dir" ] || die "no install directory (set --dir)"

# fetch URL FILE: download with curl or wget, no redirects to other schemes.
fetch() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --proto '=https' --tlsv1.2 -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -q --https-only -O "$2" "$1"
	else
		die "neither curl nor wget is installed"
	fi
}

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

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d ' ' -f 1
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | cut -d ' ' -f 1
	else
		die "neither sha256sum nor shasum is installed"
	fi
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
echo "$version" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$' \
	|| die "$version is not a release version"

archive="launchroom_${version}_${os}_${arch}.tar.gz"
sums="launchroom_${version}_SHA256SUMS"
base="$RELEASE_BASE/v$version"

tmp="$(mktemp -d 2>/dev/null || mktemp -d -t launchroom-cli)"
trap 'rm -rf "$tmp"' EXIT INT TERM

say "Downloading launchroom $version ($os/$arch)…"
fetch "$base/$archive" "$tmp/$archive" || die "could not download $base/$archive"
fetch "$base/$sums" "$tmp/$sums" || die "could not download $base/$sums"

if fetch "$base/$sums.sigstore.json" "$tmp/$sums.sigstore.json" 2>/dev/null; then
	if command -v cosign >/dev/null 2>&1; then
		cosign verify-blob \
			--bundle "$tmp/$sums.sigstore.json" \
			--certificate-identity "$SIGNER_IDENTITY$version" \
			--certificate-oidc-issuer "$SIGNER_ISSUER" \
			"$tmp/$sums" >/dev/null 2>&1 \
			|| die "the checksum file's signature does NOT verify for $SIGNER_IDENTITY$version. Not installing."
		say "Signature verified: $SIGNER_IDENTITY$version"
	else
		say "note: cosign is not installed, so only the checksum was verified (install cosign to verify the release signature too)."
	fi
else
	say "warning: this release has no signature for its checksum file; only the checksum was verified."
fi

expected="$(awk -v f="$archive" '$2 == f || $2 == "*"f { print $1 }' "$tmp/$sums")"
[ -n "$expected" ] || die "$archive is not listed in $sums"
actual="$(sha256_of "$tmp/$archive")"
[ "$expected" = "$actual" ] || die "checksum mismatch for $archive (expected $expected, got $actual)"

tar -xzf "$tmp/$archive" -C "$tmp" launchroom || die "the archive has no launchroom binary"
mkdir -p "$dir"
# Replace atomically: write next to the target, then rename.
cp "$tmp/launchroom" "$dir/.launchroom.new"
chmod 0755 "$dir/.launchroom.new"
mv -f "$dir/.launchroom.new" "$dir/launchroom"

say "Installed $dir/launchroom ($("$dir/launchroom" --version 2>/dev/null || echo "launchroom $version"))."
case ":${PATH:-}:" in
	*":$dir:"*) ;;
	*) say "Add $dir to your PATH, e.g.: export PATH=\"$dir:\$PATH\"" ;;
esac
say "Next: launchroom login --url https://your-launchroom"
