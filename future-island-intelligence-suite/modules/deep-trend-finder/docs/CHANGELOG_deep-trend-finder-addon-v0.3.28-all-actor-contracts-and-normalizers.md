# v0.3.28 — All actor contract hardening and source normalizers

Focused patch after TikTok live execution succeeded and the remaining risk moved to the other Trend Finder actors.

## What changed

- Reworked non-TikTok Apify actor input builders to follow the current documented actor contracts:
  - Instagram: `search`, `searchType`, `resultsType`, `resultsLimit`, optional `directUrls`.
  - Reddit: `searches`, `searchPosts`, `skipComments`, `maxPostCount`, `maxComments`, `time`, `sort`.
  - Google Trends: `keyword`, `predefinedTimeframe`, `geo`, `fetchRegionalData`, or `enableTrendingSearches` for open trend discovery.
  - Google News: `keywords`, `maxArticles`, `timeframe`, `region_language`, image/description decode flags.
- Removed unsafe generic `query` / `queries` / `keywords` payloads from source profiles where the actor does not document them.
- Added source-aware Google Trends flattening for `trending_searches` containers so each trend can become a separate evidence row.
- Improved normalization for:
  - Instagram official output (`shortCode`, `ownerUsername`, `likesCount`, `commentsCount`, `videoViewCount`, `displayUrl`).
  - Reddit output (`upVotes`, `numberOfComments`, `createdAt`, `communityName`).
  - Google Trends keyword analysis (`timeline_data`, `trends_url`, derived max interest).
  - Google News (`publishedAt`, `metadata.keyword`, `image`, `description`, publisher/source).
- Added regression tests for all actor input contracts and sample output shapes.

## Why

The TikTok path was now working, but the other sources still used optimistic generic payloads. Those payloads could easily fail with actor schema errors or return rows that the normalizer could not interpret well. This patch makes the non-TikTok sources behave like real source adapters instead of generic query wrappers.

## QA

- PHP lint: passed.
- JS syntax check: passed.
- Full test suite: 33 test files passed.
- Latest cumulative regression: 251 / 251 checks passed.

## Important limitation

This patch hardens actor contracts and output normalization using documented actor contracts and sample output shapes. It does not claim that every paid live Apify run was executed from this environment. Final production confirmation still requires one small live run per actor inside the target WordPress/Apify account.
