# Future Island — Module Registry (v0.4.0)

The internal SaaS is organized as **semi-independent modules inside one
plugin** (the Deep Trend Finder pattern, standardized). One registry, one
unified navigation, one module class + service + renderer per feature.

- Registry: `includes/modules/class-fi-module-registry.php` (`FI_Module_Registry`)
- Contract: `includes/modules/class-fi-abstract-module.php` (`FI_Abstract_Module`)
- Bootstrap: `future-island-intelligence-suite.php` → `fis_register_modules()` + `FI_Module_Registry::boot()`
- Navigation: top-level WP-admin menu **Future Island** (Overview = module index)
- Tests: `tests/test-v040-module-registry.php`, `tests/test-v040-trend-finder-consolidation.php`, `tests/test-v040-memory-review.php`

Every module declares: `id`, `label`, `description`, `capability`, `nav`
(page or link), `status()` health (`available` / `configuration_needed` /
`read_only` / `unavailable`), `actions` (admin-post slugs), `service_class`,
`renderer_class`. Duplicate ids are rejected. Modules whose dependencies are
missing render as **Not runnable** on the index — never as fake features.

## Modules

| Module id | Label | Navigation target | Service | Renderer | Status logic |
|-----------|-------|-------------------|---------|----------|--------------|
| `signal_room` | Signal Room | link → `tools.php?page=fi-signal-room` | — (existing surface) | `VES_Signal_Room` | unavailable when renderer class missing |
| `trend_finder` | Trend Finder | page `admin.php?page=fi-trend-finder` | `FI_Trend_Finder_Service` (wraps FIDTF engine) | `FI_Trend_Finder_Renderer` | unavailable without engine; configuration_needed without workspace page / live source |
| `source_intelligence` | Source Intelligence | page `admin.php?page=fi-source-intelligence` | `FI_Source_Intelligence_Service` (actor registry + DTF preflight) | `FI_Source_Intelligence_Renderer` | configuration_needed while any enabled source fails actor preflight |
| `signal_workbench` | Workbench | link → `tools.php?page=fi-intake` | `VES_Source_Intake` (existing audited actions) | — | configuration_needed without the intelligence store |
| `brief_builder` | Brief Builder | link → `tools.php?page=fi-brief-workbench` | `VES_Insight_Brief_Builder` | `VES_Workbench` | configuration_needed without the builder service |
| `asset_studio` | Asset Studio | page `admin.php?page=fi-asset-studio` | `FI_Asset_Studio_Service` | `FI_Asset_Studio_Renderer` | read_only when intake actions unavailable |
| `memory` | Memory | page `admin.php?page=fi-memory` | `FI_Memory_Service` | `FI_Memory_Renderer` | read_only until the store supports review decisions |
| `usage_ledger` | Usage Ledger | page `admin.php?page=fi-usage-ledger` | `FI_Usage_Ledger_Service` | `FI_Usage_Ledger_Renderer` | read_only by design (rows are written by product actions) |
| `readiness` | Readiness / Evidence | link → `admin.php?page=ves-release-candidate` | `VES_RC_Readiness_Service` (existing) | `VES_Release_Candidate_Page` | unavailable when RC surface missing |
| `settings` | Settings | link → `options-general.php?page=ves-social-scraper` | — | `VES_Admin` | unavailable when settings surface missing |

## Module actions (admin-post, all nonce + capability checked)

| Module | Actions |
|--------|---------|
| Workbench / Brief Builder / Asset Studio | reuse the audited intake actions: `ves_intake_source`, `ves_intake_signal`, `ves_intake_signal_to_insight`, `ves_intake_approve_insight`, `ves_intake_reject_insight`, `ves_insight_to_brief`, `ves_brief_to_draft`, `ves_intake_approve_draft`, `ves_draft_to_memory`, `ves_draft_usage_event` |
| Memory | `fi_memory_approve`, `fi_memory_reject` (new in v0.4.0; persist `approval_status` in the record payload; never delete) |
| Trend Finder | run lifecycle stays on the FIDTF REST routes (`/fidtf/v1/preflight`, `/runs`, `/runs/{id}`, `/runs/{id}/report`, `/runs/{id}/items`) |

## Navigation behavior

- ONE top-level **Future Island** menu; submenu order = registration order
  (Overview, Signal Room, Trend Finder, Source Intelligence, Workbench,
  Brief Builder, Asset Studio, Memory, Usage Ledger, Readiness/Evidence,
  Settings).
- Link-type entries point at the EXISTING canonical pages — no page is
  registered twice. The now-redundant Tools menu items for FI surfaces are
  hidden late (`remove_submenu_page`) while their page registrations and
  URLs keep working (legacy tests + bookmarks intact).
- `admin.php?page=fi-intake`-style wrong-parent deep links are redirected to
  their canonical `tools.php` URLs (`legacy_tools_redirects`).
- Exactly ONE Trend Finder entry exists anywhere in product navigation
  (asserted by tests).

## Page anatomy (shared shell, not a dashboard)

Every module page renders: context header (breadcrumb eyebrow, H1, purpose
sentence, honest status detail) → `fi-room-grid` with the working object in
the center (`fi-room-main`) and a decision/next-action rail
(`fi-room-rail`) → diagnostics collapsed in `<details>`. Visual tokens come
from `assets/css/fiis-signal-room.css` (paper/ink/sand/blue). The module
index is a registry view (status + one next action per module) — no metric
tiles.
