# Browser Screenshot Findings — Real Browser UI Bugfix

## Scope

This sprint used the supplied real-browser issue list and the uploaded browser-validation/snippet evidence as the acceptance source. The work intentionally stayed inside the observed UI/UX bugfix scope and did not add new product modules.

## Findings and fix status

| Priority | Finding | Evidence surface | Fix status | Verification |
|---|---|---|---|---|
| P0 | Social Intelligence result text could shrink into extremely narrow columns and wrap almost character-by-character. | Social Intelligence results / result detail. | Fixed with 280px minmax card grid and normal prose wrapping. | `tests/test-v0932-real-browser-ui-bugfix.php`; `bin/test-all.sh -q`. |
| P0 | Object Flow lifecycle cards could split labels/statuses mid-word. | Object Flow / lifecycle route. | Fixed with grid sizing, status chips, nowrap status values, and normal word wrapping for labels. | `tests/test-v0932-real-browser-ui-bugfix.php`; `tests/test-v091-ui-ux-private-beta-upgrade.php`. |
| P0 | Metric cards could split platform names and metric labels. | Summary/KPI cards. | Fixed with responsive min-width KPI grids and safe label wrapping. | `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P1 | Visible UI mixed Spanish and English staging labels. | Productization panel / Evidence Gate / route labels. | Fixed for the observed labels: confidence, draft, usage, Evidence Gate, Next Actions-style action labels. | `node tests/test-fiis-signal-productization.js`. |
| P1 | Disabled strategic actions lacked enough inline explanation. | Convert to brief / save memory / generate draft actions. | Existing reason captions preserved and CSS now exposes disabled reason visibly/accessibly. | `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P1 | Intelligence Map had graph data but was hard to interpret alone. | Decision/Intelligence Map. | Added operator summary and fallback lineage table. | `php tests/test-ves-intelligence-map.php`; `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P1 | Long admin/product pages risked sticky/admin bar overlap. | Long admin screens and tables. | Added bottom spacing to product/admin containers; no z-index escalation. | Static CSS regression check. |
| P2 | Plans & Access / Credits & Limits were too raw. | Access/admin pages. | Human labels added next to raw technical keys; JSON helper copy added. | `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P2 | Provider settings used raw provider keys as primary labels. | Provider Settings / Provider Contracts. | Human labels, module/use-case, configured/missing token status added; raw keys retained as mono metadata. | `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P2 | Missing-token warnings implied the product might be unusable. | Overview warning copy. | Warning now explains what requires credentials and what still works without them. | `tests/test-v0932-real-browser-ui-bugfix.php`. |
| P2 | Insight/detail and A/B test screens needed readability polish. | Insight detail / Test A/B snippets. | Added snippet evidence and layout safeguards for long text and chips; no feature expansion. | Snippet bundle + UI regression checklist. |

## Notes

- No new third-party provider connection was added.
- No real secrets, HMAC signatures, bearer tokens, raw callback payloads, or provider token values are rendered by the patched UI surfaces.
- n8n remains optional; the browser fixes work for manual workflows and signed callback simulator paths.
