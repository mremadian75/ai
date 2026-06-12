# Future Island v0.1 — Pilot Readiness Acceptance Criteria

A v0.1 **controlled pilot** may start only when every criterion below holds.
Criteria are binary — partially met is not met. "Verified" means statically
proven in the suite (`bin/test-all.sh`) **and** observed live on staging per
`LIVE-STAGING-VALIDATION-CHECKLIST.md`. The in-product gate is
**Tools → FI Pilot Readiness**, which computes (never asserts) every gate and
answers: *"Can we run a controlled pilot now? If not, what is missing?"*

## 0. Who the pilot is for

- 1–3 trusted operators (internal team or one friendly client team) doing real
  cultural-tech marketing intelligence work in ONE workspace.
- People willing to record one line of feedback per reviewed object.
- NOT for: self-serve signups, unsupervised users, production client data
  without a backup, or anyone promised "AI generation" (execution stays OFF).

## 0b. Workflows under test

The loop, by hand, through the shipped surfaces:

| Step | Surface | Action |
| --- | --- | --- |
| Source | Tools → FI Intake (01/02) | manual note or URL reference (never fetched) |
| Signal | Intake 03 (or a source row's "Record signal →") | what the source showed |
| Insight | Intake 04 (or a signal row's "Promote →") | evidence + draft insight |
| Review | Brief Workbench right rail | approve/reject (audited; evidence gate rules) |
| Brief | insight row's "Build brief" | evidence ids carry through |
| Draft preview | brief row's "Preview + usage" | prompt package preview; ONE idempotent usage event; no AI call |
| Memory | insight row's "Memory candidate" | candidate only; separate human approval |
| Feedback | workbench rails + Pilot Readiness page | rating/decision/comment per object |

Three seeded scenarios stage this at staggered depths (Pilot Readiness → Seed):

- **A — Competitor signal → campaign brief** — seeded through brief + memory
  candidate; the operator previews and records feedback.
- **B — Cultural signal → brand opportunity** — seeded through APPROVED
  insight; the operator builds the brief onward.
- **C — AI-visibility gap → corrective brief** — seeded as a DRAFT insight; the
  operator performs the review itself. This is a manual observation flow,
  **not** an AEO/GEO module.

Scenarios are realistic, [DEMO]-marked, free of fake client names (bracketed
placeholders only), carry no invented performance claims, and require no
external scraping.

## 0c. What success means

- Operators complete each scenario's remaining steps **without copying IDs**
  (the ID forms exist only as labeled debugging fallbacks).
- ≥ 80% of reviewed objects receive a feedback row (rating or decision).
- Evidence coverage stays 100% for approved insights (the gate enforces it;
  the pilot confirms nobody works around it).
- At least one memory candidate is human-reviewed (approved OR rejected).
- A reviewer can answer "where did this brief come from?" using only the
  Loop Trace on the Pilot Readiness page.
- Time-to-insight (source recorded → ready_for_review) is measurable from
  record timestamps and discussed at exit — no numeric target is imposed on v0.1.

## 0d. What failure means

- Any operator needs an ID-fallback form for a NORMAL path step.
- Any fake state observed: a green that is not computed, a preview presented
  as generated content, memory treated as evidence.
- Loop abandonment because the next action was unclear (each such moment must
  be captured as a feedback row with a confusion note — that data IS the pilot).
- Data loss, cross-workspace leakage, or an unauthorized action succeeding.

## 1. Core loop

- [ ] Source → Signal → Insight → Brief → Draft preview → Memory → Usage is
      functional or honestly previewable end-to-end; no step pretends to be
      more than it is (statically proven by `tests/test-ves-core-loop-end-to-end.php`).

## 2. Evidence integrity

- [ ] An insight with zero evidence cannot reach reviewed/approved
      (store-level gate `ves_intel_evidence_required`).
- [ ] `brief_generation` prompt packages are `blocked` with `missing_evidence`
      when the insight has no evidence.
- [ ] Trusted memory can never substitute for missing evidence.
- [ ] Evidence status is visible in the Signal Report and Workbenches.

## 3. Review lifecycle

- [ ] Transition matrix active (`wp ves rc-readiness-check` reports it).
- [ ] rejected/archived are terminal except explicit, reasoned reopen/restore
      back to draft; nothing ever jumps to approved.
- [ ] No auto-approval exists anywhere. The workbench review rail is a HUMAN
      decision surface: capability + nonce + the audited store transition,
      with evidence-gate refusals surfaced, never swallowed.

## 4. Memory trust boundary

- [ ] candidate / rejected / archived / expired memory never enters trusted
      context; pinning never overrides; missing status is untrusted.
- [ ] Memory is labeled "not evidence" in every surface that shows it.
- [ ] The "Memory candidate" intake action requires an APPROVED insight and
      always produces CANDIDATE status.

## 5. Generation safety

- [ ] `ves_generation_execution_enabled` is OFF.
- [ ] Prompt packages are bounded (max items/chars), redacted, and contain no
      raw prompts, provider responses, or secrets.
- [ ] `provider_execution_allowed` is false in every preview.

## 6. Usage / credits

- [ ] Ledger model (reserve → settle/void) active; no fabricated costs.
- [ ] Zero-delivery runs settle as `not_chargeable_zero_delivery` (0 credits)
      or `failed_delivery_base_fee_only` (base fee), never as full delivery.
- [ ] OpenAI costs are labeled estimates where the provider returns none.
- [ ] The "Preview + usage" action ledgers exactly ONE event per brief per
      operator (idempotent by `run_id`); a double-click never double-charges.

## 7. Provider safety

- [ ] Actor allowlist gate refuses non-registered actors before any HTTP.
- [ ] `maxTotalChargeUsd` ceiling present on run-start URLs (warning logged if absent).
- [ ] Token travels only in the Authorization header — never in URLs.

## 8. Security baseline

- [ ] Capability checks + nonces on every mutating admin/AJAX path (intake,
      review, preview, memory candidate, feedback, seed, reset).
- [ ] Prepared SQL everywhere; escaped output in admin/front-end surfaces.
- [ ] No secrets in logs, diagnostics, exports, or prompt packages.
- [ ] URL references are recorded, never fetched (no SSRF surface).

## 9. Operator readiness

- [ ] `wp ves rc-readiness-check --format=json` returns `ready_for_staging`
      or `ready_with_warnings` with all warnings reviewed and accepted.
- [ ] Live staging validation PASSED **file-backed** and recorded
      (`ves_rc_live_validation`; strict zero-byte artifact rules apply).
- [ ] All required checklist screenshots archived with command logs.
- [ ] The Pilot Readiness page verdict reads "A controlled pilot can run now".
- [ ] An operator owns the pilot: named person, rollback plan, weekly review.

## 10. Manual QA checklist (before inviting pilot users)

- [ ] `bin/test-all.sh -q` green on the deployed build; ZIP SHA matches the release report
- [ ] Responsive/UX QA per `LIVE-BROWSER-VALIDATION-CHECKLIST.md` (incl. 320–1024px workbench rails + intake)
- [ ] Review rail observed live: approve, reject, evidence-gate refusal, terminal-state lockout
- [ ] Preview double-click produces ONE usage event (verify in the Loop Trace)
- [ ] Seed → walk a scenario → trace it → reset, end to end

## 11. Data reset checklist

- [ ] Seed via Pilot Readiness → "Seed pilot demo data" (refuses when already seeded)
- [ ] Reset via the confirmation-gated button; verify demo rows gone, non-demo rows intact
- [ ] Known residue (by design, documented): append-only review-ledger decision
      rows for [DEMO] objects remain; the security event ring is bounded and self-expiring
- [ ] Full uninstall with explicit-delete wipes `ves_pilot_feedback` + seed registries

## 12. Evidence checklist

- [ ] Evidence archive (tar.gz) retained with the pilot records; `verify_archive`
      passes under the strict rules (zero-byte required artifacts FAIL even with
      matching hashes)
- [ ] Every approved insight traces to its source via the Loop Trace
- [ ] Briefs carry their insight's evidence ids (visible in trace + workbench)

## 13. Feedback checklist

- [ ] Every pilot operator can find the feedback card (workbench right rail)
- [ ] Feedback rows reviewable on Pilot Readiness → Recent pilot feedback
- [ ] Confusion points captured as comments — these are the pilot's primary output

## 14. Pilot exit criteria

Exit and review when ANY of:

- all three scenarios completed by every operator, or
- two weeks elapsed, or
- a blocker-class finding (data integrity, security, honesty violation)
  appears — exit immediately and fix before resuming.

The exit review must produce: the feedback summary, per-scenario completion
state, the top 3 confusion points, and ONE decision — iterate the loop, or
proceed to production-rails validation (backup/restore + rollback testing per
`PRODUCTION-RAILS-RUNBOOK.md`).

## 15. Honest claims

- [ ] No surface or document claims production-readiness, a mature SaaS, or
      autonomous AI. The approved claim for a passed staging validation is:

> "Future Island v0.1 Release Candidate is ready for pilot review on staging.
> It is still not a mature production SaaS. Production release requires operator
> approval, monitored pilot usage, and final acceptance."

Sign-off — Product: ____________ Engineering: ____________ Date: ____________
