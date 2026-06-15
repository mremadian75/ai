# Changelog — v0.2.1-planner-contract-hardening

## Added

- Source-specific planner validation for TikTok, Instagram, Reddit, Google Trends, Google News, and AI Research.
- Source-specific planner JSON schema fields in `FIDTF_AI_Bridge::planner_json_schema()`.
- Planner prompt instructions covering request context fields and no fake browsing/scraping guardrails.
- Provider payload preservation for source-specific fields in planned source jobs.
- Frontend planner diagnostics block: planner mode, notice, model label, validation, strategy, planned hints, and hashtags.
- Credit breakdown key: `ai_planner`.
- Report-level `credit_estimate` and `credit_breakdown`.
- Regression test file: `tests/test-fidtf-v021-planner-contract-hardening.php`.

## Changed

- Version bumped to `0.2.1-planner-contract-hardening`.
- REST `create_run` now prepares the plan response before returning it, so raw AI text stays admin-only.
- REST plan responses preserve source-specific fields instead of reducing everything to generic query fields.
- Source job public summaries can include source-specific planning hints.
- v0.1.3/v0.1.4/v0.2.0 regression tests now accept the newer v0.2.1 version marker.

## Not added

- No live TikTok/Scraptik/Apify/Bright Data call.
- No AI web research execution.
- No live-source relevance filtering.
- No final AI synthesis from real multi-source evidence.
- No deep video/audio/transcript processing.
- No sidecar worker.
