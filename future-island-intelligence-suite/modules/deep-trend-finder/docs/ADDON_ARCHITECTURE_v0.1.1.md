# ADDON_ARCHITECTURE_v0.1.1

## Scope

`future-island-deep-trend-finder-addon` is still a foundation-hardening add-on. It does not perform live scraping, AI web research, deep video analysis, audio extraction, transcription, or final model synthesis unless external bridges are explicitly connected through server-side hooks.

## Why this remains a separate add-on

The existing mother plugin already owns the workspace shell, usage billing, memory records, and current lightweight Trend Finder UI. Deep Trend Finder needs independent run/job/item/report lifecycle, ownership checks, evidence quality gates, and source-by-source progressive execution. Keeping it as an add-on avoids turning the mother plugin into a fragile monolith.

## Mother plugin audit summary

Audited baseline: `future-island-v0.9.31.8-p3.1-live-qa-hotfix.zip`.

Relevant confirmed integration points:

- `VES_Usage_Billing::can_access_panel()` exists for frontend access checks.
- `VES_Usage_Billing::reserve_usage()` exists for optional reservation.
- `VES_Memory_Records::workspace_id_for_user()` / `infer_workspace_id()` exist and are now used as workspace fallbacks.
- Existing mother tests passed:
  - `php tests/test-v09318-p2-trend-lite.php`
  - `php tests/test-v09318-p31-live-qa-hotfix.php`

No mother plugin files were changed.

## Tables

The add-on keeps its own data boundary:

### `fi_dtf_runs`

Stores request, workspace/user ownership, planner output, status, and credit estimate/reservation state.

### `fi_dtf_source_jobs`

Stores one independent source job per source: source key, provider metadata, job status, raw/normalized/relevant counts, retryability, and error code.

### `fi_dtf_items`

Stores only relevant, deduplicated evidence items after normalization and relevance filtering. Full raw payload is stored server-side only and is not returned to normal frontend users.

### `fi_dtf_reports`

Stores final/partial synthesis snapshots. Report generation is idempotent by default.

## REST routes

Namespace: `future-island-dtf/v1`

- `POST /runs` — create a planned run and source jobs.
- `GET /runs/{id}` — return run and public-safe source job status.
- `GET /runs/{id}/report` — build or return cached report.
- `GET /runs/{id}/report?force=1` — admin-only force rebuild.
- `GET /runs/{id}/items` — paginated relevant evidence payload for Show More.
- `POST /runs/{id}/ingest` — admin-only ingestion endpoint for provider results.

## Ownership and permissions

v0.1.1 adds run ownership hardening:

- Admins can access any run.
- Normal users can access their own runs.
- Normal users can access same-workspace runs only when a valid workspace id is available.
- Missing runs return 404.
- Unauthorized run access returns 403.

Ingest remains admin-only. If `source_job_id` is provided, it must belong to the run and match `source_key`. Manual `source_job_id=0` ingest is admin-only.

## Normalization schema

Items normalize to canonical fields:

- `source` / `source_key`
- `external_id`
- `url`
- `author`
- `title`
- `text`
- `caption`
- `caption_or_text`
- `published_at`
- `language`
- `market`
- `metrics`
- `media`
- `transcript`
- `subtitles`
- `comments_sample`
- `evidence_quality`

Metric aliases now include TikTok/Instagram/Reddit/Google-like keys such as `playCount`, `diggCount`, `collectCount`, `saveCount`, `score`, `upvotes`, and `trend_interest`.

## Evidence quality rule

The add-on does not claim unavailable evidence:

- `video_file=false` unless a real downloaded video path exists.
- `video_frames=false` always in this MVP.
- `audio=false` unless an actual audio path exists.
- `transcript=false` unless transcript/subtitles are provided.
- Deep video/audio analysis remains gated by `FI_DTF_ENABLE_DEEP_VIDEO=false` by default.

## Relevance filtering

Each normalized item receives:

- `relevance_score`
- `relevance_tier`: `direct|adjacent|weak|noise`
- `recommended_use`: `show|maybe|hide`
- `signal_type`
- `relevance_reason`
- `evidence_quality`

Only `show` items are stored/exposed by default after deduplication. Raw, irrelevant, and duplicate items are counted but not displayed as evidence.

## Report generation

`GET /report` is idempotent:

- If a report exists, it returns the latest stored report.
- If no report exists, it builds and stores one report.
- Memory bridge fires only when a new report is stored.
- Admin-only `force=1` creates a new report snapshot.

## Credit behavior

v0.1.1 keeps credit behavior conservative:

- Estimate before run.
- Optional reservation only if mother usage billing supports it and the admin enables it.
- No final source settlement for failed/empty/no-evidence sources.
- Disabled/unavailable source work is not charged as delivered evidence.

## Real vs stub

Real in v0.1.1:

- run creation
- source jobs
- ownership checks
- ingest validation
- normalization
- relevance filtering
- deduplication
- relevant-only evidence storage
- idempotent report storage
- show-more evidence retrieval

Still stub/bridge-only:

- live provider dispatch
- AI planner
- AI relevance batching
- AI final synthesis
- AI web research
- deep video/audio/transcript worker
