# Future Island Deep Trend Finder Add-on v0.3.42 — Full live actor QA + GPT analysis contract

## Live actor status

Validated from live Apify runs during this QA sequence:

- TikTok / Clockworks: SUCCEEDED with real rows. Important: `itemCount` can be positive while `cleanItemCount` is 0, so dataset fetch must remain `clean=false`.
- Instagram / Apify official: SUCCEEDED with direct hashtag URL mode and real post rows. Search-directory mode remains unsuitable for post evidence.
- Reddit / Trudax Lite: SUCCEEDED but can return high-engagement adjacent/off-topic rows. Relevance gating and platform-bias handling must remain strict.
- Google Trends / Data Xplorer: SUCCEEDED with nested `timeline_data`. Actor may first return RUNNING; polling must not close as zero-result early.
- Google News / Data Xplorer: SUCCEEDED with Spain-locale article rows.

## Product bug fixed in v0.3.42

The report needed to behave more like a data-scientist readout across platforms, not just a list of evidence cards. v0.3.42 adds a statistical cross-platform scorecard:

- evidence family coverage
- dominant source concentration
- direct evidence share
- platform weighting
- overfit warnings
- validation hypotheses
- stricter decision rule

## OpenAI / ChatGPT live API note

The plugin now has a stronger OpenAI Responses API smoke-test contract. It requires:

- cross-platform findings
- source diagnostics
- quantitative checks
- platform weighting
- cross-source consistency
- evidence gaps
- causal limits
- hypotheses and next actions

A key pasted into chat is intentionally not used by the plugin smoke test. Live OpenAI testing must use a key configured server-side through environment, constants, filters, or WordPress admin settings.
