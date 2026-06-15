# Baseline Package Inspection

Sprint: Live WordPress Staging Validation + Controlled Private Pilot Candidate.
Base build inspected: `future-island-real-browser-revalidation` (v0.9.33).

## Artifacts found

| Artifact | Found | Notes |
|---|---:|---|
| future-island-real-browser-revalidation-patched.zip | Yes | 3.9M, 865 entries; installable plugin |
| future-island-real-browser-revalidation-reports.zip | Yes | 23K, 11 report docs |
| real-browser-revalidation-evidence-pack.zip | Yes | 1.3M, 58 entries; harness + measurements + 38 screenshots |
| SHA256SUMS.txt | Yes | Covers the three ZIPs above |
| future_island_real_browser_revalidation_second_review.md | No | Not provided in this session's uploads; proceeded from the patched ZIP + reports as instructed |

## Hash verification

`sha256sum -c SHA256SUMS.txt` → **all OK**.

| File | SHA256 (first 16) | Match |
|---|---|---:|
| future-island-real-browser-revalidation-patched.zip | `4febd9f05311136c` | OK |
| future-island-real-browser-revalidation-reports.zip | `c9e1de3b34c741c7` | OK |
| real-browser-revalidation-evidence-pack.zip | `0f64fc30d7d06486` | OK |

## Plugin structure

- Main file: `future-island-intelligence-suite.php` (plugin header version 1.4.0).
- 523 PHP files; 250 linted core files; 246 tests at baseline (247 after this sprint).
- 10 CSS files; admin renderers under `includes/` and `includes/modules/`.
- Canonical schema: `includes/class-ves-intelligence-store.php`, run/usage/memory stores.
- Security-sensitive surfaces inspected: `class-ves-canonical-rest-controller.php`,
  `class-ves-provider-callback-auth-service.php`, `class-ves-stripe-billing.php`,
  `class-ves-provider-admin-page.php`, `class-ves-operator-qa-service.php`,
  `class-ves-access-control-admin.php`.
- Evidence/harness from prior sprint: `ui-snippets/real-browser-revalidation/`.

## Existing reports reviewed

- REAL_BROWSER_REVALIDATION_REPORT.md — prior sprint method + findings.
- PRIVATE_PILOT_ACCEPTANCE_REPORT.md / READINESS_CHECKLIST / OPERATOR_GUIDE — prior pilot docs.
- REAL_BROWSER_UI_BUGFIX_REPORT.md, BROWSER_SCREENSHOT_FINDINGS.md, UI_REGRESSION_CHECKLIST.md,
  CHANGED_FILES_SUMMARY.md — UI bugfix lineage.

## Initial risk notes (entering this sprint)

- Prior validation was browser-engine rendering of snippets, **not** a live WordPress
  install. The activation path, migrations, settings registration, and admin chrome had
  not been exercised inside WordPress. (This sprint closes that gap — see
  `LIVE_WORDPRESS_STAGING_VALIDATION_REPORT.md`.)
- PHP 8.4 runtime deprecations and missing settings-field callbacks are not catchable by
  `php -l` or the unit spine; only a live admin load surfaces them. (Two such issues were
  found and fixed this sprint.)
