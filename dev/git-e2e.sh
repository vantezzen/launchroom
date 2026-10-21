#!/bin/sh
# End-to-end test of the git providers against the dev Forgejo
# (`make test-git-e2e`, docs/19-git-providers.md §8). Needs `make up` with an
# enrolled server-1 whose launchroomd announces `gitProviders` (`make
# dev-daemon`), and starts the Forgejo of profile "forgejo" itself.
#
#   1. Forgejo: an admin user, an access token and a repository seeded from
#      dev/fixtures/node-hello (pushed with git inside the Forgejo container).
#   2. Dashboard: connect Forgejo with the token (owner, private network),
#      import the repository with previews on, deploy it.
#   3. A commit through the Forgejo API → webhook → a push deployment of it.
#   4. A pull request → webhook → a preview of its head commit.
#
# Everything talks over the dev compose network (http://forgejo:3000,
# http://dashboard:8000); the host only reaches Forgejo on 127.0.0.1.
# Environment: LR_E2E_TIMEOUT (seconds per wait, default 600), KEEP=1 keeps
# the project (otherwise it stays too: delete it in the UI; the repository
# name is unique per run).

# pass/fail always succeed, so `A && pass || fail` is a plain if/else here.
# shellcheck disable=SC2015

set -u

cd "$(dirname "$0")/.." || exit 1

COMPOSE="docker compose --project-directory dev --env-file dev/.env -f dev/compose.yml"
TIMEOUT="${LR_E2E_TIMEOUT:-600}"
FORGEJO="http://127.0.0.1:${LR_DEV_FORGEJO_PORT:-3300}"
ADMIN=lr-admin
PASSWORD=launchroom-dev-only
RUN="$(date +%s)"
REPO="hello-$RUN"
PROJECT="forgejo-$RUN"

passed=0
failed=0
pass() { passed=$((passed + 1)); printf '  PASS  %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ -z "${2:-}" ] || printf '%s\n' "$2" | sed 's/^/        /'; }
artisan() { $COMPOSE exec -T dashboard php artisan "$@"; }
api() { # api METHOD PATH [JSON]
	curl -fsS -X "$1" -H "Authorization: token $TOKEN" -H 'Content-Type: application/json' ${3:+--data "$3"} "$FORGEJO/api/v1$2"
}
# wait_for DESCRIPTION JQ-ISH-GREP: polls `status` until its JSON matches the extended regex.
wait_for() {
	deadline=$(($(date +%s) + TIMEOUT))
	while [ "$(date +%s)" -lt "$deadline" ]; do
		status="$(artisan launchroom:dev:git-provider status --name="$PROJECT" 2>/dev/null)"
		if printf '%s' "$status" | grep -Eq "$2"; then
			pass "$1"
			return 0
		fi
		if printf '%s' "$status" | grep -q '"status":"failed"'; then
			break
		fi
		sleep 5
	done
	fail "$1" "$status"
	return 1
}

if [ -z "$($COMPOSE ps -q --status running dashboard 2>/dev/null)" ]; then
	echo "The dev stack is not running: make up" >&2
	exit 2
fi

echo "== Forgejo"
$COMPOSE --profile forgejo up -d --wait forgejo >/dev/null 2>&1 || { echo "Forgejo did not start" >&2; exit 2; }
$COMPOSE exec -T forgejo forgejo admin user create --username "$ADMIN" --password "$PASSWORD" --email lr-admin@example.com --admin --must-change-password=false >/dev/null 2>&1 || true
TOKEN="$($COMPOSE exec -T forgejo forgejo admin user generate-access-token --username "$ADMIN" --token-name "e2e-$RUN" --scopes all --raw | tr -d '\r\n')"
[ -n "$TOKEN" ] && pass "admin token" || { fail "admin token"; exit 1; }

# Seed a private repository from the fixture with git inside the Forgejo container.
api POST /user/repos '{"name":"'"$REPO"'","private":true,"default_branch":"main"}' >/dev/null || { fail "create $REPO"; exit 1; }
# (docker cp creates root-owned files: copy them once more as the git user.)
$COMPOSE cp dev/fixtures/node-hello forgejo:/tmp/seed-"$RUN" >/dev/null 2>&1
$COMPOSE exec -T -e GIT_AUTHOR_NAME=Ada -e GIT_AUTHOR_EMAIL=ada@example.com -e GIT_COMMITTER_NAME=Ada -e GIT_COMMITTER_EMAIL=ada@example.com forgejo \
	sh -c 'cp -r /tmp/seed-'"$RUN"' /tmp/work-'"$RUN"' && cd /tmp/work-'"$RUN"' && git init -q -b main && git add -A && git commit -qm "Initial commit" && git push -q "http://'"$ADMIN"':'"$TOKEN"'@127.0.0.1:3000/'"$ADMIN"'/'"$REPO"'.git" main' \
	&& pass "seeded $ADMIN/$REPO" || { fail "seeded $ADMIN/$REPO"; exit 1; }

echo "== Connect and import"
CONNECTION="$(artisan launchroom:dev:git-provider connect --token="$TOKEN" | tail -n1 | tr -d '\r')"
case "$CONNECTION" in gic_*) pass "connected $CONNECTION" ;; *) fail "connect" "$CONNECTION"; exit 1 ;; esac
IMPORTED="$(artisan launchroom:dev:git-provider import --repository="$ADMIN/$REPO" --name="$PROJECT" 2>&1 | tail -n1 | tr -d '\r')"
case "$IMPORTED" in prj_*) pass "imported as $IMPORTED" ;; *) fail "import" "$IMPORTED"; exit 1 ;; esac

# Registered by a queued job once the service exists.
for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do
	HOOKS="$(api GET "/repos/$ADMIN/$REPO/hooks")"
	printf '%s' "$HOOKS" | grep -q "/webhooks/gitea/" && break
	sleep 5
done
printf '%s' "$HOOKS" | grep -q "/webhooks/gitea/$CONNECTION" && pass "webhook registered on Forgejo" || fail "webhook registered on Forgejo" "$HOOKS"
wait_for "first deployment is live" '"status":"active"' || exit 1

echo "== Push → deploy"
CONTENT="$(printf 'deployed by the e2e run %s\n' "$RUN" | base64 | tr -d '\n')"
COMMIT="$(api POST "/repos/$ADMIN/$REPO/contents/E2E.md" '{"content":"'"$CONTENT"'","message":"e2e: push","branch":"main"}' | sed -n 's/.*"commit":{[^}]*"sha":"\([0-9a-f]\{40\}\)".*/\1/p')"
[ -n "$COMMIT" ] && pass "pushed $COMMIT" || fail "push through the API"
wait_for "push deployed $COMMIT" '"sha":"'"$COMMIT"'","status":"active","trigger":"push"'

echo "== Pull request → preview"
api POST "/repos/$ADMIN/$REPO/branches" '{"new_branch_name":"feature","old_branch_name":"main"}' >/dev/null
CONTENT="$(printf 'preview %s\n' "$RUN" | base64 | tr -d '\n')"
PR_HEAD="$(api POST "/repos/$ADMIN/$REPO/contents/PREVIEW.md" '{"content":"'"$CONTENT"'","message":"e2e: preview","branch":"feature"}' | sed -n 's/.*"commit":{[^}]*"sha":"\([0-9a-f]\{40\}\)".*/\1/p')"
api POST "/repos/$ADMIN/$REPO/pulls" '{"head":"feature","base":"main","title":"E2E preview"}' >/dev/null && pass "opened a pull request" || fail "open a pull request"
wait_for "preview of the pull request is running" '"previews":\[\{"number":1,"status":"running","sha":"'"$PR_HEAD"'","provider":"gitea"'
STATUSES="$(api GET "/repos/$ADMIN/$REPO/commits/$PR_HEAD/statuses")"
printf '%s' "$STATUSES" | grep -q 'launchroom' && pass "commit status reported on Forgejo" || fail "commit status reported on Forgejo" "$STATUSES"
COMMENTS="$(api GET "/repos/$ADMIN/$REPO/issues/1/comments")"
printf '%s' "$COMMENTS" | grep -q 'launchroom' && pass "preview comment on the pull request" || fail "preview comment on the pull request" "$COMMENTS"

echo
echo "$passed passed, $failed failed (project $PROJECT)"
[ "$failed" -eq 0 ]
