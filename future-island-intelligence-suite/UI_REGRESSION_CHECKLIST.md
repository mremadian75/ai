# UI Regression Checklist — Real Browser UI Bugfix

## P0 checks

- [x] Social result cards use `repeat(auto-fit, minmax(280px, 1fr))`.
- [x] Normal social text uses `word-break: normal`, `overflow-wrap: normal`, and `white-space: normal`.
- [x] Long URLs still wrap safely without forcing prose into break-all behavior.
- [x] Object Flow route cards use readable grid sizing.
- [x] Status values render as chips and do not split mid-word.
- [x] Metric cards have a responsive min-width and do not split platform labels like TikTok.

## P1 checks

- [x] Visible staging labels use Spanish for the observed mixed-language UI strings.
- [x] Confidence values render as `alta`, `media`, `baja` where applicable.
- [x] Disabled strategic actions expose a reason/helper text.
- [x] Intelligence Map includes graph plus fallback lineage list.
- [x] Long admin/product screens have bottom spacing for sticky bar resilience.

## P2 checks

- [x] Plans & Access shows human labels plus raw technical keys.
- [x] Provider Settings shows human provider labels, raw keys, module/use case, and token configured/missing status.
- [x] Overview missing-token warning explains what still works without OpenAI/Apify credentials.
- [x] Insight/detail and A/B snippet examples preserve Observed vs Inferred readability and long-copy wrapping.

## Security/non-regression checks

- [x] Token values are not rendered.
- [x] Raw provider payloads are not rendered by the patched surfaces.
- [x] No secrets/signatures/bearer tokens were added to CSS/JS UI assets.
- [x] n8n remains optional; no new n8n dependency added.
- [x] WordPress canonical layer and existing service contracts were preserved.

## Verification commands

```bash
bin/lint-php.sh --core --timeout-per-file=10
php tests/test-v0932-real-browser-ui-bugfix.php
node tests/test-fiis-signal-productization.js
php tests/test-ves-intelligence-map.php
php tests/test-v092-staging-browser-validation-readiness.php
php tests/test-v091-ui-ux-private-beta-upgrade.php
php tests/test-v091-orchestration-agnostic-correction.php
php tests/test-v090-onboarding-qa-report-hardening.php
php tests/test-v090-ingestion-ledger-filters-diagnostics.php
FIIS_TEST_TIMEOUT=20 bin/test-all-timeout-safe.sh -q
bin/test-all.sh -q
```
