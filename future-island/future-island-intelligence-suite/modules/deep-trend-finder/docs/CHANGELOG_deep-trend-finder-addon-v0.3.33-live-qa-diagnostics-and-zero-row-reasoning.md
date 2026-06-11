# Deep Trend Finder Add-on v0.3.33

## Live QA diagnostics and zero-row reasoning

This release follows live Apify checks across the active Trend Finder actors after v0.3.32.

### Live QA observations

- TikTok / Clockworks: live run succeeded after the v0.3.31 enum fixes.
- Instagram / official Apify scraper: direct hashtag URL mode was live-confirmed and returned real post rows.
- Reddit / Trudax Lite: live run succeeded and returned clean post rows.
- Google News / Data Xplorer: live run succeeded and returned article rows.
- Google Trends / Data Xplorer: keyword mode can take longer than the immediate wait window and must stay in queued/running state until refresh completes. The actor must keep `enableTrendingSearches=false` for keyword analysis.

### Fixes

- Generic Apify adapter now differentiates zero outcomes more precisely:
  - `zero_dataset_items`: provider dataset was empty.
  - `flattened_empty`: provider returned rows, but adapter flattening produced no usable evidence rows.
  - `normalized_empty`: flattened rows existed, but no usable normalized evidence survived downstream.
- Running generic provider responses now expose:
  - `provider_dataset_rows`
  - `provider_dataset_total_rows`
  - `flattened_raw_items`
  - `discovery_dataset_id`
- Completed generic provider responses now expose `discovery_dataset_id` when available.
- Google Trends keyword output detection now supports additional live shapes:
  - `interest_over_time`
  - `related_queries`
  - `relatedQueries`
- Google Trends normalizer now derives `trend_interest` from `interest_over_time` as well as existing timeline aliases.

### Why this matters

The previous live QA proved that some actors can succeed while returning non-standard metadata, delayed datasets, or shapes that are not final post/article evidence. v0.3.33 makes the failure reason visible instead of collapsing everything into a generic zero-result state.

### QA

- PHP lint: passed.
- JS syntax check: passed.
- PHP test files: passed.
- Cumulative regression chain: passed through v0.3.33.
