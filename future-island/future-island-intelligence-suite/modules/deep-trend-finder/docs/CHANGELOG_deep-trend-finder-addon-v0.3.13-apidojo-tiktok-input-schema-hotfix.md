# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.13

**Slug:** `0.3.13-apidojo-tiktok-input-schema-hotfix`  
**Date:** 2026-05-25  
**Type:** Focused TikTok discovery input-schema hotfix on top of v0.3.12. No rewrite. No shortcode change. No database schema change.

## Why this patch exists

Production evidence showed that `apidojo/tiktok-scraper` was selected as the TikTok discovery actor, but the add-on was still sending Clockworks-style discovery input:

- `searchQueries`
- `resultsPerPage`
- `searchSection`
- `searchSorting`
- `videoSearchSorting`
- `searchDatePosted`
- `proxyCountryCode`
- media download fields

The Apify run reached the actor but failed with:

> Search keyword or start URLs must be provided

Root cause: v0.3.12 correctly separated discovery from enrichment, but it still treated `apidojo/tiktok-scraper` as if it accepted the Clockworks TikTok input schema.

## What's changed

### 1. Actor-specific TikTok input builders

`FIDTF_Provider_TikTok` now has explicit actor-family builders:

- `build_scraptik_input()` — selected-post enrichment via `scraptik/tiktok-api`
- `build_clockworks_input()` — discovery for:
  - `clockworks/tiktok-scraper`
  - `clockworks/free-tiktok-scraper`
- `build_apidojo_input()` — discovery for:
  - `apidojo/tiktok-scraper`

### 2. Apidojo discovery input no longer receives Clockworks fields

For `apidojo/tiktok-scraper`, the add-on now sends Apidojo-style fields:

- `searchKeywords`
- `startUrls`
- `dateRange`
- `location`
- `maxItems`
- `sortType`
- `includeSearchKeywords`
- `customMapFunction`

It does not send:

- `searchQueries`
- `resultsPerPage`
- `searchSection`
- `searchSorting`
- `videoSearchSorting`
- `searchDatePosted`
- `proxyCountryCode`
- `shouldDownloadVideos`
- `shouldDownloadCovers`
- `downloadSubtitlesOptions`
- Scraptik enrichment fields

### 3. Apidojo is no longer classified as Clockworks-schema actor

`actor_uses_clockworks_schema()` now only covers Clockworks actors.

New Apidojo detection:

- `actor_uses_apidojo_schema()`

### 4. Pre-dispatch validation blocks malformed Apidojo input

Before any TikTok live run is sent, the adapter validates the generated actor input.

If the selected actor is `apidojo/tiktok-scraper` but the generated input contains Clockworks-style `searchQueries` and no recognized search keyword/start URL field, dispatch is blocked with:

> Selected TikTok actor is apidojo/tiktok-scraper, but generated input does not contain a recognized search keyword field.

The diagnostic is marked as:

- `dispatch_attempted: false`
- `request_attempted: false`
- `provider_mode_used: tiktok_discovery`
- `outcome_reason: invalid_actor_input`

### 5. Admin UI copy clarified

The settings screen now explains that:

- Clockworks actors receive Clockworks search fields.
- Apidojo receives `searchKeywords/startUrls` and its query-builder fields.
- Scraptik endpoint fields are not sent to discovery.

When global live dispatch is disabled, the actor input preview is labeled:

> preview only / blocked by global_live_disabled

This avoids implying that the preview will actually be dispatched.

## What did NOT change

- Shortcode remains `[future_island_deep_trend_finder]`.
- Database schema unchanged.
- Scraptik remains enrichment-only for selected posts.
- v0.3.12 discovery/enrichment separation remains intact.
- Clockworks discovery input remains backward-compatible.
- Other sources remain untouched.

## Tests added

New file:

- `tests/test-fidtf-v0313-apidojo-tiktok-input-schema-hotfix.php`

Coverage includes:

- v0.3.13 version bump
- all three TikTok input builders exist
- Apidojo is not classified as Clockworks schema
- Apidojo input contains `searchKeywords` / `startUrls`, not `searchQueries`
- Apidojo input contains `dateRange`, `location`, `maxItems`, `sortType`, `includeSearchKeywords`, `customMapFunction`
- malformed Apidojo input is blocked before dispatch
- malformed Apidojo input reports no dispatch/request attempted
- Clockworks discovery still receives `searchQueries`
- admin UI shows `preview only / blocked by global_live_disabled`

## Verification

- PHP lint: passed across all PHP files.
- Existing test suite + v0.3.13 test: passed.
