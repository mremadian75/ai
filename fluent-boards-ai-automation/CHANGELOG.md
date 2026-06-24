# 0.9.7

### Improved — in-board result, not just "queued"

- The in-board **AI Analyze** panel now shows the result **inline**: after queuing, it polls for the new suggestion and renders the summary, recommended priority, confidence, and risk right in the panel — with a one-click link to the full review queue. If the background job is still running after a few seconds, it falls back to a clear "it will appear in the review queue shortly" message.
- The `/suggestions` REST endpoint accepts an optional `task_id` filter and returns a **slim, display-only projection** (no raw `ai_payload`/`payload`) for that case, so the panel stays fast and the full analysis blob is never shipped to the browser. The unfiltered endpoint is unchanged for the admin tables.

# 0.9.6

### Added — in-board AI analysis

- **"AI Analyze" launcher inside Fluent Boards.** A small, admin-only floating button now appears on Fluent Boards screens, opening a panel where a manager can analyze a task by ID without leaving the board. It pre-fills the task ID from the board URL when possible, shows the result inline, and links straight to the Review Queue.
- Injected via the official `fluent_boards/after_enqueue_assets` hook, so its assets load **only on Fluent Boards screens** and **only for `manage_options` users**. It calls the existing capability-protected REST endpoint with a REST nonce — no secrets are exposed to the browser.
- Fully decoupled from Fluent Boards' Vue internals (renders its own launcher/panel), so it is resilient across Fluent Boards versions.
- Respects safe mode: while the engine is OFF it shows a clear "enable the engine" message instead of doing anything.
- All styles are namespaced under `.fbaia-board-*` (no global CSS); the panel is keyboard accessible (Enter to run, Esc to close, visible focus).

# 0.9.5

Performance and integration release. This add-on stays a **separate companion** to Fluent Boards / Fluent Boards Pro (the right architecture for updateability, safety, and load time) — it is not merged into the host plugin.

### Faster

- **Lazy class autoloader.** Replaced eager `require_once` of all ~18 class files on every request with an `spl_autoload_register()` that loads each class only when it is first used. A normal front-end page load now pulls in almost none of the plugin's classes.
- **REST controller is loaded only on REST requests** (`rest_api_init`), so its class and route registration never run on normal admin/front page loads.

### Deeper integration

- Added **task dependency** triggers — `task_dependency_added` and `task_dependency_removed` (real Fluent Boards Pro hooks). Dependencies and blockers are high-signal for task intelligence, so the assistant can react when a task becomes blocked or unblocked. Both are **opt-in** (off by default) and honor the master engine switch and review-first policy like every other trigger.

### More accurate

- Dependency events flow into the existing AI context, which already reasons about `dependencies` / `blocked_by`, so analyses reflect blocking relationships when those events are enabled.

### Tests

- Added autoloader class→file mapping guards and trigger checks (69 checks total).

# 0.9.4

Fixes the admin sidebar / capability bug: activating the plugin could hide WordPress **Settings** submenu items and feel like it "restricted admin access." Root cause: the **AI Company Knowledge** custom post type auto-injected itself into the core Settings menu (`show_in_menu => 'options-general.php'`) using a custom `capability_type` plus a `map_meta_cap` anti-pattern (meta caps `edit_post`/`read_post`/`delete_post` listed under `capabilities`). On some sites this interferes with how WordPress builds/filters the Settings submenu.

### Fixed

- The Knowledge CPT no longer injects itself into the core Settings menu (`show_in_menu => false`). The "AI Company Knowledge" link is now added by the plugin itself via a plain `add_submenu_page()` with a `manage_options` capability, so it stays visible under **Settings** without ever touching the core submenu list or other plugins' menu items.
- Removed the `map_meta_cap` anti-pattern: the meta capabilities (`edit_post`, `read_post`, `delete_post`) are no longer listed under `capabilities` (they are derived from the primitive caps). Access remains strictly limited to administrators (`manage_options`).

### Added

- `FBAIA_DISABLE_KNOWLEDGE_CPT` constant — define it as `true` in `wp-config.php` to skip the Knowledge custom post type entirely. Useful as an instant escape hatch / confirmation if a site still shows any admin-menu issue.

No settings, knowledge posts, or other data are affected by this change.

# 0.9.3

Stability, safety, and admin UX hardening release. **No settings keys were removed or renamed; all existing data is preserved and migrated automatically.**

### Fixed (critical stability)

- **Settings no longer disappear on save.** `FBAIA_Helpers::update_settings()` previously rebuilt the entire option from the POST body, falling back to defaults for any field that was missing. A partial or truncated submission (e.g. PHP `max_input_vars`) therefore wiped Company Memory, the Team Directory, board profiles, and reset the model. Saving is now **non-destructive**: cleaned values are merged over the currently stored settings and only the keys actually submitted are recomputed.
- **Section-scoped saving.** The single giant settings form was split into per-tab forms. Each tab posts only its own section, so an incomplete request can never affect another tab's settings.
- **Truncation guard.** Every settings form ends with a hidden completeness sentinel. If a save arrives without it (the request was clipped by `max_input_vars`/`post_max_size`), the save is rejected with a clear admin notice instead of persisting incomplete data.
- **Fixed a double-unslash** of large textareas during save that could corrupt content containing backslashes.
- **Dashboard health counts** now read the correct `severity` key (previously always reported all-OK).

### Added (safety & resilience)

- **Safe mode on fresh install.** A brand-new activation is now **disabled by default** — the plugin makes no AI calls and mutates no tasks until an admin reviews the settings and explicitly enables the engine. The master switch also gates background jobs: while the engine is disabled, no cron is scheduled and the overdue scan (the only background job that writes to tasks) is blocked even via its manual button — so a disabled engine truly never mutates a task.
- **Versioned migrations with backup.** The plugin stores a DB version (`fbaia_db_version`) and, before any upgrade transform, backs up the current settings to `fbaia_settings_backup`. Migrations add new keys without overwriting or deleting existing data, and run automatically on upgrade.
- **Dependency guard.** Fluent Boards task hooks are only registered when Fluent Boards is actually present. With the dependency missing/inactive the add-on stays completely inert and shows a clear admin notice.
- **More health checks:** WordPress version, PHP version, Fluent Boards Task/Comment model classes, and options-table writability.

### Changed (admin UX → command center)

- Rebuilt the admin page as a tabbed **AI Project Intelligence Command Center**: Dashboard (status cards, quick actions, setup checklist), AI Provider, Task Intelligence, Automations, Company Memory & Team, Review Queue, and Logs & Health.
- Per-section **Save** buttons, an unsaved-changes warning, clearer warnings before enabling task-mutating actions, an engine status badge, and an explicit "review-first" framing.
- All CSS is scoped under `.fbaia-wrap`; added visible keyboard focus states and ARIA labels.

### Tests

- Added a dependency-free test suite (`tests/run.php`, no PHPUnit/Composer) covering non-destructive merge, partial-POST preservation, API-key preservation, AI JSON validation/fallback, comment-loop prevention, dependency-missing behavior, and a full admin-render smoke test with Fluent Boards absent.

# 0.9.2

- Fixed robust boolean parsing for AI and playbook outputs so string values like `false`, `no`, and `0` are not treated as true by PHP.
- Fixed comment write-back for events that only provide `task_id` by fetching the task board ID before writing a comment.
- Fixed queue handling so dedupe transients are only set after scheduling succeeds, and queue failures are logged/returned explicitly.
- Fixed comment cooldown so it is set only after a comment is successfully written.
- Moved hourly AI rate-limit increment until after an API key is present.

# Changelog

## 0.9.1

- Fixed a v0.9.0 sanitizer bug that dropped `task_quality`, `ai_council`, `raci`, and `meeting_recommendation` from model output before comments/suggestions were rendered.
- Fixed Team Directory parsing so template/header rows such as `Name | email | role` are ignored instead of being treated as a real team member.
- Hardened the AI Company Knowledge post type and topic taxonomy so only administrators with `manage_options` can manage or access sensitive company knowledge through WordPress admin/REST.
- Improved System Health diagnostics to report parsed team-member count instead of only raw Team Directory text length.
- Added smoke-tested coverage for Team Directory header parsing and v0.9 structured AI field retention.

## 0.9.0

- Added a redesigned admin command center with hero area, sticky section navigation, dashboard cards, guided setup checklist, progress indicator, and compact/comfortable UI density.
- Added textarea helper tools, starter templates, smooth navigation, copy buttons, responsive tables, and improved admin CSS/JS.
- Added smarter AI reasoning modules: task quality score, AI council lenses, RACI reasoning, and meeting recommendation.
- Added new company-memory fields: SLA policy, RACI matrix, and review lenses.
- Expanded the default AI JSON schema and task-thread comment formatting for task quality, AI council, RACI, and meeting guidance.
- Improved prompt instructions so the assistant avoids meetings by default, scores task readiness, and uses SLA/RACI/playbook context when present.

## 0.8.0

- Added AI Usage & Cost Guard with daily call budget, monthly token budget, recent usage table, and reset action.
- Added Automation Playbooks / Guardrails with retrieval per task and policy enforcement that can block unsafe auto-actions.
- Added playbook-aware AI prompt context, AI schema support for `decision_brief` and `policy_controls`, and matched-playbook display inside AI comments.
- Added batch triage action to queue analysis for recently updated Fluent Boards tasks without needing external n8n/Make/Zapier.
- Added comment cooldown to reduce noisy repeated AI comments on the same task/event while allowing high-risk signals through.
- Extended System Health, Operational Intelligence, and export snapshot with usage and playbook diagnostics.
- Added suggestion metadata for decision brief and matched playbook count.

## 0.7.0

- Added internal audit trail for AI comments, priority updates, subtask creation, held auto-actions, approvals, rejections, overdue scans, and digest jobs.
- Added System Health diagnostics with checks for Fluent Boards detection, API key, Action Scheduler, WP-Cron, company memory, team directory, and recent errors.
- Added Operational Intelligence dashboard summarizing suggestion volume, high-risk work, task types, recurring risks, and knowledge gaps.
- Added exportable JSON snapshot for settings without secrets, knowledge library, suggestions, audit trail, health, and insights.
- Added weekly intelligence email digest.
- Added board profiles, operating principles, company-wide definition of done, and escalation policy to Company Memory.
- Expanded Team Directory fields with capacity, timezone, seniority, manager, and notes.
- Expanded AI schema and comments with knowledge gaps, team coordination, and escalation recommendations.
- Added context limits for legacy knowledge entries, feedback examples, and relevant team members.

## 0.6.0

- Added structured **AI Company Knowledge** custom post type and topic taxonomy.
- Added lightweight retrieval from published knowledge posts in addition to pasted company memory.
- Added internal **AI Suggestion Review Queue** with approve/reject workflow.
- Added feedback learning: approved/rejected suggestions are retrieved as context for future recommendations.
- Added review-first execution policy so priority/subtask automation can be held for human approval.
- Added REST endpoints for listing, approving, and rejecting suggestions.
- Added admin actions to import legacy `Knowledge entries` into the structured knowledge library.
- Expanded AI schema with `learning_notes` and `manager_decision`.
- Improved task-thread comments with suggestion IDs, approval status, and approval reasoning.

## 0.5.0

- Added richer task enrichment from current task snapshot, recent comments, subtasks, and task signals.
- Added business metrics, brand voice, project playbooks, and decision rules.
- Expanded AI output schema with task type, urgency/impact/effort/risk scores, owner/reviewer suggestions, due-date suggestion, acceptance criteria, quality gates, blockers, and automation safety.
- Added manual task analysis from settings and REST.
- Added low-value comment suppression.

## 0.4.0

- Added company memory, team directory, and lightweight retrieval from pasted knowledge entries.

## 0.3.0

- Removed dependency on external n8n/Make/Zapier flows and moved execution fully inside WordPress.

## 0.2.0

- Improved event dedupe, loop protection, webhook hardening, and JSON parsing.

## 0.1.0

- Initial internal AI automation add-on for Fluent Boards.
