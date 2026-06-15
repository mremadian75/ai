# Next Sprint Plan — Private Pilot Prep

## Decision

The patched build is ready for controlled staging re-validation. It is not a reason to add new modules. The next sprint should validate the full pilot loop in a real browser with operator notes and then fix only confirmed blockers.

## Immediate next actions

1. Install the patched ZIP on the WordPress staging site.
2. Re-run the browser checklist using the regenerated snippets as comparison targets.
3. Validate the core pilot route:

```text
Manual/URL Source
-> Run
-> Signal Item
-> Insight
-> Brief
-> Draft
-> Memory Record
-> Usage Event
-> Decision Report
```

4. Confirm that disabled actions show clear reasons before lifecycle requirements are met.
5. Confirm no token values, HMAC secrets, signatures, raw callback payloads, API keys, cookies, or bearer tokens appear in UI/log/report surfaces.
6. Capture fresh browser screenshots for Social results, Object Flow, Intelligence Map, Overview warnings, Provider Settings, Plans & Access, and Decision Report.

## Do not build yet

- Real third-party provider integrations.
- Direct publishing/social scheduler behavior.
- Heavy graph product features.
- Multi-LLM orchestration.
- Predictive trends.
- Full billing/plan expansion.

## Later roadmap

- Proper i18n layer for full Spanish/English/Farsi coverage.
- Live browser screenshot automation.
- Usability scoring from operator QA runs.
- Better selected-node inspector for Decision Map after pilot usage confirms need.
