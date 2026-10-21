#!/bin/sh
# End-to-end test of the dashboard-driven control-plane update (`make
# test-update`, docs/11 §4).
#
# Like `make test-install`, a privileged docker:dind container plays a fresh
# VPS (no host ports, nothing from the host mounted). Three releases are
# made from the locally built images:
#
#   1.0.0  the installed version (dashboard + launchroomd built with VERSION=1.0.0)
#   1.1.0  a good release (rebuilt with VERSION=1.1.0)
#   1.2.0  a broken release (its dashboard never becomes healthy)
#
# Each release has its own edge Caddy image (stock caddy with a version
# label, so a distinct digest), compiled into its launchroomd like a real
# release (CADDY_DIGEST): the update must move the edge to the new pin.
#
# The images are pushed to a registry inside the VPS (127.0.0.1:5050), so the
# bundles pin them by digest like a real release; the bundles come from
# deploy/release/build-bundle.sh, are signed with a throwaway key
# (daemon/test/releasesign, key-based trust: private builds and tests only)
# and served from a release base inside the VPS (127.0.0.1:8088). After
# installing 1.0.0, data/daemon/trust.json and guard/trust.json are pointed at that key and base.
#
# Then, as an owner would from Settings → Updates (the GitHub Releases API is
# faked in the tinker process that starts the update; everything after that
# runs for real in horizon, the scheduler, launchroomd and its supervisor):
#
#   - 1.0.0 → 1.1.0 with a control-plane backup destination: the pre-upgrade
#     backup succeeds, the self_update task verifies and installs the bundle
#     (the real install.sh, re-run by the supervisor), migrations run, the
#     new dashboard and launchroomd come up, and the update is recorded as
#     succeeded by the new dashboard; data is kept.
#   - 1.1.0 → 1.2.0: the installer fails, the supervisor restores files and
#     database, 1.1.0 comes back, and the update is recorded as rolled back.
#
# Everything on the host is named launchroom-dev-e2e* and labelled
# launchroom.dev=true; the VPS, the S3 target and their storage are removed at
# the end (KEEP=1 keeps them; SKIP_BUILD=1 reuses already built images). Run
# from the repo root.
set -eu

VPS=launchroom-dev-e2e-update-vps
DIND_IMAGE="docker:29-dind@sha256:3e5756e2b6dad6a5f1f5deacf0a19f9eaa2581b76ae092b2d4a118162e1d4d4e"
# registry:3.1.2 (same pin as the self-update integration test)
REGISTRY_IMAGE="docker.io/library/registry@sha256:ddf754342cfc8acc51a56d5d0ab6af06826461864460636d8bd5c546dab2a7b8"
REGISTRY=127.0.0.1:5050
RELEASES=127.0.0.1:8088
IMG=launchroom-dev-e2e-update
# caddy:2.11.7, the base of every release's edge Caddy image here (as for launchroom-caddy).
STOCK_CADDY="docker.io/library/caddy:2.11.7@sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af"
DOMAIN=update.localhost
LR=/root/launchroom
S3=launchroom-dev-e2e-update-s3
S3_NET=launchroom-dev-e2e-update-net
S3_IMAGE="docker.io/versity/versitygw@sha256:eb7f786fcd91e72194e48fcf1ac16302d686bf8e3a71434bdbc067a6efd3fdc7"
S3_KEY=launchroom-e2e
S3_SECRET=launchroom-e2e-secret
S3_BUCKET=launchroom-e2e
COMPOSE="docker compose --project-directory dev --env-file dev/.env -f dev/compose.yml"
# The dev stack may run with more services (make up-multi); the tool runs here are one-offs.
export COMPOSE_IGNORE_ORPHANS=true
WORK=dev/data/e2e-update

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nFAIL: %s\n' "$*" >&2; exit 1; }
ok() { printf '    ok: %s\n' "$*"; }
in_vps() { docker exec "$VPS" "$@"; }
in_lr() { docker exec -w "$LR" "$VPS" "$@"; }
psql() { in_lr docker compose exec -T postgres psql -U launchroom -d launchroom -tAc "$1"; }

cleanup() {
	status=$?
	if [ "${KEEP:-0}" = 1 ]; then
		echo "KEEP=1: leaving $VPS and $S3 running (remove: docker rm -f -v $VPS $S3 && docker network rm $S3_NET)"
	else
		docker rm -f -v "$VPS" "$S3" >/dev/null 2>&1 || true
		docker network rm "$S3_NET" >/dev/null 2>&1 || true
	fi
	rm -rf "$WORK"
	exit "$status"
}
trap cleanup EXIT INT TERM

retry() {
	limit=$1
	shift
	start=$(date +%s)
	until "$@" >/dev/null 2>&1; do
		[ $(($(date +%s) - start)) -lt "$limit" ] || return 1
		sleep 3
	done
}

if [ "${SKIP_BUILD:-0}" = 1 ]; then
	step "SKIP_BUILD=1: using the existing $IMG-dashboard and $IMG-caddy images"
else
	step "building the dashboard (production target) and the edge Caddy for 1.0.0 and 1.1.0"
	for v in 1.0.0 1.1.0; do
		docker build -q -f deploy/images/dashboard/Dockerfile --target production --build-arg VERSION=$v \
			--label launchroom.dev=true -t "$IMG-dashboard:$v" . >/dev/null
		printf 'FROM %s\nLABEL org.opencontainers.image.version=%s\n' "$STOCK_CADDY" "$v" |
			docker build -q --label launchroom.dev=true -t "$IMG-caddy:$v" - >/dev/null
	done
	# 1.2.0: a dashboard that never becomes healthy (the web process exits).
	printf 'FROM %s\nENV LAUNCHROOM_VERSION=1.2.0\nCMD ["false"]\n' "$IMG-dashboard:1.1.0" |
		docker build -q --label launchroom.dev=true -t "$IMG-dashboard:1.2.0" - >/dev/null
	ok "built"
fi

step "starting a fresh VPS ($VPS) with a registry and a release base"
docker rm -f -v "$VPS" >/dev/null 2>&1 || true
docker run -d --name "$VPS" --hostname e2e-update-vps --privileged --label launchroom.dev=true \
	--mount type=volume,dst=/var/lib/docker,volume-label=launchroom.dev=true "$DIND_IMAGE" >/dev/null
retry 60 in_vps docker info || fail "the VPS's Docker daemon did not start"
docker save "$IMG-dashboard:1.0.0" "$IMG-dashboard:1.1.0" "$IMG-dashboard:1.2.0" "$IMG-caddy:1.0.0" "$IMG-caddy:1.1.0" |
	docker exec -i "$VPS" docker load -q >/dev/null
in_vps docker run -d --name e2e-registry --network host -e REGISTRY_HTTP_ADDR=$REGISTRY "$REGISTRY_IMAGE" >/dev/null
retry 60 in_vps wget -q -O /dev/null "http://$REGISTRY/v2/" || fail "the registry did not start"

# push NAME VERSION: pushes the image and prints its digest-pinned reference.
push() {
	ref="$REGISTRY/vantezzen/launchroom-$1:$2"
	in_vps docker tag "$IMG-$1:$2" "$ref"
	in_vps docker push -q "$ref" >/dev/null
	in_vps docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" | grep "^$REGISTRY/" | head -n 1
}
d100="$(push dashboard 1.0.0)" && d110="$(push dashboard 1.1.0)" && d120="$(push dashboard 1.2.0)"
c100="$(push caddy 1.0.0)" && c110="$(push caddy 1.1.0)"
[ "${c100#*@}" != "${c110#*@}" ] || fail "the 1.0.0 and 1.1.0 edge Caddy images have the same digest"
# launchroomd of each release pins its own edge Caddy (as release.yml builds it).
for spec in "1.0.0 ${c100#*@}" "1.1.0 ${c110#*@}"; do
	# shellcheck disable=SC2086 # two words on purpose
	set -- $spec
	docker build -q --build-arg VERSION="$1" --build-arg CADDY_DIGEST="$2" --label launchroom.dev=true -t "$IMG-daemon:$1" daemon >/dev/null
done
docker save "$IMG-daemon:1.0.0" "$IMG-daemon:1.1.0" | docker exec -i "$VPS" docker load -q >/dev/null
a100="$(push daemon 1.0.0)" && a110="$(push daemon 1.1.0)"
for ref in "$d100" "$d110" "$d120" "$c100" "$c110" "$a100" "$a110"; do
	case "$ref" in *@sha256:*) ;; *) fail "no digest for $ref" ;; esac
done
ok "images pushed to $REGISTRY and pinned by digest"

step "release bundles 1.0.0, 1.1.0, 1.2.0 (build-bundle.sh) signed with a throwaway key"
rm -rf "$WORK" && mkdir -p "$WORK"
$COMPOSE build -q go
for spec in "1.0.0 $d100 $a100 $c100" "1.1.0 $d110 $a110 $c110" "1.2.0 $d120 $a110 $c110"; do
	# shellcheck disable=SC2086 # four words on purpose
	set -- $spec
	$COMPOSE run --rm --no-deps -T -v "$PWD/$WORK:/work" -e SOURCE_DATE_EPOCH=0 go \
		sh /src/deploy/release/build-bundle.sh --version "$1" --dashboard-image "$2" --daemon-image "$3" --caddy-image "$4" --out "/work/v$1" >/dev/null
done
$COMPOSE run --rm --no-deps -T -v "$PWD/$WORK:/work" -e GOCACHE=/tmp/go-build go \
	go run ./test/releasesign /work/release.pub /work/v1.0.0/launchroom-deploy.tar.gz /work/v1.1.0/launchroom-deploy.tar.gz /work/v1.2.0/launchroom-deploy.tar.gz
in_vps mkdir -p /srv/releases/download /opt/bundle-1.0.0
for v in 1.0.0 1.1.0 1.2.0; do docker cp "$WORK/v$v" "$VPS:/srv/releases/download/v$v"; done
in_vps tar -xzf /srv/releases/download/v1.0.0/launchroom-deploy.tar.gz -C /opt/bundle-1.0.0
in_vps docker run -d --name e2e-releases --network host -v /srv:/srv:ro --entrypoint frankenphp "$d100" \
	file-server --listen "$RELEASES" --root /srv >/dev/null
retry 60 in_vps wget -q -O /dev/null "http://$RELEASES/releases/download/v1.1.0/launchroom-deploy.tar.gz.sha256" ||
	fail "the release base does not serve the bundles"
ok "bundles signed and served at http://$RELEASES/releases"

step "installing 1.0.0 (install.sh --bundle, digest-pinned images)"
docker exec -w /root "$VPS" sh /opt/bundle-1.0.0/install/install.sh --bundle /opt/bundle-1.0.0 \
	--domain "$DOMAIN" --no-public-ip-detect >"$WORK/install.log" 2>&1 || { cat "$WORK/install.log"; fail "install.sh failed"; }
retry 120 sh -c "docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM servers WHERE is_control_plane\" | grep -qx online" ||
	fail "control-plane server is not online"
[ "$(in_lr docker compose exec -T dashboard php artisan tinker --execute='echo config("launchroom.version");')" = 1.0.0 ] || fail "the dashboard is not 1.0.0"
retry 60 sh -c "[ \"\$(docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc 'SELECT daemon_version FROM servers WHERE is_control_plane')\" = 1.0.0 ]" ||
	fail "launchroomd is not 1.0.0"
# edge_caddy REF: the control-plane server's edge Caddy runs REF (the release's pin).
edge_caddy() { [ "$(docker exec "$VPS" docker inspect lr-system-caddy --format '{{.Config.Image}}' 2>/dev/null)" = "$1" ]; }
retry 120 edge_caddy "$c100" || fail "the edge Caddy does not run the 1.0.0 image $c100"
pub="$(sed 's/$/\\n/' "$WORK/release.pub" | tr -d '\n')"
in_vps sh -c "printf '%s\n' '{\"releaseBase\": \"http://$RELEASES/releases\", \"publicKey\": \"$pub\", \"allowInsecureReleaseBase\": true}' >$LR/data/daemon/trust.json"
# The guarded update supervisor re-verifies against its own copy outside
# launchroomd's data folder (docs/09 §6), so the test pin goes there too.
in_vps cp $LR/data/daemon/trust.json $LR/guard/trust.json
ok "1.0.0 installed and online, edge Caddy on the 1.0.0 pin; data/daemon/trust.json and guard/trust.json pin the test key and release base"

step "owner, recovery passphrase and control-plane backup destination (S3 on $S3_NET)"
docker rm -f -v "$S3" >/dev/null 2>&1 || true
docker network rm "$S3_NET" >/dev/null 2>&1 || true
docker network create --label launchroom.dev=true "$S3_NET" >/dev/null
docker run -d --name "$S3" --label launchroom.dev=true --network "$S3_NET" \
	-e ROOT_ACCESS_KEY="$S3_KEY" -e ROOT_SECRET_KEY="$S3_SECRET" \
	--mount type=volume,dst=/data,volume-label=launchroom.dev=true "$S3_IMAGE" posix /data >/dev/null
docker network connect "$S3_NET" "$VPS"
s3_ip="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$S3_NET\").IPAddress}}" "$S3")"
token="$(in_lr docker compose exec -T dashboard php artisan launchroom:setup-token --plain)"
# shellcheck disable=SC2016 # PHP code
in_lr docker compose exec -T -e E2E_SETUP_TOKEN="$token" -e E2E_S3_ENDPOINT="http://$s3_ip:7070" \
	-e E2E_S3_BUCKET="$S3_BUCKET" -e E2E_S3_KEY="$S3_KEY" -e E2E_S3_SECRET="$S3_SECRET" \
	dashboard php artisan tinker --no-interaction --execute='
		$actor = App\Data\Audit\AuditActor::system();
		$owner = app(App\Actions\Instance\CreateInstanceOwner::class)->handle(new App\Data\Instance\CreateOwnerData(getenv("E2E_SETUP_TOKEN"), "E2E Owner", "owner@e2e.test", "e2e-owner-password-1234"), $actor);
		app(App\Actions\Projects\CreateProject::class)->handle($owner->refresh()->currentTeam, new App\Data\Projects\CreateProjectData("update-e2e"), $actor);
		app(App\Actions\Backups\SetRecoveryPassphrase::class)->handle("e2e update passphrase ".time(), $actor);
		app(App\Actions\Backups\CreateBackupDestination::class)->handle(null, new App\Data\Backups\BackupDestinationInput(name: "e2e", endpoint: getenv("E2E_S3_ENDPOINT"), bucket: getenv("E2E_S3_BUCKET"), accessKeyId: getenv("E2E_S3_KEY"), secretAccessKey: getenv("E2E_S3_SECRET"), allowPrivateNetwork: true), $owner, $actor);
		echo "e2e-fixtures-created\n";' >"$WORK/tinker.log" 2>&1 || { cat "$WORK/tinker.log"; fail "could not create the fixtures"; }
grep -q e2e-fixtures-created "$WORK/tinker.log" || { cat "$WORK/tinker.log"; fail "the fixture script did not finish"; }
project="$(psql "SELECT public_id FROM projects WHERE name = 'update-e2e'")"
ok "owner, project $project and destination created"

# start_update VERSION DASHBOARD_IMAGE DAEMON_IMAGE: what "Update now" on
# Settings → Updates does, with GitHub's Releases API faked in this process
# only (the release, its images.env asset).
start_update() {
	# shellcheck disable=SC2016 # PHP code
	in_lr docker compose exec -T -e E2E_VERSION="$1" -e E2E_DASHBOARD="$2" -e E2E_DAEMON="$3" \
		dashboard php artisan tinker --no-interaction --execute='
			$v = getenv("E2E_VERSION");
			$api = "https://api.github.com/repos/vantezzen/launchroom/releases";
			app()->instance(App\Contracts\DnsResolver::class, new class implements App\Contracts\DnsResolver { public function resolve(string $hostname): array { return ["140.82.121.6"]; } });
			Illuminate\Support\Facades\Http::fake([
				"$api/tags/v$v" => Illuminate\Support\Facades\Http::response(["tag_name" => "v$v", "name" => "launchroom $v", "body" => "## Faster deploys\n- e2e", "html_url" => "https://github.com/vantezzen/launchroom/releases/tag/v$v", "published_at" => now()->toIso8601String(), "draft" => false, "prerelease" => false, "assets" => [["name" => "images.env", "url" => "$api/assets/1"]]]),
				"$api/assets/1" => Illuminate\Support\Facades\Http::response("LR_DASHBOARD_IMAGE=".getenv("E2E_DASHBOARD")."\nLR_DAEMON_IMAGE=".getenv("E2E_DAEMON")."\n"),
			]);
			$owner = App\Models\User::query()->where("email", "owner@e2e.test")->sole();
			$u = app(App\Actions\Updates\StartControlPlaneUpdate::class)->handle($owner, new App\Data\Updates\StartControlPlaneUpdateInput($v), App\Data\Audit\AuditActor::system());
			echo "e2e-update-started ".$u->public_id."\n";' >"$WORK/start.log" 2>&1 || { cat "$WORK/start.log"; fail "could not start the update to $1"; }
	grep -q e2e-update-started "$WORK/start.log" || { cat "$WORK/start.log"; fail "the update to $1 did not start"; }
}

# wait_update VERSION: waits until the update to VERSION is finished and prints its status.
wait_update() {
	start=$(date +%s)
	while :; do
		s="$(psql "SELECT status FROM control_plane_updates WHERE to_version = '$1' ORDER BY id DESC LIMIT 1" 2>/dev/null || true)"
		case "$s" in succeeded | failed | rolled_back) printf '%s' "$s"; return ;; esac
		[ $(($(date +%s) - start)) -lt 1500 ] || { printf 'timeout(%s)' "$s"; return; }
		sleep 5
	done
}

healthy() {
	in_lr docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | while read -r service state health; do
		[ "$state" = running ] || fail "$service is $state ($1)"
		case "$service:$health" in
		*:healthy | scheduler: | zot: | launchroomd:) ;;
		*) fail "$service health is '$health' ($1)" ;;
		esac
	done
}

step "update 1.0.0 → 1.1.0 (pre-upgrade backup, signed bundle, real installer)"
start=$(date +%s)
start_update 1.1.0 "$d110" "$a110"
status="$(wait_update 1.1.0)"
[ "$status" = succeeded ] || {
	psql "SELECT status, error FROM control_plane_updates ORDER BY id"
	psql "SELECT status, error, progress FROM daemon_tasks WHERE type = 'self_update'"
	in_lr docker compose logs --tail 80 launchroomd
	fail "the update to 1.1.0 ended $status"
}
ok "succeeded in $(($(date +%s) - start)) s"
[ "$(psql "SELECT r.status || ' ' || r.reason FROM control_plane_updates u JOIN backup_runs r ON r.id = u.backup_run_id WHERE u.to_version = '1.1.0'")" = "succeeded pre_upgrade" ] ||
	fail "no successful pre-upgrade backup recorded"
ok "pre-upgrade backup succeeded before anything changed"
healthy "after 1.1.0"
[ "$(in_lr docker compose exec -T dashboard php artisan tinker --execute='echo config("launchroom.version");')" = 1.1.0 ] || fail "the dashboard is not 1.1.0"
retry 120 sh -c "[ \"\$(docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc 'SELECT daemon_version FROM servers WHERE is_control_plane')\" = 1.1.0 ]" ||
	fail "launchroomd does not report 1.1.0"
in_lr grep -qx "LR_DASHBOARD_IMAGE=$d110" .env || fail ".env does not pin the 1.1.0 dashboard"
[ "$(psql "SELECT public_id FROM projects WHERE name = 'update-e2e'")" = "$project" ] || fail "project $project is gone"
in_vps test ! -e "$LR/data/postgres/launchroom-pre-upgrade.dump" || fail "the pre-upgrade dump was left behind"
[ -z "$(in_vps docker ps -aq --filter label=launchroom.role=updater)" ] || fail "the update supervisor was left behind"
[ "$(psql "SELECT count(*) FROM audit_events WHERE action = 'instance.update_finished'")" -ge 1 ] || fail "the finished update was not audited"
retry 60 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $d110 -fsk --resolve $DOMAIN:443:127.0.0.1 -o /dev/null https://$DOMAIN/up" ||
	fail "https://$DOMAIN/up is not served after the update"
retry 120 edge_caddy "$c110" || fail "the edge Caddy did not move to the 1.1.0 image $c110 (runs $(in_vps docker inspect lr-system-caddy --format '{{.Config.Image}}'))"
ok "dashboard and launchroomd run 1.1.0, edge Caddy on the 1.1.0 pin, data kept, dump and supervisor cleaned up, audited, served through Caddy"

step "update 1.1.0 → 1.2.0 (broken release: rolled back)"
start=$(date +%s)
start_update 1.2.0 "$d120" "$a110"
status="$(wait_update 1.2.0)"
[ "$status" = rolled_back ] || {
	psql "SELECT status, error FROM control_plane_updates ORDER BY id"
	in_lr docker compose logs --tail 80 launchroomd
	fail "the broken update ended $status, want rolled_back"
}
ok "rolled back in $(($(date +%s) - start)) s: $(psql "SELECT error FROM control_plane_updates WHERE to_version = '1.2.0'")"
healthy "after the rollback"
[ "$(in_lr docker compose exec -T dashboard php artisan tinker --execute='echo config("launchroom.version");')" = 1.1.0 ] || fail "the dashboard is not back on 1.1.0"
in_lr grep -qx "LR_DASHBOARD_IMAGE=$d110" .env || fail ".env was not restored"
[ "$(psql "SELECT public_id FROM projects WHERE name = 'update-e2e'")" = "$project" ] || fail "project $project is gone after the rollback"
[ "$(psql "SELECT status FROM control_plane_updates WHERE to_version = '1.1.0'")" = succeeded ] || fail "the database restore lost the earlier update"
retry 120 edge_caddy "$c110" || fail "the edge Caddy is not on the 1.1.0 image after the rollback"
ok "1.1.0 is back with its files, database and edge Caddy"

printf '\nPASS: control-plane update with backup, signed bundle and installer; broken release rolled back\n'
