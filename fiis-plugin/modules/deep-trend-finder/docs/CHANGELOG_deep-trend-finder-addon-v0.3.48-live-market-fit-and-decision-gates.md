# v0.3.48 — Live market-fit and decision gate hardening

## Why
A live provider smoke test using TikTok search for `mahou cerveza 0,0` showed that provider results can include outside-market, weakly related, or platform-noise rows even when the query is brand/category-specific. The previous build protected against provider-query leakage, but creative-only rows could still influence decision-language too strongly.

## Changes
- Added source-country normalization from `locationCreated`, `locationMeta.countryCode`, `countryCode`, and related fields.
- Added language normalization from `textLanguage`.
- Added source-market gate for social/community sources so rows outside the requested market are capped/hidden from strategic use.
- Added language mismatch cap for social/community rows when a target language is provided.
- Tightened decision-ready rows to `strong` and `support` only; `creative_only` is no longer treated as decision-ready.
- Added `creative_candidate_items()` for fallback inspiration without confusing it with decision evidence.
- Added `demand_decision_ready_rows` so weak demand rows do not inflate market-demand confidence.
- Added per-source decision/creative/weak row counts to the source summary and source role matrix.

## Tests
- Added `test-fidtf-v0348-live-market-fit-and-decision-gates.php`.
- Updated regression version/schema checks to v0.3.48 / report schema v3.5.
