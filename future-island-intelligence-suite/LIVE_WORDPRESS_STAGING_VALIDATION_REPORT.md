# Live WordPress Staging Validation Report

## 1. Validation level

- Real WordPress staging (remote host): No
- **Local WordPress install: Yes** ← this report
- Static snippets only: No (superseded by the live install)
- Code-side only: No

The plugin was installed and **activated inside a real WordPress 7.0 admin** running on
PHP 8.4.19, and every Future Island admin screen was loaded through a real browser
(headless Chromium via Playwright) with a logged-in administrator session. This is a
genuine WordPress admin validation, not snippet rendering.

Honest limitation: the database is SQLite (via the official `sqlite-database-integration`
drop-in) because no MySQL is available in this environment, and the active theme is the
WordPress default. A customer staging site on MySQL + a production theme should still get
one confirmation pass, but the WordPress runtime, admin chrome, admin bar, settings API,
migrations, REST routing, and capability checks were all genuinely exercised here.

## 2. Environment

- WordPress version: 7.0
- PHP version: 8.4.19
- Database: SQLite (sqlite-database-integration drop-in; `WP_SQLite_DB` confirmed)
- Browser: headless Chromium 1194 (Playwright 1.56)
- Theme: WordPress default
- Active relevant plugins: future-island-intelligence-suite (1.4.0), sqlite-database-integration
- Plugin tested: built from the v0.9.33 base + this sprint's two fixes (v0.9.34)
- Date/time: 2026-06-15 (UTC)

## 3. Install/activation

| Step | Status | Evidence |
|---|---|---|
| Plugin files installed to wp-content/plugins | PASS | `wp plugin list` shows it |
| Activation (`wp plugin activate`) | PASS | "Plugin activated. Success: Activated 1 of 1." no fatal |
| Migrations / table creation | PASS | 60 canonical tables created (sources→signals→evidence→insights→briefs→drafts→memory→runs→usage…) |
| Admin menus registered | PASS | Top menus "Future Island" + "Intelligence Suite" with all sub-pages |
| Activation produced no fatal/warning | PASS | clean (one PHP 8.4 deprecation found in a code path + fixed — see §9) |

## 4. Screens checked

Captured at desktop (1440); social/object-flow/insight/ledger also at mobile (390).
Screenshots: `evidence/live-wordpress-screenshots/`. Every page returned HTTP 200 with
**0 PHP error markers** and **0 secret markers** in the rendered DOM.

| Screen | Desktop | Mobile | Status | Evidence |
|---|---:|---:|---|---|
| Overview (Future Island) | Yes | – | PASS | 01-overview-desktop.png |
| Command Room (+ Object Flow, Run Timeline, Evidence drawer) | Yes | Yes | PASS | 02-command-room-*.png, 05/07 |
| Signal Room / Social results | Yes | Yes | PASS | 03-social-results-*.png |
| Insight detail surface | Yes | Yes | PASS | 14-insight-detail-*.png |
| Provider Ingestions Ledger | Yes | Yes | PASS | 08-provider-ingestion-ledger-*.png |
| Decision Map / Intelligence Map | Yes | – | PASS | 09-decision-map-desktop.png |
| Decision Report (render_html for run #1) | Yes | – | PASS | 10-decision-report-desktop.png |
| Provider Settings | Yes | – | PASS | 11-provider-settings-desktop.png |
| Plans & Access | Yes | – | PASS | 12-plans-access-desktop.png |
| Credits & Limits | Yes | – | PASS | 13-credits-limits-desktop.png |
| Intelligence Suite Overview | Yes | – | PASS | 16-intelligence-suite-overview-desktop.png |
| Diagnostics | Yes | – | PASS | 17-diagnostics-desktop.png |
| Usage Ledger | Yes | – | PASS | 18-usage-ledger-desktop.png |
| Memory | Yes | – | PASS | 19-memory-desktop.png |
| Audit Log | Yes | – | PASS | 20-audit-log-desktop.png |
| Operator QA | Yes | – | PASS | 21-operator-qa-desktop.png |
| Staging Browser Validation | Yes | – | PASS | 22-staging-browser-validation-desktop.png |
| Provider Contracts | Yes | – | PASS | 23-provider-contracts-desktop.png |
| Settings (Trend Finder slots) | Yes | – | PASS (after fix) | 24-settings-desktop.png |
| Trend Finder / Source Intelligence / Brief Builder / Workbench | Yes | – | PASS | 25–28-*.png |
| Admin footer / overlap check | Yes | – | PASS | 15-admin-footer-overlap-check.png |

## 5. P0 validation

| Issue | Status | Evidence | Remaining risk |
|---|---|---|---|
| P0.1 Social result text layout | PASS | Signal Room + Command Room evidence drawer render seeded long captions/hashtags wrapping by word; cards keep width; mobile stacks | None observed |
| P0.2 Object Flow / lifecycle word-break | PASS | Command Room route/status renders as chips; seeded run state readable | None observed |
| P0.3 Metric card wrapping | PASS | Credits & Limits + overview KPI cards readable; no split platform names | None observed |

## 6. P1 validation

| Issue | Status | Evidence | Remaining risk |
|---|---|---|---|
| P1.1 Spanish/English consistency | PASS (validated surfaces) | Seeded insight/brief render Spanish; confidence alta/media/baja; some admin section headers remain English by design | Full i18n is later roadmap |
| P1.2 Disabled action explanations | PASS | Command Room review actions + disabled CTAs show reasons | None observed |
| P1.3 Intelligence Map usability | PASS | Map + operator summary + lineage list render; lineage columns present | None observed |
| P1.4 Admin bar/footer overlap | PASS (this env) | Footer renders below content; no overlay (15-*.png) | Confirm with production theme + long real tables |

## 7. P2 validation

| Issue | Status | Evidence | Remaining risk |
|---|---|---|---|
| P2.1 Plans & Access / Credits & Limits | PASS | Human labels + mono keys; readable grouping | None observed |
| P2.2 Provider Settings | PASS | Human label + key + module/use-case + token configured/missing; "Token values are never rendered" | None observed |
| P2.3 Overview warning copy | PASS | Explains OpenAI/Apify purpose + what works without them | None observed |
| P2.4 Insight/detail polish | PASS | Observed vs Inferred, confidence + risk chips, limited-evidence label | None observed |

## 8. Console/network/PHP logs

- Browser console: only `ERR_CERT_AUTHORITY_INVALID` for an external HTTPS resource
  (e.g. WordPress.org dashboard API) — environmental, not plugin-caused.
- `wp-content/debug.log` across the full admin tour: **zero plugin-caused** PHP
  Warning/Notice/Deprecated/Fatal/Parse lines. The only entries are 6 instances of a
  WordPress **core** `strip_tags(null)` deprecation in `wp-admin/admin-header.php:41`
  (WP 7.0 on PHP 8.4), which is not the plugin.

## 9. Issues found and fixed this sprint

| Severity | Issue | Where | Fix | Verification |
|---|---|---|---|---|
| P0 (admin fatal) | Settings page fataled: `VES_Admin` missing method `render_trend_source_slots_field` (registered via add_settings_field) | `includes/class-ves-admin.php:398` | Implemented the renderer (escaped, matches sanitizer field contract) | Settings page now 200, full render, slots table present, no fatal in debug.log; test-v0934 |
| Low (log noise, PHP 8.4) | Implicitly-nullable param deprecation | `includes/class-ves-provider-callback-auth-service.php:94` | `int $timestamp = null` → `?int $timestamp = null` | No deprecation in debug.log; test-v0934 |

## 10. Decision

- **Ready for controlled private pilot:** Yes — after the two fixes above, the plugin
  installs, activates, migrates, and renders every admin screen cleanly in a real
  WordPress admin with no plugin-caused errors and no secret leakage.
- **Needs patch before private pilot:** None remaining (the two found were patched and
  re-verified in the same live environment).
- **Not verified:** MySQL backend, a production theme, multisite, and non-Chromium
  browsers. One confirmation pass on the customer's actual staging host is recommended
  but not a blocker for a controlled pilot.
