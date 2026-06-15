# Future Island Deep Trend Finder Add-on v0.2.1 — Planner Contract Hardening

## Scope

v0.2.1 hardens the AI planner contract so a future v0.3.0 TikTok provider bridge can receive a source-specific payload without changing the planning layer again.

This release still does not execute live scraping, AI web research, relevance filtering over live source data, final multi-source synthesis, deep video, audio extraction, transcript generation, or sidecar workers.

## Planner gate

`enable_ai_planner_bridge` remains disabled by default. When disabled, `FIDTF_AI_Planner::build_plan()` uses the local fallback planner and does not call `fidtf_ai_planner_result`.

When enabled, the planner bridge may call a configured model adapter, but the result is still validated and sanitized before storage.

## Source-specific planner contract

Validated source plans now preserve these source-specific fields:

- TikTok: `queries`, `hashtags`, `creator_or_format_hints`, `language_hints`, `market_hints`, `sort_strategy`, `max_items`, `notes`
- Instagram: `queries`, `hashtags`, `profile_or_topic_hints`, `language_hints`, `market_hints`, `sort_strategy`, `max_items`, `notes`
- Reddit: `queries`, `subreddits`, `time_range`, `language_hints`, `market_hints`, `sort_strategy`, `max_items`, `notes`
- Google Trends: `seed_keywords`, `related_query_hints`, `geo`, `time_range`, `language_hints`, `market_hints`, `sort_strategy`, `max_items`, `notes`
- Google News: `queries`, `publisher_or_topic_hints`, `geo`, `language`, `time_range`, `sort_strategy`, `max_items`, `notes`
- AI Research: `research_questions`, `assumptions_to_test`, `market_questions`, `competitor_questions`, `sort_strategy`, `max_items`, `notes`

Unknown or unselected sources are removed. `max_items` is clamped through `FIDTF_Settings::source_limit()`. List fields are capped and HTML is stripped.

## Provider payload readiness

`FIDTF_Provider_Apify::build_payload()` and `FIDTF_Provider_AI::build_payload()` now preserve source-specific fields inside `query_payload_json`. No live provider call is added. This only prepares payload contracts for a later bridge.

## REST/UI diagnostics

REST plan responses expose safe planner diagnostics:

- `planner_mode`
- `planner_notice`
- `validation_error`
- `validation_notes`
- `model_label`
- source-specific source plans

Admin-only diagnostics remain admin-only:

- `raw_text`
- `bridge_diagnostic`

The frontend renders planner mode, planner notice, model label, validation state, source strategy, planned queries/hints, hashtags, and limits.

## Credit estimate behavior

Credit estimate now includes `ai_planner` in the breakdown.

- AI planner bridge disabled: `ai_planner = 0`
- AI planner bridge enabled: planner estimate is included
- If credit reservation is disabled, `credit_mode` remains `planning_only`
- No final settlement is introduced in this release

Reports include `credit_breakdown`, including the planner credit estimate.

## Next version

Recommended next version: `v0.3.0-tiktok-provider-bridge`.

The next version should integrate only one live source first: TikTok. It should keep dispatch async and provider-gated, and should return provider results through the existing ingest/normalization path.
