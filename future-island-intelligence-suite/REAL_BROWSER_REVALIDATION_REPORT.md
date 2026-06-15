# Future Island — Real Browser Revalidation + Private Pilot Prep — Sprint Report

Sprint: **Future Island Real Browser Revalidation + Private Pilot Prep**
Base build: `future-island-real-browser-ui-bugfix-patched` (v0.9.32)
Patch tag in this sprint: **v0.9.33**

## 0. Honesty header — what "real browser" means here

This sprint used a **real browser engine** (headless Chromium 1194 driven by
Playwright 1.56) to render every UI snippet **with the actual shipped plugin CSS**
at desktop (1440), tablet (768) and mobile (375) widths, then **measured** the DOM
for the exact browser-observed bugs (character-by-character columns, mid-word breaks
on prose, narrow cards) and captured screenshots.

This is materially stronger than code/static inspection: it exercises the real CSS
cascade, grid sizing, and text wrapping in a real layout engine.

It is **not** a full live WordPress staging install. This environment has no WordPress
runtime, database, admin chrome, or WordPress core admin CSS. So:

- **Verified (real browser):** the plugin's CSS + snippet markup render correctly in a
  real browser engine across three viewports.
- **Not verified here:** plugin activation inside a live WordPress, WP admin chrome,
  core `widefat`/admin-bar interaction, multi-theme/hostile-plugin overlap, and
  non-Chromium engines. These still require one pass on a real WP staging site.

Methodology, harness, screenshots and machine-readable measurements are committed under
`ui-snippets/real-browser-revalidation/`.

## 1. Executive summary

- Re-validated the previously broken screens in a real browser. **All P0/P1/P2
  surfaces from the prior bug list render correctly** (desktop + mobile).
- The deeper real-browser pass surfaced **two genuine defects the v0.9.32 patch did
  not reach**, plus **evidence-snippet fidelity drift**. All were fixed with a small,
  additive, low-risk patch.
- No architecture change, no new module, no provider connection, no new dependency,
  no n8n requirement, no DB migration. WordPress remains the canonical record layer.
- Verification: PHP lint 250/0, full test runner **246/246**, timeout-safe runner
  **246/246**, JS productization 40/0, prior UI bugfix test 34/34, new revalidation
  regression test **17/17**. Security scan of user-facing surfaces: clean.

## 2. P0 / P1 / P2 revalidation results (real browser)

| ID | Screen | Expected | Result (Chromium, desktop+mobile) |
|---|---|---|---|
| P0.1 | Social Intelligence results | readable cards, word-level wrapping, no char-by-char | **PASS** — 4-col desktop / 1-col mobile, prose wraps by word, URLs wrap safely |
| P0.2 | Object Flow / lifecycle | status chips, no mid-word splits, mobile stacking | **PASS** — COMPLETADO / EN CURSO / PENDIENTE render as chips; steps stack on mobile |
| P0.3 | Metric / KPI cards | enough width, no split platform names | **PASS** — "Uso del run" one line; responsive KPI grid |
| P1.1 | Spanish/English copy | Spanish-first visible labels | **PASS** on validated surfaces (confidence alta/media/baja, Borrador, Revisión de evidencia) |
| P1.2 | Disabled actions | visible reason/helper | **PASS** — "Requiere insight aprobado." shown in-button (`::after`) and as helper text |
| P1.3 | Intelligence Map | graph + lineage list | **PASS** — operator summary + 8-column lineage table readable on desktop |
| P1.4 | Admin bar/footer overlap | bottom spacing, no z-index escalation | **PASS (code)** — `padding-bottom:96px` present; live admin-bar overlap still needs a WP pass |
| P2.1 | Plans & Access / Credits & Limits | human labels + raw keys | **PASS** — human labels with mono key metadata |
| P2.2 | Provider Settings | human labels, module/use-case, token status, no values | **PASS** — "Token values are never rendered." present |
| P2.3 | Overview missing-token warning | explains what still works | **PASS** — explains OpenAI/Apify purpose + what works without them |
| P2.4 | Insight / detail | Observed vs Inferred, confidence/risk | **PASS** — Observado/Inferido + Confianza/Riesgo chips |

## 3. New defects found by real-browser rendering (and fixed)

### 3.1 Status chips unstyled on admin screens (real product bug)

`.fi-status-chip` was styled **only** under `.ves-wrap.fi-command-room`,
`.ves-wrap .fi-provider-admin`, and `.ves-wrap .fi-beta-qa`. But the real admin
renderers emit `class="wrap fi-provider-admin"` and `class="wrap fi-beta-qa ..."` —
**with no `.ves-wrap` ancestor**. So on the **Provider Ingestions Ledger** and
**Operator QA** screens, statuses like `partial` rendered as **plain text**, not pills.

Fix: a context-independent base `.fi-status-chip` style (+ graceful ledger status
colours). Additive and low-specificity — the existing `.ves-wrap`-scoped rules stay
authoritative inside the Command Room, so nothing changes there.

### 3.2 Timeline filter chips touching (real product bug)

`.fi-timeline-filters` had **no CSS rule anywhere**, so the Command Room run-timeline
filter chips rendered touching: `all eventswarningsproviderreview`.

Fix: `.fi-timeline-filters { display:flex; flex-wrap:wrap; gap:8px; }`.

### 3.3 Evidence-snippet fidelity drift (evidence accuracy)

The shipped evidence snippets had drifted from the real renderers, so rendering them
under-represented the live UI:

- `intelligence-map-lineage-list.html` used `<table class="fiis-imap-lineage">`, but the
  live JS renderer emits `<table class="fiis-imap-table">` (which carries cell padding,
  `nowrap` headers, responsive cells, and an `overflow-x:auto` wrapper). Corrected.
- `run-timeline.html` and `command-room.html` omitted the real
  `wrap ves-wrap fi-command-room` wrapper. Wrapped to match the renderer; the run
  timeline now correctly stacks rows on mobile and the Command Room shows its intended
  design.
- `operator-qa.html` / `provider-ingestion-ledger.html` now carry the real
  `wrap fi-...` root classes.

These are HTML-evidence corrections; the live PHP/JS renderers already emitted the
correct markup.

## 4. Remaining measured flags (acceptable)

Across 57 measured snippet×viewport combinations, **2 flags remain, both at 375px on
non-prose technical tokens**, off by rounding-level margins:

- `2026-06-15` in the 8-column lineage table (content box 56px vs ~61px needed; a date
  breaks at a hyphen, not character-by-character).
- `google_search` technical key rendered as mono metadata (85px vs 87px; technical keys
  are explicitly allowed to wrap per spec).

These are within the "URLs/tokens may wrap safely" allowance and are not prose
character-by-character breaks. They are documented rather than over-engineered, to keep
the patch minimal. (See `ui-snippets/real-browser-revalidation/measurements.json`.)

## 5. Changed files

| File | Change | Why |
|---|---|---|
| `assets/css/fiis-ui-system.css` | Added base `.fi-status-chip`, ledger status colours, `.fi-timeline-filters` spacing (v0.9.33 block) | Fix 3.1 + 3.2 |
| `ui-snippets/real-browser-ui-bugfix/intelligence-map-lineage-list.html` | Table class `fiis-imap-lineage` → `fiis-imap-table` | Fix 3.3 (match live renderer) |
| `ui-snippets/run-timeline.html` | Wrapped in `wrap ves-wrap fi-command-room` | Fix 3.3 |
| `ui-snippets/command-room.html` | Root carries `ves-wrap` co-class | Fix 3.3 |
| `ui-snippets/operator-qa.html` | Root → `wrap fi-beta-qa fi-operator-qa-screen` | Fix 3.3 |
| `ui-snippets/provider-ingestion-ledger.html` | Root → `wrap fi-provider-admin` | Fix 3.3 |
| `tests/test-v0933-real-browser-revalidation.php` | New regression test (17 checks) | Lock 3.1–3.3 |
| `ui-snippets/real-browser-revalidation/*` | Harness + measurements + 38 screenshots | Real-browser evidence |

## 6. Product alignment (preserved)

- Evidence-first, generation-second: preserved (no generation-first surface added).
- WordPress canonical record layer: preserved.
- Orchestration-agnostic / optional n8n: preserved.
- HMAC/provider security boundary: preserved; no token/secret/signature/payload
  rendered (independently scanned).
- Memory remains context, not evidence: preserved in copy.

## 7. Verification commands (all run in this environment)

```bash
bin/lint-php.sh --core --timeout-per-file=10            # 250 files, 0 errors, 0 timeouts
php tests/test-v0932-real-browser-ui-bugfix.php         # 34 / 34
php tests/test-v0933-real-browser-revalidation.php      # 17 / 17
node tests/test-fiis-signal-productization.js          # 40 passed, 0 failed
php tests/test-ves-intelligence-map.php                 # 36 passed, 0 failed
FIIS_TEST_TIMEOUT=20 bin/test-all-timeout-safe.sh -q   # 246 / 246, 0 timeouts
bin/test-all.sh -q                                     # 246 / 246
# Real browser render (Chromium via Playwright):
( cd ui-snippets/real-browser-revalidation && \
  PLAYWRIGHT_BROWSERS_PATH=<pw> node render-harness.mjs ) # 57 combos, 2 acceptable flags
```

## 8. Pilot readiness decision

- **Ready for controlled private pilot after one fresh live-WordPress staging pass:**
  yes. Code, automated tests, and real-browser rendering all pass; the only gate left
  is confirming the same screens inside a live WP install (admin chrome / admin-bar /
  core CSS), which this environment cannot host.
- **Production-ready:** no (private pilot only).
- **Do not build yet:** real provider integrations, social scheduler, publishing-first
  flows, heavy graph product, multi-LLM orchestration, predictive trends, billing
  expansion.

See `PRIVATE_PILOT_READINESS_CHECKLIST.md`, `PRIVATE_PILOT_OPERATOR_GUIDE.md`, and
`PRIVATE_PILOT_ACCEPTANCE_REPORT.md`.
