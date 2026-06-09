# Add-on Architecture — v0.3.0 TikTok Provider Bridge

## Product boundary

Deep Trend Finder remains a WordPress-first, credit-aware marketing intelligence add-on. The system plans source jobs, normalizes public signals, filters relevance, and exposes only evidence-backed results. It does not claim that a planned source was scraped unless provider data was actually returned and ingested.

## v0.3.0 execution model

### Gates

A TikTok job may start a live provider run only when both gates are true:

1. `enable_live_dispatch`
2. `enable_tiktok_live_bridge`

If either gate is off, the job remains planned-only and returns an explicit diagnostic.

### Source behavior

| Source | v0.3.0 behavior |
|---|---|
| TikTok | Can run live only through the TikTok provider bridge. |
| Instagram | Planned-only. |
| Reddit | Planned-only. |
| Google Trends | Planned-only. |
| Google News | Planned-only. |
| AI research | Uses its existing gated AI research bridge only if separately enabled; no fake web research bridge was added. |

## Main components

### Settings

`includes/class-fidtf-settings.php`

Stores and sanitizes:

- global live dispatch gate
- TikTok live bridge gate
- TikTok actor ID
- TikTok provider mode
- TikTok default/max limits
- TikTok polling toggle
- server-side Apify token

Token behavior:

- `FIDTF_TIKTOK_APIFY_TOKEN` constant overrides the database setting.
- The token is never localized to JavaScript.
- The admin token field is blank on render and preserves the existing token when left blank.

### Source dispatcher

`includes/class-fidtf-source-dispatcher.php`

Builds canonical source-job payloads. For TikTok only, `provider_intent` becomes `live_collect` when both live gates are enabled. For every non-TikTok source, `provider_intent` stays `planned_only`.

### TikTok provider

`includes/providers/class-fidtf-provider-tiktok.php`

Builds the canonical payload and maps it into provider actor input. It does not download video, audio, or transcripts. It only requests metadata-style collection.

### TikTok live adapter

`includes/providers/class-fidtf-tiktok-live-adapter.php`

Supports three provider modes:

1. `external_filter`: uses `fidtf_tiktok_live_dispatch_result` first, then legacy `fidtf_dispatch_source_job` only for backward compatibility.
2. `core_apify_client`: calls a mother/core client supplied through `fidtf_tiktok_core_apify_client`.
3. `apify_bridge`: starts an async Apify actor run server-side and polls the run/dataset later.

### Source job service

`includes/class-fidtf-source-job-service.php`

Creates source jobs, dispatches TikTok if allowed, ingests returned items, and refreshes queued/running TikTok jobs during run polling.

### Run service

`includes/class-fidtf-run-service.php`

Derives run status from source job statuses instead of assuming that global live dispatch means every source is running.

### REST controller

`includes/class-fidtf-rest-controller.php`

Refreshes running TikTok jobs on `GET /runs/{id}` and keeps normal users away from provider internals. Admins can still see provider, actor ID, and run ID diagnostics.

### Normalizer

`includes/class-fidtf-normalizer.php`

Handles common nested TikTok provider shapes including:

- `authorMeta`
- `stats`
- nested `video` URLs/covers
- nested music metadata
- provider subtitles when available

It does not claim downloaded video, audio analysis, transcript generation, or deep visual analysis unless those fields truly exist.

## Data flow

1. User creates a Deep Trend Finder run.
2. Planner creates source plans.
3. Source jobs are stored with canonical payloads.
4. TikTok job checks global live gate + TikTok bridge gate.
5. If enabled, TikTok adapter starts provider job or accepts immediate provider items.
6. Returned items are normalized.
7. Relevance filter stores only relevant, deduped evidence.
8. REST polling refreshes TikTok queued/running jobs.
9. Frontend shows planned, running, completed, or no-relevant-evidence states.

## External API notes

The direct Apify mode uses async actor run semantics. It does not use synchronous wait-for-finish scraping. Provider tokens stay server-side.
