# MANUAL_QA_deep-trend-finder-addon-v0.1.2

## Setup

1. Confirm the Future Island mother plugin is active.
2. Upload and activate `future-island-deep-trend-finder-addon-v0.1.2-foundation-corrections.zip`.
3. Add shortcode `[future_island_deep_trend_finder]` to a private test page.
4. Keep live dispatch disabled.
5. Keep deep video disabled.

## Frontend checks

1. Open the shortcode page as a permitted user.
2. Confirm the form includes:
   - research brief
   - objective
   - market
   - language
   - date range
   - keywords
   - brand/company
   - competitors
   - audience
   - exclude terms
   - source checkboxes
3. Submit a run.
4. Confirm source cards render for selected sources.
5. Confirm each source card shows status, planned queries, limit, raw count, normalized count, relevant count, and waiting-for-provider messaging.
6. Confirm the UI says provider bridge is not enabled when live dispatch is off.
7. Confirm no provider actor IDs are shown to normal users.
8. Click Load report.
9. Confirm a no-evidence run says: `This is a planned run, not an evidence report yet.`
10. Click Show evidence.
11. Confirm empty runs do not show fake evidence.

## Admin ingest check

1. Create a run.
2. Use the admin-only ingest endpoint with a source job that belongs to the run.
3. Ingest source rows with metric values like `1.4K`, `2,786`, and `1.2M`.
4. Confirm stored items normalize metrics into canonical keys.
5. Confirm video URLs do not set `video_file=true`.
6. Confirm report changes from planned/no-evidence to partial evidence only after relevant evidence exists.

## Security checks

1. As user A, create a run.
2. As user B in a different workspace, try to read that run, report, and items.
3. Confirm access is denied.
4. As admin, confirm access is allowed.
5. Try ingesting with a `source_job_id` from another run.
6. Confirm the request is rejected.
7. Try ingesting with mismatched `source_key` and source job.
8. Confirm the request is rejected.

## Regression checks

Run locally:

```bash
php -l $(find . -name '*.php')
node --check assets/js/fidtf-frontend.js
php tests/test-fidtf-v010.php
php tests/test-fidtf-v011-foundation-hardening.php
php tests/test-fidtf-v012-foundation-corrections.php
```

Expected:

- PHP lint passes.
- JS syntax check passes.
- v0.1.0 tests pass.
- v0.1.1 tests pass.
- v0.1.2 tests pass.

## Known limitations

- Live provider dispatch is still disabled by default.
- No live scraping is included.
- No deep video/audio/frame/transcript processing is included.
- Provider bridges are planned for the next phase.
