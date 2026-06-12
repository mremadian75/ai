# v0.3.51 — Live entity-collision and Spain-fit hardening

Live QA found that the token `mahou` can match non-brand contexts such as anime/magic (`Mahou Shoujo`, anime songs, otaku content) and foreign event contexts while the run is intended for Mahou beer in Spain.

## Fixed
- Added Mahou entity disambiguation gate for social sources.
- Added beer/Madrid/San Isidro/product context requirement before a bare `mahou` match can become brand evidence.
- Capped anime/magic Mahou collisions as hidden noise.
- Added text-level Spain-fit drift gate for foreign social context when market is Spain.
- Updated report schema to `fidtf.report.v3.8`.
- Added regression coverage for anime false positives, Ecuador/Mahou Shoujo false positives, and valid Mahou San Isidro Madrid evidence.

## No schema change
No database schema change.
