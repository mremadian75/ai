# CHANGELOG_deep-trend-finder-addon-v0.1.3-async-readiness

## Added

- Bumped add-on version to `0.1.3-async-readiness`.
- Added `request_meta_json` to the run schema for async provider/planner context.
- Added safe DB version check through `FIDTF_DB::maybe_upgrade()`.
- Added full planner `request_context`: brief, objective, market, language, date range, keywords, brand, company, competitors, audience, exclude terms, and channels.
- Added external planner context merge so missing external context is backfilled from the sanitized request.
- Added canonical provider dispatch result normalization.
- Added explicit provider statuses: queued, running, completed, failed, retryable_failed, skipped.
- Added controlled frontend polling with attempts, maxAttempts, intervalMs, and stop conditions.
- Added stable provider-bridge-disabled messaging.
- Added source row update foundation during polling.
- Added company field and custom date-range option in the shortcode form.
- Added v0.1.3 async-readiness tests.

## Changed

- Competitor list limit increased to 20.
- Exclude terms limit increased to 30.
- Provider payloads now include request context fields needed by future bridge workers.
- Public item reads remain capped to 50; report reads use a separate internal bounded path.
- No-evidence report copy now explicitly says no live scraping and no AI web research have been executed.
- Evidence quality limitations now explicitly mention no frame-by-frame visual analysis.

## Not changed

- No live scraping was added.
- No real provider call was added.
- No AI planner call was added.
- No AI web research was added.
- No deep video/audio/transcript processing was added.
- No mother plugin files were changed.
