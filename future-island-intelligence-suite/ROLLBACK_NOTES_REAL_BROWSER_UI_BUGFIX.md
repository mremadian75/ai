# Rollback Notes — Real Browser UI Bugfix

## Rollback scope

This sprint is additive and UI-focused. It does not introduce migrations, new external dependencies, new provider connections, new background jobs, or n8n requirements.

## Files to revert for full rollback

- `assets/css/ves-frontend.css`
- `assets/css/fiis-ui-system.css`
- `assets/css/fiis-intelligence-map.css`
- `assets/js/fiis-signal-productization.js`
- `assets/js/fiis-intelligence-map.js`
- `includes/class-ves-intelligence-map.php`
- `includes/class-ves-access-control-admin.php`
- `includes/class-ves-provider-admin-page.php`
- `tests/test-fiis-signal-productization.js`
- `tests/test-v0932-real-browser-ui-bugfix.php`
- `ui-snippets/real-browser-ui-bugfix/`

## Partial rollback options

- To revert only Social/metric card layout fixes, revert the appended v0.9.32 block in `assets/css/ves-frontend.css`.
- To revert only Object Flow/disabled reason styling, revert the appended v0.9.32 block in `assets/css/fiis-ui-system.css`.
- To revert only Intelligence Map lineage, revert `includes/class-ves-intelligence-map.php`, `assets/js/fiis-intelligence-map.js`, and `assets/css/fiis-intelligence-map.css`.
- To revert only admin copy/label cleanup, revert `includes/class-ves-access-control-admin.php` and `includes/class-ves-provider-admin-page.php`.

## Data safety

No data migration or destructive storage change was made. Rollback should not require database changes.
