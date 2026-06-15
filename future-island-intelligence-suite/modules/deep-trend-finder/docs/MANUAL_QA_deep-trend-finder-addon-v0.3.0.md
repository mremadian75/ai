# Manual QA — Deep Trend Finder Add-on v0.3.0

## Pre-checks

1. Install and activate the mother/core plugin if required by your environment.
2. Install and activate `future-island-deep-trend-finder-addon` v0.3.0.
3. Open WordPress Admin → Settings → Deep Trend Finder.
4. Confirm the page says v0.3.0 and shows the TikTok live bridge section.

## Scenario 1 — Safe default, no live provider calls

Settings:

- Global live dispatch: off
- TikTok live bridge: off

Steps:

1. Create a run with TikTok selected.
2. Check the source jobs.

Expected:

- Run status is planned/waiting for sources.
- TikTok job does not start a provider run.
- No provider run ID is created.
- Frontend explains that provider bridge is not enabled.

## Scenario 2 — Global live on, TikTok bridge off

Settings:

- Global live dispatch: on
- TikTok live bridge: off

Steps:

1. Create a TikTok run.

Expected:

- TikTok job does not run live.
- Diagnostic is `tiktok_live_bridge_disabled`.
- No provider run ID is created.
- Instagram/Reddit/Google sources do not run live either.

## Scenario 3 — TikTok bridge enabled but no provider configured

Settings:

- Global live dispatch: on
- TikTok live bridge: on
- Provider mode: external filter or direct bridge without token

Expected:

- TikTok returns `tiktok_provider_unavailable`.
- UI shows honest diagnostic.
- No fake evidence appears.

## Scenario 4 — Direct Apify bridge starts a queued run

Settings:

- Global live dispatch: on
- TikTok live bridge: on
- Provider mode: direct Apify bridge
- Actor ID: configured TikTok actor
- Token: configured server-side

Steps:

1. Create a run with TikTok selected.
2. Watch the job status after run creation.
3. Refresh/poll the run.

Expected:

- TikTok provider run starts asynchronously.
- Status becomes queued or running.
- Provider run ID is visible to admins only.
- Frontend says TikTok collection started.
- Token is never visible in page source or browser network payload except as a server-side API request header from WordPress to provider.

## Scenario 5 — TikTok returns items but relevance filter removes all

Steps:

1. Use a provider/mock that returns TikTok items unrelated to the run objective.
2. Poll the run.

Expected:

- Job status becomes `completed_no_relevant_evidence`.
- Raw/normalized counts may be greater than zero.
- Relevant count is zero.
- Frontend says TikTok returned data but no items passed relevance filtering.
- Report does not pretend a final strategic conclusion exists.

## Scenario 6 — TikTok returns relevant items

Steps:

1. Use a provider/mock that returns TikTok items matching the brief/keywords.
2. Poll the run.
3. Click Show evidence.

Expected:

- Job status becomes completed.
- Run status becomes evidence_ingested.
- Relevant evidence cards appear.
- Media URL may be shown if provider supplied it.
- Evidence quality still states no transcript/deep video unless provider supplied transcript/subtitle fields.

## Scenario 7 — Non-TikTok sources stay planned-only

Settings:

- Global live dispatch: on
- TikTok live bridge: on

Steps:

1. Create a run with Instagram, Reddit, Google Trends, and Google News.

Expected:

- These sources remain planned-only or waiting for their future bridges.
- They do not call TikTok bridge.
- They do not call a generic scraping bridge.
- No fake results are displayed.

## Regression checks

Run from the plugin root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/fidtf-frontend.js
php tests/test-fidtf-v030-tiktok-provider-bridge.php
```

Expected:

- PHP lint passes.
- JS syntax check passes.
- v0.3.0 tests pass.
