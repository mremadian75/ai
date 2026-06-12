# ADDON_ARCHITECTURE_v0.1.2

## Release scope

`v0.1.2-foundation-corrections` is a foundation correction release. It does not add live scraping, real provider calls, a real AI planner, deep video processing, or sidecar workers.

The add-on remains a planning, normalization, ingestion, evidence-quality, and progressive UI foundation for the future provider bridge phase.

## Core boundaries

Real in this release:

- Run creation.
- Source job planning.
- Admin-only source result ingestion.
- Ownership checks for run, report, and items endpoints.
- Idempotent report retrieval.
- Canonical metric normalization.
- Source-safe media schema.
- Evidence quality flags and limitations.
- Relevant-only stored evidence rows.
- Frontend source rows and polling foundation.
- Extended planner input sanitization.

Not real in this release:

- Live Scraptik / Apify / Bright Data calls.
- Live Google Trends / Google News calls.
- Live Reddit / Instagram calls.
- AI planner bridge.
- AI final synthesis bridge.
- Video download.
- Frame extraction.
- Audio extraction.
- Transcript generation.
- Sidecar workers.

## Main files

- `future-island-deep-trend-finder-addon.php` — plugin bootstrap and version.
- `includes/class-fidtf-run-service.php` — request sanitization, run lifecycle, ownership helpers, ingest entrypoint.
- `includes/class-fidtf-ai-planner.php` — local fallback planner and request context preservation.
- `includes/class-fidtf-source-job-service.php` — source job creation, dispatch hook, ingestion, pagination, relevant-only storage.
- `includes/class-fidtf-normalizer.php` — canonical item schema, metric parser, media schema, evidence quality.
- `includes/class-fidtf-relevance-filter.php` — deterministic relevance scoring and exclude-term suppression.
- `includes/class-fidtf-report-service.php` — idempotent local report generation and honest planned-run template data.
- `includes/class-fidtf-rest-controller.php` — REST routes and safe public response shaping.
- `assets/js/fidtf-frontend.js` — form collection, source rows, polling foundation, report and evidence loading.
- `templates/shortcode-deep-trend-finder.php` — frontend planner form.
- `templates/report-deep-trend-finder.php` — report rendering.

## REST routes

```text
POST /future-island-dtf/v1/runs
GET  /future-island-dtf/v1/runs/{id}
GET  /future-island-dtf/v1/runs/{id}/report
GET  /future-island-dtf/v1/runs/{id}/items
POST /future-island-dtf/v1/runs/{id}/ingest
```

Security behavior:

- Normal users must be logged in and pass the mother plugin access check.
- Run, report, and item reads enforce user/workspace ownership.
- Administrators can access all runs.
- Ingest remains administrator-only.
- `source_job_id` must belong to the run.
- `source_key` must match the source job.

## Canonical metrics

Every normalized item now always contains these metric keys:

```json
{
  "views": null,
  "likes": null,
  "comments": null,
  "shares": null,
  "saves": null,
  "score": null,
  "trend_interest": null
}
```

Missing metrics remain `null`. Real zero remains `0`. No missing metric is invented as zero.

Accepted numeric formats include:

- `1.4K`
- `80.7K`
- `2,786`
- `1.2M`
- `3B`
- numeric strings
- integers and floats

## Media schema

Each item has this media shape:

```json
{
  "type": "video|image|text|article|trend|unknown",
  "thumbnail_url": null,
  "media_url": null,
  "downloaded_video_path": null,
  "audio_path": null
}
```

The top-level item `url` is the public source URL. It is not reused as `media_url`.

Source defaults:

- `google_news` → `article`
- `google_trends` → `trend`
- `reddit` → `text`
- `ai_research` → `text`
- `tiktok` / `instagram` → `video` or `image` only when payload fields support that

## Evidence quality

Evidence quality always includes:

```json
{
  "caption_or_text": true,
  "metrics": true,
  "comments": false,
  "video_file": false,
  "video_frames": false,
  "audio": false,
  "transcript": false,
  "article_snippet": false,
  "trend_timeseries": false,
  "deep_video_analyzed": false,
  "limitations": []
}
```

`video_frames` and `deep_video_analyzed` remain false in this release. A `videoUrl` does not imply downloaded or analyzed video.

## Frontend foundation

After run creation, the frontend renders source rows with:

- source status
- planned queries
- planned limit
- raw count
- normalized count
- relevant count
- error code
- waiting-for-provider state

The frontend polls `GET /runs/{id}` every 6 seconds while the run is waiting. If provider bridge is not enabled, the UI states that the run is planned and waiting for source integration.

## Report behavior

Reports are idempotent. Loading a report twice returns the latest report unless an administrator forces a rebuild.

No-evidence reports explicitly state:

> This is a planned run, not an evidence report yet.

The report contains:

- run status
- source summary cards
- evidence count
- what the report can say
- what the report cannot say
- next setup step

## Credit behavior

Credit behavior stays conservative:

- Credit estimate is created at run creation.
- Credit reservation remains disabled by default.
- Empty source failures do not create final source charges.
- Deep video credit remains zero because deep video is disabled.

## Next phase

v0.2.0 should focus on provider bridge integration, preferably async through n8n or a dedicated sidecar that posts back through the ingest endpoint. Do not run heavy scraping synchronously inside WordPress.
