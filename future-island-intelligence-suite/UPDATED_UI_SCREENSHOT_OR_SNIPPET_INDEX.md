# Updated UI Screenshot or Snippet Index

Generated snippet directory inside the patched plugin:

```text
ui-snippets/real-browser-ui-bugfix/
```

| Screen / snippet | File | What it verifies |
|---|---|---|
| Social Intelligence results | `social-intelligence-results.html` | Result grid min width and normal paragraph wrapping. |
| Social result card detail | `social-result-card-detail.html` | Long captions and URLs remain readable. |
| Insight detail / executive reading | `insight-detail-executive-reading.html` | Observed vs Inferred hierarchy, confidence/risk chips, evidence readability. |
| Object Flow / Evidence Gate / Next Actions | `object-flow-evidence-gate-next-actions.html` | Lifecycle labels/status chips, Evidence Gate Spanish label, disabled action reason. |
| A/B testing plan | `ab-testing-plan.html` | Test A/B copy and readable KPI/status chips. |
| Intelligence Map with lineage list | `intelligence-map-lineage-list.html` | Graph fallback list columns: Run, Insight, Brief, Memory, Status, Confidence, Last updated, Open. |
| Overview missing-token warning | `overview-missing-token-warning.html` | Missing credentials copy explains what still works. |
| Provider Settings | `provider-settings.html` | Human labels + raw keys + configured/missing token status. |
| Plans & Access | `plans-access.html` | Human labels beside raw technical keys and JSON helper copy. |
| Credits & Limits | `credits-limits.html` | Metric cards render readable labels. |
| Diagnostics | `diagnostics.html` | Operator diagnostics copy stays clear and credential-safe. |

Bundle generated:

```text
SCREENSHOTS_OR_HTML_SNIPPETS_BUNDLE.zip
```

## v0.9.34 — Live WordPress admin screenshots (this sprint)

Real `wp-admin` screenshots from a live local WordPress 7.0 / PHP 8.4 install, captured
with a logged-in admin via headless Chromium. Directory:

```text
evidence/live-wordpress-screenshots/
```

| # | Screen | Files |
|---|---|---|
| 01 | Overview | `01-overview-desktop.png` |
| 02 | Command Room (+ Object Flow, Run Timeline, evidence drawer) | `02-command-room-desktop.png`, `-mobile.png` |
| 03 | Signal Room / Social results | `03-social-results-desktop.png`, `-mobile.png` |
| 05/07 | Object Flow / Run Timeline (Command Room) | `05-object-flow-desktop.png`, `07-run-timeline-desktop.png` |
| 08 | Provider Ingestions Ledger | `08-provider-ingestion-ledger-desktop.png`, `-mobile.png` |
| 09 | Decision / Intelligence Map | `09-decision-map-desktop.png` |
| 10 | Decision Report (render_html, run #1) | `10-decision-report-desktop.png` |
| 11 | Provider Settings | `11-provider-settings-desktop.png` |
| 12 | Plans & Access | `12-plans-access-desktop.png` |
| 13 | Credits & Limits | `13-credits-limits-desktop.png` |
| 14 | Insight detail | `14-insight-detail-desktop.png`, `-mobile.png` |
| 15 | Admin footer / overlap check | `15-admin-footer-overlap-check.png` |
| 16–28 | Intelligence Suite Overview, Diagnostics, Usage Ledger, Memory, Audit Log, Operator QA, Staging Browser Validation, Provider Contracts, Settings (slots), Trend Finder, Source Intelligence, Brief Builder, Workbench | `16-…` through `28-…-desktop.png` |

Per-page machine-readable scan (HTTP status, PHP-error markers, secret markers):
`evidence/live-wordpress-screenshots/_live-findings.json` — all pages HTTP 200, 0 PHP error
markers, 0 secret markers.
