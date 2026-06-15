# Live Actor + GPT QA — v0.3.36

## Scope
Full live QA pass for Deep Trend Finder actor execution contracts and GPT/OpenAI response parsing.

## Live actor runs executed

### TikTok — clockworks/tiktok-scraper
- Query: marketing
- Limit: 2
- Run status: SUCCEEDED
- Dataset: 2WDZQOZSEnBqOrZlV
- Finding: `itemCount=2` while `cleanItemCount=0`; raw fetch must remain `clean=false`.
- Output shape confirmed: `text`, `webVideoUrl`, `diggCount`, `playCount`, `commentCount`, `collectCount`, `searchQuery`, `authorMeta.*`, `videoMeta.coverUrl`.
- Fix: normalizer now maps `searchQuery` to `provider_query`.

### Instagram — apify/instagram-scraper
- Query mode: direct hashtag URL for `/explore/tags/marketing/`
- Limit: 2
- Run status: SUCCEEDED
- Dataset: mGVUEzaMnH8aaShBV
- Finding: direct hashtag URL mode returns real post rows; search mode previously returned hashtag directory rows.
- Output shape confirmed: `caption`, `shortCode`, `url`, `commentsCount`, `likesCount`, `displayUrl`, `timestamp`, `ownerUsername`, `hashtags`.
- Existing direct URL strategy confirmed.

### Reddit — trudax/reddit-scraper-lite
- Query: brand strategy
- Limit: 2
- Run status: SUCCEEDED
- Dataset: MqkZ0BOdmoow97vU5
- Finding: actor contract works, but broad Reddit search can return adjacent/off-topic high-engagement posts. The report must not treat Reddit search results as direct proof without relevance scoring.
- Output shape confirmed: `title`, `body`, `communityName`, `numberOfComments`, `upVotes`, `createdAt`, `thumbnailUrl`, `dataType`.
- Fix: non-video source limitation cleanup reduces irrelevant transcript/audio warnings on Reddit evidence cards.

### Google Trends — data_xplorer/google-trends-fast-scraper
- Query: marketing
- Mode: keyword analysis with `enableTrendingSearches=false`
- Region: ES
- Dataset: Rga4lh2ijEqWe5Bkd
- Finding: keyword mode returns nested `timeline_data.{keyword}.{date}=value` and `timeline_data.isPartial.{date}=bool`.
- Existing timeseries parser confirmed; v0.3.36 keeps source-specific limitation cleanup.

### Google News — data_xplorer/google-news-scraper-fast
- Query: marketing
- Region/language: ES:es
- Limit: 2
- Run status: SUCCEEDED
- Dataset: vX2qnTEE230ebTldu
- Finding: `itemCount=2` while `cleanItemCount=0`; raw fetch must remain `clean=false`.
- Output shape confirmed: `title`, `url`, `source`, `publishedAt`, `image`, `metadata.keyword`.
- Fix: non-video source limitation cleanup avoids fake transcript/video warnings.

## GPT/OpenAI QA

A safe live OpenAI API call could not be executed inside this environment because no secure runtime `OPENAI_API_KEY` was available. A key pasted into chat is considered compromised and must not be used. Instead, v0.3.36 hardens the plugin against current OpenAI response shapes:

- Responses API text blocks
- Chat Completions message content
- SDK structured-output `parsed` payloads
- `output_parsed`, `parsed`, `structured_output`, and nested content parsed payloads
- Provider error objects
- incomplete/failed/cancelled/requires_action statuses
- refusal blocks
- env-style API key leakage in diagnostics

## New fixes in v0.3.36

1. `searchQuery` from Clockworks TikTok is now preserved as `provider_query`.
2. Non-video source cards no longer show irrelevant video/transcript/audio limitations for Google Trends, Google News, or Reddit.
3. AI planner bridge accepts structured-output parsed payloads.
4. Final synthesis bridge accepts structured-output parsed payloads while preserving allowlisted fields only.
5. Diagnostics redact env-style API key leaks such as `OPENAI_API_KEY=...`.

## Local test result

- PHP lint: 71 files passed.
- JS syntax check: passed.
- PHP regression tests: 41 files passed.
- Cumulative regression: 422 / 422 passed.
