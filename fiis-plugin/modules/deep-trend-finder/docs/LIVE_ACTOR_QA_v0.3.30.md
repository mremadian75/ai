# Deep Trend Finder v0.3.30 Live Actor QA

Purpose: smoke-test all live actor contracts with very small limits before using them in paid/large runs.

Recommended test query: `mahou`
Market: `Spain`
Language: `es`
Date range: `last_7_days`
Limit: 3-5 items per source.

Actor matrix:

1. TikTok
   - Default discovery actor: `clockworks/tiktok-scraper`
   - Alternate validated actor: `apidojo/tiktok-scraper`
   - Expected: run reaches `SUCCEEDED`, dataset has rows, add-on normalizes post URL/title/metrics/thumbnail.

2. Instagram
   - Actor: `apify/instagram-scraper`
   - Expected input shape: `search`, `searchType`, `searchLimit`, `resultsType`, `resultsLimit`.
   - Expected: dataset contains post rows or hashtag wrapper rows expanded to evidence.

3. Reddit
   - Actor: `trudax/reddit-scraper-lite`
   - Expected input shape: `searches`, `searchPosts`, `maxPostCount`, `maxItems`, `time`, `sort`, `proxy`.
   - v0.3.30 intentionally avoids sending ambiguous `type` for post search.

4. Google Trends
   - Actor: `data_xplorer/google-trends-fast-scraper`
   - Expected input shape: keyword mode with `keyword`, `predefinedTimeframe`, `geo`, or trending mode with `enableTrendingSearches`.
   - v0.3.30 uses residential proxy group for reliability.

5. Google News
   - Actor: `data_xplorer/google-news-scraper-fast`
   - Expected input shape: `keywords`, `maxArticles`, `timeframe`, `region_language`.
   - v0.3.30 normalizes locale such as `es_ES` into `ES:es`.

Pass criteria:
- Request is attempted only after local contract validation passes.
- Actor run ID and dataset ID are persisted in diagnostics.
- Provider dataset total rows survive cache expiry.
- Raw rows flatten into normalized evidence rows.
- UI does not show a false-zero if provider dataset contains rows.

Known limitation:
This add-on cannot guarantee that a third-party actor will remain compatible forever. If an actor changes its schema, use the `fidtf_generic_apify_actor_input` filter to adapt input without exposing provider mechanics to users.
