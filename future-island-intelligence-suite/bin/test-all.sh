#!/usr/bin/env bash
#
# test-all.sh — run every direct PHP test script in the plugin.
#
# These tests are self-contained: each defines its own WordPress shims/mocks and
# makes NO real network/API calls. This runner just executes each test-*.php with
# the PHP CLI, captures its exit code, and prints a PASS/FAIL summary.
#
# Usage:
#   bin/test-all.sh            # run all tests
#   bin/test-all.sh -q         # quiet: only show failures + summary
#   bin/test-all.sh path/...   # run only tests under the given path(s)
#
# Exit code: 0 if all tests pass, 1 if any test fails (CI-friendly).

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "ERROR: php CLI not found (set PHP_BIN to override)." >&2
    exit 2
fi

QUIET=0
SEARCH_PATHS=()
for arg in "$@"; do
    case "$arg" in
        -q|--quiet) QUIET=1 ;;
        *) SEARCH_PATHS+=("$arg") ;;
    esac
done
if [ "${#SEARCH_PATHS[@]}" -eq 0 ]; then
    SEARCH_PATHS=("tests" "modules")
fi

# Collect test files (sorted, deterministic).
mapfile -t TEST_FILES < <(find "${SEARCH_PATHS[@]}" -type f -name 'test-*.php' 2>/dev/null | sort)

total=0; passed=0; failed=0
declare -a FAILED_FILES=()

echo "== FIIS test runner =="
echo "PHP: $($PHP_BIN -r 'echo PHP_VERSION;')"
echo "Tests discovered: ${#TEST_FILES[@]}"
echo "----------------------------------------"

for f in "${TEST_FILES[@]}"; do
    total=$((total + 1))
    output="$($PHP_BIN "$f" 2>&1)"
    rc=$?
    # Surface the test's own "X passed, Y failed" tally line if present.
    tally="$(printf '%s\n' "$output" | grep -iE '[0-9]+ (passed|pass).*[0-9]+ (failed|fail)' | tail -1)"
    if [ $rc -eq 0 ]; then
        passed=$((passed + 1))
        [ "$QUIET" -eq 0 ] && printf 'PASS  %s   %s\n' "$f" "$tally"
    else
        failed=$((failed + 1))
        FAILED_FILES+=("$f")
        printf 'FAIL  %s   (exit %d)   %s\n' "$f" "$rc" "$tally"
        # On failure, always show the captured output (last 30 lines) for triage.
        printf '%s\n' "$output" | tail -30 | sed 's/^/        | /'
    fi
done

echo "----------------------------------------"
echo "Total: $total   Passed: $passed   Failed: $failed"
if [ "$failed" -gt 0 ]; then
    echo "Failed files:"
    for f in "${FAILED_FILES[@]}"; do echo "  - $f"; done
    exit 1
fi
echo "All tests passed."
exit 0
