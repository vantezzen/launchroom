#!/bin/sh
# End-to-end test of a fresh control-plane install (`make test-install`).
#
# A privileged docker:dind container plays a fresh VPS: its own Docker daemon,
# no host ports, nothing from the host mounted. The locally built dashboard
# (production target), launchroom-caddy (deploy/images/caddy) and daemon
# images are streamed into it with `docker save | docker load` (no registry);
# launchroomd is built like a release, with the loaded launchroom-caddy's
# digest compiled in (CADDY_DIGEST). postgres, valkey and zot are pulled from
# the internet by the nested daemon, like on a real server.
# Then install.sh runs from the repo's deploy/ folder as the bundle, and we
# check: services healthy, the edge Caddy is the pinned launchroom-caddy
# (and launchroomd pins nothing else), /up through launchroomd's Caddy, setup token printed,
# local launchroomd enrolled and online, /install/server.sh templated, an idempotent
# re-run, and that `launchroom uninstall` removes everything it labelled.
#
# Restore scenario (docs/14 §5.2, §9.15): an owner, a project, the recovery
# passphrase and a control-plane backup destination are created (versitygw,
# a labelled sibling container on a labelled network the VPS joins; no host
# ports), a control-plane backup runs, the control plane is wiped with
# `launchroom uninstall` (folder, data, containers, volumes: a new VPS as
# far as launchroom is concerned), and `install.sh --restore` brings it
# back: same data, APP_KEY and signing key, the owner can sign in, the local
# launchroomd enrolls as a new server and comes online, the dashboard is in
# recovery mode with the previous control-plane server to replace, and a new
# backup (decrypting the restored destination credentials) succeeds.
#
# Dashboard domain scenario (docs/05 §4.1): the owner moves the dashboard to
# another name through Settings → Dashboard domain's actions; the checks
# (DNS, Caddy route + internal-CA certificate, HTTPS test through the host
# gateway) pass in horizon, the switch moves the canonical origin, the old
# origin forwards browsers but keeps launchroomd's endpoints and the installer,
# and both `install.sh --domain` and `./launchroom dashboard-domain-reset`
# make APP_URL canonical again.
#
# Client certificates scenario (docs/03 §4.3): Check and Require through
# `launchroom:client-certificates`; the edge refuses daemon API requests
# without a certificate and foreign certificates, lets this instance's
# certificates through, browsers are unaffected, the local launchroomd
# stays online; then off again.
#
# Everything on the host is named launchroom-dev-e2e* and labelled
# launchroom.dev=true; the VPS container and its storage are removed at the end
# (KEEP=1 keeps the VPS for debugging; SKIP_BUILD=1 reuses the already built
# dashboard and caddy images; launchroomd is always built, from the cache, for
# the digest the VPS reports). Run from the repo root.
set -eu

VPS=launchroom-dev-e2e-vps
DIND_IMAGE="docker:29-dind@sha256:3e5756e2b6dad6a5f1f5deacf0a19f9eaa2581b76ae092b2d4a118162e1d4d4e"
DASHBOARD_IMAGE=launchroom-dev-e2e-dashboard:test
DAEMON_IMAGE=launchroom-dev-e2e-daemon:test
CADDY_IMAGE=launchroom-dev-e2e-caddy:test
DOMAIN=test.localhost
# Settings → Dashboard domain scenario (docs/05 §4.1).
NEW_DOMAIN=dash.test-new.localhost
LR=/root/launchroom
# S3 target for the restore scenario: versitygw v1.9.0 (same pin as dev/compose.yml).
S3=launchroom-dev-e2e-s3
S3_NET=launchroom-dev-e2e-net
S3_IMAGE="docker.io/versity/versitygw@sha256:eb7f786fcd91e72194e48fcf1ac16302d686bf8e3a71434bdbc067a6efd3fdc7"
S3_KEY=launchroom-e2e
S3_SECRET=launchroom-e2e-secret
S3_BUCKET=launchroom-e2e
PASSPHRASE="e2e restore passphrase $(date +%s)"

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nFAIL: %s\n' "$*" >&2; exit 1; }
ok() { printf '    ok: %s\n' "$*"; }
in_vps() { docker exec "$VPS" "$@"; }
in_lr() { docker exec -w "$LR" "$VPS" "$@"; }

TMP="$(mktemp -d)"

cleanup() {
	status=$?
	rm -rf "$TMP"
	if [ "${KEEP:-0}" = 1 ]; then
		echo "KEEP=1: leaving $VPS and $S3 running (remove: docker rm -f -v $VPS $S3 && docker network rm $S3_NET)"
	else
		docker rm -f -v "$VPS" "$S3" >/dev/null 2>&1 || true
		docker network rm "$S3_NET" >/dev/null 2>&1 || true
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

if [ "${SKIP_BUILD:-0}" = 1 ]; then
	step "SKIP_BUILD=1: using the existing $DASHBOARD_IMAGE and $CADDY_IMAGE"
else
	step "building images (dashboard production target, launchroom-caddy)"
	docker build -q -f deploy/images/dashboard/Dockerfile --target production \
		--label launchroom.dev=true -t "$DASHBOARD_IMAGE" . >/dev/null
	docker build -q --label launchroom.dev=true -t "$CADDY_IMAGE" deploy/images/caddy >/dev/null
	ok "built $DASHBOARD_IMAGE and $CADDY_IMAGE"
fi

step "starting a fresh VPS ($VPS, docker:dind, no host ports)"
docker rm -f -v "$VPS" >/dev/null 2>&1 || true
docker run -d --name "$VPS" --hostname e2e-vps --privileged \
	--label launchroom.dev=true \
	--mount type=volume,dst=/var/lib/docker,volume-label=launchroom.dev=true \
	"$DIND_IMAGE" >/dev/null
retry 60 in_vps docker info || fail "the VPS's Docker daemon did not start"
ok "Docker $(in_vps docker version --format '{{.Server.Version}}') ready"

step "loading launchroom-caddy, building launchroomd with its digest (as a release does)"
docker save "$CADDY_IMAGE" | docker exec -i "$VPS" docker load -q
caddy_digest="$(in_vps docker image inspect "$CADDY_IMAGE" --format '{{index .RepoDigests 0}}' | sed 's/.*@//')"
printf '%s' "$caddy_digest" | grep -Eq '^sha256:[0-9a-f]{64}$' || fail "no digest for $CADDY_IMAGE in the VPS ($caddy_digest)"
CADDY_REF="docker.io/library/${CADDY_IMAGE%%:*}@$caddy_digest"
docker build -q --build-arg CADDY_DIGEST="$caddy_digest" --label launchroom.dev=true -t "$DAEMON_IMAGE" daemon >/dev/null
ok "built $DAEMON_IMAGE for $CADDY_REF"

step "loading the local images into the VPS (docker save | docker load)"
docker save "$DASHBOARD_IMAGE" "$DAEMON_IMAGE" | docker exec -i "$VPS" docker load -q
docker cp deploy "$VPS:/opt/bundle"
pins="$(in_vps docker run --rm "$DAEMON_IMAGE" system-images)"
[ "$(printf '%s\n' "$pins" | grep '^caddy=')" = "caddy=$caddy_digest" ] || fail "launchroomd does not pin exactly $caddy_digest: $pins"
ok "images loaded (launchroomd pins caddy=$caddy_digest only), bundle copied to /opt/bundle"

step "install.sh --bundle /opt/bundle --domain $DOMAIN --no-public-ip-detect"
docker exec -w /root -e LR_DASHBOARD_IMAGE="$DASHBOARD_IMAGE" -e LR_DAEMON_IMAGE="$DAEMON_IMAGE" -e LR_CADDY_IMAGE="$CADDY_REF" "$VPS" \
	sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain "$DOMAIN" --no-public-ip-detect >"$TMP/install.log" 2>&1 ||
	{ cat "$TMP/install.log"; fail "install.sh failed"; }
cat "$TMP/install.log"
token="$(sed -n "s#.*https://$DOMAIN/setup?token=\(lr_setup_[A-Za-z0-9_-]*\).*#\1#p" "$TMP/install.log" | head -n 1)"
[ -n "$token" ] || fail "no setup token printed"
ok "setup token printed (lr_setup_…)"
if grep -Eq 'lr_jt_[A-Za-z0-9_-]{20,}|base64:[A-Za-z0-9+/=]{20,}|hskey-' "$TMP/install.log"; then fail "install output contains a secret"; fi
ok "no join token or APP_KEY in the output"
rm -f "$TMP/install.log"

step "checking services"
in_lr docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | tee /dev/stderr | while read -r service state health; do
	[ "$state" = running ] || fail "$service is $state"
	case "$service:$health" in
	*:healthy | scheduler: | zot: | launchroomd:) ;;
	*) fail "$service health is '$health'" ;;
	esac
done
ok "all services running, probes healthy"
[ "$(in_lr stat -c %a .env)" = 600 ] || fail ".env is not mode 0600"
in_vps test ! -e "$LR/data/daemon/join-token" || fail "the used join token was left on disk"
ok ".env is 0600, join token removed after enrollment"

step "dockerd-guard owns the Docker socket (D49)"
mounts="$(in_vps docker inspect launchroom-launchroomd-1 --format '{{range .Mounts}}{{.Destination}} {{end}}')"
case " $mounts " in *" /var/run/docker.sock "*) fail "launchroomd still has the Docker socket mounted ($mounts)" ;; esac
in_vps docker inspect launchroom-dockerd-guard-1 --format '{{range .Mounts}}{{.Destination}} {{end}}' | grep -q '/var/run/docker.sock' ||
	fail "dockerd-guard has no Docker socket"
in_vps test -s "$LR/guard/trust.json" || fail "no release pin in guard/trust.json"
# guard_call METHOD PATH [BODY]: an Engine API call on the guard's socket (as launchroomd makes it).
guard_call() {
	in_vps docker run --rm --user 0 --network none -v launchroom_guard-socket:/run/launchroom --entrypoint curl "$DASHBOARD_IMAGE" \
		-s -o /dev/stderr -w '%{http_code}' --unix-socket /run/launchroom/docker.sock -X "$1" -H 'Content-Type: application/json' \
		${3:+-d "$3"} "http://docker/v1.47$2" 2>"$TMP/guard.out"
}
code="$(guard_call POST '/containers/create?name=lr-e2e-evil' '{"Image":"127.0.0.1:5000/x@sha256:0000000000000000000000000000000000000000000000000000000000000000","Labels":{"launchroom.managed":"true","launchroom.server":"srv_01J9Z8X7W6V5T4S3R2Q1P0N9M8"},"HostConfig":{"Privileged":true,"Binds":["/:/host"]}}')"
{ [ "$code" = 403 ] && grep -q 'dockerd-guard' "$TMP/guard.out"; } || fail "the guard did not refuse a privileged container ($code: $(cat "$TMP/guard.out"))"
code="$(guard_call GET "/containers/$(in_vps docker ps -q --filter label=com.docker.compose.service=postgres | head -n 1)/json")"
{ [ "$code" = 403 ] && grep -q 'not managed by launchroom' "$TMP/guard.out"; } || fail "the guard showed launchroomd the postgres container ($code)"
code="$(guard_call POST '/containers/create?name=lr-e2e-sock' '{"Image":"x","HostConfig":{"Binds":["/var/run/docker.sock:/s"]}}')"
[ "$code" = 403 ] || fail "the guard accepted a Docker socket mount ($code)"
code="$(guard_call GET '/containers/json')"
[ "$code" = 200 ] || fail "the guard refused a container list ($code)"
rm -f "$TMP/guard.out"
ok "launchroomd has no Docker socket; the guard refuses privileged containers, host mounts and other containers"

step "local launchroomd enrolled and online"
psql() { in_lr docker compose exec -T postgres psql -U launchroom -d launchroom -tAc "$1"; }
retry 120 sh -c "docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM servers WHERE is_control_plane\" | grep -qx online" ||
	fail "control-plane server is not online: $(psql 'SELECT status FROM servers')"
ok "server $(psql "SELECT public_id || ' ' || status || ' roles=' || roles::text FROM servers WHERE is_control_plane")"
retry 60 sh -c "docker exec -w $LR $VPS docker compose logs launchroomd 2>&1 | grep -q 'firewall rules applied'" ||
	fail "launchroomd did not apply its nftables isolation: $(in_lr docker compose logs launchroomd 2>&1 | grep -i firewall | tail -n 3)"
ok "network isolation applied (nftables table inet launchroom)"

step "dashboard through launchroomd's Caddy (Host: $DOMAIN, internal CA)"
# curl from the dashboard image, on the VPS's host network.
vps_curl() { in_vps docker run --rm --network host --entrypoint curl "$DASHBOARD_IMAGE" -sk --resolve "$DOMAIN:443:127.0.0.1" "$@"; }
retry 180 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -fsk --resolve $DOMAIN:443:127.0.0.1 -o /dev/null https://$DOMAIN/up" ||
	fail "https://$DOMAIN/up is not served by Caddy: $(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/up" || true)"
code="$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/up")"
[ "$code" = 200 ] || fail "/up answered $code"
ok "GET https://$DOMAIN/up -> $code"
# The edge Caddy is the release's launchroom-caddy (docs/05 §4, D47), not stock caddy.
running="$(in_vps docker inspect lr-system-caddy --format '{{.Config.Image}}')"
[ "$running" = "$CADDY_REF" ] || fail "the edge Caddy runs $running, want $CADDY_REF"
modules="$(in_vps docker exec lr-system-caddy caddy list-modules)"
for m in caddy.storage.redis dns.providers.cloudflare dns.providers.hetzner; do
	printf '%s\n' "$modules" | grep -qx "$m" || fail "the edge Caddy lacks $m"
done
[ "$(in_lr docker compose exec -T dashboard php artisan tinker --execute='echo config("launchroom.daemon.system_images.caddy"), " ", var_export(config("launchroom.ingress.caddy_modules"), true);')" = "$CADDY_REF true" ] ||
	fail "the dashboard does not use $CADDY_REF with its modules"
ok "edge Caddy is the pinned launchroom-caddy ($caddy_digest) with storage and DNS modules; the dashboard knows it"
# Mesh (docs/05 §2): Headscale's control endpoints are reachable through
# the dashboard's origin, its REST API is not; the dashboard holds a key.
key="$(vps_curl -fs "https://$DOMAIN/key?v=130")" || fail "Headscale /key is not reachable through the dashboard origin"
printf '%s' "$key" | grep -q '"publicKey"' || fail "unexpected /key answer: $key"
code="$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/api/v1/node")"
[ "$code" != 200 ] && [ "$code" != 401 ] || fail "Headscale's API is routed publicly ($code)"
in_vps test -s "$LR/data/keys/headscale-api-key" || fail "no Headscale bootstrap key for the dashboard"
ok "mesh: /key served by Headscale through Caddy and the dashboard, API not public, bootstrap key handed over"
code="$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/setup?token=$token")"
[ "$code" = 200 ] || fail "/setup answered $code"
ok "GET https://$DOMAIN/setup?token=… -> $code"
redirect="$(in_vps docker run --rm --network host --entrypoint curl "$DASHBOARD_IMAGE" -s -o /dev/null -w '%{http_code} %{redirect_url}' -H "Host: $DOMAIN" http://127.0.0.1/up)"
[ "$redirect" = "308 https://$DOMAIN/up" ] || fail "unexpected http answer: $redirect"
ok "http -> https redirect ($redirect)"

step "/install/server.sh is templated for this instance"
vps_curl -f "https://$DOMAIN/install/server.sh" >"$TMP/server.sh" || fail "could not fetch /install/server.sh"
grep -q "LR_CONTROL_PLANE_URL:-https://$DOMAIN}" "$TMP/server.sh" || fail "server.sh lacks the instance URL"
grep -q 'LR_CP_KEY:-ed25519:' "$TMP/server.sh" || fail "server.sh lacks the CP key"
! grep -Eq '__LAUNCHROOM_(URL|CP_KEY|DAEMON_IMAGE)__|^#__LAUNCHROOM_DAEMON_COMPOSE__$' "$TMP/server.sh" || fail "server.sh still has placeholders"
grep -q "LR_DAEMON_IMAGE:-$DAEMON_IMAGE}" "$TMP/server.sh" || fail "server.sh lacks the launchroomd image"
grep -q '^name: launchroomd$' "$TMP/server.sh" || fail "server.sh lacks the embedded compose file"
docker exec -i "$VPS" sh -n <"$TMP/server.sh" || fail "server.sh is not valid sh"
rm -f "$TMP/server.sh"
ok "URL, CP key, launchroomd image and compose filled in; sh -n passes"

step "re-running install.sh (idempotent)"
key_before="$(in_lr sed -n 's/^APP_KEY=//p' .env)"
docker exec -w /root -e LR_DASHBOARD_IMAGE="$DASHBOARD_IMAGE" -e LR_DAEMON_IMAGE="$DAEMON_IMAGE" -e LR_CADDY_IMAGE="$CADDY_REF" "$VPS" \
	sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain "$DOMAIN" --no-public-ip-detect >/dev/null 2>&1 ||
	fail "second install.sh run failed"
[ "$(in_lr sed -n 's/^APP_KEY=//p' .env)" = "$key_before" ] || fail "re-run changed APP_KEY"
[ "$(psql 'SELECT count(*) FROM servers')" = 1 ] || fail "re-run enrolled a second server"
[ "$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/up")" = 200 ] || fail "dashboard down after re-run"
ok "secrets kept, still one server, dashboard up"

# uninstall_and_check: `launchroom uninstall` leaves nothing behind.
uninstall_and_check() {
	printf 'uninstall\ndelete\n' | docker exec -i -w "$LR" "$VPS" ./launchroom uninstall
	[ -z "$(in_vps docker ps -aq --filter label=launchroom.managed=true)" ] || fail "labelled containers left"
	[ -z "$(in_vps docker network ls -q --filter label=launchroom.managed=true)" ] || fail "labelled networks left"
	[ -z "$(in_vps docker volume ls -q --filter label=launchroom.managed=true)" ] || fail "labelled volumes left"
	in_vps test ! -e "$LR" || fail "$LR still exists"
	left="$(in_vps docker ps -aq)"
	[ -z "$left" ] || fail "containers left on the VPS: $left"
	ok "no containers, networks, volumes or folder left"
}

# Client certificates scenario (mTLS, D50, docs/03 §4.3): Check, then
# Require through the escape-hatch command; the edge Caddy then refuses
# /daemon/v1/* without a certificate of this instance's CA (enroll and
# issuance excepted), refuses a foreign certificate in the handshake, lets a
# certificate of its CA through to the dashboard, leaves browser paths
# alone, and the control-plane launchroomd (loopback) stays online.
step "client certificates: Check, then Require"
lr_artisan() { in_lr docker compose exec -T dashboard php artisan "$@"; }
lr_artisan launchroom:client-certificates verify >/dev/null || fail "could not turn on Check"
lr_artisan launchroom:client-certificates require >/dev/null || fail "Require was refused although the only server is the control plane"
[ "$(lr_artisan launchroom:client-certificates | tr -d '\r')" = require ] || fail "the mode is not require"
edge_says() { vps_curl -s -X "$1" "https://$DOMAIN$2"; }
retry 120 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/daemon/v1/state | grep -q 'client certificate required'" ||
	fail "the edge does not require client certificates: $(edge_says GET /daemon/v1/state)"
ok "GET /daemon/v1/state without a certificate -> refused by the edge"
edge_says POST /daemon/v1/enroll | grep -q '"unauthorized"' || fail "enroll does not reach the dashboard: $(edge_says POST /daemon/v1/enroll)"
edge_says POST /daemon/v1/client-certificate | grep -q '"unauthorized"' || fail "issuance does not reach the dashboard"
ok "enroll and certificate issuance still reach the dashboard (their own checks)"
[ "$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/up")" = 200 ] || fail "/up broke with client certificates"
[ "$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/login")" = 200 ] || fail "/login broke with client certificates"
ok "browser paths unaffected"

# A certificate of this instance's CA (for the control-plane server, whose
# key we make here) and a self-signed one from elsewhere, written by PHP
# into a scratch folder of the VPS; curl presents them.
in_vps mkdir -p /tmp/mtls
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
lr_artisan tinker --no-interaction --execute='
	$k = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
	openssl_pkey_export($k, $pem);
	$pub = openssl_pkey_get_details($k)["key"];
	$server = App\Models\Server::query()->where("is_control_plane", true)->sole();
	$issued = app(App\Services\Daemon\ClientCertificateAuthority::class)->issue($server, $pub, now()->toImmutable()->subMinute(), now()->toImmutable()->addHour());
	echo "-----KEY-----\n", $pem, "-----CERT-----\n", "-----BEGIN CERTIFICATE-----\n", chunk_split(base64_encode($issued->der), 64, "\n"), "-----END CERTIFICATE-----\n";
	$f = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
	$x = openssl_csr_sign(openssl_csr_new(["commonName" => $server->public_id], $f), null, $f, 1);
	openssl_pkey_export($f, $fpem); openssl_x509_export($x, $fcert);
	echo "-----FKEY-----\n", $fpem, "-----FCERT-----\n", $fcert, "-----END-----\n";' >"$TMP/mtls.out" 2>&1 || { cat "$TMP/mtls.out"; fail "could not make test certificates"; }
sed -n '/^-----KEY-----$/,/^-----CERT-----$/p' "$TMP/mtls.out" | sed '1d;$d' | docker exec -i "$VPS" sh -c 'cat > /tmp/mtls/key.pem'
sed -n '/^-----CERT-----$/,/^-----FKEY-----$/p' "$TMP/mtls.out" | sed '1d;$d' | docker exec -i "$VPS" sh -c 'cat > /tmp/mtls/cert.pem'
sed -n '/^-----FKEY-----$/,/^-----FCERT-----$/p' "$TMP/mtls.out" | sed '1d;$d' | docker exec -i "$VPS" sh -c 'cat > /tmp/mtls/foreign-key.pem'
sed -n '/^-----FCERT-----$/,/^-----END-----$/p' "$TMP/mtls.out" | sed '1d;$d' | docker exec -i "$VPS" sh -c 'cat > /tmp/mtls/foreign.pem'
rm -f "$TMP/mtls.out"
mtls_curl() { in_vps docker run --rm --network host -v /tmp/mtls:/mtls:ro --entrypoint curl "$DASHBOARD_IMAGE" -sk --resolve "$DOMAIN:443:127.0.0.1" "$@"; }
body="$(mtls_curl --cert /mtls/cert.pem --key /mtls/key.pem "https://$DOMAIN/daemon/v1/state")"
printf '%s' "$body" | grep -q '"unauthorized"' || fail "a certificate of this CA did not pass the edge: $body"
ok "a certificate of this instance's CA passes the edge (the unsigned request is then refused by the dashboard)"
if mtls_curl -f -o /dev/null --cert /mtls/foreign.pem --key /mtls/foreign-key.pem "https://$DOMAIN/up"; then fail "a foreign certificate was accepted"; fi
ok "a self-signed certificate from elsewhere is refused in the TLS handshake"
in_vps rm -rf /tmp/mtls

sleep 35
[ "$(psql "SELECT status FROM servers WHERE is_control_plane")" = online ] || fail "the control-plane server went offline under Require"
[ "$(psql "SELECT count(*) FROM servers WHERE is_control_plane AND last_seen_at > now() - interval '40 seconds'")" = 1 ] || fail "the control-plane launchroomd stopped reaching the dashboard"
ok "the control-plane launchroomd (loopback) keeps reaching the dashboard"

lr_artisan launchroom:client-certificates off >/dev/null || fail "could not turn client certificates off"
retry 120 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/daemon/v1/state | grep -q unauthorized" ||
	fail "turning certificates off did not reach the edge"
[ "$(psql "SELECT count(*) FROM audit_events WHERE action = 'instance.client_certificates_changed'")" = 3 ] || fail "mode changes were not audited"
ok "off again (the escape hatch), every change audited"

step "restore scenario: S3 target ($S3, versitygw on $S3_NET, no host ports)"
docker rm -f -v "$S3" >/dev/null 2>&1 || true
docker network rm "$S3_NET" >/dev/null 2>&1 || true
docker network create --label launchroom.dev=true "$S3_NET" >/dev/null
docker run -d --name "$S3" --label launchroom.dev=true --network "$S3_NET" \
	-e ROOT_ACCESS_KEY="$S3_KEY" -e ROOT_SECRET_KEY="$S3_SECRET" \
	--mount type=volume,dst=/data,volume-label=launchroom.dev=true \
	"$S3_IMAGE" posix /data >/dev/null
docker network connect "$S3_NET" "$VPS"
s3_ip="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$S3_NET\").IPAddress}}" "$S3")"
[ -n "$s3_ip" ] || fail "no address for $S3"
S3_ENDPOINT="http://$s3_ip:7070"
ok "versitygw at $S3_ENDPOINT"

step "restore scenario: owner, project, recovery passphrase, control-plane backup destination"
# What a user does in the UI (/setup, new project, Settings → Backups),
# through the same actions.
# The re-run above printed a newer setup link: take a fresh one.
token="$(in_lr docker compose exec -T dashboard php artisan launchroom:setup-token --plain)"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
in_lr docker compose exec -T \
	-e E2E_SETUP_TOKEN="$token" -e E2E_PASSPHRASE="$PASSPHRASE" -e E2E_S3_ENDPOINT="$S3_ENDPOINT" \
	-e E2E_S3_BUCKET="$S3_BUCKET" -e E2E_S3_KEY="$S3_KEY" -e E2E_S3_SECRET="$S3_SECRET" \
	dashboard php artisan tinker --no-interaction --execute='
		$actor = App\Data\Audit\AuditActor::system();
		$owner = app(App\Actions\Instance\CreateInstanceOwner::class)->handle(new App\Data\Instance\CreateOwnerData(getenv("E2E_SETUP_TOKEN"), "E2E Owner", "owner@e2e.test", "e2e-owner-password-1234"), $actor);
		app(App\Actions\Projects\CreateProject::class)->handle($owner->refresh()->currentTeam, new App\Data\Projects\CreateProjectData("restore-e2e"), $actor);
		app(App\Actions\Backups\SetRecoveryPassphrase::class)->handle(getenv("E2E_PASSPHRASE"), $actor);
		app(App\Actions\Backups\CreateBackupDestination::class)->handle(null, new App\Data\Backups\BackupDestinationInput(name: "e2e", endpoint: getenv("E2E_S3_ENDPOINT"), bucket: getenv("E2E_S3_BUCKET"), accessKeyId: getenv("E2E_S3_KEY"), secretAccessKey: getenv("E2E_S3_SECRET"), allowPrivateNetwork: true), $owner, $actor);
		echo "e2e-fixtures-created\n";
		echo app(App\Actions\Backups\DownloadRecoveryKit::class)->handle($actor);' >"$TMP/tinker.log" 2>&1 || { cat "$TMP/tinker.log"; fail "could not create the owner, project and destination"; }
grep -q e2e-fixtures-created "$TMP/tinker.log" || { cat "$TMP/tinker.log"; fail "the fixture script did not finish"; }
# The recovery kit the owner downloads (Settings → Backups) and keeps off the server.
sed -n '/^-----BEGIN LAUNCHROOM RECOVERY KIT-----$/,/^-----END LAUNCHROOM RECOVERY KIT-----$/p' "$TMP/tinker.log" >"$TMP/recovery-kit"
grep -q '^repository-password: ' "$TMP/recovery-kit" || { cat "$TMP/tinker.log"; fail "no recovery kit"; }
rm -f "$TMP/tinker.log"
project="$(psql "SELECT public_id FROM projects WHERE name = 'restore-e2e'")"
[ -n "$project" ] || fail "the project was not created"
old_server="$(psql 'SELECT id FROM servers WHERE is_control_plane')"
old_server_public="$(psql 'SELECT public_id FROM servers WHERE is_control_plane')"
app_key="$(in_lr sed -n 's/^APP_KEY=//p' .env)"
cp_key="$(in_lr cat data/daemon/cp-key)"
ok "owner, project $project, passphrase and destination; control-plane server $old_server_public"

step "dashboard domain: Settings → Dashboard domain moves the dashboard to $NEW_DOMAIN"
# What the owner does in the UI (docs/05 §4.1), through the same actions: the
# checks run in horizon, Caddy routes the new name (internal CA for
# *.localhost), the HTTPS test reaches the dashboard through the host gateway.
host_curl() { host=$1; shift; in_vps docker run --rm --network host --entrypoint curl "$DASHBOARD_IMAGE" -sk --resolve "$host:443:127.0.0.1" "$@"; }
dashboard_tinker() {
	# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
	in_lr docker compose exec -T dashboard php artisan tinker --no-interaction --execute='
		$owner = App\Models\User::query()->where("email", "owner@e2e.test")->firstOrFail();
		$actor = App\Data\Audit\AuditActor::system();
		'"$1"
}
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
dashboard_tinker 'app(App\Actions\Instance\DashboardDomain\StartDashboardDomainChange::class)->handle("'"$NEW_DOMAIN"'", $owner, $actor); echo "started\n";' |
	grep -q started || fail "could not start the dashboard domain change"
retry 300 sh -c "docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM dashboard_domain_changes WHERE hostname = '$NEW_DOMAIN'\" | grep -qx ready" ||
	fail "the new domain did not become ready: $(psql "SELECT status, dns->>'status', probe::text FROM dashboard_domain_changes")"
ok "DNS (local), route, certificate and HTTPS test passed: $(psql "SELECT probe->>'message' FROM dashboard_domain_changes WHERE hostname = '$NEW_DOMAIN'")"
# shellcheck disable=SC2016 # PHP code: its $variables are PHP's
dashboard_tinker 'echo app(App\Actions\Instance\DashboardDomain\SwitchDashboardDomain::class)->handle($owner, $actor), "\n";' |
	grep -q "https://$NEW_DOMAIN" || fail "the switch did not return the new origin"
[ "$(in_lr docker compose exec -T dashboard php artisan launchroom:dashboard-url | tail -n 1)" = "https://$NEW_DOMAIN" ] || fail "the canonical origin did not move"
retry 120 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -fsk --resolve $NEW_DOMAIN:443:127.0.0.1 -o /dev/null https://$NEW_DOMAIN/login" ||
	fail "https://$NEW_DOMAIN/login is not served"
redirect="$(host_curl "$DOMAIN" -o /dev/null -w '%{http_code} %{redirect_url}' "https://$DOMAIN/login")"
[ "$redirect" = "308 https://$NEW_DOMAIN/login" ] || fail "the old origin answered '$redirect' instead of forwarding"
code="$(host_curl "$DOMAIN" -o /dev/null -w '%{http_code}' "https://$DOMAIN/daemon/v1/state")"
[ "$code" = 401 ] || fail "launchroomd's endpoint on the old origin answered $code (want 401 unsigned, not a redirect)"
code="$(host_curl "$DOMAIN" -o /dev/null -w '%{http_code}' "https://$DOMAIN/install/server.sh")"
[ "$code" = 200 ] || fail "the installer on the old origin answered $code during the transition window"
host_curl "$NEW_DOMAIN" -f "https://$NEW_DOMAIN/install/server.sh" | grep -q "LR_CONTROL_PLANE_URL:-https://$NEW_DOMAIN}" || fail "server.sh doesn't name the new origin"
in_lr ./launchroom status | grep -q "Dashboard: https://$NEW_DOMAIN" || fail "./launchroom status shows the old address"
sleep 35
[ "$(psql 'SELECT status FROM servers WHERE is_control_plane')" = online ] || fail "the control-plane server went offline after the switch"
ok "served at https://$NEW_DOMAIN; the old origin forwards browsers (308), keeps launchroomd (401) and the installer; server online"

step "dashboard domain: install.sh --domain $DOMAIN makes APP_URL canonical again"
docker exec -w /root -e LR_DASHBOARD_IMAGE="$DASHBOARD_IMAGE" -e LR_DAEMON_IMAGE="$DAEMON_IMAGE" -e LR_CADDY_IMAGE="$CADDY_REF" "$VPS" \
	sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain "$DOMAIN" --no-public-ip-detect >"$TMP/domain.log" 2>&1 ||
	{ cat "$TMP/domain.log"; fail "install.sh --domain failed"; }
grep -q "making https://$DOMAIN the dashboard address again" "$TMP/domain.log" || { cat "$TMP/domain.log"; fail "install.sh --domain did not reset the dashboard domain"; }
rm -f "$TMP/domain.log"
[ "$(in_lr docker compose exec -T dashboard php artisan launchroom:dashboard-url | tail -n 1)" = "https://$DOMAIN" ] || fail "APP_URL is not canonical again"
retry 120 sh -c "test \"\$(docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -sk --resolve $DOMAIN:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://$DOMAIN/login)\" = 200" ||
	fail "https://$DOMAIN/login is not served again"
redirect="$(host_curl "$NEW_DOMAIN" -o /dev/null -w '%{http_code} %{redirect_url}' "https://$NEW_DOMAIN/login")"
[ "$redirect" = "308 https://$DOMAIN/login" ] || fail "the domain it left answered '$redirect' instead of forwarding"
printf 'reset\n' | docker exec -i -w "$LR" "$VPS" ./launchroom dashboard-domain-reset >"$TMP/reset.log" 2>&1 || { cat "$TMP/reset.log"; fail "./launchroom dashboard-domain-reset failed"; }
grep -q "https://$DOMAIN again" "$TMP/reset.log" || { cat "$TMP/reset.log"; fail "the reset helper did not report the address"; }
rm -f "$TMP/reset.log"
[ "$(psql "SELECT count(*) FROM audit_events WHERE action IN ('instance.dashboard_domain_switched', 'instance.dashboard_domain_reset')")" -ge 3 ] || fail "switch and resets are not audited"
ok "back at https://$DOMAIN (installer and ./launchroom dashboard-domain-reset); $NEW_DOMAIN forwards there"

step "restore scenario: control-plane backup"
# Started right after the destination was created, so it races the queued
# destination test for `restic init` (serialised by the dashboard since this
# test found a corrupted repository: two inits, "ciphertext verification failed").
start=$(date +%s)
tries=0
until backup="$(in_lr docker compose exec -T dashboard php artisan launchroom:backups:control-plane --wait --json 2>&1)"; do
	tries=$((tries + 1))
	[ "$tries" -lt 3 ] || fail "control-plane backup failed: $backup"
	sleep 5
done
printf '%s' "$backup" | grep -q '"status":"succeeded"' || fail "control-plane backup did not succeed: $backup"
ok "backup $(printf '%s' "$backup" | sed -n 's/.*"run":"\([^"]*\)".*/\1/p') succeeded in $(($(date +%s) - start)) s"

step "restore scenario: wipe the control plane (launchroom uninstall)"
uninstall_and_check
# Uninstall also deleted launchroom's images; a new VPS pulls them, ours are local.
docker save "$DASHBOARD_IMAGE" "$DAEMON_IMAGE" "$CADDY_IMAGE" | docker exec -i "$VPS" docker load -q >/dev/null
[ "$(in_vps docker image inspect "$CADDY_IMAGE" --format '{{index .RepoDigests 0}}' | sed 's/.*@//')" = "$caddy_digest" ] ||
	fail "$CADDY_IMAGE has another digest after loading it again"
ok "images loaded again (as on a new VPS)"

step "install.sh --recovery-kit FILE --restore-allow-http (passphrase from the environment)"
docker cp "$TMP/recovery-kit" "$VPS:/root/recovery-kit.txt"
rm -f "$TMP/recovery-kit"
start=$(date +%s)
docker exec -w /root -e LR_DASHBOARD_IMAGE="$DASHBOARD_IMAGE" -e LR_DAEMON_IMAGE="$DAEMON_IMAGE" -e LR_CADDY_IMAGE="$CADDY_REF" \
	-e LR_RECOVERY_PASSPHRASE="$PASSPHRASE" "$VPS" \
	sh /opt/bundle/install/install.sh --bundle /opt/bundle --domain "$DOMAIN" --no-public-ip-detect \
	--recovery-kit /root/recovery-kit.txt --restore-allow-http >"$TMP/restore.log" 2>&1 ||
	{ cat "$TMP/restore.log"; fail "install.sh --restore failed"; }
cat "$TMP/restore.log"
ok "restored in $(($(date +%s) - start)) s"
grep -q "https://$DOMAIN/recovery" "$TMP/restore.log" || fail "no recovery checklist link printed"
grep -q 'Setup is already complete' "$TMP/restore.log" || fail "the restored instance asks for setup again"
if grep -Fq "$PASSPHRASE" "$TMP/restore.log" || grep -Fq "$S3_SECRET" "$TMP/restore.log" || grep -Eq 'base64:[A-Za-z0-9+/=]{20,}' "$TMP/restore.log"; then
	fail "restore output contains a secret"
fi
rm -f "$TMP/restore.log"
ok "recovery link printed, setup not offered again, no secrets in the output"

step "restored control plane"
in_lr docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' | while read -r service state health; do
	[ "$state" = running ] || fail "$service is $state after the restore"
	case "$service:$health" in
	*:healthy | scheduler: | zot: | launchroomd:) ;;
	*) fail "$service health is '$health' after the restore" ;;
	esac
done
[ "$(in_lr sed -n 's/^APP_KEY=//p' .env)" = "$app_key" ] || fail "APP_KEY was not restored"
[ "$(in_lr cat data/daemon/cp-key)" = "$cp_key" ] || fail "the control-plane signing key was not restored"
[ "$(psql "SELECT public_id FROM projects WHERE name = 'restore-e2e'")" = "$project" ] || fail "project $project is missing"
[ "$(psql "SELECT count(*) FROM users WHERE email = 'owner@e2e.test' AND is_instance_owner")" = 1 ] || fail "the owner is missing"
ok "all services healthy; APP_KEY, signing key, owner and project $project are back"
retry 120 sh -c "docker exec -w $LR $VPS docker compose exec -T postgres psql -U launchroom -d launchroom -tAc \"SELECT status FROM servers WHERE is_control_plane\" | grep -qx online" ||
	fail "the new control-plane server is not online: $(psql 'SELECT public_id, status, is_control_plane FROM servers')"
new_server_public="$(psql 'SELECT public_id FROM servers WHERE is_control_plane')"
[ "$new_server_public" != "$old_server_public" ] || fail "the old server identity was reused"
[ "$(psql "SELECT is_control_plane::text || ' ' || status FROM servers WHERE id = $old_server")" != "" ] || fail "the previous control-plane server is gone"
[ "$(psql "SELECT is_control_plane FROM servers WHERE id = $old_server")" = f ] || fail "the previous server is still marked control plane"
[ "$(psql "SELECT status FROM servers WHERE id = $old_server")" != revoked ] || fail "the previous server was revoked instead of kept for the checklist"
[ "$(psql "SELECT recovery_state->>'previousControlPlaneServerId' FROM instance_settings")" = "$old_server" ] || fail "recovery mode is not on"
ok "launchroomd enrolled as $new_server_public and online; recovery mode replaces $old_server_public"
retry 120 sh -c "docker exec $VPS docker run --rm --network host --entrypoint curl $DASHBOARD_IMAGE -fsk --resolve $DOMAIN:443:127.0.0.1 -o /dev/null https://$DOMAIN/up" ||
	fail "https://$DOMAIN/up is not served after the restore"
code="$(vps_curl -o /dev/null -w '%{http_code}' "https://$DOMAIN/login")"
[ "$code" = 200 ] || fail "/login answered $code after the restore"
ok "dashboard served through the new launchroomd's Caddy"

step "restore scenario: a backup after the restore (restored KEK opens the destination)"
retry 60 sh -c "docker exec -w $LR $VPS docker compose exec -T dashboard php artisan launchroom:backups:control-plane --wait --json | grep -q '\"status\":\"succeeded\"'" ||
	fail "a control-plane backup after the restore failed"
ok "control-plane backup after the restore succeeded"

step "launchroom uninstall"
uninstall_and_check
docker rm -f -v "$S3" >/dev/null 2>&1 || true
docker network rm "$S3_NET" >/dev/null 2>&1 || true

printf '\nPASS: fresh install, routing, enrollment, re-run, dashboard domain, backup, wipe, install --restore and uninstall\n'
