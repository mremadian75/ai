# ADDON_ARCHITECTURE_v0.2.0

Version: `0.2.0-ai-planner-bridge`

## Scope

v0.2.0 only implements the AI Planner Bridge boundary. It does not implement live scraping, AI web research, relevance batching on real provider data, final model synthesis, or deep video/audio/transcript processing.

The product behavior remains planning-first and evidence-safe: the system may generate a structured source plan, but it must not claim that source data was collected until provider results are actually ingested.

## Main layers

1. **Frontend shortcode** collects brief, objective, market, language, date range, brand/company, competitors, audience, exclude terms, keywords, and selected channels.
2. **Run service** sanitizes the request, asks the planner for a source plan, estimates credits, creates the run, creates source jobs, and attempts dispatch only through gated bridges.
3. **AI planner** chooses either local fallback or external model bridge.
4. **AI bridge adapter** detects compatible mother-plugin planner clients without hardcoding credentials or model names.
5. **Source jobs** remain planned/waiting unless live provider dispatch is explicitly enabled.
6. **Reports** remain honest no-evidence reports until relevant evidence rows are ingested.

## AI Planner Bridge hard gate

New setting:

```php
enable_ai_planner_bridge = false
```

New helper:

```php
FIDTF_Settings::ai_planner_bridge_enabled(): bool
```

Rules:

- When disabled, `fidtf_ai_planner_result` is not called.
- When disabled, planner mode is `local_fallback`.
- When enabled, `fidtf_ai_planner_result` may run and may call configured model APIs.
- If the bridge result validates, planner mode is `external_model_bridge`.
- If the bridge result is unavailable, invalid, text-only, or malformed, planner mode is `local_fallback_after_invalid_ai`.
- The bridge never creates a fake model label.
- Raw invalid AI text is only preserved for admin/debug inspection.

## AI bridge adapter

New file:

```text
includes/class-fidtf-ai-bridge.php
```

Responsibilities:

- Register a safe default handler for `fidtf_ai_planner_result`.
- Build a structured planner payload with instructions and JSON schema.
- Detect compatible mother-plugin planner methods, such as `plan_deep_trend_finder`.
- Return `WP_Error('ai_planner_unavailable')` when no compatible configured planner client exists.
- Normalize valid wrapper output shaped as `{ ok, model_label, plan, raw_text, validation_notes }`.
- Avoid hardcoded API keys, model labels, and direct OpenAI/Gemini/Claude HTTP calls.

## Planner prompt contract

The planner prompt asks the model to produce a source-specific query plan only. It explicitly forbids claims that scraping, web research, trend validation, video analysis, audio analysis, or final synthesis has happened.

Required output schema:

```json
{
  "schema_version": "fidtf.plan.v1",
  "brief_summary": "",
  "model_label": "",
  "validation_notes": [],
  "limitations": [],
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
  "sources": {
    "source_key": {
      "source_key": "",
      "queries": [],
      "hashtags": [],
      "research_questions": [],
      "language_hints": [],
      "market_hints": [],
      "max_items": 0,
      "sort_strategy": "",
      "notes": ""
    }
  }
}
```

## External planner validation

`FIDTF_AI_Planner::validate_plan()` sanitizes and validates:

- source keys
- selected/enabled source compatibility
- queries
- hashtags
- research questions
- language and market hints
- source limits
- request context
- model label if actually provided
- validation notes
- admin/debug raw text

Invalid source plans do not fatal. They fall back to local planning.

## Existing safety preserved

v0.2.0 preserves v0.1.4 behavior:

- Live source dispatch is still disabled by default.
- Provider hooks do not run when live dispatch is disabled.
- AI research bridge remains separately gated.
- Source/run statuses remain centralized and clamped.
- `credit_mode` distinguishes `planning_only`, `reserved`, and `settled`.
- Normal users do not see provider actor IDs.
- Deep video remains hard-disabled unless an external worker and hard feature flag exist.

## What is not implemented

- Live Apify/Scraptik/Bright Data calls
- Instagram/Reddit/Google Trends/Google News execution
- AI web research
- AI relevance filtering over real provider data
- Final AI synthesis
- Video/audio/transcript worker
- Sidecar queue runtime

## Next recommended version

`v0.2.1-ai-planner-runtime-adapter` or `v0.3.0-provider-bridge-contract`.

The safer next step is to connect one compatible mother-plugin model method to `FIDTF_AI_Bridge` in a controlled way, then only after that connect a provider bridge for one source.
