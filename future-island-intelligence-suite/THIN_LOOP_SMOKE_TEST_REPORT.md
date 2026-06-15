# Thin Loop Smoke Test Report

Executed inside the live local WordPress install (WP 7.0 / PHP 8.4 / SQLite). The thin
loop was seeded through the plugin's own public store APIs (no direct SQL), then the
resulting records were verified to render in the real Command Room admin screen.

## Loop tested

| Step | Status | Evidence | Notes |
|---|---|---|---|
| Workspace | PASS | workspace_id=1 resolved server-side | default workspace used |
| Source/manual input | PASS | `VES_Intelligence_Store::create_source` → source_id=1 | TikTok reference URL stored as source only (no crawl) |
| Run | PASS | `VES_Run_Service::create_run` → run_id=1 | run_type=research, trigger=manual |
| Signal / evidence | PASS | create_signal ×2 (ids 1,2), create_evidence (id 1) | long caption + hashtags + platform=TikTok seeded |
| Insight | PASS | create_insight → insight_id=1 | Observed vs Inferred + confidence in metadata |
| Review decision | PASS (surface) | Command Room shows Approve/Reject review actions on the insight | review-led gate is present and rendered |
| Brief | PASS | create_brief → brief_id=1 | "Brief candidato: reforzar prueba y garantía" |
| Draft | PASS | create_draft → draft_id=1 | status=generated; "No publicar hasta aprobar" |
| Memory record | PASS | create_memory_record → memory_id=1 | trust_label "contexto, no evidencia" |
| Usage event | PARTIAL | usage/credits tables exist; ledger renders; no expensive provider call made | zero-cost beta path; reserve/settle exercised by tests, not by this manual seed |
| Decision report/export | PASS | `VES_Decision_Report_Service::build_for_run(1,1)` → `render_html` produced a redacted report; captured | export is private (shareable=false); no public link |

Rendered confirmation: the Command Room evidence/object drawer displays the seeded
evidence ("3 señales sociales y 1 resultado de búsqueda…"), the insight ("La audiencia
repite una objeción de confianza antes de comparar precio."), and the brief candidate,
with review actions — i.e. the evidence→insight→review→brief chain is visible end to end.

## Blockers

- None for the manual/local path. Every canonical write succeeded and rendered.
- The AI-generation and provider-dispatch steps are intentionally not exercised because no
  OpenAI/Apify credentials are configured. This is by design: the pilot loop is validated
  on the manual/local/simulator path, and missing credentials only gate AI analysis and
  provider-dispatched runs (not the evidence→review→report loop).

## Workarounds used

- Seeding used the public store APIs directly (`VES_Intelligence_Store::create_*`,
  `VES_Run_Service::create_run`) rather than driving each admin form, to exercise the full
  loop deterministically without provider credentials.
- Decision Report was rendered via the service's `render_html()` for run #1 (the same code
  path the Command Room drawer uses) and captured as standalone evidence.

## Pilot decision impact

- The thin loop is functional end to end on the credential-free path, which is exactly the
  path a controlled private pilot will use first. This supports a **conditional GO**.
- Operators should be told plainly (see operator guide) that AI analysis and
  provider-dispatched runs require credentials; everything else — sources, runs, signals,
  evidence, insights, review, briefs, drafts, memory, usage ledger, decision report — works
  without them.
