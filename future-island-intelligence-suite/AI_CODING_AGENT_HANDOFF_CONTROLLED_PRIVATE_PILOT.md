# AI Coding Agent Handoff — Controlled Private Pilot

## Current state

The plugin was installed and activated in a **real local WordPress 7.0 / PHP 8.4 admin**,
migrations created 60 canonical tables, the thin loop was seeded end to end, and all 25
admin screens render with no plugin-caused PHP errors and no secret leakage. Two issues
that only a live install surfaces were found and fixed (Settings-page fatal; a PHP 8.4
deprecation). Build tag: **v0.9.34**.

## Product constraints (still hold — do not break)

- Evidence-first, generation-second, review-led, usage-aware.
- WordPress is the canonical record layer; external workers/n8n are optional.
- Memory is context, not evidence.
- No public share links; provider tokens/secrets/signatures/raw payloads never rendered.
- No real provider connection, no direct publishing, no multi-LLM orchestration.

## What changed this sprint

- `includes/class-ves-admin.php`: implemented `render_trend_source_slots_field()` (was a
  missing settings-field callback → fatal). Matches the existing sanitize contract
  (`{slot}_slug`, `_enabled`, `_cost_level`, `_reliability_note`; `_last_status` read-only).
- `includes/class-ves-provider-callback-auth-service.php`: `?int $timestamp = null`.
- `tests/test-v0934-live-staging-validation.php`: locks both fixes (10 checks).
- `evidence/live-wordpress-screenshots/`: live wp-admin screenshots + `_live-findings.json`.

## How to reproduce the live environment

```bash
# WP-CLI + WordPress on SQLite (no MySQL needed)
php wp-cli.phar core download --path=wp
cp wp/wp-content/plugins/sqlite-database-integration/db.copy wp/wp-content/db.php  # fill placeholders
wp config create --skip-check ; wp core install --url=http://127.0.0.1:8089 ...
wp plugin activate future-island-intelligence-suite
php -S 127.0.0.1:8089 -t wp <router.php>
# then drive wp-admin with Playwright/Chromium (see /tmp/wp/shoot.mjs pattern)
```

## Risks left for the next agent

- Only validated on SQLite + default theme + Chromium. Do one pass on the customer's
  MySQL staging host with their theme.
- WordPress **core** emits a `strip_tags(null)` deprecation on PHP 8.4 (admin-header.php) —
  not the plugin; ignore or suppress at the environment level.
- Provider Ingestions Ledger renders empty because the thin loop seeded via the
  intelligence store, not the provider-ingestion path. Seed a provider ingestion (signed
  callback simulator) if you want to demo the ledger with rows + status chips.
- Do not start a feature sprint. Keep the loop thin.
