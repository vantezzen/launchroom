#!/bin/sh
# The launchroom GitHub Action's main step (action.yml, docs/16 §6): runs
# `launchroom deploy` or `launchroom preview wait` with --json and turns the
# result into the step's outputs and the job summary. Inputs come as
# INPUT_* environment variables; LAUNCHROOM_URL and LAUNCHROOM_TOKEN are
# read by the CLI itself. Exits with the CLI's exit code.

set -eu

say() { printf '%s\n' "$*" >&2; }
die() { say "::error::$*"; exit 2; }

command -v launchroom >/dev/null 2>&1 || die "the launchroom CLI is not installed"
command -v jq >/dev/null 2>&1 || die "jq is needed to read launchroom's output"
[ -n "${LAUNCHROOM_URL:-}" ] || die "the url input is empty"
[ -n "${LAUNCHROOM_TOKEN:-}" ] || die "the token input is empty (store the token as a secret)"
[ -n "${INPUT_PROJECT:-}" ] || die "the project input is empty"

event_field() {
	if [ -n "${GITHUB_EVENT_PATH:-}" ] && [ -f "$GITHUB_EVENT_PATH" ]; then
		jq -r "$1 // empty" "$GITHUB_EVENT_PATH" 2>/dev/null || true
	fi
}

# output NAME VALUE: one line, never a multi-line value.
output() {
	value="$(printf '%s' "$2" | tr -d '\r\n')"
	if [ -n "${GITHUB_OUTPUT:-}" ]; then
		printf '%s=%s\n' "$1" "$value" >>"$GITHUB_OUTPUT"
	fi
}

summary() {
	if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
		printf '%s\n' "$*" >>"$GITHUB_STEP_SUMMARY"
	fi
}

set -- --project "$INPUT_PROJECT"
[ -z "${INPUT_ENVIRONMENT:-}" ] || set -- "$@" --environment "$INPUT_ENVIRONMENT"
[ -z "${INPUT_SERVICE:-}" ] || set -- "$@" --service "$INPUT_SERVICE"

result="$(mktemp)"
trap 'rm -f "$result"' EXIT

case "${INPUT_COMMAND:-deploy}" in
deploy)
	[ -n "${INPUT_SERVICE:-}" ] || die "deploy needs the service input"
	sha="${INPUT_SHA:-}"
	if [ -z "$sha" ] && [ -z "${INPUT_REF:-}" ] && [ "${GITHUB_EVENT_NAME:-}" = push ]; then
		sha="${GITHUB_SHA:-}"
	fi
	[ -z "$sha" ] || set -- "$@" --sha "$sha"
	[ -z "${INPUT_REF:-}" ] || set -- "$@" --ref "$INPUT_REF"

	code=0
	launchroom deploy --json "$@" >"$result" || code=$?

	deployment="$(jq -r '.[0].id // empty' "$result" 2>/dev/null || true)"
	dashboard="$(jq -r '.[0].dashboardUrl // empty' "$result" 2>/dev/null || true)"
	status="$(jq -r '.[0].status // empty' "$result" 2>/dev/null || true)"
	url=""
	if [ "$code" = 0 ]; then
		url="$(launchroom status --json "$@" 2>/dev/null | jq -r '.services[0].urls[0] // empty' 2>/dev/null || true)"
	fi
	output deployment "$deployment"
	output dashboard-url "$dashboard"
	output url "$url"

	summary "### launchroom deploy"
	summary ""
	summary "| | |"
	summary "|---|---|"
	summary "| Service | \`$INPUT_SERVICE\` |"
	summary "| Deployment | ${deployment:-none} |"
	summary "| Status | ${status:-unknown} |"
	[ -z "$url" ] || summary "| Address | $url |"
	[ -z "$dashboard" ] || summary "| Details | $dashboard |"
	;;
preview-wait)
	pr="${INPUT_PR:-}"
	[ -n "$pr" ] || pr="$(event_field '.pull_request.number')"
	[ -n "$pr" ] || die "preview-wait needs the pr input (or a pull_request event)"
	sha="${INPUT_SHA:-}"
	[ -n "$sha" ] || sha="$(event_field '.pull_request.head.sha')"
	set -- "$@" --pr "$pr"
	[ -z "$sha" ] || set -- "$@" --sha "$sha"
	[ -z "${INPUT_TIMEOUT:-}" ] || set -- "$@" --timeout "$INPUT_TIMEOUT"

	code=0
	launchroom preview wait --json "$@" >"$result" || code=$?

	url="$(jq -r '.urls[0].url // empty' "$result" 2>/dev/null || true)"
	deployment="$(jq -r '(.deployments | map(select(.status == "failed")) | .[0].id) // .deployments[0].id // empty' "$result" 2>/dev/null || true)"
	dashboard="$(jq -r '.dashboardUrl // empty' "$result" 2>/dev/null || true)"
	health="$(jq -r '.health // empty' "$result" 2>/dev/null || true)"
	output url "$url"
	output deployment "$deployment"
	output dashboard-url "$dashboard"

	summary "### launchroom preview for #$pr"
	summary ""
	summary "| | |"
	summary "|---|---|"
	summary "| Preview | ${health:-unknown} |"
	jq -r '.urls[]? | "| \(.service) | \(.url) |"' "$result" 2>/dev/null >>"${GITHUB_STEP_SUMMARY:-/dev/null}" || true
	[ -z "$dashboard" ] || summary "| Details | $dashboard |"
	;;
*)
	die "command must be deploy or preview-wait"
	;;
esac

# The CLI's JSON stays visible in the log.
cat "$result"
exit "$code"
