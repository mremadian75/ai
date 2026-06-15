# Pilot Readiness Report

## Current status

The build is ready for real WordPress staging browser validation. It is not being claimed production-ready.

## Pilot route now supported

```text
Clean codebase
-> plugin activation readiness
-> migration readiness
-> workspace onboarding
-> Command Room UI
-> start first run
-> signed callback simulator
-> provider fixture ingestion
-> ingestion ledger
-> run timeline diagnostics
-> insight review
-> brief creation
-> draft generation
-> outcome capture
-> decision map
-> decision report export
-> evidence pack
-> browser validation checklist
```

## Ready for staging when

- the patched ZIP installs and activates cleanly;
- full lint and tests pass in staging/CI;
- browser screenshots are captured;
- signed callback simulator send-mode is tested against staging;
- bad signature/replay/private fixture rejection is confirmed;
- provider ingestion ledger and timeline show expected states;
- a private Decision Report export works;
- evidence pack is generated and archived.

## Not ready for public launch

- Real pilot users should not yet get unrestricted public self-serve access.
- External providers should not be connected until simulator + fixture path is validated.
- n8n should not be positioned as a required runtime.
- No autonomous publishing should be enabled.
