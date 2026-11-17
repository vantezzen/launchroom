# Express (express-generator --no-view), dependencies installed with npm.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "express-generator@$LR_FIXTURE_EXPRESS_GENERATOR" --no-view --git "$FIXTURE"
	cd "$FIXTURE"
	npm install
	cd ..
	adopt "$FIXTURE"
}
