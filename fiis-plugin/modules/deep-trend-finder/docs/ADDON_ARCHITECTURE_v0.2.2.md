# Future Island Deep Trend Finder Add-on v0.2.2 Architecture

## Purpose

v0.2.2 is source job contract hardening. It prepares Deep Trend Finder for a later TikTok provider bridge without starting live scraping or creating fake evidence.

## Canonical source job payload

Every source job now stores `query_payload_json` using this wrapper:

```json
{
  "source_key": "tiktok",
  "schema_version": "fidtf.source_payload.v1",
  "planner_mode": "local_fallback|external_model_bridge|local_fallback_after_invalid_ai",
  "request_context": {
    "user_brief": "",
    "objective": "",
    "market": "",
    "language": "",
    "date_range": "",
    "keywords": [],
    "brand": "",
    "company": "",
    "competitors": [],
    "audience": "",
    "exclude_terms": [],
    "channels": []
  },
  "source_plan": {},
  "limits": {
    "max_items": 50,
    "admin_limit": 50
  },
  "provider_intent": "planned_only",
  "live_dispatch_enabled": false
}
```

`source_plan`, `request_context`, and `limits` are the source of truth. Some top-level shortcuts remain only for backward compatibility with v0.2.1 tests and UI logic.

## Provider payload contract

`FIDTF_Provider_Apify::build_payload()` and `FIDTF_Provider_AI::build_payload()` return the canonical wrapper. Dispatch remains gated. When live dispatch is disabled, provider filters are not called.

Expected later provider context:

```json
{
  "source_key": "tiktok",
  "actor_id": "configured-provider-id",
  "payload": {
    "schema_version": "fidtf.source_payload.v1",
    "source_plan": {},
    "request_context": {},
    "limits": {},
    "provider_intent": "planned_only"
  }
}
```

## REST contract

`prepare_jobs_for_response()` adds `plan_summary` for each job:

- TikTok: queries, hashtags, creator/format hints, strategy, limit.
- Instagram: queries, hashtags, profile/topic hints, strategy, limit.
- Reddit: queries, subreddits, time range, strategy, limit.
- Google Trends: seed keywords, related query hints, geo, time range, limit.
- Google News: queries, publisher/topic hints, geo, language, time range.
- AI Research: research questions, assumptions to test, market questions, competitor questions.

Non-admin responses hide provider and actor details. Admin responses may include provider, actor ID, and provider run ID.

## Credit contract

Credit breakdown now prefers:

```json
{
  "local_planning": 1,
  "ai_planner": 0,
  "source_jobs": 0,
  "relevance_batches": 0,
  "final_synthesis": 0,
  "deep_video": 0
}
```

The legacy `planning` key remains for compatibility.

## Explicit non-goals

- No live scraping.
- No TikTok/Apify/Scraptik dispatch.
- No real evidence collection.
- No AI web research execution.
- No deep video/audio/transcript processing.
- No final evidence-backed synthesis from zero evidence.

## Next version

Build `v0.3.0-tiktok-provider-bridge` and connect only TikTok through this contract.
