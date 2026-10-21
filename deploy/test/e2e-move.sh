#!/bin/sh
# End-to-end test of "Move to a new server" (`make test-move`, docs/14 §13).
#
# Privileged docker:dind containers play fresh VPSes on one labelled network
# (static addresses, no host ports, nothing from the host mounted), with the
# locally built dashboard, launchroom-caddy and launchroomd images streamed in
# as in `make test-install`.
#
# Move 1, the control plane (D56, docs/14 §13.4): install launchroom on VPS A,
# deploy a project with Postgres and an nginx app (unprivileged, :8080) that serves files from a
# volume (through a custom domain on the edge), write rows and a file, start
# Settings → Move launchroom (through the same actions as the UI), run
# `install.sh --move <token>` on VPS B, confirm the code it prints, write more
# right before the move, move every app, database and volume, write a
# dashboard change right before the hand-over, hand over. Then: B's dashboard
# works (same domain), B's launchroomd is the control plane over loopback,
# the app serves through B's edge with every row and the newest file, and A
# is frozen ("moved"), its server revoked and decommissioned (no edge, no
# workloads).
#
# Move 2, a regular server (docs/14 §13.2): servers C and D join B with
# server.sh; a project with Postgres and a volume app runs on C; C moves to D
# (stateless first, then each stateful service with a short stop, no S3);
# the data is on D, the app serves through B's edge, C is retired.
#
# The mesh: Tailscale only speaks plain http to a login server on port 80, so
# a socat container (alias hs.e2e-move) forwards to the control plane's
# Headscale; after Move 1 it forwards to B, like a DNS record you switch.
#
# Everything is named launchroom-dev-e2e-move* and labelled launchroom.dev=true;
# all of it is removed at the end (KEEP=1 keeps it; SKIP_BUILD=1 reuses the
# built dashboard and caddy images). Run from the repo root.
set -eu

PREFIX=launchroom-dev-e2e-move
NET="$PREFIX-net"
SUBNET=172.29.250.0/24
A="$PREFIX-a" A_IP=172.29.250.11
B="$PREFIX-b" B_IP=172.29.250.12
C="$PREFIX-c" C_IP=172.29.250.13
D="$PREFIX-d" D_IP=172.29.250.14
HS="$PREFIX-hs" HS_IP=172.29.250.20
DIND_IMAGE="docker:29-dind@sha256:3e5756e2b6dad6a5f1f5deacf0a19f9eaa2581b76ae092b2d4a118162e1d4d4e"
SOCAT_IMAGE="alpine/socat@sha256:beb4a68d9e4fe6b0f21ea774a0fde6c31f580dde6368939ed70100c5385b015e"
DASHBOARD_IMAGE=launchroom-dev-e2e-dashboard:test
DAEMON_IMAGE=launchroom-dev-e2e-daemon:test
CADDY_IMAGE=launchroom-dev-e2e-caddy:test
DOMAIN=dash.move.localhost
# The apps' generated domains (*.localhost, read from the database).
APP_HOST=""
APP2_HOST=""
LR=/root/launchroom

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nFAIL: %s\n' "$*" >&2; exit 1; }
ok() { printf '    ok: %s\n' "$*"; }
on() { vps=$1; shift; docker exec "$vps" "$@"; }
in_lr() { vps=$1; shift; docker exec -w "$LR" "$vps" "$@"; }
psql_on() { in_lr "$1" docker compose exec -T postgres psql -U launchroom -d launchroom -tAc "$2"; }
tinker() {
	vps=$1
	shift
	# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
	in_lr "$vps" docker compose exec -T dashboard php artisan tinker --no-interaction --execute='
		$owner = App\Models\User::query()->where("email", "owner@e2e.test")->firstOrFail();
		$actor = App\Data\Audit\AuditActor::user($owner);
		'"$1"
}

TMP="$(mktemp -d)"

cleanup() {
	status=$?
	if [ "$status" != 0 ]; then
		for vps in "$A" "$B" "$C" "$D"; do
			docker inspect "$vps" >/dev/null 2>&1 || continue
			echo "--- $vps: move log / launchroomd"
			docker exec "$vps" sh -c "tail -n 40 /root/move.log 2>/dev/null; cd $LR 2>/dev/null && docker compose logs --tail 30 launchroomd 2>/dev/null; cd /root/launchroomd 2>/dev/null && docker compose logs --tail 30 launchroomd 2>/dev/null" || true
		done
	fi
	rm -rf "$TMP"
	if [ "${KEEP:-0}" = 1 ]; then
		echo "KEEP=1: leaving $A $B $C $D $HS (remove: docker rm -f -v $A $B $C $D $HS && docker network rm $NET)"
	else
		docker rm -f -v "$A" "$B" "$C" "$D" "$HS" >/dev/null 2>&1 || true
		docker network rm "$NET" >/dev/null 2>&1 || true
	fi
	exit "$status"
}
trap cleanup EXIT INT TERM

# retry SECONDS COMMAND...: run until it succeeds or the time is up.
retry() {
	limit=$1
	shift
	start=$(date +%s)
	until "$@" >/dev/null 2>&1; do
		[ $(($(date +%s) - start)) -lt "$limit" ] || return 1
		sleep 3
	done
}

# ---- Images -----------------------------------------------------------------------

if [ "${SKIP_BUILD:-0}" = 1 ]; then
	step "SKIP_BUILD=1: using the existing $DASHBOARD_IMAGE and $CADDY_IMAGE"
else
	step "building images (dashboard production target, launchroom-caddy)"
	docker build -q -f deploy/images/dashboard/Dockerfile --target production \
		--label launchroom.dev=true -t "$DASHBOARD_IMAGE" . >/dev/null
	docker build -q --label launchroom.dev=true -t "$CADDY_IMAGE" deploy/images/caddy >/dev/null
	ok "built $DASHBOARD_IMAGE and $CADDY_IMAGE"
fi

step "network $NET ($SUBNET) and the mesh login address hs.e2e-move"
docker rm -f -v "$A" "$B" "$C" "$D" "$HS" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
docker network create --label launchroom.dev=true --subnet "$SUBNET" "$NET" >/dev/null
ok "network ready"

# hs_points_at IP: hs.e2e-move:80 forwards to IP:8081 (that server's Headscale).
hs_points_at() {
	docker rm -f "$HS" >/dev/null 2>&1 || true
	docker run -d --name "$HS" --label launchroom.dev=true --network "$NET" --ip "$HS_IP" --network-alias hs.e2e-move \
		"$SOCAT_IMAGE" TCP-LISTEN:80,fork,reuseaddr "TCP:$1:8081" >/dev/null
}

# start_vps NAME IP: a fresh docker:dind "VPS" with our images and the bundle.
start_vps() {
	docker run -d --name "$1" --hostname "$1" --privileged \
		--label launchroom.dev=true --network "$NET" --ip "$2" \
		--mount type=volume,dst=/var/lib/docker,volume-label=launchroom.dev=true \
		"$DIND_IMAGE" >/dev/null
	retry 60 docker exec "$1" docker info || fail "the Docker daemon of $1 did not start"
	docker save "$CADDY_IMAGE" "$DASHBOARD_IMAGE" | docker exec -i "$1" docker load -q >/dev/null
	[ "$1" = "$A" ] || docker save "$DAEMON_IMAGE" | docker exec -i "$1" docker load -q >/dev/null
	docker cp deploy "$1:/opt/bundle"
}

# Control-plane override (compose.override.yml is never touched by the
# installer): the dashboard also on :8001 for the other VPSes (http; every
# payload is signed), Headscale on :8081 behind hs.e2e-move, and the mesh's
# plain-http login server allowed by launchroomd.
write_cp_override() {
	docker exec -i "$1" sh -c "mkdir -p $LR/data/headscale && cat > $LR/compose.override.yml" <<'EOF'
x-mesh-env: &mesh-env
  LAUNCHROOM_MESH_LOGIN_SERVER: http://hs.e2e-move
  # Generated app domains on *.localhost (Caddy's internal CA), like dev.
  LAUNCHROOM_LOCALHOST_DOMAINS: "true"
services:
  dashboard:
    ports: ["8001:8000"]
    environment: *mesh-env
  horizon:
    environment: *mesh-env
  scheduler:
    environment: *mesh-env
  reverb:
    environment: *mesh-env
  headscale:
    ports: ["8081:8080"]
    volumes:
      - ./data/headscale/e2e-config.yaml:/etc/headscale/config.yaml:ro
  launchroomd:
    environment:
      LR_MESH_INSECURE_LOGIN_HOSTS: hs.e2e-move
EOF
	# Headscale's own address is the one the servers log in to.
	docker exec "$1" sh -c "sed -e 's#^server_url: .*#server_url: http://hs.e2e-move#' /opt/bundle/images/headscale/config.yaml > $LR/data/headscale/e2e-config.yaml"
}

step "VPS A ($A_IP): launchroom-caddy digest, launchroomd built like a release"
start_vps "$A" "$A_IP"
caddy_digest="$(on "$A" docker image inspect "$CADDY_IMAGE" --format '{{index .RepoDigests 0}}' | sed 's/.*@//')"
printf '%s' "$caddy_digest" | grep -Eq '^sha256:[0-9a-f]{64}$' || fail "no digest for $CADDY_IMAGE ($caddy_digest)"
CADDY_REF="docker.io/library/${CADDY_IMAGE%%:*}@$caddy_digest"
docker build -q --build-arg CADDY_DIGEST="$caddy_digest" --label launchroom.dev=true -t "$DAEMON_IMAGE" daemon >/dev/null
docker save "$DAEMON_IMAGE" | docker exec -i "$A" docker load -q >/dev/null
ok "images on A (caddy $caddy_digest)"
hs_points_at "$A_IP"

install_env() { printf '%s\n' -e "LR_DASHBOARD_IMAGE=$DASHBOARD_IMAGE" -e "LR_DAEMON_IMAGE=$DAEMON_IMAGE" -e "LR_CADDY_IMAGE=$CADDY_REF"; }

# ---- Move 1: the control plane ---------------------------------------------------

step "Move 1: install launchroom on A ($DOMAIN)"
write_cp_override "$A"
# shellcheck disable=SC2046 # the -e flags split on purpose
docker exec -w /root $(install_env) "$A" sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain "$DOMAIN" --no-public-ip-detect >"$TMP/install-a.log" 2>&1 ||
	{ cat "$TMP/install-a.log"; fail "install.sh on A failed"; }
retry 120 sh -c "docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM servers WHERE is_control_plane\" | grep -qx online" ||
	fail "A's launchroomd is not online"
ok "A runs the control plane"

step "owner, project move-e2e: Postgres and an nginx app with a volume"
setup_token="$(in_lr "$A" docker compose exec -T dashboard php artisan launchroom:setup-token --plain)"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
in_lr "$A" docker compose exec -T -e E2E_SETUP_TOKEN="$setup_token" dashboard php artisan tinker --no-interaction --execute='
	$actor = App\Data\Audit\AuditActor::system();
	$owner = app(App\Actions\Instance\CreateInstanceOwner::class)->handle(new App\Data\Instance\CreateOwnerData(getenv("E2E_SETUP_TOKEN"), "E2E Owner", "owner@e2e.test", "e2e-owner-password-1234"), $actor);
	$team = $owner->refresh()->currentTeam;
	$project = app(App\Actions\Projects\CreateProject::class)->handle($team, new App\Data\Projects\CreateProjectData("move-e2e"), $actor);
	$create = app(App\Actions\Services\CreateService::class);
	$pg = $create->handle($project, App\Data\Services\CreateServiceData::from(["name" => "postgres", "kind" => App\Enums\ServiceKind::Database, "source" => ["type" => "database", "engine" => "postgres", "version" => "18"]]), $owner, $actor);
	$web = $create->handle($project, App\Data\Services\CreateServiceData::from(["name" => "web", "kind" => App\Enums\ServiceKind::Image, "source" => ["type" => "image", "reference" => "nginxinc/nginx-unprivileged:1.27-alpine"], "port" => 8080]), $owner, $actor);
	$environment = $project->production()->sole();
	$instance = App\Models\ServiceInstance::query()->where("service_id", $web->id)->where("environment_id", $environment->id)->sole();
	app(App\Actions\Services\AddServiceVolume::class)->handle($instance, new App\Data\Services\AddVolumeData("/usr/share/nginx/html/data"), $actor);
	app(App\Actions\Connections\CreateConnection::class)->handle($web, $pg, App\Enums\ConnectionMode::Private, App\Enums\ConnectionStyle::Url, $owner, $actor);
	app(App\Actions\Deployments\DeployEnvironment::class)->handle($environment, $owner, $actor);
	echo "e2e-project-created\n";' >"$TMP/tinker.log" 2>&1 || { cat "$TMP/tinker.log"; fail "could not create the project"; }
grep -q e2e-project-created "$TMP/tinker.log" || { cat "$TMP/tinker.log"; fail "the project script did not finish"; }
retry 600 sh -c "test \"\$(docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT count(*) FROM service_instances WHERE active_deployment_id IS NOT NULL\")\" = 2" ||
	fail "the project did not deploy: $(psql_on "$A" "SELECT status, failure_message FROM deployments")"
ok "postgres and web are active on A"
# app_host VPS PROJECT: the web service's generated domain.
app_host() { psql_on "$1" "SELECT d.hostname FROM domains d JOIN service_instances i ON i.id = d.service_instance_id JOIN services s ON s.id = i.service_id JOIN projects p ON p.id = s.project_id WHERE s.name = 'web' AND p.name = '$2' ORDER BY d.id LIMIT 1"; }
APP_HOST="$(app_host "$A" move-e2e)"
printf '%s' "$APP_HOST" | grep -q '\.localhost$' || fail "the app has no *.localhost domain ($APP_HOST)"

# container_of VPS SERVICE_NAME: the running workload container of a service (by its labels).
container_of() {
	svc="$(psql_on "$PSQL_VPS" "SELECT s.public_id FROM services s JOIN projects p ON p.id = s.project_id WHERE s.name = '$2' AND p.name = '$PROJECT' LIMIT 1")"
	on "$1" docker ps -q --filter "label=launchroom.service=$svc" --filter status=running | head -n 1
}
# sql VPS SQL: runs SQL as the app's database user in the project's Postgres on VPS.
sql() {
	cid="$(container_of "$1" postgres)"
	[ -n "$cid" ] || return 1
	# shellcheck disable=SC2016 # expanded by the container's shell
	on "$1" docker exec -e SQL="$2" "$cid" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$SQL"'
}
# web_file VPS TEXT: the app's volume holds TEXT as its index page.
web_file() {
	cid="$(container_of "$1" web)"
	[ -n "$cid" ] || return 1
	# shellcheck disable=SC2016 # expanded by the container's shell
	on "$1" docker exec -u 0 -e TEXT="$2" "$cid" sh -c 'printf "%s\n" "$TEXT" > /usr/share/nginx/html/data/index.html'
}
# served VPS HOST: what the app answers through that VPS's edge.
# (The volume is mounted at data/, so nginx's own page answers the health check.)
served() { on "$1" docker run --rm --network host --entrypoint curl "$DASHBOARD_IMAGE" -sk --resolve "$2:443:127.0.0.1" "https://$2/data/"; }

PROJECT=move-e2e
PSQL_VPS="$A"
step "data on A: rows in Postgres, a file in the app's volume"
retry 120 sql "$A" "SELECT 1" || fail "the project's Postgres does not answer"
sql "$A" "CREATE TABLE notes (id serial PRIMARY KEY, body text NOT NULL); INSERT INTO notes (body) VALUES ('one'), ('two'), ('three');" >/dev/null
retry 60 web_file "$A" "written on A v1" || fail "could not write into the app's volume"
retry 120 sh -c "docker exec $A docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $APP_HOST:443:127.0.0.1 https://$APP_HOST/data/ | grep -qx 'written on A v1'" ||
	fail "the app does not serve its file through A's edge: $(served "$A" "$APP_HOST" || true)"
ok "3 rows; $APP_HOST serves 'written on A v1' through A's edge"

step "Settings → Move launchroom on A: the one-time move command"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
token="$(tinker "$A" 'echo app(App\Actions\Moves\StartControlPlaneMove::class)->handle($owner, $actor)["token"], "\n";' | grep '^lrmv1\.' | tail -n 1)"
[ -n "$token" ] || fail "no move token"
ok "token issued (lrmv1.…)"

step "VPS B ($B_IP): install.sh --move <token> (in the background; it waits for the hand-over)"
start_vps "$B" "$B_IP"
write_cp_override "$B"
# shellcheck disable=SC2046 # the -e flags split on purpose
docker exec -d -w /root $(install_env) -e LR_MOVE_TOKEN_ARG="$token" "$B" sh -c \
	"sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain $DOMAIN --no-public-ip-detect --move \"\$LR_MOVE_TOKEN_ARG\" --move-url http://$A_IP:8001 >/root/move.log 2>&1; echo \$? >/root/move.exit"
retry 300 sh -c "docker exec $B grep -Eq 'code is [2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}' /root/move.log" ||
	{ on "$B" cat /root/move.log || true; fail "B's installer did not print a code"; }
code="$(on "$B" sed -n 's/.*code is \([2-9A-HJ-NP-Z]\{4\}-[2-9A-HJ-NP-Z]\{4\}\).*/\1/p' /root/move.log | head -n 1)"
[ "$(psql_on "$A" "SELECT claim_code FROM control_plane_moves WHERE status = 'claimed'")" = "$code" ] || fail "A shows another code than B's terminal"
ok "both sides show $code"

step "the owner confirms the code; B's launchroomd joins A as a server"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$A" 'app(App\Actions\Moves\ApproveControlPlaneMove::class)->handle(App\Models\Moves\ControlPlaneMove::open(), "'"$code"'", $owner, $actor); echo "approved\n";' | grep -q approved ||
	fail "approval failed"
retry 300 sh -c "test \"\$(docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM control_plane_moves ORDER BY id DESC LIMIT 1\")\" = joined" ||
	fail "B did not join: $(psql_on "$A" "SELECT status, error FROM control_plane_moves")"
b_server="$(psql_on "$A" "SELECT s.public_id FROM servers s JOIN control_plane_moves m ON m.target_server_id = s.id ORDER BY m.id DESC LIMIT 1")"
retry 300 sh -c "test -n \"\$(docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT mesh_ip FROM servers WHERE public_id = '$b_server' AND status = 'online' AND mesh_ip IS NOT NULL\")\"" ||
	fail "B is not online on the private network: $(psql_on "$A" "SELECT name, status, mesh_ip, mesh_status FROM servers")"
ok "B is $b_server, online, on the private network"

step "right before the move: one more row, a new file"
sql "$A" "INSERT INTO notes (body) VALUES ('right before the move');" >/dev/null
web_file "$A" "written on A v2"
ok "4 rows, file v2"

step "move every app, database and volume from A to B"
start=$(date +%s)
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$A" 'app(App\Actions\Moves\MoveControlPlaneWorkloads::class)->handle(App\Models\Moves\ControlPlaneMove::open(), false, $owner, $actor); echo "moving\n";' | grep -q moving ||
	fail "could not start moving the workloads"
retry 1200 sh -c "test \"\$(docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM control_plane_moves ORDER BY id DESC LIMIT 1\")\" = ready_to_hand_over" ||
	fail "the workloads did not move: $(psql_on "$A" "SELECT kind, status, failed_step, message FROM server_move_items ORDER BY position")"
ok "everything runs on B after $(($(date +%s) - start)) s: $(psql_on "$A" "SELECT string_agg(kind || '=' || status, ' ') FROM server_move_items")"
downtime="$(psql_on "$A" "SELECT max(extract(epoch FROM finished_at - stopped_at))::int FROM server_move_items WHERE stopped_at IS NOT NULL")"
ok "longest stop of a stateful service: ${downtime:-?} s"
PSQL_VPS="$A"
retry 120 sh -c "docker exec $B docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $APP_HOST:443:127.0.0.1 https://$APP_HOST/data/ | grep -qx 'written on A v2'" ||
	fail "B's edge does not serve the moved app: $(served "$B" "$APP_HOST" || true)"
[ "$(sql "$B" "SELECT count(*) FROM notes")" = 4 ] || fail "the database on B lacks rows: $(sql "$B" "SELECT body FROM notes" || true)"
ok "B's edge serves 'written on A v2'; B's Postgres has all 4 rows"

step "a dashboard change right before the hand-over, then hand over"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$A" 'app(App\Actions\Projects\CreateProject::class)->handle($owner->currentTeam, new App\Data\Projects\CreateProjectData("written-before-handover"), $actor); echo "project\n";' | grep -q project || fail "could not create the project"
start=$(date +%s)
# The installer on B asks every few seconds; hand over once it did.
retry 60 sh -c "test \"\$(docker exec -w $LR $A docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT count(*) FROM control_plane_moves WHERE receiver_seen_at > now() - interval '30 seconds'\")\" = 1" ||
	fail "B's installer isn't waiting"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$A" 'app(App\Actions\Moves\StartHandover::class)->handle(App\Models\Moves\ControlPlaneMove::open(), $actor); echo "handing-over\n";' | grep -q handing-over ||
	fail "the hand-over was refused"
retry 900 docker exec "$B" test -s /root/move.exit || { on "$B" tail -n 60 /root/move.log; fail "B's installer did not finish"; }
[ "$(on "$B" cat /root/move.exit)" = 0 ] || { on "$B" cat /root/move.log; fail "B's installer failed"; }
ok "hand-over and takeover took $(($(date +%s) - start)) s"
if grep -Eq 'lrmv1\.|lr_jt_[A-Za-z0-9_-]{20,}|base64:[A-Za-z0-9+/=]{20,}' "$(on "$B" cat /root/move.log >"$TMP/move.log"; echo "$TMP/move.log")"; then
	fail "the installer's output contains a secret"
fi
ok "no token, join token or APP_KEY in the installer's output"

step "B: the dashboard, its data and its launchroomd"
in_lr "$B" docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | while read -r service state health; do
	[ "$state" = running ] || fail "$service is $state on B"
	case "$service:$health" in *:healthy | scheduler: | zot: | launchroomd:) ;; *) fail "$service health is '$health' on B" ;; esac
done
retry 180 sh -c "docker exec $B docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -fsk --resolve $DOMAIN:443:127.0.0.1 -o /dev/null https://$DOMAIN/login" ||
	fail "B does not serve the dashboard at https://$DOMAIN"
[ "$(psql_on "$B" "SELECT count(*) FROM projects WHERE name IN ('move-e2e', 'written-before-handover')")" = 2 ] || fail "B's dashboard lacks data written before the hand-over"
[ "$(psql_on "$B" "SELECT status FROM control_plane_moves ORDER BY id DESC LIMIT 1")" = handed_over ] || fail "the move is not handed over on B"
[ "$(psql_on "$B" "SELECT public_id FROM servers WHERE is_control_plane AND status <> 'revoked'")" = "$b_server" ] || fail "B's server is not the control plane"
[ "$(psql_on "$B" "SELECT count(*) FROM servers WHERE status = 'revoked' AND replaced_by_server_id IS NOT NULL")" = 1 ] || fail "A's server is not retired on B"
[ "$(psql_on "$B" "SELECT frozen_at IS NULL AND move_checklist IS NOT NULL FROM instance_settings")" = t ] || fail "B is frozen or has no checklist"
in_lr "$B" grep -q '^LR_CONTROL_PLANE_URL=$' .env || fail "B's launchroomd still points at the old dashboard"
retry 120 sh -c "test \"\$(docker exec -w $LR $B docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT count(*) FROM servers WHERE public_id = '$b_server' AND status = 'online' AND last_seen_at > now() - interval '40 seconds'\")\" = 1" ||
	fail "B's launchroomd does not reach its own dashboard"
ok "B's dashboard has everything; B's server is the control plane and online over loopback"

step "B: the app with all its data, through B's edge"
PSQL_VPS="$B"
[ "$(served "$B" "$APP_HOST")" = "written on A v2" ] || fail "the app on B answers '$(served "$B" "$APP_HOST")'"
[ "$(sql "$B" "SELECT string_agg(body, ',' ORDER BY id) FROM notes")" = "one,two,three,right before the move" ] || fail "rows on B: $(sql "$B" "SELECT body FROM notes")"
ok "$APP_HOST serves the newest file; Postgres has every row"

step "A: frozen, moved, decommissioned"
[ "$(psql_on "$A" "SELECT moved_to FROM instance_settings")" = "https://$DOMAIN" ] || fail "A doesn't know where launchroom moved"
retry 120 sh -c "test -z \"\$(docker exec $A docker ps -q --filter name=lr-system-caddy)\"" || fail "A still runs its edge"
[ -z "$(on "$A" docker ps -q --filter label=launchroom.workload)" ] || fail "A still runs workloads: $(on "$A" docker ps --format '{{.Names}}')"
ok "A points to https://$DOMAIN, serves nothing and runs no workloads"

# ---- Move 2: a regular server -----------------------------------------------------

step "Move 2: the private network's login server is B's Headscale now; A goes"
hs_points_at "$B_IP"
docker rm -f -v "$A" >/dev/null
ok "A deleted, hs.e2e-move → B"

# join_server VPS IP NAME ROLES: a new VPS that joins B with server.sh.
join_server() {
	start_vps "$1" "$2"
	# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
	join="$(tinker "$B" '$issued = app(App\Actions\Servers\CreateEnrollment::class)->handle($owner->currentTeam, new App\Data\Servers\CreateEnrollmentData('"$4"', "'"$3"'"), $owner, $actor); echo "TOKEN=", $issued->token, "\n";' | sed -n 's/^TOKEN=//p')"
	[ -n "$join" ] || fail "no join token for $3"
	docker exec -i "$1" sh -c "mkdir -p /root/launchroomd && cat > /root/launchroomd/compose.override.yml" <<'EOF'
services:
  launchroomd:
    environment:
      LR_MESH_INSECURE_LOGIN_HOSTS: hs.e2e-move
EOF
	on "$B" docker run --rm --network host --entrypoint curl "$DASHBOARD_IMAGE" -fs http://127.0.0.1:8000/install/server.sh >"$TMP/server.sh"
	docker cp "$TMP/server.sh" "$1:/root/server.sh"
	docker exec -w /root -e LR_JOIN_TOKEN="$join" -e LR_CONTROL_PLANE_URL="http://$B_IP:8001" -e LR_DAEMON_IMAGE="$DAEMON_IMAGE" -e LAUNCHROOM_DAEMON_DIR=/root/launchroomd -e LR_BUNDLE_DIR=/opt/bundle "$1" \
		sh /root/server.sh >"$TMP/join-$3.log" 2>&1 || { cat "$TMP/join-$3.log"; fail "server.sh on $3 failed"; }
	retry 300 sh -c "test -n \"\$(docker exec -w $LR $B docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT mesh_ip FROM servers WHERE name = '$3' AND status = 'online' AND mesh_ip IS NOT NULL\")\"" ||
		fail "$3 is not online on the private network: $(psql_on "$B" "SELECT name, status, mesh_ip FROM servers")"
}

step "servers C (runtime) and D (build only for now) join B"
join_server "$C" "$C_IP" vps-c '["runtime"]'
join_server "$D" "$D_IP" vps-d '["build"]'
ok "C and D online on the private network"

step "project move2-e2e on C (allow-listed there): Postgres and a volume app"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$B" '
	$c = App\Models\Server::query()->where("name", "vps-c")->sole();
	$project = app(App\Actions\Projects\CreateProject::class)->handle($owner->currentTeam, new App\Data\Projects\CreateProjectData("move2-e2e"), $actor);
	$create = app(App\Actions\Services\CreateService::class);
	$pg = $create->handle($project, App\Data\Services\CreateServiceData::from(["name" => "postgres", "kind" => App\Enums\ServiceKind::Database, "source" => ["type" => "database", "engine" => "postgres", "version" => "18"]]), $owner, $actor);
	$web = $create->handle($project, App\Data\Services\CreateServiceData::from(["name" => "web", "kind" => App\Enums\ServiceKind::Image, "source" => ["type" => "image", "reference" => "nginxinc/nginx-unprivileged:1.27-alpine"], "port" => 8080]), $owner, $actor);
	$environment = $project->production()->sole();
	$instance = App\Models\ServiceInstance::query()->where("service_id", $web->id)->where("environment_id", $environment->id)->sole();
	app(App\Actions\Services\AddServiceVolume::class)->handle($instance, new App\Data\Services\AddVolumeData("/usr/share/nginx/html/data"), $actor);
	app(App\Actions\Connections\CreateConnection::class)->handle($web, $pg, App\Enums\ConnectionMode::Private, App\Enums\ConnectionStyle::Url, $owner, $actor);
	// Only on C (an allow-list): after the move it stands for D.
	foreach (App\Models\ServiceInstance::query()->where("environment_id", $environment->id)->get() as $i) {
		$i->forceFill(["scaling" => new App\Data\Services\ScalingSettings(allowServers: [$c->public_id])])->save();
	}
	app(App\Actions\Deployments\DeployEnvironment::class)->handle($environment, $owner, $actor);
	echo "deployed\n";' >"$TMP/move2-setup.log" 2>&1
grep -q deployed "$TMP/move2-setup.log" || { cat "$TMP/move2-setup.log"; fail "could not create move2-e2e"; }
APP2_HOST="$(app_host "$B" move2-e2e)"
retry 600 sh -c "test \"\$(docker exec -w $LR $B docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT count(*) FROM service_instances i JOIN environments e ON e.id = i.environment_id JOIN projects p ON p.id = e.project_id WHERE p.name = 'move2-e2e' AND i.active_deployment_id IS NOT NULL\")\" = 2" ||
	fail "move2-e2e did not deploy: $(psql_on "$B" "SELECT status, failure_message FROM deployments ORDER BY id DESC LIMIT 4")"
PROJECT=move2-e2e
PSQL_VPS="$B"
retry 120 sql "$C" "SELECT 1" || fail "move2-e2e's Postgres does not answer on C"
sql "$C" "CREATE TABLE notes (id serial PRIMARY KEY, body text NOT NULL); INSERT INTO notes (body) VALUES ('c1'), ('c2');" >/dev/null
retry 60 web_file "$C" "written on C" || fail "could not write into the volume on C"
retry 180 sh -c "docker exec $B docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $APP2_HOST:443:127.0.0.1 https://$APP2_HOST/data/ | grep -qx 'written on C'" ||
	fail "B's edge does not serve the app on C: $(served "$B" "$APP2_HOST" || true)"
ok "move2-e2e runs on C and serves through B's edge"

step "Servers → C → Move to another server: D"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$B" '
	$d = App\Models\Server::query()->where("name", "vps-d")->sole();
	app(App\Actions\Servers\UpdateServerRoles::class)->handle($d, new App\Data\Servers\UpdateServerRolesData(["runtime", "build"]), $actor);
	echo "d-ready\n";' | grep -q d-ready || fail "could not make D a runtime server"
sql "$C" "INSERT INTO notes (body) VALUES ('right before the move');" >/dev/null
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$B" '
	$c = App\Models\Server::query()->where("name", "vps-c")->sole();
	$d = App\Models\Server::query()->where("name", "vps-d")->sole();
	$plan = app(App\Services\Moves\MovePlanner::class)->plan($c, $d);
	if ($plan->blockers !== []) { echo "BLOCKED ", implode(" | ", $plan->blockers), "\n"; return; }
	app(App\Actions\Moves\StartServerMove::class)->handle($c, $d, false, $owner, $actor);
	echo "move-started\n";' >"$TMP/move2.log" 2>&1
grep -q move-started "$TMP/move2.log" || { cat "$TMP/move2.log"; fail "the move was refused"; }
start=$(date +%s)
retry 1200 sh -c "test \"\$(docker exec -w $LR $B docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM server_moves ORDER BY id DESC LIMIT 1\")\" = ready" ||
	fail "C did not move: $(psql_on "$B" "SELECT kind, status, failed_step, message FROM server_move_items WHERE server_move_id = (SELECT max(id) FROM server_moves) ORDER BY position")"
ok "everything moved to D in $(($(date +%s) - start)) s: $(psql_on "$B" "SELECT string_agg(kind || '=' || status, ' ') FROM server_move_items WHERE server_move_id = (SELECT max(id) FROM server_moves)")"
[ "$(sql "$D" "SELECT string_agg(body, ',' ORDER BY id) FROM notes")" = "c1,c2,right before the move" ] || fail "rows on D: $(sql "$D" "SELECT body FROM notes" || true)"
retry 120 sh -c "docker exec $B docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $APP2_HOST:443:127.0.0.1 https://$APP2_HOST/data/ | grep -qx 'written on C'" ||
	fail "the app on D does not serve its file: $(served "$B" "$APP2_HOST" || true)"
ok "D has every row and the file; B's edge serves it"

step "retire C"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
tinker "$B" 'app(App\Actions\Moves\RetireMovedServer::class)->handle(App\Models\Moves\ServerMove::query()->latest("id")->firstOrFail(), $actor); echo "retired\n";' | grep -q retired ||
	fail "retiring C failed"
[ "$(psql_on "$B" "SELECT status FROM servers WHERE name = 'vps-c'")" = revoked ] || fail "C is not revoked"
retry 120 sh -c "test -z \"\$(docker exec $C docker ps -q --filter label=launchroom.workload)\"" || fail "C still runs workloads"
ok "C is revoked and runs nothing; its volumes stay on its disk"

printf '\nPASS: the control plane moved from A to B with its apps, data and domain; a regular server moved from C to D\n'
