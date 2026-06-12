# Changelog — v0.3.40

- Repeated live actor QA for TikTok, Instagram, Reddit, Google Trends, and Google News.
- Hardened the OpenAI admin smoke-test request to use Responses API JSON-schema output.
- Added `store=false` to the smoke-test request payload.
- Added safe OpenAI key-source diagnostics without exposing raw credentials.
- Explicitly ignores request-body API keys and redacts key-like strings in diagnostics.
- Added regression coverage for v0.3.40 OpenAI JSON-schema smoke-test hardening.
