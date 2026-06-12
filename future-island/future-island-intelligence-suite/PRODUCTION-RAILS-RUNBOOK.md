# Future Island — Production Rails Runbook

Plugin **1.4.0** · label **v0.1-rc7**. Companion to
`RELEASE-CANDIDATE-RUNBOOK.md`; this document covers the hard rails added by
the Production Rails Hardening bundle (Phase 9A/9B/9C), the Phase 4/5
operational rails, and how operators work with them.

## 1. Provider dispatch (fail-closed)

- A paid Apify run can only start when **all** of these hold:
  - the actor-registry allowlist service is loaded (missing service = blocked: `ves_allowlist_unavailable`),
  - the actor slug is allowlisted (`ves_actor_not_allowlisted` otherwise),
  - the run URL carries `maxTotalChargeUsd` between **0.10** and **50.00** USD
    (`ves_charge_ceiling_required` / `_too_low` / `_too_high` otherwise) —
    unless the actor is explicitly registered `zero_cost`.
- Tokens travel only in the `Authorization` header. Never in URLs, logs, or diagnostics.
- Every refusal is a scrubbed security event (RC page → Audit & rails).
- **Local-dev escape hatch** (never staging/production):
  `VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY` — default false,
  inert unless `siteurl` is localhost/`.local`/`.test`; non-local attempts are
  logged as `unsafe_bypass_attempt` and refused.
- To allow a new actor: register it in the Actor Registry (or the
  `ves_apify_actor_allowlist_extra` option). To exempt one from the ceiling it
  must be both allowlisted **and** `zero_cost => true` in its registry entry.

## 2. Workspace isolation

`VES_Workspace_Guard` refuses cross-workspace reads in prompt packages,
workbenches, and anything routed through it; objects without a workspace are
"unknown" and unusable without an explicit `allow_unknown` opt-in. Mismatches
are security events. Nothing silently defaults to workspace 1.

## 3. Idempotency rails

- Trend observations: `UNIQUE KEY ws_canonical (workspace_id, canonical_hash)`
  (additive; dbDelta). Racing duplicate inserts resolve to the existing row.
  If historical duplicates block index creation, `rc-readiness-check` reports
  it (`trend_idempotency` check) and application-level dedup remains active;
  consolidate duplicates, then `wp ves verify-schema --repair`.
- Usage reservations: idempotent by `usage_key` (existing behavior, now probed).
- Memory candidates: deduped by workspace+type+source identity at the records layer.
- Review decisions: deduped by ledger `idempotency_key` (unique index).

## 4. Background jobs / dead letters

Jobs retry up to **3** times; the final failure lands in the append-only
dead-letter ring (scrubbed reason, no secrets) and the job key is refused
further execution. Inspect on the RC page (Audit & rails → Queue dead letters).
Clearing a dead letter is an explicit `manage_options` action
(`VES_Job_Rails::clear_dead_letter`) — never automatic.

## 5. Review decision ledger

Every successful insight/brief/draft status change and memory review action
appends one immutable row (`wp_ves_review_decisions`): who, what, from→to,
reason, evidence snapshot hash, idempotency key. There is no update/delete API.
Blocked transitions never write a decision — they write a security event.
Recent decisions render read-only on the RC page.

## 6. Usage settlement states

Ledger rows map onto: `reserved / completed / failed / voided /
settlement_required / not_chargeable / diagnostic_only`. Zero-delivery runs
settle as `not_chargeable` (0 credits) or base-fee-only — never as full
delivery. Ambiguous outcomes get an append-only `settlement_required` marker;
`rc-readiness-check` warns while any marker is open and **strict mode blocks**.
Resolve via operator review, then `VES_Usage_Settlement::resolve_settlement_marker`.

## 7. Live validation (evidence-backed only)

```bash
# 1. backup, then run the battery (read-only; refuses without confirmation):
bash scripts/future-island-live-validation-v3.sh --mode=collect-cli \
  --i-confirm-this-is-staging \
  --db-backup=/path/staging-backup.sql \
  --plugin-zip=/path/future-island-….zip \
  --expected-siteurl=https://staging.example \
  --operator="Your Name"

# 2. complete LIVE-BROWSER-VALIDATION-CHECKLIST.md (screenshots into the evidence folder)

# 3. finalize + record ONLY through the verified, FILE-BACKED pack:
bash scripts/future-island-live-validation-v3.sh --mode=finalize …
wp ves rc-record-live-validation --evidence-pack=…/evidence-pack-v2.json --evidence-root=…

# 4. strict gate:
wp ves rc-readiness-check --strict --format=json   # best result: ready_for_pilot_review
```

A manually-written `ves_rc_live_validation` option is classified
**unverified_manual**; a recorded failure is **failed**; an unreadable record is
**unknown_error** — all block strict mode. Strict artifact rules apply: a
zero-byte required artifact fails verification even when its recorded hash
matches (silent commands record `NO_OUTPUT_RECORDED`; clean logs record
`NO_*_OBSERVED` markers). See `RELEASE-EVIDENCE-PACK-V2-SPEC.md`.

## 8. Rollback

Same as the RC runbook: deactivate the plugin and/or restore the DB backup.
All schema changes are additive (Phase 4 adds one table: `ves_pilot_feedback`);
no destructive ALTER/DROP/DELETE exists outside explicit uninstall. Re-running
migrations is idempotent.

## 9. What this bundle does NOT change

No AI generation (flag still OFF), no publishing/scheduling, no new providers,
no Brand Brain/RAG/embeddings, no billing plans, no auto-approval. Production
release still requires: evidence-backed live validation pass → operator
approval → monitored pilot → final acceptance.

---

# Phase 4/5 — operational rails

Everything below separates **current truth** (verified in the self-contained
suite), **assumptions** (must be confirmed on a real host), and **manual steps**
(no automation exists — a human does them).

## 10. Environment requirements (assumptions — confirm on staging)

| Item | Requirement |
| --- | --- |
| WordPress | 6.x single site; admin + admin-post + Tools menu available |
| PHP | 7.4–8.4 (suite runs on 8.4; code is 7.4-compatible, audited) |
| MySQL/MariaDB | InnoDB; `dbDelta`-compatible privileges (CREATE/ALTER) |
| PHP extensions | zlib (gzip evidence archives), Phar (archive verification), hash, json |
| Filesystem | writable `sys_get_temp_dir()` for archive verification; cleaned automatically |
| Outbound network | NONE required for the core loop (URL sources are recorded, never fetched) |
| WP-CLI | required for validation/recording commands on staging |

## 11. Required constants / options

| Name | Production value |
| --- | --- |
| `ves_generation_execution_enabled` (option + filter) | **OFF** for v0.1 — every surface reads the builder truth |
| `VES_PRODUCTION_MVP`, `VES_ENABLE_DEEP_VIDEO_ANALYSIS`, `FI_DTF_ENABLE_DEEP_VIDEO` | false |
| `VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY` | must NOT be defined |
| `ves_delete_data_on_uninstall` | site policy decision — explicit delete wipes all suite tables/options |

## 12. Permissions / capabilities

Every mutating surface requires `manage_options` + a per-action nonce:
intake (source/signal/insight/brief), workbench review, prompt preview + usage,
memory candidate, pilot feedback, pilot seed/reset, schema repair, memory admin,
diagnostics clearing. Read surfaces (Signal Room, workbenches, RC page, Pilot
Readiness, trace) are `manage_options` pages. There are no REST routes in the
Phase 4/5 surfaces; if any are added later they MUST carry `permission_callback`.

## 13. Cron / Action Scheduler expectations

The core loop is synchronous admin-action work — no cron dependency. Background
rails (queue, scheduled jobs) follow the dead-letter rules in §4; Action
Scheduler is used when present, WP-Cron otherwise. The pilot runs fine with
default WP-Cron.

## 14. Data retention assumptions

- Loop objects (source/signal/evidence/insight/brief) persist until explicit
  uninstall-with-delete; there is no automatic purge.
- The review-decision ledger and usage events are append-only; pilot resets
  remove ONLY registry-listed demo rows (and their preview events) — audit
  decisions remain by design.
- The security event log is a bounded ring (self-expiring).
- Pilot feedback persists until uninstall; export/summarize it at pilot exit.

## 15. Backup / restore requirements (manual — UNTESTED in this repo's environment)

- Full DB backup before: install, upgrade, validation runs, pilot start.
- The validation script REQUIRES `--db-backup` and records its SHA-256 into the
  evidence pack.
- Restore drill (manual): restore the backup on a scratch host, activate the
  plugin, run `wp ves verify-schema` + `bin/test-all.sh` — document the result.
  **This drill has not been performed by the automated suite; do not claim it.**

## 16. Staging-to-production checklist

1. ZIP SHA verified against the release report; suite green from a clean extraction.
2. Staging validation: strict gate `ready_for_pilot_review`, file-backed pack archived.
3. Pilot completed; exit review done; blocker-class findings fixed and re-validated.
4. Backup/restore drill performed and documented (§15).
5. Rollback drill performed and documented (§17).
6. Production install window: backup → install → `wp ves verify-schema` →
   smoke-walk the loop in a throwaway workspace → seed/reset OFF (no demo data
   in production) → monitor logs for 48h.
7. Generation execution stays OFF until a separate, explicit decision.

## 17. Rollback checklist (manual)

1. Deactivate the plugin (admin or `wp plugin deactivate`).
2. If schema rollback is required: restore the pre-install DB backup (the only
   way back — migrations are additive and do not auto-reverse).
3. Re-verify the site (front-end + admin) without the plugin.
4. Record what triggered the rollback as a security/ops note.
**This drill has not been performed by the automated suite; do not claim it.**

## 18. Observability checklist

- Loop action failures log WHY via `VES_Log::warn` (error code + object ids —
  never payloads, never secrets): intake refusals, review refusals, seed refusals.
- Guardrail trips land in the scrubbed security event log (RC page → Audit & rails).
- Dead letters carry scrubbed reasons (§4).
- The Loop Trace (Pilot Readiness) reconstructs any insight's trail on demand.
- Verify on staging: trigger one intake failure + one evidence-gate refusal and
  confirm both are visible and secret-free.

## 19. Security checklist

- [ ] capability + nonce on every mutating path (tested: unauthorized = 403, bad nonce = rejected)
- [ ] URL references recorded, never fetched; scheme whitelist; credentialed URLs refused
- [ ] archive verification: traversal-safe, size-audited, temp-cleaned, fail-closed
- [ ] no secrets in logs/diagnostics/evidence (redaction in the validation script)
- [ ] prepared SQL; escaped output (hostile-title tests in the suite)
- [ ] no destructive action without explicit confirmation (pilot reset requires a checkbox, server-enforced)

## 20. Known non-production areas (honest list)

- AI generation execution: OFF; previews only.
- Billing/credits: ledger + settlement rails exist; **plans/checkout are not a
  v0.1 capability** — do not sell against them.
- Backup/restore + rollback drills: documented above, not yet performed.
- Member-app dark mode: roadmap.
- Feedback has no export UI yet (DB table is the source of truth at pilot exit).
- The ID-fallback forms on Intake exist for debugging; production operators use row actions.
