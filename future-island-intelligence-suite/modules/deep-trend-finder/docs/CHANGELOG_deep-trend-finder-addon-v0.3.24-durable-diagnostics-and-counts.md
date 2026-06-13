# Deep Trend Finder Add-on v0.3.24 — Durable diagnostics and provider-count persistence

Focused hardening patch after live Apify debugging showed the provider run and dataset were correct, but diagnostic/count visibility could still depend on short-lived WordPress transients.

## What changed

- Added durable source-job fields for TikTok provider diagnostics:
  - `provider_dataset_rows`
  - `provider_dataset_total_rows`
  - `flattened_raw_items`
  - `diagnostics_json`
- Persisted sanitized dispatch diagnostics to the source job row, not only to a transient.
- REST job responses now keep provider count context even after object-cache eviction or transient expiry.
- Source-job writes are filtered against real table columns before DB update/insert, so older installs remain safe during upgrade.
- Direct Apify mode now preserves actor input context in completion diagnostics.
- Direct Apify mode now fetches dataset items using the requested actor limit instead of falling back to the global default.

## Why this matters

v0.3.19–v0.3.23 fixed the live collection and normalization path. v0.3.24 makes the debugging and UI evidence-count layer durable, so a real Apify run with rows should not later degrade back into an unhelpful zero-count display because transient diagnostics expired.

## QA

- PHP lint: passed.
- Frontend JS syntax check: passed.
- Full add-on regression suite: passed.
- New v0.3.24 regression: 125 / 125 passed.
