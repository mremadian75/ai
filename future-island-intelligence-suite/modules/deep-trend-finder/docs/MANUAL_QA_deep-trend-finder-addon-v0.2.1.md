# Manual QA — v0.2.1-planner-contract-hardening

1. Upload and activate the v0.2.1 ZIP.
2. Confirm the plugin version shows `0.2.1-planner-contract-hardening`.
3. Confirm live dispatch remains off by default.
4. Confirm AI planner bridge remains off by default.
5. Create a run with TikTok, Instagram, Reddit, Google Trends, Google News, and AI Research selected.
6. Confirm the frontend shows planner mode and planner notice.
7. Confirm source cards show source strategy, planned terms/hints, hashtags where applicable, and max item limits.
8. Confirm no provider job starts while live dispatch is disabled.
9. Confirm no AI web research claim appears.
10. Confirm normal users do not see raw AI text or bridge diagnostics.
11. Enable debug/admin view and confirm admin-only diagnostics are visible only to admins when present.
12. Confirm credit mode remains `planning_only` when reservation is disabled.
13. Confirm estimated credits include the `ai_planner` row only when AI planner bridge is enabled.
14. Load the report and confirm it says this is a planned run, not an evidence report, when no evidence has been ingested.
15. Confirm no fake trend claims, no fake evidence, and no deep-video/audio/transcript claims appear.
