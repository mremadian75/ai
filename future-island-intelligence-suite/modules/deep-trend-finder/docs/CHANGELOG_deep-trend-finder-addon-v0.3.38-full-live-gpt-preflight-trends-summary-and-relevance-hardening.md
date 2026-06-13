# Changelog — v0.3.38

## Added

- Non-terminal provider polling metadata:
  - `retryable`
  - `retry_after`
  - `poll_after_seconds`
  - `provider_non_terminal`
- Google Trends nested time-series summary metrics.
- Multi-word phrase relevance gate for weak semantic matches.
- Regression test: `test-fidtf-v0338-full-live-gpt-preflight-trends-summary-and-relevance-hardening.php`.

## Changed

- Google Trends reports now retain more useful interpretation data from `timeline_data` instead of only storing raw rows.
- Weak Reddit/adjacent results with only provider-query provenance are kept below show threshold unless item text carries enough semantic overlap.

## Verified

- PHP lint passed.
- JS syntax check passed.
- 43 PHP test files passed.
- Cumulative regression: 449 / 449.
