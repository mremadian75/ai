# MANUAL_QA_deep-trend-finder-addon-v0.1.1

## Install

1. Confirm the mother plugin `future-island-v0.9.31.8-p3.1-live-qa-hotfix` or compatible core is active.
2. Upload and activate `future-island-deep-trend-finder-addon-v0.1.1-foundation-hardening.zip`.
3. Add shortcode `[future_island_deep_trend_finder]` to a private test page.

## Expected frontend behavior

1. Create a run with brief, market, keywords, and selected sources.
2. The UI should create a run and source rows without making live provider calls by default.
3. Normal users should not see provider actor IDs or internal query payloads.
4. Clicking Load report twice should return the same report snapshot, not create duplicate reports.
5. Clicking Show evidence before ingest should show no relevant evidence yet, not a fabricated report.

## Admin ingest QA

Use REST as an admin only:

```json
{
  "source_key": "tiktok",
  "source_job_id": 123,
  "items": [
    {
      "id": "abc123",
      "url": "https://example.com/abc123",
      "desc": "Verano silencioso en Madrid para turismo cultural",
      "playCount": 120000,
      "diggCount": 8000,
      "collectCount": 300,
      "commentCount": 120
    }
  ]
}
```

Expected:

- `source_job_id` must belong to the run.
- `source_key` must match the job.
- Relevant count should increase only for matching evidence.
- Duplicate IDs/URLs should not create duplicate evidence cards in the same ingest batch.

## Security QA

- User A cannot open User B's run unless both resolve to the same workspace.
- Admin can open any run.
- Non-admin cannot use `force=1` report rebuild.
- Non-admin cannot ingest provider results.
- Manual `source_job_id=0` ingest is admin-only.

## Truthfulness QA

Report and evidence quality must explicitly avoid claiming:

- downloaded video file when no downloaded path exists,
- frame extraction,
- audio extraction,
- transcript/subtitles,
- live scraping,
- AI web research,
- sequence-by-sequence video analysis.
