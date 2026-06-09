# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.14

**Slug:** `0.3.14-tiktok-retryable-dispatch-recovery`  
**Date:** 2026-05-25  
**Type:** Focused hotfix on v0.3.13. No rewrite. Add-on only.

## Production symptom

A TikTok run could show:

- run intent: `live_tiktok_collection`
- source job status: `retryable_failed`
- UI copy: `Planned only in this release.`

That was misleading. The run had passed live-readiness, but the provider dispatch hit a retryable provider/concurrency/transport state before a provider run id was available.

## Root correction

Retryable TikTok dispatch failures are now treated as retryable live-dispatch states, not planned-only states.

## What changed

### 1. Retryable dispatch recovery

`FIDTF_Source_Job_Service::maybe_refresh_running_jobs()` can now restart TikTok discovery dispatch when a TikTok job is `retryable_failed` and has no `provider_run_id`.

Before:
- retryable failure without a provider run id stayed stuck forever because refresh skipped jobs without `provider_run_id`.

Now:
- the job remains active/retryable.
- after backoff, polling can call `FIDTF_TikTok_Live_Adapter::start()` again.
- if Apify returns a run id, the job moves to queued/running and continues the normal polling path.

### 2. Retryable jobs are not marked completed

Initial retryable failures no longer receive `completed_at` in the source job row.

### 3. Provider messages are preserved

TikTok zero-count logic no longer overwrites provider failure messages during retryable/failed dispatch states.

New helper:
- `FIDTF_Source_Job_Service::message_for_dispatch_state()`

### 4. Admin diagnostics expose retryable provider state

Per-job diagnostics now persist and expose:

- `error_code`
- `error_message`
- `retryable`
- `retry_after`

### 5. Frontend copy is truthful

`retryable_failed` now shows retry-specific copy instead of `Planned only in this release.`

Examples:
- `TikTok provider is temporarily busy. The job will retry automatically.`
- `TikTok provider is rate limited. The job will retry automatically.`

### 6. Frontend polling window extended

Live polling now runs long enough to survive Core/Apify retry backoff.

Before:
- 10 attempts

Now:
- 30 attempts for live dispatch

`retryable_failed` is no longer treated as a final frontend polling state.

## What did NOT change

- Shortcode unchanged.
- Database schema unchanged.
- Apidojo input schema from v0.3.13 unchanged.
- Clockworks input schema unchanged.
- Scraptik enrichment role unchanged.
- Other providers untouched.

## Tests

Added:
- `tests/test-fidtf-v0314-tiktok-retryable-dispatch-recovery.php`

Verified:
- PHP lint: passed.
- Existing test suite + v0.3.14 test: passed.
