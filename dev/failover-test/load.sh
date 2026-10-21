#!/bin/sh
# Failover-test load generator (dev/compose.multi.yml service `loadgen`).
# One request loop per edge: HTTPS to LR_LOAD_HOST via that edge's address
# (curl --resolve, the dev edges use Caddy's internal CA, hence -k). Prints
# one line per request: "<epoch ms> <edge> <http code> <seconds>"; code 000
# means no HTTP response (connect/TLS/timeout).
set -eu

host="${LR_LOAD_HOST:?LR_LOAD_HOST is required}"
path="${LR_LOAD_PATH:-/}"
interval="${LR_LOAD_INTERVAL:-0.1}"

loop() {
	edge="$1"
	ip="$(getent hosts "$edge" | awk '{print $1; exit}')"
	[ -n "$ip" ] || { echo "loadgen: cannot resolve $edge" >&2; return 1; }
	while :; do
		out="$(curl -sk -o /dev/null --max-time 5 --resolve "$host:443:$ip" \
			-w '%{http_code} %{time_total}' "https://$host$path" 2>/dev/null)" || true
		[ -n "$out" ] || out="000 5.000"
		echo "$(date +%s%3N) $edge $out"
		sleep "$interval"
	done
}

for edge in ${LR_LOAD_EDGES:-server-1 server-2}; do
	loop "$edge" &
done
trap 'kill 0' TERM INT
wait
