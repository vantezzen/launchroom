#!/bin/sh
# Builds launchroomd's binaries: launchroomd, dockerd-guard (D49) and the
# ambassador, installed as launchroom-ambassador (ambassadord.BinaryName).
# One recipe for the image (Dockerfile), the dev servers (make dev-daemon)
# and the integration tests (make test-daemon-integration).
#
#   sh build.sh OUT_DIR [VERSION [CADDY_DIGEST]]
#
# Run in daemon/ (the Go module); GOOS, GOARCH and CGO_ENABLED come from the
# environment. CADDY_DIGEST is the release's launchroom-caddy index digest,
# compiled into the system-image allow-list (internal/policy, docs/05 §4);
# empty, the build accepts only the stock Caddy. Each binary is renamed into
# place, so a running fake server restarts on a complete file.
set -eu

out="$1"
version="${2:-0.0.0-dev}"
caddy="${3:-}"
mkdir -p "$out"
for bin in launchroomd:launchroomd dockerd-guard:dockerd-guard ambassador:launchroom-ambassador; do
	name="${bin#*:}"
	go build -trimpath -buildvcs=false \
		-ldflags "-s -w -X main.version=$version -X github.com/vantezzen/launchroom/daemon/internal/policy.launchroomCaddyDigest=$caddy" \
		-o "$out/.$name.tmp" "./cmd/${bin%%:*}"
	mv "$out/.$name.tmp" "$out/$name"
done
