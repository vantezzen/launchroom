#!/bin/sh
# Dev only: puts a pinned, checksum-verified restic binary at $1 (run inside
# the dev `go` container, fed on stdin by the Makefile). launchroomd finds it
# next to its own executable (internal/backup.LookupBinary), so server-1 and
# the integration tests need no image rebuild. Production images bundle the
# same version (daemon/Dockerfile).
set -eu
dst="$1"
version=0.19.1
arch="$(go env GOARCH)"
case "$arch" in
  amd64) sum=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c ;;
  arm64) sum=a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465 ;;
  *) echo "fetch-restic: unsupported arch $arch" >&2; exit 1 ;;
esac
if [ -x "$dst" ] && [ "$(cat "$dst.sha256" 2>/dev/null)" = "$sum" ]; then
  exit 0
fi
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
curl -fsSL -o "$tmp/restic.bz2" "https://github.com/restic/restic/releases/download/v${version}/restic_${version}_linux_${arch}.bz2"
echo "$sum  $tmp/restic.bz2" | sha256sum -c - >/dev/null
# No bunzip2 in the golang image; perl's core IO::Uncompress::Bunzip2 is.
perl -MIO::Uncompress::Bunzip2=bunzip2,\$Bunzip2Error -e 'bunzip2($ARGV[0] => $ARGV[1]) or die "bunzip2: $Bunzip2Error\n"' "$tmp/restic.bz2" "$tmp/restic"
chmod 0755 "$tmp/restic"
mv "$tmp/restic" "$dst"
echo "$sum" > "$dst.sha256"
echo "restic $version ($arch) installed at $dst"
