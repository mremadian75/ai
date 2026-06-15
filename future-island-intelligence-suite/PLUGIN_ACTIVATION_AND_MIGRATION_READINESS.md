# Plugin Activation and Migration Readiness

## Activation readiness

The plugin remains a WordPress-first product shell. This sprint did not introduce new runtime dependencies or a new frontend build system.

Activation validation route for staging:

1. Install `future-island-staging-browser-validation-patched.zip` on WordPress staging.
2. Activate the plugin.
3. Confirm PHP error/debug logs do not show warnings from activation.
4. Open Future Island → Command Room.
5. Open Future Island → Browser Validation.
6. Confirm Provider Contracts, Provider Ingestions, Beta QA, and Browser Validation pages load.

## Migration readiness

This sprint added no destructive migration. Existing canonical tables and prior additive migrations remain the storage path.

Expected table families from previous sprints:

- workspaces and workspace members;
- canonical runs;
- decision edges;
- review decisions;
- memory records;
- usage events;
- outcomes;
- pattern observations;
- run logs;
- provider ingestions.

## No direct external writes

External workers, simulators, optional orchestrators, and optional n8n examples must never write directly to canonical tables. They return rows through authenticated WordPress REST callbacks only.

## Roll-forward safety

The changes are UI/service/report/test additions and warning fixes. Roll-forward risk is low compared with schema-changing work.
