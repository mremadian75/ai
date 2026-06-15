# Optional Future Island n8n Provider Callback Example

Optional n8n example. Future Island does not require n8n. This template is one optional staging/private-beta route for sending provider rows to Future Island through authenticated WordPress REST endpoints. Approved callbacks can also come from the internal simulator, a custom worker, a cron job, an Apify actor wrapper, Make/Zapier-style workflow, or a future managed Future Island worker. External tools must never write directly to canonical Future Island tables.

## Secret storage

When this optional n8n route is chosen, store the provider secret in an n8n environment variable or credential such as:

```text
FI_PROVIDER_SECRET
```

Do not paste real secrets into workflow notes, node names, URLs, logs, screenshots, or report exports.

## HMAC policy

Future Island expects these headers:

```text
X-FI-Provider-Key
X-FI-Timestamp
X-FI-Signature
X-FI-Idempotency-Key
```

The signature base string is:

```text
timestamp + "." + raw_body
```

The HMAC algorithm is SHA-256. Send the header as:

```text
sha256=<computed_hex_digest>
```

## Raw body rules

The exact same raw JSON body used for the HTTP request must be used to build the signature. Do not sign a reformatted body and then send a different body.

Minimum body:

```json
{
  "run_id": 123,
  "provider_key": "approved_social_provider",
  "provider_family": "social_signal",
  "provider_run_id": "optional-n8n-run-123",
  "rows": []
}
```

## Idempotency key

Generate one unique idempotency key per callback attempt:

```text
optional-n8n-fi-<timestamp-or-run-id>
```

Reusing the same key is treated as replay and should be blocked.

## Endpoint examples

```text
/wp-json/fi/v1/provider/social/ingest
/wp-json/fi/v1/provider/aeo/ingest
/wp-json/fi/v1/provider/creative/ingest
```

## Test scenarios

Run these before real provider trials:

1. valid social callback;
2. bad signature;
3. stale timestamp;
4. replayed idempotency key;
5. invalid/private row;
6. mixed batch with accepted and rejected rows.

## Do not do this

- Do not put provider tokens in URL query parameters.
- Do not log raw callback bodies when they may contain secrets.
- Do not expose HMAC secrets or signatures in screenshots or exports.
- Do not scrape private/logged-in content.
- Do not let n8n or any external tool write directly to WordPress database tables.
- Do not promote invalid provider rows into evidence.

Use `bin/fi-provider-callback-simulate.php` for local dry-runs and `bin/run-provider-fixture-trials.sh` for a full fixture sweep.

## Product boundary

Optional n8n example. Future Island does not require n8n. WordPress remains the canonical product shell, permission layer, review UI, and record layer. External tools return results only through authenticated WordPress endpoints.
