# CHANGELOG_deep-trend-finder-addon-v0.1.2-foundation-corrections

## Changed

- Bumped add-on version to `0.1.2-foundation-corrections`.
- Added robust metric parsing for numeric provider strings such as `1.4K`, `80.7K`, `2,786`, `1.2M`, and `3B`.
- Made normalized metrics always expose canonical keys with `null` for missing values.
- Preserved real zero values as `0`.
- Separated top-level item `url` from `media.media_url`.
- Added source-specific media typing for Google News, Google Trends, Reddit, AI Research, TikTok, and Instagram.
- Tightened evidence-quality flags and limitations.
- Added extended planner form fields: date range, brand/company, competitors, audience, and exclude terms.
- Added source row rendering and polling foundation in the frontend.
- Added safer public response shaping for `GET /runs/{id}`.
- Clamped item pagination to `per_page <= 50`.
- Upgraded report template to show run status, source summary, evidence count, can/cannot-say sections, and next setup step.

## Fixed

- Short metric strings no longer normalize as missing.
- Google News and Reddit public URLs are no longer misclassified as media URLs.
- Video URLs no longer imply video files, frame extraction, audio analysis, or deep video analysis.
- Missing metrics are no longer treated as invented zeroes.
- Empty planned runs no longer look like final evidence reports.

## Still intentionally not implemented

- No live scraping.
- No real provider calls.
- No Scraptik / Apify / Bright Data bridge.
- No real AI planner.
- No AI final synthesis.
- No video download.
- No ffmpeg / frame extraction.
- No audio extraction.
- No transcript generation.
- No sidecar worker.
