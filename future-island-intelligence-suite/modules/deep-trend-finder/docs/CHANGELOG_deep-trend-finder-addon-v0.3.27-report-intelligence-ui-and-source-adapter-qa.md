# v0.3.27 — Report intelligence, evidence UI grouping, and adapter QA

Focused hardening patch after successful TikTok live execution.

## Fixed / improved

- Upgraded report schema to `fidtf.report.v3.2`.
- Report now analyzes up to 200 stored evidence rows internally instead of truncating synthesis at 100.
- Added request-focus extraction from run context and request metadata.
- Added evidence-fit diagnosis so the system separates:
  - brand-direct evidence
  - category/context evidence
  - adjacent creative inspiration
  - weak/background signals
- Added signal clusters with representative examples.
- Added briefing recommendations that explicitly warn when brand-direct evidence is thin.
- Frontend evidence grid now groups evidence into Direct, Adjacent, and Weak/background groups.
- Long scoring rationale is now collapsed under “Why this evidence is shown”.
- REST item payload now exposes matched core/expansion keywords for audit/debug.
- Added CSS for evidence fit cards, signal clusters, and grouped evidence sections.

## Still true

- TikTok live path has been validated with the user’s real Apify run shape.
- Instagram, Reddit, Google Trends, and Google News bridges are architecturally active but still require live actor-by-actor QA because Apify actor schemas vary.
- This patch does not turn the product into a direct publishing tool.
