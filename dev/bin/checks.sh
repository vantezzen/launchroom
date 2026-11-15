# Pass/fail counters of the dev end-to-end scripts (dev/cli-e2e.sh,
# dev/git-e2e.sh), sourced from the repo root. pass and fail always succeed,
# so `check && pass … || fail …` reads as if/else.
passed=0
failed=0
pass() { passed=$((passed + 1)); printf '  PASS  %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf '  FAIL  %s\n' "$1"; [ -z "${2:-}" ] || printf '%s\n' "$2" | sed 's/^/        /'; }
