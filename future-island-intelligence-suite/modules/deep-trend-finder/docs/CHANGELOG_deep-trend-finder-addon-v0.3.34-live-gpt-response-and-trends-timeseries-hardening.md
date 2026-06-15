# Changelog — v0.3.34

## Added
- OpenAI/ChatGPT Responses API shape parsing in the AI planner bridge.
- Chat Completions message-content parsing in the AI planner bridge.
- Markdown-fence stripping and JSON object extraction for model text.
- External final synthesis parsing from OpenAI-shaped response arrays.
- Google Trends nested timeline flattening for Data Xplorer live keyword output.
- Regression test: `test-fidtf-v0334-live-gpt-response-and-trends-timeseries-hardening.php`.
- Live QA document: `docs/LIVE_ACTOR_AND_GPT_QA_v0.3.34.md`.

## Fixed
- Google Trends keyword-mode live rows with `timeline_data.{keyword}.{date}` now derive `trend_interest` correctly.
- Google Trends `isPartial` boolean maps are no longer misread as interest values.
- AI planner bridge no longer rejects valid OpenAI-shaped responses just because the mother client returned raw model output instead of a decoded plan.
- Final synthesis bridge no longer switches to `external_model_bridge` when the external response contains no supported report fields.

## Verified
- PHP lint: passed.
- JS syntax check: passed.
- Full PHP regression suite: passed.
- Cumulative regression: 381 / 381.
