# Deep Trend Finder v0.3.37 — Full live actor QA + GPT bridge preflight

## Scope

This QA pass focused on the five live Trend Finder source families and the ChatGPT/OpenAI planning/synthesis bridge:

- TikTok / Clockworks TikTok scraper
- Instagram / Apify official Instagram scraper
- Reddit / Trudax Reddit scraper lite
- Google Trends / Data Xplorer Google Trends scraper
- Google News / Data Xplorer Google News scraper
- GPT request/response parser and final synthesis bridge

## Live actor findings

### TikTok

Live run succeeded with `clockworks/tiktok-scraper` using:

- `searchQueries`
- `resultsPerPage`
- `searchSection=/video`
- `videoSearchSorting=MOST_RELEVANT`
- `videoSearchDateFilter=ALL_TIME`
- `proxyCountryCode=ES`

Important live shape:

- `itemCount` can be positive while `cleanItemCount` is zero.
- Real evidence fields include `text`, `webVideoUrl`, `playCount`, `diggCount`, `commentCount`, `shareCount`, `collectCount`, `searchQuery`, `authorMeta.*`, `videoMeta.coverUrl`.

The plugin must keep using raw dataset fetch (`clean=false`) and map `searchQuery`/actor context to provider query.

### Instagram

Live run succeeded with `apify/instagram-scraper` only when using direct hashtag URL mode:

- `directUrls: [https://www.instagram.com/explore/tags/<tag>/]`
- `resultsType=posts`
- `resultsLimit`

Search mode with `search/searchType` can return hashtag-directory rows rather than post evidence. Direct hashtag URL mode is the correct default for trend evidence collection.

Important live shape:

- `caption`, `shortCode`, `url`, `likesCount`, `commentsCount`, `displayUrl`, `ownerUsername`, `hashtags`.
- `cleanItemCount` can be zero while valid rows exist.

### Reddit

Live run succeeded with `trudax/reddit-scraper-lite` using:

- `searches`
- `searchPosts=true`
- `searchComments=false`
- `searchCommunities=false`
- `skipComments=true`
- `maxPostCount`
- `maxComments=0`

Important live behavior:

Reddit search may return high-engagement adjacent or off-topic rows. Provider-query provenance proves the actor used the query; it does not prove the row itself is relevant. v0.3.37 caps provider-query-only rows below show threshold unless the row text/title/body has semantic overlap.

### Google Trends

Keyword mode works with:

- `enableTrendingSearches=false`
- `keyword`
- `predefinedTimeframe`
- `geo`
- `fetchRegionalData=false`

Important live shape:

- `timeline_data.<keyword>.<YYYY-MM-DD>` contains interest values.
- `timeline_data.isPartial` must not be counted as interest.

### Google News

Live run succeeded with:

- `keywords`
- `maxArticles`
- `timeframe`
- `region_language`
- `extractImages=true`

Important live shape:

- `title`, `url`, `source`, `publishedAt`, `image`, `metadata.keyword`.
- `cleanItemCount` can be zero while article rows are valid.

## GPT/OpenAI live status

A real OpenAI API call was not executed in this environment because no secure runtime API key was available. A key pasted in chat must be considered compromised and must not be used.

The setup flow was opened for secure key creation. Once a new key is stored only in WordPress admin/env, run one real provider call through the installed plugin and verify:

- request goes to OpenAI Responses API-compatible bridge
- output JSON is parsed from `output_text` / `output[].content[].text` / structured parsed payload
- incomplete/failed/refusal/error responses do not override local deterministic synthesis
- diagnostics redact API keys

## v0.3.37 code changes

- Dataset rows are enriched with provider query context derived from actor input when the actor output omits query provenance.
- Instagram direct hashtag URL gets converted back to provider query tag for scoring/debug.
- Reddit/provider-query-only rows are capped below show threshold to avoid false evidence from unrelated high-engagement search results.
- GPT live-style Responses API payload is covered by regression tests.

