# syntax=docker/dockerfile:1@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e
#
# ghcr.io/vantezzen/launchroom-caddy: launchroom's edge Caddy (docs/05 §4–5).
# Stock Caddy plus exactly these modules, every version pinned:
#
#   - storage "redis"  github.com/pberkel/caddy-storage-redis  shared certificate
#                      storage for several edges (Valkey on the control plane)
#   - dns "cloudflare" github.com/caddy-dns/cloudflare         DNS-01 (wildcards)
#   - dns "hetzner"    github.com/caddy-dns/hetzner/v2         DNS-01, Hetzner
#                      Cloud DNS API (api.hetzner.cloud, Cloud API tokens)
#   - dns "route53"      github.com/caddy-dns/route53       DNS-01, Amazon Route 53
#   - dns "digitalocean" github.com/caddy-dns/digitalocean  DNS-01, DigitalOcean
#                        (the module has no release tags; pinned to a commit)
#   - dns "porkbun"      github.com/caddy-dns/porkbun       DNS-01, Porkbun
#   - dns "desec"        github.com/caddy-dns/desec         DNS-01, deSEC
#   - dns "gandi"        github.com/caddy-dns/gandi         DNS-01, Gandi LiveDNS
#   - dns "ovh"          github.com/caddy-dns/ovh           DNS-01, OVHcloud
#   - dns "netcup"       github.com/caddy-dns/netcup        DNS-01, netcup
#   - http.handlers.waf  github.com/corazawaf/coraza-caddy/v2  the web firewall
#                        (docs/22 §6): Coraza (github.com/corazawaf/coraza/v3)
#                        with the OWASP Core Rule Set embedded into the binary
#                        (github.com/corazawaf/coraza-coreruleset/v4, no rule
#                        files on disk). All three Apache-2.0. CRS changes only
#                        with a launchroom release (D64).
#
# Why these (D47, docs/05 §5): the registrars and clouds self-hosters use
# most, each a small libdns provider over a plain HTTPS API with a token
# that can be checked read-only before use. Not built in: providers that
# need a self-hosted endpoint (RFC 2136, PowerDNS, ACME-DNS: the edge would
# call addresses the dashboard can't vet), Azure/Google Cloud DNS (large
# SDKs for a few users), GoDaddy and Namecheap (API access gated by account
# size or a per-IP allow-list), DuckDNS (one TXT record per name, so no
# per-project wildcards).
#
# Multi-arch: the builder runs on the build platform and cross-compiles
# (CGO off), so `docker buildx build --platform linux/amd64,linux/arm64` needs
# no emulation. launchroomd only runs this image when its digest is pinned in
# its system-image allow-list (daemon/internal/policy, docs/09 R31): see
# deploy/images/README.md for how releases and local builds pin it.

ARG CADDY_VERSION=v2.11.7
# Web firewall pins (docs/22 §6.1). The engine and rule set versions are also
# written into image labels, which launchroomd reads from the running, digest-
# pinned Caddy to announce the "waf" feature and report the versions.
ARG CORAZA_CADDY_VERSION=v2.6.2
ARG CORAZA_VERSION=3.8.1
ARG CRS_VERSION=4.25.0

# caddy:2.11.7-builder (multi-arch index digest)
FROM --platform=$BUILDPLATFORM docker.io/library/caddy:2.11.7-builder@sha256:d842facfd63b960922e57444ff76fd8f0793a66dfdcf481af8026e1f4e6a39b4 AS builder
ARG CADDY_VERSION
ARG CORAZA_CADDY_VERSION
ARG CORAZA_VERSION
ARG CRS_VERSION
ARG TARGETOS
ARG TARGETARCH
ARG TARGETVARIANT
ENV CGO_ENABLED=0 \
    XCADDY_SETCAP=0 \
    XCADDY_SKIP_CLEANUP=0 \
    GOFLAGS=-trimpath
RUN GOOS="$TARGETOS" GOARCH="$TARGETARCH" GOARM="${TARGETVARIANT#v}" \
    xcaddy build "$CADDY_VERSION" \
      --output /out/caddy \
      --with github.com/pberkel/caddy-storage-redis@v1.9.0 \
      --with github.com/caddy-dns/cloudflare@v0.2.4 \
      --with github.com/caddy-dns/hetzner/v2@v2.0.1 \
      --with github.com/caddy-dns/route53@v1.6.2 \
      --with github.com/caddy-dns/digitalocean@v0.0.0-20250606074528-04bde2867106 \
      --with github.com/caddy-dns/porkbun@v0.3.1 \
      --with github.com/caddy-dns/desec@v1.1.0 \
      --with github.com/caddy-dns/gandi@v1.2.1 \
      --with github.com/caddy-dns/ovh@v1.1.0 \
      --with github.com/caddy-dns/netcup@v1.0.0 \
      --with "github.com/corazawaf/coraza-caddy/v2@$CORAZA_CADDY_VERSION" \
      --with "github.com/corazawaf/coraza/v3@v$CORAZA_VERSION" \
      --with "github.com/corazawaf/coraza-coreruleset/v4@v$CRS_VERSION"

# caddy:2.11.7 (multi-arch index digest; the same stock image config/launchroom.php pins)
FROM docker.io/library/caddy:2.11.7@sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af
ARG CORAZA_VERSION
ARG CRS_VERSION
COPY --from=builder /out/caddy /usr/bin/caddy
LABEL org.opencontainers.image.title="launchroom-caddy" \
      org.opencontainers.image.description="Caddy with Redis/Valkey certificate storage, DNS-01 providers and the Coraza web firewall for launchroom edges" \
      io.vantezzen.launchroom.waf.engine="coraza ${CORAZA_VERSION}" \
      io.vantezzen.launchroom.waf.ruleset="OWASP CRS ${CRS_VERSION}" \
      org.opencontainers.image.source="https://github.com/vantezzen/launchroom" \
      org.opencontainers.image.licenses="Apache-2.0"
# Fails the build if a module is missing.
RUN set -e; modules="$(caddy list-modules)"; \
    for m in caddy.storage.redis dns.providers.cloudflare dns.providers.hetzner dns.providers.route53 \
             dns.providers.digitalocean dns.providers.porkbun dns.providers.desec dns.providers.gandi \
             dns.providers.ovh dns.providers.netcup http.handlers.waf; do \
      printf '%s\n' "$modules" | grep -qx "$m" || { echo "missing module $m" >&2; exit 1; }; \
    done
