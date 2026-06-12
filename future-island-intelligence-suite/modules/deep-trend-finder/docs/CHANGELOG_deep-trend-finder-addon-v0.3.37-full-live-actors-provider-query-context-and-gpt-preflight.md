# Changelog — v0.3.37

## Added

- Provider-query context enrichment for raw Apify dataset rows.
- Regression coverage for provider-query-only false positives.
- Regression coverage for live-style OpenAI Responses API `output_text` payloads across all selected sources.
- Full live QA documentation for TikTok, Instagram, Reddit, Google Trends, Google News and GPT bridge status.

## Fixed

- Reddit/high-engagement rows no longer become showable evidence solely because the actor search query matched the request.
- Raw actor rows that omit query provenance can inherit safe query context from actor input for diagnostics and scoring.
- Instagram direct hashtag URL rows preserve the hashtag as provider query for auditability.

## Notes

- Live GPT API execution still requires a secure runtime key in WordPress/admin env. A key pasted into chat is compromised and must be revoked.
