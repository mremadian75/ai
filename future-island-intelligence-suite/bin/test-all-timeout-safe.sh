#!/usr/bin/env bash
#
# test-all-timeout-safe.sh — run every PHP test file with a per-file timeout.
#
# This intentionally complements bin/test-all.sh instead of replacing it. It is
# useful in local containers and CI/staging jobs where one broad runner process
# can hang or exceed an outer timeout. Failing test output is not hidden.
#
# Usage:
#   bin/test-all-timeout-safe.sh
#   bin/test-all-timeout-safe.sh -q
#   FIIS_TEST_TIMEOUT=60 bin/test-all-timeout-safe.sh tests modules
#   FIIS_TEST_LOG=/tmp/fi-tests.log bin/test-all-timeout-safe.sh -q

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

PHP_BIN="${PHP_BIN:-php}"
PER_FILE_TIMEOUT="${FIIS_TEST_TIMEOUT:-45}"
LOG_FILE="${FIIS_TEST_LOG:-$ROOT/test-all-timeout-safe.log}"

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

: > "$LOG_FILE"
echo "== FIIS timeout-safe test runner ==" | tee -a "$LOG_FILE"
echo "PHP: $($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null)" | tee -a "$LOG_FILE"
echo "Per-file timeout: ${PER_FILE_TIMEOUT}s" | tee -a "$LOG_FILE"

mapfile -t TEST_FILES < <(find "${SEARCH_PATHS[@]}" -type f -name 'test-*.php' 2>/dev/null | sort)

echo "Tests discovered: ${#TEST_FILES[@]}" | tee -a "$LOG_FILE"
echo "----------------------------------------" | tee -a "$LOG_FILE"

passed=0
failed=0
timed_out=0
declare -a FAILED_FILES=()
declare -a TIMEOUT_FILES=()

for test_file in "${TEST_FILES[@]}"; do
    rel="${test_file#$ROOT/}"
    tmp="$(mktemp)"
    if timeout "$PER_FILE_TIMEOUT" "$PHP_BIN" "$test_file" > "$tmp" 2>&1; then
        passed=$((passed + 1))
        if [ "$QUIET" -eq 0 ]; then
            tally="$(grep -iE '[0-9]+ (passed|pass).*[0-9]+ (failed|fail)' "$tmp" | tail -1)"
            printf 'PASS  %s   %s\n' "$rel" "$tally" | tee -a "$LOG_FILE"
        fi
        cat "$tmp" >> "$LOG_FILE"
    else
        code=$?
        if [ "$code" -eq 124 ]; then
            timed_out=$((timed_out + 1))
            TIMEOUT_FILES+=("$rel")
            echo "TIMEOUT  $rel" | tee -a "$LOG_FILE"
        else
            failed=$((failed + 1))
            FAILED_FILES+=("$rel")
            echo "FAIL  $rel (exit $code)" | tee -a "$LOG_FILE"
        fi
        cat "$tmp" | tee -a "$LOG_FILE"
    fi
    rm -f "$tmp"
done

total="${#TEST_FILES[@]}"
echo "----------------------------------------" | tee -a "$LOG_FILE"
echo "Total: $total   Passed: $passed   Failed: $failed   Timeouts: $timed_out" | tee -a "$LOG_FILE"
if [ "$failed" -ne 0 ]; then
    echo "Failed files:" | tee -a "$LOG_FILE"
    for f in "${FAILED_FILES[@]}"; do echo "  - $f" | tee -a "$LOG_FILE"; done
fi
if [ "$timed_out" -ne 0 ]; then
    echo "Timed out files:" | tee -a "$LOG_FILE"
    for f in "${TIMEOUT_FILES[@]}"; do echo "  - $f" | tee -a "$LOG_FILE"; done
fi
echo "Log: $LOG_FILE" | tee -a "$LOG_FILE"

if [ "$failed" -ne 0 ] || [ "$timed_out" -ne 0 ]; then
    exit 1
fi
exit 0
