# Deep Trend Finder Add-on v0.3.22

Focused TikTok live QA hardening patch.

## Why this patch exists

Production showed a real Apify TikTok run with a populated dataset, but the WordPress UI could still remain in a misleading `Raw 0 / Normalized 0 / Relevant 0` state for previously closed false-zero jobs or stale diagnostics.

v0.3.21 fixed the canonical dataset fallback. v0.3.22 improves the surrounding repair and reporting layer so the user does not get trapped by stale backoff, empty legacy payloads, or hidden diagnostic counts.

## Changes

- Added admin-only forced provider refresh support via `force_provider_refresh=1` or `force_refresh=1` on run/report/items REST requests.
- Let false-zero TikTok repair bypass retry backoff while keeping a short lock to avoid hammering Apify.
- Added a minimal canonical repair payload fallback for legacy jobs with a valid `provider_run_id` but missing/empty `query_payload_json`.
- Promoted safe output counts from dispatch diagnostics into the public job response:
  - `provider_dataset_rows`
  - `flattened_raw_items`
  - `normalized_count`
  - `relevant_count`
- Updated frontend state logic to distinguish:
  - true zero provider output
  - provider rows returned but no usable posts extracted
  - normalizer failure
  - relevance-filter rejection
- Added regression test `test-fidtf-v0322-repair-force-and-public-counts.php`.

## Not changed

- No shortcode changes.
- No database schema changes.
- No provider actor change.
- No rewrite of the add-on.
- No direct publishing logic added.
