# Private Pilot Risk Register

Severity / likelihood scale: Low / Medium / High.

| # | Risk | Severity | Likelihood | Mitigation | Owner | Pilot decision impact |
|---|---|---|---|---|---|---|
| 1 | Live WP admin CSS conflict (production theme/admin bar differs from validated env) | Medium | Medium | Plugin styles are scoped; footer/overlap validated; one confirmation pass on customer theme | Eng | Not a blocker; confirm on staging |
| 2 | Provider integration instability | Medium | Medium | Providers are optional; manual + signed-callback simulator paths are the pilot default | Eng/Ops | Out of pilot scope; keep optional |
| 3 | Missing AI/provider credentials | Low | High | Overview warning explains what works without keys; thin loop runs credential-free | Ops | Expected; documented in operator guide |
| 4 | Evidence quality confusion (operators over-trust thin evidence) | Medium | Medium | "limited evidence" labels; Observed vs Inferred; memory labeled as context | Product | Reinforce in demo + guide |
| 5 | Usage/credit misinterpretation | Low | Medium | Zero-cost beta; usage ledger visible; reserve/settle idempotent | Product | Explain in onboarding |
| 6 | Operator confusion (where to start, what each screen means) | Medium | Medium | Operator guide + demo script + Overview that lists every screen | Product | Mitigated by docs |
| 7 | Spanish/English copy inconsistency on non-core surfaces | Low | Medium | Core surfaces Spanish-first; full i18n is later roadmap | Product | Cosmetic; not a blocker |
| 8 | Secret exposure | High | Low | 27/27 REST routes gated; redaction enforced; 0 markers in live render | Security | Blocker if it ever appears; none now |
| 9 | Scope creep (pilot pressure to add providers/publishing/dashboards) | Medium | High | Explicit "do not build yet" list; review-led product identity | Lead | Guard actively |
| 10 | Overclaiming maturity (calling it production-ready) | Medium | Medium | Final decision language fixed to "controlled private pilot after live WP validation"; never "production-ready" | Lead | Communications discipline |

## Top watch-items for the pilot window

- #8 (secrets) — any sighting is an immediate stop-ship for the affected surface.
- #9 (scope creep) — keep the loop thin; one working evidence-backed loop beats new modules.
- #1 (theme/admin conflict) — first action on the customer host is the confirmation pass.
