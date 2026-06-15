# Changed Files Summary — Real Browser UI Bugfix

| File | Change | Why |
|---|---|---|
| `assets/css/ves-frontend.css` | Added browser-readability rules for social result cards, result text, URLs, KPI/metric cards, and responsive grids. | Fix P0 social result text wrapping and P0 metric-card label breakage without redesigning the module. |
| `assets/css/fiis-ui-system.css` | Added route/Object Flow grid sizing, status chip behavior, disabled-action reason rendering, Evidence Gate layout support, and bottom spacing for long admin/product screens. | Fix P0 Object Flow word-breaking, improve disabled action clarity, and reduce sticky footer/admin-bar overlap risk. |
| `assets/js/fiis-signal-productization.js` | Localized visible staging UI strings to Spanish and localized confidence labels. | Fix P1 Spanish/English inconsistency while keeping internal keys unchanged. |
| `assets/js/fiis-intelligence-map.js` | Added operator summary and fallback lineage table below the graph. | Make Intelligence Map usable when graph labels are hard to read; preserve read-only behavior. |
| `assets/css/fiis-intelligence-map.css` | Added readable styles for operator summary, count chips, lineage table, and graph/table spacing. | Improve Intelligence Map readability without turning it into a heavy graph product. |
| `includes/class-ves-intelligence-map.php` | Added server-side lineage rows to graph payload. | Give the UI a deterministic Run → Insight → Brief → Memory fallback list. |
| `includes/class-ves-access-control-admin.php` | Added human-readable access labels, raw-key metadata, JSON override helper text, provider labels/statuses, and clearer missing-token warnings. | Clean up Plans & Access, Credits & Limits, Provider Settings, and Overview warnings while preserving technical keys. |
| `includes/class-ves-provider-admin-page.php` | Added human provider/family labels, module/use-case column, and configured/missing token status. | Make provider contracts understandable without exposing token values. |
| `tests/test-fiis-signal-productization.js` | Added assertions for Spanish-first productization labels. | Prevent regression to visible English labels like Evidence Gate/Draft/Usage. |
| `tests/test-v0932-real-browser-ui-bugfix.php` | Added static regression test for social layout, Object Flow, metric labels, Spanish copy, disabled reasons, Intelligence Map lineage, provider labels, missing-token copy, and sensitive marker absence. | Lock down the browser-observed UI fixes. |
| `ui-snippets/real-browser-ui-bugfix/*` | Added regenerated HTML snippets for requested validation screens. | Provide concrete browser/snippet evidence for private-pilot demo review. |
