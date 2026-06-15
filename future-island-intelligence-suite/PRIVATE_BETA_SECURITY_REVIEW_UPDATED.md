# Private Beta Security Review — Updated

## Preserved controls

- WordPress remains the canonical product shell, permission layer, review UI and record layer.
- External workers return rows only through authenticated WordPress endpoints.
- n8n remains optional only.
- Provider callbacks use HMAC/replay/idempotency controls from prior sprint.
- Provider rows are validated before they can become canonical signals/evidence.
- Failed or invalid rows are logged but not promoted.
- Raw provider payloads, signatures, secrets, cookies, bearer tokens and API keys are not shown in admin UI, logs, reports or snippets.
- Memory remains context, not evidence.
- No public share/report links were added.

## New controls from this sprint

- Browser Validation admin page makes the safe staging route visible to operators.
- Onboarding save form keeps provider mode explicit and orchestration-agnostic.
- Family-specific fixture trial defaults reduce incorrect staging callback setup.
- Warning cleanup avoids hidden PHP warning noise during staging.

## Remaining security gates before pilot

- Verify callback secret configuration on real staging.
- Verify HTTPS endpoint only.
- Verify debug logs on staging contain no secrets.
- Verify valid/bad/replay callback cases in browser and logs.
- Verify user capabilities for non-admin beta users before expanding access.
