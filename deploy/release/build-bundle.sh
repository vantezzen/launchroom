#!/bin/sh
# Builds the release bundle launchroom-deploy.tar.gz (docs/11 §2, docs/09 R30).
#
#   deploy/release/build-bundle.sh --version 1.2.3 \
#     --dashboard-image ghcr.io/vantezzen/launchroom-dashboard@sha256:<64 hex> \
#     --daemon-image ghcr.io/vantezzen/launchroomd@sha256:<64 hex> \
#     --caddy-image ghcr.io/vantezzen/launchroom-caddy@sha256:<64 hex> \
#     --out DIR
#
# Run from anywhere; the bundle is assembled from the repo's deploy/ folder.
# Writes into DIR:
#   launchroom-deploy.tar.gz         the bundle (no top-level folder)
#   launchroom-deploy.tar.gz.sha256  "<hex>  launchroom-deploy.tar.gz"
#   images.env                       copy of the bundle's control-plane/images.env
#
# Bundle layout: VERSION, control-plane/{compose.yml,images.env,.env.example,
# launchroom}, daemon/compose.yml, install/{install.sh,server.sh},
# images/zot/config.json, images/headscale/config.yaml.
#
# control-plane/images.env is deploy/control-plane/images.env with the
# launchroom images replaced by the given digests; third-party pins are kept.
# The caddy image must be the one launchroomd was built with (CADDY_DIGEST,
# daemon/internal/policy): servers refuse any other edge Caddy.
# Every LR_*_IMAGE line must be digest-pinned afterwards, or this fails.
#
# Deterministic: sorted entries, mtime = $SOURCE_DATE_EPOCH (default: the
# commit time of HEAD, else 0), numeric owner 0:0, fixed modes, `gzip -n`.
# Needs GNU tar (--sort, --mtime, --owner) and sha256sum. Signing
# (cosign sign-blob) happens in .github/workflows/release.yml, not here.
set -eu

die() { printf 'build-bundle: error: %s\n' "$*" >&2; exit 1; }

VERSION=""
DASHBOARD_IMAGE=""
DAEMON_IMAGE=""
CADDY_IMAGE=""
OUT=""

while [ $# -gt 0 ]; do
	case "$1" in
	--version) VERSION="${2:?--version needs a value}"; shift ;;
	--dashboard-image) DASHBOARD_IMAGE="${2:?--dashboard-image needs a value}"; shift ;;
	--daemon-image) DAEMON_IMAGE="${2:?--daemon-image needs a value}"; shift ;;
	--caddy-image) CADDY_IMAGE="${2:?--caddy-image needs a value}"; shift ;;
	--out) OUT="${2:?--out needs a value}"; shift ;;
	-h | --help) sed -n '2,/^set -eu$/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
	*) die "unknown option $1" ;;
	esac
	shift
done

[ -n "$VERSION" ] && [ -n "$DASHBOARD_IMAGE" ] && [ -n "$DAEMON_IMAGE" ] && [ -n "$CADDY_IMAGE" ] && [ -n "$OUT" ] ||
	die "--version, --dashboard-image, --daemon-image, --caddy-image and --out are required"
printf '%s' "$VERSION" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$' ||
	die "--version must be a semantic version without a v prefix (got $VERSION)"

pinned() { printf '%s' "$1" | grep -Eq '^[a-z0-9.-]+(:[0-9]+)?/[a-z0-9._/-]+@sha256:[0-9a-f]{64}$'; }
for image in "$DASHBOARD_IMAGE" "$DAEMON_IMAGE" "$CADDY_IMAGE"; do
	pinned "$image" || die "$image is not an image reference pinned by digest (<repo>@sha256:<64 hex>)"
done

command -v sha256sum >/dev/null 2>&1 || die "sha256sum is required"
tar --version 2>/dev/null | grep -q 'GNU tar' || die "GNU tar is required (--sort/--mtime/--owner)"

DEPLOY="$(cd "$(dirname "$0")/.." && pwd)"
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
	SOURCE_DATE_EPOCH="$(git -C "$DEPLOY" log -1 --format=%ct 2>/dev/null || echo 0)"
fi
printf '%s' "$SOURCE_DATE_EPOCH" | grep -Eq '^[0-9]+$' || die "SOURCE_DATE_EPOCH must be a unix timestamp"

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT INT TERM

mkdir -p "$stage/control-plane" "$stage/daemon" "$stage/install" "$stage/images/zot" "$stage/images/headscale"
printf '%s\n' "$VERSION" >"$stage/VERSION"
cp "$DEPLOY/control-plane/compose.yml" "$DEPLOY/control-plane/.env.example" "$DEPLOY/control-plane/launchroom" "$stage/control-plane/"
cp "$DEPLOY/daemon/compose.yml" "$stage/daemon/"
cp "$DEPLOY/install/install.sh" "$DEPLOY/install/server.sh" "$stage/install/"
cp "$DEPLOY/images/zot/config.json" "$stage/images/zot/"
cp "$DEPLOY/images/headscale/config.yaml" "$stage/images/headscale/"

# images.env: replace the launchroom pins, keep everything else verbatim.
awk -v dashboard="$DASHBOARD_IMAGE" -v daemon="$DAEMON_IMAGE" -v caddy="$CADDY_IMAGE" '
	/^LR_DASHBOARD_IMAGE=/ { print "LR_DASHBOARD_IMAGE=" dashboard; next }
	/^LR_DAEMON_IMAGE=/ { print "LR_DAEMON_IMAGE=" daemon; next }
	/^LR_CADDY_IMAGE=/ { print "LR_CADDY_IMAGE=" caddy; next }
	{ print }
' "$DEPLOY/control-plane/images.env" >"$stage/control-plane/images.env"

grep -q "^LR_DASHBOARD_IMAGE=$DASHBOARD_IMAGE\$" "$stage/control-plane/images.env" || die "images.env has no LR_DASHBOARD_IMAGE line"
grep -q "^LR_DAEMON_IMAGE=$DAEMON_IMAGE\$" "$stage/control-plane/images.env" || die "images.env has no LR_DAEMON_IMAGE line"
grep -q "^LR_CADDY_IMAGE=$CADDY_IMAGE\$" "$stage/control-plane/images.env" || die "images.env has no LR_CADDY_IMAGE line"
unpinned="$(grep -E '^LR_[A-Z_]+_IMAGE=' "$stage/control-plane/images.env" | grep -Ev '@sha256:[0-9a-f]{64}$' || true)"
[ -z "$unpinned" ] || die "images not pinned by digest: $unpinned"

# Fixed modes: the helper and the scripts are executable, the rest is not.
find "$stage" -type d -exec chmod 0755 {} +
find "$stage" -type f -exec chmod 0644 {} +
chmod 0755 "$stage/control-plane/launchroom" "$stage/install/install.sh" "$stage/install/server.sh"

tar --sort=name --format=gnu --mtime="@$SOURCE_DATE_EPOCH" --owner=0 --group=0 --numeric-owner \
	-C "$stage" -cf - VERSION control-plane daemon install images |
	gzip -9 -n >"$OUT/launchroom-deploy.tar.gz"
(cd "$OUT" && sha256sum launchroom-deploy.tar.gz >launchroom-deploy.tar.gz.sha256)
cp "$stage/control-plane/images.env" "$OUT/images.env"

printf 'build-bundle: %s/launchroom-deploy.tar.gz (%s)\n' "$OUT" "$(cut -d' ' -f1 "$OUT/launchroom-deploy.tar.gz.sha256")"
