# Dev-only NAT router for the "home" network of server-3 (dev/compose.multi.yml):
# simulates a home build box behind CGNAT. Outbound only: MASQUERADE to the dev
# network, nothing forwarded inwards, DNS via dnsmasq (forwards to Docker's
# embedded resolver so dev-stack names like `dashboard` resolve).
FROM alpine:3.22
RUN apk add --no-cache iptables dnsmasq iproute2
COPY --chmod=0755 entrypoint.sh /usr/local/bin/launchroom-nat-router
ENTRYPOINT ["launchroom-nat-router"]
