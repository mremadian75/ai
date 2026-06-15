# Future Island — Runtime Repair and UI Rebuild Report (v0.3.55)

## A. Executive verdict

`ready_for_local_review`

This pass fixed the live runtime failures at their root (actor allowlist, dispatch preflight, error misclassification, stuck jobs, invalid-input handling, unreachable product-loop steps, meta-copy assets) and rebuilt the internal report/intake surfaces around the working loop. Every fix is locked by tests that fail on the v0.3.54 baseline and pass now (206/206 total).

It is NOT staging- or pilot-ready yet: no live WordPress, no real Apify token, and no real provider responses were available in this environment, so the repaired dispatch path has been verified by execution against the real code with mocked transport — not against live providers. The next gate is a staging run with one real provider (Section H).

## B. Failure inventory

| # | Flow | Failure (reproduced) | Root cause | Fix | Test |
| - | ---- | ------------------- | ---------- | --- | ---- |
| 1 | Any source run via core trend finder / Google Intelligence (live screenshots FI-250000 / FI-540000) | Run dies at `provider_dispatch_failed` with "This data source actor is not allowlisted on the server", surfaced as **"Una fuente está temporalmente ocupada"** with Retry + Reduced search that can never succeed | `VES_Apify_Actor_Registry::allowed_slugs()` enumerated a hand-maintained subset of legacy platform keys. Dispatch paths resolve actor slugs for keys OUTSIDE that subset (`tiktok_trending_videos` → `data_xplorer/tiktok-trends`, `tiktok_trending_creators`, `tiktok_trending_sounds`, `reddit_trends` → `easyapi/reddit-trends-scraper`, `tiktok_hashtag_trends` legacy default, semrush modes). **The gate blocked the product's own shipped defaults.** | `VES_Config::all_actor_platform_keys()/all_actor_slugs()` enumerate every resolvable key + semrush modes; registry consumes them; registry entries added for shipped defaults that were missing | `tests/test-v0355-allowlist-root-cause.php` (fatals on baseline) |
| 2 | DTF Google News source run (live screenshot: "skipped — ves_actor_not_allowlisted") | Google News offered as runnable, then skipped at dispatch by the core gate | DTF default actor `data_xplorer/google-news-scraper-fast` existed only in `FIDTF_Settings`, never in the core registry/allowlist | New registry entry `google_news_fast`; DTF module also registers its configured `actor_map` on the `ves_apify_actor_allowlist` filter (same trust as legacy settings overrides) | same as #1 + `test-fidtf-v0355-preflight-and-watchdog.php` |
| 3 | Misclassification of allowlist refusal | `ves_actor_not_allowlisted` (status 0) fell through `classify_error_category` to the default branch → `provider_transport_error` → "temporarily busy", retryable=true, reduced-search offered | No allowlist branch before the transport fallthrough at `class-ves-ajax-controller.php` | New category `provider_actor_not_allowlisted`: detected before the fallthrough, non-retryable, no reduced-scope action, honest ES/EN message naming the admin fix; frontend label/action maps updated | `tests/test-v0355-allowlist-root-cause.php` |
| 4 | No preflight anywhere | Allowlist discovered only inside `VES_Apify_Client::request`, after credits/slots/run records | No dispatch path validated the actor before dispatch | `VES_Apify_Actor_Registry::preflight_actor_slug()` wired into: AJAX `start()` (before credit reservation), trend executor (before slot acquisition → `failed_not_allowlisted`), DTF job dispatch (job finalized `skipped` with reason, no provider call), DTF generic adapter `start()`, DTF live preflight | `test-fidtf-v0355-preflight-and-watchdog.php` proves zero provider calls for a blocked source |
| 5 | Unrunnable sources offered as runnable | DTF source picker rendered every enabled source as selectable | `live_preflight_status()` never consulted the allowlist | `actor_preflight()` per source; blocked sources go to `unavailable_sources` with reason, render as **Unavailable** with a disabled checkbox; `source_live_ready()` false → planner/reduced sets exclude them automatically | same |
| 6 | Reddit stuck in "running", 0 rows, forever (live screenshot) | Job rows in `queued/running` that no refresh path could progress (no `provider_run_id`, or source became non-refreshable mid-run) spun indefinitely | No watchdog/stale finalizer existed | Watchdog in `maybe_refresh_running_jobs`: stale (default 900 s untouched, filterable) queued/running jobs finalize as retryable `provider_timeout_stale` with `completed_at` set | `test-fidtf-v0355-preflight-and-watchdog.php` |
| 7 | Invalid input reaches the server (live screenshot FI-500000) | Empty query submitted; server bounces `invalid_input` after a failed run attempt | Forms use `onsubmit="return false"` + JS clicks, so native `required` never fired; no JS gate | Declarative contracts (`data-ves-required-any` on social/ads/seo forms; existing `required` attrs enforced) + start-click gate that names the missing field, focuses it, dims the run button with a hint; DTF submit blocks empty brief+keywords / empty channels. Server validation unchanged (defense in depth) | render/grep assertions in `tests/test-v0355-allowlist-root-cause.php` (frontend wiring) |
| 8 | Insight → Brief unreachable from intake | Intake creates DRAFT insights; brief requires APPROVED; intake had no approve control | Review actions lived only outside the loop | `Approve`/`Reject` row actions through `VES_Insight_Lifecycle_Service` (evidence/quality gates intact); honest gate-failure messages | `tests/test-v0355-intake-product-loop.php` (fatals on baseline) |
| 9 | Draft → Memory/Usage dead end | Usage requires an approved draft; nothing could approve a draft in intake | Same gap | `Approve output` action via `update_draft_status` (transition ledger enforced) | same |
| 10 | Memory candidates live immediately | `status=active` with only a loose `requires_review` flag | Store enum has no pending status | `approval_status=pending_review` carried in metadata; consumers must treat it as continuity, not approved evidence | same |
| 11 | Normal path requires typing object ids | Six forms demanded raw ids | UI design | Pipeline ledger is now the primary surface with per-row action rails + workbench deep links; by-id forms collapsed into one Advanced/debug panel | same |
| 12 | Google Ads assets are meta-copy | Headlines like "Test the signal route", "Turn signal into brief", "Validate before campaign" — internal process language as ad copy | Generator templated workflow words around an evidence term | Campaign-facing generator derived from topic/market/audience/format; workflow framing only in metadata; `asset_fields()` flags `over_limit` and `meta_copy_blocked`; new `blocked_insufficient_evidence` status at zero usable evidence; intake bridge rewritten the same way | `test-fidtf-v0355-campaign-asset-copy.php` (fails on baseline) |
| 13 | Report reads like a dashboard card stack | Equal cards, metric tile wall, claim gate buried inside the cross-platform section, diagnostics mixed in | Template structure | Rebuilt as a 12-section intelligence artifact (order in §E); summary strip; source truth rail; collapsed diagnostics; copy buttons on valid asset fields only | `test-fidtf-v0355-artifact-report-render.php` |

Flows checked and found already correct (no fix needed): intake form action slugs all match registered handlers; nonce + capability checks present on every state-changing intake action; provider wrapper objects are flattened by `expand_source_item` and never counted as evidence rows; count truth fields (`provider_returned_items` … `discarded_items`) existed from v0.3.54 and are preserved; one source failing does not fail the whole DTF run.

## C. Changed files

| File | Change | Why |
| ---- | ------ | --- |
| `includes/class-ves-config.php` | `all_actor_platform_keys()` + `all_actor_slugs()` | Complete allowlist enumeration (root cause #1) |
| `includes/class-ves-apify-actor-registry.php` | Consume `all_actor_slugs()`; new entries `google_news_fast`, `tiktok_trending_videos/creators/sounds`, `reddit_trends`; lexis hashtag fallback; `preflight_actor_slug()` | Shipped defaults must pass the gate; one preflight for all dispatchers |
| `includes/class-ves-ajax-controller.php` | `provider_actor_not_allowlisted` category before transport fallthrough; distinct message; non-retryable; preflight in `start()` before credit reservation | Fix #3, #4 |
| `includes/class-ves-run-execution-service.php` | `failed_not_allowlisted` classification; preflight before slot acquisition | Fix #4 in trend runs |
| `includes/class-ves-source-intake.php` | Approve/Reject insight, Approve draft actions; memory `approval_status`; workbench links; campaign-facing draft body; signal-room page layout; advanced/debug id panel | Fixes #8–#12 |
| `modules/deep-trend-finder/includes/class-fidtf-settings.php` | `actor_preflight()`; allowlist in `source_live_ready()`; `unavailable_sources` in live preflight | Fix #5 |
| `modules/deep-trend-finder/includes/class-fidtf-source-job-service.php` | Preflight skip before dispatch; stale-job watchdog (`job_is_stale`, `finalize_stale_job`) | Fixes #4, #6 |
| `modules/deep-trend-finder/includes/class-fidtf-plugin.php` | Registers module actors on the core allowlist filter | Fix #2 |
| `modules/deep-trend-finder/includes/providers/class-fidtf-generic-apify-live-adapter.php` | Adapter-level preflight with precise error code | Defense in depth for refresh paths |
| `modules/deep-trend-finder/includes/class-fidtf-report-service.php` | Campaign-facing Google Ads generator; banned-term/over-limit flagging; `blocked_insufficient_evidence`; audience in focus; new human labels | Fix #12 |
| `modules/deep-trend-finder/templates/report-deep-trend-finder.php` | Full rebuild: 12-section intelligence artifact | Fix #13 |
| `modules/deep-trend-finder/templates/shortcode-deep-trend-finder.php` | Unavailable-source state in the channel picker | Fix #5 |
| `modules/deep-trend-finder/assets/js/fidtf-frontend.js` | Required-input gate on submit; copy-button handler | Fixes #7, #13 |
| `modules/deep-trend-finder/assets/css/fidtf-frontend.css` | Artifact layout layer; unavailable chip | Fix #13 |
| `assets/js/ves-frontend.js` | Required-input gate + live button state; allowlist category labels/messages/actions | Fixes #3, #7 |
| `assets/css/ves-frontend.css` | Blocked-button + hint styles | Fix #7 |
| `assets/css/fiis-signal-room.css` | Intake signal-room grid (pipeline + rail + advanced panel) | Fix #11 |
| `templates/_scraper-form.php`, `_ads-form.php`, `_seo-form.php` | `data-ves-required-any` contracts | Fix #7 |
| `future-island-intelligence-suite.php` | `FIDTF_VERSION` 0.3.54 → 0.3.55 | Version identity |
| 5 new test files, 1 updated contract test | See §F | Lock the fixes |

## D. Runtime fixes (how each area now behaves)

- **Provider execution / state machine.** DTF source jobs keep the existing lifecycle (`planned → waiting_for_provider/queued/running → completed / completed_no_relevant_evidence / retryable_failed / failed / skipped / paused_by_configuration`) with two repaired guarantees: a job that fails actor preflight finalizes as `skipped` (`source_actor_not_allowlisted`, non-retryable, `completed_at` set) before any provider call, and a queued/running job that nothing can progress is finalized by the watchdog as `failed`/`provider_timeout_stale` (retryable=true — a fresh retry CAN succeed). No state leaves a spinner alive: every terminal write sets `completed_at`.
- **Allowlist/registry.** The gate still blocks unknown actors (tested), but no longer blocks the product's own configuration: registry entries + complete legacy enumeration + module filter registration cover every shipped default and every admin-configured slug. `preflight_actor_slug()` distinguishes `actor_not_configured` from `actor_not_allowlisted`.
- **Invalid input.** Blocked in the browser with the exact missing field named and focused; run button dims with a hint while incomplete; server validation unchanged. Valid input is preserved (no form reset on block). Invalid requests no longer create run artifacts because they never leave the browser.
- **Count truth.** The v0.3.54 ladder (`provider_returned_items`, `actor_dataset_items`, `parsed_items`, `normalized_items`, `relevant/usable`, `decision_ready`, `creative_only`, `weak`, `discarded` + reasons) is preserved and now rendered as a per-source truth rail. Wrapper/metadata rows are flattened by the adapter and never counted as evidence (existing behavior, verified).
- **Retry / reduced search.** Eligibility derives from the error category. `provider_actor_not_allowlisted` is non-retryable and never offers reduced search; transient categories keep both. Reduced source sets exclude unavailable actors automatically because `source_live_ready()` is false for them.
- **Product loop.** Source → Signal → Insight (create, approve/reject) → Brief → Draft (approve) → Memory (pending review) → Usage (idempotent, traceable) all work from the pipeline rows without typing ids. Usage events carry workspace, user, module, object type/id, trace run-id, and a 7-day idempotency transient.
- **Asset generation.** Copy is consumer-facing and topic-derived; the banned-term audit (`meta_copy_blocked`) and `over_limit` flags make violations visible instead of silently shipping; status escalates only with ≥10 decision-ready rows, ≥2 source families and ≥3 demand rows, and collapses to `blocked_insufficient_evidence` at zero usable evidence.

## E. UI rebuild

- **Report** is now a single-column intelligence artifact in this order: title+status → decision summary → claim-readiness gate → evidence map → source execution truth → signal clusters → interpretation → briefing route → platform-ready outputs → proof still needed → limitations → trace+diagnostics. The metric tile wall became an editorial summary strip; sources render as a status-colored truth rail; the claim gate moved from deep inside the cross-platform card to a top-level gate; diagnostics, the role matrix and per-source cross-platform detail are collapsed `<details>`; valid asset fields have Copy buttons; raw slugs map to human labels (including the new `provider_timeout_stale`, `blocked_insufficient_evidence`, allowlist states).
- **Intake** is a signal room: context strip on top, the pipeline ledger (with per-row action rails: use-for-signal, promote, approve/reject, build brief, create block, approve output, memory, usage, workbench links) as the center, intake forms on a sticky right rail, and the by-id forms demoted to one collapsed Advanced/debug panel. Paper/black/sand/blue tokens come from the existing `fiis-signal-room.css` system.
- **DTF source picker** shows Unavailable (disabled) for sources that cannot run, with the reason and the admin fix.
- Not redesigned in this pass: the main shortcode app dashboard page (`templates/shortcode.php` overview), workbench internals, admin console. The workbench gained deep links from intake but keeps its existing layout.

## F. Tests run

| Command | Result | Notes |
| ------- | ------ | ----- |
| `php -v` | PHP 8.4.19 | |
| `bash bin/test-all.sh -q` (baseline v0.3.54) | 201/201 passed | Confirms "green tests ≠ working product" |
| `bash bin/test-all.sh -q` (after fixes) | **206/206 passed** | 5 new test files |
| `php tests/test-v0355-allowlist-root-cause.php` | 52/52; **fatals on baseline** | Executes real registry/config code |
| `php modules/.../test-fidtf-v0355-preflight-and-watchdog.php` | 27/27; **fatals on baseline** | Executes job service + adapter with transport stubs; proves zero dispatch for blocked actors |
| `php tests/test-v0355-intake-product-loop.php` | 27/27; **fatals on baseline** | Executes intake processors with store/lifecycle stubs |
| `php modules/.../test-fidtf-v0355-campaign-asset-copy.php` | passes; **fails on baseline** ("no meta term 'signal' inside ad copy") | Executes the real generator via reflection |
| `php modules/.../test-fidtf-v0355-artifact-report-render.php` | 58/58 | Section order, strip, rail, collapsed diagnostics, copy gating, responsive CSS |
| `php -l` on every changed PHP file | clean | |
| `node --check` on both changed JS bundles | clean | |
| `npm test` / `npm run build` / `npm run lint` / `composer test` / `phpunit` | not run | No `package.json`, `composer.json`, or `phpunit.xml` exists in this package |

## G. Remaining limitations

- **Still requires live validation:** no WordPress runtime, browser, Apify token, or OpenAI key existed in this environment. Dispatch, polling, and the rebuilt UI were verified by executing the real PHP against mocked transport and by markup/CSS contracts — not by a live browser session or a real provider run.
- **What still does not run without config:** live collection needs `enable_live_dispatch` + per-source bridges + a backend Apify token; AI planner/synthesis needs the OpenAI key; actors that genuinely require rental (Apify-side) will still fail with `provider_actor_rental_required` until rented — that is provider reality, correctly labeled.
- **Reduced search** in the core trend finder reuses the existing reduced-scope flow; it now never includes preflight-blocked actors, but its scope heuristics were not redesigned.
- **Memory governance** is metadata-level (`approval_status=pending_review`); a real pending status in the store enum and a memory review screen remain future work.
- **Not touched (by instruction or scope):** public marketing header/nav, landing hero, blog/pricing/FAQ, Google Ads API integration, predictive trends, AEO/GEO module, multi-LLM orchestration, real-time dashboard, Brand Brain v2, fine-tuning, automated publishing. The main app overview page and workbench internals kept their existing layout.
- **Watchdog scope:** `waiting_for_provider` (config-paused) jobs are intentionally NOT watchdogged — that state is honest and actionable; only queued/running spinners are.

## H. Next smallest step

Deploy this package to staging with one real Apify token, run one Deep Trend Finder run with all six sources enabled, and verify three things in the browser: (1) Google News now runs (or shows Unavailable with the registry reason instead of dying at dispatch), (2) a deliberately blocked actor shows the non-retryable "not enabled on this server" message with no Retry/Reduced-search buttons, and (3) no source stays in "running" past the watchdog window. Capture screenshots at 375/768/1280 of the rebuilt report and intake pages.
