# v0.3.41 — Cross-platform data-scientist analysis + live GPT safety

## Live QA context

The latest live actor checks confirmed that TikTok, Instagram, Reddit, Google News and Google Trends can all return valid provider data, but they do not have the same analytical role. v0.3.41 upgrades the report so cross-platform synthesis is explicit and source-aware.

## Changes

- Added `cross_platform_intelligence` to final reports.
- Added source role diagnostics for TikTok, Instagram, Reddit, Google Trends, Google News and AI research.
- Added shared-language detection across source families.
- Added platform-bias warnings.
- Added data-scientist hypotheses to test.
- Strengthened OpenAI smoke-test JSON schema with cross-platform findings, source diagnostics, quantitative checks, hypotheses, risks and actions.
- Strengthened the live GPT prompt to act as a senior marketing data scientist and to avoid unsupported claims.

## Safety

Chat-pasted OpenAI API keys are treated as compromised and must not be used by the add-on. Live GPT execution should happen only from server-side configuration or the admin runtime.
