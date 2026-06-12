# Deep Trend Finder Add-on Architecture — v0.3.1

## Product boundary

This add-on remains a focused Deep Trend Finder module inside Future Island. It is not a generic scraper dashboard and not a direct publishing tool. It should collect and normalize evidence, then allow the product layer to interpret signals without inventing unavailable evidence.

## Source execution status

| Source family | v0.3.1 status |
|---|---|
| TikTok | Live bridge supported through Core Apify client, direct Apify fallback, or external filter mode. |
| Instagram | Planned-only. |
| Reddit | Planned-only. |
| Google Trends | Planned-only. |
| Google News | Planned-only. |
| Other social/search sources | Not added in this release. |

## Provider modes

### `core_apify_client`

Preferred mode when the Future Island Core plugin is active and exposes:

- `VES_Apify_Client::request()`
- `VES_Apify_Client::fetch_run()`
- `VES_Apify_Client::fetch_items()`

The add-on does not read the Core Apify token directly. It delegates request execution to Core.

### `apify_bridge`

Direct add-on fallback mode using the add-on's own server-side Apify token setting. This is useful for isolated QA but bypasses Core-level cost/concurrency controls.

### `external_filter`

Compatibility mode for custom integrators. It requires a server-side handler attached to `fidtf_tiktok_core_apify_client`.

## Core adapter flow

1. Build canonical TikTok actor input from the user request.
2. Detect required Core static methods.
3. Optionally acquire a Core dispatch slot if Core exposes slot helpers.
4. Start actor run with `VES_Apify_Client::request('POST', $url, $actor_input)`.
5. Extract `provider_run_id` from known response shapes.
6. If the run is complete, fetch items with `VES_Apify_Client::fetch_items($run_id, $limit)`.
7. If the run is queued/running, store `provider_run_id` for later refresh.
8. On refresh, call `VES_Apify_Client::fetch_run($run_id, 0)` then `fetch_items($run_id, $limit)` when complete.

## Stuck-job prevention

Provider responses that report queued/running without a provider run ID are treated as invalid. This prevents source jobs from remaining in a permanent queued state that cannot be refreshed.

## Refresh behavior

Queued/running TikTok jobs that already have a provider run ID can be refreshed even if live dispatch is later disabled. Dispatching new provider jobs still depends on admin settings.

## UI and accessibility contract

The add-on UI avoids undefined `sendPrompt(...)` and inline `onclick` handlers in production templates. The frontend grid uses responsive `auto-fit` behavior and visible focus states.

## Evidence truthfulness

The add-on does not claim deep video, audio, transcript, frame, or visual analysis unless real provider fields are present. TikTok evidence is metadata/caption/hashtag/statistics-led by default.
