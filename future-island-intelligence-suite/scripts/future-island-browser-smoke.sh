#!/usr/bin/env bash
#
# future-island-browser-smoke.sh — Phase 9C.3 minimal browser smoke harness.
#
# Dependency-free (curl only). It can NOT replace the human browser checklist:
# it only verifies that the operator-facing admin URLs respond (200/302) and
# prints the screenshot list the checklist requires. It never fakes screenshots
# and never authenticates past the login wall by itself — pass a logged-in
# cookie jar exported from your browser session if you want authenticated
# status codes.
#
# Usage:
#   bash scripts/future-island-browser-smoke.sh --base-url=https://staging.example.com \
#     [--cookie-jar=/path/cookies.txt]
#
set -u

BASE_URL=""
COOKIE_JAR=""
for arg in "$@"; do
    case "$arg" in
        --base-url=*)   BASE_URL="${arg#*=}" ;;
        --cookie-jar=*) COOKIE_JAR="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done
if [ -z "$BASE_URL" ]; then echo "REFUSED: --base-url is required." >&2; exit 2; fi
if ! command -v curl >/dev/null 2>&1; then echo "REFUSED: curl not found." >&2; exit 2; fi

CURL="curl -s -o /dev/null -w %{http_code} --max-time 20"
[ -n "$COOKIE_JAR" ] && CURL="$CURL -b $COOKIE_JAR"

check() {
    path="$1"; label="$2"
    code=$($CURL "$BASE_URL$path" || echo "000")
    case "$code" in
        200|302) echo "OK   [$code] $label  $path" ;;
        *)       echo "FAIL [$code] $label  $path" ;;
    esac
}

echo "== Future Island browser smoke (status codes only — NOT a visual validation) =="
check "/wp-login.php"                                              "Login page"
check "/wp-admin/admin.php?page=ves-intelligence-suite"            "Intelligence Suite console"
check "/wp-admin/admin.php?page=ves-release-candidate"             "Release Candidate page"
check "/wp-admin/admin.php?page=ves-memory-knowledge"              "Memory / Knowledge"
check "/wp-admin/admin.php?page=ves-operations"                    "Operations"

cat <<'SHOTS'

REQUIRED SCREENSHOTS (manual; save into the evidence folder /screenshots):
  01 Signal Room
  02 Social Media / Signal Report
  03 Evidence Gate — blocked state AND ready state
  04 Operator Queue
  05 Memory / Brand Context
  06 Generation Context Preview
  07 Prompt Package Preview
  08 Brief Workbench
  09 Draft Workbench
  10 Release Candidate page
  11 Security / dead-letter diagnostics section
  12 Any error state encountered
Also export: browser console log, failed network requests, PHP error log tail.
Record whether ANY Generate / Publish / Auto-approve button appears (it must not).
SHOTS
exit 0
