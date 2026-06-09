# v0.3.45 Full live actor + GPT analysis-depth QA

Live actor evidence used during this patch confirmed the same production constraints:

- TikTok / Clockworks can return `itemCount > 0` while `cleanItemCount = 0`, so dataset ingestion must keep `clean=false`.
- Instagram official scraper must use direct hashtag URL mode for post evidence; search mode can return hashtag-directory rows.
- Reddit can return high-engagement but off-intent community posts; relevance must inspect title/body/subreddit fit.
- Google News can collide on publisher/entity names; publisher names are not semantic proof.
- Google Trends keyword mode returns nested `timeline_data.{keyword}.{date}` and can be long-running before succeeding.

OpenAI live calls require a server-side key through WordPress/env. Keys pasted into chat are intentionally ignored by the plugin smoke-test route. v0.3.45 strengthens the GPT analysis contract so it must produce cross-platform claim readiness, not just row summaries.
