# Changelog — v0.3.36 Full Live Actor and GPT QA Hardening

## Added
- Live QA documentation for all Trend Finder source actors.
- Regression test file: `tests/test-fidtf-v0336-full-live-actor-and-gpt-qa-hardening.php`.
- OpenAI structured-output parsed payload support in the planner bridge.
- External final synthesis parsed payload support.

## Fixed
- Clockworks TikTok `searchQuery` is now normalized into `provider_query`.
- Google Trends, Google News and Reddit evidence cards no longer receive irrelevant transcript/audio/video limitations.
- OpenAI diagnostics redact `OPENAI_API_KEY=...`, `api_key=...`, and `x-api-key=...` style leaks.

## Confirmed by live QA
- TikTok: `clockworks/tiktok-scraper` succeeded with 2 raw rows.
- Instagram: `apify/instagram-scraper` direct hashtag URL mode succeeded with 2 post rows.
- Reddit: `trudax/reddit-scraper-lite` succeeded with 2 post rows.
- Google Trends: `data_xplorer/google-trends-fast-scraper` keyword mode succeeded with nested timeseries rows.
- Google News: `data_xplorer/google-news-scraper-fast` succeeded with 2 article rows.

## Not performed
- Live OpenAI API request was not executed because no secure runtime API key was available. A key pasted into chat was not used.
