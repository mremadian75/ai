# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.10

**Slug:** `0.3.10-core-social-apify-execution-parity`
**Date:** 2026-05-25
**Type:** Production debugging patch (focused). No rewrite. Add-on only — Core was not modified.

## Why this release

Production runs against `scraptik/tiktok-api` were either dispatching with the wrong actor schema, hanging on a synchronous 20-second wait that looked like "no request was sent", or completing with empty datasets that the UI mis-labelled as "filtered out by relevance". The add-on had also diverged from the working Apify execution lifecycle the Core Social Media Scraper uses.

The root cause was located in the add-on's Core Apify client adapter:
- **v0.3.9 removed `$options['waitForFinish'] = 0;`** from the run URL builder, so the add-on's POST to `acts/scraptik~tiktok-api/runs` waited synchronously for up to 20s. On slow or backed-up Apify queues this regularly looked like the request was never sent.
- Core's `VES_Run_Execution_Service::start_source_apify()` (Future Island Core v0.9.31.8 / `class-ves-run-execution-service.php:725-728`) does the opposite: it builds the URL with `waitForFinish=0` and lets the source-job refresh loop poll via `VES_Apify_Client::fetch_run()` and `VES_Apify_Client::fetch_items()`.

## What changed (add-on only)

### `includes/providers/class-fidtf-core-apify-client-adapter.php`
- `run_url()` now explicitly sets `$options['waitForFinish'] = 0` after `ves_prepare_run_options()` — mirrors Core social scraper exactly. HTTP-fallback URL also uses `waitForFinish=0`.
- `completed_result()` distinguishes three outcomes and emits an `outcome_reason` field: `evidence_returned`, `flattened_empty` (provider returned dataset rows but none survived flattening), and `zero_dataset_items`.
- `normalize_start_response()` "queued" and `normalize_refresh_response()` "running" returns now include `provider_mode_used = core_apify_client`, `request_attempted = true`, and `outcome_reason = run_queued`/`run_running`.

### `includes/class-fidtf-rest-controller.php`
- Per-job `dispatch_diagnostic` (admin-only) now contains: `dispatch_attempted`, `request_attempted`, `outcome_reason`, `reason`, `provider_mode` (configured), `provider_mode_used` (effective at dispatch), `start_method`, `addon_version`, `output_counts.{provider_dataset_rows,flattened_raw_items,normalized_items,relevant_items}`, `actor_input_preview` (first 12 entries) and the full token-stripped `sanitized_actor_input`.
- New `derive_outcome_reason()` helper distinguishes `planned_only`, `run_running`, `provider_failed`, `evidence_returned`, `zero_dataset_items`, `normalizer_extracted_zero`, `no_items_passed_relevance`.

### `assets/js/fidtf-frontend.js`
- `updateRunView()` now branches on four post-completion states: running, zero raw items, normalizer failure, relevance failure. Each emits a distinct message instead of collapsing everything to "TikTok returned data, but no items passed the relevance filter."
- `sourceShortMessage()` already received the four-state copy in v0.3.6+; this release wires the run-level poll-status banner to match.

### `includes/class-fidtf-admin.php`
- Settings header copy now describes the v0.3.10 parity goal so admins can confirm the running version visually.

### Tests
- New `tests/test-fidtf-v0310-core-social-apify-execution-parity.php` (45+ new assertions). End-to-end test pipes a `VES_Apify_Client` mock through `FIDTF_Provider_TikTok->dispatch()` and asserts:
  - exactly one POST through `VES_Apify_Client::request`
  - URL contains `waitForFinish=0`
  - body is JSON-encoded via `wp_json_encode`
  - body contains `searchPosts_keyword = "mahou"` (brand wins over generic taxonomy)
  - body contains `searchPosts_region = "ES"` (Spain mapping)
  - body omits all generic fields (queries, searchTerms, hashtags, maxItems, downloadVideo, includeMetadata, includeComments)
  - dispatch result records `provider_mode_used`, `request_attempted`, `provider_run_id`
  - Core adapter, REST controller, admin copy and JS all contain the new outcome strings.
- Updated `test-fidtf-v030-tiktok-provider-bridge.php` to accept either v0.3.5 ("returned data") or v0.3.10 ("returned posts") relevance copy.
- Bumped version-acceptance lists in tests v0.1.3 → v0.3.9.

## What did not change

- Core plugin: **untouched**. The add-on calls into the existing public Core API exactly as Core's own scraper does.
- DB schema: **untouched**.
- Shortcode `[future_island_deep_trend_finder]`: **untouched**.
- Other providers (Instagram, Reddit, Google Trends, Google News, AI Research): remain planned-only.
- Scraptik input contract (v0.3.9): retained — endpoint-only fields, no generic keys, `searchPosts_region=ES` for Spain.
- 20,000-view minimum relevance gate (v0.3.9): retained.
- Clockworks/tiktok-scraper alternative input contract (v0.3.9): retained.

## Why the existing actor schemas are kept truthful

`scraptik/tiktok-api` is endpoint-field driven (per the actor's documented schema). Sending `shouldDownloadVideos`, `downloadSubtitlesOptions` or `searchQueries` to it is silently ignored or, in some Apify versions, rejected as invalid input. Video download/transcription is therefore left as a deliberate non-feature for Scraptik in this release. If an admin switches `tiktok_actor_id` to `clockworks/tiktok-scraper`, the input builder emits `searchQueries`, `searchSection=/video`, `shouldDownloadVideos`, `downloadSubtitlesOptions=TRANSCRIBE_ALL_VIDEOS`, and `proxyCountryCode=ES`. The add-on does NOT fabricate fake transcript fields for Scraptik.

## Production acceptance behaviour

After installing v0.3.10:

1. `Settings → Future Island Deep Trend Finder` shows the v0.3.10 string (admin can verify the running version visually).
2. With global live dispatch enabled, TikTok bridge enabled, Core Apify client present and actor id `scraptik/tiktok-api`, clicking "Start TikTok trend run":
   - POSTs once to `https://api.apify.com/v2/acts/scraptik~tiktok-api/runs?build=latest&waitForFinish=0&timeout=0&maxTotalChargeUsd=...` via the same `VES_Apify_Client::request()` Core uses.
   - Body is `wp_json_encode(scraptik_endpoint_input)` containing `searchPosts_keyword=mahou`, `searchPosts_count=25`, `searchPosts_region=ES`, `searchPosts_publishTime=…`, `searchPosts_sortType=…`, plus the rest of the empty Scraptik endpoint defaults. No `queries`/`hashtags`/`downloadVideo` etc.
   - Returns a provider_run_id; the source-job polling loop fetches the run and dataset via `VES_Apify_Client::fetch_run()` / `VES_Apify_Client::fetch_items()` exactly like Core.
3. Admin run record now reports `dispatch_diagnostic.request_attempted=true`, `provider_mode_used=core_apify_client`, `addon_version=0.3.10-…`, and the sanitized actor input. The token is never exposed.
4. If the actor returns SUCCEEDED with zero dataset items, the UI says "TikTok provider completed, but returned zero dataset items." — not the misleading "no items passed the relevance filter".
5. If the dataset has rows but the normalizer cannot extract usable fields, the UI says "TikTok data was returned, but the normalizer could not extract usable post fields."
6. If normalized items exist but all sit below the 20,000-view threshold, the UI says "TikTok returned posts, but no items passed the relevance filter."

## Remaining risks

- The 20s waitForFinish path is fully removed. Production environments that already configured `tiktok_polling_enabled=false` will appear "stuck queued" forever; admins must enable polling (default true) for the async lifecycle to complete.
- Scraptik's video/transcript story remains intentionally non-functional. If product later needs transcripts via Scraptik, a separate `videoWithoutWatermark_awemeId` enrichment pass must be designed; this release does NOT pretend to support it.
- If existing installs stored `tiktok_provider_mode=external_filter` and no `fidtf_tiktok_live_dispatch_result` filter is registered, `tiktok_effective_provider_mode()` silently switches to `core_apify_client`. The stored mode is still reported in admin diagnostics for audit, but the dispatch path is the Core-parity one.

## Staging test checklist

1. Install v0.3.10 zip.
2. Activate; confirm header reads v0.3.10.
3. In settings: enable global live dispatch, enable TikTok bridge, leave actor id at `scraptik/tiktok-api`.
4. Create a run with brand=`mahou`, market=`Spain`, keywords=`beverage, mahou, cerveza`.
5. Verify Apify console shows a new `scraptik/tiktok-api` run with the Scraptik endpoint input (searchPosts_keyword=mahou, searchPosts_region=ES, searchPosts_count=25).
6. Verify Apify input does NOT include `queries`, `searchTerms`, `hashtags`, `maxItems`, `downloadVideo`.
7. Wait for the source-job refresh tick; verify the run record fills `Raw / Normalized / Relevant` according to dataset content.
8. Verify admin run diagnostic shows `provider_mode_used=core_apify_client`, `request_attempted=true`, `outcome_reason=evidence_returned` (or distinct outcome for empty datasets).
