# Deep Trend Finder Add-on Architecture v0.3.2

## Product role
The add-on is the only active Trend Finder runtime. Core remains the SaaS shell: navigation, auth context, credits, memory, diagnostics, and Apify infrastructure. The add-on owns the Deep Trend Finder page, source planning, TikTok bridge, evidence filtering, and report shell.

## Integration contract
The add-on exposes module metadata through:

```php
fidtf_module_info();
fidtf_get_frontend_page_id();
fidtf_get_frontend_url();
add_filter('future_island_register_external_modules', ['FIDTF_Module_Info', 'register_via_filter']);
```

The module payload contains:

```json
{
  "key": "deep_trend_finder",
  "label": "Deep Trend Finder",
  "shortcode": "future_island_deep_trend_finder",
  "page_id": 123,
  "url": "...",
  "status": "active|core_missing",
  "requires_core": true,
  "capability": "read"
}
```

## Page lifecycle
Activation calls `FIDTF_Plugin::maybe_create_frontend_page()`.

Rules:
- Create `/deep-trend-finder/` only if missing.
- Reuse stored `fidtf_page_id` when valid.
- Reuse existing slug page when found.
- Do not overwrite a user-edited page if shortcode is missing.
- Store `fidtf_page_shortcode_missing=1` when a page exists without `[future_island_deep_trend_finder]`.
- Admin can explicitly recreate/update the page through a nonce + `manage_options` action.

## Runtime source policy
- TikTok may run live only when global live dispatch and TikTok live bridge are both enabled.
- Core Apify Client mode delegates execution to `VES_Apify_Client`.
- Direct Apify mode remains fallback/debug.
- Instagram, Reddit, Google Trends, Google News, and AI Research remain planned-only in this release.

## Memory policy
Reports are stored through Core memory when available:

```php
VES_Memory_Records::save_record([
  'memory_type' => 'research_summary',
  'source_type' => 'deep_trend_finder',
  'source_id' => (string) $run_id,
]);
```

Fallback memory paths are wrapped in try/catch and do not fatal if Core memory is unavailable.

## UI policy
The shortcode renders a structured intelligence workspace, not a raw scraper form. It separates:
- brief input
- source planning
- source progress
- evidence display
- report interpretation

The UI never claims evidence exists before it is collected. Charts and recommendations are explicitly deferred until real evidence exists.
