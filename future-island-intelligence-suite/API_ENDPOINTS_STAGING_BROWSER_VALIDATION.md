# API Endpoints — Staging Browser Validation

## Endpoint changes

No new public API endpoint was added in this sprint.

## Existing staging route endpoints preserved

Provider callbacks still enter through existing authenticated WordPress REST endpoints, including:

- `POST /fi/v1/provider/social/ingest`
- `POST /fi/v1/provider/aeo/ingest`
- `POST /fi/v1/provider/creative/ingest`
- `GET /fi/v1/provider/contracts`
- `GET /fi/v1/runs/{id}/report`
- `GET /fi/v1/runs/{id}/report.md`
- `POST /fi/v1/reports/export`
- onboarding/operator QA routes from the canonical REST controller.

## New admin route

A new WordPress admin page was added:

```text
admin.php?page=fi-staging-browser-validation
```

This is an internal/private-beta browser validation surface. It is not a public REST API.

## Security boundary

External workers, callback simulators and optional orchestrators must enter only through authenticated WordPress REST callbacks. No external tool writes directly to canonical tables.
