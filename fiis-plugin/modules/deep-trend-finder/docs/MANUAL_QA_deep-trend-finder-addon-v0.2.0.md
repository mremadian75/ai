# MANUAL_QA_deep-trend-finder-addon-v0.2.0

1. Upload `future-island-deep-trend-finder-addon-v0.2.0-ai-planner-bridge.zip`.
2. Activate the add-on with the Future Island mother plugin active.
3. Open Settings → Deep Trend Finder.
4. Confirm the page mentions `v0.2.0 ai-planner-bridge`.
5. Confirm AI planner bridge is disabled by default.
6. Confirm the warning says local fallback planner will be used and no planner model API call will be made.
7. Create a frontend run with TikTok, Google News, and AI Research selected.
8. Confirm the plan is created with `planner_mode=local_fallback`.
9. Confirm no live provider jobs are started.
10. Confirm source jobs remain planned/waiting for provider bridge.
11. Confirm credit mode is `planning_only` unless credit reservation is explicitly enabled.
12. Enable AI planner bridge only if a compatible mother-plugin planner client exists.
13. Run again and confirm invalid/unavailable AI bridge falls back safely, without fatal errors.
14. Confirm normal users do not see raw AI text, provider actor IDs, or provider mechanics.
15. Confirm the no-evidence report does not claim live scraping, AI web research, or deep video analysis.
