# Rollback Notes — Staging Browser Validation Sprint

## Scope of changes

This sprint is mostly additive and correction-focused:

- warning fixes;
- test hardening;
- onboarding admin-post/form integration;
- Browser Validation admin screen;
- fixture runner defaults;
- evidence pack generator;
- CSS/snippet/report updates.

## Rollback approach

1. Revert the patched ZIP to `future-island-orchestration-agnostic-staging-trial-patched.zip`.
2. If only the UI integration misbehaves, revert:
   - `includes/class-ves-workspace-onboarding-service.php`
   - `includes/class-ves-provider-admin-page.php`
   - `includes/class-ves-operator-qa-service.php`
   - `includes/modules/command-room/class-fi-command-room-renderer.php`
   - `assets/css/fiis-ui-system.css`
3. If only warning cleanup needs rollback, revert:
   - `modules/deep-trend-finder/includes/class-fidtf-normalizer.php`
   - affected warning tests.

## Data risk

No destructive schema changes were added. Existing tables and records are preserved.

## Operational risk

The main risk is browser/admin UI regression, not data loss. Validate on staging before pilot invitation.
