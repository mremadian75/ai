# Deep Trend Finder v0.3.38 live actor + GPT QA

## Scope
Focused hardening after repeated live QA of all Trend Finder actors and GPT/OpenAI integration shape.

## Live actor results observed

- TikTok / Clockworks: live run succeeded with real rows. Current output uses `text`, `webVideoUrl`, `playCount`, `diggCount`, `commentCount`, `shareCount`, `collectCount`, `searchQuery`, `authorMeta.*`, `videoMeta.coverUrl` and often has `cleanItemCount=0`, so raw dataset fetch must remain `clean=false`.
- Instagram / Apify official: live direct hashtag URL mode succeeded with real post rows. Search mode is not used for post evidence because it can return hashtag-directory rows.
- Reddit / Trudax Lite: live run succeeded with real post rows, but Reddit can return high-engagement adjacent/off-topic rows. Relevance scoring remains the quality gate.
- Google News / Data Xplorer: live run succeeded with article rows from Spain locale.
- Google Trends / Data Xplorer: live keyword mode can start as RUNNING and later succeed. Final output uses nested `timeline_data.{keyword}.{date}` maps.

## GPT/OpenAI status

A secure runtime API key was not available inside the test environment. A key pasted in chat must be treated as compromised and was not used. The plugin was therefore hardened with live-style mocked Responses API and Chat Completions payloads, including structured/parsed payloads and error/refusal/incomplete states.

## v0.3.38 fixes

- Adds source-specific polling guidance for non-terminal Apify runs, especially Google Trends.
- Adds Google Trends summary metrics from nested `timeline_data`:
  - `trend_peak`
  - `trend_latest`
  - `trend_average`
  - `trend_delta`
  - `trend_points`
  - `trend_direction`
- Hardens multi-word query relevance so provider-query provenance alone cannot elevate weak evidence.
- Adds regression coverage for Google Trends nested timeline extraction and weak phrase matching.

## Local QA

- PHP lint passed.
- JS syntax check passed.
- 43 PHP test files passed.
- Cumulative regression reached 449 / 449 checks.
