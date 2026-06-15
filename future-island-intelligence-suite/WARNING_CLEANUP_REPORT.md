# Warning Cleanup Report

## Reproduced warnings before patch

### 1. Undefined `$provider_malformed_empty_item`

```text
modules/deep-trend-finder/includes/class-fidtf-normalizer.php:313
```

Cause: `extract_transcript()` returned a `provider_malformed_empty_item` field but that local variable exists in `evidence_quality()`, not in transcript extraction.

Fix: set transcript metadata to an explicit `false` value. The canonical malformed-row flag remains computed and returned by `evidence_quality()`.

### 2. Missing Deep Trend Finder README

```text
modules/deep-trend-finder/tests/test-fidtf-v0340-full-live-openai-json-schema-and-key-safety.php
modules/deep-trend-finder/tests/test-fidtf-v0341-cross-platform-data-scientist-and-live-gpt-safety.php
modules/deep-trend-finder/tests/test-fidtf-v0342-cross-platform-statistical-scoring-and-gpt-analysis-contract.php
```

Cause: tests attempted to `file_get_contents()` a per-module README that is not shipped in the unified plugin package.

Fix: use `is_readable()` and fall back to an empty string. The README assertions had already been archived, so this avoids a warning without changing product behavior.

### 3. Undefined `$source` in error-category test

```text
tests/test-v093017-error-category-ordering.php:18
```

Cause: a double-quoted test string interpolated `$source` while it was meant to inspect the literal code string.

Fix: escaped the literal `$source` reference.

## Post-fix targeted verification

The following targeted tests passed without warnings:

```text
modules/deep-trend-finder/tests/test-fidtf-v0350-live-instagram-malformed-provider-row-gates.php
modules/deep-trend-finder/tests/test-fidtf-v0340-full-live-openai-json-schema-and-key-safety.php
modules/deep-trend-finder/tests/test-fidtf-v0341-cross-platform-data-scientist-and-live-gpt-safety.php
modules/deep-trend-finder/tests/test-fidtf-v0342-cross-platform-statistical-scoring-and-gpt-analysis-contract.php
tests/test-v093017-error-category-ordering.php
tests/test-v091-orchestration-agnostic-correction.php
tests/test-v091-ui-ux-private-beta-upgrade.php
```

## Policy

No warnings were suppressed blindly. Each warning was addressed at its source.
