# Live actor and GPT QA — v0.3.41

## Live actors

Recent live checks confirmed:

- TikTok / Clockworks returns real video rows, but may report `cleanItemCount=0`; the add-on must keep `clean=false` dataset fetching.
- Instagram / official Apify scraper returns real post rows when using direct hashtag URLs, not search-directory mode.
- Reddit / Trudax Lite returns real rows, but can surface high-engagement weak/off-topic context; source-aware relevance gates are mandatory.
- Google News / Data Xplorer returns real article rows for Spain locale.
- Google Trends / Data Xplorer returns nested `timeline_data` and may start as non-terminal before succeeding; polling must continue.

## GPT live analysis

The add-on now exposes a stronger server-side OpenAI smoke test. It requires structured JSON analysis with cross-platform findings, source diagnostics, quantitative checks, hypotheses, risks and actions.

Live API execution must use a server-side key. A key pasted into chat is considered compromised and should be revoked, not used in runtime testing.

## Product implication

The report should behave less like a scraped-data summary and more like a marketing data-scientist readout: source role, evidence strength, cross-source overlap, platform bias, and next validation tests.
