# ADDON_ARCHITECTURE_v0.1.3

Version: `0.1.3-async-readiness`

This release prepares Deep Trend Finder for async provider integration without executing live scraping, AI research, or deep video processing.

## Runtime boundaries

- WordPress remains the product shell, run registry, source-job registry, evidence store, and report renderer.
- Provider execution remains outside this add-on until v0.2.0.
- Provider bridges must return a canonical dispatch result and/or later ingest items through the admin-only endpoint.
- Normal users never see actor IDs, provider slugs, API keys, or raw provider mechanics.

## Main layers

1. **Shortcode UI**
   - `[future_island_deep_trend_finder]`
   - Collects brief, objective, market, language, date range, keywords, brand, company, competitors, audience, exclude terms, and selected sources.

2. **Run service**
   - Sanitizes request input.
   - Stores run-level request fields and `request_meta_json`.
   - Creates source jobs.
   - Reserves credits only through the mother plugin when enabled.

3. **AI planner foundation**
   - Local fallback planner only.
   - External planner hook is validated but no real AI planner is called.
   - `request_context` is complete and structured for v0.2.0.

4. **Source job / provider contract**
   - Canonical provider result schema:
     - `ok`
     - `status`
     - `provider_run_id`
     - `raw_count`
     - `normalized_count`
     - `relevant_count`
     - `error_code`
     - `retryable`
     - `message`
     - `items`
   - Allowed canonical statuses:
     - `queued`
     - `running`
     - `completed`
     - `failed`
     - `retryable_failed`
     - `skipped`
   - Disabled/missing bridge results keep the visible job in `waiting_for_provider` while the dispatch result itself remains canonical.

5. **Normalizer / evidence quality**
   - Canonical metric keys are preserved.
   - Video URLs do not imply downloaded video files.
   - Deep video, frame, audio, and transcript claims remain false unless provider data explicitly supplies them.
   - Limitations are explicit for missing transcript, audio, frame analysis, and downloaded video.

6. **Frontend polling**
   - Polls `GET /runs/{id}` with bounded attempts.
   - Stops on final statuses.
   - Stops quickly when live dispatch is disabled and jobs are only planned/waiting for provider.
   - Fetch errors show warnings instead of destroying the UI.

7. **Items endpoint**
   - Public item pagination is clamped to 50 items max.
   - Frontend Show More uses 20 items per page.
   - Reports can use an internal bounded read path without exposing large public payloads.

8. **Reports**
   - No-evidence reports are explicitly planned-run reports.
   - Empty runs say no live scraping and no AI web research have been executed.
   - Evidence reports remain partial evidence reports with low or low-medium confidence unless enough source diversity exists.

## Still not implemented

- Live provider calls.
- Real Scraptik / Apify / Bright Data / Google / Reddit execution.
- Real AI planner bridge.
- Real AI final synthesis.
- Deep video download or analysis.
- Audio extraction.
- Transcript generation.
- Sidecar worker.

## Next version

Recommended next release: `v0.2.0-provider-bridge-contract`.

The next release should connect a single async provider pathway first, via an approved external bridge, simulator, managed worker, or optional n8n example that calls WordPress back through the ingest endpoint. It should not turn WordPress into the heavy scraping runtime.
