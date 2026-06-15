# Updated Staging Validation Guide — Orchestration-Agnostic

## Install and prepare

1. Install the patched plugin on a WordPress staging site.
2. Run plugin migrations.
3. Configure provider callback secret and provider allowlist.
4. Create or resolve a private-beta workspace.
5. Complete onboarding with an orchestration-agnostic provider mode.

## Provider modes

- `manual_only`
- `signed_callback_simulator`
- `approved_external_orchestrator`
- `optional_n8n_example`
- `provider_disabled`

Do not make n8n the default or required path.

## Staging validation sequence

1. Start Brand & Market X-Ray or another guided playbook.
2. Confirm a canonical Run exists.
3. Run the signed callback simulator with a valid provider fixture.
4. Confirm accepted rows enter the Provider Ingestion Ledger.
5. Run bad-signature and stale-timestamp simulator modes.
6. Confirm blocked callbacks do not create evidence.
7. Run replay/idempotency mode.
8. Confirm replay blocked.
9. Run invalid private fixture.
10. Confirm rejected rows remain ledger/log records only.
11. Open Run Timeline and diagnostics.
12. Open Review Queue.
13. Create or review Brief/Draft candidates.
14. Record a manual outcome if applicable.
15. Export Decision Report.
16. Generate the release evidence pack.
17. Optionally test the n8n example only if that route is chosen.

## Browser validation surfaces

Validate these admin/product surfaces:

- Command Room
- Workspace onboarding
- Provider contracts/settings
- Provider ingestion ledger
- Run Timeline
- Run diagnostics
- Review Queue
- Brief Builder
- Draft Workbench
- Memory Review
- Outcome Capture
- Pattern Observation preview
- Decision Map
- Decision Report preview/export
- Operator QA
- Private beta readiness
- Evidence pack notes
