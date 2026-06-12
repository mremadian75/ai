# Future Island v0.1 RC — Live Staging Validation Checklist

Operator: ____________  Date: ____________  Staging URL: ____________

Every box must be checked with real evidence (command output captured to a log
file; screenshots saved). **Never run `--apply` during validation. Never enable
AI execution. If any item fails, the validation fails — record it honestly.**

## A. Environment

- [ ] `php -v` recorded (7.4+ / 8.x)
- [ ] `wp --info` recorded
- [ ] `wp core version` recorded
- [ ] `wp db query "SELECT VERSION();"` recorded
- [ ] `wp option get siteurl` is the STAGING url, not production
- [ ] `wp plugin list` and `wp theme list` recorded
- [ ] DB backup exists: `staging-backup-*.sql` (path noted)

## B. Install

- [ ] RC ZIP SHA-256 matches the published checksum
- [ ] `wp plugin install <zip> --force` succeeded
- [ ] `wp plugin activate future-island-intelligence-suite` succeeded
- [ ] No PHP fatals in the error log during activation

## C. CLI validation (read-only — exit codes recorded)

- [ ] `wp ves verify-schema` — no missing tables
- [ ] `wp ves validate-staging --format=json` — not `not_ready`
- [ ] `wp ves readiness-check --format=json`
- [ ] `wp ves rc-readiness-check --format=json` — `ready_for_staging` or `ready_with_warnings`; `production_ready: false`; warnings reviewed
- [ ] `wp ves memory-summary --format=json`
- [ ] `wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json` — only trusted memory appears
- [ ] `wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json`
- [ ] `wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=<id> --format=json` — bounded, redacted, `provider_execution_allowed: false`
- [ ] `wp ves operator-queue --workspace=1 --format=json` — real queue counts, no fabricated rows
- [ ] `wp ves memory-expire --dry-run` — reports only, writes nothing
- [ ] (optional) `wp ves trend-verify-staging`, `wp ves trend-evaluate`, `wp ves trend-summary`, `wp ves trend-obs-backfill --dry-run --limit=100`

## D. Browser validation (screenshot each)

1. - [ ] Signal Room — workflow spine, operator queue, readiness strip, honest empty states, no fake charts
2. - [ ] Social Media / Signal Report — Informe de señales, Evidence Snapshot, Observed / Inferred / Cannot conclude
3. - [ ] Evidence Gate — an insight without evidence shows blocked, with no approve path
4. - [ ] Operator Queue — items match CLI output
5. - [ ] Memory / Brand Context — trusted vs candidate separated; rejected/archived/expired only as diagnostics; memory-is-not-evidence notice
6. - [ ] Generation Context Preview — candidate/rejected/archived/expired excluded
7. - [ ] Prompt Package Preview — blocked package hides full context; no raw prompt anywhere
8. - [ ] Brief Workbench — three rails (evidence / object / decision), decision card with next action, REAL approve/reject (nonce-protected) on reviewable insights, evidence-gate refusal surfaces as a notice, NO generate button
9. - [ ] Draft Workbench — brief summary, output slots, review rail, prompt-package preview, feedback cards, NO publish button
10. - [ ] Release Candidate page — Staging checklist section; shows UNRUN until step F; never claims production-ready
11. - [ ] Any error state encountered (screenshot + console)

## D2. Phase 4/5 surfaces (walk + screenshot)

1. - [ ] Intake — route spine with live counts, Next panel, 01/02 source cards, prefill row actions ("Record signal →", "Promote →") carry context without ID copying
2. - [ ] Intake URL reference — unsafe URLs (ftp/credentialed/javascript) refused with the recorded-only message; NOTHING fetched (network tab clean)
3. - [ ] Insight row actions — "Build brief" + "Memory candidate" only on APPROVED insights; brief carries evidence ids
4. - [ ] Brief row "Preview + usage" — lands on the draft workbench with the preview notice; double-click ledgers ONE usage event (verify via Loop Trace)
5. - [ ] Pilot Readiness — verdict answers the pilot question honestly; gates table matches CLI state; seed → walk scenario C review → trace insight → reset (confirmation enforced)
6. - [ ] Feedback — record one feedback row from a workbench rail; it appears under Recent pilot feedback

## E. Logs

- [ ] Browser console captured on each page (no uncaught errors)
- [ ] Failed network requests captured (none unexplained)
- [ ] PHP error log last ~300 lines captured and sanitized (no secrets)

## F. Sign-off

- [ ] All items above pass with evidence attached
- [ ] Record ONLY through the verified, file-backed evidence pack (a manual
      `wp option update ves_rc_live_validation …` is classified
      **unverified_manual** and is NOT trusted):
      `wp ves rc-record-live-validation --evidence-pack=…/evidence-pack-v2.json --evidence-root=…`
- [ ] `wp ves rc-readiness-check --format=json` now shows `live_validation.status: passed` and still `production_ready: false`

Failures found (attach list): ____________________________________________

Result: ☐ PASSED ☐ FAILED — Signature: ____________
