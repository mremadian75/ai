# Updated Release Evidence Pack Notes

The evidence pack generator is orchestration-agnostic.

It collects:

- timeout-safe test logs when present;
- provider fixture trial logs when present;
- correction reports;
- operator QA playbook;
- staging validation guide;
- callback guide;
- security notes;
- optional integration examples when present.

## Optional n8n behavior

Optional n8n assets are copied only if present under:

```text
integrations/examples/n8n/
```

Evidence-pack generation must not fail when n8n assets are absent.

## Required proof

The evidence pack should prove the signed callback simulator and approved external orchestrator pattern, not a tool-specific n8n dependency.
