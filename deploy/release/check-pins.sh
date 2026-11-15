#!/bin/sh
# Fails when a copy of a pin differs from its owner (docs/09 R31): runtime
# images in deploy/control-plane/images.env, build/test/dev tools in
# deploy/pins.env, the release identity in deploy/install/lib/release.sh.
# Everything that can read the owners does (Makefile, dev/bin/compose.sh,
# deploy/test/lib.sh, the installers); the copies below cannot (Dockerfile ARG
# defaults, Go constants and tests, PHP config, templates, CI workflows) and
# must carry the owner's exact value. Run from anywhere (make lint-deploy).
set -eu

cd "$(dirname "$0")/../.."

status=0
pin() { sed -n "s/^$1=//p" deploy/control-plane/images.env deploy/pins.env | tail -n 1; }

# expect KEY FILE...: FILE contains KEY's digest (or, for a value without
# one, the value itself).
expect() {
	key=$1
	shift
	value="$(pin "$key")"
	[ -n "$value" ] || { printf 'check-pins: %s is not pinned in images.env or pins.env\n' "$key" >&2; status=1; return; }
	case "$value" in *@sha256:*) needle="${value#*@}" ;; *) needle="$value" ;; esac
	for file in "$@"; do
		grep -qF "$needle" "$file" ||
			{ printf 'check-pins: %s does not have %s=%s\n' "$file" "$key" "$needle" >&2; status=1; }
	done
}

expect LR_POSTGRES_IMAGE dashboard/config/launchroom.php daemon/test/integration/backup_test.go daemon/test/integration/cpupdate_test.go
expect LR_VALKEY_IMAGE dashboard/config/launchroom.php daemon/test/integration/edge_test.go
expect LR_HEADSCALE_IMAGE daemon/test/integration/mesh_test.go
expect LR_ZOT_IMAGE daemon/test/integration/build_test.go
expect LR_COMPOSE_IMAGE daemon/test/integration/selfupdate_test.go
expect LR_GO_IMAGE daemon/Dockerfile
expect LR_VERSITYGW_IMAGE dashboard/resources/templates/versitygw.yaml daemon/test/integration/backup_test.go
expect LR_REGISTRY_IMAGE daemon/test/integration/selfupdate_test.go
expect LR_STOCK_CADDY_IMAGE deploy/images/caddy/Dockerfile dashboard/config/launchroom.php dev/compose.yml \
	daemon/internal/policy/policy.go daemon/test/integration/reconcile_test.go
expect LR_BUSYBOX_IMAGE dashboard/config/launchroom.php daemon/internal/policy/policy.go daemon/test/integration/firewall_test.go
for arch in AMD64 ARM64; do
	expect "LR_RESTIC_SHA256_$arch" daemon/Dockerfile deploy/images/dashboard/Dockerfile
done
for file in daemon/Dockerfile deploy/images/dashboard/Dockerfile; do
	grep -qx "ARG RESTIC_VERSION=$(pin LR_RESTIC_VERSION)" "$file" ||
		{ printf 'check-pins: %s does not have ARG RESTIC_VERSION=%s\n' "$file" "$(pin LR_RESTIC_VERSION)" >&2; status=1; }
done

# CI can't read pins.env: the golangci-lint action's version and the
# govulncheck module version are copies.
for key_tool in LR_GOLANGCI_LINT_VERSION:golangci LR_GOVULNCHECK_VERSION:govulncheck; do
	key="${key_tool%%:*}"
	value="$(pin "$key")"
	for file in .github/workflows/*.yml; do
		case "${key_tool#*:}" in
			golangci) found="$(awk '/golangci-lint-action@/ { f = 1 } f && $1 == "version:" { print $2; f = 0 }' "$file")" ;;
			*) found="$(sed -n 's/.*govulncheck@\([^ ]*\).*/\1/p' "$file")" ;;
		esac
		if [ -n "$found" ] && printf '%s\n' "$found" | grep -vqxF "$value"; then
			printf 'check-pins: %s does not have %s=%s everywhere\n' "$file" "$key" "$value" >&2
			status=1
		fi
	done
done

# The release identity: the installers' pin (lib/release.sh) is launchroomd's
# default trust (docs/09 R30).
identity="$(sed -n 's/^SIGNER_IDENTITY="\(.*\)"$/\1/p' deploy/install/lib/release.sh)"
if [ -z "$identity" ] || ! grep -qF "\"$identity\"" daemon/internal/update/trust.go; then
	printf 'check-pins: daemon/internal/update/trust.go DefaultIdentity is not %s\n' "$identity" >&2
	status=1
fi

# No unpinned copy of a pinned tool image left in the dev stack.
if grep -nE '^[[:space:]]*image: (postgres|valkey/valkey|golang|docker):' dev/compose.yml dev/compose.multi.yml >&2; then
	printf 'check-pins: dev compose files pull the images above by tag; use the pins (deploy/pins.env, images.env)\n' >&2
	status=1
fi

[ "$status" -ne 0 ] || echo "All pin copies match deploy/control-plane/images.env and deploy/pins.env."
exit "$status"
