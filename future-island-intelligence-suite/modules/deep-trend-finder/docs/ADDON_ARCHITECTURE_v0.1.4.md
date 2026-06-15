# ADDON_ARCHITECTURE_v0.1.4

Version: `0.1.4-bridge-safety`

## Purpose

v0.1.4 is a bridge-safety release. It does not connect live providers. It hardens the boundary between WordPress, provider bridge hooks, AI research hooks, source-job status, and credit reporting before v0.2.0 starts implementing real bridge logic.

## Confirmed behavior

- Live dispatch is disabled by default.
- When live dispatch is off, no provider dispatch filter runs.
- AI research bridge is disabled by default and separately gated.
- Deep video remains hard-disabled unless `FI_DTF_ENABLE_DEEP_VIDEO` is explicitly true and the setting is enabled.
- Credit reservation remains disabled by default.
- Planned/no-evidence runs report `credit_mode=planning_only` and settle no final credits.

## Provider hook gating

`FIDTF_Provider_Apify::dispatch()` checks `FIDTF_Settings::live_dispatch_enabled()` before calling:

```php
apply_filters('fidtf_dispatch_source_job', null, $context, $source_plan, $request);
```

If live dispatch is off:

```php
WP_Error('live_dispatch_disabled')
```

is returned and no provider filter is executed.

If live dispatch is on but no bridge handles the filter:

```php
WP_Error('provider_bridge_missing')
```

is returned.

## AI research bridge gating

`FIDTF_Provider_AI::dispatch()` checks `FIDTF_Settings::ai_research_bridge_enabled()` before calling:

```php
apply_filters('fidtf_ai_research_items', null, $payload, $source_plan, $request);
```

If the AI bridge is disabled or live dispatch is off:

```php
WP_Error('ai_research_bridge_missing')
```

is returned and no AI research filter is executed.

## Provider filter contract

A provider bridge may return either a canonical dispatch result or immediate item rows.

Canonical queued result:

```json
{
  "ok": true,
  "status": "queued",
  "provider_run_id": "apify-run-id",
  "raw_count": 0,
  "normalized_count": 0,
  "relevant_count": 0,
  "error_code": "",
  "retryable": false,
  "message": "Queued",
  "items": []
}
```

Immediate items result:

```json
{
  "ok": true,
  "status": "completed",
  "provider_run_id": "bridge-run-123",
  "raw_count": 2,
  "items": [
    {
      "id": "post-1",
      "url": "https://example.test/post-1",
      "text": "Observed public trend signal",
      "source_key": "tiktok"
    }
  ]
}
```

Retryable error result:

```json
{
  "ok": false,
  "status": "retryable_failed",
  "provider_run_id": "bridge-run-123",
  "raw_count": 0,
  "normalized_count": 0,
  "relevant_count": 0,
  "error_code": "provider_timeout",
  "retryable": true,
  "message": "Provider timed out before returning items.",
  "items": []
}
```

## Canonical dispatch result

All bridge outputs are normalized by `FIDTF_Source_Job_Service::normalize_dispatch_result()` into:

```php
[
  'ok' => true|false,
  'status' => 'queued|running|completed|failed|retryable_failed|skipped|waiting_for_provider|completed_no_relevant_evidence|planned',
  'provider_run_id' => '',
  'raw_count' => 0,
  'normalized_count' => 0,
  'relevant_count' => 0,
  'error_code' => '',
  'retryable' => false,
  'message' => '',
  'items' => [],
]
```

Unknown statuses are clamped to the source-job allowed status model.

## Allowed statuses

Run statuses are centralized in `FIDTF_Run_Service::allowed_statuses()`:

- `created`
- `planned_waiting_for_sources`
- `running`
- `partial`
- `evidence_ingested`
- `completed`
- `completed_no_relevant_evidence`
- `failed`

Source-job statuses are centralized in `FIDTF_Source_Job_Service::allowed_statuses()`:

- `planned`
- `waiting_for_provider`
- `queued`
- `running`
- `completed`
- `completed_no_relevant_evidence`
- `retryable_failed`
- `failed`
- `skipped`

`update_run()`, `update_job()`, REST preparation, and dispatch normalization sanitize statuses before storage or response output.

## Credit mode

`FIDTF_Credit_Service::credit_mode()` returns:

- `settled` when `final_credits_settled > 0`
- `reserved` when `credits_reserved > 0`
- `voided` only if a future void flag exists
- `planning_only` otherwise

v0.1.4 exposes `credit_mode`, `credits_reserved`, and `final_credits_settled` in run/report responses. Planned/no-evidence runs remain planning estimates only.

## Admin safety copy

The admin page warns when:

- live dispatch is disabled,
- live dispatch is enabled and provider/API costs may start,
- deep video is hard-disabled,
- deep video setting is on while the hard flag is off,
- credit reservation is disabled.

## Next version

The next recommended version is `v0.2.0-ai-planner-bridge`. It should implement a controlled AI Planner Bridge and provider bridge contract without turning WordPress into a heavy crawler runtime.
