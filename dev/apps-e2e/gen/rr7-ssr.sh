# React Router 7 framework mode (create-react-router's default template, SSR).
# The template comes from remix-run/react-router-templates on GitHub.
# stage: LR_FIXTURE_NODE_IMAGE main
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

main() {
	cd /work/tmp
	npx -y "create-react-router@$LR_FIXTURE_CREATE_REACT_ROUTER" "$FIXTURE" --yes --no-git-init --install --package-manager npm
	adopt "$FIXTURE"
}
