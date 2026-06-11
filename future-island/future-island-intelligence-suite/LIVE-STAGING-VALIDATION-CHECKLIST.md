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
8. - [ ] Brief Workbench — insight summary, evidence binder, disabled/safe review controls, NO generate button
9. - [ ] Draft Workbench — brief summary, output slots, disabled/safe review controls, NO publish button
10. - [ ] Release Candidate page — shows UNRUN until step F; never claims production-ready
11. - [ ] Any error state encountered (screenshot + console)

## E. Logs

- [ ] Browser console captured on each page (no uncaught errors)
- [ ] Failed network requests captured (none unexplained)
- [ ] PHP error log last ~300 lines captured and sanitized (no secrets)

## F. Sign-off

- [ ] All items above pass with evidence attached
- [ ] `wp option update ves_rc_live_validation '{"status":"passed","recorded_at":"<UTC>","note":"<operator>"}' --format=json`
- [ ] `wp ves rc-readiness-check --format=json` now shows `live_validation.status: passed` and still `production_ready: false`

Failures found (attach list): ____________________________________________

Result: ☐ PASSED ☐ FAILED — Signature: ____________
