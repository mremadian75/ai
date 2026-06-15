# Private Pilot Acceptance Criteria

Status against the v0.9.34 build, validated in a live local WordPress admin.

## Ready for controlled private pilot

| Criterion | Status |
|---|---|
| P0 layout bugs fixed in live WordPress (or clearly validated env) | MET — live WP admin, real browser |
| No fatal/warning logs (plugin-caused) | MET — debug.log clean of plugin issues; 1 fatal + 1 deprecation found & fixed |
| Admin screens usable | MET — 25 screens render HTTP 200 |
| Manual/local/simulator loop works | MET — thin loop seeded end to end |
| Evidence-first surfaces readable | MET — Observed/Inferred, confidence/risk, lineage |
| Disabled actions explain blockers | MET — reasons rendered on disabled CTAs |
| No secrets/tokens visible | MET — 0 markers across rendered pages |
| Report/export safe | MET — private export, shareable=false, redacted |
| Operator guide complete | MET — PRIVATE_PILOT_OPERATOR_GUIDE.md |

## Not required for controlled private pilot

- Real third-party provider integrations.
- Direct publishing.
- Full billing automation.
- Advanced AEO/GEO.
- Real-time dashboard.
- Enterprise team roles.
- Predictive trend forecasting.
- Multi-LLM orchestration.

## Production blockers (none currently present — listed as gates to watch)

| Blocker | Present now? |
|---|---|
| Secret/token exposure | No |
| Unauthenticated mutation endpoint | No (only public route is signature-verified Stripe webhook) |
| Broken activation/migration | No (activates; 60 tables created) |
| P0 layout regression | No |
| Broken usage ledger for expensive actions | No (zero-cost beta; reserve/settle covered by tests) |
| AI outputs approved by default | No (review-led; drafts are candidates) |
| Raw private payload exposure | No (redaction enforced) |

## Decision

**Ready for controlled private pilot after live WordPress validation** — the live
validation has been performed in this sprint and passed. Production readiness is **not**
claimed.
