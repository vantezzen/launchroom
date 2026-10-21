#!/bin/sh
# Fake-server entrypoint (dev only): start the nested dockerd, then run
# launchroomd, restarting it whenever its binary is replaced.
#
# Deliberately supervisor-free: one shell loop, polling every 2 s.
#   - dockerd dies          → container exits (compose restarts it)
#   - launchroomd exits     → restarted after a short backoff
#   - binary mtime changes  → launchroomd gets SIGTERM and is restarted
#   - binary missing        → logged once; waits until `make dev-daemon` builds it
#
# Env:
#   LR_DAEMON_BIN       path of the launchroomd binary (default /opt/launchroom/bin/launchroomd)
#   LR_DOCKERD_ARGS     extra dockerd flags (e.g. --insecure-registry=zot:5000)
#   LR_DEV_GATEWAY      default route via this address (server-3: the NAT router
#                       of its home network, dev/nat-router)
#   LR_DEV_NAMESERVER   use only this resolver (server-3: the router's dnsmasq),
#                       so nested containers resolve through it too
#
# /data/reset-docker (written by `make dev-enroll-N` on re-enrollment): the
# nested Docker state is wiped before dockerd starts. Objects of the previous
# enrollment carry its server id and a re-enrolled launchroomd (new id) must
# never adopt them (docs/03 §8), so a re-enrolled fake server starts empty.
set -eu

DAEMON_BIN="${LR_DAEMON_BIN:-/opt/launchroom/bin/launchroomd}"
NAME="${LR_DEV_SERVER_NAME:-$(hostname)}"

log() { printf '[%s] %s\n' "$NAME" "$*"; }

daemon_pid=""
dockerd_pid=""

if [ -n "${LR_DEV_GATEWAY:-}" ]; then
	ip route replace default via "$LR_DEV_GATEWAY"
	# Docker routes between compose networks even for an `internal` one
	# (Docker Desktop's VM even SNATs such traffic to the bridge's gateway
	# address), so a peer could still open connections to us directly. Behind
	# a real (CG)NAT nothing can: drop every new inbound connection that comes
	# from outside the home LAN or via the bridge gateway (replies to our own
	# connections pass).
	lan="$(ip -o -4 route show dev eth0 scope link | awk '{print $1; exit}')"
	bridge_gw="${lan%.*/*}.1"
	for rule in "! -s $lan" "-s $bridge_gw"; do
		# shellcheck disable=SC2086 # $rule holds two words on purpose
		iptables -t mangle -C PREROUTING -i eth0 $rule -m conntrack --ctstate NEW -j DROP 2>/dev/null ||
			iptables -t mangle -A PREROUTING -i eth0 $rule -m conntrack --ctstate NEW -j DROP
	done
	log "default route via $LR_DEV_GATEWAY (NAT), inbound only from $lan"
fi
if [ -n "${LR_DEV_NAMESERVER:-}" ]; then
	printf 'nameserver %s\n' "$LR_DEV_NAMESERVER" > /etc/resolv.conf
fi
if [ -f /data/reset-docker ]; then
	log "re-enrolled: wiping the nested Docker state"
	find /var/lib/docker -mindepth 1 -maxdepth 1 -exec rm -rf {} +
	rm -f /data/reset-docker
fi

shutdown() {
	log "shutting down"
	[ -n "$daemon_pid" ] && kill -TERM "$daemon_pid" 2>/dev/null || true
	[ -n "$daemon_pid" ] && wait "$daemon_pid" 2>/dev/null || true
	[ -n "$dockerd_pid" ] && kill -TERM "$dockerd_pid" 2>/dev/null || true
	[ -n "$dockerd_pid" ] && wait "$dockerd_pid" 2>/dev/null || true
	exit 0
}
trap shutdown TERM INT

# Nested daemon: unix socket only (no TCP listener). The stock entrypoint only
# adds a TCP host when no explicit `dockerd ...` command is given.
# shellcheck disable=SC2086 # LR_DOCKERD_ARGS is intentionally word-split
dockerd-entrypoint.sh dockerd --host=unix:///var/run/docker.sock ${LR_DOCKERD_ARGS:-} &
dockerd_pid=$!

i=0
until docker info >/dev/null 2>&1; do
	if ! kill -0 "$dockerd_pid" 2>/dev/null; then
		log "dockerd exited during startup"
		exit 1
	fi
	i=$((i + 1))
	if [ "$i" -ge 60 ]; then
		log "dockerd not ready after 60s"
		exit 1
	fi
	sleep 1
done
log "nested dockerd is ready ($(docker version --format '{{.Server.Version}}'))"

mtime() { stat -c %Y "$DAEMON_BIN" 2>/dev/null || echo missing; }

missing_logged=0
while :; do
	if ! kill -0 "$dockerd_pid" 2>/dev/null; then
		log "dockerd exited; stopping"
		shutdown
	fi

	if [ ! -x "$DAEMON_BIN" ]; then
		if [ "$missing_logged" -eq 0 ]; then
			log "launchroomd binary $DAEMON_BIN not found; run \`make dev-daemon\` (dockerd keeps running)"
			missing_logged=1
		fi
		sleep 2
		continue
	fi
	missing_logged=0

	started_mtime="$(mtime)"
	log "starting launchroomd ($DAEMON_BIN, mtime $started_mtime)"
	"$DAEMON_BIN" &
	daemon_pid=$!

	while :; do
		sleep 2
		if ! kill -0 "$dockerd_pid" 2>/dev/null; then
			log "dockerd exited; stopping"
			shutdown
		fi
		if ! kill -0 "$daemon_pid" 2>/dev/null; then
			status=0
			wait "$daemon_pid" || status=$?
			daemon_pid=""
			log "launchroomd exited with status $status; restarting in 3s"
			sleep 3
			break
		fi
		if [ "$(mtime)" != "$started_mtime" ]; then
			log "launchroomd binary changed; restarting launchroomd"
			kill -TERM "$daemon_pid" 2>/dev/null || true
			wait "$daemon_pid" 2>/dev/null || true
			daemon_pid=""
			break
		fi
	done
done
