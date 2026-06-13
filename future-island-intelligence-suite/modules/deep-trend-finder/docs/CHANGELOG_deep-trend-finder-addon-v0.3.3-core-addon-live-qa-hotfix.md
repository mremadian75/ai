# Deep Trend Finder Add-on v0.3.3 — Core/Add-on Live QA Hotfix

## Fixed
- Fixed auto-created page handling when `wp_insert_post(..., true)` returns `WP_Error`.
- Prevented failed page creation from storing a bogus numeric `fidtf_page_id`.
- Preserved the existing user-edited-page behavior: no overwrite unless the admin explicitly uses recreate/update.

## Scope
- No new sources.
- No Instagram/Reddit/Google live bridge.
- No deep video/audio/transcript implementation.
