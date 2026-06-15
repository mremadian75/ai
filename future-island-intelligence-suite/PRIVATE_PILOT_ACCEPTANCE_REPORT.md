# Future Island — Private Pilot Acceptance Report

Build: **v0.9.33** (Real Browser Revalidation + Private Pilot Prep)
Decision: **Conditional GO** for a controlled private pilot — conditioned on one live
WordPress staging confirmation pass (see §4).

## 1. Acceptance criteria and status

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Build is syntactically clean | **PASS** | lint 250/0 |
| 2 | Automated tests pass | **PASS** | full 246/246, timeout-safe 246/246, JS 40/0 |
| 3 | Previously broken P0 UI fixed | **PASS (real browser)** | §2; `ui-snippets/real-browser-revalidation/` |
| 4 | P1/P2 UI surfaces acceptable | **PASS (real browser)** | §2; screenshots |
| 5 | No secret/token/signature leakage in UI | **PASS** | security scan; redaction copy present |
| 6 | Evidence-first product loop intact | **PASS (code/snippet)** | disabled gates, review-first copy |
| 7 | Architecture preserved (no new module/dep/migration) | **PASS** | changed-files diff is CSS/HTML/test/docs only |
| 8 | Live WordPress staging visual confirmation | **PENDING** | not hostable in this environment |
| 9 | End-to-end loop on real data | **PENDING** | requires staging + (optional) credentials |

## 2. What was verified in a real browser

A headless Chromium (Playwright) rendered every UI snippet with the **actual shipped
CSS** at 1440/768/375 and measured the DOM. Across **57 combinations**:

- All previously-reported P0/P1/P2 surfaces render correctly.
- Only **2 acceptable flags** remain (375px, on a date and a technical key — non-prose
  tokens allowed to wrap; off by 2–5px).
- Two genuine product defects (admin status chips unstyled; timeline filters touching)
  were found and **fixed**, then re-confirmed green.

This is stronger than static review but is **not** a live WordPress install — see §4.

## 3. What changed in this sprint

Small, additive, reversible:

- `assets/css/fiis-ui-system.css`: base `.fi-status-chip`, ledger status colours,
  `.fi-timeline-filters` spacing.
- 5 evidence snippets corrected to match the live renderers.
- 1 new regression test (`test-v0933-real-browser-revalidation.php`, 17 checks).
- Real-browser evidence bundle under `ui-snippets/real-browser-revalidation/`.

No PHP service contract, REST endpoint, DB schema, capability check, or dependency was
changed. Rollback is limited to reverting the CSS block, the snippet edits, and the new
test (see `ROLLBACK_NOTES_REAL_BROWSER_UI_BUGFIX.md` for the prior-sprint baseline).

## 4. The one remaining gate before pilot

This environment has **no WordPress runtime**. Before onboarding pilot operators, run
one pass on a live WP staging site and confirm, at desktop + mobile:

1. Install/activate the plugin; no activation error or fatal.
2. Re-shoot the previously-broken screens (Social results, Object Flow, metrics,
   Intelligence Map, Provider Settings, Plans & Access, Decision Report) and compare to
   `ui-snippets/real-browser-revalidation/shots/`.
3. Confirm no admin-bar/footer overlap on long admin tables.
4. Confirm redaction holds with **real** provider rows (not fixtures).
5. Walk the canonical loop and confirm a record is written at each step.

If all five pass, this report upgrades to **GO**. If any screen still shows social/Object
Flow wrapping, hidden primary actions, or any secret leakage, fix those first.

## 5. Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| Live WP admin chrome differs from isolated render | Medium | §4 staging pass; admin screens already legible via core `widefat` |
| Hostile theme/plugin overlays sticky content | Low | bottom spacing added; no z-index escalation; confirm on staging |
| Legacy English strings outside validated surfaces | Low | scoped i18n later; not a pilot blocker |
| 8-column lineage table tight on small phones | Low | `overflow-x:auto` wrapper present; date wraps at hyphen only |

## 6. Recommendation

Proceed to the live-WP staging confirmation pass. Do **not** start a new feature sprint
(providers, scheduler, multi-LLM, predictive trends, billing) until the pilot produces
operator feedback. Keep WordPress canonical, keep generation review-gated, keep memory
labeled as context.
