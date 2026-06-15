# Manual QA — v0.2.2-source-job-contract-hardening

1. Upload and activate the ZIP.
2. Confirm the version is `0.2.2-source-job-contract-hardening`.
3. Keep live dispatch disabled.
4. Keep AI planner bridge disabled unless specifically testing estimate behavior.
5. Create a Deep Trend Finder run from the shortcode.
6. Confirm no provider/API job starts.
7. Confirm source rows show source-specific plan summaries.
8. Confirm TikTok shows queries, hashtags, creator/format hints, strategy, and limit.
9. Confirm Reddit shows queries, subreddits, time range, strategy, and limit.
10. Confirm Google Trends shows seed keywords, related query hints, geo, time range, and limit.
11. Confirm AI Research shows research questions and assumptions to test.
12. Confirm normal users do not see provider or actor IDs.
13. Confirm admin users can see provider/actor IDs if inspecting REST output.
14. Load the report and confirm local planning and AI planner estimate are separated.
15. Confirm the report still says no live scraping and no AI web research were executed.
16. Confirm no deep-video/audio/transcript claim appears.
17. Confirm empty runs do not create fake evidence, fake insights, or final trend claims.
18. Next development should move to `v0.3.0-tiktok-provider-bridge` only.
