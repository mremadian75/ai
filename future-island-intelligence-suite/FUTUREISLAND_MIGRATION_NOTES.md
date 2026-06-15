# Future Island — Migration Notes (v0.4.0 modular SaaS)

This document maps every renamed/consolidated surface from pre-v0.4.0
packages to the v0.4.0 modular layout. **No data is deleted by this release.**

## 1. Trend Finder consolidation (the duplicate is gone)

### What existed before (the duplicate)

| # | Implementation | Where it appeared | Engine |
|---|----------------|-------------------|--------|
| 1 | Legacy core "Trend Finder" | SaaS app sidebar entry **Trend Finder** → page `?fiis_page=trend`; dashboard tool card | `includes/trend-finder.php`, `VES_Run_Execution_Service`, `VES_Trend_*` (waterfall/bundle engine), AJAX `ves_trends_start` |
| 2 | "Deep Trend Finder" | SaaS app sidebar entry **Deep Trend Finder** → page `?fiis_page=deep-trend` + dedicated frontend workspace page | `modules/deep-trend-finder/` (FIDTF_*: REST lifecycle, source jobs, evidence quality, report artifact) |

Both sidebar entries ultimately launched the same Deep Trend Finder
workspace (the `trend` page had already been reduced to a launcher), so the
product showed **two navigation entries for one product** — and the legacy
`trend` page section was dead weight in the shell.

### Canonical choice

**The Deep Trend Finder engine (FIDTF_\*) is the one canonical Trend
Finder**, now labeled simply **Trend Finder** everywhere. It is the more
complete implementation: REST run lifecycle, per-source jobs with preflight
and watchdog, evidence-quality accounting, claim-readiness, report artifact,
and the campaign-facing Google Ads asset block.

### Old → new mapping

| Old | New | Mechanism |
|-----|-----|-----------|
| App route `trend` | App route `deep-trend` | `VES_App_Router::ALIASES` — `trend`, `trend_finder`, `trend-finder`, `deeptrend`, `deep_trend` all normalize to `deep-trend`; old links/bookmarks land on the canonical page |
| Sidebar entry "Trend Finder" (`trend`) + "Deep Trend Finder" (`deep-trend`) | ONE sidebar entry **Trend Finder** (`deep-trend`) | Duplicate `$ves_nav('trend', …)` removed from `templates/shortcode.php` |
| `data-page="trend"` shell section | Removed | Router alias makes it unreachable; section deleted (no dead page) |
| Dashboard tool card → `?fiis_page=trend` | → `?fiis_page=deep-trend` | Card target updated |
| Keyboard shortcut `6` → `trend` | `6` → `deep-trend` | `assets/js/ves-frontend.js` map updated |
| Page title "Deep Trend Finder" | "Trend Finder" | Canonical naming |
| (new) WP-admin module hub | **Future Island → Trend Finder** (`admin.php?page=fi-trend-finder`) | Run history, provider/source preflight truth, diagnostics, workspace CTA |

### What was deliberately kept (no data loss, backward compatibility)

- **All run data**: FIDTF run/job/item tables are untouched; the module hub
  reads them via `FIDTF_Run_Service::list_recent_runs()`. Legacy core trend
  bundles/stores (`VES_Trend_Run_Store`, transients) are untouched and still
  readable by their own classes.
- **Legacy backend routes**: the old AJAX endpoints (`ves_trends_start`,
  `ves_trends_poll`, …) and `includes/trend-finder.php` remain registered so
  any in-flight automation/integration keeps working. They are no longer
  reachable from product navigation. They are candidates for removal in a
  LATER major release, after staging confirms nothing depends on them —
  removal is deliberately NOT automatic.
- **DTF settings page** (`options-general.php?page=future-island-deep-trend-finder`)
  remains the module's settings surface, linked from the module hub.
- **Frontend workspace page** (the `[future_island_deep_trend_finder]`
  shortcode page) remains the canonical run form + results view.

### If you need to clean up manually (optional, destructive — NOT automated)

- Old `ves_trend_bundle_*` transients expire on their own (2 h TTL).
- Legacy `wp_ves_trend_runs` rows can be archived/dropped only after you
  confirm nothing reads them; this release does not touch them.

## 2. Admin navigation changes

A new top-level **Future Island** admin menu is the product navigation
(Overview + Signal Room, Trend Finder, Source Intelligence, Workbench,
Brief Builder, Asset Studio, Memory, Usage Ledger, Readiness/Evidence,
Settings).

| Old location | New location | Compatibility |
|--------------|--------------|---------------|
| Tools → FI Intake | Future Island → Workbench (links to the same page) | `tools.php?page=fi-intake` still works; the Tools menu ITEM is hidden to avoid duplicate navigation, the page registration is unchanged |
| Tools → FI Signal Room | Future Island → Signal Room (link) | same — URL unchanged, menu item hidden |
| Tools → FI Brief Workbench | Future Island → Brief Builder (link) | same |
| Tools → FI Draft Workbench | linked from Asset Studio rows ("Open in workbench") | same |
| (new) | Future Island → Trend Finder / Source Intelligence / Asset Studio / Memory / Usage Ledger | new module pages (`admin.php?page=fi-…`) |
| `admin.php?page=fi-intake` (wrong parent deep link) | redirects to `tools.php?page=fi-intake` | `FI_Module_Registry::legacy_tools_redirects()` |

Ops/diagnostic pages (Actor Registry, Admin Console, Billing Ledger,
Intelligence Map, Release Candidate, …) stay where they were — they are
operations surfaces, not product navigation.

## 3. Memory review state

Memory candidates now carry `approval_status` (`pending_review` /
`approved` / `rejected`) inside the record's JSON payload (`metadata` on the
canonical table, `content_json` on the legacy `memory_records` table).
Existing records without the key are treated as `pending_review`. Rejection
flags the record; it never deletes it.

## 4. Version identity

- Suite header/`FIS_VERSION`/`VES_PLUGIN_VERSION`: **1.4.0**
  (was 1.3.0; bump also cache-busts changed CSS/JS).
- Package label: `FIS_PACKAGE_LABEL = v0.4.0-modular-saas`.
- Deep Trend Finder module: `FIDTF_VERSION = 0.3.55` (unchanged in this pass).

## 5. Tests guarding this migration

- `tests/test-v040-trend-finder-consolidation.php` — one route/nav/page,
  aliases work, old run rows readable, legacy backend retained, notes exist.
- `tests/test-v040-module-registry.php` — one Trend Finder in module nav,
  registry contracts, legacy Tools menu dedupe.
- `tests/test-v110-unified-app.php`, `tests/test-v09318-p0-mvp-gating.php` —
  updated from the old two-Trend-Finder contract to the consolidated one.
