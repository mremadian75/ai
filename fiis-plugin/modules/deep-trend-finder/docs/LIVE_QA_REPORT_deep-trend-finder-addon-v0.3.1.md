# Live QA Report — Deep Trend Finder Add-on v0.3.1

## Scope

This QA pass verifies the add-on patch against local static and unit-style checks. It does not perform a live Apify network run because no real credentials should be used in automated tests.

## Baselines inspected

- Core: `future-island-v0.9.31.8-p3.1-live-qa-hotfix`
- Add-on: `0.3.0-tiktok-provider-bridge`
- Target: `0.3.1-core-apify-client-bridge-hardening`

## Confirmed Core contract

The Core plugin exposes static `VES_Apify_Client` methods:

- `request($method, $url, $body = null, $attempt = 1)`
- `fetch_run($run_id, $wait_for_finish = 20)`
- `fetch_items($run_id, $limit = 150)`

v0.3.0 did not match this contract. v0.3.1 adds an adapter that does.

## Checks run

- PHP lint on Core PHP files: passed.
- PHP lint on add-on PHP files: passed.
- JavaScript syntax check on Core JS files: passed.
- JavaScript syntax check on add-on frontend JS: passed.
- Full add-on regression tests through v0.3.1: passed.
- Full Core test suite discovered in the ZIP: passed, with one pre-existing PHP warning in a Core test file.
- Static UI grep for undefined `sendPrompt(...)` in production add-on UI: clean except regression test assertions.
- Static UI grep for inline `onclick=` in production add-on templates: clean except regression test assertions.
- Static grep for raw Chart.js CDN in production add-on UI: clean except regression test assertions.

## Remaining live QA required

- Real admin configuration test with a valid Core Apify token.
- Real TikTok actor run through Core client.
- Refresh after provider completion.
- Browser-level check that no token appears in HTML, REST response, console, or JS bundle.
- Confirmation that Core shell navigation should or should not link to the auto-created Deep Trend Finder page.

## Known non-blocker

The add-on now auto-creates/reuses a frontend page. It does not modify the Core navigation shell. If the old Core Trend Finder menu must route to this add-on page, that should be handled as a separate Core shell patch.
