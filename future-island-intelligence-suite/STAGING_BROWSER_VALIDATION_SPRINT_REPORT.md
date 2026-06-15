# Future Island Staging Browser Validation + Product UI Integration + Warning Cleanup + Pilot Readiness Sprint

## Outcome

This sprint moves the orchestration-agnostic correction build toward real WordPress staging browser validation. It does not add a new product module. It cleans known warnings, improves verification and fixture simulation, integrates the staging validation route into WordPress admin, and packages operator-facing evidence for a private-beta pilot.

## Product boundary preserved

Future Island remains:

- evidence-first;
- generation-second;
- review-led;
- workspace-scoped;
- usage-aware;
- orchestration-agnostic;
- private-beta controlled.

n8n remains an optional example only. The required staging path is:

```text
approved worker / simulator / optional orchestrator
-> signed WordPress REST callback
-> provider contract validation
-> canonical Run
-> Run Timeline
-> Signal / Evidence
-> Insight
-> Brief
-> Draft
-> Memory
-> Usage
-> Outcome
-> Pattern
-> Decision Report
```

## Implemented changes

### Warning cleanup

- Fixed `provider_malformed_empty_item` undefined-variable warning in `FIDTF_Normalizer::extract_transcript()` by using an explicit `false` value for transcript metadata while preserving the evidence-quality malformed-row flag.
- Guarded optional Deep Trend Finder README reads in v0.3.40-v0.3.42 tests with `is_readable()`.
- Fixed literal `$source` inspection in `test-v093017-error-category-ordering.php` to avoid test-time interpolation warnings.

### Product UI integration

- Added admin-post registration for `VES_Workspace_Onboarding_Service`.
- Added a real editable onboarding panel in Command Room with fields for brand, market, provider mode, usage mode, review default, and optional first-run creation.
- Added Command Room notices for onboarding save success/failure.
- Added a Browser Validation admin screen under the Command Room menu.
- Added Browser Validation links from Command Room provider controls.
- Added CSS for the integrated onboarding editor and Browser Validation route.
- Updated HTML snippets for Command Room, onboarding, ledger, timeline, Decision Map, Decision Report, Operator QA, and staging browser validation.

### Staging support

- Added `bin/build-staging-browser-validation-evidence-pack.sh`.
- Improved `bin/run-provider-fixture-trials.sh` so AEO and Creative fixtures use family-specific default provider keys:
  - `approved_aeo_capture`
  - `manual_creative_analysis`
- Ran the signed callback simulator dry-run and fixture-trial dry-run. No secret value or full signature was printed.

### Tests

- Added `tests/test-v092-staging-browser-validation-readiness.php`.
- Re-ran warning-targeted tests.
- Re-ran broad lint and both test runners.

## Files changed at code level

- `includes/class-ves-plugin.php`
- `includes/class-ves-workspace-onboarding-service.php`
- `includes/class-ves-provider-admin-page.php`
- `includes/class-ves-operator-qa-service.php`
- `includes/modules/command-room/class-fi-command-room-renderer.php`
- `modules/deep-trend-finder/includes/class-fidtf-normalizer.php`
- `modules/deep-trend-finder/tests/test-fidtf-v0340-full-live-openai-json-schema-and-key-safety.php`
- `modules/deep-trend-finder/tests/test-fidtf-v0341-cross-platform-data-scientist-and-live-gpt-safety.php`
- `modules/deep-trend-finder/tests/test-fidtf-v0342-cross-platform-statistical-scoring-and-gpt-analysis-contract.php`
- `tests/test-v093017-error-category-ordering.php`
- `tests/test-v092-staging-browser-validation-readiness.php`
- `bin/run-provider-fixture-trials.sh`
- `bin/build-staging-browser-validation-evidence-pack.sh`
- `assets/css/fiis-ui-system.css`
- `ui-snippets/*.html`

## Verification summary

See `TEST_LINT_OUTPUT_STAGING_BROWSER_VALIDATION.txt` for full command output.

## Remaining gate

A real browser session and a real WordPress staging install were not available in the sandbox. The build is prepared for that validation, and the required browser/operator checklist is included.
