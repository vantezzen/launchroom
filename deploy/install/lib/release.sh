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
