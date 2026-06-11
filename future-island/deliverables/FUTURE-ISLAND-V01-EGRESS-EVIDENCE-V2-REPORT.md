# FUTURE ISLAND V0.1 — PHASE 9D + 9E: EXTERNAL EGRESS LOCKDOWN + EVIDENCE PACK V2 — FINAL REPORT

Plugin **1.2.7** · label **v0.1-rc3** · baseline: Production Rails Hardening (1.2.6, 189 tests green)
Date: 2026-06-15 · ZIP SHA-256: `64132036cce27c7176303fcc9d9f415a6521b8d60d3ba21ff59c36f23e380643`

---

## 1. Executive verdict

**Classification: `ready_for_live_staging`**

Phase 9D + 9E is **statically ready for review/live staging — its tests pass (193/193 from the tree AND from a clean extraction of the shipped ZIP). It locks down external egress and upgrades live-validation evidence integrity. It is not production-ready. Live validation remains UNRUN.** All five known issues are closed: the three direct Apify dispatch paths (MarketSignal run-sync, DTF generic + TikTok adapter fallbacks) now route through the single guarded core client or fail closed with security events; every external HTTP path in the tree is classified in a new egress inventory consumed by readiness (zero unknown egress, grep-proven); the evidence pack is now schema 2.0 with mandatory browser artifacts and **file-backed verification** — a pack JSON alone is `json_only_unverified` and can never record a pass; validation script v3 refuses weak staging detection (`--expected-siteurl/--expected-host` exact matching) and refuses to finalize without the 12 named screenshots plus console/network/php logs; and the memory `set_status` diagnostic now logs success only after the DB proves it.

## 2. Current truth

- **Static status:** 193/193 tests (189 prior + 4 new test files; 1 rewritten to v2); PHP 7.4 audit green; `php -l` 18/18; `bash -n` 3/3 scripts; no JS changed; security greps fully classified in the log.
- **Live status:** nothing live was executed — this container has no WordPress, WP-CLI, MySQL, or browser.
- **UNRUN:** validation script v3 on real staging, browser artifact capture, evidence-pack v2 generation against a live site, file-backed recording, `rc-readiness-check --strict` on a live install (statically it correctly blocks in this state — by design).

## 3. Inspection findings (pre-coding)

- **External egress inventory (grep sweep):** 10 files with `wp_remote_*` in production: Apify core client (gateway), MarketSignal (2: Apify run-sync ❌bypass + OpenAI), DTF generic adapter (8: 1 run-start ❌bypass + reads), DTF TikTok adapter (7: 1 run-start ❌bypass + reads), OpenAI client, analysis.php (OpenAI legacy + SSRF-guarded public fetch), Stripe billing (2), DTF AI bridge (2), Creative-Intelligence OpenAI provider (1), admin connection test (1 read).
- **Apify dispatch paths:** core client/run-execution/google-intel/monitor/brand-audit/ajax/core-adapter all already guarded (ceiling via `ves_make_run_url`/explicit). The three bypasses above were real (HIGH RISK).
- **AI provider paths:** five OpenAI call sites; all key-gated + operator-triggered; none reachable by default; no generation execution path exists (flag OFF).
- **Billing paths:** Stripe REST via `wp_remote_post` in one class, isolated from the usage-credit ledger, secrets never logged (existing tests).
- **Evidence pack paths:** v1 could compute `passed` from command hashes + operator name with empty browser fields, and `record_live_validation` never touched the filesystem (HIGH RISK).
- **Validation script paths:** v2's staging detection was a confirmation flag + crude URL pattern (MEDIUM RISK).
- **Risks avoided:** no provider business-logic rewrite, no OpenAI consolidation (classified as roadmap `ai_provider_legacy_requires_review` instead), no new tables, no Signal Room changes, no read-path rewrites (header-token reads verified and kept).

## 4. What changed

- **Phase 9D:** `VES_External_Egress_Inventory` (23 classified rows, summary + per-provider views); MarketSignal run-sync routed through `VES_Apify_Client::request()` with ceiling + fail-closed + no local token handling; DTF generic adapter direct start → core adapter or fail-closed (zero HTTP); DTF TikTok adapter same; four new readiness checks (`external_egress`, `apify_single_gate`, `ai_egress`, `billing_egress`) wired into strict mode's hard-rail list.
- **Phase 9E:** evidence pack schema 2.0 (full 18-command battery, per-command stdout/stderr/combined files+hashes, 12 named screenshots with per-file hashes, console/network/php-error logs, DB-backup hash, non-circular archive-manifest hash); `verify_files`/`verify_archive` (PharData) with `missing_required_artifacts`/`file_hash_mismatch` classifications; `rc-record-live-validation` requires `--evidence-root`|`--evidence-archive` (JSON-only refused); classifier adds `json_only_unverified`; strict trusts only file-verified `passed`; validation script v3 (3 modes, expected-host matching, artifact refusals); checklist v3 with mandatory artifact names; RC page shows schema version, file-backed state, json-only warning and missing-for-trust list.
- **Low fix:** `set_status` logs `status_changed` only after a successful update, `status_change_failed` otherwise; ledger writes only after success; returns false on failure.
- **Did not change:** AI behavior (no new calls, flag still OFF), read paths (header-token discipline verified), Stripe billing logic, schema (zero DB changes this phase), Signal Room, the v2 script (kept alongside v3).

## 5. External egress inventory (final status — full machine-readable registry in `VES_External_Egress_Inventory`)

| Path (class::method) | Provider | Classification | Guarded | Risk → final |
| --- | --- | --- | --- | --- |
| `VES_Apify_Client::request` | apify | apify_run_start_core_guarded | yes | the gate itself |
| Run-execution / Google-Intel / Monitor / Brand-Audit / Ajax dispatchers | apify | apify_run_start_core_guarded | yes | guarded (pre-existing) |
| `VES_Market_Signal_Commercial::run_apify_sync` | apify | apify_run_start_core_guarded | yes | **HIGH → fixed (routed)** |
| `FIDTF_Core_Apify_Client_Adapter::start` | apify | apify_run_start_core_guarded | yes | guarded (pre-existing) |
| `FIDTF_Generic_Apify_Live_Adapter::start_direct_apify_run` | apify | apify_direct_legacy_blocked | yes | **HIGH → fail-closed** |
| `FIDTF_TikTok_Live_Adapter::start_direct_apify_run` | apify | apify_direct_legacy_blocked | yes | **HIGH → core-routed/fail-closed** |
| Core client reads / admin users-me / ajax dataset / DTF refresh+dataset reads | apify | apify_read_guarded | yes | header token, no token-in-URL (verified) |
| `VES_OpenAI_Client::request` | openai | ai_provider_gated | yes | key+credit+operator gated |
| analysis.php / MarketSignal run_chatgpt / FIDTF_AI_Bridge / FICI provider | openai | ai_provider_legacy_requires_review | yes | key-gated; consolidation roadmap |
| `VES_Stripe_Billing::api_request` | stripe | billing_provider_explicit | yes | isolated from usage credits |
| analysis.php subtitle fetch | public_web | public_content_fetch_guarded | yes | SSRF-guarded |
| **Unknown egress** | — | — | — | **ZERO (readiness blocks otherwise)** |

## 6. Apify dispatch lockdown

Direct paths fixed: 3/3 (grep-proven: no production `wp_remote_post(` targeting an acts/actor-tasks run URL remains; the only `wp_remote_post` mentions in those code paths are docblocks). Single gate: every run-start hits `enforce_run_dispatch_safety()` (fail-closed allowlist, 0.10–50.00 ceiling, zero-cost exception, local-only bypass). MarketSignal's admin-configured actors are now subject to the allowlist — unknown actor/task IDs block before HTTP (operators add them via the registry/extra option). Blocked legacy fallbacks record `provider_dispatch_blocked` security events. Tests: `test-ves-egress-lockdown-9d.php` (35) + updated DTF contract chain (v030/v0324 roots → 25 files) + existing fail-closed/provider-safety suites still green.

## 7. AI / billing egress classification

OpenAI: 5 call sites classified (1 gated core client, 4 legacy-requires-review), all key-gated and operator-triggered, none default-reachable; readiness `ai_egress` blocks on any unguarded/unknown AI path and warns if the generation flag is ON (still OFF; no execution path exists; prompt-package preview remains provider-call-free — existing builder tests). Stripe: classified `billing_provider_explicit`, separate from the usage-credit ledger; no secrets in logs (existing Stripe tests); no billing expansion. No new AI behavior anywhere.

## 8. Evidence Pack v2

Schema 2.0 requires: 18 commands × (exit 0 + stdout/stderr/combined file+hash), 12 named screenshots + per-file hashes, browser console + network errors + PHP error log files+hashes (NO_*_OBSERVED markers count as files, absence does not), DB backup hash, archive manifest hash, operator. `validation_status` is computed — empty screenshots/console/php/network/db-backup all force `incomplete` (proven). File-backed verification: every referenced path (traversal-rejected) must exist with a matching SHA-256 in `--evidence-root` or in the extracted `--evidence-archive` (PharData; manifest hash re-checked; extraction failure = honest refusal). JSON-only recording attempts return `ves_evidence_json_only` and store nothing. Recorded state carries `files_verified: true`; the classifier yields `passed` only for such states, `json_only_unverified` for pack-shaped states without it, `unverified_manual` for bare options. Hash remains deterministic/tamper-evident. Tests: rewritten `test-ves-evidence-pack-9b.php` (25) + `test-ves-evidence-archive-verification-9e.php` (15, real files + real tar.gz round-trip incl. tamper + deletion + wrong-manifest cases).

## 9. Validation Script v3

Modes: `collect-cli` (battery only, pack stays `incomplete`), `finalize` (browser artifacts mandatory), `full`. Staging detection: `--expected-siteurl` exact match on siteurl AND home (or `--expected-host` host match on both) on top of the explicit confirmation flag + DB backup — all refusals exit 3 before any other action (behaviorally proven). Capture: per-command `.out`/`.err`/`.combined.txt` with exit codes and per-stream hashes in `manifest-commands.txt`; secrets redacted; artifacts copied into `screenshots/` + `logs/`; pack v2 assembled with the non-circular archive-manifest hash; tar.gz built; exact file-backed record command printed. Never `--apply`, never AI generation, never a pass without browser artifacts. v2 script kept. Tests: `test-ves-validation-script-v3-9e.php` (48 — including live refusal executions of the real script).

## 10. RC readiness / UI

Strict mode now additionally blocks on: unknown egress, run-start bypass, unguarded AI path, json-only/unverified live state, and the four new checks joining the hard-rail list; best strict result stays `ready_for_pilot_review`. RC page: three warning banner states (UNRUN with missing-for-trust list · unverified_manual · **json_only_unverified** with re-record instructions) and the passed banner now shows schema version + `file-backed: yes` + hash; the readiness table includes the four egress rows. No production-ready claim exists anywhere (re-proven by the page test).

## 11. Memory log fix

`VES_Brand_Context_Service::set_status()` now: failure → `status_change_failed` log, no ledger write, returns false; success → `status_changed` log + one ledger decision. Test: `test-ves-memory-status-log-9de.php` (8, both paths with a fail-toggling wpdb).

## 12. Security review

Capabilities unchanged (manage_options on CLI/page); no new nonce surfaces (no new mutating admin forms); sanitization/escaping on all new output (json-only banner, schema labels — page XSS probe still green); no new SQL; secret redaction extended to the v3 script and verified in dead-letter/ledger/security-log paths; external egress now centrally classified with zero unknowns; no raw prompt/provider response exposure (grep-classified); mutation safety: recording requires verified files + no blockers, readiness/page remain read-only; evidence file paths are traversal-checked before hashing.

## 13. Tests added/updated

New: `test-ves-egress-lockdown-9d.php` (35), `test-ves-evidence-archive-verification-9e.php` (15), `test-ves-validation-script-v3-9e.php` (48), `test-ves-memory-status-log-9de.php` (8). Rewritten: `test-ves-evidence-pack-9b.php` → v2 contract (25). Updated: DTF chain roots `test-fidtf-v030…` + `test-fidtf-v0324…` (legacy direct-bridge assertions → fail-closed contract, covering 25 chained files), `test-ves-rc-readiness-check.php` (20, egress stubs + v2 classifier), `test-ves-rc-strict-readiness-9c.php` (16, +json-only blocked scenario), `test-ves-release-candidate-page.php` (30, json-only + file-verified banners), 36 files version literals 1.2.6→1.2.7.

## 14. Verification outputs

In `command-output-v01-egress-evidence-v2.log`: `php -l` 18/18; `bash -n` 3/3; PHP 7.4 audit 4/4; security greps with per-hit classification (zero unclassified egress); **suite 193/193 from the tree and 193/193 from the clean ZIP extraction (559 files)**. ZIP SHA-256: `64132036cce27c7176303fcc9d9f415a6521b8d60d3ba21ff59c36f23e380643`.

## 15. Live validation status

**UNRUN — exact reason: this execution environment has no WordPress install, no WP-CLI, no MySQL and no browser**, so the v3 script, browser captures and file-backed recording could not be executed. No evidence archive, hash, screenshots or logs exist. Statically, the strict gate correctly reports blocked in this state.

## 16. Findings

| Sev | Location | Issue | Impact | Status / fix |
| --- | --- | --- | --- | --- |
| high | MarketSignal `run_apify_sync` (pre-phase) | direct run-sync dispatch with local token, no allowlist/ceiling | paid bypass | **FIXED** (routed through core gate) |
| high | DTF generic + TikTok adapters (pre-phase) | direct `wp_remote_post` run-start fallbacks | paid bypass | **FIXED** (fail-closed / core-routed) |
| high | evidence pack v1 (pre-phase) | `passed` computable without browser evidence; no file verification | forgeable validation | **FIXED** (schema 2.0 + file-backed recording) |
| medium | v2 script staging detection (pre-phase) | confirmation flag + crude URL pattern | wrong-environment runs | **FIXED** (v3 expected-host matching; v2 retained for compat but v3 is the documented path) |
| medium | egress visibility (pre-phase) | no central inventory | silent new egress | **FIXED** (inventory + readiness blocks + grep tests) |
| low | `set_status` logging (pre-phase) | success logged before DB proof | misleading diagnostics | **FIXED** + tested |
| low | MarketSignal admin-configured actors | now require allowlisting | operator must register actors before first run | documented behavior change (intentional, fail-closed) |
| note | OpenAI legacy direct calls | classified `ai_provider_legacy_requires_review` | none now | consolidation into `VES_OpenAI_Client` is roadmap |
| note | archive verification needs PharData | rare PHP builds without phar | recording falls back to `--evidence-root` | fails closed; documented in spec |

## 17. Final decision

- **Can this be reviewed?** Yes — diff (56 files, +1713/−275), log, report and ZIP are complete and reproducible.
- **Can this remain on dev/staging branch?** Yes — committed on `claude/trusting-dijkstra-7tdqsa` (draft PR #2).
- **Is it ready for live staging?** Yes — with v3 as the validation driver.
- **Is it ready for pilot review?** Not yet — that requires the file-backed live validation pass (strict mode enforces it).
- **Is it production-ready?** **No.** No live evidence exists; no code path can claim it.
- **Can AI generation be enabled?** **No.** Flag OFF; no execution path; AI egress classified and gated.
- **What remains before production?** (1) v3 script on real staging (`--expected-siteurl`, backup, collect-cli) → (2) browser checklist v3 with the 12 named screenshots + logs → (3) finalize mode → (4) `rc-record-live-validation --evidence-pack=… --evidence-root=…` → (5) `rc-readiness-check --strict` → `ready_for_pilot_review` → (6) operator approval, monitored pilot, final acceptance.
