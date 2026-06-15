# Future Island Orchestration-Agnostic Staging Trial Correction Report

## Sprint purpose

This correction sprint patches the staging-trial build so Future Island remains orchestration-agnostic. n8n is retained only as an optional example template. The required staging path is the signed provider callback into WordPress, followed by provider contract validation and canonical Future Island records.

## Corrected product path

```text
Approved external worker / simulator / optional orchestrator
-> signed provider callback
-> WordPress REST endpoint
-> provider contract validation
-> canonical Run
-> Run Timeline
-> Signal / Evidence
-> Insight
-> Brief
-> Draft
-> Memory
-> Usage
-> Outcome
-> Pattern
-> Decision Report
```

## What changed

- Moved the n8n example assets to `integrations/examples/n8n/`.
- Renamed the n8n files with `OPTIONAL_` naming.
- Added `ORCHESTRATOR_AGNOSTIC_CALLBACK_GUIDE.md`.
- Added `OPTIONAL_N8N_WORKFLOW_PACK_NOTES.md`.
- Updated onboarding provider modes to:
  - `manual_only`
  - `signed_callback_simulator`
  - `approved_external_orchestrator`
  - `optional_n8n_example`
  - `provider_disabled`
- Updated Run Diagnostics copy so the next action is simulator or approved external orchestrator first.
- Updated Operator QA so it can pass without n8n.
- Updated evidence pack generation to collect optional n8n examples only if present.
- Added orchestration-agnostic tests.
- Added a UI/UX private-beta operator upgrade pass.

## Product guardrail restored

WordPress remains the canonical product shell, permission layer, review UI, and record layer. External tools never write directly to canonical tables. External tools return results only through authenticated WordPress endpoints.

## n8n status

n8n remains available only as an optional example for operators who choose it. Future Island staging validation, fixture trials, report export, QA, and evidence-pack generation do not require n8n.
