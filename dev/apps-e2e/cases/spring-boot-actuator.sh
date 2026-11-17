# Spring Initializr: Web + Actuator, Maven, Java 21.
# shellcheck shell=sh disable=SC2034
GAPS="G77"
PREVIEW_BRANCH=""

check_main() {
	expect G77 "/actuator/health answers UP" body_matches '"status":"UP"' "$APP" /actuator/health
	expect G77 "actuator links use https (forward headers)" body_matches "\"href\":\"https://$APP" "$APP" /actuator
	todo G77 "no heap OOM at 512 MB (MaxRAMPercentage)" "needs a memory limit setting"
}
