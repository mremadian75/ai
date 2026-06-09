# v0.3.47 — Live evidence readiness debug

Focused QA patch after live Apify smoke tests.

Fixes:
- Treat provider query as provenance only; it no longer inflates top evidence language.
- Filter platform noise such as fyp, viral, trending, views from strategic terms and hashtags.
- Require at least 3 demand/search rows before reporting demand confirmation as directional.
- Split source coverage from decision-ready source coverage in confidence calculations.
- Tighten client-readiness logic so weak/creative-only multi-source data cannot look client-safe.
- Improve validation plan language by comparing request terms against actual evidence language.
