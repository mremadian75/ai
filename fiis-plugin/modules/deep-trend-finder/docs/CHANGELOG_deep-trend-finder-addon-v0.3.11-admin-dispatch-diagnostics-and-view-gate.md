# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.11

**Slug:** `0.3.11-admin-dispatch-diagnostics-and-view-gate`
**Date:** 2026-05-25
**Type:** Upgrade on v0.3.10 — admin self-debug surface + view-gate diagnostics. No rewrite. Add-on only.

## What's new

### 1. View-gate diagnostics (the v0.3.9 brief asked for this; it was finally wired)
`FIDTF_Relevance_Filter::score()` now distinguishes three TikTok view-count states:
- **`view_count_missing = true`** — the normalized item has no `metrics.views`. Item is **no longer hard-rejected**; the rest of the scoring decides per existing policy, but the flag is preserved on the scored item so admins can see how many evidence rows landed without view counts.
- **`below_min_views = true`** — view count is present but below `tiktok_min_views`. Item is rejected as before.
- **passed view gate** — view count is present and ≥ threshold.

New `FIDTF_Relevance_Filter::summarize_view_diagnostics()` aggregates these flags into `{view_count_missing, below_min_views, view_gate_passed}` for the run record.

### 2. Persistent per-job dispatch trace (no DB schema change)
`FIDTF_Source_Job_Service::ingest_items()` writes view diagnostics to a per-job transient `fidtf_view_diag_{job_id}` (24h TTL). New accessor `FIDTF_Source_Job_Service::view_diagnostics_for_job($job_id)`.

### 3. Admin-visible per-job dispatch diagnostic
The REST controller's `dispatch_diagnostic` now contains `view_diagnostics: {view_count_missing, below_min_views, view_gate_passed, configured_min_views}` on top of v0.3.10's fields (`request_attempted`, `outcome_reason`, `provider_mode_used`, `addon_version`, `sanitized_actor_input`, `output_counts`).

### 4. Admin-only frontend diagnostic panel
`assets/js/fidtf-frontend.js` gains `dispatchDiagnosticPanelHtml()` rendering the diagnostic per job. The panel is only injected when `FIDTF.isAdmin === true`. It shows: addon version, request attempted, configured/used provider modes, outcome reason, output counts, view-gate counts, first 8 keys of the sanitized actor input preview.

### 5. CSS for the diagnostic panel
`assets/css/fidtf-frontend.css` — `.fidtf-dispatch-diagnostic` and `.fidtf-diagnostic-grid` styles. Subtle dashed-blue box, not visually intrusive for non-admins (gated server-side via `isAdmin`).

### 6. Admin settings copy updated
Settings header now describes the v0.3.11 surface.

## What did NOT change

- Core plugin: untouched.
- DB schema: untouched (the dispatch trace uses a transient).
- Shortcode `[future_island_deep_trend_finder]`: untouched.
- Scraptik / Clockworks input contracts (v0.3.9 → v0.3.10): unchanged.
- waitForFinish=0 async-dispatch pattern (v0.3.10): unchanged.
- Other providers (Instagram, Reddit, Google Trends, News, AI Research): planned-only as before.
- The 20,000-view default and admin-configurable min_views input: unchanged (already in v0.3.9; this release surfaces it in the diagnostic).

## Tests

- New `tests/test-fidtf-v0311-admin-dispatch-diagnostics-and-view-gate.php` — 27 new assertions covering:
  - view_count_missing flag set without hard rejection
  - below_min_views flag set when threshold exceeded
  - summarize_view_diagnostics aggregation
  - ingest_items persists view diag transient
  - REST controller exposes `view_diagnostics` with `configured_min_views`
  - Admin settings UI exposes `tiktok_min_views`
  - Frontend `dispatchDiagnosticPanelHtml` exists and gates on `isAdminContext()`
  - CSS class `.fidtf-dispatch-diagnostic` exists
- Updated version-acceptance lists in 11 older test files to accept `0.3.11-…`.

19 test files; all pass under `php tests/test-fidtf-v*.php`. PHP lint clean on all 47 PHP files.

## Acceptance behaviour for admins

After installing v0.3.11 and triggering a TikTok run while logged in as an admin:
1. The frontend run-status card renders a "Admin dispatch diagnostic" expandable section under each TikTok job row (non-admin users do not see it).
2. The panel shows: Version, Request attempted yes/no, Provider mode (configured), Provider mode used, Outcome reason, Output counts (dataset rows · flattened · normalized · relevant), View gate (missing N · below N · passed N · threshold N), and the first 8 keys of the sanitized actor input.
3. If the actor returned posts but most lacked view counts, the View-gate line shows e.g. `missing 18 · below 2 · passed 5 (threshold 20000)` — telling the admin exactly why "Relevant" count is low.

## Remaining risks

- Same as v0.3.10. The transient TTL is 24h; runs older than that lose their view diagnostic. The persisted per-job columns (raw/normalized/relevant counts) remain accurate indefinitely.
- The diagnostic panel writes ~1 KB of HTML per job; on the rare 50-job run this is still tiny.
- No PII risk: the diagnostic strips tokens at the REST boundary and only renders pre-sanitised data.
