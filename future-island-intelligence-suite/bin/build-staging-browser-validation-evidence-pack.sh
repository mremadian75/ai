#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${FI_STAGING_BROWSER_EVIDENCE_PACK:-${ROOT_DIR}/staging-browser-validation-evidence-pack.zip}"
TMP="${ROOT_DIR}/.staging-browser-validation-evidence-pack"
rm -rf "${TMP}"
mkdir -p "${TMP}/logs" "${TMP}/reports" "${TMP}/integrations" "${TMP}/ui-snippets" "${TMP}/fixtures"
copy_if_exists() {
  local src="$1" dest="$2"
  if [ -e "${src}" ]; then cp -R "${src}" "${dest}"; fi
}
for f in test-all-timeout-safe.log provider-fixture-trial.log; do copy_if_exists "${ROOT_DIR}/${f}" "${TMP}/logs/"; done
copy_if_exists "${ROOT_DIR}/tests/fixtures/provider" "${TMP}/fixtures/"
copy_if_exists "${ROOT_DIR}/ui-snippets" "${TMP}/ui-snippets/"
copy_if_exists "${ROOT_DIR}/SCREENSHOTS_OR_HTML_SNIPPETS_BUNDLE.zip" "${TMP}/ui-snippets/"
# Optional integration examples are collected only if present; n8n is never required for pack success.
copy_if_exists "${ROOT_DIR}/integrations/examples" "${TMP}/integrations/"
for f in \
  STAGING_BROWSER_VALIDATION_SPRINT_REPORT.md \
  WARNING_CLEANUP_REPORT.md \
  PLUGIN_ACTIVATION_AND_MIGRATION_READINESS.md \
  UI_IMPLEMENTATION_REPORT.md \
  COMMAND_ROOM_BROWSER_VALIDATION_GUIDE.md \
  SIGNED_CALLBACK_SIMULATOR_BROWSER_TRIAL.md \
  PROVIDER_FIXTURE_STAGING_TRIAL_REPORT.md \
  OPERATOR_QA_BROWSER_PLAYBOOK.md \
  PRIVATE_BETA_SECURITY_REVIEW_UPDATED.md \
  PILOT_READINESS_REPORT.md \
  ORCHESTRATOR_AGNOSTIC_CALLBACK_GUIDE.md \
  OPTIONAL_N8N_WORKFLOW_PACK_NOTES.md; do
  copy_if_exists "${ROOT_DIR}/${f}" "${TMP}/reports/"
done
{
  echo "Future Island Staging Browser Validation Evidence Pack"
  echo "Generated at: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Canonical path: approved worker/simulator/optional orchestrator -> signed WordPress REST callback -> validation -> canonical records."
  echo "n8n status: optional example only; evidence pack success does not require n8n assets."
  echo
  echo "Included files:"
  find "${TMP}" -type f | sed "s#${TMP}/#- #" | sort
} > "${TMP}/MANIFEST.md"
if command -v sha256sum >/dev/null 2>&1; then
  find "${TMP}" -type f -print0 | sort -z | xargs -0 sha256sum > "${TMP}/SHA256SUMS.txt"
fi
rm -f "${OUT}"
( cd "${TMP}" && zip -qr "${OUT}" . )
echo "Staging browser validation evidence pack: ${OUT}"
