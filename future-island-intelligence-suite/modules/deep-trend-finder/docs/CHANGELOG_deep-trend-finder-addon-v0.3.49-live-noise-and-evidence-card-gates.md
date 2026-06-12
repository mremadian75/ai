# v0.3.49 — Live noise and evidence-card gate hardening

Live QA exposed a severe TikTok search weakness: queries like `Mahou San Isidro cerveza` can return high-engagement posts from unrelated countries and unrelated topics. Some rows can also match via author handles or provider-query provenance rather than post content.

## Fixes
- Excluded TikTok / Instagram / Reddit / Google News author names from semantic relevance matching. Author names remain display metadata only.
- Replaced loose substring matching with boundary-aware matching to avoid false positives such as `san` inside unrelated words or `beer` inside handle fragments.
- Removed multi-word token-anywhere matching from the base `contains()` helper to avoid false direct matches like `brand strategy` when the terms appear separately in irrelevant contexts.
- Added a REST evidence-card gate so `noise` and `recommended_use=hide` rows do not load as visible evidence cards.
- Corrected source `relevant_count` so hidden/noise rows do not inflate source coverage.
- Updated report schema to `fidtf.report.v3.6`.

## Live QA pattern covered
- TikTok returned unrelated high-view rows from ZM/TZ/LY/NE/ZA for `Mahou San Isidro cerveza`.
- Google Search returned valid Spain/Madrid/Mahou/San Isidro demand context, confirming that social and search source roles must remain separate.
