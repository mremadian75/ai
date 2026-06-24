# Fluent Boards AI Automation Add-on

Internal WordPress AI automation and project-intelligence add-on for the official Fluent Boards / Fluent Boards Pro plugin.

Version: 0.9.7

> **0.9.7** makes the in-board **"AI Analyze"** panel show the result inline (summary, priority, confidence, risk) instead of just "queued", via an optional `task_id` filter on the suggestions REST endpoint that returns a slim, display-only projection.
>
> **0.9.6** adds an admin-only **"AI Analyze" launcher inside the Fluent Boards screens** — analyze a task by ID without leaving the board, then jump to the review queue. It loads only on Fluent Boards screens, only for administrators, and uses the existing capability-protected REST endpoint.
>
> **0.9.5** adds a lazy class autoloader (classes load on demand) and loads the REST layer only on REST requests for faster page loads, plus opt-in **task dependency** triggers for deeper task intelligence. It stays a separate companion to Fluent Boards — by design it is not merged into the host plugin, so Fluent Boards keeps receiving official updates.
>
> **0.9.4** fixes the admin sidebar bug: activating the plugin could hide WordPress **Settings** submenu items. The AI Company Knowledge post type no longer injects itself into the core Settings menu and the capability setup was corrected; the menu link is now added safely by the plugin. (0.9.3 fixed settings being wiped on save, made fresh installs **safe by default**, guarded against a missing Fluent Boards, and introduced the tabbed command center.) All existing settings and knowledge are preserved and migrated automatically.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- The official Fluent Boards or Fluent Boards Pro plugin (for task hooks and write-back)
- An OpenAI-compatible chat-completions API key
- Works on standard shared hosting. No Composer, no build step, no external automation service.

## Installation

1. Install and activate the official **Fluent Boards / Fluent Boards Pro** plugin.
2. Upload this add-on (Plugins → Add New → Upload Plugin) and activate it.
3. On first activation the engine is **OFF (safe mode)** — it will not call AI or change any task until you enable it.
4. Go to **Settings → Fluent Boards AI** to open the command center.

## What it does

- Listens to Fluent Boards task events.
- Builds task context from current task data, recent comments, subtasks, and event payload.
- Adds company context from pasted memory, structured knowledge posts, team directory, decision rules, KPIs, brand voice, and playbooks.
- Calls an OpenAI-compatible chat completions API.
- Saves AI recommendations into a review queue.
- Tracks an internal audit trail for AI comments, held auto-actions, approvals, rejections, digest jobs, and automation writes.
- Provides a System Health panel and exportable JSON snapshot for troubleshooting/staging handoff.
- Tracks AI usage and enforces internal call/token budgets to control cost.
- Retrieves and enforces Automation Playbooks / Guardrails so internal rules can block unsafe auto-actions.
- Can queue batch triage for recently updated tasks from inside WordPress.
- Provides a redesigned admin command center with guided setup, dashboard cards, templates, smoother knowledge/team/playbook editing, and compact/comfortable density.
- Adds task quality scoring, AI council lenses, RACI reasoning, SLA-aware recommendations, and meeting/no-meeting guidance.
- Optionally writes structured AI comments into task threads.
- Allows managers/admins to approve or reject AI suggestions.
- Uses approved/rejected suggestions as lightweight feedback-learning context for future recommendations.
- Can update priority, create subtasks, send alerts, scan overdue tasks, send daily digests, and send weekly operational intelligence reports without n8n/Make/Zapier.

## Safe setup (recommended order)

The admin page is organized into tabs. Each tab has its **own Save button** — save one tab at a time.

1. **AI Provider** — set the model and API key (preferably via `wp-config.php`, see below). Click **Test OpenAI + Context**.
2. **Company Memory** — paste company profile, products, goals, customers, constraints, board profiles, and playbooks. Use the starter-template buttons.
3. **Team Directory** — add members with role, department, skills, capacity, timezone, seniority, manager, and notes.
4. **Task Intelligence** — choose recommendation depth and which signals to produce (owner/reviewer, acceptance criteria, due date, RACI, meeting guidance, etc.).
5. **Automations** — keep **Action policy = Review-first**. Pick a few triggers (start with task created, stage changed, priority changed, date changed, comment created). Leave **Suggest/apply priority** and **Create subtasks** OFF until you trust the suggestions.
6. **Dashboard → Analyze task** — run a real task, then open the **Review Queue** to approve or reject.
7. Only after suggestions look consistently good, flip the **Engine enabled** master switch ON (Automations tab).

### Default safety posture

- Fresh installs are **disabled** until you explicitly enable the engine.
- The default policy is **review-first**: AI never silently changes priority or creates subtasks.
- Task-mutating actions (priority/subtasks) are opt-in and additionally gated by confidence, an `automation_safe` flag from the model, manager-approval flags, and matched playbook guardrails.
- Generated AI comments are marked so they can never re-trigger comment-created automation (loop prevention), and a per-task/event cooldown limits noise.

## wp-config.php constants

Define your provider credentials as constants so the key is never stored in the database or shown in the UI:

```php
define('FBAIA_OPENAI_API_KEY', 'sk-...');
define('FBAIA_OPENAI_API_BASE', 'https://api.openai.com/v1'); // optional, for OpenAI-compatible providers
```

When a constant is set, the matching field on the AI Provider tab is locked and the key is never displayed.

## Review-first workflow

The default policy stores recommendations first and holds auto-actions. This avoids letting AI silently change task priority or create subtasks before you trust the system.

In the review queue you can:

- Approve & apply
- Reject with feedback

Rejected and approved suggestions are later used as examples so the assistant becomes more aligned with how your company makes decisions.

## Structured knowledge library

The plugin registers:

- Post type: `fbaia_knowledge`
- Taxonomy: `fbaia_topic`

Use it for durable internal knowledge such as:

- Company positioning
- Product specs
- Client segments
- Project playbooks
- Launch SOPs
- Brand rules
- Legal/compliance constraints
- Internal glossary/codenames

## Limitations

- This is still a lightweight WordPress-native RAG-like system, not a vector database.
- Priority updates and subtask creation depend on Fluent Boards internal model compatibility and should be tested on staging first.
- For production, use review-first mode until the feedback loop is mature.




## v0.9 UI/UX and smarter reasoning

The admin experience is now closer to an internal AI command center:

- Dashboard cards for readiness, pending decisions, AI usage, and knowledge context.
- Guided setup checklist so admins know what context is still missing.
- Sticky navigation to jump between Memory, Team, Actions, Triggers, Usage, Health, Insights, Review Queue, and Advanced.
- Starter templates for company profile, team directory, board profiles, and automation playbooks.
- Textarea tools for copy/clear and auto-growing fields.
- Compact/comfortable density setting.

The AI schema also now supports:

- `task_quality` for clarity/readiness scoring.
- `ai_council` for multi-lens review.
- `raci` for ownership reasoning.
- `meeting_recommendation` to avoid unnecessary meetings and recommend live sync only when justified.

New memory fields:

- SLA / response-time policy
- RACI / ownership matrix
- AI council review lenses

## v0.8 automation playbooks and cost guard

New fields in Settings → Fluent Boards AI:

- **Cost guard**: daily AI call budget and monthly token budget.
- **Automation playbooks / guardrails**: structured internal rules that can be matched per task.
- **Comment cooldown**: avoids repeated low-value AI comments for the same task/event.
- **Batch triage**: queues AI analysis for recently updated tasks.

Example playbook entry:

```text
name: Paid campaign launch guardrail
match: campaign, Meta Ads, Google Ads, landing page, tracking
instructions: Check tracking plan, campaign objective, landing page owner, approval status, and post-launch review.
risk_rules: Flag high risk if there is no GA4/GTM plan, no budget owner, or launch date is unclear.
required_context: objective, budget, channel, landing page URL, tracking plan, reviewer
reviewer: Head of Growth
block_auto_actions: yes
min_confidence: 0.75
---
name: Product bug triage
match: bug, checkout, payment, account, broken, error
instructions: Prioritize customer-impacting bugs and ask for reproduction steps when missing.
risk_rules: Escalate checkout/payment bugs immediately.
reviewer: Product/Engineering Lead
block_auto_actions: yes
min_confidence: 0.80
```

When a playbook matches, the AI receives it as context. The plugin also enforces the rule after the AI response: if a playbook blocks auto-actions or needs a higher confidence score, priority/subtask automation is held even if the model says it is safe.

## v0.7 operational context

New context sections available in Settings → Fluent Boards AI:

- Operating principles
- Definition of done
- Escalation policy
- Board profiles
- Expanded team directory fields

Board profile examples can be pasted as JSON or as entries separated by `---`:

```text
board_id: 12
name: Marketing Campaigns
goal: Plan and deliver campaign tasks with clear tracking and creative approval.
owner_role: Marketing Manager
default_reviewer: Head of Growth
definition_of_done: Campaign tasks require asset links, tracking plan, publish date, and post-launch review.
risk_rules: Flag as high risk if paid traffic, tracking, legal claims, or launch deadline are unclear.
```

## Diagnostics and export

The admin page now includes:

- System Health
- Operational Intelligence
- Audit Trail
- Export snapshot JSON

The export intentionally removes API secrets.

## Troubleshooting

**My settings (Company Memory / Team Directory) disappeared after saving.**
This was a bug in 0.9.2 and is fixed in 0.9.3. Saving is now non-destructive and each tab saves only its own fields. If a save is ever truncated by your server, the plugin now refuses to save and shows a clear error instead of wiping data. A pre-upgrade backup of your settings is stored in the `fbaia_settings_backup` option.

**I see "Settings were NOT saved … the form submission was incomplete."**
Your server's PHP `max_input_vars` (or `post_max_size`) clipped the request. Save one tab at a time (each tab is small), or ask your host to raise `max_input_vars` (e.g. to 3000). Nothing was changed when you see this notice.

**Activating the plugin hid Settings submenu items / felt like it restricted admin access.**
Fixed in 0.9.4. The AI Company Knowledge post type used to inject itself into the core **Settings** menu with a custom capability setup that could interfere with how WordPress builds that submenu on some sites. It no longer does — the link is added safely by the plugin instead. If you ever need to rule the post type out entirely, add this to `wp-config.php`:

```php
define('FBAIA_DISABLE_KNOWLEDGE_CPT', true);
```

With that defined, the Knowledge post type is not registered at all (the rest of the plugin keeps working). If your menus return only after defining it, tell us — but 0.9.4 should already resolve it without the constant.

**Other WordPress / Fluent Boards admin pages looked broken after activating.**
0.9.3+ hardens activation and admin rendering: the plugin no longer auto-enables on install, only registers Fluent Boards hooks when Fluent Boards is present, scopes all CSS under `.fbaia-wrap`, and guards every Fluent Boards reference. Update to the latest version and the wider admin should be unaffected.

**Fluent Boards "not detected" notice.**
Install/activate the official Fluent Boards or Fluent Boards Pro plugin. Until then the add-on stays inert (no hooks, no writes).

**Nothing happens on task events.**
Check, in order: the **Engine enabled** master switch (Automations tab), that the relevant trigger is selected, that an API key is configured, the **System Health** panel, and the **Logs** tab. The cost guard and rate limit can also pause AI calls.

**AI returns invalid JSON.**
The plugin validates and sanitizes all model output and falls back to a safe structure (low confidence, automation marked unsafe) rather than acting on malformed data.

## Privacy & security

- API keys, nonces, cookies, and tokens are never logged. Logs and the audit trail redact sensitive keys, and large values are truncated.
- The API key is never echoed back into the admin UI; defining it in `wp-config.php` keeps it out of the database entirely.
- The diagnostic snapshot export removes API secrets and the webhook secret.
- Every admin action requires a capability check (`manage_options`) and a nonce; every REST route has a `permission_callback`. Redirects use `wp_safe_redirect`.
- Task context, comments, and company memory are sent to your configured AI provider only when the engine is enabled and an event/analysis runs. Raw prompts and task content are not written to the PHP error log unless **Debug** is explicitly enabled (and secrets are still redacted).
- The assistant is instructed to use only the provided context and to avoid inventing company facts; it lowers confidence and asks questions when information is missing.

## Testing on staging

1. Clone the site to staging and ensure Fluent Boards is active there.
2. Activate the add-on. Confirm the **Settings** menu and Fluent Boards admin still appear normally.
3. Add an API key and run **AI Provider → Test OpenAI + Context**.
4. Save each tab, refresh, and confirm values persist.
5. Deactivate Fluent Boards and confirm the add-on shows a graceful notice with no fatal error; re-activate and confirm **System Health** passes.
6. With **Review-first** on, analyze a real task and confirm a suggestion appears in the **Review Queue** without changing the task.
7. Approve a suggestion and confirm the expected priority/subtask change happens.
8. Run the bundled checks from the plugin directory: `php tests/run.php` (no Composer/PHPUnit required).

Keep **review-first** mode in production until the feedback loop is mature.
