# Future Island — v0.4.0 Modular SaaS Report

## A. Executive verdict

`ready_for_local_review`

The plugin is re-architected into a registered module system with one
canonical Trend Finder, a unified product navigation, working product-loop
actions, and an installable, integrity-verified ZIP. All 209 tests pass
(196 pre-existing + 13 updated/new files; 3 new v0.4.0 test files alone add
143 executed checks). It is NOT staging-validated: no live WordPress,
browser, or provider credentials existed in this environment, so module
pages, menu behavior and dispatch were verified by executing the real PHP
under WP shims — not in a running wp-admin. One staging install is the gate
before anything stronger than local review.

## B. Artifact list (exact paths)

| Artifact | Path |
|----------|------|
| Installable ZIP | `/home/user/ai/future-island-intelligence-suite-v0.4.0-modular-saas.zip` |
| SHA256 file | `/home/user/ai/future-island-intelligence-suite-v0.4.0-modular-saas.zip.sha256` |
| Diff (v0.3.54 package baseline → v0.4.0) | `/home/user/ai/FUTUREISLAND_V040_MODULAR_SAAS.diff` (6,714 lines) |
| Test output log | `/home/user/ai/FUTUREISLAND_V040_TEST_OUTPUT.log` |
| Module registry doc | `/home/user/ai/future-island-intelligence-suite/FUTUREISLAND_MODULE_REGISTRY.md` (ships in the ZIP) |
| Migration notes | `/home/user/ai/future-island-intelligence-suite/FUTUREISLAND_MIGRATION_NOTES.md` (ships in the ZIP) |

Verification performed (recorded above in the build output):
- `sha256sum` of the ZIP equals the `.sha256` file: `fd3baa3817dacaee12e39dcd732b1eece6f326388eb0841351206950b8b12950`
- `sha256sum -c` → OK; `unzip -t` → "No errors detected"
- Archive contains `future-island-intelligence-suite/future-island-intelligence-suite.php` at the right depth (installable via Plugins → Upload), 651 files, 0 `.git`/`node_modules` entries, 2.3 MB.
- Builder: `future-island-intelligence-suite/scripts/build-release-package.sh` (new, reusable).

## C. Module registry summary

Registry: `FI_Module_Registry` (`includes/modules/class-fi-module-registry.php`); contract: `FI_Abstract_Module`. Duplicate ids rejected; statuses are honest; unavailable modules render **Not runnable**.

| Module | Page | Service | Status logic | Notes |
|--------|------|---------|--------------|-------|
| Signal Room | link → `tools.php?page=fi-signal-room` | existing `VES_Signal_Room` | unavailable if class missing | loop overview |
| **Trend Finder** | `admin.php?page=fi-trend-finder` | `FI_Trend_Finder_Service` → FIDTF engine | unavailable w/o engine; configuration_needed w/o workspace/live source | run history, provider truth, diagnostics, workspace CTA |
| Source Intelligence | `admin.php?page=fi-source-intelligence` | `FI_Source_Intelligence_Service` | configuration_needed while any actor fails preflight | per-source actor/allowlist truth + intake doorway |
| Workbench | link → `tools.php?page=fi-intake` | `VES_Source_Intake` | configuration_needed w/o store | source→signal→insight room (v0.3.55 rebuild) |
| Brief Builder | link → `tools.php?page=fi-brief-workbench` | `VES_Insight_Brief_Builder` | configuration_needed w/o builder | approved-insight gate enforced |
| Asset Studio | `admin.php?page=fi-asset-studio` | `FI_Asset_Studio_Service` | read_only w/o intake actions | Google Ads 5/5/5 blocks, copy buttons, caveat/proof, action rail |
| Memory | `admin.php?page=fi-memory` | `FI_Memory_Service` | read_only until store supports review | approve/reject candidates; never deletes |
| Usage Ledger | `admin.php?page=fi-usage-ledger` | `FI_Usage_Ledger_Service` | read_only by design | explainable rows: action/object trace/user/workspace/credits |
| Readiness / Evidence | link → RC page | existing RC services | unavailable if missing | supports validation, does not dominate |
| Settings | link → suite settings | existing `VES_Admin` | unavailable if missing | one entry, no duplicate settings UI |

Navigation: ONE top-level **Future Island** menu (Overview = module index).
Link entries reuse canonical pages (no duplicate page registrations);
redundant Tools menu items are hidden late while their URLs/registrations
stay intact; wrong-parent deep links redirect.

## D. Trend Finder consolidation

- **Duplicates found:** (1) legacy core "Trend Finder" — app route `trend`,
  sidebar entry, dashboard card, shell section, backed by
  `includes/trend-finder.php` + `VES_Run_Execution_Service` (waterfall
  engine, AJAX `ves_trends_start`); (2) "Deep Trend Finder" — app route
  `deep-trend` + dedicated frontend workspace, backed by
  `modules/deep-trend-finder/` (FIDTF). Two sidebar entries for one product.
- **Canonical:** the FIDTF engine, labeled **Trend Finder** everywhere
  (REST lifecycle, per-source jobs with preflight + watchdog, evidence
  quality, claim-readiness, report artifact, asset block).
- **Removed/deprecated:** duplicate `$ves_nav('trend', …)` entry and the
  dead `data-page="trend"` shell section removed; dashboard card and
  keyboard shortcut `6` retargeted; page title unified to "Trend Finder".
- **Old → new mapping & compatibility:** `VES_App_Router::ALIASES` maps
  `trend`, `trend_finder`, `trend-finder`, `deeptrend`, `deep_trend` →
  `deep-trend`, so every old link/bookmark lands on the canonical page.
  Legacy AJAX endpoints and `includes/trend-finder.php` are retained
  (compatibility, no data loss) but unreachable from navigation; removal is
  documented as a deliberate later step, never automatic. All run data
  untouched; the module hub reads existing FIDTF runs via the new read-only
  `FIDTF_Run_Service::list_recent_runs()`.
- **Tests:** `tests/test-v040-trend-finder-consolidation.php` (24 checks:
  one route/label/nav/section, aliases, legacy rows readable, notes exist);
  `tests/test-v040-module-registry.php` (exactly one Trend Finder in module
  nav/menu); the OLD two-Trend-Finder contracts in
  `test-v110-unified-app.php` and `test-v09318-p0-mvp-gating.php` were
  replaced with consolidation assertions.
- Full mapping: `FUTUREISLAND_MIGRATION_NOTES.md`.

## E. Runtime fixes (carried + extended; all test-locked)

- **Provider preflight / allowlist** (v0.3.55, retained): complete allowlist
  enumeration + registry entries for shipped defaults; preflight before
  credits/slots in every dispatch path; not-allowlisted classified
  non-retryable with honest messaging; unavailable sources render disabled,
  excluded from live/reduced sets. Now ALSO surfaced as a dedicated Source
  Intelligence page and the Trend Finder hub's provider rail.
- **Invalid input**: blocked in the browser with the exact missing field
  named (core forms + DTF form); server validation unchanged.
- **Parser/count truth**: wrapper objects never count as evidence; the
  provider→dataset→parsed→normalized→usable→decision-ready ladder is
  preserved in the report and per-source rails.
- **Stuck state**: watchdog finalizes stale queued/running jobs as
  retryable `provider_timeout_stale`; no eternal spinners.
- **Retry/reduced-search eligibility**: category-derived; config failures
  never offer retry/reduced search.
- **Product loop**: Source → Signal → Insight (approve/reject) → Brief →
  Asset (approve) → Memory candidate → Usage event, all one-click with
  nonce + capability + workspace checks and idempotent usage recording.
  NEW in v0.4.0: memory approve/reject decisions persisted on both backing
  stores (`update_memory_review`; rejection flags, never deletes), Asset
  Studio action rails reusing the audited intake actions, "Open in
  workbench" links, and the explainable Usage Ledger page (tracker rows now
  include workspace/user/run).

## F. UI changes

The wp-admin product surface is no longer scattered Tools pages: one
**Future Island** menu, an honest module index (status + one next action per
row — explicitly not a metric dashboard), and module pages that share the
signal-room shell: context header (what am I looking at + honest status),
center work object, right decision rail (the next action), diagnostics
collapsed in `<details>`. Paper/ink/sand/blue tokens from
`fiis-signal-room.css`; status-colored source rails instead of equal card
grids; Asset Studio fields render as copyable production fields (Copy
buttons only on valid fields); raw slugs are humanized in labels. The SaaS
app shell keeps exactly one Trend Finder entry. Tests assert the anatomy
(context header, rail, collapsed diagnostics, no metric tiles on the index,
no raw-slug nav labels).

## G. Tests run

| Command | Result | Notes |
|---------|--------|-------|
| `php -v` | PHP 8.4.19 | |
| `bash bin/test-all.sh -q` (before this pass) | 206/206 | v0.3.55 state |
| `bash bin/test-all.sh` (final, full output captured) | **209/209 passed** | log: `FUTUREISLAND_V040_TEST_OUTPUT.log` |
| `php tests/test-v040-module-registry.php` | 101/101 | executed registry/menu/render checks |
| `php tests/test-v040-trend-finder-consolidation.php` | 24/24 | router executed + template/JS/bootstrap contracts + legacy rows readable |
| `php tests/test-v040-memory-review.php` | 18/18 | executed review decisions with wpdb stub |
| `php -l` on all new/changed PHP; `node --check` on changed JS | clean | |
| `bash scripts/build-release-package.sh --out=/home/user/ai` | OK | zip + sha + `sha256sum -c` + `unzip -t` + layout check |
| `npm test` / `npm run build` / `npm run lint` / `composer test` / `phpunit` | not applicable | no `package.json`, `composer.json`, or `phpunit.xml` in the plugin |

## H. Remaining limitations

- **Needs live staging validation:** the new Future Island admin menu,
  module pages, Tools-menu dedupe and redirects, the consolidated app-shell
  navigation, and the DTF run lifecycle were verified by executing real PHP
  under WP shims and by template contracts — not in a running wp-admin or
  browser. Multisite/RTL/mobile-admin not exercised.
- **Provider credentials required for live runs:** Apify backend token +
  per-source bridges/toggles (TikTok, Instagram, Reddit, Google Trends,
  Google News); OpenAI key for AI planner/synthesis. Until configured,
  sources honestly report planned-only/configuration-needed.
- **Mocked in tests:** WordPress runtime (shims), `$wpdb`, provider HTTP
  transport. No real network calls were made.
- **Not production-ready pieces:** Asset Studio covers Google Ads blocks
  only (social/video hooks remain per-run report sections — stated in the
  UI, not claimed as a studio); Memory approval is payload-level (no store
  enum migration); legacy core trend backend retained pending a staged
  decommission; the public frontend app shell (`shortcode.php` dashboard
  page) was only touched for the Trend Finder consolidation.
- **Public marketing header/nav intentionally not touched.**

## I. Next smallest step

Install `/home/user/ai/future-island-intelligence-suite-v0.4.0-modular-saas.zip`
on a staging WordPress, open **Future Island → Overview**, and walk one loop
end-to-end (record source → signal → insight approve → brief → Google Ads
block → approve → memory candidate → approve in Memory → usage event →
check it in Usage Ledger), confirming on the way that the admin sidebar
shows exactly one Trend Finder and that `?fiis_page=trend` lands on it.
