# Deep Trend Finder Add-on v0.3.25

## Focus
Upgrade the add-on from a TikTok-only live proof into a broader live-source trend finder with a cleaner evidence UI and a substantially stronger deterministic synthesis layer.

## Main changes

- Added generic Apify live adapter for non-TikTok sources:
  - Instagram
  - Reddit
  - Google Trends
  - Google News
- Added `enable_multi_source_live_bridge` safety gate.
- Added source-level live readiness through `FIDTF_Settings::source_live_ready()`.
- Updated preflight contract to expose `live_sources` and `planned_only_sources`.
- Updated run intent to support `live_multi_source_collection`.
- Updated source-job refresh so non-TikTok live sources can be refreshed through the generic adapter.
- Preserved TikTok-specific discovery/enrichment flow.
- Rebuilt report synthesis into a professional evidence report:
  - executive summary
  - key patterns
  - strategic readout
  - content angle briefs
  - recommended validation plan
  - evidence quality summary
  - live-aware “can say / cannot say” limits
- Fixed report typography so nested report headings no longer inherit huge hero sizing.
- Improved evidence cards with thumbnail support, metric chips, provider query and hashtag context.
- Dedupe enabled source cards to prevent repeated channel tiles.
- Added admin multi-source live readiness diagnostics.

## Important limitation
The generic Apify bridge uses best-effort actor inputs for Instagram, Reddit, Google Trends and Google News. Actual Apify actors can have different input schemas. Each actor mapping must be live-tested in the WordPress/Apify environment before selling that source as production-grade.

## QA
- PHP lint passed for all PHP files.
- JS syntax check passed for `assets/js/fidtf-frontend.js`.
- Full PHP regression suite passed.
