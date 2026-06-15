#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${FI_PROVIDER_TRIAL_LOG:-${ROOT_DIR}/provider-fixture-trial.log}"
ENDPOINT="${FI_PROVIDER_ENDPOINT:-https://staging.example.com/wp-json/fi/v1/provider/social/ingest}"
RUN_ID="${FI_PROVIDER_RUN_ID:-123}"
PROVIDER_KEY="${FI_PROVIDER_KEY:-}"
SECRET_ENV="${FI_PROVIDER_SECRET_ENV:-FI_PROVIDER_SECRET}"
DRY_FLAG="--dry-run"
if [ "${FI_PROVIDER_SEND:-0}" = "1" ]; then DRY_FLAG="--send"; fi
if [ -z "${!SECRET_ENV:-}" ]; then export "${SECRET_ENV}=fi_trial_secret_redacted_placeholder"; fi
: > "${LOG_FILE}"
echo "Future Island provider fixture trial runner" | tee -a "${LOG_FILE}"
echo "Mode: $([ "${DRY_FLAG}" = "--dry-run" ] && echo dry-run || echo send)" | tee -a "${LOG_FILE}"
run_fixture() {
  local family="$1" route="$2" fixture="$3" mode_extra="${4:-}"
  local endpoint="${ENDPOINT}"
  case "${route}" in
    social) endpoint="${FI_PROVIDER_SOCIAL_ENDPOINT:-${ENDPOINT}}" ;;
    aeo) endpoint="${FI_PROVIDER_AEO_ENDPOINT:-https://staging.example.com/wp-json/fi/v1/provider/aeo/ingest}" ;;
    creative) endpoint="${FI_PROVIDER_CREATIVE_ENDPOINT:-https://staging.example.com/wp-json/fi/v1/provider/creative/ingest}" ;;
  esac
  local provider_key="${PROVIDER_KEY}"
  if [ -z "${provider_key}" ]; then
    case "${family}" in
      social_signal) provider_key="${FI_PROVIDER_SOCIAL_KEY:-approved_social_provider}" ;;
      aeo_answer) provider_key="${FI_PROVIDER_AEO_KEY:-approved_aeo_capture}" ;;
      creative_asset_analysis) provider_key="${FI_PROVIDER_CREATIVE_KEY:-manual_creative_analysis}" ;;
      *) provider_key="manual_provider_row" ;;
    esac
  fi
  echo "--- ${fixture} (${family} / ${provider_key}) ---" | tee -a "${LOG_FILE}"
  php "${ROOT_DIR}/bin/fi-provider-callback-simulate.php" \
    --endpoint="${endpoint}" \
    --fixture="${ROOT_DIR}/${fixture}" \
    --provider-family="${family}" \
    --provider-key="${provider_key}" \
    --secret-env="${SECRET_ENV}" \
    --run-id="${RUN_ID}" \
    --idempotency-key="fi-trial-${route}-$(basename "${fixture}" .json)" \
    ${DRY_FLAG} ${mode_extra} 2>&1 | sed -E 's/(sha256=)[a-f0-9]{64}/\1[redacted]/Ig; s/(fi_[A-Za-z0-9_\-]{8,})/[redacted-secret]/g' | tee -a "${LOG_FILE}"
}
run_fixture social_signal social tests/fixtures/provider/social-valid-topic.json
run_fixture social_signal social tests/fixtures/provider/social-invalid-private.json
run_fixture social_signal social tests/fixtures/provider/social-mixed-batch.json
run_fixture aeo_answer aeo tests/fixtures/provider/aeo-valid-gap.json
run_fixture aeo_answer aeo tests/fixtures/provider/aeo-missing-answer.json
run_fixture creative_asset_analysis creative tests/fixtures/provider/creative-valid-analysis.json
run_fixture creative_asset_analysis creative tests/fixtures/provider/creative-invalid-token-leak.json
echo "Provider fixture trials complete. Log: ${LOG_FILE}" | tee -a "${LOG_FILE}"
