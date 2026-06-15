# Provider Fixture Staging Trial Report

## Fixtures covered

- `social-valid-topic.json`
- `social-invalid-private.json`
- `social-mixed-batch.json`
- `aeo-valid-gap.json`
- `aeo-missing-answer.json`
- `creative-valid-analysis.json`
- `creative-invalid-token-leak.json`

## Runner change

`bin/run-provider-fixture-trials.sh` now uses provider-family-specific defaults:

- Social: `approved_social_provider`
- AEO: `approved_aeo_capture`
- Creative: `manual_creative_analysis`

Operators can still override provider keys with environment variables.

## Verification

The fixture runner completed in dry-run mode and wrote `provider-fixture-trial.log` with redacted signatures and no raw secret exposure.

## Staging expectation

On a real WordPress staging site, valid fixture rows should enter the provider ingestion ledger and map to canonical records only after WordPress validation. Invalid/private fixtures should be rejected and logged, not promoted to evidence.
