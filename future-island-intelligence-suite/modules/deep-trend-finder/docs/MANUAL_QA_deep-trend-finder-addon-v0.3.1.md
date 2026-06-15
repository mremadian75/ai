# Manual QA — Deep Trend Finder Add-on v0.3.1

## Install and activation

1. Install Future Island Core `0.9.31.8-p3.1-live-qa-hotfix` or newer compatible Core.
2. Install and activate Deep Trend Finder Add-on `0.3.1-core-apify-client-bridge-hardening`.
3. Confirm no fatal error appears on activation.
4. Confirm a frontend page containing `[future_island_deep_trend_finder]` is created or an existing matching page is reused.

## Admin diagnostics

1. Open the Deep Trend Finder settings screen.
2. Confirm the TikTok provider status box appears.
3. Confirm the status box reports:
   - Core active: yes/no
   - `VES_Apify_Client` available: yes/no
   - `request`, `fetch_run`, `fetch_items` method availability
   - external filter handler availability
   - direct token availability without showing the token
   - recommended provider mode
4. If Core is active and complete, set provider mode to `core_apify_client`.

## Core Apify client mode

1. Ensure the Core plugin has a valid Apify configuration.
2. Enable live dispatch.
3. Enable TikTok live bridge.
4. Submit a TikTok trend request.
5. Confirm the source job enters queued/running with a provider run ID.
6. Refresh the run after the provider completes.
7. Confirm normalized TikTok evidence appears.
8. Confirm no raw Apify token appears in frontend HTML, JS, REST responses, or browser console.

## Disabled dispatch refresh behavior

1. Start a TikTok job and confirm it receives a provider run ID.
2. Disable TikTok live bridge or global live dispatch.
3. Refresh the run.
4. Confirm the existing queued/running job can still refresh or move to a terminal state.
5. Confirm new provider dispatch is blocked while disabled.

## Invalid provider response

1. Simulate a 2xx provider start response with no run ID and no items.
2. Confirm the job fails with `tiktok_provider_invalid_run_response`.
3. Confirm the job does not remain queued forever.

## UI smoke test

1. Open the frontend page on desktop and mobile widths.
2. Confirm the form and status cards remain readable at 320px, 375px, 768px, 1024px, and wide desktop.
3. Confirm focus-visible outlines appear on buttons and links.
4. Confirm no undefined `sendPrompt(...)` errors appear.
5. Confirm no inline `onclick` is required for production add-on UI.

## Truthfulness checks

1. Run a no-evidence request.
2. Confirm the UI/report does not fabricate evidence or insights.
3. Confirm the report does not claim deep video, audio, transcript, frame, or visual analysis unless provider fields truly exist.
