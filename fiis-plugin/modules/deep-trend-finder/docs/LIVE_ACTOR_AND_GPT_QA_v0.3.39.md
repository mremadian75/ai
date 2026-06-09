# Deep Trend Finder v0.3.39 — Full live actor QA + OpenAI runtime diagnostics

## Live actor QA summary

Live Apify runs were executed for the production actor family used by Deep Trend Finder:

- TikTok / `clockworks/tiktok-scraper`: succeeded with real rows. The dataset confirmed `itemCount > 0` while `cleanItemCount = 0`, so the add-on must keep fetching with `clean=false` and normalize rows internally.
- Instagram / `apify/instagram-scraper`: succeeded with direct hashtag URL mode and real post rows. The previous search mode remains deprecated because it can return hashtag-directory metadata instead of post evidence.
- Reddit / `trudax/reddit-scraper-lite`: succeeded with real post rows. Live QA again confirmed Reddit can return high-engagement but weakly related posts, so provider query provenance must not be treated as semantic relevance.
- Google News / `data_xplorer/google-news-scraper-fast`: succeeded with Spanish-locale article rows.
- Google Trends / `data_xplorer/google-trends-fast-scraper`: succeeded with keyword-mode nested `timeline_data`. Long-running intermediate states must stay retryable/pollable and must never be treated as zero evidence.

## OpenAI / ChatGPT live request status

A live OpenAI HTTP request from this sandbox could not be completed because the container could not resolve `api.openai.com` at runtime. The add-on was updated so WordPress installations can run a secure admin-only OpenAI smoke test from a server runtime that has a server-side key configured.

The smoke test:

- uses `/v1/responses`
- reads keys only from server-side config (`FIDTF_OPENAI_API_KEY`, `OPENAI_API_KEY`, env, or `fidtf_openai_api_key` filter)
- never returns the raw key
- redacts provider diagnostics
- parses Responses API text and JSON output through the same bridge used by planner/report QA

## Files changed

- `future-island-deep-trend-finder-addon.php`
- `includes/class-fidtf-ai-bridge.php`
- `includes/class-fidtf-settings.php`
- `includes/class-fidtf-rest-controller.php`
- `tests/test-fidtf-v0339-full-live-openai-network-diagnostics.php`
- `docs/LIVE_ACTOR_AND_GPT_QA_v0.3.39.md`
- `docs/CHANGELOG_deep-trend-finder-addon-v0.3.39-full-live-actors-openai-network-diagnostics-and-secure-smoke-test.md`
