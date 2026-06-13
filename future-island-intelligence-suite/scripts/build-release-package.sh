#!/usr/bin/env bash
#
# build-release-package.sh — produce the installable WordPress plugin ZIP.
#
# Builds <out-dir>/<name>.zip containing the future-island-intelligence-suite/
# plugin folder (installable via Plugins → Add New → Upload), writes the
# matching .sha256 file, and verifies archive integrity with unzip -t.
#
# Usage:
#   bash scripts/build-release-package.sh [--name=<package-name>] [--out=<dir>]
# Defaults:
#   --name=future-island-intelligence-suite-v0.4.0-modular-saas
#   --out=<parent of the plugin directory>
#
# Exit codes: 0 built+verified; 1 verification failed; 2 environment problem.

set -u

NAME="future-island-intelligence-suite-v0.4.0-modular-saas"
OUT=""
for arg in "$@"; do
    case "$arg" in
        --name=*) NAME="${arg#--name=}" ;;
        --out=*)  OUT="${arg#--out=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_BASENAME="$(basename "$PLUGIN_DIR")"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
OUT="${OUT:-$PARENT_DIR}"
mkdir -p "$OUT"
ZIP_PATH="$OUT/$NAME.zip"
SHA_PATH="$ZIP_PATH.sha256"

for bin in zip unzip sha256sum; do
    command -v "$bin" >/dev/null 2>&1 || { echo "ERROR: '$bin' is required." >&2; exit 2; }
done

rm -f "$ZIP_PATH" "$SHA_PATH"

echo "== Building $ZIP_PATH from $PLUGIN_DIR =="
(
    cd "$PARENT_DIR" || exit 2
    zip -rq "$ZIP_PATH" "$PLUGIN_BASENAME" \
        -x "*/.git/*" "*/node_modules/*" "*/vendor/bin/*" "*/tmp/*" "*/.DS_Store" "*/$NAME.zip" "*/$NAME.zip.sha256"
) || { echo "ERROR: zip failed." >&2; exit 2; }

(
    cd "$OUT" || exit 2
    sha256sum "$(basename "$ZIP_PATH")" > "$(basename "$SHA_PATH")"
) || { echo "ERROR: sha256sum failed." >&2; exit 2; }

echo "== SHA256 =="
cat "$SHA_PATH"

echo "== Verifying SHA matches =="
( cd "$OUT" && sha256sum -c "$(basename "$SHA_PATH")" ) || { echo "ERROR: SHA mismatch." >&2; exit 1; }

echo "== Verifying ZIP integrity (unzip -t) =="
unzip -tq "$ZIP_PATH" || { echo "ERROR: ZIP integrity check failed." >&2; exit 1; }

echo "== Verifying plugin layout (main file at $PLUGIN_BASENAME/) =="
unzip -l "$ZIP_PATH" | grep -q "$PLUGIN_BASENAME/future-island-intelligence-suite.php" \
    || { echo "ERROR: plugin main file missing from archive." >&2; exit 1; }

echo "OK: package built and verified."
echo "ZIP: $ZIP_PATH"
echo "SHA: $SHA_PATH"
exit 0
