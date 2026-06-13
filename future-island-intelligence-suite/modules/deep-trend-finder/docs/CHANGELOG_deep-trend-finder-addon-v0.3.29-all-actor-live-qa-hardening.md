# v0.3.29 — All actor live QA hardening

## Purpose

v0.3.29 hardens every Trend Finder live actor path, not only TikTok. The goal is to reduce schema mismatch risk before live provider dispatch and make provider output ingestion more tolerant of real Apify dataset shapes.

## Changed

- Bumped plugin version to `0.3.29`.
- Added local actor input contract validation for:
  - Instagram
  - Reddit
  - Google Trends
  - Google News
  - generic query actors
- Generic Apify adapter now fails locally with `source_actor_contract_invalid` before provider dispatch if a required input contract is missing.
- Actor profile detection is stricter and supports exact defaults plus safer generic aliases.
- Dataset wrapper expansion now handles:
  - `items`
  - `results`
  - `posts`
  - `articles`
  - `data`
  - `trendingSearches`
  - `trending_searches`
  - `trends`
- Refresh limit detection now respects actor-specific limit fields:
  - Reddit `maxPostCount`
  - Google News `maxArticles`
  - Google Trends `trendingSearchesMaxItems`
- Direct dataset metadata fallback now attempts to read dataset `itemCount` when the Core Apify client is unavailable.
- Normalizer support expanded for non-TikTok source variants:
  - Google Trends: `query`, `trendsUrl`, `timelineData`, `interestOverTime`, `formattedTraffic`
  - Google News: `sourceName`, `articleUrl`, `metadata.query`, nested image objects
  - Instagram: additional play/view count aliases
  - Reddit: additional comments/upvote aliases
- Added v0.3.29 regression tests covering every actor profile, every actor input contract, wrapper expansion, and normalized evidence fields.

## QA status

- PHP lint passed.
- JavaScript syntax check passed.
- 34 PHP regression test files passed.
- v0.3.29 cumulative regression count: 287 / 287.

## Important limitation

This patch hardens local contracts and output handling. TikTok has already been validated against a real Apify dataset in prior QA. Instagram, Reddit, Google Trends, and Google News still need one small live run on the installed site using the exact mapped actors and account permissions before being marked production-confirmed.
