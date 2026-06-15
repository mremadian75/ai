# Core + Add-on Integration QA v0.3.2

## Core shell
- Core version shows `0.9.31.8-p4-deep-trend-addon-integration`.
- Core loads `class-ves-deep-trend-addon-bridge.php` before AJAX controller.
- If add-on is active, Core navigation shows `Deep Trend Finder` and links to the add-on page.
- If add-on is inactive, Core navigation shows a locked Trend Finder state.
- Normal Core shortcode shell does not include `_trend-finder-form.php`.

## Deprecated legacy endpoints
Verify old mutating endpoints return deprecated envelopes and do not reserve credits or call providers:
- `ves_trends_start`
- `ves_trends_brief_create`
- `ves_trend_monitors_save`

Expected response shape:

```json
{
  "success": false,
  "deprecated": true,
  "replacement": "future_island_deep_trend_finder",
  "addon_active": true,
  "addon_url": "..."
}
```

Read/cleanup endpoints may still return data for in-flight legacy runs but include `deprecated:true`.

## Add-on module contract
- `fidtf_module_info()` returns `key`, `label`, `shortcode`, `page_id`, `url`, `status`, `requires_core`, and `capability`.
- `fidtf_get_frontend_url()` returns the stored page URL or `/deep-trend-finder/` fallback.
- Core bridge can consume module info if helpers exist, otherwise falls back to shortcode detection.

## Memory
- Old Core records with `trend_finder` / `ves_trend` are labelled `Legacy Trend Finder report`.
- New add-on records use `source_type='deep_trend_finder'` and label `Deep Trend Finder`.

## Non-goals verified
- No new live source was added.
- No deep video/audio/transcript pipeline was added.
- No fake charts, fake evidence, or fake final recommendations were added.
