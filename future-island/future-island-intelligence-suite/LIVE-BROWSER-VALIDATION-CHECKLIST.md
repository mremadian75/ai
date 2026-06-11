# Future Island — Live Browser Validation Checklist v2 (Phase 9C.2)

Operator: ____________ Date: ____________ Staging URL: ____________
Evidence folder: `fi-evidence-<timestamp>/screenshots/`

Run `scripts/future-island-browser-smoke.sh --base-url=…` first for URL
reachability, then complete every item below **manually with screenshots**.
The smoke script cannot replace this checklist. If any item fails, the
validation fails — record it honestly.

## Required screenshots

1. - [ ] **Signal Room** — workflow spine, operator queue, readiness strip, honest empty states, no fake charts
2. - [ ] **Social Media / Signal Report** — Informe de señales, Evidence Snapshot, Observed / Inferred / Cannot conclude
3. - [ ] **Evidence Gate** — BOTH states: an insight without evidence blocked, and one with evidence ready
4. - [ ] **Operator Queue** — counts match `wp ves operator-queue --workspace=1 --format=json`
5. - [ ] **Memory / Brand Context** — trusted vs candidate separated; rejected/archived/expired diagnostics; memory-is-not-evidence notice
6. - [ ] **Generation Context Preview** — candidate/rejected/archived/expired excluded
7. - [ ] **Prompt Package Preview** — blocked package hides full context; `provider_execution_allowed: false`
8. - [ ] **Brief Workbench** — evidence binder, disabled/safe review controls, NO generate button
9. - [ ] **Draft Workbench** — output slots, disabled/safe review controls, NO publish button
10. - [ ] **Release Candidate page** — rails table (provider fail-closed, charge ceiling, workspace guard, review ledger, settlement, trend idempotency, dead-letter, security log), live-validation banner state, no production-ready claim
11. - [ ] **Security / dead-letter diagnostics** — the Audit & rails section on the RC page (or honest "unavailable" state)
12. - [ ] **Any error state encountered** — with its support code

## Required exports

- [ ] Browser console log for each page (saved as `browser-console-<page>.txt`)
- [ ] Failed network requests export (HAR or list) — none unexplained
- [ ] PHP error log tail (~300 lines, redacted) — captured by the validation script
- [ ] Explicit note: did ANY **Generate / Publish / Auto-approve** button appear anywhere? (must be NO)

## Sign-off

- [ ] All screenshots saved into the evidence folder before the pack hash is finalized
- [ ] `wp ves rc-record-live-validation --evidence-pack=…` run ONLY if everything above passed
- [ ] `wp ves rc-readiness-check --strict --format=json` output saved

Result: ☐ PASSED ☐ FAILED — Signature: ____________
