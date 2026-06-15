# Future Island Orchestrator-Agnostic Provider Callback Guide

Future Island staging trials are orchestration-agnostic. The required product path is the signed provider callback into WordPress, not any specific external workflow tool.

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

## Approved callback sources

Approved callbacks can come from:

- internal signed callback simulator;
- custom worker;
- cron job;
- Apify actor wrapper;
- Make/Zapier-style workflow;
- optional n8n example;
- future managed Future Island worker.

## Canonical boundary

WordPress remains the canonical product shell, permission layer, review UI, and record layer.

External tools never write directly to canonical tables.

External tools return results only through authenticated WordPress endpoints.

## REST callback contract

External workers post rows to one of the private-beta provider endpoints:

```text
/wp-json/fi/v1/provider/social/ingest
/wp-json/fi/v1/provider/aeo/ingest
/wp-json/fi/v1/provider/creative/ingest
```

Every request must include:

```text
X-FI-Provider-Key
X-FI-Timestamp
X-FI-Signature
X-FI-Idempotency-Key
```

The signature base string is:

```text
timestamp + "." + raw_body
```

The signature is an HMAC SHA-256 digest sent as:

```text
sha256=<computed_hex_digest>
```

## Required operator flow

1. Install the plugin on WordPress staging.
2. Configure the provider callback secret and provider allowlist.
3. Create or select a private-beta workspace.
4. Create a canonical Run from Command Room / onboarding / playbook.
5. Run the signed callback simulator against a fixture.
6. Confirm valid signed callback accepted.
7. Confirm bad signature blocked.
8. Confirm replay/idempotency blocked.
9. Confirm invalid private fixture rejected and not promoted to evidence.
10. Review Provider Ingestion Ledger.
11. Review Run Timeline and diagnostics.
12. Review Insights, Briefs, Drafts, Memory, Outcomes, and Patterns.
13. Export the private Decision Report.
14. Generate the evidence pack.
15. Optionally test the n8n example if that route is chosen.

## Optional n8n example

The n8n files are examples only. Future Island does not require n8n for staging validation, provider ingestion, QA, or evidence-pack success. Use them only when the chosen staging operator already wants to trial n8n.

## Prohibited patterns

- Do not give external workers direct database access.
- Do not write directly to Future Island canonical tables from n8n, Make, Zapier, Apify, cron, or custom scripts.
- Do not send provider secrets in URLs.
- Do not expose HMAC secrets, signatures, bearer tokens, cookies, raw provider bodies, or API keys in logs, screenshots, reports, or exports.
- Do not promote rejected/private/invalid provider rows into evidence.
- Do not scrape private or logged-in content.

## Staging-safe fallback

When no external orchestrator is chosen, the signed callback simulator remains the canonical staging path. It can print redacted curl commands, perform dry-runs, send valid callbacks, generate bad signatures, generate stale timestamps, and test replay/idempotency behavior.
