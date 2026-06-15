# Next Sprint Plan — Controlled Customer Pilot

## Decision

The build is a controlled-private-pilot candidate, validated on a live local WordPress.
The next sprint runs the pilot with real operators and fixes only confirmed blockers. It
is **not** a feature sprint.

## Immediate next actions

1. Install the patched ZIP on the customer's actual staging host (MySQL + production theme).
2. Run the confirmation pass: activate, re-shoot the previously-broken screens, confirm
   no admin-bar/footer overlap with the real theme, confirm redaction with **real**
   provider rows (signed callback simulator), walk the thin loop.
3. Onboard 1–3 pilot operators using `PRIVATE_PILOT_OPERATOR_GUIDE.md` and the demo script.
4. Capture operator feedback against `PRIVATE_PILOT_READINESS_CHECKLIST.md`.

## In scope (only if a pilot blocker is confirmed)

- Scoped CSS/copy/helper fixes; admin readability; redaction hardening.
- Seeding the provider ingestion ledger via the signed callback simulator for the demo.
- Per-page capability tightening if non-admin operator roles are introduced.

## Do not build yet

- Real third-party provider integrations as default.
- Direct publishing / social scheduler.
- Multi-LLM orchestration, predictive trends, real-time dashboards.
- Full billing/plan expansion, enterprise team roles, advanced AEO/GEO.

## Exit criteria for the pilot

- Operators complete the thin loop unaided and produce at least one evidence-backed
  Decision Report.
- No secret/token exposure, no fatal/warning in logs on the customer host.
- A short list of prioritized, confirmed blockers (if any) for the following sprint.

## Product reminder

One working, evidence-backed, reviewable loop beats ten planned modules.
