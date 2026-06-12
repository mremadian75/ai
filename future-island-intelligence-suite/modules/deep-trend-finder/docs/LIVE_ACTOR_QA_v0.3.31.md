# Deep Trend Finder v0.3.31 Live Actor QA

Date: 2026-05-26

## Live tests actually executed

### TikTok — clockworks/tiktok-scraper
- Initial v0.3.30-style input failed live validation because `videoSearchSorting` was sent as numeric string `0`.
- Corrected input using `videoSearchSorting: MOST_RELEVANT` succeeded.
- Live result: run succeeded with 3 dataset rows.
- Fix: v0.3.31 now sends live enum values and removes legacy `searchSorting` / `searchDatePosted`.

### Instagram — apify/instagram-scraper
- Search mode with `search`, `searchType: hashtag`, `resultsType: posts` succeeded but returned hashtag directory rows, not post evidence.
- Live result: run succeeded with 3 rows containing `searchTerm`, `searchSource`, `name`, `postsCount`, `url`, `id`.
- Fix: v0.3.31 converts hashtag-like queries into direct hashtag URLs so the actor should collect post evidence instead of hashtag directory evidence.

### Reddit — trudax/reddit-scraper-lite
- Live run succeeded with the v0.3.30 post-search contract.
- Dataset metadata confirmed 3 clean rows with expected fields: `title`, `body`, `communityName`, `url`, `upVotes`, `numberOfComments`, `createdAt`, `thumbnailUrl`.
- No contract patch needed beyond keeping `type` omitted.

### Google Trends — data_xplorer/google-trends-fast-scraper
- v0.3.30 keyword input without explicit `enableTrendingSearches: false` silently returned generic trending searches for default country instead of keyword analysis.
- Live evidence: actor returned a trending-searches container with `geo: US` even though the intended keyword test used Spain context.
- Fix: v0.3.31 explicitly sends `enableTrendingSearches: false` in keyword mode.

### Google News — data_xplorer/google-news-scraper-fast
- Live run succeeded with `keywords`, `maxArticles`, `timeframe`, `region_language`, `extractImages`.
- Dataset metadata confirmed 3 clean rows with expected fields: `title`, `url`, `source`, `publishedAt`, `image`, `metadata.keyword`.
- No contract patch needed; proxy default was relaxed to standard Apify proxy for lower cost and broader account compatibility.

## Tooling limitations during QA

Some direct dataset reads for user-generated social content and some scraper runs were blocked by tool safety checks in this environment. Where that happened, QA relied on run metadata, dataset metadata, and successful non-preview async runs rather than displaying all raw items.

## v0.3.31 patch summary

- TikTok Clockworks enum contract fixed.
- Instagram post-evidence path changed from search-directory mode to direct hashtag URL mode.
- Google Trends keyword mode now disables trending mode explicitly.
- Google actor proxy defaults to standard Apify proxy, with `fidtf_google_actor_proxy_configuration` filter for residential override.
- Regression test added: `tests/test-fidtf-v0331-live-actor-contract-fixes.php`.
