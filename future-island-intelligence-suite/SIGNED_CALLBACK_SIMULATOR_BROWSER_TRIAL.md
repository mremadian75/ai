# Signed Callback Simulator Browser Trial

## Purpose

The simulator is the required staging-safe way to test signed provider callbacks before connecting any external worker.

## Dry-run command

```bash
FI_PROVIDER_SECRET='replace-in-shell-only' php bin/fi-provider-callback-simulate.php \
  --endpoint='https://staging.example.com/wp-json/fi/v1/provider/social/ingest' \
  --fixture='tests/fixtures/provider/social-valid-topic.json' \
  --provider-key='approved_social_provider' \
  --secret-env='FI_PROVIDER_SECRET' \
  --run-id=123 \
  --dry-run
```

## Browser validation steps

1. Create or select a canonical Run in Command Room.
2. Configure a provider secret in Provider Contracts.
3. Run the dry-run command and inspect the redacted curl preview.
4. Run send mode only against staging.
5. Confirm Provider Ingestions ledger records accepted/rejected rows.
6. Confirm Run Timeline shows provider/auth/validation/normalization stages.
7. Confirm rejected/private rows are not promoted into evidence.

## Security gates

- Secret value is never printed.
- Signature is redacted in dry-run output.
- Raw callback body is not shown in browser UI.
- Replay/idempotency checks must block duplicate callbacks.
- Bad signature and stale timestamp tests must fail closed.
