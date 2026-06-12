# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.17

**Slug:** `0.3.17-clockworks-input-schema-fix`

## Focus
Hotfix for the HTTP 400 "TikTok provider returned an error" introduced by v0.3.16.
`shouldDownloadVideos: false` and `shouldDownloadCovers: false` are removed from
the clockworks actor input; the actor rejects explicit `false` booleans for these
fields in keyword/hashtag search mode. Also adds proper error mapping for
`apify_invalid_input` and `apify_memory_limit` in the core adapter.

---

## Bug Fixes

### Remove `shouldDownloadVideos` and `shouldDownloadCovers` from clockworks input
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

v0.3.16 added these fields explicitly as `false` to prevent expensive downloads.
The clockworks/tiktok-scraper actor returns HTTP 400 when these booleans are sent
in keyword/hashtag search mode — they are only valid in profile/URL modes.

The main plugin (`platform-input.php`) never sets these fields for search-mode runs
and simply relies on the actor default. The addon now matches this behaviour exactly.

`downloadSubtitlesOptions: 'NEVER_DOWNLOAD_SUBTITLES'` remains in place as it IS
accepted by the actor in all modes and keeps subtitle/transcription off.

### Add `apify_invalid_input` and `apify_memory_limit` to `map_core_error()`
**File:** `includes/providers/class-fidtf-core-apify-client-adapter.php`

Both error codes previously fell through to the generic "TikTok provider returned
an error." message with no actionable context.

- `apify_invalid_input` → `tiktok_provider_invalid_input` with message:
  _"TikTok actor rejected the run input (HTTP 400). Check the actor slug and input
  schema in admin settings."_ (non-retryable)
- `apify_memory_limit` → `tiktok_provider_memory_limit` with message:
  _"TikTok actor exceeded Apify memory limit. The run will be retried."_
  (retryable, retry_after 90 s)

### `tiktok_provider_memory_limit` handled as `retryable_failed` in job service
**File:** `includes/class-fidtf-source-job-service.php`

Memory limit errors are transient; the job is now saved as `retryable_failed`
instead of `failed`, so the polling loop will re-attempt the run automatically.

---

## No Other Changes
- All v0.3.16 fixes remain in place (min_views 10k, token check, waiting_for_provider
  promotion, JS message fix, videoSearchDateFilter).
- No database schema change.
- No shortcode change.
