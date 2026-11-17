# Helpers of dev/apps-e2e.sh (sourced; COMPOSE, TIMEOUT, PORT, WORK and
# RESULTS are set by the caller). Cases (dev/apps-e2e/cases/<app>.sh) set
# variables and define check functions:
#
#   GAPS="G49 G48"         gap ids of the case (deploy and preview checks)
#   DATABASE=1             also create a Postgres connected to the app
#   PREVIEW_BRANCH=probe   open pr-1 from this fixture branch (default main; "" for
#                          none), then check_preview
#   PROBE=1                deploy a second project from the `probe` branch, then check_probe
#   SKIP="reason"          don't run (listed in the summary)
#   check_main / check_preview / check_probe   assertions (APP, PREVIEW, PROBE_HOST hosts)
#
# Assertions: `expect "<gap ids>" "<what>" <command…>` with the commands
# below; `todo "<gap ids>" "<what>" "<why it can't run yet>"` records a check
# the harness can't make yet.
# shellcheck shell=sh
# shellcheck disable=SC2015,SC2016,SC2034 # PHP in single quotes; hosts are read by the cases

art() { $COMPOSE exec -T dashboard php artisan "$@"; }

# json_field KEY: the first "KEY":"value" string on stdin.
json_field() { sed -n 's/.*"'"$1"'":"\([^"]*\)".*/\1/p' | head -n 1; }

# tinker CODE: runs PHP in the dev dashboard and prints the lines it marks
# with "LR:" (without the marker). Stand-in for dev commands that don't exist
# yet (see dev/fixtures/README.md, "Sample apps end to end").
tinker() { art tinker --execute="$1" 2>/dev/null | sed -n 's/^LR://p'; }

# --- deploy -----------------------------------------------------------------

# deploy_project NAME BRANCH: a project NAME with the fixture $CASE as its app
# (no settings; Postgres when DATABASE=1), deployed. Sets PROJECT_ID,
# INSTANCE_ID, DEPLOYMENT_ID and HOST.
deploy_project() {
	set -- "$1" "$2"
	if [ "$DATABASE" = 1 ]; then db=""; else db="--without-database"; fi
	out="$(art launchroom:dev:deploy-app --name="$1" --fixture="$CASE" --branch="$2" $db --replace --json 2>&1 | tail -n 1)"
	PROJECT_ID="$(printf '%s' "$out" | json_field project)"
	INSTANCE_ID="$(printf '%s' "$out" | json_field appInstance)"
	DEPLOYMENT_ID="$(printf '%s' "$out" | sed -n 's/.*"deployments":\["\([^"]*\)".*/\1/p')"
	HOST="$(printf '%s' "$out" | sed -n 's/.*"hostnames":\["\([^"]*\)".*/\1/p')"
	[ -n "$PROJECT_ID" ] && [ -n "$INSTANCE_ID" ] && [ -n "$DEPLOYMENT_ID" ] && [ -n "$HOST" ] || { DETAIL="$out"; return 1; }
}

# wait_deployment INSTANCE DEPLOYMENT: until it is active (0) or ends otherwise (1).
wait_deployment() {
	deadline=$(($(date +%s) + TIMEOUT))
	while [ "$(date +%s)" -lt "$deadline" ]; do
		st="$(art launchroom:dev:status "$1" 2>/dev/null | tr '{' '\n' | grep "\"id\":\"$2\"")"
		state="$(printf '%s' "$st" | json_field status)"
		case "$state" in
			active) return 0 ;;
			failed | cancelled | superseded)
				DETAIL="deployment $2 $state: $(printf '%s' "$st" | json_field failure)"
				return 1
				;;
		esac
		sleep 5
	done
	DETAIL="deployment $2 still '${state:-unknown}' after ${TIMEOUT}s"
	return 1
}

# build_log INSTANCE DEPLOYMENT: the plan/build log into $WORK/build.log.
build_log() { art launchroom:dev:status "$1" --build-log="$2" >"$WORK/build.log" 2>&1 || true; }

# enable_previews PROJECT: previews on (defaults of the git-provider dev import).
enable_previews() {
	tinker '$p = App\Models\Project::query()->where("public_id", "'"$1"'")->sole();
app(App\Actions\Previews\UpdatePreviewSettings::class)->handle($p, new App\Data\Previews\PreviewSettingsInput(true, null, null, null, 2, 7, App\Enums\Previews\ForkPolicy::Off, false, false), App\Console\InstanceOwner::first(), App\Data\Audit\AuditActor::system());
echo "LR:ok\n";' | grep -qx ok
}

# open_preview PROJECT_NAME PROJECT BRANCH: pr-1 from BRANCH, waits until its
# deployment is active. Sets PREVIEW (host).
open_preview() {
	art launchroom:dev:pr open --project="$2" --number=1 --branch="$3" >"$WORK/pr.out" 2>&1 || { DETAIL="$(cat "$WORK/pr.out")"; return 1; }
	PREVIEW="$(printf '%s' "$HOST" | sed 's/-production\./-pr-1./')"
	deadline=$(($(date +%s) + TIMEOUT))
	while [ "$(date +%s)" -lt "$deadline" ]; do
		st="$(art launchroom:dev:git-provider status --name="$1" 2>/dev/null)"
		pr="$(printf '%s' "$st" | tr '{' '\n' | grep '"trigger":"pr"' | tail -n 1)"
		preview="$(printf '%s' "$st" | tr '{' '\n' | grep '"number":1,' | json_field status)"
		case "$(printf '%s' "$pr" | json_field status)" in
			active) return 0 ;;
			failed | cancelled) DETAIL="preview deployment: $pr"; return 1 ;;
		esac
		case "$preview" in failed | skipped | closed) DETAIL="preview $preview: $(cat "$WORK/pr.out")"; return 1 ;; esac
		sleep 5
	done
	DETAIL="preview not running after ${TIMEOUT}s: $st"
	return 1
}

# deploy_branch BRANCH: another project from the fixture's BRANCH, deployed
# and waited for. Sets BRANCH_HOST (and the ids, as deploy_project).
deploy_branch() {
	deploy_project "apps-$CASE-$1" "$1" && wait_deployment "$INSTANCE_ID" "$DEPLOYMENT_ID" && BRANCH_HOST=$HOST
}

# deploy_fails BRANCH REGEX: the BRANCH deployment fails, and its failure
# matches REGEX (what the dashboard tells the user).
deploy_fails() {
	deploy_project "apps-$CASE-$1" "$1" || return 1
	if wait_deployment "$INSTANCE_ID" "$DEPLOYMENT_ID"; then
		DETAIL="deployment $DEPLOYMENT_ID went live"
		return 1
	fi
	printf '%s' "$DETAIL" | grep -Eiq -- "$2"
}

# status_matches REGEX: launchroom:dev:status of the last deployed instance
# (deployments with their failure, placements, placement issue) matches.
status_matches() {
	DETAIL="$(art launchroom:dev:status "$INSTANCE_ID" 2>/dev/null | head -c 600)"
	printf '%s' "$DETAIL" | grep -Eiq -- "$1"
}

# edge_ips: the addresses of server-1's edge (requests from the edge itself
# are not "the visitor").
edge_ips() { docker exec launchroom-dev-server-1-1 docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' lr-system-caddy 2>/dev/null; }

# json_value_is KEY VALUE HOST PATH: the JSON body has "KEY":"VALUE" (or the
# bare literal for true/false/numbers).
json_value_is() {
	key=$1 want=$2
	shift 2
	fetch "$@"
	grep -Eq "\"$key\": *(\"$want\"|$want)([,}])" "$WORK/body"
}

# not_from_edge KEY HOST PATH: the JSON field KEY (a client address) is set
# and isn't one of the edge's own addresses.
not_from_edge() {
	key=$1
	shift
	fetch "$@"
	ip="$(sed -n 's/.*"'"$key"'": *"\([^"]*\)".*/\1/p' "$WORK/body")"
	DETAIL="$DETAIL (edge: $(edge_ips))"
	[ -n "$ip" ] || return 1
	case " $(edge_ips) " in *" $ip "*) return 1 ;; esac
}

# body_lacks REGEX HOST PATH [curl args…]
body_lacks() { re=$1; shift; fetch "$@"; ! grep -Eq -- "$re" "$WORK/body"; }

# action_id HOST PATH: the first server action id in the page's HTML.
action_id() {
	fetch "$1" "$2"
	sed -n 's/.*\$ACTION_ID_\([0-9a-f]*\).*/\1/p' "$WORK/body" | head -n 1
}

# redeploy PROJECT: deploys production again (same commit). Sets DEPLOYMENT_ID.
redeploy() {
	DEPLOYMENT_ID="$(tinker '$p = App\Models\Project::query()->where("public_id", "'"$1"'")->sole();
$r = app(App\Actions\Deployments\DeployEnvironment::class)->handle($p->production()->sole(), App\Console\InstanceOwner::first(), App\Data\Audit\AuditActor::system(), "apps e2e redeploy");
foreach ($r->deployments as $d) { echo "LR:".$d->public_id."\n"; }' | head -n 1)"
	[ -n "$DEPLOYMENT_ID" ]
}

# --- requests ---------------------------------------------------------------

# fetch HOST PATH [curl args…]: HTTPS request from the host through the dev
# edge. Sets CODE; body in $WORK/body, headers in $WORK/headers.
fetch() {
	h=$1
	p=$2
	shift 2
	CODE="$(curl -sk --max-time "${FETCH_TIMEOUT:-60}" --resolve "$h:$PORT:127.0.0.1" -D "$WORK/headers" -o "$WORK/body" \
		-w '%{http_code}' "$@" "https://$h:$PORT$p")" || true
	DETAIL="HTTP $CODE from $p: $(head -c 300 "$WORK/body" 2>/dev/null | tr '\n' ' ')"
}

code_is() { want=$1; shift; fetch "$@"; [ "$CODE" = "$want" ]; }
code_in() { want=$1; shift; fetch "$@"; case " $want " in *" $CODE "*) return 0 ;; esac; return 1; }
body_matches() { re=$1; shift; fetch "$@"; grep -Eq -- "$re" "$WORK/body"; }
header_matches() { re=$1; shift; fetch "$@"; grep -Eiq -- "$re" "$WORK/headers"; }
log_matches() { DETAIL="build log of $DEPLOYMENT_ID: no match for /$1/"; grep -Eiq -- "$1" "$WORK/build.log"; }
log_lacks() { DETAIL="build log of $DEPLOYMENT_ID: $(grep -Ei -m 3 -- "$1" "$WORK/build.log" | tr '\n' ' ')"; ! grep -Eiq -- "$1" "$WORK/build.log"; }

# --- results ----------------------------------------------------------------

record() { printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$CASE" "$3" >>"$RESULTS"; }

# expect "GAPS" "what" command…
expect() {
	gaps=$1
	what=$2
	shift 2
	DETAIL=""
	if "$@"; then
		pass "[$gaps] $what"
		record PASS "$gaps" "$what"
	else
		fail "[$gaps] $what" "$DETAIL"
		record FAIL "$gaps" "$what"
	fi
}

# todo "GAPS" "what" "why": a check this harness can't make yet.
todo() {
	printf '  TODO  [%s] %s (%s)\n' "$1" "$2" "$3"
	record TODO "$1" "$2 ($3)"
}

run_case() {
	CASE=$1
	GAPS="" DATABASE=0 PREVIEW_BRANCH=main PROBE=0 SKIP="" BRANCH=main
	APP="" PREVIEW="" PROBE_HOST=""
	check_main() { :; }
	check_preview() { :; }
	check_probe() { :; }
	# shellcheck disable=SC1090
	. "dev/apps-e2e/cases/$CASE.sh"

	echo
	echo "== $CASE [$GAPS]"
	if [ -n "$SKIP" ]; then
		printf '  SKIP  %s\n' "$SKIP"
		record SKIP "$GAPS" "$SKIP"
		return 0
	fi

	if ! deploy_project "apps-$CASE" "$BRANCH"; then
		fail "[$GAPS] project created and deployed" "$DETAIL"
		record FAIL "$GAPS" "project created and deployed"
		return 0
	fi
	APP=$HOST
	MAIN_PROJECT=$PROJECT_ID
	expect "$GAPS" "deploys green with no settings ($APP)" wait_deployment "$INSTANCE_ID" "$DEPLOYMENT_ID"
	ok=$?
	build_log "$INSTANCE_ID" "$DEPLOYMENT_ID"
	if grep -q "^FAIL	[^	]*	$CASE	deploys green" "$RESULTS"; then
		tail -n 15 "$WORK/build.log" | sed 's/^/        | /'
	else
		check_main
	fi
	[ "$ok" = 0 ] || true

	if [ -n "$PREVIEW_BRANCH" ] && ! grep -q "^FAIL	[^	]*	$CASE	deploys green" "$RESULTS"; then
		if enable_previews "$MAIN_PROJECT"; then
			expect "$GAPS" "pr-1 preview from '$PREVIEW_BRANCH' deploys green" open_preview "apps-$CASE" "$MAIN_PROJECT" "$PREVIEW_BRANCH"
			if ! grep -q "^FAIL	[^	]*	$CASE	pr-1 preview" "$RESULTS"; then
				check_preview
			fi
		else
			fail "[$GAPS] previews switched on" "tinker failed"
			record FAIL "$GAPS" "previews switched on"
		fi
	fi

	if [ "$PROBE" = 1 ]; then
		if deploy_project "apps-$CASE-probe" probe && wait_deployment "$INSTANCE_ID" "$DEPLOYMENT_ID"; then
			PROBE_HOST=$HOST
			build_log "$INSTANCE_ID" "$DEPLOYMENT_ID"
			check_probe
		else
			fail "[$GAPS] probe branch deploys green" "$DETAIL"
			record FAIL "$GAPS" "probe branch deploys green"
		fi
	fi
}

summary() {
	echo
	echo "== Summary"
	for s in PASS FAIL TODO SKIP; do
		printf '  %s %s\n' "$(grep -c "^$s	" "$RESULTS")" "$s"
	done
	if grep -q '^FAIL	' "$RESULTS"; then
		echo
		echo "Failures by gap (route to the gap's owner, plan §1/§4):"
		for gap in $(grep '^FAIL	' "$RESULTS" | cut -f2 | tr ' ' '\n' | sort -u); do
			echo "  $gap"
			grep '^FAIL	' "$RESULTS" | awk -F '\t' -v g="$gap" '{ n = split($2, a, " "); for (i = 1; i <= n; i++) if (a[i] == g) { printf "    %s: %s\n", $3, $4; break } }'
		done
	fi
	[ "$(grep -c '^FAIL	' "$RESULTS")" -eq 0 ]
}
