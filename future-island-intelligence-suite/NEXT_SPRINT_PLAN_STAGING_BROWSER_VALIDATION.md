# Next Sprint Plan — Staging Browser Validation

## Goal

Validate the orchestration-agnostic staging build in a real WordPress staging site and patch only evidence-backed bugs.

## Steps

1. Install on WordPress staging.
2. Configure provider callback secret.
3. Run signed callback simulator.
4. Run fixture callbacks.
5. Confirm bad signatures are blocked.
6. Confirm replay/idempotency is blocked.
7. Confirm invalid private fixtures are rejected.
8. Validate Provider Ingestion Ledger.
9. Validate Run Timeline and diagnostics.
10. Validate Review Queue, Brief Builder, Draft Workbench, Memory Review, Outcome Capture, Pattern preview, Decision Map, and Decision Report.
11. Generate release evidence pack.
12. Optionally test the n8n example if the staging operator chooses that route.
13. Complete browser validation across desktop/tablet/mobile widths.
14. Patch only bugs supported by staging evidence.

## Not in scope

- No new major features.
- No unsafe scraping.
- No public self-serve launch.
- No direct database writes by external orchestrators.
- No mandatory n8n dependency.
