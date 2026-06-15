# Future Island — Production Rails Runbook (Phase 9A/9B/9C)

Plugin **1.2.6** · label **v0.1-rc2**. Companion to
`RELEASE-CANDIDATE-RUNBOOK.md`; this document covers the hard rails added by
the Production Rails Hardening bundle and how operators work with them.

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
bash scripts/future-island-live-validation-v2.sh \
  --i-confirm-this-is-staging \
  --db-backup=/path/staging-backup.sql \
  --plugin-zip=/path/future-island-….zip \
  --operator="Your Name"

# 2. complete LIVE-BROWSER-VALIDATION-CHECKLIST.md (screenshots into the evidence folder)

# 3. record ONLY through the verified pack:
wp ves rc-record-live-validation --evidence-pack=<evidence-folder>/evidence-pack-….json

# 4. strict gate:
wp ves rc-readiness-check --strict --format=json   # best result: ready_for_pilot_review
```

A manually-written `ves_rc_live_validation` option is classified
**unverified_manual**: the RC page shows a warning banner and strict mode
blocks. See `RELEASE-EVIDENCE-PACK-SPEC.md` for the pack format and hash rules.

## 8. Rollback

Same as the RC runbook: deactivate the plugin and/or restore the DB backup.
All Phase 9 schema changes are additive (one new table, one new unique index);
no destructive ALTER/DROP/DELETE exists. Re-running migrations is idempotent.

## 9. What this bundle does NOT change

No AI generation (flag still OFF), no publishing/scheduling, no new providers,
no Brand Brain/RAG/embeddings, no billing plans, no auto-approval. Production
release still requires: evidence-backed live validation pass → operator
approval → monitored pilot → final acceptance.
