# v0.3.44 - Full live cross-platform GPT contract hardening

## Changed

- Bumped add-on to v0.3.44.
- Added multi-platform OpenAI smoke sample preparation.
- Increased GPT smoke-test evidence sample capacity from 5 to 25 rows.
- Added `sample_profile` diagnostics for OpenAI smoke-test output.
- Expanded OpenAI JSON-schema contract with:
  - `platform_interaction_map`
  - `claim_audit`
  - `follow_up_test_design`
  - `sample_limits`
  - `data_scientist_verdict`
- Added Reddit/Google News marketing-intent context gate to reduce celebrity/community/news drift.
- Added v0.3.44 regression test.

## Live QA notes

- TikTok, Instagram, Reddit, Google News and Google Trends have live-confirmed output shapes from the current QA cycle.
- Google Trends may be long-running; non-terminal states remain retryable/pollable.
- TikTok/Instagram often have `cleanItemCount=0` while valid rows exist; keep `clean=false`.
- Reddit and Google News can produce literal keyword matches that are not strategic proof.
