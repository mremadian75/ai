# Optional n8n Workflow Pack Notes

Optional n8n example. Future Island does not require n8n.

The included n8n files are sample operator assets under:

```text
integrations/examples/n8n/OPTIONAL_future-island-provider-callback-template.json
integrations/examples/n8n/README_OPTIONAL_N8N_PROVIDER_CALLBACK.md
```

They demonstrate how one external workflow tool can create a signed provider callback. They are not the required staging runtime, not the canonical provider runtime, and not the next-sprint dependency.

The required staging path is:

```text
signed callback simulator or approved external orchestrator
-> authenticated WordPress REST endpoint
-> provider contract validation
-> canonical Future Island records
```

Use optional n8n only when an operator intentionally chooses that route. QA, fixture trials, evidence-pack generation, report export, and staging validation must pass without n8n.
