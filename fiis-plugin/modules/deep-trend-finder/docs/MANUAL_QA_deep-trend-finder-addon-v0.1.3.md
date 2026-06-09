# MANUAL_QA_deep-trend-finder-addon-v0.1.3

## Install

1. Install and activate the current Future Island mother plugin.
2. Upload and activate `future-island-deep-trend-finder-addon-v0.1.3-async-readiness.zip`.
3. Confirm no activation fatal error occurs.
4. Confirm the add-on settings remain accessible in wp-admin.

## Shortcode UI

1. Add `[future_island_deep_trend_finder]` to a protected/member page.
2. Confirm the form shows:
   - brief
   - objective
   - market
   - language
   - date range including custom
   - keywords
   - brand
   - company
   - competitors
   - audience
   - exclude terms
   - source checkboxes
3. Submit a run with provider bridge disabled.
4. Confirm the UI says the provider bridge is not enabled and the run is waiting for source integration.
5. Confirm source rows show status, limit, raw count, normalized count, relevant count, and any error code.
6. Wait 15 seconds and confirm polling stops; it must not keep spinning forever.

## Report behavior

1. Click Load report on a no-evidence run.
2. Confirm the report says:
   - This is a planned run, not an evidence report yet.
   - No live scraping has been executed.
   - No AI web research has been executed.
3. Confirm no fake insight, chart, stat, screenshot, or AI conclusion appears.

## Show evidence

1. Click Show evidence on a no-evidence run.
2. Confirm it shows no relevant evidence has been ingested yet.
3. Confirm the button does not break the run card.
4. Confirm Show More uses 20 items per request once evidence exists.

## Admin ingest smoke test

1. As admin, call the ingest endpoint with a valid `run_id`, `source_job_id`, `source_key`, and sample items.
2. Confirm wrong `source_job_id` is rejected.
3. Confirm mismatched `source_key` is rejected.
4. Confirm relevant items are normalized, deduped, and stored.
5. Confirm video URL alone does not set `video_file=true`.
6. Confirm evidence quality limitations mention no transcript, no audio analysis, and no frame-by-frame visual analysis when those fields are missing.

## Regression checks

Run:

```bash
php -l $(find . -name '*.php')
node --check assets/js/fidtf-frontend.js
php tests/test-fidtf-v010.php
php tests/test-fidtf-v011-foundation-hardening.php
php tests/test-fidtf-v012-foundation-corrections.php
php tests/test-fidtf-v013-async-readiness.php
```

Expected: all checks pass.

## Out of scope

- Live scraping.
- Real provider execution.
- Real AI planner.
- AI web research.
- Deep video/audio/transcript processing.
- Sidecar workers.
