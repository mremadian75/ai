# Rollback Notes — Live WordPress Staging Validation (v0.9.34)

This sprint made two small, isolated code fixes plus tests/docs/evidence. Everything is
reversible without touching schema, contracts, or dependencies.

## Code changes (revert these to roll back behavior)

1. `includes/class-ves-admin.php`
   - Added method `render_trend_source_slots_field()` (the callback that
     `add_settings_field('trend_source_slots_table', …)` already referenced).
   - Rollback effect: reverting re-introduces the Settings-page fatal on PHP 8.4. Prefer
     keeping the fix; if you must revert, also remove or guard the `add_settings_field`
     call at line ~398 so the Settings page does not fatal.

2. `includes/class-ves-provider-callback-auth-service.php`
   - `int $timestamp = null` → `?int $timestamp = null` (line ~94). Pure type-hint change;
     behavior identical. Safe to revert, but reverting re-adds a PHP 8.4 deprecation.

## Non-code additions (safe to delete with no runtime effect)

- `tests/test-v0934-live-staging-validation.php`
- `evidence/live-wordpress-screenshots/` (screenshots + `_live-findings.json`)
- All new `*.md` reports listed in CONTROLLED_PRIVATE_PILOT_FINAL_REPORT.md §15.

## Not changed (explicitly)

- No database schema or migration changed (the 60 tables are created by the existing,
  unchanged activation path).
- No REST route, capability check, nonce, or dependency changed.
- No CSS/JS behavior changed in this sprint (the v0.9.33 CSS fixes are unchanged).

## Verify after rollback

```bash
bin/lint-php.sh --core --timeout-per-file=10
bin/test-all.sh -q
```
