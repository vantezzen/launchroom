#!/bin/sh
# Multi-server failover test (docs/05 §3, docs/02 §6). `make test-failover`.
#
# Topology (dev multi stack, `make up-multi`, all three fake servers enrolled):
#   server-1 "control plane"  edge + runtime, the control plane, Postgres
#   server-2                  edge + runtime (the non-DB server that is killed)
#   server-3                  build, label location=home, behind NAT (home build box)
#
# Scenario: a fresh project (node-hello on branch failover, 3 replicas across
# server-1 and server-2, Postgres pinned to server-1 and reached from both
# servers, the domain served by both edges), builds on server-3, then under
# continuous load against both edges: kill server-3, kill server-2, kill the
# whole control plane, redeploy a new commit. Every phase is judged per edge:
# the edges that are up must answer every request. The topology is restored
# at the end (also on failure).
#
# Uses docker compose, curl and launchroom's artisan (dev) commands only.
# Evidence goes to dev/failover-test/out/<timestamp>/ (git-ignored).
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PROJECT="${LR_FAILOVER_PROJECT:-failover}"
# Seconds to keep the control plane down (> node_dead_after = 90 s, so a
# dashboard that wrongly declared the servers dead on return would show).
CP_DOWN="${LR_FAILOVER_CP_DOWN:-120}"
OUT="$ROOT/dev/failover-test/out/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"

# dev/.env is plain KEY=VALUE (ports).
if [ -f dev/.env ]; then
	# shellcheck disable=SC1091
	. dev/.env
fi
EDGE1_PORT="${LR_DEV_HTTPS_PORT:-8443}"
EDGE2_PORT="${LR_DEV_HTTPS_PORT_2:-8444}"
CP_SERVICES="dashboard horizon reverb scheduler vite headscale zot valkey postgres"

dc() { docker compose --project-directory dev --env-file dev/.env -f dev/compose.yml -f dev/compose.multi.yml "$@"; }
art() { dc exec -T dashboard php artisan "$@"; }
now_ms() { dc exec -T loadgen date +%s%3N; }
log() { printf '%s  %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "$OUT/steps.log"; }
die() {
	log "FAIL: $*"
	exit 1
}

# wait_for <seconds> <what> <command...>: retries the command every 3 s.
wait_for() {
	limit="$1"
	what="$2"
	shift 2
	start="$(date +%s)"
	until "$@" >/dev/null 2>&1; do
		[ "$(($(date +%s) - start))" -lt "$limit" ] || die "timed out after ${limit}s waiting for: $what"
		sleep 3
	done
	log "ok: $what ($(($(date +%s) - start))s)"
}

# Load phases: the loadgen timestamp of each phase start, for the analysis.
mark() {
	echo "$(now_ms) $1" >>"$OUT/phases.txt"
	log "phase: $1"
}

status() { art launchroom:dev:status "$APP"; }

# The latest deployment of the app: "<id> <status> <commit> <build server>".
latest() {
	status | sed -n 's/^{"deployments":\[{"id":"\([^"]*\)","status":"\([^"]*\)","commit":"\{0,1\}\([^",]*\)"\{0,1\},"buildServer":"\{0,1\}\([^",]*\).*/\1 \2 \3 \4/p'
}

# Healthy serving replicas on <server>.
serving_on() {
	status | tr '{' '\n' | grep "\"server\":\"$1\"" | grep '"phase":"healthy"' | grep -c '"role":"serving"' || true
}

server_online() { status | tr '{' '\n' | grep "\"name\":\"$1\"" | grep -q '"status":"online"'; }

deployed() {
	# shellcheck disable=SC2046 # word splitting on purpose
	set -- $(latest)
	[ "${2:-}" = active ] && [ "${3:-}" = "$want_commit" ] &&
		[ "$(serving_on 'control plane')" -ge 1 ] && [ "$(($(serving_on 'control plane') + $(serving_on server-2)))" -eq 3 ]
}

# curl through one edge (host port): prints "<code> <seconds> <body>".
via() {
	curl -sk --max-time 5 --resolve "$HOST:$1:127.0.0.1" -w '\n%{http_code} %{time_total}' "https://$HOST:$1$2" 2>/dev/null |
		awk 'NR == 1 { body = $0 } END { print $0, body }' || true
}
edge_ok() { via "$1" /db | grep -q '^200 '; }

restore_topology() {
	status_code=$?
	log "restoring the topology"
	# Keep the evidence of a failed run: load lines so far and the last status.
	if [ "$status_code" -ne 0 ] && [ ! -s "$OUT/load.log" ]; then
		dc logs --no-log-prefix loadgen 2>/dev/null | grep -E '^[0-9]{13} ' >"$OUT/load.log" || true
		[ -z "${APP:-}" ] || status >"$OUT/status-at-failure.json" 2>&1 || true
	fi
	dc rm -sf loadgen >/dev/null 2>&1 || true
	# shellcheck disable=SC2086 # service list
	dc start $CP_SERVICES server-1 server-2 server-3 nat-router >/dev/null 2>&1 || true
	exit "$status_code"
}
trap restore_topology EXIT

# ---- 0. Topology -----------------------------------------------------------

log "evidence: $OUT"
for svc in server-1 server-2 server-3 nat-router headscale dashboard horizon; do
	[ -n "$(dc ps -q --status running "$svc")" ] || die "$svc is not running (make up-multi, then make dev-enroll-2 / dev-enroll-3)"
done
# Roles and labels through the regular action (idempotent). server-3 first:
# the control plane may only drop `build` while another build server is online.
art launchroom:servers:update server-3 --roles=build --labels=location=home | tee -a "$OUT/topology.json"
# server-2 is an edge like server-1: admitted to the shared certificate
# storage by the instance owner (SetSharedEdge; the control plane always is).
art launchroom:servers:update server-2 --roles=edge,runtime --shared-edge=yes | tee -a "$OUT/topology.json"
art launchroom:servers:update "control plane" --roles=edge,runtime | tee -a "$OUT/topology.json"

# ---- 1. Project --------------------------------------------------------------

shas="$(dc exec -T git-fixtures wget -qO- http://127.0.0.1:8080/ | tr '{' '\n' | sed -n 's/.*"failover":\["\([0-9a-f]*\)","\([0-9a-f]*\)"\].*/\1 \2/p')"
V1="${shas%% *}"
V2="${shas##* }"
[ -n "$V1" ] && [ -n "$V2" ] && [ "$V1" != "$V2" ] || die "node-hello has no failover branch with two commits"
log "commits: v1 $V1, v2 $V2"

out="$(art launchroom:dev:deploy-app --name="$PROJECT" --branch=failover --replicas=3 \
	"--app-servers=control plane,server-2" "--db-servers=control plane" --replace --json)"
echo "$out" >"$OUT/project.json"
APP="$(echo "$out" | sed -n 's/.*"appInstance":"\([^"]*\)".*/\1/p')"
HOST="$(echo "$out" | sed -n 's/.*"hostnames":\["\([^"]*\)".*/\1/p')"
[ -n "$APP" ] && [ -n "$HOST" ] || die "deploy-app: $out"
log "project $PROJECT: app $APP at https://$HOST"

# The first deploy builds the branch head (v2); push v1 so the redeploy in
# step 6 brings a new commit.
want_commit="$V2"
wait_for 600 "first deployment active (3 replicas, Postgres on server-1)" deployed
art launchroom:dev:git-push "$APP" --sha="$V1" | tee -a "$OUT/steps.log"
want_commit="$V1"
wait_for 600 "v1 active" deployed

# shellcheck disable=SC2046 # word splitting on purpose
set -- $(latest)
DEPLOY_V1="$1"
BUILD_SERVER_V1="${4:-}"
status >"$OUT/status-v1.json"
art launchroom:dev:status "$APP" --build-log="$DEPLOY_V1" >"$OUT/build-v1.log"
log "v1 $DEPLOY_V1 built on: $BUILD_SERVER_V1"
[ "$BUILD_SERVER_V1" = server-3 ] || die "the build did not run on server-3 (home)"
grep -q 'Pushed 127.0.0.1:5000/' "$OUT/build-v1.log" || die "no push through the registry forwarder in the build log"
dc logs server-3 2>/dev/null | grep '"registry forwarder listening"' | tail -n 1 >>"$OUT/build-v1.log" || true

# ---- 2. Both edges, Postgres from replicas on both servers --------------------

: >"$OUT/db-check.txt"
i=0
while [ "$i" -lt 40 ]; do
	via "$EDGE1_PORT" /db >>"$OUT/db-check.txt"
	via "$EDGE2_PORT" /db >>"$OUT/db-check.txt"
	i=$((i + 1))
done
ok_requests="$(grep -c '^200 .*"db":{"server":"[0-9]' "$OUT/db-check.txt" || true)"
log "db round trips: $ok_requests/80 ok"
[ "$ok_requests" -eq 80 ] || die "not every /db request succeeded (see db-check.txt)"
st="$(status)"
for server in "control plane" server-2; do
	hit=""
	for c in $(echo "$st" | tr '{' '\n' | grep "\"server\":\"$server\"" | grep '"role":"serving"' | sed -n 's/.*"container":"\([0-9a-f]*\)".*/\1/p'); do
		if grep -q "\"replica\":\"$c\"" "$OUT/db-check.txt"; then hit="$c"; fi
	done
	[ -n "$hit" ] || die "no replica on $server answered a /db request"
	log "replica $hit on $server queried Postgres (on control plane)"
done

# ---- 3. Load ----------------------------------------------------------------

LR_LOAD_HOST="$HOST"
LR_LOAD_PATH=/db
export LR_LOAD_HOST LR_LOAD_PATH
dc --profile failover-test up -d loadgen >/dev/null 2>&1
mark baseline
sleep 20

# ---- 4a. Kill server-3 (home build box) ---------------------------------------

mark server-3-down
dc kill server-3 >/dev/null 2>&1
sleep 40
dc start server-3 >/dev/null 2>&1
mark server-3-back
wait_for 180 "server-3 online again" server_online server-3

# ---- 4b. Kill server-2 (edge + runtime, no database) --------------------------

before="$(serving_on server-2)"
mark server-2-down
dc kill server-2 >/dev/null 2>&1
t0="$(date +%s)"
moved() { [ "$(serving_on 'control plane')" -eq 3 ]; }
wait_for 300 "server-2's $before replica(s) rescheduled onto server-1" moved
log "rescheduled after $(($(date +%s) - t0))s"
status >"$OUT/status-server-2-down.json"
sleep 15
dc start server-2 >/dev/null 2>&1
mark server-2-back
wait_for 300 "server-2 online again" server_online server-2
wait_for 300 "server-2's edge serving again" edge_ok "$EDGE2_PORT"

# ---- 5. Kill the control plane ------------------------------------------------

mark control-plane-down
# shellcheck disable=SC2086 # service list
dc stop $CP_SERVICES >/dev/null 2>&1
log "control plane stopped ($CP_SERVICES); waiting ${CP_DOWN}s"
sleep "$CP_DOWN"
# shellcheck disable=SC2086 # service list
dc start $CP_SERVICES >/dev/null 2>&1
mark control-plane-back
dashboard_up() { curl -sf --max-time 3 "http://127.0.0.1:${LR_DEV_DASHBOARD_PORT:-8000}/up"; }
wait_for 300 "dashboard up again" dashboard_up
for server in "control plane" server-2 server-3; do
	wait_for 120 "$server online after the control plane returned" server_online "$server"
done
want_commit="$V1"
wait_for 120 "v1 still active with 3 healthy replicas" deployed
status >"$OUT/status-control-plane-back.json"
sleep 20

# ---- 6. Redeploy a new commit -------------------------------------------------

mark redeploy
art launchroom:dev:git-push "$APP" | tee -a "$OUT/steps.log"
want_commit="$V2"
wait_for 600 "v2 active" deployed
# shellcheck disable=SC2046 # word splitting on purpose
set -- $(latest)
art launchroom:dev:status "$APP" --build-log="$1" >"$OUT/build-v2.log"
log "v2 $1 built on: ${4:-}"
[ "${4:-}" = server-3 ] || die "the v2 build did not run on server-3 (home)"
sleep 15
mark end
sleep 2
via "$EDGE1_PORT" /db | grep -q '"release":"2"' || die "edge 1 does not serve v2"
via "$EDGE2_PORT" /db | grep -q '"release":"2"' || die "edge 2 does not serve v2"
status >"$OUT/status-v2.json"

# ---- Analysis ------------------------------------------------------------------

dc logs --no-log-prefix loadgen 2>/dev/null | grep -E '^[0-9]{13} ' >"$OUT/load.log"
dc rm -sf loadgen >/dev/null 2>&1

# Per phase and edge: requests, successes, success rate, max/p50 latency.
# A phase "requires" an edge unless that edge's server is the one down.
if awk '
	NR == FNR { start[NR] = $1; name[NR] = $2; n = NR; next }
	{
		p = 0
		for (i = 1; i <= n; i++) if ($1 >= start[i]) p = i
		if (p == 0 || name[p] == "end") next
		k = name[p] SUBSEP $2
		total[k]++
		if ($3 ~ /^2/) ok[k]++
		if ($4 > max[k]) max[k] = $4
		seen[name[p]] = p; edges[$2] = 1
	}
	END {
		fail = 0
		printf "%-20s %-9s %7s %7s %8s %8s  %s\n", "phase", "edge", "reqs", "ok", "rate", "max s", "required"
		for (i = 1; i <= n; i++) {
			ph = name[i]; if (!(ph in seen)) continue
			for (e in edges) {
				k = ph SUBSEP e
				req = !((ph == "server-2-down" || ph == "server-2-back") && e == "server-2")
				rate = total[k] ? 100 * ok[k] / total[k] : 0
				printf "%-20s %-9s %7d %7d %7.2f%% %8.3f  %s\n", ph, e, total[k], ok[k], rate, max[k], req ? "yes" : "no (its server is down/rejoining)"
				if (req && (total[k] == 0 || ok[k] != total[k])) fail = 1
			}
		}
		exit fail
	}
' "$OUT/phases.txt" "$OUT/load.log" >"$OUT/summary.txt"; then
	cat "$OUT/summary.txt"
else
	cat "$OUT/summary.txt"
	die "requests failed through an edge that was up (see summary.txt and load.log)"
fi

log "PASS: failover test (evidence in $OUT)"
