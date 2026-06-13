# v0.3.43 — Full live actor QA + GPT data-scientist hardening

Live QA findings applied:

- TikTok/Clockworks can return `itemCount > 0` and `cleanItemCount = 0`; keep `clean=false`.
- Instagram official scraper must use direct hashtag URLs for post evidence.
- Reddit can return high-engagement but weak/off-topic evidence; provider query provenance is not proof.
- Google Trends keyword mode returns nested `timeline_data`.
- Google News can produce acronym/entity collisions, for example SEO as a publisher/entity rather than search-engine optimization.

Fixes:

- Exclude Google News/Reddit source or community names from semantic query matching.
- Add an SEO acronym context gate for news/community evidence.
- Expand OpenAI smoke-test JSON schema with triangulation matrix, confidence score, evidence quality score, and must-not-claim guardrails.
- Strengthen the GPT prompt to behave like a senior marketing data scientist comparing all source families.
