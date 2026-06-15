#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${FI_STAGING_EVIDENCE_PACK:-${ROOT_DIR}/staging-trial-evidence-pack.zip}"
TMP="${ROOT_DIR}/.staging-trial-evidence-pack"
rm -rf "${TMP}"
mkdir -p "${TMP}/logs" "${TMP}/integrations" "${TMP}/reports"
copy_if_exists() {
  local src="$1" dest="$2"
  if [ -e "${src}" ]; then cp -R "${src}" "${dest}"; fi
}
copy_if_exists "${ROOT_DIR}/test-all-timeout-safe.log" "${TMP}/logs/"
copy_if_exists "${ROOT_DIR}/provider-fixture-trial.log" "${TMP}/logs/"
copy_if_exists "${ROOT_DIR}/integrations/examples" "${TMP}/integrations/"
for f in API_ENDPOINTS_STAGING_TRIAL.md MIGRATION_NOTES_STAGING_TRIAL.md UPDATED_OPERATOR_QA_PLAYBOOK.md UPDATED_STAGING_VALIDATION_GUIDE.md UPDATED_RELEASE_EVIDENCE_PACK_NOTES.md ORCHESTRATOR_AGNOSTIC_CALLBACK_GUIDE.md ORCHESTRATION_AGNOSTIC_CORRECTION_REPORT.md PRIVATE_BETA_SECURITY_REVIEW.md; do
  copy_if_exists "${ROOT_DIR}/${f}" "${TMP}/reports/"
done
{
  echo "Future Island Orchestration-Agnostic Staging Trial Evidence Pack"
  echo "Generated at: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Included files (optional n8n example is collected only when present):"
  find "${TMP}" -type f | sed "s#${TMP}/#- #" | sort
} > "${TMP}/MANIFEST.md"
if command -v sha256sum >/dev/null 2>&1; then
  find "${TMP}" -type f -print0 | sort -z | xargs -0 sha256sum > "${TMP}/SHA256SUMS.txt"
fi
rm -f "${OUT}"
( cd "${TMP}" && zip -qr "${OUT}" . )
echo "Staging trial evidence pack: ${OUT}"
