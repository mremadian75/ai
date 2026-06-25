<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_REST_Controller
{
    public function register()
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes()
    {
        register_rest_route('fb-ai-automation/v1', '/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'preview'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'event' => ['required' => true, 'type' => 'string'],
                'payload' => ['required' => true],
            ],
        ]);

        register_rest_route('fb-ai-automation/v1', '/task/(?P<id>\d+)/analyze', [
            'methods' => 'POST',
            'callback' => [$this, 'analyze_task'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/incoming', [
            'methods' => 'POST',
            'callback' => [$this, 'incoming'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/suggestions', [
            'methods' => 'GET',
            'callback' => [$this, 'suggestions'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/suggestions/(?P<id>[a-zA-Z0-9_\-]+)/apply', [
            'methods' => 'POST',
            'callback' => [$this, 'apply_suggestion'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/suggestions/(?P<id>[a-zA-Z0-9_\-]+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'reject_suggestion'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/insights', [
            'methods' => 'GET',
            'callback' => [$this, 'insights'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/audit', [
            'methods' => 'GET',
            'callback' => [$this, 'audit'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/export', [
            'methods' => 'GET',
            'callback' => [$this, 'export_snapshot'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/command', [
            'methods' => 'POST',
            'callback' => [$this, 'command'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('fb-ai-automation/v1', '/command/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'command_execute'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function command(WP_REST_Request $request)
    {
        if (!class_exists('FBAIA_Command')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Command engine unavailable.'], 500);
        }
        $text = sanitize_textarea_field((string) $request->get_param('text'));
        $intent = FBAIA_Command::interpret($text);
        if (is_wp_error($intent)) {
            return new WP_REST_Response(['ok' => false, 'error' => $intent->get_error_message()], 400);
        }
        $result = FBAIA_Command::execute($intent, false);
        $result['interpreted'] = ['intent' => $intent['intent'], 'params' => $intent['params'], 'speak' => $intent['speak']];
        return new WP_REST_Response($result, 200);
    }

    public function command_execute(WP_REST_Request $request)
    {
        if (!class_exists('FBAIA_Command')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Command engine unavailable.'], 500);
        }
        // Re-sanitize server-side; never trust the client's intent/params blindly.
        $raw = [
            'intent' => sanitize_key((string) $request->get_param('intent')),
            'params' => (array) $request->get_param('params'),
        ];
        $intent = FBAIA_Command::sanitize_intent($raw);
        $result = FBAIA_Command::execute($intent, true);
        return new WP_REST_Response($result, 200);
    }

    public function preview(WP_REST_Request $request)
    {
        $event = sanitize_key($request->get_param('event'));
        $payload = $request->get_param('payload');
        $payload = is_array($payload) ? $payload : ['input' => $payload];

        $client = new FBAIA_OpenAI_Client();
        $result = $client->complete($event, FBAIA_Helpers::normalize($payload));
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $result->get_error_message(),
            ], 400);
        }

        return [
            'ok' => true,
            'result' => $result['parsed'] ?? [],
            'usage' => $result['usage'] ?? [],
        ];
    }

    public function analyze_task(WP_REST_Request $request)
    {
        $task_id = absint($request->get_param('id'));
        $adapter = new FBAIA_FluentBoards_Adapter();
        $task = $task_id ? $adapter->get_task($task_id) : null;
        if (!$task) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Task not found.'], 404);
        }

        $runner = new FBAIA_Runner();
        $result = $runner->queue('external_event', [
            'task_id' => $task_id,
            'task' => $adapter->task_to_array($task),
            'manual_analysis' => true,
            'requested_by' => get_current_user_id(),
        ], true);

        return new WP_REST_Response([
            'ok' => !empty($result['queued']),
            'queued' => !empty($result['queued']),
            'reason' => $result['reason'] ?? null,
            'task_id' => $task_id,
        ], 202);
    }

    public function suggestions(WP_REST_Request $request)
    {
        if (!class_exists('FBAIA_Suggestion_Store')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Suggestion store is not available.'], 500);
        }
        $status = sanitize_key($request->get_param('status') ?: '');
        $items = FBAIA_Suggestion_Store::all($status);

        // Optional task filter. When set, return a slim, display-only projection (no raw
        // ai_payload/payload) — keeps the in-board panel fast and avoids shipping the full
        // analysis blob to the browser.
        $task_id = absint($request->get_param('task_id'));
        if ($task_id) {
            $items = array_values(array_filter($items, function ($item) use ($task_id) {
                return (int) ($item['task_id'] ?? 0) === $task_id;
            }));
            $items = array_map([$this, 'slim_suggestion'], $items);
        }

        return [
            'ok' => true,
            'stats' => FBAIA_Suggestion_Store::stats(),
            'suggestions' => $items,
        ];
    }

    private function slim_suggestion(array $item)
    {
        return [
            'id' => sanitize_text_field($item['id'] ?? ''),
            'task_id' => (int) ($item['task_id'] ?? 0),
            'status' => sanitize_key($item['status'] ?? 'pending'),
            'created_at' => sanitize_text_field($item['created_at'] ?? ''),
            'summary' => sanitize_textarea_field($item['summary'] ?? ''),
            'recommended_priority' => sanitize_key($item['recommended_priority'] ?? ''),
            'confidence' => (float) ($item['confidence'] ?? 0),
            'risk_score' => (float) ($item['risk_score'] ?? 0),
            'decision_brief' => sanitize_textarea_field($item['decision_brief'] ?? ''),
        ];
    }

    public function apply_suggestion(WP_REST_Request $request)
    {
        if (!class_exists('FBAIA_Suggestion_Store')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Suggestion store is not available.'], 500);
        }
        $id = sanitize_text_field($request->get_param('id'));
        $result = FBAIA_Suggestion_Store::apply($id);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['ok' => false, 'error' => $result->get_error_message()], 404);
        }
        return ['ok' => true, 'result' => $result];
    }

    public function reject_suggestion(WP_REST_Request $request)
    {
        if (!class_exists('FBAIA_Suggestion_Store')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Suggestion store is not available.'], 500);
        }
        $id = sanitize_text_field($request->get_param('id'));
        $feedback = sanitize_textarea_field($request->get_param('feedback') ?: '');
        $result = FBAIA_Suggestion_Store::reject($id, $feedback);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['ok' => false, 'error' => $result->get_error_message()], 404);
        }
        return ['ok' => true, 'result' => $result];
    }

    public function health(WP_REST_Request $request)
    {
        return ['ok' => true, 'diagnostics' => class_exists('FBAIA_Health_Check') ? FBAIA_Health_Check::diagnostics() : []];
    }

    public function insights(WP_REST_Request $request)
    {
        return ['ok' => true, 'snapshot' => class_exists('FBAIA_Insights') ? FBAIA_Insights::snapshot() : []];
    }

    public function audit(WP_REST_Request $request)
    {
        $limit = max(1, min(500, absint($request->get_param('limit') ?: 100)));
        return ['ok' => true, 'audit' => class_exists('FBAIA_Audit_Trail') ? FBAIA_Audit_Trail::all($limit) : []];
    }

    public function export_snapshot(WP_REST_Request $request)
    {
        return ['ok' => true, 'snapshot' => class_exists('FBAIA_Exporter') ? FBAIA_Exporter::snapshot() : []];
    }

    public function incoming(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            $data = $request->get_params();
        }
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid request body.'], 400);
        }

        $raw_event = sanitize_key($data['event'] ?? 'external_event');
        $event = in_array($raw_event, array_keys(FBAIA_Helpers::available_triggers()), true) ? $raw_event : 'external_event';
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : $data;
        if ($event === 'external_event' && $raw_event !== 'external_event') {
            $payload['original_event'] = $raw_event;
        }

        $runner = new FBAIA_Runner();
        $result = $runner->queue($event, $payload);

        if (empty($result['queued'])) {
            return new WP_REST_Response([
                'ok' => true,
                'queued' => false,
                'reason' => $result['reason'] ?? 'not_queued',
                'event' => $event,
            ], 202);
        }

        return new WP_REST_Response(['ok' => true, 'queued' => true, 'event' => $event], 202);
    }
}
