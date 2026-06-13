# CHANGELOG_deep-trend-finder-addon-v0.1.4-bridge-safety

## Added

- Added `enable_ai_research_bridge`, disabled by default.
- Added central run allowed status model and `FIDTF_Run_Service::sanitize_status()`.
- Added central source-job allowed status model and `FIDTF_Source_Job_Service::sanitize_status()`.
- Added `FIDTF_Credit_Service::credit_mode()`.
- Added `credit_mode`, `credits_reserved`, and `final_credits_settled` to run/report responses.
- Added planning-only credit copy in the frontend and report template.
- Added v0.1.4 bridge-safety regression tests.

## Changed

- Provider dispatch hooks now run only after live dispatch is enabled.
- AI research hooks now run only after live dispatch and AI bridge are enabled.
- Unknown run/source statuses are clamped before storage and response output.
- Admin copy now describes v0.1.4 bridge safety instead of older foundation hardening.
- Admin page now shows explicit warnings for provider costs, deep-video hard flag, and credit reservation state.
- README now describes v0.1.4 behavior.

## Not changed

- No live scraping was added.
- No provider API calls were added.
- No AI web research was added.
- No deep video/audio/transcript worker was added.
- No mother plugin files were changed.
