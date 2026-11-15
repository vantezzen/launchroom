#!/bin/sh
# Unit tests for install/cli.sh's release verification (docs/16 §5.3,
# docs/09 R30): the CLI installs only when the checksum file's signature
# verifies, and without a signature or a way to check it only with
# --allow-unsigned. Runs in a throwaway busybox container (make
# test-cli-sh): curl, uname, cosign and docker are stubs on PATH, the
# "release" is a folder of files. Nothing is installed outside /tmp.
set -eu

SCRIPT="${1:-/mnt/install/cli.sh}"
WORK="$(mktemp -d)"
STUBS="$WORK/bin"
REL="$WORK/release"
VERSION=1.2.3
mkdir -p "$STUBS" "$REL"
FAILED=0

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	FAILED=1
}

# A release: the archive with a `launchroom` that prints its version, the
# checksum file and its signature.
mkdir -p "$WORK/pkg"
printf '#!/bin/sh\necho "launchroom %s"\n' "$VERSION" >"$WORK/pkg/launchroom"
chmod 0755 "$WORK/pkg/launchroom"
archive="launchroom_${VERSION}_linux_amd64.tar.gz"
sums="launchroom_${VERSION}_SHA256SUMS"
tar -czf "$REL/$archive" -C "$WORK/pkg" launchroom
(cd "$REL" && sha256sum "$archive" >"$sums")
printf '{"bundle":"stub"}\n' >"$REL/$sums.sigstore.json"

# curl: serves https://releases.test/download/v<version>/<file> from $REL.
cat >"$STUBS/curl" <<'EOF'
#!/bin/sh
out=""
while [ $# -gt 0 ]; do
	case "$1" in -o) out="$2"; shift 2 ;; *) url="$1"; shift ;; esac
done
file="$STUB_REL/${url##*/}"
[ -f "$file" ] || exit 22
cp "$file" "$out"
EOF
cat >"$STUBS/uname" <<'EOF'
#!/bin/sh
case "$1" in -s) echo Linux ;; -m) echo x86_64 ;; esac
EOF
# cosign: STUB_COSIGN=ok verifies, anything else refuses; records its argv.
cat >"$STUBS/cosign" <<'EOF'
#!/bin/sh
printf 'cosign %s\n' "$*" >>"$STUB_LOG"
[ "${STUB_COSIGN:-}" = ok ] || { echo "error: invalid signature" >&2; exit 1; }
EOF
chmod 0755 "$STUBS"/*

# run NAME [args…]: runs cli.sh with the stubs; PATH has no cosign unless
# WITH_COSIGN=1 and never a docker. Output in $WORK/NAME.out.
run() {
	name=$1
	shift
	path="$WORK/path-$name"
	mkdir -p "$path"
	for tool in curl uname; do ln -sf "$STUBS/$tool" "$path/$tool"; done
	[ "${WITH_COSIGN:-0}" != 1 ] || ln -sf "$STUBS/cosign" "$path/cosign"
	for tool in sh sha256sum awk tar gzip mktemp rm cp mv chmod mkdir sed tail cat ln grep tr printf; do
		t="$(command -v "$tool" 2>/dev/null)" && [ -x "$t" ] && ln -sf "$t" "$path/$tool"
	done
	status=0
	env -i PATH="$path" HOME="$WORK/home-$name" STUB_REL="$REL" STUB_LOG="$WORK/$name.log" \
		STUB_COSIGN="${STUB_COSIGN:-}" LAUNCHROOM_RELEASE_BASE=https://releases.test \
		sh "$SCRIPT" --version "$VERSION" --dir "$WORK/dir-$name" "$@" >"$WORK/$name.out" 2>&1 || status=$?
	echo "$status" >"$WORK/$name.status"
}
installed() { [ -x "$WORK/dir-$1/launchroom" ]; }
said() { grep -q "$2" "$WORK/$1.out" || fail "$1: output lacks '$2':
$(cat "$WORK/$1.out")"; }

# Bug 12: no cosign and no Docker must not install on the checksum alone.
run no-verifier
installed no-verifier && fail "no-verifier: installed without checking the signature"
[ "$(cat "$WORK/no-verifier.status")" != 0 ] || fail "no-verifier: exit 0"
said no-verifier "needs cosign"

run no-verifier-allowed --allow-unsigned
installed no-verifier-allowed || fail "no-verifier-allowed: not installed: $(cat "$WORK/no-verifier-allowed.out")"
said no-verifier-allowed "signature was not checked"

WITH_COSIGN=1 STUB_COSIGN=ok run cosign-ok
installed cosign-ok || fail "cosign-ok: not installed: $(cat "$WORK/cosign-ok.out")"
grep -q -- "--certificate-identity https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v$VERSION" "$WORK/cosign-ok.log" ||
	fail "cosign-ok: not checked against the exact release identity: $(cat "$WORK/cosign-ok.log")"
said cosign-ok "Signature verified"

# A present signature that does not verify stops the install, even with --allow-unsigned.
WITH_COSIGN=1 STUB_COSIGN=bad run cosign-bad --allow-unsigned
installed cosign-bad && fail "cosign-bad: installed with an invalid signature"
said cosign-bad "could not be verified"

# A release without a signature: only with --allow-unsigned.
rm "$REL/$sums.sigstore.json"
WITH_COSIGN=1 STUB_COSIGN=ok run unsigned
installed unsigned && fail "unsigned: installed a release without a signature"
said unsigned "no signature"
WITH_COSIGN=1 STUB_COSIGN=ok run unsigned-allowed --allow-unsigned
installed unsigned-allowed || fail "unsigned-allowed: not installed: $(cat "$WORK/unsigned-allowed.out")"
printf '{"bundle":"stub"}\n' >"$REL/$sums.sigstore.json"

# A tampered archive fails its checksum after a valid signature.
printf 'tampered' >>"$REL/$archive"
WITH_COSIGN=1 STUB_COSIGN=ok run tampered
installed tampered && fail "tampered: installed an archive that does not match the checksum file"
said tampered "checksum mismatch"

rm -rf "$WORK"
[ "$FAILED" = 0 ] || exit 1
printf 'cli.sh verification tests passed\n'
