# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.12

**Slug:** `0.3.12-tiktok-discovery-enrichment-split`  
**Date:** 2026-05-25  
**Type:** Focused TikTok architecture correction. No rewrite. Add-on only.

## Main correction

v0.3.12 stops using `scraptik/tiktok-api` as the primary TikTok discovery/search actor.

The TikTok live flow is now split into two explicit phases:

1. **TikTok discovery**
   - Default actor: `clockworks/tiktok-scraper`
   - Provider mode label: `tiktok_discovery`
   - Uses discovery input fields such as `searchQueries`, `hashtags`, `profiles`, `postURLs`, `resultsPerPage`, `searchSection`, `searchSorting`, `videoSearchSorting`, and `proxyCountryCode`.

2. **TikTok enrichment**
   - Default actor: `scraptik/tiktok-api`
   - Provider mode label: `tiktok_enrichment`
   - Used only after discovery returns candidate posts.
   - Sends selected-post fields such as `post_awemeId`, `listComments_awemeId`, `listComments_count`, and `listComments_cursor`.

## Backward compatibility

- Existing `tiktok_actor_id` remains accepted as a legacy setting.
- If an old install has `tiktok_actor_id = scraptik/tiktok-api`, the add-on treats it as enrichment, not discovery.
- Discovery falls back to `clockworks/tiktok-scraper` unless an explicit non-Scraptik discovery actor is configured.
- `actor_map['tiktok']` now mirrors the discovery actor.

## Admin settings

The ambiguous TikTok actor setting was replaced with:

- TikTok discovery actor ID
- TikTok enrichment actor ID

The admin copy now explains that discovery sends search/collection fields and enrichment sends selected-post Scraptik fields.

## Diagnostics

Admin diagnostics now expose:

- `discovery_actor_id`
- `enrichment_actor_id`
- `discovery_actor_input`
- `discovery_run_id`
- `discovery_dataset_rows`
- `discovery_flattened_items`
- `normalized_items`
- `relevant_items`
- `enrichment_attempted`
- `enrichment_run_ids`
- `enrichment_comments_count`

The dispatch diagnostic is persisted per source job through a transient. No database schema change was made.

## UI copy updates

The frontend/admin flow now includes the requested production messages:

- `TikTok discovery completed`
- `TikTok enrichment completed`
- `Discovery returned posts but relevance filter rejected them`
- `Discovery returned zero posts`
- `Enrichment skipped: no selected post aweme_id`

## Tests

Added `tests/test-fidtf-v0312-tiktok-discovery-enrichment-split.php` covering:

- `scraptik/tiktok-api` classification as enrichment actor
- default discovery actor as `clockworks/tiktok-scraper`
- discovery input contains `searchQueries`, not `post_awemeId`
- enrichment input contains `post_awemeId` and `listComments_awemeId`
- legacy `tiktok_actor_id=scraptik/tiktok-api` migration
- discovery completion without enrichment
- enrichment merging selected-post comments/details after discovery

Existing TikTok/Core tests were updated to match the corrected discovery-first contract.
