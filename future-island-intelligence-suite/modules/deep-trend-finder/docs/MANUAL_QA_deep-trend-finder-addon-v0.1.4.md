# MANUAL_QA_deep-trend-finder-addon-v0.1.4

## Install

1. Upload `future-island-deep-trend-finder-addon-v0.1.4-bridge-safety.zip` in WordPress.
2. Activate the add-on.
3. Confirm the mother Future Island plugin is active.
4. Add `[future_island_deep_trend_finder]` to a test page.

## Admin settings checks

1. Open Settings → Deep Trend Finder.
2. Confirm the intro copy says v0.1.4 bridge-safety.
3. With live dispatch off, confirm: “Live dispatch is disabled. Runs are planned only and no provider calls will be made.”
4. Enable live dispatch temporarily and confirm the provider/API cost warning appears.
5. Confirm the AI research bridge checkbox exists and is off by default.
6. Confirm the deep-video hard-disabled warning appears.
7. If credit reservation is off, confirm the planning-estimate warning appears.
8. Turn live dispatch back off before normal QA.

## Frontend planned run checks

1. Submit a brief with TikTok, Instagram, Reddit, Google Trends, Google News, and AI Research enabled.
2. Confirm a run is created.
3. Confirm source rows show planned/waiting provider states.
4. Confirm the frontend says: “Planning estimate only. No final credits were settled.”
5. Load report.
6. Confirm the report says the run is planned and not an evidence report.
7. Confirm no fake insights, trend claims, deep-video claims, or AI-web-research claims appear.

## Safety checks

1. With live dispatch disabled, confirm no external provider job starts.
2. With AI research bridge disabled, confirm no AI research job starts.
3. Confirm normal users do not see actor/provider IDs in source rows.
4. Confirm admins can see actor/provider IDs where intended.
5. Confirm unknown/invalid status values do not appear in REST responses.

## Regression checks

Run from the plugin directory:

```bash
php tests/test-fidtf-v010.php
php tests/test-fidtf-v011-foundation-hardening.php
php tests/test-fidtf-v012-foundation-corrections.php
php tests/test-fidtf-v013-async-readiness.php
php tests/test-fidtf-v014-bridge-safety.php
```
