# Deep Trend Finder Add-on v0.3.23

Focused hardening after validating the real Apify `apidojo/tiktok-scraper` dataset shape.

## Fixes

- Adds real nested Apidojo media support for `video.url`, `video.cover`, and `video.thumbnail`.
- Preserves hashtags and provider discovery query on normalized TikTok items.
- Adds a small provider-query relevance boost so high-quality search-result posts are not hidden only because the exact keyword is absent from the caption.
- Adds canonical dataset metadata reads so diagnostics can distinguish fetched rows from total provider dataset rows.
- Improves direct Apify flattened-empty messaging to avoid saying “zero posts” when Apify returned rows that could not be flattened.
- Persists provider run id on successful refresh-start repair paths.

## QA

- PHP lint across plugin files.
- Full local regression suite.
- New regression for the real nested Apidojo item shape seen in live run `DU5Ktv41hz9GUSZuP`.
