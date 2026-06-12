# Deep Trend Finder Add-on v0.3.26

## Goal
Improve the post-success product experience after TikTok live collection started working: reduce weak/generic evidence, make the report usable as a professional marketing-intelligence artifact, and harden the non-TikTok live source bridges without pretending every actor schema has been live validated.

## Changes

### Analysis quality
- Split relevance scoring into core user intent vs planner/source expansion keywords.
- Added stricter generic-category handling so broad terms such as beverage/drink/recipe do not flood brand-specific reports.
- Added matched core/expansion keyword audit fields.
- Removed duplicate “Score … Score …” reason phrasing.
- Replaced stale evidence rows on successful provider reprocessing so older broad scores do not linger after a quality-gate upgrade.

### Report quality
- Added evidence intelligence fields: direct/adjacent/weak signal mix, top sources, hook families, and evidence counts.
- Added creative territories, risk notes, and source confidence.
- Added source-confidence aware executive summary, professional insights, strategic readout, and content angle briefs.
- Invalidates cached reports created by older add-on versions.

### UI/UX
- Full report no longer renders cramped inside the right report shell. The report shell now stays as a compact preview and the full report renders in a full-width output area.
- Added Evidence map, Hook mechanics, Creative territories, and Strategic risk sections.
- Improved evidence-card text layout and score display.
- Fixed duplicate source/channel cards by centralizing source list deduplication.

### Multi-source live hardening
- Added actor input profiles for Instagram, Reddit, Google Trends, Google News, and generic query actors.
- Added `fidtf_generic_apify_actor_input` filter so site/Core-specific code can adapt actor inputs to strict Apify schemas without editing the add-on.
- Persisted and exposed `actor_input_profile` in diagnostics.
- Exposed sanitized non-TikTok actor inputs to admin diagnostics.

## Important limitation
TikTok has been validated against a real Apify dataset shape. The other live bridges are now structurally active and diagnosable, but each configured Apify actor still needs one live QA pass because Apify actors do not share one universal input schema.
