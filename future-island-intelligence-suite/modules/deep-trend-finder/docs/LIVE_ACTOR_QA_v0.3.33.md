# Live Actor QA v0.3.33

## Tested actor routes

### TikTok / Clockworks
- Actor: `clockworks/tiktok-scraper`
- Live result: succeeded with real rows after enum correction.
- Important input rules:
  - `videoSearchSorting` must be one of `MOST_RELEVANT`, `MOST_LIKED`, `LATEST`.
  - Legacy numeric values such as `0` are invalid.

### Instagram / Apify official
- Actor: `apify/instagram-scraper`
- Live result: direct hashtag URL mode succeeded and returned post rows.
- Important input rule:
  - Query/search mode can return hashtag directory rows.
  - For trend evidence, use `directUrls` with `https://www.instagram.com/explore/tags/{tag}/`.

### Reddit / Trudax Lite
- Actor: `trudax/reddit-scraper-lite`
- Live result: succeeded with post rows.
- Important input rule:
  - Use `searches` + `searchPosts=true` + `maxPostCount`.
  - Avoid ambiguous legacy `type` for post search.

### Google Trends / Data Xplorer
- Actor: `data_xplorer/google-trends-fast-scraper`
- Live result: keyword mode can be long-running and should remain refreshable until terminal state.
- Important input rule:
  - Keyword analysis must explicitly send `enableTrendingSearches=false`.
  - Trending discovery mode uses `enableTrendingSearches=true` and `trendingSearches*` fields.

### Google News / Data Xplorer
- Actor: `data_xplorer/google-news-scraper-fast`
- Live result: succeeded with article rows.
- Important input rule:
  - Use `keywords`, `maxArticles`, `timeframe`, and `region_language`.

## v0.3.33 improvement

The system now separates provider empty state, adapter flattening failure, and downstream normalization failure. This is essential for debugging paid actor runs because a successful Apify run does not necessarily mean usable marketing evidence was produced.
