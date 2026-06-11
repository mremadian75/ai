# v0.3.39 — Full live actors + OpenAI network diagnostics + secure smoke test

## Added

- Admin-only OpenAI smoke-test route: `/future-island-dtf/v1/admin/openai-smoke-test`.
- `FIDTF_AI_Bridge::openai_runtime_status()` for safe runtime diagnostics.
- `FIDTF_AI_Bridge::run_openai_smoke_test()` for secure WordPress-side live OpenAI checks.
- OpenAI runtime status inside the existing preflight response.
- Regression test for safe OpenAI diagnostics and no raw key exposure.

## Hardened

- OpenAI/ChatGPT live testing now has a controlled server-side path rather than relying on pasted keys in UI/debug output.
- Provider diagnostics remain redacted.
- Failed OpenAI transport, DNS, invalid JSON, provider error, incomplete status, refusal, and unparsed model output are separated into safe statuses.

## Live QA note

All Apify actor families were live-tested in low-limit runs. OpenAI live HTTP execution from this sandbox was blocked by DNS/network resolution, so the plugin now exposes a secure server-side smoke test for WordPress runtime validation.
