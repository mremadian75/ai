# Future Island Deep Trend Finder Add-on v0.3.2 — Core Shell Integration UI

## Changed
- Bumped add-on plugin header and `FIDTF_VERSION` to `0.3.2-core-shell-integration-ui`.
- Added `FIDTF_Module_Info` and public helpers:
  - `fidtf_module_info()`
  - `fidtf_get_frontend_page_id()`
  - `fidtf_get_frontend_url()`
- Registered module info through `future_island_register_external_modules` for Core consumption.
- Hardened auto-created page logic:
  - creates the page only when missing
  - reuses existing `/deep-trend-finder/` page
  - does not overwrite user-edited pages when the shortcode is missing
  - stores `fidtf_page_id` and `fidtf_page_slug`
  - flags `fidtf_page_shortcode_missing`
- Added admin page controls for viewing the page, recreating/updating it, and copying the shortcode manually.
- Rewired memory bridge to prefer `VES_Memory_Records::save_record()` with `source_type='deep_trend_finder'`.
- Rebuilt shortcode UI into a structured workspace:
  - Intelligence Hero
  - Research Brief Panel
  - Source Plan / Signal Map
  - Progressive Source Rows
  - Evidence Grid
  - Report Shell
- Updated frontend JS to refresh source plan, progressive rows, evidence grid, and report shell without inline handlers.
- Reworked CSS into a scoped Future Island editorial/signal-room style under `.fidtf-*` selectors.

## Still intentionally not implemented
- Instagram live bridge.
- Reddit live bridge.
- Google Trends live bridge.
- Google News live bridge.
- Deep video/audio/transcript processing.
- Fake charts, fake evidence, fake final recommendations.

## Verification
- PHP lint passed on 40 add-on PHP files.
- Node syntax check passed on `assets/js/fidtf-frontend.js`.
- Existing add-on regression tests passed through v0.3.1.
- New v0.3.2 integration/UI test passed: 415 / 415 cumulative assertions.
