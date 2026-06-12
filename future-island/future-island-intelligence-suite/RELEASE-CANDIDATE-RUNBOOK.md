# Future Island Intelligence Suite — v0.1 Release Candidate Runbook

Plugin version: **1.2.5** · Product label: **v0.1-rc1**

This runbook takes an operator from the RC ZIP to a verified staging install.
It never touches production. **This RC is NOT production-ready until the live
staging checklist passes and is signed off by a human operator.**

---

## 0. Ground rules

- **Staging only.** Never install an RC directly on production.
- **Backup first.** No step below may run before a database export exists.
- **No `--apply` during validation.** Every validation command is read-only or
  dry-run by default; leave it that way.
- **No AI execution.** `ves_generation_execution_enabled` must stay OFF (default).
- **No fabricated results.** If a step fails, record the failure — do not skip it
  and do not mark it passed.

## 1. Prerequisites

| Requirement | Check |
| --- | --- |
| WordPress 6.x staging site | `wp core version` |
| PHP 7.4+ (8.x supported) | `php -v` |
| MySQL/MariaDB via WordPress | `wp db query "SELECT VERSION();"` |
| WP-CLI | `wp --info` |
| Admin user with `manage_options` | `wp user list --role=administrator` |
| The RC ZIP + its SHA-256 | compare with `sha256sum <zip>` |

## 2. Backup (mandatory)

```bash
wp db export staging-backup-$(date +%Y%m%d-%H%M%S).sql
```

Keep the file outside the web root. Validation must not start without it.

## 3. Install / update

```bash
wp plugin install /path/to/future-island-intelligence-suite-v01-release-candidate.zip --force
wp plugin activate future-island-intelligence-suite
wp plugin list | grep future-island
```

`--force` upgrades an existing install in place; tables are migrated additively
(dbDelta only adds — no destructive migrations exist in this build).

## 4. Core validation (read-only)

Run in order; capture each command's full output to a log file:

```bash
wp ves verify-schema
wp ves validate-staging --format=json
wp ves readiness-check --format=json
wp ves rc-readiness-check --format=json
wp ves memory-summary --format=json
wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json
wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json
wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json
wp ves operator-queue --workspace=1 --format=json
wp ves memory-expire --dry-run
```

Expected: `rc-readiness-check` returns `ready_for_staging` or
`ready_with_warnings`, with `live_validation.status = "unrun"` until step 7.
`blocked` means stop and fix before continuing.

## 5. Trend engine validation (optional, read-only)

```bash
wp ves trend-verify-staging
wp ves trend-evaluate
wp ves trend-summary
wp ves trend-obs-backfill --dry-run --limit=100
```

## 6. Browser validation

Follow `LIVE-STAGING-VALIDATION-CHECKLIST.md` and capture every screenshot it
lists (Signal Room, Signal Report, Evidence Gate, Operator Queue, Memory/Brand
Context, Generation Context Preview, Prompt Package Preview, Brief Workbench,
Draft Workbench, Release Candidate page, any error state), plus the browser
console and the last ~300 sanitized lines of the PHP error log.

## 7. Record the result (only after a real pass)

Only after **every** checklist item passes:

```bash
wp option update ves_rc_live_validation \
  '{"status":"passed","recorded_at":"YYYY-MM-DD HH:MM:SS","note":"<operator> staging signoff"}' \
  --format=json
```

Re-run `wp ves rc-readiness-check --format=json` — `live_validation.status`
becomes `passed`. `production_ready` stays `false` by design: production release
additionally requires operator approval and a monitored pilot.

If validation failed, do **not** write the option. File the failures instead.

## 8. Rollback

```bash
wp plugin deactivate future-island-intelligence-suite
wp db import staging-backup-<timestamp>.sql   # only if data must be restored
```

Deactivation alone never deletes data. `uninstall.php` (full delete) is the only
destructive path and only runs on explicit plugin deletion.

## 9. Feature flags that must stay OFF

| Flag | Why |
| --- | --- |
| `ves_generation_execution_enabled` (option) | AI provider execution. v0.1 ships prompt-package previews only. |
| `VES_PRODUCTION_MVP` (constant) | Production MVP gating. |
| `VES_ENABLE_DEEP_VIDEO_ANALYSIS` (constant) | Deep video analysis (core). |
| `FI_DTF_ENABLE_DEEP_VIDEO` (constant) | Deep video analysis (DTF module). |

The Release Candidate admin page (Intelligence Suite → Release Candidate) shows
all four; any ON state is surfaced as a warning.
