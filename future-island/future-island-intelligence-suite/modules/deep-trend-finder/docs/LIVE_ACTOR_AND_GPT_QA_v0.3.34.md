# Deep Trend Finder v0.3.34 — Live Actor + GPT Request QA

## Scope
This QA pass focused on live actor execution results plus the ChatGPT/OpenAI response-contract path used by the Deep Trend Finder AI planner and final synthesis bridge.

## Live Apify actor findings

### TikTok / Clockworks
Previous v0.3.31/v0.3.32 live QA confirmed that Clockworks TikTok succeeds when `videoSearchSorting` uses the current enum values such as `MOST_RELEVANT` instead of legacy numeric values.

### Instagram / Apify official scraper
Live direct hashtag URL test succeeded.

- Actor: `apify/instagram-scraper`
- Run: `gm8tooixvkZTvZPAj`
- Dataset: `Bg6Fefk5E4gqye8wC`
- Result: 3 post rows with real post fields: `caption`, `shortCode`, `likesCount`, `commentsCount`, `displayUrl`, `images`.

Confirmed behavior: direct hashtag URL mode is the right mode for post evidence. Search mode can return hashtag directory rows instead of posts.

### Reddit / Trudax Lite
Live test succeeded.

- Actor: `trudax/reddit-scraper-lite`
- Run: `RnRxSTFWeyJDQoKT0`
- Dataset: `RHB3aFzUMTVBVH3q4`
- Result: 3 clean post rows with fields such as `title`, `body`, `upVotes`, `numberOfComments`, `communityName`, `url`.

### Google News / Data Xplorer
Live test succeeded.

- Actor: `data_xplorer/google-news-scraper-fast`
- Run: `iYbxk25jYS5nHbCEk`
- Dataset: `SmiUtUYypkZZoEFgw`
- Result: 3 article rows with `title`, `url`, `source`, `publishedAt`, `image`, and `metadata.keyword`.

### Google Trends / Data Xplorer
Live keyword-mode test succeeded after waiting for completion.

- Actor: `data_xplorer/google-trends-fast-scraper`
- Run: `PyFGDgMxOVPRtgoZo`
- Dataset: `Rga4lh2ijEqWe5Bkd`
- Result: 1 keyword-analysis row for `marketing` in Spain with `timeline_data.marketing` keyed by dates.

## Bugs found and fixed in v0.3.34

### 1. Google Trends nested timeline was not producing trend interest correctly
Live output shape:

```json
{
  "keyword": "marketing",
  "geo": "ES",
  "timeline_data": {
    "marketing": {
      "2026-04-26": 74,
      "2026-05-05": 100
    },
    "isPartial": {
      "2026-05-26": true
    }
  }
}
```

Older logic only looked for flat rows with keys like `value` or `interest`. It could miss nested keyword maps and fail to derive `trend_interest`.

Fix:
- Added recursive numeric extraction for nested Google Trends timeline maps.
- Added timeline flattening into date/value/series rows.
- Preserved partial flags safely without treating booleans as trend values.

### 2. ChatGPT/OpenAI bridge did not handle raw Responses API shapes
Mother plugin clients may return OpenAI-shaped arrays such as:

- `output_text`
- `output[].content[].text`
- `choices[].message.content`

Older bridge logic expected an already-decoded source plan. It could treat a valid ChatGPT response as an invalid array.

Fix:
- Added model-text extraction for Responses API and Chat Completions shapes.
- Added markdown-fence stripping.
- Added JSON extraction from model text.
- Preserved `raw_text` only as sanitized admin diagnostic.
- Planner validation still controls source filtering and prevents unknown sources.

### 3. Final synthesis external bridge could mark external mode even when no supported fields were returned
Fix:
- External synthesis is now merged only if allowlisted fields are present.
- OpenAI-shaped external synthesis JSON can be decoded safely.
- Unknown fields are dropped.

## OpenAI live request note
A real paid OpenAI API call was not executed in this sandbox because no `OPENAI_API_KEY` was available in the runtime. The API key setup widget was triggered for a proper live API test. Until a key is connected, v0.3.34 validates the ChatGPT/OpenAI contract with deterministic mocked Responses API and Chat Completions payloads.

## Local verification

- PHP lint: 69 PHP files passed.
- JavaScript syntax check: passed.
- PHP regression suite: 39 test files passed.
- Latest cumulative regression: 381 / 381 passed.
