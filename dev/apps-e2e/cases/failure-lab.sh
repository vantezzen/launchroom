# Go app that fails on purpose; each mode is a branch (mode.txt). What the
# dashboard says about a failure is read through launchroom:dev:status.
# shellcheck shell=sh disable=SC2034
GAPS="G1 G5 G22 G24"
PREVIEW_BRANCH=""

crash_reported() { # the crash-late deployment's crash shows up with its exit code
	deploy_branch crash-late || return 1
	sleep 45
	status_matches '"exitCode":3|exit code 3|exited with 3'
}

check_main() {
	expect REG "/ answers 200" code_is 200 "$APP" /
	expect G5 "exit127: 'command not found' is named as such" deploy_fails exit127 'command_not_found|command not found|127'
	expect "G1 G5" "crash-late: the crash and its exit code are reported" crash_reported
	todo "G1 G5" "the crash trace shown is the old run's (60 lines)" "needs the service panel's start log tail"
	todo "G1 G5" "oom: exit 137 with oomKilled" "needs a memory limit setting on the service"
	todo G24 "hang: restarted after the liveness check fails (OD2)" "not built yet"
	todo G22 "v2-crash: automatic rollback to v1 (OD1)" "needs a second deploy of the branch's step 2"
}
