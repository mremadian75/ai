# Command Room Browser Validation Guide

## Required staging route

Use this route in a real WordPress browser session:

```text
Install plugin
-> activate plugin
-> open Command Room
-> complete onboarding
-> start Brand & Market X-Ray
-> run signed callback simulator dry-run
-> send valid signed callback on staging
-> inspect Provider Ingestions
-> inspect Run Timeline diagnostics
-> review Insight
-> create Brief
-> generate Draft
-> record Outcome
-> inspect Decision Map
-> export private Decision Report
-> build evidence pack
```

## Browser pages to open

- Future Island → Command Room
- Future Island → Provider Contracts
- Future Island → Provider Ingestions
- Future Island → Beta QA
- Future Island → Browser Validation

## Command Room checks

- Page title and product context are visible.
- Onboarding panel shows progress and editable profile.
- Provider mode copy is orchestration-agnostic.
- Playbook launcher includes Brand & Market X-Ray.
- Evidence/object drawer is visible.
- Run timeline is visible after a run exists.
- Review queue actions are visible when objects exist.
- Decision Map preview is visible for a selected run.
- No raw provider payload, token, signature, cookie, or secret is displayed.

## Pass condition

A private-beta operator can complete the route without database edits and without relying on n8n.
