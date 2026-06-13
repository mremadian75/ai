# Changelog — v0.2.2-source-job-contract-hardening

## Added

- Canonical source job payload wrapper: `fidtf.source_payload.v1`.
- Structured source job `request_context`, `source_plan`, and `limits` blocks.
- `provider_intent: planned_only` and payload-level `live_dispatch_enabled`.
- REST `plan_summary` for every source job.
- Frontend source-specific source row summaries.
- `local_planning` credit breakdown key.
- Direct v0.2.2 regression test suite.

## Changed

- Provider `build_payload()` methods now return canonical wrappers instead of flat payloads.
- `create_jobs()` stores canonical payloads in `query_payload_json`.
- REST jobs keep backward fields while adding structured summaries.
- Report template separates local planning estimate from AI planner estimate.

## Preserved

- Live dispatch remains disabled by default.
- AI planner bridge remains disabled by default.
- AI research bridge remains disabled by default.
- Deep video remains hard-disabled.
- No mother plugin changes.

## Not added

- No live provider calls.
- No TikTok/Scraptik/Apify execution.
- No AI web research.
- No deep video/audio/transcript processing.
