# Future Island — Private Pilot Readiness Checklist

Use this checklist to take the v0.9.33 build into a controlled private pilot.
Legend: `[x]` verified in this environment · `[ ]` must be confirmed on live WP staging.

## A. Build integrity (verified here)

- [x] PHP lint clean — 250 files, 0 errors, 0 timeouts.
- [x] Full test runner green — 246 / 246.
- [x] Timeout-safe runner green — 246 / 246, 0 timeouts.
- [x] JS productization tests — 40 / 40.
- [x] UI bugfix regression (v0.9.32) — 34 / 34.
- [x] Revalidation regression (v0.9.33) — 17 / 17.
- [x] No new runtime dependency added; no DB migration introduced.

## B. Browser/UI (verified in real browser engine; re-confirm in live WP)

- [x] Social Intelligence results: word-level wrapping, readable cards, no char-by-char.
- [x] Object Flow: status chips, no mid-word splits, mobile stacking.
- [x] Metric/KPI cards: readable labels, responsive grid.
- [x] Disabled strategic actions show a specific reason/helper.
- [x] Intelligence Map: graph + readable lineage table on desktop.
- [x] Provider Settings / Plans & Access: human labels beside raw keys.
- [x] Overview missing-token warning explains what still works.
- [x] Insight detail: Observed vs Inferred, confidence + risk visible.
- [x] Provider Ingestions Ledger / Operator QA status chips render as pills.
- [x] Command Room run-timeline filters are spaced; rows stack on mobile.
- [ ] Same screens confirmed inside a **live WordPress admin** (admin chrome, admin bar,
      core CSS) at desktop + mobile.
- [ ] Long admin tables confirmed free of admin-bar/footer overlap on a real site.

## C. Security / data boundary (verified here; re-confirm with real data)

- [x] No token values, HMAC secrets, signatures, raw callback bodies, cookies, API keys,
      or bearer tokens appear in any user-facing snippet/CSS/JS surface.
- [x] Redaction copy present: "Token values are never rendered", "signature redacted",
      "No raw callback body, cookie, token, secret, or signature is shown".
- [ ] Confirm redaction holds with **real** provider rows on staging (not just fixtures).
- [ ] Confirm capability checks on every admin page (operator vs admin) on the live site.

## D. Product loop (confirm end-to-end on staging)

Walk the canonical route and confirm a record is written at each step:

```text
Manual/URL Source -> Run -> Signal Item -> Insight -> Brief -> Draft
  -> Review decision -> Memory record -> Usage event -> Decision Report
```

- [ ] Brand & Market X-Ray creates a canonical Run (no external crawl required).
- [ ] Signed callback simulator path validates and writes canonical records.
- [ ] Disabled actions stay blocked until lifecycle requirements are met, with reasons.
- [ ] Review decisions (approve/reject/revise) are recorded before memory is trusted.
- [ ] Usage/credit events stay idempotent (reserve→settle), zero-cost in beta.
- [ ] Decision Report renders from real lineage and is redacted.

## E. Operational readiness

- [ ] Pilot operator list defined; each has the correct WP role/capability.
- [ ] Rollback path confirmed (see `ROLLBACK_NOTES_REAL_BROWSER_UI_BUGFIX.md`).
- [ ] Operator guide shared (`PRIVATE_PILOT_OPERATOR_GUIDE.md`).
- [ ] Feedback capture channel agreed (where operators log issues/screens).
- [ ] Credentials decision made: pilot can run manual + simulator paths **without**
      OpenAI/Apify; connect only if AI analysis / provider runs are in pilot scope.

## F. Explicitly out of pilot scope (do not build yet)

- [ ] Real third-party provider integrations as a default.
- [ ] Direct publishing / social scheduler behavior.
- [ ] Heavy graph product, multi-LLM orchestration, predictive trends.
- [ ] Billing/plan expansion beyond existing usage ledger.

## Go / No-go

**Go for private pilot** once every `[ ]` in sections B–E is checked on a live
WordPress staging site. The code, automated tests, and real-browser rendering gates
(sections A–C verified items) already pass.
