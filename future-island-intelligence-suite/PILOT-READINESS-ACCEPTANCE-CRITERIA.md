# Future Island v0.1 — Pilot Readiness Acceptance Criteria

A v0.1 pilot may start only when **all** criteria below hold. These criteria are
binary — partially met is not met. "Verified" means statically tested in the
suite (`bin/test-all.sh`) **and** observed live on staging per
`LIVE-STAGING-VALIDATION-CHECKLIST.md`.

## 1. Core loop

- [ ] Source → Signal → Insight → Brief → Draft → Memory → Usage is functional
      or honestly previewable end-to-end; no step pretends to be more than it is.

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
- [ ] No auto-approval exists anywhere.

## 4. Memory trust boundary

- [ ] candidate / rejected / archived / expired memory never enters trusted
      context; pinning never overrides; missing status is untrusted.
- [ ] Memory is labeled "not evidence" in every surface that shows it.

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

## 7. Provider safety

- [ ] Actor allowlist gate refuses non-registered actors before any HTTP.
- [ ] `maxTotalChargeUsd` ceiling present on run-start URLs (warning logged if absent).
- [ ] Token travels only in the Authorization header — never in URLs.

## 8. Security baseline

- [ ] Capability checks + nonces on every mutating admin/AJAX path.
- [ ] Prepared SQL everywhere; escaped output in admin/front-end surfaces.
- [ ] No secrets in logs, diagnostics, exports, or prompt packages.

## 9. Operator readiness

- [ ] `wp ves rc-readiness-check --format=json` returns `ready_for_staging`
      or `ready_with_warnings` with all warnings reviewed and accepted.
- [ ] Live staging validation PASSED and recorded (`ves_rc_live_validation`).
- [ ] All 11 checklist screenshots archived with command logs.
- [ ] An operator owns the pilot: named person, rollback plan, weekly review.

## 10. Honest claims

- [ ] No surface or document claims production-readiness, a mature SaaS, or
      autonomous AI. The approved claim for a passed staging validation is:

> "Future Island v0.1 Release Candidate is ready for pilot review on staging.
> It is still not a mature production SaaS. Production release requires operator
> approval, monitored pilot usage, and final acceptance."

Sign-off — Product: ____________ Engineering: ____________ Date: ____________
