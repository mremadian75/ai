# Future Island Intelligence Suite — Deep-Review Pass 4

Snapshot of the reviewed/debugged plugin build, committed for durable persistence
(prior work lived only in an ephemeral sandbox and was repeatedly lost to resets).

- **Build version:** 1.2.4
- **Source ZIP SHA-256:** `16260ffabfb6dd3926b996e6c33fe45941383570ff58351b43d944c3b2ab0f1f`
- **Status:** NOT production-ready. Live staging validation is **UNRUN** — all
  verification below is static lint + the self-contained PHP test harness, not a
  live WordPress/provider run.

## Verification (this snapshot)

- PHP lint: **363/363** files syntax-clean (`bin/lint-php.sh --all`)
- Tests: **170/170** pass (`bin/test-all.sh`)
- PHP 7.4 compatibility audit: **4/4** pass
- Cost-spine: **50/50** · Evidence validator: **16/16**

## Fixes locked in this pass

| # | Area | Severity | Fix |
|---|------|----------|-----|
| 1 | `class-ves-ajax-controller.php` · `google_analyze()` | HIGH (billing + memory trust) | A local OpenAI fallback is not a provider completion. Now **voids** the credit reservation instead of charging, and **skips** the context-builder writeback + temp-memory save — mirroring `analyze_item()`. Previously it charged and wrote degraded results into trusted memory. |
| 2 | `class-ves-google-intel.php` | HIGH | Removed `token` from the Apify URL query (root cause of the double-auth 403 / FI-600000 and a secret leak into access logs; `VES_Apify_Client` already injects `Authorization: Bearer`). |
| 3 | `class-ves-openai-client.php` | LOW | Scrub `$upstream_message` before it can surface in diagnostics. |
| 4 | `class-ves-insight-evidence-validator.php` | HIGH | Closed the evidence-first loophole: zero/missing-confidence evidence no longer counts as "strong"; a bare term match no longer supports a claim without credible confidence; supported claims require a concrete evidence id. |
| 5 | `class-ves-ai-usage-tracker.php` | HIGH | Cost honesty: approximate/fallback pricing is reported as **estimated**, never **actual** (`actual` only when `pricing_source === 'known'`). |
| 6 | `class-ves-stripe-billing.php` | MEDIUM | Admin render no longer echoes the secret key / webhook secret; save only overwrites on a non-empty submission. |
| 7 | `class-ves-projects.php` | HIGH (IDOR) | Removed the `user_id === 0` access exception that let any logged-in user reach owner-less projects. |
| 8 | `class-ves-intelligence-store.php` | LOW | Boundary-safe `trend_record_id` LIKE match (fixes 12-vs-123 prefix collision). |

## New regression tests added this pass

- `tests/test-ves-insight-evidence-validator.php` — 5 GATE assertions (zero/missing
  confidence not strong; no-id cannot support; term-only match needs credible confidence).
- `tests/test-ves-cost-spine.php` — A1b: approximate-priced completed call reports
  estimated cost, not actual.
- `tests/test-ves-google-analyze-fallback-billing.php` — source-level guard that
  `google_analyze()` voids + skips memory on fallback (all 3 AI paths parity-checked).

## Not in this snapshot

Phase 6B–8A feature layers (Signal Room, Workbench, operator queue, evidence binder,
review-state, generation prompt-package builder / context resolver, related CLI +
admin pages + assets) were built in earlier sessions but lost to sandbox resets and
are **not** reconstructed here. This snapshot is the **core reviewed/debugged build**.
