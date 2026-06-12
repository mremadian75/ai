# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.15

**Slug:** `0.3.15-tiktok-live-skip-diagnostic-fix`

## Focus
Fix the remaining TikTok live state mismatch where a live-intended TikTok run could render as `skipped` with the misleading message `Planned only in this release.`

## Changes
- Live-intended TikTok `skipped` outcomes are promoted to `failed` during initial dispatch when live TikTok dispatch is enabled.
- TikTok `skipped` status now has explicit backend and frontend copy.
- Run-level messaging now says TikTok live dispatch was skipped before provider collection and is not planned-only.
- Provider diagnostics remain the source of truth for the exact failure reason.

## Unchanged
- No shortcode change.
- No database schema change.
- No actor schema rewrite.
- No changes to non-TikTok providers.
