# Changelog — Deep Trend Finder Add-on v0.3.1 Core Apify Client Bridge Hardening

Release: `0.3.1-core-apify-client-bridge-hardening`
Baseline: `0.3.0-tiktok-provider-bridge`
Core baseline inspected: `future-island-v0.9.31.8-p3.1-live-qa-hotfix`

## Why this patch exists

v0.3.0 introduced TikTok live provider bridging, but its `core_apify_client` mode expected an object-style client contract (`run_actor`, `start_actor`, `start_run`, `poll_run`). The real Future Island Core exposes a static `VES_Apify_Client` contract instead:

- `VES_Apify_Client::request($method, $url, $body = null, $attempt = 1)`
- `VES_Apify_Client::fetch_run($run_id, $wait_for_finish = 20)`
- `VES_Apify_Client::fetch_items($run_id, $limit = 150)`

This meant `core_apify_client` mode could be selected but could not reliably start or refresh TikTok jobs through the real Core client.

## Changed

- Added `FIDTF_Core_Apify_Client_Adapter` to bridge the add-on TikTok provider to the real static Core `VES_Apify_Client` API.
- Kept the patch add-on-side only; no Core code was changed.
- Preserved existing provider modes:
  - `external_filter`
  - `apify_bridge`
  - `core_apify_client`
- Added Core diagnostics for:
  - Core active
  - `VES_Apify_Client` availability
  - required method availability: `request`, `fetch_run`, `fetch_items`
  - recommended provider mode
  - external filter presence
  - direct token presence without exposing the token
- Hardened invalid provider responses:
  - 2xx responses without `provider_run_id` and without immediate items now fail with `tiktok_provider_invalid_run_response` instead of leaving jobs stuck.
- Hardened refresh behavior:
  - Existing queued/running jobs with a `provider_run_id` can refresh even if live dispatch is later disabled.
  - Added lightweight refresh throttling/backoff for retryable provider states.
- Added activation helper to create/reuse a frontend page containing `[future_island_deep_trend_finder]` without overwriting user-edited pages.
- Added frontend CSS improvements:
  - responsive auto-fit grids
  - focus-visible styles for buttons/card links
- Added v0.3.1 regression tests for the Core static client bridge.

## Not changed

- No new sources were added.
- No Instagram, Reddit, Google Trends, Google News, YouTube, LinkedIn, Pinterest, or Ads live bridges were added.
- No fake AI web research was added.
- No deep video, audio, transcript generation, frame analysis, ffmpeg, Playwright, Crawlee, or sidecar worker was added.
- No Core plugin patch was required for this bridge.
- Existing stored provider-mode settings are not forcibly migrated.

## Important behavior note

The default stored provider mode remains backward-compatible with previous releases. In a real Future Island Core environment, the admin diagnostics now recommend `core_apify_client` when the required Core static methods are present.
