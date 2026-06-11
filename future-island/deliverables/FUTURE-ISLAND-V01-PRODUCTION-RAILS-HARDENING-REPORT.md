# FUTURE ISLAND V0.1 — PRODUCTION RAILS HARDENING (PHASE 9A + 9B + 9C) — FINAL REPORT

Plugin **1.2.6** · label **v0.1-rc2** · baseline: v0.1-rc1 (1.2.5, 179 tests green)
Date: 2026-06-14 · ZIP SHA-256: `92eb6abf80f17e6eb2aeaae392e9c27518bb4b6e2dee2b78ff2a1ef5943a5708`

---

## 1. Executive verdict

**Classification: `ready_for_live_staging`**

Production Rails Hardening is **statically ready for review/live staging. It is not production-ready. Live validation remains UNRUN.** All ten known weaknesses from the RC review were addressed: provider dispatch now fails closed (registry missing = no paid run), the charge ceiling is hard-enforced with floor/max policy and an explicit zero-cost exception, workspace isolation is a tested guard service, trend-observation idempotency is DB-level (unique index + race-safe insert), review decisions land in an append-only ledger covering insight/brief/draft/memory, usage settlement has explicit states with append-only `settlement_required` markers, live validation now requires a hash-verified evidence pack (manual options are classified `unverified_manual`), validation is automated by a refusal-first bash script that produces the evidence archive, browser validation has a v2 checklist plus a curl smoke harness, and `rc-readiness-check --strict` blocks without all of it. 189/189 tests pass from the tree **and** from a clean extraction of the shipped ZIP. This container has no WordPress/WP-CLI/MySQL/browser, so nothing live was executed.

## 2. Current truth

- **Statically complete:** everything in section 4; 189/189 tests (179 prior + 10 new files / 192 new assertions); PHP 7.4 audit green; `php -l` clean ×34; `bash -n` clean ×2; security greps clean.
- **Live complete:** nothing. No staging exists in this environment.
- **UNRUN:** the validation script on real staging, the browser checklist, evidence-pack generation against a live site, `rc-record-live-validation`, `rc-readiness-check --strict` on a live install (statically it correctly returns `blocked` without evidence).

## 3. Inspection findings (pre-coding risk map)

- **Provider paths** — token already header-only; allowlist existed but failed OPEN when the registry class was absent (HIGH RISK → fixed); ceiling was warn-only (HIGH RISK → fixed); ceiling had no max bound (MEDIUM → fixed).
- **Money paths** — reserve/settle/void already idempotent by `usage_key` under a credit lock with append-only `reversed` refund events (stronger than feared); missing piece was the explicit state taxonomy + unsettled visibility (MEDIUM → fixed).
- **Lifecycle paths** — insight matrix existed (rc1); brief/draft had NO status mutation surface at all and memory `set_status` accepted any valid status incl. resurrection of rejected records (HIGH RISK → fixed: shared matrices + ledger).
- **Idempotency paths** — observation dedupe was SELECT-then-INSERT (race window, MEDIUM → fixed with unique index + collision re-select); backfill done-markers already failure-aware; usage telemetry already keyed; memory candidate dedupe existed at the records layer but **`create_candidate` never opted in** (MEDIUM → fixed).
- **Workspace paths** — builder/workbench had inline checks without central logging; CLI previews accepted any workspace int; nothing defaulted to ws 1 silently (verified) (MEDIUM → guard service + wiring).
- **Release validation paths** — `ves_rc_live_validation` was a bare trusted option (HIGH RISK → evidence pack).
- **UI paths** — RC page lacked rails visibility (→ extended); Signal Room left untouched intentionally (existing 7B test surface; rails live on the RC page).
- **Risky points avoided** — no rewrite of the billing ledger schema, no Action Scheduler replacement, no Signal Room re-layout, no REST surface changes, no destructive migrations.

## 4. What changed

**Phase 9A** — fail-closed dispatch gate (`ves_allowlist_unavailable` / `ves_actor_not_allowlisted`); hard ceiling (`ves_charge_ceiling_required/_too_low/_too_high`, floor 0.10 / max 50.00 filterable, registry `zero_cost` exception); local-tests-only bypass constant (default false, inert off-localhost, attempts security-logged); `VES_Workspace_Guard` + wiring into prompt builder and workbenches; observation `UNIQUE KEY ws_canonical` + race-safe insert + read-only migration report; `create_candidate` dedupe opt-in; `VES_Job_Rails` (retry counts, max 3, scrubbed bounded dead-letter ring, operator-only clear) wired into `with_lock`.

**Phase 9B** — `VES_Review_Decision_Ledger` (append-only table, unique idempotency key, scrubbed metadata, matrices for brief/draft/memory incl. no-resurrection) wired into insight store, new `update_brief_status`/`update_draft_status`, and memory `set_status`; `VES_Usage_Settlement` (canonical states, `settlement_required` markers append-only + resolvable, stale-reservation report); `VES_RC_Evidence_Pack` (schema 1.0, deterministic hash, computed-not-asserted `validation_status`, gated `record_live_validation`, `unverified_manual` classification); `VES_Security_Event_Log` (bounded, scrubbed, append-only) fed by every blocked gate.

**Phase 9C** — `scripts/future-island-live-validation-v2.sh` (refusal-first, read-only battery, redaction, manifest, evidence pack completion, tar.gz archive); `scripts/future-island-browser-smoke.sh` (curl-only, honest about its limits); `LIVE-BROWSER-VALIDATION-CHECKLIST.md`; `--strict` readiness mode (blocked without verified evidence + all rails; best result `ready_for_pilot_review`); CLI `ves rc-evidence-pack` + `ves rc-record-live-validation`; RC page: rails rows in the readiness table, evidence-hash banner states, Audit & rails section (decisions timeline, dead letters, security summary).

**What did NOT change** — AI execution (still OFF, no execution path), billing ledger schema, REST surfaces, Signal Room layout, publishing/scheduling (still none), provider set (no new scrapers), memory-is-not-evidence policy, prompt-package redaction/bounds.

## 5. Files changed (70 total: 8 new includes… full list in the diff)

| Path | Purpose | Risk |
| --- | --- | --- |
| `includes/class-ves-apify-client.php` | fail-closed gate + hard ceiling + bypass | **high-value/medium-risk** (dispatch path; 5 test files cover it) |
| `includes/class-ves-apify-actor-registry.php` | `zero_cost` flag + `is_zero_cost_slug` | low |
| `includes/class-ves-workspace-guard.php` (new) | tenant isolation service | low |
| `includes/class-ves-security-event-log.php` (new) | scrubbed append-only audit | low |
| `includes/class-ves-job-rails.php` (new) + `class-ves-action-scheduler-jobs.php` | retry/dead-letter rails | medium (job execution path; behavioral tests) |
| `includes/class-ves-review-decision-ledger.php` (new) | append-only review ledger + matrices | low (additive table) |
| `includes/class-ves-intelligence-store.php` | ledger wiring + brief/draft updaters + security events | medium (core store; suite-covered) |
| `includes/class-ves-brand-context-service.php` | memory matrix + ledger + candidate dedupe | medium (63-test surface stays green) |
| `includes/class-ves-usage-settlement.php` (new) | settlement taxonomy + markers + health | low (read/compat layer) |
| `includes/class-ves-rc-evidence-pack.php` (new) | evidence pack build/verify/record | low |
| `includes/class-ves-rc-readiness-service.php` | 6 new rail checks + strict mode + pack-aware classification | low |
| `includes/class-ves-cli-rc-readiness.php` | `--strict`, `rc-evidence-pack`, `rc-record-live-validation` | low |
| `includes/class-ves-release-candidate-page.php` | rails diagnostics + banner states | low (read-only) |
| `includes/class-ves-trend-observation-store.php` | unique index + race-safe insert + report | medium (write path; race-tested) |
| `includes/class-ves-generation-prompt-package-builder.php`, `class-ves-workbench.php` | guard wiring | low |
| `includes/class-ves-migrations.php` | ledger registration + schema version bump | low |
| `scripts/*.sh` (2 new), 3 docs (new), bootstrap | automation + docs + wiring | none/low |
| 10 new test files + 5 updated test harnesses + 34 version literals | coverage | none |

## 6. Provider safety hardening

Fail-closed allowlist (missing registry blocks BEFORE token checks and HTTP); ceiling hard-enforced with floor/max + zero-cost exception (only allowlisted AND explicitly `zero_cost`); token header-only (re-proven); bypass requires scary constant AND local siteurl, default false, refused+logged elsewhere. Tests: `test-ves-provider-fail-closed-9a.php` (14), `test-ves-provider-safety-hardening.php` (31), `test-v093019` harness updated (18).

## 7. Workspace isolation

`VES_Workspace_Guard`: id validation, object-in-workspace assertion (loads via store getters), unknown-workspace refusal without explicit `allow_unknown`, row filtering, security-event logging, `guard_active()` probe. Wired into: prompt package builder (3 assembly paths), Brief/Draft workbenches, readiness. Memory context retrieval and operator queue were already workspace-scoped by query (verified; guard now available to them). Tests: `test-ves-workspace-guard-9a.php` (18) including cross-workspace package/workbench refusals.

## 8. Idempotency and queue rails

Trend: unique index + deterministic collision resolution + report (`test-ves-db-idempotency-9a.php`, 15 — includes a simulated race where the pre-check SELECT misses and the INSERT collides). Usage: duplicate reserve/settle already idempotent (existing tests + settle-idempotence verified in v093020 path). Memory: candidate dedupe now actually enabled. Review decisions: unique idempotency key + race re-select. Action Scheduler: retry counts, max 3 → scrubbed dead letter, dead keys refuse execution, success clears counters, operator-only clear (`test-ves-dead-letter-rails-9a.php`, 21).

## 9. Review / audit ledger

Coverage: insight (store matrix), brief/draft (new updaters, shared matrix: terminal rejected/archived, approved→archived only), memory (downgrades open, resurrection impossible, expiry path preserved), prompt packages preview-only. Append-only: no update/delete API (probed); blocked transitions write security events, never decisions; duplicate submissions collapse. Scrubber removes secret-shaped values and forbidden keys. RC page renders a read-only escaped timeline. Tests: `test-ves-review-decision-ledger-9b.php` (29).

## 10. Usage settlement ledger

States: `reserved/completed/failed/voided/settlement_required/not_chargeable/diagnostic_only` mapped from existing statuses + `settlement_classification` (zero-delivery → `not_chargeable`, never a completed delivery); unknown stored statuses surface as `settlement_required` rather than hiding. Void/reversal stays the existing append-only refund event. Markers are append-only, idempotent, resolvable with timestamps (no deletion); readiness warns on open markers/stale reservations and **strict blocks**. No fabricated costs/refunds (documented + asserted). Tests: `test-ves-usage-settlement-9b.php` (24).

## 11. Release evidence pack

Schema 1.0 (16 required fields, 9 required command outputs with exit code 0); deterministic SHA-256 over canonicalized content (key-order independent — proven); `build()` computes status, never asserts it; `rc-record-live-validation` refuses invalid schema/hash/tamper/incomplete/blockers and stores the hash-backed state; bare manual options classify `unverified_manual` everywhere (readiness, strict, RC page warning banner); RC page shows the hash escaped/truncated. Tests: `test-ves-evidence-pack-9b.php` (20), plus readiness/page updates.

## 12. Live validation automation

`future-island-live-validation-v2.sh`: refuses without `--i-confirm-this-is-staging` + existing DB backup; refuses production-looking siteurls; optional ZIP install with SHA-256; 22-command read-only battery with per-command exit codes + output hashes into a timestamped folder; secret redaction; PHP error-log tail capture; completes the evidence pack (command outputs + recomputed hash via the documented canonicalization); tar.gz archive; honest next steps. Browser: checklist v2 (12 screenshot items + console/network/error-log exports + forbidden-button check) and the curl smoke harness (status codes only, never fakes screenshots). Strict readiness: see §11. Tests: `test-ves-live-validation-script-9c.php` (53), `test-ves-rc-strict-readiness-9c.php` (14).

## 13. UI / operator diagnostics

RC page now shows every rail in the readiness table (provider fail-closed, charge ceiling, workspace guard, review ledger, settlement health, trend idempotency, dead-letter, security log, live evidence state) with honest ok/warn/block badges, three banner states (UNRUN / unverified-manual warning / evidence-backed pass with hash), and the read-only Audit & rails section. No fake green; no production-ready state exists in the code. Signal Room intentionally unchanged (rails live on the RC diagnostics surface; its 7B contract tests stay byte-stable).

## 14. Security review

Capabilities on the new CLI commands + page + dead-letter/marker mutations (`manage_options`); no new nonce surfaces (no new admin forms — everything read-only); sanitization/escaping on all new output incl. XSS probe tests; prepared SQL in ledger/store/settlement queries; secret redaction at every new sink (security log, ledger, dead letters, markers, script); REST untouched; mutation safety: readiness/page/report read-only (asserted zero option writes), recording gated + verified, ledger append-only.

## 15. Tests added/updated

New: `provider-fail-closed-9a` (14), `workspace-guard-9a` (18), `db-idempotency-9a` (15), `dead-letter-rails-9a` (21), `review-decision-ledger-9b` (29), `usage-settlement-9b` (24), `evidence-pack-9b` (20), `security-event-log-9b` (13), `rc-strict-readiness-9c` (14), `live-validation-script-9c` (53). Updated: provider-safety-hardening (31 — hard-block semantics + ceiling policy + zero-cost), v093019 harness (permissive registry stub + ceiling URL), rc-readiness-check (20 — rails stubs + unverified_manual), release-candidate-page (26 — banner states + hash), phase3 contract (schema version format), 34 files' version literals 1.2.5→1.2.6.

## 16. Verification outputs

In `command-output-v01-production-rails-hardening.log`: `php -l` 34/34 clean; `bash -n` 2/2; PHP 7.4 audit pass; JS untouched and clean; security greps explained; **suite 189/189 from the tree and 189/189 from the clean ZIP extraction (552 files)**. ZIP SHA-256: `92eb6abf80f17e6eb2aeaae392e9c27518bb4b6e2dee2b78ff2a1ef5943a5708`.

## 17. Live validation status

**UNRUN.** No staging exists in this container; no evidence pack was generated against a live site; no screenshots exist; `ves_rc_live_validation` is unset. Statically, `rc-readiness-check --strict` correctly returns `blocked` in exactly this situation — that is the designed behavior, not a defect.

## 18. Findings

| Sev | Location | Issue | Impact | Status / fix |
| --- | --- | --- | --- | --- |
| high | apify client (pre-phase) | allowlist failed open without registry; ceiling warn-only | uncapped paid dispatch | **FIXED** (fail-closed + hard ceiling, 9A.1/9A.2) |
| high | `ves_rc_live_validation` (pre-phase) | bare option trusted as passed | fakeable validation | **FIXED** (evidence pack + unverified_manual, 9B.3) |
| high | memory `set_status` (pre-phase) | rejected/archived could be resurrected to active/pinned | trust boundary bypass-by-edit | **FIXED** (matrix, 9B.1) |
| medium | `create_candidate` (pre-phase) | records-layer dedupe never enabled | duplicate candidate pile-up | **FIXED** (9A.4) |
| medium | observation insert (pre-phase) | SELECT-then-INSERT race | double rows under concurrency | **FIXED** (unique index + collision re-select) |
| medium | background jobs (pre-phase) | no retry cap / dead-letter | silent infinite failure | **FIXED** (9A.5) |
| low | evidence pack cross-language hash | bash/python step must match PHP canonicalization | recording fails honestly on mismatch | documented in spec; record command verifies in PHP either way |
| low | dead-letter/security/marker storage | bounded options, not tables | very old entries roll off | documented; run-log mirrors run-scoped failures |
| note | unique index on legacy data | dbDelta can't add it while duplicates exist | app-level dedup remains the guard | surfaced by the migration report + readiness warn |
| note | Signal Room | rails not added there | diagnostics centralized on RC page | intentional scope control |

## 19. Final decision

- **Can this be reviewed?** Yes — diff, log, report, and tests are complete and reproducible.
- **Can this remain on dev/staging branch?** Yes — committed on `claude/trusting-dijkstra-7tdqsa` (draft PR #2).
- **Is it ready for live staging?** Yes — `ready_for_live_staging`, with the v2 script ready to drive it.
- **Is it ready for pilot review?** Not yet — pilot review requires the evidence-backed live pass (strict mode enforces exactly this).
- **Is it production-ready?** **No.** No live evidence exists, and no code path can claim otherwise.
- **Can AI generation be enabled?** **No.** Flag OFF; no execution path shipped.
- **What exactly remains before production?** (1) Run `future-island-live-validation-v2.sh` on real staging with a backup; (2) complete the browser checklist with screenshots; (3) `wp ves rc-record-live-validation --evidence-pack=…`; (4) `wp ves rc-readiness-check --strict` → `ready_for_pilot_review`; (5) operator approval, monitored pilot, final acceptance.
