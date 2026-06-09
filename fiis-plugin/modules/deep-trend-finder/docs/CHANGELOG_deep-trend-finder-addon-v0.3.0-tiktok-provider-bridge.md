# Changelog — Deep Trend Finder Add-on v0.3.0 TikTok Provider Bridge

## Summary

This release turns v0.2.2 from a source-job contract hardening build into a TikTok-only live provider bridge build. It keeps the product honest: TikTok may start a live provider job only when both the global live dispatch gate and the TikTok bridge gate are enabled. Instagram, Reddit, Google Trends, Google News, and AI research remain planned-only unless their own bridges are added later.

## Added

- TikTok-specific live adapter: `includes/providers/class-fidtf-tiktok-live-adapter.php`.
- TikTok provider modes:
  - `external_filter`
  - `core_apify_client`
  - `apify_bridge`
- Server-side TikTok Apify token support through the admin setting or `FIDTF_TIKTOK_APIFY_TOKEN` constant.
- TikTok bridge admin controls:
  - enable/disable TikTok live bridge
  - actor ID
  - default item limit
  - max item limit
  - provider mode
  - server-side token field
  - polling toggle
- TikTok actor input mapping from canonical source-job payloads:
  - queries
  - hashtags
  - keywords
  - market/language/date range
  - exclude terms
  - creator/format hints
  - clamped max item limit
- Polling/refresh path for queued/running TikTok jobs when the run is refreshed.
- TikTok-specific frontend states for running and completed-no-relevant-evidence jobs.
- v0.3.0 test suite: `tests/test-fidtf-v030-tiktok-provider-bridge.php`.

## Changed

- Global live dispatch no longer means every source may fire a provider job.
- Non-TikTok Apify providers now return `source_live_bridge_disabled` instead of invoking generic provider filters.
- TikTok now returns explicit diagnostics:
  - `live_dispatch_disabled`
  - `tiktok_live_bridge_disabled`
  - `tiktok_provider_unavailable`
  - `provider_rate_limited`
  - `provider_busy`
  - `tiktok_provider_failed`
- Canonical payloads now expose:
  - `provider_intent`
  - source-specific `live_dispatch_enabled`
  - `global_live_dispatch_enabled`
  - `tiktok_live_bridge_enabled`
- Run status is derived from source job states instead of assuming global live dispatch means `running`.
- TikTok normalization now handles common nested provider shapes such as `authorMeta`, `stats`, and nested `video` URLs.
- Report fallback copy now states that only TikTok has a live bridge in this build.

## Not included by design

- No Instagram live bridge.
- No Reddit live bridge.
- No Google Trends live bridge.
- No Google News live bridge.
- No AI web-research live bridge.
- No final synthesis completion claim from weak/no evidence.
- No video/audio download.
- No frame extraction.
- No transcript generation.
- No Playwright/Crawlee/ffmpeg sidecar.
- No direct publishing.

## Verification

- PHP lint: all plugin and test PHP files passed.
- JS syntax: `node --check assets/js/fidtf-frontend.js` passed.
- Regression tests passed:
  - v0.1.0: 30 / 30
  - v0.1.1: 30 / 30
  - v0.1.2: 80 / 80
  - v0.1.3: 161 / 161
  - v0.1.4: 202 / 202
  - v0.2.0: 235 / 235
  - v0.2.1: 291 / 291
  - v0.2.2: 340 / 340
  - v0.3.0: 372 / 372
