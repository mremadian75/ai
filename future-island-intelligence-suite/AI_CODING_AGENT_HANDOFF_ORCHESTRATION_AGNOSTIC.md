# AI Coding Agent Handoff — Orchestration-Agnostic Correction

## Current truth

Future Island is orchestration-agnostic. n8n is optional only. Do not present n8n as the required staging path, provider runtime, orchestrator, or next-sprint dependency.

## Required callback path

```text
Approved external worker / simulator / optional orchestrator
-> signed provider callback
-> WordPress REST endpoint
-> provider contract validation
-> canonical records
```

## Files changed in this sprint

- `includes/class-ves-workspace-onboarding-service.php`
- `includes/class-ves-operator-qa-service.php`
- `includes/class-ves-run-diagnostics-service.php`
- `includes/class-ves-provider-admin-page.php`
- `includes/class-ves-decision-report-service.php`
- `includes/modules/command-room/class-fi-command-room-renderer.php`
- `assets/css/fiis-ui-system.css`
- `bin/build-staging-trial-evidence-pack.sh`
- `integrations/examples/n8n/*`
- new correction/report/test files

## Next agent rules

- Preserve signed callback simulator as the default staging validation route.
- Treat n8n as an optional example only.
- Never let external tools write directly to canonical tables.
- Keep provider payloads redacted in UI, logs, reports, and screenshots.
- Keep memory as context, not evidence.
- Keep review actions human-led and append-only.
