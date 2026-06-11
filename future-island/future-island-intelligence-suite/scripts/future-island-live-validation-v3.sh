#!/usr/bin/env bash
#
# future-island-live-validation-v3.sh — Phase 9E.3 operator validation script.
#
# v3 upgrades over v2 (which remains available): explicit expected-staging-host
# matching, separate stdout/stderr/combined capture per command, evidence pack
# v2 with file-backed hashes, browser artifact requirements, archive manifest
# hashing, and three modes. It NEVER runs --apply, never performs destructive
# migrations, never calls AI generation, and never claims a pass without the
# browser artifacts.
#
# Usage:
#   bash scripts/future-island-live-validation-v3.sh \
#     --mode=collect-cli|finalize|full \
#     --expected-siteurl=https://staging.example.com   (or --expected-host=staging.example.com) \
#     --i-confirm-this-is-staging \
#     --db-backup=/path/staging-backup.sql \
#     [--plugin-zip=/path/plugin.zip] [--wp-path=/var/www/staging] \
#     [--operator="Name"] [--output=/path/evidence-root] \
#     [--evidence-dir=/path/existing-evidence-folder]      (finalize mode) \
#     [--screenshots-dir=<dir>] [--browser-console-log=<file>] \
#     [--network-errors-log=<file>] [--php-error-log=<file>]
#
set -u

MODE="full"
CONFIRM_STAGING=0
EXPECTED_SITEURL=""
EXPECTED_HOST=""
DB_BACKUP=""
PLUGIN_ZIP=""
WP_PATH=""
OPERATOR="${USER:-unknown-operator}"
OUTPUT_ROOT="."
EVIDENCE_DIR=""
SCREENSHOTS_DIR=""
BROWSER_CONSOLE_LOG=""
NETWORK_ERRORS_LOG=""
PHP_ERROR_LOG=""
for arg in "$@"; do
    case "$arg" in
        --mode=*)               MODE="${arg#*=}" ;;
        --i-confirm-this-is-staging) CONFIRM_STAGING=1 ;;
        --expected-siteurl=*)   EXPECTED_SITEURL="${arg#*=}" ;;
        --expected-host=*)      EXPECTED_HOST="${arg#*=}" ;;
        --db-backup=*)          DB_BACKUP="${arg#*=}" ;;
        --plugin-zip=*)         PLUGIN_ZIP="${arg#*=}" ;;
        --wp-path=*)            WP_PATH="${arg#*=}" ;;
        --operator=*)           OPERATOR="${arg#*=}" ;;
        --output=*)             OUTPUT_ROOT="${arg#*=}" ;;
        --evidence-dir=*)       EVIDENCE_DIR="${arg#*=}" ;;
        --screenshots-dir=*)    SCREENSHOTS_DIR="${arg#*=}" ;;
        --browser-console-log=*) BROWSER_CONSOLE_LOG="${arg#*=}" ;;
        --network-errors-log=*) NETWORK_ERRORS_LOG="${arg#*=}" ;;
        --php-error-log=*)      PHP_ERROR_LOG="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

case "$MODE" in collect-cli|finalize|full) : ;; *) echo "REFUSED: --mode must be collect-cli, finalize or full." >&2; exit 2 ;; esac

WP="wp"
[ -n "$WP_PATH" ] && WP="wp --path=$WP_PATH"

# ── secret redaction ─────────────────────────────────────────────────────────
redact() {
    sed -E \
        -e 's/apify_api_[A-Za-z0-9]+/[redacted-token]/g' \
        -e 's/sk-[A-Za-z0-9_-]{8,}/[redacted-token]/g' \
        -e 's/sk_live_[A-Za-z0-9]{8,}/[redacted-token]/g' \
        -e 's/whsec_[A-Za-z0-9]{8,}/[redacted-token]/g' \
        -e 's/AIza[A-Za-z0-9_-]{20,}/[redacted-token]/g' \
        -e 's/(Authorization[\"'\'': ]*)[Bb]earer[[:space:]]+[A-Za-z0-9_.-]+/\1Bearer [redacted-token]/g'
}

sha() { sha256sum "$1" | awk '{print $1}'; }

# ── hard stops (9E.3: strong staging detection) ──────────────────────────────
if [ "$CONFIRM_STAGING" -ne 1 ]; then
    echo "REFUSED: pass --i-confirm-this-is-staging after verifying this is NOT production." >&2
    exit 3
fi
if [ -z "$EXPECTED_SITEURL" ] && [ -z "$EXPECTED_HOST" ]; then
    echo "REFUSED: --expected-siteurl=<url> or --expected-host=<host> is REQUIRED. Weak staging detection is not accepted." >&2
    exit 3
fi
if [ "$MODE" != "finalize" ]; then
    if [ -z "$DB_BACKUP" ] || [ ! -s "$DB_BACKUP" ]; then
        echo "REFUSED: --db-backup must point to an existing, non-empty DB export. Create one first:" >&2
        echo "  $WP db export staging-backup-\$(date +%Y%m%d-%H%M%S).sql" >&2
        exit 3
    fi
    if ! command -v wp >/dev/null 2>&1; then
        echo "REFUSED: WP-CLI not found on PATH." >&2
        exit 3
    fi

    SITEURL="$($WP option get siteurl 2>/dev/null || echo '')"
    HOMEURL="$($WP option get home 2>/dev/null || echo '')"
    norm() { echo "$1" | sed -E 's#/+$##' | tr 'A-Z' 'a-z'; }
    host_of() { echo "$1" | sed -E 's#^[a-z]+://##' | cut -d/ -f1 | cut -d: -f1; }
    if [ -n "$EXPECTED_SITEURL" ]; then
        if [ "$(norm "$SITEURL")" != "$(norm "$EXPECTED_SITEURL")" ]; then
            echo "REFUSED: siteurl '$SITEURL' does not match --expected-siteurl '$EXPECTED_SITEURL'." >&2
            exit 3
        fi
        if [ "$(norm "$HOMEURL")" != "$(norm "$EXPECTED_SITEURL")" ] && [ "$(host_of "$(norm "$HOMEURL")")" != "$(host_of "$(norm "$EXPECTED_SITEURL")")" ]; then
            echo "REFUSED: home '$HOMEURL' does not match the expected staging URL/host." >&2
            exit 3
        fi
    else
        for u in "$SITEURL" "$HOMEURL"; do
            if [ "$(host_of "$(norm "$u")")" != "$(norm "$EXPECTED_HOST")" ]; then
                echo "REFUSED: '$u' is not on expected staging host '$EXPECTED_HOST'." >&2
                exit 3
            fi
        done
    fi
    echo "siteurl/home verified against expected staging target: $SITEURL"
fi

# ── finalize mode requires the browser artifacts up front ────────────────────
require_browser_artifacts() {
    if [ -z "$SCREENSHOTS_DIR" ] || [ ! -d "$SCREENSHOTS_DIR" ]; then
        echo "REFUSED: --screenshots-dir=<dir> with the 12 required screenshots is mandatory to finalize." >&2
        exit 3
    fi
    for shot in 01-signal-room.png 02-social-media-signal-report.png 03-evidence-gate-blocked.png \
                04-evidence-gate-ready.png 05-operator-queue.png 06-memory-brand-context.png \
                07-generation-context-preview.png 08-prompt-package-preview.png 09-brief-workbench.png \
                10-draft-workbench.png 11-release-candidate-page.png 12-security-dead-letter-diagnostics.png; do
        if [ ! -s "$SCREENSHOTS_DIR/$shot" ]; then
            echo "REFUSED: required screenshot missing: $SCREENSHOTS_DIR/$shot" >&2
            exit 3
        fi
    done
    if [ -z "$BROWSER_CONSOLE_LOG" ] || [ ! -s "$BROWSER_CONSOLE_LOG" ]; then
        echo "REFUSED: --browser-console-log=<file> is mandatory (use NO_CONSOLE_ERRORS_OBSERVED content when clean)." >&2
        exit 3
    fi
    if [ -z "$NETWORK_ERRORS_LOG" ] || [ ! -s "$NETWORK_ERRORS_LOG" ]; then
        echo "REFUSED: --network-errors-log=<file> is mandatory (use NO_NETWORK_ERRORS_OBSERVED content when clean)." >&2
        exit 3
    fi
    if [ -z "$PHP_ERROR_LOG" ] || [ ! -r "$PHP_ERROR_LOG" ]; then
        echo "REFUSED: --php-error-log=<file> is mandatory for a finalized pack." >&2
        exit 3
    fi
}
if [ "$MODE" = "finalize" ] || [ "$MODE" = "full" ]; then
    require_browser_artifacts
fi

# ── evidence folder ──────────────────────────────────────────────────────────
if [ "$MODE" = "finalize" ]; then
    if [ -z "$EVIDENCE_DIR" ] || [ ! -d "$EVIDENCE_DIR" ]; then
        echo "REFUSED: finalize mode needs --evidence-dir=<folder created by collect-cli>." >&2
        exit 3
    fi
    EVDIR="$EVIDENCE_DIR"
else
    STAMP="$(date -u +%Y%m%d-%H%M%S)"
    EVDIR="$OUTPUT_ROOT/fi-evidence-$STAMP"
    mkdir -p "$EVDIR/commands" "$EVDIR/screenshots" "$EVDIR/logs" || { echo "Cannot create $EVDIR" >&2; exit 2; }
fi
echo "Evidence folder: $EVDIR"
MANIFEST="$EVDIR/manifest-commands.txt"

# ── plugin ZIP (optional) ────────────────────────────────────────────────────
BUILD_SHA="$(printf '0%.0s' $(seq 1 64))"
if [ -n "$PLUGIN_ZIP" ] && [ "$MODE" != "finalize" ]; then
    [ -s "$PLUGIN_ZIP" ] || { echo "REFUSED: --plugin-zip not found: $PLUGIN_ZIP" >&2; exit 3; }
    BUILD_SHA="$(sha "$PLUGIN_ZIP")"
    echo "$BUILD_SHA  $(basename "$PLUGIN_ZIP")" > "$EVDIR/build-sha256.txt"
    $WP plugin install "$PLUGIN_ZIP" --force 2>&1 | redact | tee "$EVDIR/commands/plugin-install.combined.txt" >/dev/null
    $WP plugin activate future-island-intelligence-suite 2>&1 | redact | tee "$EVDIR/commands/plugin-activate.combined.txt" >/dev/null
fi
[ -f "$EVDIR/build-sha256.txt" ] && BUILD_SHA="$(awk '{print $1}' "$EVDIR/build-sha256.txt" | head -1)"

# ── DB backup hash ───────────────────────────────────────────────────────────
DB_BACKUP_SHA=""
if [ -n "$DB_BACKUP" ] && [ -s "$DB_BACKUP" ]; then
    DB_BACKUP_SHA="$(sha "$DB_BACKUP")"
    echo "$DB_BACKUP_SHA  $(basename "$DB_BACKUP")" > "$EVDIR/db-backup-sha256.txt"
fi

# ── command battery (READ-ONLY; stdout/stderr/combined; never --apply) ──────
run_cmd() {
    label="$1"; slug="$2"; shift 2
    out="$EVDIR/commands/$slug.out"; err="$EVDIR/commands/$slug.err"; comb="$EVDIR/commands/$slug.combined.txt"
    "$@" >"$out.raw" 2>"$err.raw"
    code=$?
    redact < "$out.raw" > "$out"; redact < "$err.raw" > "$err"
    cat "$out" "$err" > "$comb"
    rm -f "$out.raw" "$err.raw"
    printf '%s|exit=%d|stdout=%s|stdout_sha=%s|stderr=%s|stderr_sha=%s|combined=%s|combined_sha=%s\n' \
        "$label" "$code" "commands/$slug.out" "$(sha "$out")" "commands/$slug.err" "$(sha "$err")" "commands/$slug.combined.txt" "$(sha "$comb")" >> "$MANIFEST"
    echo "[$code] $label"
}

if [ "$MODE" != "finalize" ]; then
    : > "$MANIFEST"
    run_cmd "php -v"                                php-v            php -v
    run_cmd "wp --info"                             wp-info          $WP --info
    run_cmd "wp core version"                       wp-core-version  $WP core version
    run_cmd "wp db query \"SELECT VERSION();\""     db-version       $WP db query "SELECT VERSION();"
    run_cmd "wp option get siteurl"                 siteurl          $WP option get siteurl
    run_cmd "wp option get home"                    home             $WP option get home
    run_cmd "wp plugin list"                        plugin-list      $WP plugin list
    run_cmd "wp theme list"                         theme-list       $WP theme list
    run_cmd "wp ves verify-schema"                  verify-schema    $WP ves verify-schema
    run_cmd "wp ves validate-staging --format=json" validate-staging $WP ves validate-staging --format=json
    run_cmd "wp ves readiness-check --format=json"  readiness        $WP ves readiness-check --format=json
    run_cmd "wp ves rc-readiness-check --format=json" rc-readiness   $WP ves rc-readiness-check --format=json
    run_cmd "wp ves memory-summary --format=json"   memory-summary   $WP ves memory-summary --format=json
    run_cmd "wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json" memory-context $WP ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json
    run_cmd "wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json" gen-context $WP ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json
    run_cmd "wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json" prompt-preview $WP ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json
    run_cmd "wp ves operator-queue --workspace=1 --format=json" operator-queue $WP ves operator-queue --workspace=1 --format=json
    run_cmd "wp ves memory-expire --dry-run"        memory-expire    $WP ves memory-expire --dry-run
fi

# ── browser artifacts into the evidence folder ───────────────────────────────
if [ "$MODE" = "finalize" ] || [ "$MODE" = "full" ]; then
    cp "$SCREENSHOTS_DIR"/*.png "$EVDIR/screenshots/" 2>/dev/null
    redact < "$BROWSER_CONSOLE_LOG" > "$EVDIR/logs/browser-console.log"
    redact < "$NETWORK_ERRORS_LOG"  > "$EVDIR/logs/network-errors.log"
    tail -n 300 "$PHP_ERROR_LOG" | redact > "$EVDIR/logs/php-error-log-tail.log"
fi

# ── evidence pack v2 assembly (python3) ──────────────────────────────────────
PACK_FILE="$EVDIR/evidence-pack-v2.json"
if command -v python3 >/dev/null 2>&1; then
python3 - "$EVDIR" "$PACK_FILE" "$MODE" "$BUILD_SHA" "$DB_BACKUP_SHA" "$OPERATOR" <<'PYEOF'
import hashlib, json, os, sys
ev, pack_file, mode, build_sha, db_sha, operator = sys.argv[1:7]

def fsha(p):
    h = hashlib.sha256()
    with open(p, 'rb') as fh:
        for chunk in iter(lambda: fh.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()

def grab(cmdline):
    # wp option values captured during the run
    p = os.path.join(ev, 'commands', cmdline)
    return open(p).read().strip() if os.path.isfile(p) else ''

outputs = {}
manifest = os.path.join(ev, 'manifest-commands.txt')
if os.path.isfile(manifest):
    for line in open(manifest):
        line = line.strip()
        if not line:
            continue
        parts = dict(p.split('=', 1) for p in line.split('|')[1:])
        label = line.split('|')[0]
        outputs[label] = {
            'exit_code': int(parts['exit']),
            'stdout_file': parts['stdout'], 'stdout_sha256': parts['stdout_sha'],
            'stderr_file': parts['stderr'], 'stderr_sha256': parts['stderr_sha'],
            'combined_file': parts['combined'], 'combined_sha256': parts['combined_sha'],
        }

shots_dir = os.path.join(ev, 'screenshots')
manifest_shots, shot_files = [], {}
if os.path.isdir(shots_dir):
    for name in sorted(os.listdir(shots_dir)):
        if name.lower().endswith('.png'):
            manifest_shots.append(name)
            shot_files[name] = fsha(os.path.join(shots_dir, name))

def log_pair(rel):
    p = os.path.join(ev, rel)
    return (rel, fsha(p)) if os.path.isfile(p) else ('', '')

console_f, console_h = log_pair('logs/browser-console.log')
net_f, net_h = log_pair('logs/network-errors.log')
php_f, php_h = log_pair('logs/php-error-log-tail.log')

REQUIRED = ['php -v','wp --info','wp core version','wp db query "SELECT VERSION();"',
 'wp option get siteurl','wp option get home','wp plugin list','wp theme list',
 'wp ves verify-schema','wp ves validate-staging --format=json','wp ves readiness-check --format=json',
 'wp ves rc-readiness-check --format=json','wp ves memory-summary --format=json',
 'wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json',
 'wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json',
 'wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json',
 'wp ves operator-queue --workspace=1 --format=json','wp ves memory-expire --dry-run']
REQ_SHOTS = ['01-signal-room.png','02-social-media-signal-report.png','03-evidence-gate-blocked.png',
 '04-evidence-gate-ready.png','05-operator-queue.png','06-memory-brand-context.png',
 '07-generation-context-preview.png','08-prompt-package-preview.png','09-brief-workbench.png',
 '10-draft-workbench.png','11-release-candidate-page.png','12-security-dead-letter-diagnostics.png']

cli_ok = all(outputs.get(c, {}).get('exit_code', 1) == 0 for c in REQUIRED)
browser_ok = all(s in shot_files for s in REQ_SHOTS) and console_h and net_h and php_h
status = 'passed' if (mode != 'collect-cli' and cli_ok and browser_ok and operator and db_sha) else 'incomplete'

pack = {
  'schema_version': '2.0',
  'build_sha256': build_sha,
  'plugin_version': 'see wp plugin list output',
  'rc_label': '',
  'siteurl': grab('siteurl.out'),
  'home': grab('home.out'),
  'wp_version': grab('wp-core-version.out'),
  'php_version': grab('php-v.out').split('\n')[0] if grab('php-v.out') else '',
  'db_version': grab('db-version.out'),
  'db_backup_sha256': db_sha,
  'command_outputs': outputs,
  'screenshots_manifest': manifest_shots,
  'screenshot_files': shot_files,
  'php_error_log_file': php_f, 'php_error_log_sha256': php_h,
  'browser_console_log_file': console_f, 'browser_console_log_sha256': console_h,
  'network_errors_file': net_f, 'network_errors_sha256': net_h,
  'evidence_archive_sha256': '0' * 64,  # replaced below with the manifest-files hash
  'generated_at': __import__('datetime').datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S'),
  'operator': {'name': operator, 'user_id': 0},
  'validation_status': status,
  'limitations': ['Human visual evidence (screenshots) reviewed by an operator; this pack proves capture, not UX quality.'],
}

# Non-circular archive integrity: hash every artifact into manifest-files.txt,
# store ITS sha256 in the pack, then archive everything (incl. the pack).
files_manifest = os.path.join(ev, 'manifest-files.txt')
with open(files_manifest, 'w') as mf:
    for root, _, files in os.walk(ev):
        for name in sorted(files):
            p = os.path.join(root, name)
            rel = os.path.relpath(p, ev)
            if rel in ('manifest-files.txt', 'evidence-pack-v2.json'):
                continue
            mf.write('%s  %s\n' % (fsha(p), rel))
pack['evidence_archive_sha256'] = fsha(files_manifest)

def canonicalize(v):
    if isinstance(v, dict):
        return {str(k): canonicalize(v[k]) for k in sorted(v, key=str)}
    if isinstance(v, list):
        return [canonicalize(x) for x in v]
    return v
h = dict(pack)
pack['evidence_pack_hash'] = hashlib.sha256(
    json.dumps(canonicalize(h), separators=(',', ':'), ensure_ascii=False).encode()
).hexdigest()
json.dump(pack, open(pack_file, 'w'), indent=2)
print('evidence pack v2:', pack_file)
print('validation_status:', status)
print('evidence_pack_hash:', pack['evidence_pack_hash'])
print('archive_manifest_sha256:', pack['evidence_archive_sha256'])
PYEOF
else
    echo "WARNING: python3 unavailable — pack assembly skipped; collect mode artifacts remain usable." >&2
fi

# ── archive ──────────────────────────────────────────────────────────────────
BASE="$(basename "$EVDIR")"
ARCHIVE="$(dirname "$EVDIR")/$BASE.tar.gz"
tar -czf "$ARCHIVE" -C "$(dirname "$EVDIR")" "$BASE"
echo "Evidence archive: $ARCHIVE"
sha256sum "$ARCHIVE"

cat <<NEXT

NEXT STEPS:
 1. Review every command output in $EVDIR/commands/ — any non-zero exit code means FAILED.
 2. Confirm the 12 screenshots + logs/ in the evidence folder match LIVE-BROWSER-VALIDATION-CHECKLIST.md.
 3. Record ONLY through file-backed verification:
      $WP ves rc-record-live-validation --evidence-pack=$PACK_FILE --evidence-root=$EVDIR
    (or --evidence-archive=$ARCHIVE)
 4. Strict gate: $WP ves rc-readiness-check --strict --format=json
 NOTE: a recorded pass still does NOT make the build production-ready.
NEXT
exit 0
