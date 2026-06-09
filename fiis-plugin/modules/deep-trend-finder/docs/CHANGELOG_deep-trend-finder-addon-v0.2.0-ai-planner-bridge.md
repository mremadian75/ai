# CHANGELOG_deep-trend-finder-addon-v0.2.0-ai-planner-bridge

## Added

- Added explicit `enable_ai_planner_bridge` setting, disabled by default.
- Added `FIDTF_Settings::ai_planner_bridge_enabled()`.
- Added `FIDTF_Settings::planner_model_alias()`.
- Added `includes/class-fidtf-ai-bridge.php`.
- Added safe AI planner payload builder with structured instructions and JSON schema.
- Added compatible mother-plugin planner adapter detection for `plan_deep_trend_finder` methods.
- Added bridge fallback behavior for unavailable clients, invalid JSON/text, and invalid source plans.
- Added planner validation metadata: `validation_error`, `validation_notes`, `model_label`, and admin-only `raw_text`/`bridge_diagnostic` response fields.
- Added v0.2.0 regression tests.

## Changed

- `FIDTF_AI_Planner::build_plan()` no longer calls `fidtf_ai_planner_result` unless the planner bridge setting is enabled.
- Admin copy now explains the AI planner bridge cost boundary.
- README and architecture docs now describe v0.2.0 planner behavior.
- No-evidence report copy now references the AI planner bridge release.

## Not changed

- No live scraping was added.
- No provider API calls were added.
- No AI web research was added.
- No final synthesis bridge was added.
- No deep video/audio/transcript worker was added.
- The mother plugin was not modified.
