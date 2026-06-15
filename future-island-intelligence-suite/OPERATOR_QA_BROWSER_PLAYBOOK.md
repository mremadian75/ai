# Operator QA Browser Playbook

## Required checks

- Plugin activates with no PHP warnings.
- Migration/readiness screens load.
- Workspace onboarding can be saved.
- Brand & Market X-Ray can create or select a Run.
- Signed callback simulator is available.
- Fixture dry-run passes.
- Valid signed callback is accepted on staging.
- Bad signature is blocked.
- Replay/idempotency is blocked.
- Invalid private fixture is rejected.
- Provider ingestion ledger is visible.
- Run Timeline is visible.
- Decision Report export works privately.
- Optional n8n template is present only as an optional example if included.

## QA path

```text
Command Room
-> Browser Validation
-> Provider Contracts
-> signed callback simulator
-> Provider Ingestions
-> Run Timeline
-> Review Queue
-> Decision Map
-> Decision Report
-> Evidence Pack
```

## Failure policy

Any staging bug must be classified as:

- warning cleanup regression;
- activation/migration issue;
- callback authentication issue;
- provider contract issue;
- UI/browser issue;
- evidence/report issue;
- environment-only issue.

Patch only evidence-backed staging bugs before inviting pilot users.
