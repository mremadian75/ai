# Private Pilot Security Re-check

Performed against the v0.9.34 build, combining (a) a live-WordPress runtime scan of every
rendered admin page and (b) a static code audit of the security-sensitive surfaces.

## Checks performed

- Rendered-DOM scan of 25 admin screens + the decision-report render for token/secret/
  signature/bearer markers (regex over full HTML, not just visible text).
- Static audit of REST route registration, admin-post/AJAX nonce usage, capability checks,
  redaction helpers, and public-share-link surfaces.
- Confirmation that the Stripe webhook (the only public REST endpoint) verifies its
  signature before acting.

## Secrets/tokens

- **No** OpenAI key, Apify token, bearer token, API key, cookie, or raw private payload was
  found in any rendered admin page (0 matches across all captures).
- Provider Settings shows token **status** only ("configured"/"missing"/"optional") and
  states "Token values are never rendered." Confirmed in the live render.
- No hardcoded secrets in shipped CSS/JS UI assets.

## HMAC / signatures

- The Run Timeline shows "signature redacted"; the Provider Ingestions Ledger states
  "Raw payloads and tokens are not displayed."
- `VES_Provider_Callback_Auth_Service` computes HMAC signatures server-side and never
  echoes them to a UI surface.

## Raw payloads

- Provider payload previews go through `redact_sensitive_payload_for_preview` /
  `redact_sensitive_text` (100+ redaction call sites). No raw callback body is rendered.

## Permissions / nonces / REST

- **27/27** `register_rest_route` calls declare a `permission_callback`.
- The single `__return_true` route is the **Stripe webhook**, which is authenticated by
  signature: `rest_webhook()` → `verify_signature()` using `hash_equals()` (constant-time),
  returning early on mismatch. This is the correct pattern for a payment webhook.
- 107 nonce checks (`check_admin_referer` / `wp_verify_nonce` / `check_ajax_referer`).
- 261 `current_user_can` capability checks; admin pages register under `manage_options`.

## Redaction

- Dedicated helpers: `redact_sensitive_text`, `redact_sensitive_payload_for_preview`,
  `redact_string`, `redact_array`, plus `redaction_required` gating. Decision Report
  `render_html` output scanned: no secret markers.

## Issues found

| Severity | Issue | Fix | Status |
|---|---|---|---|
| — | No secret/token/signature/payload exposure found | — | No action needed |
| Info | One public REST route (`__return_true`) | Verified it is the signature-checked Stripe webhook | Accepted (correct pattern) |

## Remaining risks

- Redaction was validated with fixture/seeded data. Re-confirm with **real** provider rows
  on the customer staging host before widening the pilot.
- Capability model assumes `manage_options` operators in the pilot. If non-admin operator
  roles are introduced later, re-audit per-page capabilities.

## Production blockers

- None identified in this re-check. (Production readiness is out of scope for this sprint
  and is not claimed; this assesses controlled-private-pilot security only.)
