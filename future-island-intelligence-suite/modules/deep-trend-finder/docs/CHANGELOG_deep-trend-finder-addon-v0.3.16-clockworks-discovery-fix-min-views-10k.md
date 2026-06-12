# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.16

**Slug:** `0.3.16-clockworks-discovery-fix-min-views-10k`

## Focus
Root-cause fix for the TikTok live run failure ("TikTok trend run failed before usable provider evidence was returned"), plus min_views threshold reduced to 10,000 to align with the main plugin's social scraper philosophy.

---

## Critical Bug Fixes

### [ROOT CAUSE] `build_clockworks_input()` was exhausting Apify memory on every discovery run
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

The Clockworks TikTok scraper actor was configured with:
```
shouldDownloadVideos: true
shouldDownloadCovers: true
downloadSubtitlesOptions: 'TRANSCRIBE_ALL_VIDEOS'
```
These settings instruct the actor to download the actual video binary, the cover image, and transcribe every video for every discovery run. This causes the actor to exhaust Apify memory limits or timeout before returning any results — which is why the run showed `failed` / `Raw 0 · Normalized 0 · Relevant 0`.

The main plugin (VES `platform-input.php`) learned this lesson in v0.9.24.15:
> "TikTok discovery/profile runs must stay cheap and stable. Transcription is only allowed for exact post URL runs."

**Fix:** Changed to:
```
shouldDownloadVideos: false
shouldDownloadCovers: false
downloadSubtitlesOptions: 'NEVER_DOWNLOAD_SUBTITLES'
```
This mirrors the main plugin's discovery-run approach. The view-count filter continues to run locally after collection (not at the actor level), matching the main plugin's design.

### `searchDatePosted` replaced by `videoSearchDateFilter` for current Clockworks schema
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

Added `clockworks_video_search_date_filter()` helper. Now sends both:
- `searchDatePosted` (legacy integer code, kept for backward compatibility)
- `videoSearchDateFilter` (current enum: `TODAY`, `THIS_WEEK`, `THIS_MONTH`, `ALL_TIME`)

This mirrors how the main plugin sends both fields (v0.9.24.15+).

### `core_apify_client_status()` now verifies the main plugin's Apify token
**File:** `includes/class-fidtf-settings.php`

Previously, the `complete: true` flag only checked that `VES_Apify_Client` class and its methods existed. This caused `tiktok_live_ready` to be `true` in the preflight even when the main plugin's Apify token was not set — leading to a run that passed preflight but failed dispatch.

**Fix:** When `FIDTF_Core_Apify_Client_Adapter` reports `complete: true`, the settings layer now additionally checks `VES_Config::get_token() !== ''`. If the token is missing, `complete` is set to `false` and `backend_token_missing` is added to `missing_methods`. The preflight will then correctly block live dispatch with an actionable reason.

### `waiting_for_provider` on TikTok now promoted to `failed` when dispatch is allowed
**File:** `includes/class-fidtf-source-job-service.php`

Previously, configuration-error codes (`tiktok_provider_unavailable`, `core_apify_client_incomplete`, etc.) mapped to `waiting_for_provider` job status. This left the run at `planned_waiting_for_sources` — no visual error, no actionable feedback. The v0.3.15 fix only caught the `skipped → failed` path.

**Fix:** When `source_key === 'tiktok'` and `dispatch_allowed()` is true, `waiting_for_provider` is now promoted to `failed`, making the run surface as `partial` with a diagnostic message. Same behaviour as the existing `skipped → failed` promotion.

### JS `sourceShortMessage()` now handles `waiting_for_provider` for TikTok
**File:** `assets/js/fidtf-frontend.js`

When the job status was `waiting_for_provider`, no tiktok-specific condition matched, so the function fell through to the generic fallback: `"Planned only in this release."` — a misleading message when the intent was clearly live.

**Fix:** Added an explicit `waiting_for_provider` / `paused_by_configuration` case for TikTok with context-aware messages:
- Backend token missing → actionable instruction to set the token
- Core client unavailable → provider configuration message
- Generic fallback → "TikTok provider is not fully configured..."

---

## Feature Change

### `tiktok_min_views` default reduced from 20,000 to 10,000
**File:** `includes/class-fidtf-settings.php`

The default minimum view count threshold for TikTok posts is now **10,000** (was 20,000). This aligns with the user's requirement and produces more results on lower-volume topics. The filter continues to run as a local post-scrape filter (not at the actor level), matching the main plugin's design philosophy.

Existing installs that already have a stored `tiktok_min_views` value are unaffected; the new default only applies to fresh installs or settings resets.

---

## Unchanged
- No database schema change.
- No shortcode change.
- No changes to non-TikTok providers.
- No changes to the apidojo or scraptik input builders.
- No changes to enrichment flow.
- The min_views filter continues to run locally in `FIDTF_Relevance_Filter`, not passed to the actor (same as main plugin).

---

## Files Changed
```
future-island-deep-trend-finder-addon.php            (version bump)
includes/providers/class-fidtf-provider-tiktok.php   (clockworks input fix + videoSearchDateFilter)
includes/class-fidtf-settings.php                    (min_views 10k + token validation in core_apify_client_status)
includes/class-fidtf-source-job-service.php          (waiting_for_provider → failed promotion)
assets/js/fidtf-frontend.js                          (waiting_for_provider sourceShortMessage)
```
