#!/usr/bin/env bash
#
# future-island-live-validation-v2.sh — Phase 9C.1 operator validation script.
#
# Runs the read-only Future Island staging validation battery, captures every
# command's stdout/stderr/exit code into a timestamped evidence folder, redacts
# secrets, generates the evidence manifest and a tar.gz archive. It NEVER runs
# --apply, never performs destructive migrations, never calls AI generation,
# and refuses to run without explicit staging confirmation and a DB backup.
#
# Usage:
#   bash scripts/future-island-live-validation-v2.sh \
#     --i-confirm-this-is-staging \
#     --db-backup=/path/staging-backup.sql \
#     [--plugin-zip=/path/future-island-….zip] \
#     [--wp-path=/var/www/staging] \
#     [--php-error-log=/var/log/php-errors.log] \
#     [--operator="Name"] \
#     [--output=/path/evidence-root]
#
set -u

# ── args ─────────────────────────────────────────────────────────────────────
CONFIRM_STAGING=0
DB_BACKUP=""
PLUGIN_ZIP=""
WP_PATH=""
PHP_ERROR_LOG=""
OPERATOR="${USER:-unknown-operator}"
OUTPUT_ROOT="."
for arg in "$@"; do
    case "$arg" in
        --i-confirm-this-is-staging) CONFIRM_STAGING=1 ;;
        --db-backup=*)     DB_BACKUP="${arg#*=}" ;;
        --plugin-zip=*)    PLUGIN_ZIP="${arg#*=}" ;;
        --wp-path=*)       WP_PATH="${arg#*=}" ;;
        --php-error-log=*) PHP_ERROR_LOG="${arg#*=}" ;;
        --operator=*)      OPERATOR="${arg#*=}" ;;
        --output=*)        OUTPUT_ROOT="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

WP="wp"
[ -n "$WP_PATH" ] && WP="wp --path=$WP_PATH"

# ── hard stops ───────────────────────────────────────────────────────────────
if [ "$CONFIRM_STAGING" -ne 1 ]; then
    echo "REFUSED: pass --i-confirm-this-is-staging after verifying this is NOT production." >&2
    exit 3
fi
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
case "$SITEURL" in
    *prod*|*www.futureisland.*)
        echo "REFUSED: siteurl '$SITEURL' looks like production. This script is staging-only." >&2
        exit 3 ;;
esac
echo "siteurl: $SITEURL (operator confirmed staging)"

# ── evidence folder ─────────────────────────────────────────────────────────
STAMP="$(date -u +%Y%m%d-%H%M%S)"
EVDIR="$OUTPUT_ROOT/fi-evidence-$STAMP"
mkdir -p "$EVDIR/commands" "$EVDIR/screenshots" || { echo "Cannot create $EVDIR" >&2; exit 2; }
echo "Evidence folder: $EVDIR"

# ── secret redaction ────────────────────────────────────────────────────────
redact() {
    sed -E \
        -e 's/apify_api_[A-Za-z0-9]+/[redacted-token]/g' \
        -e 's/sk-[A-Za-z0-9_-]{8,}/[redacted-token]/g' \
        -e 's/sk_live_[A-Za-z0-9]{8,}/[redacted-token]/g' \
        -e 's/whsec_[A-Za-z0-9]{8,}/[redacted-token]/g' \
        -e 's/AIza[A-Za-z0-9_-]{20,}/[redacted-token]/g' \
        -e 's/(Authorization[\"'\'': ]*)[Bb]earer[[:space:]]+[A-Za-z0-9_.-]+/\1Bearer [redacted-token]/g'
}

# ── plugin ZIP install (optional) + SHA ─────────────────────────────────────
BUILD_SHA="$(printf '0%.0s' $(seq 1 64))"
if [ -n "$PLUGIN_ZIP" ]; then
    if [ ! -s "$PLUGIN_ZIP" ]; then echo "REFUSED: --plugin-zip not found: $PLUGIN_ZIP" >&2; exit 3; fi
    BUILD_SHA="$(sha256sum "$PLUGIN_ZIP" | awk '{print $1}')"
    echo "Plugin ZIP SHA-256: $BUILD_SHA" | tee "$EVDIR/build-sha256.txt"
    echo "Installing/updating plugin from ZIP (--force)…"
    $WP plugin install "$PLUGIN_ZIP" --force 2>&1 | redact | tee "$EVDIR/commands/plugin-install.out"
    $WP plugin activate future-island-intelligence-suite 2>&1 | redact | tee "$EVDIR/commands/plugin-activate.out"
fi

# ── command battery (READ-ONLY; never --apply) ──────────────────────────────
MANIFEST="$EVDIR/manifest-commands.txt"
: > "$MANIFEST"

run_cmd() {
    # $1 = manifest label (canonical command string), $2 = slug, $3… = command
    label="$1"; slug="$2"; shift 2
    out="$EVDIR/commands/$slug.out"
    "$@" >"$out.raw" 2>&1
    code=$?
    redact < "$out.raw" > "$out"
    rm -f "$out.raw"
    sha="$(sha256sum "$out" | awk '{print $1}')"
    printf '%s|exit=%d|sha256=%s\n' "$label" "$code" "$sha" >> "$MANIFEST"
    echo "[$code] $label"
    return 0
}

run_cmd "php -v"                                       php-v            php -v
run_cmd "wp --info"                                    wp-info          $WP --info
run_cmd "wp core version"                              wp-core-version  $WP core version
run_cmd "wp db query \"SELECT VERSION();\""            db-version       $WP db query "SELECT VERSION();"
run_cmd "wp option get siteurl"                        siteurl          $WP option get siteurl
run_cmd "wp option get home"                           home             $WP option get home
run_cmd "wp plugin list"                               plugin-list      $WP plugin list
run_cmd "wp theme list"                                theme-list       $WP theme list
run_cmd "wp ves verify-schema"                         verify-schema    $WP ves verify-schema
run_cmd "wp ves validate-staging --format=json"        validate-staging $WP ves validate-staging --format=json
run_cmd "wp ves readiness-check --format=json"         readiness        $WP ves readiness-check --format=json
run_cmd "wp ves rc-readiness-check --format=json"      rc-readiness     $WP ves rc-readiness-check --format=json
run_cmd "wp ves memory-summary --format=json"          memory-summary   $WP ves memory-summary --format=json
run_cmd "wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json" memory-context $WP ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json
run_cmd "wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json" gen-context $WP ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json
run_cmd "wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json" prompt-preview $WP ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json
run_cmd "wp ves operator-queue --workspace=1 --format=json" operator-queue $WP ves operator-queue --workspace=1 --format=json
run_cmd "wp ves memory-expire --dry-run"               memory-expire    $WP ves memory-expire --dry-run

# Optional trend battery (still read-only / dry-run).
run_cmd "wp ves trend-verify-staging"                  trend-verify     $WP ves trend-verify-staging
run_cmd "wp ves trend-evaluate"                        trend-evaluate   $WP ves trend-evaluate
run_cmd "wp ves trend-summary"                         trend-summary    $WP ves trend-summary
run_cmd "wp ves trend-obs-backfill --dry-run --limit=100" trend-backfill-dry $WP ves trend-obs-backfill --dry-run --limit=100

# ── PHP error log capture (optional, redacted, bounded) ─────────────────────
PHP_LOG_SHA=""
if [ -n "$PHP_ERROR_LOG" ] && [ -r "$PHP_ERROR_LOG" ]; then
    tail -n 300 "$PHP_ERROR_LOG" | redact > "$EVDIR/php-error-log-tail.txt"
    PHP_LOG_SHA="$(sha256sum "$EVDIR/php-error-log-tail.txt" | awk '{print $1}')"
    echo "PHP error log tail captured (sha256 $PHP_LOG_SHA)"
fi

# ── evidence pack (in-WP generator merges environment facts) ────────────────
$WP ves rc-evidence-pack --output="$EVDIR" --build-sha="$BUILD_SHA" --operator="$OPERATOR" \
    ${PHP_ERROR_LOG:+--php-error-log="$EVDIR/php-error-log-tail.txt"} \
    2>&1 | redact | tee "$EVDIR/commands/rc-evidence-pack.out"

# Attach the captured command outputs to the freshest generated pack so its
# validation_status can be computed honestly (jq optional; python3 fallback).
PACK_FILE="$(ls -1t "$EVDIR"/evidence-pack-*.json 2>/dev/null | head -1 || true)"
if [ -n "$PACK_FILE" ] && command -v python3 >/dev/null 2>&1; then
    python3 - "$PACK_FILE" "$MANIFEST" <<'PYEOF'
import hashlib, json, sys
pack_file, manifest = sys.argv[1], sys.argv[2]
pack = json.load(open(pack_file))
outputs = {}
for line in open(manifest):
    line = line.strip()
    if not line:
        continue
    label, exit_part, sha_part = line.split('|')
    outputs[label] = {
        'exit_code': int(exit_part.split('=')[1]),
        'output_sha256': sha_part.split('=')[1],
    }
pack['command_outputs'] = outputs
required = [
    'php -v', 'wp core version', 'wp option get siteurl', 'wp ves verify-schema',
    'wp ves validate-staging --format=json', 'wp ves rc-readiness-check --format=json',
    'wp ves memory-summary --format=json', 'wp ves operator-queue --workspace=1 --format=json',
    'wp ves memory-expire --dry-run',
]
all_ok = all(outputs.get(c, {}).get('exit_code', 1) == 0 and outputs.get(c, {}).get('output_sha256') for c in required)
pack['validation_status'] = 'passed' if all_ok and pack.get('operator', {}).get('name') else 'incomplete'

def canonicalize(v):
    if isinstance(v, dict):
        return {str(k): canonicalize(v[k]) for k in sorted(v, key=str)}
    if isinstance(v, list):
        return [canonicalize(x) for x in v]
    return v
h = dict(pack); h.pop('evidence_pack_hash', None)
pack['evidence_pack_hash'] = hashlib.sha256(
    json.dumps(canonicalize(h), separators=(',', ':'), ensure_ascii=False).encode()
).hexdigest()
json.dump(pack, open(pack_file, 'w'), indent=2)
print('evidence pack completed:', pack_file)
print('validation_status:', pack['validation_status'])
print('evidence_pack_hash:', pack['evidence_pack_hash'])
PYEOF
fi

# ── archive ──────────────────────────────────────────────────────────────────
ARCHIVE="$OUTPUT_ROOT/fi-evidence-$STAMP.tar.gz"
tar -czf "$ARCHIVE" -C "$OUTPUT_ROOT" "fi-evidence-$STAMP"
echo "Evidence archive: $ARCHIVE"
sha256sum "$ARCHIVE"

# ── next steps ───────────────────────────────────────────────────────────────
cat <<NEXT

NEXT STEPS (manual, evidence required):
 1. Complete LIVE-BROWSER-VALIDATION-CHECKLIST.md — save screenshots into:
      $EVDIR/screenshots/
 2. Re-run this script OR re-hash the pack after adding screenshots if you want
    them inside the manifest.
 3. Review every command output in $EVDIR/commands/ — any non-zero exit code
    means the validation FAILED; do not record a pass.
 4. Record the validation ONLY through the verified pack:
      $WP ves rc-record-live-validation --evidence-pack=$PACK_FILE
 5. Confirm:  $WP ves rc-readiness-check --strict --format=json
 NOTE: a pass recorded here still does NOT make the build production-ready.
NEXT
exit 0
