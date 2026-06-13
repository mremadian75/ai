# Deep Trend Finder Add-on v0.3.46

## Marketing decision readiness + evidence hygiene

This release turns the professional synthesis output into a stronger marketing-direction report without changing the shortcode, database schema, or provider architecture.

### Added
- Marketing Decision Summary in the report output and rendered HTML.
- Confidence Breakdown separated into source coverage, relevance confidence, market-demand confidence, creative-mechanics confidence, brand-fit confidence, and client readiness.
- Source Role Matrix that explains what each source can and cannot support.
- Research Mode field in the frontend form and request metadata.
- Evidence tiers: `strong`, `support`, `creative_only`, `weak`, and `noise`.
- Marketing allowed-use flags and risk notes for evidence rows.
- REST propagation of `evidence_tier`, `marketing_allowed_use`, `client_safe`, and `risk_note`.
- Frontend evidence grouping by marketing-readiness tier instead of raw direct/adjacent only.
- v0.3.46 regression check for the new report and evidence-readiness wiring.

### Improved
- Repeated language and hashtag extraction now removes technical artifacts such as `amp`, `https`, `www`, `com`, and platform noise hashtags such as `#fyp`, `#viral`, and `#foryou` from strategic summaries.
- Brand-focused runs no longer treat broad category terms as direct proof by default.
- Strategic readout now avoids counting weak or empty source families as proof.
- Evidence quality summary now shows decision-ready, creative-only, weak, and noise row counts.
- Validation plan now pushes competitor/category benchmark and demand/search confirmation before client-facing claims.

### Preserved
- Existing shortcode.
- Existing database schema.
- Existing provider bridge architecture.
- Existing live/planned-only transparency.
