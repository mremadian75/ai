# FIDTF v0.3.40 — Full live actor QA + OpenAI smoke-test hardening

## Live actor QA observed

- TikTok / Clockworks: SUCCEEDED with real rows. `cleanItemCount` can still be `0` while `itemCount` is positive; raw dataset fetching remains required.
- Instagram / Apify official: SUCCEEDED with direct hashtag URL mode and real post rows.
- Reddit / Trudax Lite: SUCCEEDED but can return adjacent/off-topic high-engagement rows; relevance gate must stay strict.
- Google Trends / Data Xplorer: keyword mode can be long-running; polling must not close the job as zero result before terminal state.
- Google News / Data Xplorer: SUCCEEDED with Spain locale article rows.

## OpenAI live-test status

The plugin now exposes an admin-only smoke test endpoint that uses Responses API with JSON-schema output. It requires a server-side API key from constant/env/filter. Request-body API keys are ignored and redacted so leaked chat keys cannot accidentally be used or returned by diagnostics.

## Patch focus

- Responses API JSON-schema request shape.
- `store=false` for smoke-test calls.
- safe runtime key-source diagnostics.
- request-body API key ignored.
- stronger output parsing and secret redaction.
