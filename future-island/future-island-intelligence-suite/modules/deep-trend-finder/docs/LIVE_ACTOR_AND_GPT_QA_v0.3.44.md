# LIVE ACTOR AND GPT QA - v0.3.44

Scope: full Deep Trend Finder live actor QA plus GPT analysis contract hardening.

## Live actor evidence used

- TikTok / Clockworks: confirmed live `SUCCEEDED` with `itemCount > 0` and `cleanItemCount = 0`. The add-on must keep fetching Apify dataset items with `clean=false`.
- Instagram / Apify official: confirmed live direct hashtag URL mode returns real post rows with caption, shortcode, URL, media, owner username, likes/comments and hashtags.
- Reddit / Trudax Lite: confirmed live run can return rows, but the live sample showed celebrity/community drift for `brand strategy`. Reddit must not promote high-engagement adjacent rows to direct strategic evidence without business/marketing context.
- Google News / Data Xplorer: confirmed live Spain-locale rows. The live `seo` case can produce publisher/entity collisions, so publisher names are not semantic proof.
- Google Trends / Data Xplorer: confirmed keyword mode returns nested `timeline_data.{keyword}.{date}` and may be non-terminal before completion; no early zero-result closure.

## Bugs / risks addressed

1. GPT analysis was too row-level. v0.3.44 prepares a compact multi-platform package with row examples plus `sample_profile`, source counts, fit counts, metric totals and required analysis behaviors.
2. GPT schema did not force enough data-scientist output. v0.3.44 requires `platform_interaction_map`, `claim_audit`, `follow_up_test_design`, `sample_limits`, and `data_scientist_verdict`.
3. Reddit/news drift can occur when the literal words in the query appear in celebrity or community discourse. v0.3.44 adds a marketing-intent context gate for Reddit/Google News.
4. Request-body API keys remain ignored. OpenAI live calls must use server-side runtime key configuration only: constant, env var, or `fidtf_openai_api_key` filter.

## OpenAI live status

The live OpenAI route is ready at:

`/wp-json/future-island-dtf/v1/admin/openai-smoke-test`

It sends a Responses API JSON-schema smoke request using server-side key configuration only. It never accepts a key from the request body and redacts key-like strings from diagnostics.

The pasted chat key is treated as compromised and must be revoked. Put a new key only in WordPress/env before running the live OpenAI smoke test.
