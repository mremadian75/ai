# Deep Trend Finder Add-on v0.3.19 — READY status polling fix

## Production diagnosis

Apify `apidojo/tiktok-scraper` was being started correctly and the provider run later produced dataset rows, but the add-on could still close the source job as `completed_no_relevant_evidence` with `Raw 0 · Normalized 0 · Relevant 0`.

Root cause: the add-on treated Apify run status `READY` as if it were a finished/successful run. In Apify lifecycle terms, `READY` is a non-terminal queued/pre-run state. Fetching dataset items at `READY` returns zero rows, so the add-on prematurely marked the job as completed with no evidence and stopped polling.

## Fixes

- Treat only `SUCCEEDED` as a successful terminal Apify state.
- Treat `READY` / `STARTING` as `queued`.
- Treat `RUNNING` / `TIMING-OUT` as `running`.
- Keep `FAILED`, `ABORTED`, and `TIMED-OUT` as failed terminal states.
- Apply the fix to both Core Apify Client mode and Direct Apify token mode.
- Add a one-time repair path for previous TikTok jobs that were prematurely closed as zero-count `completed_no_relevant_evidence` but still have a provider run id.
- Add Apidojo flat-row normalization support for `postPage`, `channel.username`, `channel.name`, `channel.avatar`, and `uploadedAtFormatted`.

## Expected behavior after install

A TikTok run that starts in Apify as `READY` should remain queued/running in the add-on. The frontend should continue polling. Once Apify reaches `SUCCEEDED`, the dataset should be fetched, flattened, normalized, and then filtered for relevance.

If the provider returns 110 rows but none pass the evidence/relevance gate, the UI should say the relevance filter rejected posts, not that discovery returned zero posts.
