# Future Island — Live Browser Validation Checklist v3 (Phase 9E.4)

Operator: ____________ Date: ____________ Staging URL: ____________
Evidence folder: `fi-evidence-<timestamp>/`

**This is human visual evidence, not automated proof.** The smoke script only
checks URLs respond; every item below needs a human eye and a saved artifact.
Evidence Pack v2 cannot compute `passed` without these files — exact names
matter, they are hashed into the pack. If any item fails, the validation fails;
record it honestly.

## Required screenshots → `screenshots/`

| File (exact name) | Must show |
| --- | --- |
| `01-signal-room.png` | workflow spine, operator queue, readiness strip, honest empty states |
| `02-social-media-signal-report.png` | Informe de señales, Evidence Snapshot, Observed/Inferred/Cannot-conclude |
| `03-evidence-gate-blocked.png` | an insight WITHOUT evidence blocked from approval |
| `04-evidence-gate-ready.png` | an insight WITH evidence in a ready/approvable state |
| `05-operator-queue.png` | queue counts matching `wp ves operator-queue --workspace=1 --format=json` |
| `06-memory-brand-context.png` | trusted vs candidate separation; memory-is-not-evidence notice |
| `07-generation-context-preview.png` | candidate/rejected/archived/expired excluded |
| `08-prompt-package-preview.png` | blocked package hides context; `provider_execution_allowed: false` |
| `09-brief-workbench.png` | evidence binder; disabled/safe review controls; NO generate button |
| `10-draft-workbench.png` | output slots; disabled/safe review controls; NO publish button |
| `11-release-candidate-page.png` | rails table + evidence-state banner; no production-ready claim |
| `12-security-dead-letter-diagnostics.png` | Audit & rails section (decisions, dead letters, security events) |

## Required logs → `logs/`

| File (exact name) | Rule |
| --- | --- |
| `browser-console.log` | console export from each page; if clean, file must contain `NO_CONSOLE_ERRORS_OBSERVED` |
| `network-errors.log` | failed requests export; if clean, file must contain `NO_NETWORK_ERRORS_OBSERVED` |
| `php-error-log-tail.log` | last ~300 lines, redacted (the v3 script captures this) |

The files must EXIST even when empty of findings — absence blocks `passed`.

## Required notes

- [ ] Did ANY **Generate / Publish / Auto-approve** button appear anywhere? (must be NO — note where if yes)
- [ ] Spanish/English copy consistent on the pilot surfaces
- [ ] No fake/placeholder metrics observed

## Responsive / UX manual QA (Phase 3 — workbench rails + intake)

Resize (or device-emulate) at **320, 375, 430, 768 and 1024 px** widths and confirm on
the Brief Workbench, Draft Workbench, Intake page and Release Candidate page:

- [ ] ≥1101px: workbench shows THREE rails — evidence (left), object (center), decision/status (right)
- [ ] ≤1100px: evidence rail folds BELOW object + decision (two columns, then full-width evidence)
- [ ] ≤782px: rails stack to ONE column in order object → decision → evidence; no horizontal scrolling of the page itself
- [ ] 320–430px: no clipped text; long mono strings (hashes, URLs) wrap or scroll inside their card, never the viewport
- [ ] Decision-status card shows: state badge + what it means + a concrete "Next:" action — on every workbench state
- [ ] Intake forms are usable with keyboard only (tab order top→bottom; skip link lands on recent objects)
- [ ] RC "Staging checklist" table scrolls horizontally inside its section on small screens
- [ ] Focus outlines visible on every interactive control (focus-visible)
- [ ] prefers-reduced-motion honored (no animation observed when enabled)

## Flow

1. `bash scripts/future-island-browser-smoke.sh --base-url=…` (reachability only).
2. Walk every surface, capture the 12 screenshots with the exact names above.
3. Export console + network logs (or write the explicit NO_*_OBSERVED markers).
4. Finalize: `bash scripts/future-island-live-validation-v3.sh --mode=finalize --evidence-dir=… --screenshots-dir=… --browser-console-log=… --network-errors-log=… --php-error-log=… --expected-siteurl=… --i-confirm-this-is-staging`
5. Record: `wp ves rc-record-live-validation --evidence-pack=…/evidence-pack-v2.json --evidence-root=…`
6. Gate: `wp ves rc-readiness-check --strict --format=json`

Result: ☐ PASSED ☐ FAILED — Signature: ____________
(A pass here still does NOT make the build production-ready.)
