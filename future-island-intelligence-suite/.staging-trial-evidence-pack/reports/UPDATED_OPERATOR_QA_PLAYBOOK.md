# Updated Operator QA Playbook — Orchestration-Agnostic Staging Trial

## Required QA path

The QA path must pass without n8n.

```text
signed callback simulator or approved external orchestrator
-> signed provider callback
-> WordPress REST endpoint
-> provider validation
-> provider ingestion ledger
-> run timeline
-> decision report
```

## Required checks

- Callback simulator available.
- Provider fixture dry-run available.
- Valid signed callback accepted.
- Bad signature blocked.
- Replay/idempotency blocked.
- Invalid private fixture rejected and not promoted into evidence.
- Provider ingestion ledger visible.
- Run Timeline visible.
- Run diagnostics visible.
- Review Queue visible.
- Decision Report export works.
- Evidence pack generated.
- Optional n8n template present only if included.
- No raw provider payloads, signatures, cookies, bearer tokens, API keys, or HMAC secrets displayed.

## Optional n8n check

Only run this section when the chosen staging operator explicitly selects the optional n8n route. The optional n8n template is not required for QA success.

## Operator notes

Use the Operator QA screen to record manual validation notes. Keep screenshots redacted. Do not include secrets or raw callback bodies in reports.
