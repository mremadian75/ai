# Deep Trend Finder v0.3.32 Live Actor QA

## Live runs executed

- TikTok / Clockworks: succeeded with 3 items. Finding: dataset itemCount was populated while cleanItemCount may be 0.
- Instagram / official scraper: direct hashtag URL mode succeeded with 3 post rows and post-level fields such as caption, shortCode, likesCount, commentsCount, displayUrl and images.
- Reddit / Trudax Lite: succeeded with 3 clean rows.
- Google Trends / Data Xplorer: succeeded, but default trending mode must be avoided for keyword analysis by explicitly setting enableTrendingSearches=false.
- Google News / Data Xplorer: succeeded with 3 article rows for Spain locale.

## Fix shipped

Dataset item fetching now uses clean=false for Apify dataset fallback/direct fetches. The plugin then sanitizes and normalizes rows internally. This avoids false zero ingestion when Apify clean filtering considers otherwise usable rows non-clean.
