<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Command Console — a "Siri-style" natural-language command layer.
 *
 * Flow: free text -> AI interprets into a STRUCTURED intent (constrained to a whitelist) ->
 * the plugin validates/sanitizes it -> read-only intents run immediately; task-MUTATING
 * intents return a preview and require an explicit confirm before they touch anything.
 *
 * Safety: the AI never executes anything directly. It only proposes one of a fixed set of
 * intents; the PHP side decides what is allowed, sanitizes every parameter, enforces the
 * capability + confirm gates, and marks any written content so it can't trigger loops.
 */
class FBAIA_Command
{
    /**
     * Intent registry. type: 'read' runs immediately; 'mutate' requires confirm.
     */
    public static function intents()
    {
        return [
            'help'            => ['type' => 'read'],
            'find_tasks'      => ['type' => 'read'],
            'analyze_task'    => ['type' => 'read'],
            'summarize_task'  => ['type' => 'read'],
            'set_priority'    => ['type' => 'mutate'],
            'create_subtasks' => ['type' => 'mutate'],
            'add_comment'     => ['type' => 'mutate'],
        ];
    }

    public static function system_prompt()
    {
        return 'You are a command interpreter for a WordPress + Fluent Boards task assistant. '
            . 'Convert the user request into ONE JSON object describing a single intent. '
            . 'Allowed intents and params: '
            . 'help {}; '
            . 'find_tasks {filter:"overdue|stale|due_soon|recent", days:int, board_id:int}; '
            . 'analyze_task {task_id:int}; '
            . 'summarize_task {task_id:int}; '
            . 'set_priority {task_id:int, priority:"low|medium|high|urgent"}; '
            . 'create_subtasks {task_id:int, titles:[string]}; '
            . 'add_comment {task_id:int, text:string}. '
            . 'Return ONLY: {"intent":"<one of the above>","params":{...},"confidence":0.0,"speak":"short confirmation sentence"}. '
            . 'If the request is unclear or not supported, use intent "help". Never invent task IDs; if no id is given for an intent that needs one, use intent "help". '
            . 'Extract integers for task_id from phrases like "task 42" or "#42".';
    }

    /**
     * Interpret free text into a validated intent via the AI, then sanitize it.
     *
     * @return array|WP_Error  ['intent'=>, 'params'=>, 'type'=>, 'speak'=>, 'confidence'=>]
     */
    public static function interpret($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return new WP_Error('empty_command', __('Please type or say a command.', 'fluent-boards-ai-automation'));
        }
        $client = new FBAIA_OpenAI_Client();
        $result = $client->chat_json(self::system_prompt(), $text, 600);
        if (is_wp_error($result)) {
            return $result;
        }
        $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
        return self::sanitize_intent($parsed);
    }

    /**
     * Validate + sanitize a raw intent object (from the AI). Pure; safe to unit-test.
     */
    public static function sanitize_intent($raw)
    {
        $raw = is_array($raw) ? $raw : [];
        $intents = self::intents();
        $intent = sanitize_key($raw['intent'] ?? '');
        if (!isset($intents[$intent])) {
            $intent = 'help';
        }
        $params_in = isset($raw['params']) && is_array($raw['params']) ? $raw['params'] : [];
        $params = [];

        switch ($intent) {
            case 'find_tasks':
                $filter = sanitize_key($params_in['filter'] ?? 'recent');
                $params['filter'] = in_array($filter, ['overdue', 'stale', 'due_soon', 'recent'], true) ? $filter : 'recent';
                $params['days'] = max(1, min(365, (int) ($params_in['days'] ?? 7)));
                $params['board_id'] = absint($params_in['board_id'] ?? 0);
                break;
            case 'analyze_task':
            case 'summarize_task':
                $params['task_id'] = absint($params_in['task_id'] ?? 0);
                if (!$params['task_id']) {
                    $intent = 'help';
                }
                break;
            case 'set_priority':
                $params['task_id'] = absint($params_in['task_id'] ?? 0);
                $priority = sanitize_key($params_in['priority'] ?? '');
                $params['priority'] = in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ? $priority : '';
                if (!$params['task_id'] || $params['priority'] === '') {
                    $intent = 'help';
                }
                break;
            case 'create_subtasks':
                $params['task_id'] = absint($params_in['task_id'] ?? 0);
                $titles = [];
                foreach ((array) ($params_in['titles'] ?? []) as $t) {
                    $t = sanitize_text_field((string) $t);
                    if ($t !== '') {
                        $titles[] = $t;
                    }
                }
                $params['titles'] = array_slice($titles, 0, 20);
                if (!$params['task_id'] || empty($params['titles'])) {
                    $intent = 'help';
                }
                break;
            case 'add_comment':
                $params['task_id'] = absint($params_in['task_id'] ?? 0);
                $params['text'] = sanitize_textarea_field((string) ($params_in['text'] ?? ''));
                if (!$params['task_id'] || $params['text'] === '') {
                    $intent = 'help';
                }
                break;
        }

        return [
            'intent' => $intent,
            'type' => self::intents()[$intent]['type'],
            'params' => $params,
            'speak' => sanitize_text_field($raw['speak'] ?? ''),
            'confidence' => isset($raw['confidence']) ? max(0, min(1, (float) $raw['confidence'])) : null,
        ];
    }

    /**
     * Execute a sanitized intent. Read intents run now; mutate intents return a preview unless
     * $confirm is true (an explicit, authorized admin action).
     *
     * @return array  ['ok'=>bool, 'message'=>string, 'needs_confirm'=>bool, 'intent'=>, 'params'=>, 'data'=>]
     */
    public static function execute(array $intent, $confirm = false, $adapter = null)
    {
        $adapter = $adapter ?: new FBAIA_FluentBoards_Adapter();
        $name = $intent['intent'] ?? 'help';
        $params = $intent['params'] ?? [];
        $type = self::intents()[$name]['type'] ?? 'read';

        if ($name === 'help') {
            return self::ok(__('I can: find tasks (overdue/stale/due soon/recent), analyze or summarize a task, set priority, create subtasks, or add a comment. Try: "analyze task 42" or "set task 42 to high".', 'fluent-boards-ai-automation'));
        }

        // Mutating intents: preview first, execute only on explicit confirm.
        if ($type === 'mutate' && !$confirm) {
            return [
                'ok' => true,
                'needs_confirm' => true,
                'intent' => $name,
                'params' => $params,
                'message' => self::preview_message($name, $params),
            ];
        }

        switch ($name) {
            case 'find_tasks':
                return self::do_find_tasks($params, $adapter);
            case 'analyze_task':
                return self::do_analyze_task($params, $adapter);
            case 'summarize_task':
                return self::do_summarize_task($params, $adapter);
            case 'set_priority':
            case 'create_subtasks':
            case 'add_comment':
                return self::do_mutation($name, $params, $adapter);
        }
        return self::fail(__('Unsupported command.', 'fluent-boards-ai-automation'));
    }

    private static function preview_message($name, array $params)
    {
        $tid = (int) ($params['task_id'] ?? 0);
        switch ($name) {
            case 'set_priority':
                return sprintf(__('Set task #%1$d priority to "%2$s"?', 'fluent-boards-ai-automation'), $tid, $params['priority'] ?? '');
            case 'create_subtasks':
                return sprintf(__('Create %1$d subtask(s) on task #%2$d?', 'fluent-boards-ai-automation'), count($params['titles'] ?? []), $tid);
            case 'add_comment':
                return sprintf(__('Add a comment to task #%d?', 'fluent-boards-ai-automation'), $tid);
        }
        return __('Confirm this action?', 'fluent-boards-ai-automation');
    }

    private static function do_find_tasks(array $params, $adapter)
    {
        $filter = $params['filter'] ?? 'recent';
        $days = (int) ($params['days'] ?? 7);
        $allow = $params['board_id'] ? (string) $params['board_id'] : '';
        $now = time();

        if ($filter === 'overdue') {
            $tasks = $adapter->get_due_tasks(gmdate('Y-m-d H:i:s', $now), 25, $allow);
        } elseif ($filter === 'stale') {
            $tasks = $adapter->get_stale_tasks(gmdate('Y-m-d H:i:s', $now - ($days * DAY_IN_SECONDS)), 25, $allow);
        } elseif ($filter === 'due_soon') {
            $tasks = $adapter->get_tasks_due_within(gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + ($days * DAY_IN_SECONDS)), 25, $allow);
        } else {
            $tasks = $adapter->get_recent_tasks(gmdate('Y-m-d H:i:s', $now - ($days * DAY_IN_SECONDS)), 25, $allow);
        }

        if (is_wp_error($tasks)) {
            return self::fail($tasks->get_error_message());
        }
        $rows = [];
        foreach ((array) $tasks as $task) {
            $a = $adapter->task_to_array($task);
            $rows[] = [
                'id' => isset($a['id']) ? absint($a['id']) : 0,
                'title' => isset($a['title']) ? sanitize_text_field($a['title']) : '',
                'priority' => isset($a['priority']) ? sanitize_text_field($a['priority']) : '',
                'due_at' => isset($a['due_at']) ? sanitize_text_field($a['due_at']) : '',
            ];
        }
        return [
            'ok' => true,
            'needs_confirm' => false,
            'message' => sprintf(__('Found %1$d %2$s task(s).', 'fluent-boards-ai-automation'), count($rows), $filter),
            'data' => ['tasks' => $rows],
        ];
    }

    private static function do_analyze_task(array $params, $adapter)
    {
        $task_id = (int) ($params['task_id'] ?? 0);
        $task = $task_id ? $adapter->get_task($task_id) : null;
        if (!$task) {
            return self::fail(sprintf(__('Task #%d was not found.', 'fluent-boards-ai-automation'), $task_id));
        }
        $runner = new FBAIA_Runner();
        $result = $runner->queue('external_event', [
            'task_id' => $task_id,
            'task' => $adapter->task_to_array($task),
            'manual_analysis' => true,
            'requested_by' => get_current_user_id(),
        ], true);
        if (empty($result['queued'])) {
            return self::fail(sprintf(__('Could not queue analysis (%s).', 'fluent-boards-ai-automation'), $result['reason'] ?? 'error'));
        }
        return self::ok(sprintf(__('Queued AI analysis for task #%d. It will appear in the review queue.', 'fluent-boards-ai-automation'), $task_id));
    }

    private static function do_summarize_task(array $params, $adapter)
    {
        $task_id = (int) ($params['task_id'] ?? 0);
        $task = $task_id ? $adapter->get_task($task_id) : null;
        if (!$task) {
            return self::fail(sprintf(__('Task #%d was not found.', 'fluent-boards-ai-automation'), $task_id));
        }
        $client = new FBAIA_OpenAI_Client();
        $result = $client->complete('external_event', ['task_id' => $task_id, 'task' => $adapter->task_to_array($task)]);
        if (is_wp_error($result)) {
            return self::fail($result->get_error_message());
        }
        $parsed = $result['parsed'] ?? [];
        return [
            'ok' => true,
            'needs_confirm' => false,
            'message' => sanitize_textarea_field($parsed['summary'] ?? __('No summary produced.', 'fluent-boards-ai-automation')),
            'data' => [
                'summary' => sanitize_textarea_field($parsed['summary'] ?? ''),
                'recommended_priority' => sanitize_key($parsed['recommended_priority'] ?? ''),
                'confidence' => isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0,
                'next_actions' => array_slice((array) ($parsed['next_actions'] ?? []), 0, 5),
            ],
        ];
    }

    private static function do_mutation($name, array $params, $adapter)
    {
        $task_id = (int) ($params['task_id'] ?? 0);
        $task = $task_id ? $adapter->get_task($task_id) : null;
        if (!$task) {
            return self::fail(sprintf(__('Task #%d was not found.', 'fluent-boards-ai-automation'), $task_id));
        }
        $board_id = 0;
        $a = $adapter->task_to_array($task);
        if (!empty($a['board_id'])) {
            $board_id = absint($a['board_id']);
        }

        if ($name === 'set_priority') {
            $r = $adapter->update_task_priority($task_id, $params['priority']);
            if (is_wp_error($r)) {
                return self::fail($r->get_error_message());
            }
            self::audit('command_set_priority', ['task_id' => $task_id, 'priority' => $params['priority']]);
            return self::ok(sprintf(__('Set task #%1$d priority to %2$s.', 'fluent-boards-ai-automation'), $task_id, $params['priority']));
        }

        if ($name === 'create_subtasks') {
            $count = 0;
            foreach ($params['titles'] as $title) {
                $r = $adapter->create_subtask($task_id, $title);
                if (!is_wp_error($r)) {
                    $count++;
                }
            }
            self::audit('command_create_subtasks', ['task_id' => $task_id, 'count' => $count]);
            return self::ok(sprintf(__('Created %1$d subtask(s) on task #%2$d.', 'fluent-boards-ai-automation'), $count, $task_id));
        }

        if ($name === 'add_comment') {
            if (!$board_id) {
                return self::fail(__('Could not resolve the board for that task.', 'fluent-boards-ai-automation'));
            }
            $settings = FBAIA_Helpers::get_settings();
            $privacy = ($settings['write_private_comment'] ?? 'yes') === 'yes' ? 'private' : 'public';
            $text = FBAIA_Helpers::GENERATED_MARKER . "\n<p>" . esc_html($params['text']) . '</p>'
                . '<p><small>' . esc_html(FBAIA_FluentBoards_Adapter::GENERATED_TEXT_MARKER) . '</small></p>';
            $r = $adapter->create_ai_comment($task_id, $board_id, $text, $privacy);
            if (is_wp_error($r)) {
                return self::fail($r->get_error_message());
            }
            self::audit('command_add_comment', ['task_id' => $task_id]);
            return self::ok(sprintf(__('Added a comment to task #%d.', 'fluent-boards-ai-automation'), $task_id));
        }

        return self::fail(__('Unsupported command.', 'fluent-boards-ai-automation'));
    }

    private static function ok($message, array $data = [])
    {
        return ['ok' => true, 'needs_confirm' => false, 'message' => $message, 'data' => $data];
    }

    private static function fail($message)
    {
        return ['ok' => false, 'needs_confirm' => false, 'message' => $message];
    }

    private static function audit($action, array $context)
    {
        if (class_exists('FBAIA_Audit_Trail')) {
            FBAIA_Audit_Trail::record($action, $context + ['status' => 'applied']);
        }
        if (class_exists('FBAIA_Logger')) {
            FBAIA_Logger::add('success', 'Command executed: ' . $action, $context);
        }
    }
}
