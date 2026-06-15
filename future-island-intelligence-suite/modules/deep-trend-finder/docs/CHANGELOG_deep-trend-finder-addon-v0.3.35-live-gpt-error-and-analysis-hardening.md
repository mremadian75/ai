# Changelog — v0.3.35 live GPT error and analysis hardening

## Added
- OpenAI/ChatGPT provider-error diagnostics in the AI planner bridge.
- Support for ChatGPT/OpenAI array content blocks in planner responses.
- Support for array content blocks in external final synthesis responses.
- Refusal detection for Responses API style content blocks.
- Incomplete/failed/cancelled response rejection for planner and final synthesis bridges.
- Regression coverage for provider errors, redaction, refusals, incomplete responses, and external synthesis block parsing.

## Fixed
- OpenAI provider errors are no longer treated as candidate source plans.
- Incomplete model responses can no longer accidentally set external synthesis mode.
- API keys inside provider error messages are redacted from stored diagnostics.
- ChatGPT array content responses are now parsed before strict source-plan validation.

## QA
- PHP lint: 70 files.
- JS syntax check: passed.
- PHP test files: 40.
- Latest cumulative regression: 397 / 397.
