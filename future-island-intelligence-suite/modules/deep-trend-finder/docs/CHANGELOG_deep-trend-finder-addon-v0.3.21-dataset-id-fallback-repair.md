# Deep Trend Finder Add-on v0.3.21

Focused production debug patch for TikTok Apify runs that show completed datasets in Apify but still surface as zero evidence in WordPress.

## Fixes

- Added a dataset-id fallback for Core Apify client refreshes.
  - If `/actor-runs/{runId}/dataset/items` returns empty after a `SUCCEEDED` run, the add-on now reads `defaultDatasetId` from the run metadata and fetches `/datasets/{datasetId}/items` directly.
- Removed the one-shot zero-count repair blocker.
  - Old falsely-finalized jobs can now be safely repaired on later report/items/run loads instead of staying stuck at `Raw 0 / Normalized 0` until a transient expires.
- Standardized direct dataset fetch query args with `format=json&clean=true&limit=...`.
- Preserved v0.3.19 and v0.3.20 fixes:
  - `READY` is not terminal.
  - `SUCCEEDED` is the only successful terminal Apify state.
  - Apidojo flat/display-label TikTok rows normalize correctly.

## Why

Live Apify evidence showed the production run `DU5Ktv41hz9GUSZuP` succeeded with 110 dataset rows. The remaining failure path was not actor execution; it was WordPress-side result recovery/reading.

## QA

- PHP lint: passed for all PHP files.
- Full add-on test suite: passed.
- New regression test: `test-fidtf-v0321-dataset-id-fallback-repair.php`.
