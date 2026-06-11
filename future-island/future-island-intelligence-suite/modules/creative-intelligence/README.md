# Creative Intelligence

WordPress add-on plugin for AI-powered creative asset analysis inside a WordPress-first SaaS workspace.

## Version 0.1.3

This version focuses on connecting the Creative Intelligence add-on to the wider SaaS flow.

## Included

- Shortcode: `[fici_creative_analyzer]`
- Admin settings page under Settings > Creative Intelligence
- OpenAI Responses API integration with structured output
- Image/screenshot analysis
- Browser-side video keyframe extraction for short MP4/WebM/MOV videos
- Previous analyses history
- Result cards for hook, platform fit, strengths, weaknesses, opportunities, hooks, captions, and brief
- Workspace binding display
- Core bridge hooks for credits, memory, insight, brief, and run status
- Buttons to write memory, create insight, and create brief from a completed analysis

## REST endpoints

- `GET /wp-json/fici/v1/workspace`
- `POST /wp-json/fici/v1/analysis/start`
- `GET /wp-json/fici/v1/analysis/recent`
- `GET /wp-json/fici/v1/analysis/{id}/status`
- `GET /wp-json/fici/v1/analysis/{id}/result`
- `POST /wp-json/fici/v1/analysis/{id}/write-memory`
- `POST /wp-json/fici/v1/analysis/{id}/create-insight`
- `POST /wp-json/fici/v1/analysis/{id}/create-brief`
- `DELETE /wp-json/fici/v1/analysis/{id}`

## API key

Add the OpenAI API key in the settings page, or preferably define it in `wp-config.php`:

```php
define( 'FICI_OPENAI_API_KEY', 'your_api_key_here' );
```

## Core bridge hooks

The add-on can run standalone, but is designed to connect to a core SaaS plugin through hooks:

- `fici_core_workspace_id`
- `fici_core_workspace_context`
- `fici_core_user_can_use_addon`
- `fici_core_reserve_credits_payload`
- `fici_core_reserve_credits`
- `fici_core_finalize_usage`
- `fici_core_refund_usage`
- `fici_core_create_run`
- `fici_core_update_run_status`
- `fici_core_create_memory_record`
- `fici_core_create_insight_from_result`
- `fici_core_create_brief_from_result`

## Notes

- Heavy server-side video processing is not included yet.
- v0.2 should move video extraction/transcription into Action Scheduler, Apify, or an external worker.
