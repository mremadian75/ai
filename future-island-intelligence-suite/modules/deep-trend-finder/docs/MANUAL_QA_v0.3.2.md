# Manual QA — Deep Trend Finder Add-on v0.3.2

1. Install and activate Core v0.9.31.8-p4.
2. Install and activate Add-on v0.3.2.
3. Open WordPress admin → Settings → Deep Trend Finder.
4. Confirm the frontend page block shows:
   - View page
   - shortcode `[future_island_deep_trend_finder]`
   - Recreate/update page action
5. Open the auto-created `/deep-trend-finder/` page.
6. Confirm the page renders:
   - Intelligence Hero
   - Research Brief Panel
   - Source Plan / Signal Map
   - Progressive Rows
   - Evidence Grid
   - Report Shell
7. Submit a planned run with live dispatch disabled.
8. Confirm no provider call is made and the UI says planned/waiting for source integration.
9. Enable live dispatch + TikTok bridge + Core Apify client mode.
10. Submit a TikTok-included run and confirm only TikTok attempts a live provider run.
11. Confirm Instagram, Reddit, Google Trends, Google News, and AI Research stay planned-only.
12. Load report and evidence. Confirm empty states are honest when no evidence exists.
13. Check browser console: no `sendPrompt` error, no inline handler error, no Chart.js/CDN error.
14. Check mobile width around 375px and 768px: cards stack and controls remain usable.
15. Confirm no Apify token or actor internals are exposed to normal users.
