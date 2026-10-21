#!/bin/sh
# End-to-end test of the launchroom CLI against the running dev stack
# (`make test-cli-e2e`, docs/16-api-and-cli.md §5.2). Needs `make up` and a
# project with at least one deployed service in the dev dashboard.
#
# The CLI is built for Linux into cli/bin/ and runs in the Go tools container
# on the dev compose network (http://dashboard:8000). Its config, token
# (file store) and working directory live in cli/bin/e2e/, removed afterwards.
# Sign-in goes through the real device flow; the local-only artisan command
# launchroom:dev:cli-approve approves it as LR_E2E_EMAIL (default: the first
# instance owner).
#
# Environment: LR_E2E_EMAIL, LR_E2E_PROJECT (prj_…; default: the first
# project), LR_E2E_ENVIRONMENT (slug, default production), LR_E2E_SERVICE
# (svc_…; default: the first live service), KEEP=1 keeps cli/bin/e2e.

# pass/fail always succeed, so `A && pass || fail` is a plain if/else here.
# shellcheck disable=SC2015

set -u

cd "$(dirname "$0")/.." || exit 1

COMPOSE="docker compose --project-directory dev --env-file dev/.env -f dev/compose.yml"
URL="http://dashboard:8000"
E2E=/src/cli/bin/e2e
HOST_E2E=cli/bin/e2e
ENVIRONMENT="${LR_E2E_ENVIRONMENT:-production}"
ABILITIES="read,deploy,env:read,env:write,secrets:read"

passed=0
failed=0
pass() { passed=$((passed + 1)); printf '  PASS  %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ -z "${2:-}" ] || printf '%s\n' "$2" | sed 's/^/        /'; }

cleanup() {
	[ "${KEEP:-}" = 1 ] || rm -rf "$HOST_E2E"
}
trap cleanup EXIT

# lr ARGS...: runs the CLI in the Go container; stdout/stderr pass through.
lr() {
	$COMPOSE run --rm --no-deps -T -w "$E2E/work" \
		-e XDG_CONFIG_HOME="$E2E/config" -e HOME="$E2E/home" \
		-e LAUNCHROOM_CREDENTIALS_STORE=file -e NO_COLOR=1 \
		${LR_TOKEN_OVERRIDE:+-e LAUNCHROOM_TOKEN="$LR_TOKEN_OVERRIDE"} \
		go /src/cli/bin/launchroom-e2e "$@" 2>/dev/null
}
# lr_err ARGS...: like lr, but keeps stderr (callers redirect it).
lr_err() {
	$COMPOSE run --rm --no-deps -T -w "$E2E/work" \
		-e XDG_CONFIG_HOME="$E2E/config" -e HOME="$E2E/home" \
		-e LAUNCHROOM_CREDENTIALS_STORE=file -e NO_COLOR=1 \
		go /src/cli/bin/launchroom-e2e "$@"
}

if [ -z "$($COMPOSE ps -q --status running dashboard 2>/dev/null)" ]; then
	echo "The dev stack is not running: make up" >&2
	exit 2
fi

echo "Building the CLI (linux)…"
$COMPOSE run --rm --no-deps -T -w /src/cli -e CGO_ENABLED=0 -e GOOS=linux go \
	go build -trimpath -ldflags "-X main.version=0.0.0-e2e" -o bin/launchroom-e2e ./cmd/launchroom >/dev/null 2>&1 \
	|| { echo "build failed" >&2; exit 1; }
rm -rf "$HOST_E2E"
mkdir -p "$HOST_E2E/work" "$HOST_E2E/config" "$HOST_E2E/home"

echo "Running against $URL"

# 1. Sign in through the device flow.
lr_err login --url "$URL" --no-browser --json >"$HOST_E2E/login.out" 2>&1 &
login_pid=$!
code=""
i=0
while [ $i -lt 60 ] && [ -z "$code" ]; do
	code="$(sed -n 's/.*"userCode":"\([A-Z0-9-]*\)".*/\1/p' "$HOST_E2E/login.out" 2>/dev/null | head -n 1)"
	[ -n "$code" ] || sleep 1
	i=$((i + 1))
done
if [ -z "$code" ]; then
	fail "login prints a machine-readable user code" "$(cat "$HOST_E2E/login.out" 2>/dev/null)"
	kill "$login_pid" 2>/dev/null
	exit 1
fi
pass "login prints the user code ($code)"

if $COMPOSE exec -T dashboard php artisan launchroom:dev:cli-approve "$code" ${LR_E2E_EMAIL:+--email="$LR_E2E_EMAIL"} --abilities="$ABILITIES" >"$HOST_E2E/approve.out" 2>&1; then
	pass "dashboard approves the sign-in"
else
	fail "dashboard approves the sign-in" "$(cat "$HOST_E2E/approve.out")"
	kill "$login_pid" 2>/dev/null
	exit 1
fi
if wait "$login_pid" && grep -q '"store": "file"' "$HOST_E2E/login.out"; then
	pass "login stores the token"
else
	fail "login stores the token" "$(cat "$HOST_E2E/login.out")"
	exit 1
fi
perm="$(stat -c %a "$HOST_E2E/config/launchroom/credentials.json" 2>/dev/null || stat -f %Lp "$HOST_E2E/config/launchroom/credentials.json")"
[ "$perm" = 600 ] && pass "credentials file is 0600" || fail "credentials file is 0600" "mode $perm"
if grep -q 'lr_pat_' "$HOST_E2E/login.out"; then
	fail "the token is never printed"
else
	pass "the token is never printed"
fi

# 2. whoami
out="$(lr whoami --json)" && echo "$out" | grep -q '"abilities"' && pass "whoami" || fail "whoami" "$out"

# 3. Pick a project and link to it.
project="${LR_E2E_PROJECT:-}"
if [ -z "$project" ]; then
	# The token goes to curl in a 0600 header file, never in argv.
	(umask 077 && sed -n 's/.*"http[^"]*": "\([^"]*\)".*/Authorization: Bearer \1/p' "$HOST_E2E/config/launchroom/credentials.json" | head -n 1 >"$HOST_E2E/auth.header")
	project="$($COMPOSE run --rm --no-deps -T go curl -fsS -H "@$E2E/auth.header" -H 'Accept: application/json' "$URL/api/v1/projects" 2>/dev/null \
		| grep -o 'prj_[0-9A-HJKMNP-TV-Z]\{26\}' | head -n 1)"
fi
if [ -z "$project" ]; then
	fail "find a project (create one in the dev dashboard, or set LR_E2E_PROJECT)"
	exit 1
fi
out="$(lr link --project "$project" --environment "$ENVIRONMENT" --json)" && echo "$out" | grep -q "$project" \
	&& pass "link $project / $ENVIRONMENT" || { fail "link" "$out"; exit 1; }

# 4. status, and choose a live service.
out="$(lr status --json)" && echo "$out" | grep -q '"services"' && pass "status" || fail "status" "$out"
service="${LR_E2E_SERVICE:-$(echo "$out" | awk '/"id": "svc_/ { gsub(/[",]/, "", $2); id = $2 } /"status": "live"/ && id != "" { print id; exit }')}"
if [ -z "$service" ]; then
	fail "find a live service in $ENVIRONMENT (deploy one, or set LR_E2E_SERVICE)"
	exit 1
fi
out="$(lr link --project "$project" --environment "$ENVIRONMENT" --service "$service" --json)" && echo "$out" | grep -q "$service" \
	&& pass "link to service $service" || { fail "link to service" "$out"; exit 1; }

# 5. Variables.
out="$(lr env set E2E_VAR=hello --json)" && echo "$out" | grep -q 'E2E_VAR' && pass "env set" || fail "env set" "$out"
out="$(lr env ls --json)" && echo "$out" | grep -q '"E2E_VAR"' && pass "env ls shows the staged variable" || fail "env ls" "$out"
if echo "$out" | grep -q '"type": "secret"' && echo "$out" | awk '/"type": "secret"/ { s = 1 } s && /"value": "/ { bad = 1 } /^  }/ { s = 0 } END { exit bad ? 0 : 1 }'; then
	fail "env ls never lists secret values"
else
	pass "env ls never lists secret values"
fi
out="$(lr env pull e2e.env --yes --json)" && [ -f "$HOST_E2E/work/e2e.env" ] && grep -q '^[A-Z_][A-Z0-9_]*=' "$HOST_E2E/work/e2e.env" \
	&& pass "env pull writes the file" || fail "env pull" "$out"
perm="$(stat -c %a "$HOST_E2E/work/e2e.env" 2>/dev/null || stat -f %Lp "$HOST_E2E/work/e2e.env")"
[ "$perm" = 600 ] && pass "pulled file is 0600" || fail "pulled file is 0600" "mode $perm"
lr env pull e2e.env >/dev/null
[ $? -eq 2 ] && pass "env pull refuses to overwrite without --yes (no TTY)" || fail "env pull refuses to overwrite without --yes"

# 6. run: env injected, exit code passed through.
key="$(sed -n 's/^\([A-Z_][A-Z0-9_]*\)=.*/\1/p' "$HOST_E2E/work/e2e.env" | head -n 1)"
out="$(lr run -- printenv "$key")" && [ -n "$out" ] && pass "run injects $key" || fail "run injects $key" "$out"
lr run -- sh -c 'exit 3' >/dev/null
[ $? -eq 3 ] && pass "run passes the exit code through" || fail "run passes the exit code through"

out="$(lr env rm E2E_VAR --json)" && echo "$out" | grep -q 'E2E_VAR' && pass "env rm" || fail "env rm" "$out"

# 7. logs and deploy.
lr logs --since 1h --json >/dev/null && pass "logs" || fail "logs" "$(lr_err logs --since 1h 2>&1)"
out="$(lr deploy --redeploy --detach --json)" && echo "$out" | grep -q '"id": "dpl_' && pass "deploy --redeploy --detach" || fail "deploy --redeploy" "$out"

# 8. A wrong token is exit 3 with a JSON error.
LR_TOKEN_OVERRIDE="1|lr_pat_not-a-token"
lr whoami --json >/dev/null
[ $? -eq 3 ] && pass "a bad token exits 3" || fail "a bad token exits 3"
unset LR_TOKEN_OVERRIDE

echo ""
echo "$passed passed, $failed failed"
[ "$failed" -eq 0 ]
