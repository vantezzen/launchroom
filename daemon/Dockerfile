# syntax=docker/dockerfile:1@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e
# launchroomd image: ghcr.io/vantezzen/launchroomd
#
# Build (from daemon/):  docker build -t ghcr.io/vantezzen/launchroomd:dev .
# Release:              --build-arg VERSION=1.2.3 --build-arg CADDY_DIGEST=sha256:<launchroom-caddy index digest>
# Multi-arch:           docker buildx build --platform linux/amd64,linux/arm64 ...
# The Go toolchain only runs in the build stage; hosts never need Go (docs/README D3).

# Base images are pinned by digest (docs/09 R31; checked by deploy/release/check-pinned-images.sh).
ARG GO_IMAGE=golang:1.27@sha256:820153096fda4de6ac1b7194d12e416b7dd8f4e9a33d17e0345b111b945162d8

FROM --platform=$BUILDPLATFORM ${GO_IMAGE} AS build
ARG TARGETOS
ARG TARGETARCH
ARG VERSION=0.0.0-dev
# Index digest (sha256:…) of this release's ghcr.io/vantezzen/launchroom-caddy,
# compiled into the system-image allow-list (internal/policy, docs/05 §4).
# Empty for dev builds: they accept the stock caddy:2 digest instead.
ARG CADDY_DIGEST=
WORKDIR /src
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY . .
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    set -eu; \
    for bin in launchroomd:launchroomd dockerd-guard:dockerd-guard ambassador:launchroom-ambassador; do \
      CGO_ENABLED=0 GOOS="$TARGETOS" GOARCH="$TARGETARCH" \
        go build -trimpath -buildvcs=false \
          -ldflags "-s -w -X main.version=${VERSION} -X github.com/vantezzen/launchroom/daemon/internal/policy.launchroomCaddyDigest=${CADDY_DIGEST}" \
          -o "/out/${bin#*:}" "./cmd/${bin%%:*}"; \
    done

# restic (docs/14 §2): a pinned, checksum-verified static binary. The daemon
# runs it as a child process with argv only (internal/backup).
FROM --platform=$BUILDPLATFORM debian:bookworm-slim@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587 AS restic
ARG TARGETARCH
ARG RESTIC_VERSION=0.19.1
ARG RESTIC_SHA256_AMD64=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c
ARG RESTIC_SHA256_ARM64=a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465
RUN set -eu; \
    apt-get update; apt-get install -y --no-install-recommends ca-certificates curl bzip2; \
    case "$TARGETARCH" in \
      amd64) sum="$RESTIC_SHA256_AMD64" ;; \
      arm64) sum="$RESTIC_SHA256_ARM64" ;; \
      *) echo "unsupported arch $TARGETARCH" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/restic.bz2 "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_${TARGETARCH}.bz2"; \
    echo "$sum  /tmp/restic.bz2" | sha256sum -c -; \
    bunzip2 -c /tmp/restic.bz2 > /usr/local/bin/restic; \
    chmod 0755 /usr/local/bin/restic

# Static distroless base: no shell, no package manager.
#
# Deliberately NOT the :nonroot variant. launchroomd programs nftables
# (NET_ADMIN) and binds the mesh IP and 127.0.0.1:5000 with host networking;
# dockerd-guard (same image, entrypoint /usr/local/bin/dockerd-guard) owns the
# Docker socket and needs uid 0 to open it; it runs with no capabilities and
# no network (docs/09-security.md §6, D49).
FROM gcr.io/distroless/static-debian12@sha256:d75cdd72874d4790092fcb1b058493ecf6bb5bf2b2b897045b00ff01d91843f2 AS runtime
ARG VERSION=0.0.0-dev
LABEL org.opencontainers.image.title="launchroomd" \
      org.opencontainers.image.description="launchroomd: reconciles signed desired state on one server" \
      org.opencontainers.image.source="https://github.com/vantezzen/launchroom" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${VERSION}"
COPY --from=build /out/ /usr/local/bin/
COPY --from=restic /usr/local/bin/restic /usr/local/bin/restic
USER 0:0
ENV LR_DATA_DIR=/data
ENTRYPOINT ["/usr/local/bin/launchroomd"]
