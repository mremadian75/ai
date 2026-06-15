# Migration Notes — Staging Browser Validation

## Database changes

No new database table or destructive migration was introduced in this sprint.

## Existing migrations relied on

The sprint relies on existing additive tables from previous sprints:

- canonical runs;
- workspaces and workspace members;
- run logs;
- provider ingestions;
- usage events;
- review decisions;
- decision edges;
- outcomes;
- pattern observations.

## Activation behavior

The onboarding UI now registers an admin-post handler during plugin init. This does not create a table by itself.

## Rollback

Rollback is file-level only unless reverting to a previous packaged ZIP. No database rollback is expected.
