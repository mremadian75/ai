#!/usr/bin/env bash
#
# lint-php.sh — fast PHP syntax lint (php -l) for the plugin.
#
# Default ("core") mode lints the highest-value PHP first: the main plugin file,
# everything under includes/, and module include/ dirs. Pass --all to lint every
# .php file in the repo (still fast; php -l is per-file and cheap).
#
# Usage:
#   bin/lint-php.sh             # core lint (main file + includes + module includes)
#   bin/lint-php.sh --all       # lint every .php file in the repo
#   bin/lint-php.sh --changed   # lint only PHP files changed vs git HEAD (+ untracked)
#   bin/lint-php.sh --verbose   # print each file as it is linted (per-file progress)
#   bin/lint-php.sh path ...    # lint only the given files/dirs
#
# Flags combine, e.g.:  bin/lint-php.sh --all --verbose
#
# Exit code: 0 if all files are syntax-clean, 1 if any file has a parse error,
#            2 on setup error (no php, or --changed outside a git repo).

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "ERROR: php CLI not found (set PHP_BIN to override)." >&2
    exit 2
fi

MODE="core"
VERBOSE=0
TIMEOUT_PER_FILE=0
EXPLICIT_PATHS=()
for arg in "$@"; do
    case "$arg" in
        --all) MODE="all" ;;
        --core) MODE="core" ;;
        --changed) MODE="changed" ;;
        --verbose|-v) VERBOSE=1 ;;
        --timeout-per-file=*) TIMEOUT_PER_FILE="${arg#*=}" ;;
        *) EXPLICIT_PATHS+=("$arg") ;;
    esac
done

# Portable per-file timeout: GNU `timeout` (Linux) or `gtimeout` (macOS coreutils).
# If neither is present, we degrade gracefully and lint without a timeout.
TIMEOUT_BIN=""
if [ "$TIMEOUT_PER_FILE" -gt 0 ] 2>/dev/null; then
    if command -v timeout >/dev/null 2>&1; then TIMEOUT_BIN="timeout"
    elif command -v gtimeout >/dev/null 2>&1; then TIMEOUT_BIN="gtimeout"
    else echo "NOTE: --timeout-per-file requested but no 'timeout'/'gtimeout' found; linting without a per-file timeout." >&2; fi
fi

collect_changed() {
    # Lint only what changed vs HEAD plus untracked files. Fails gracefully when
    # git is unavailable or this is not a repo (clear message, exit 2).
    if ! command -v git >/dev/null 2>&1; then
        echo "__LINT_ERR__ --changed requires git, which was not found on PATH."
        return 2
    fi
    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "__LINT_ERR__ --changed must be run inside a git working tree (none detected). Use --core or --all."
        return 2
    fi
    {
        git diff --name-only --diff-filter=ACMR HEAD 2>/dev/null
        git ls-files --others --exclude-standard 2>/dev/null
    } | grep -E '\.php$' | sort -u
}

collect() {
    if [ "${#EXPLICIT_PATHS[@]}" -gt 0 ]; then
        find "${EXPLICIT_PATHS[@]}" -type f -name '*.php' 2>/dev/null | sort
    elif [ "$MODE" = "changed" ]; then
        collect_changed
    elif [ "$MODE" = "all" ]; then
        find . -type f -name '*.php' -not -path '*/.git/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null | sort
    else
        # Core: main entry + includes + module includes (the runtime-critical code),
        # which is where a parse error would actually break the site.
        {
            echo "./future-island-intelligence-suite.php"
            echo "./uninstall.php"
            find ./includes -type f -name '*.php' 2>/dev/null
            find ./modules -type f -name '*.php' -path '*/includes/*' 2>/dev/null
        } | sort -u
    fi
}

raw="$(collect)"; rc=$?
if [ $rc -ne 0 ]; then
    # Surface the graceful --changed message without the marker prefix.
    printf '%s\n' "$raw" | sed 's/^__LINT_ERR__ /ERROR: /'
    exit 2
fi
mapfile -t FILES < <(printf '%s\n' "$raw" | grep -v '^$')

echo "== FIIS PHP lint ($MODE) =="
echo "PHP: $($PHP_BIN -r 'echo PHP_VERSION;')"
echo "Files: ${#FILES[@]}"
if [ "${#FILES[@]}" -eq 0 ]; then
    echo "Nothing to lint."
    exit 0
fi
[ -n "$TIMEOUT_BIN" ] && echo "Per-file timeout: ${TIMEOUT_PER_FILE}s (via $TIMEOUT_BIN)"
echo "----------------------------------------"

total=${#FILES[@]}
i=0
errors=0
timeouts=0
declare -a BAD=()
for f in "${FILES[@]}"; do
    [ -f "$f" ] || continue
    i=$((i + 1))
    # Per-file progress in verbose mode (printed BEFORE linting, so a hang/slow
    # file is identifiable; php -l is per-file so there is no whole-run timeout).
    [ "$VERBOSE" -eq 1 ] && printf '[%d/%d] %s\n' "$i" "$total" "$f"
    if [ -n "$TIMEOUT_BIN" ]; then
        out="$("$TIMEOUT_BIN" "$TIMEOUT_PER_FILE" "$PHP_BIN" -l "$f" 2>&1)"; rc=$?
    else
        out="$($PHP_BIN -l "$f" 2>&1)"; rc=$?
    fi
    if [ $rc -ne 0 ]; then
        errors=$((errors + 1))
        BAD+=("$f")
        # 124 is GNU timeout's "deadline exceeded" exit code — surface it distinctly
        # so a slow/hanging file is diagnosable rather than looking like a syntax error.
        if [ -n "$TIMEOUT_BIN" ] && [ $rc -eq 124 ]; then
            timeouts=$((timeouts + 1))
            echo "TIMEOUT  $f  (exceeded ${TIMEOUT_PER_FILE}s)"
        else
            echo "ERROR  $f  (exit $rc)"
            printf '%s\n' "$out" | sed 's/^/       | /'
        fi
    fi
done

echo "----------------------------------------"
echo "Linted: $i/$total   Errors: $errors   Timeouts: $timeouts"
if [ "$errors" -gt 0 ]; then
    echo "Lint FAILED: $errors file(s) with problems:"
    for f in "${BAD[@]}"; do echo "  - $f"; done
    exit 1
fi
echo "Lint OK: all ${total} file(s) syntax-clean."
exit 0
