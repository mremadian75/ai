# CHANGELOG_deep-trend-finder-addon-v0.1.1-foundation-hardening

## Changed

- Bumped add-on version to `0.1.1-foundation-hardening`.
- Added run ownership helpers and enforced run access on GET run/report/items.
- Kept ingest admin-only and added strict source job/run/source matching.
- Made report generation idempotent through `build_or_get_report()` and `get_latest_report()`.
- Added admin-only `force=1` report rebuild path.
- Expanded normalization aliases for TikTok/Instagram/Reddit/Google-like metric keys.
- Added canonical evidence-quality booleans for video/audio/transcript reality.
- Added relevance tier, recommended use, and signal type fields.
- Store only relevant, deduplicated items as frontend evidence.
- Added paginated `GET /runs/{id}/items` endpoint for Show More evidence loading.
- Hid provider mechanics from normal job responses while keeping admin diagnostics available.
- Added v0.1.1 regression test suite.

## Not changed

- No live provider calls were added.
- No Apify/Scraptik/Bright Data integration was added.
- No OpenAI/AI web research call was added.
- No deep video/audio/transcript worker was added.
- No mother plugin files were modified.
