<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automation Recipes — a monday.com-style "When [trigger], if [conditions], then [actions]"
 * engine, layered safely on top of the AI pipeline.
 *
 * Design principles (kept consistent with the rest of the plugin):
 *  - OFF by default (global 'recipes_enabled' setting).
 *  - Deterministic: recipes do not call the AI; they complement the AI layer.
 *  - Safe by default: non-mutating actions (notify / flag) may run; task-MUTATING actions
 *    (set_priority / create_subtasks / add_comment) only run when the recipe is set to
 *    run_mode = "auto" AND the global 'recipe_allow_mutations' opt-in is on AND the engine
 *    is enabled. Otherwise they are recorded as held (visible in logs + audit), never applied.
 *  - Recipes ride the existing async, deduped, board-filtered event pipeline.
 *
 * Recipes are stored in their own option (not in settings) so they can never bloat or
 * destabilize the settings save path.
 */
class FBAIA_Recipes
{
    const OPTION = 'fbaia_recipes';
    const MAX_RECIPES = 100;

    public static function all()
    {
        $raw = get_option(self::OPTION, '');
        if (is_array($raw)) {
            return $raw;
        }
        return self::parse((string) $raw);
    }

    /**
     * True when at least one enabled recipe targets the given event. Used by the runner to
     * decide whether to queue an event that is not in the global trigger list.
     */
    public static function has_enabled_for_event($event)
    {
        $event = sanitize_key($event);
        foreach (self::all() as $recipe) {
            if (($recipe['enabled'] ?? false) && ($recipe['trigger'] ?? '') === $event) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persist recipes from raw admin input (JSON array or "---"-separated blocks).
     *
     * @return array{saved:int,errors:int}
     */
    public static function save_from_raw($raw)
    {
        $recipes = self::parse((string) $raw);
        $recipes = array_slice($recipes, 0, self::MAX_RECIPES);
        update_option(self::OPTION, $recipes, false);
        return ['saved' => count($recipes)];
    }

    public static function parse($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            $out = [];
            foreach ($json as $item) {
                if (is_array($item)) {
                    $recipe = self::normalize_recipe($item);
                    if ($recipe) {
                        $out[] = $recipe;
                    }
                }
            }
            return $out;
        }

        // Fallback: simple key:value blocks separated by a line of only dashes.
        $blocks = preg_split('/\n\s*---+\s*\n/', $raw);
        $out = [];
        foreach ($blocks as $block) {
            $recipe = self::parse_block($block);
            if ($recipe) {
                $out[] = $recipe;
            }
        }
        return $out;
    }

    private static function parse_block($block)
    {
        $block = trim((string) $block);
        if ($block === '') {
            return [];
        }
        $data = ['conditions' => [], 'actions' => []];
        foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($key, $value) = array_map('trim', explode(':', $line, 2));
            $key = sanitize_key(str_replace([' ', '-'], '_', $key));
            switch ($key) {
                case 'name':
                case 'trigger':
                case 'board_allowlist':
                case 'run_mode':
                case 'match':
                    $data[$key] = $value;
                    break;
                case 'enabled':
                    $data['enabled'] = in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
                    break;
                case 'when':
                    // when: field op value   e.g.  when: priority equals urgent
                    $parts = preg_split('/\s+/', $value, 3);
                    if (count($parts) >= 2) {
                        $data['conditions'][] = [
                            'field' => $parts[0],
                            'op' => $parts[1],
                            'value' => isset($parts[2]) ? $parts[2] : '',
                        ];
                    }
                    break;
                case 'then':
                    // then: type param   e.g.  then: notify admin@x.com  | then: set_priority high
                    $parts = preg_split('/\s+/', $value, 2);
                    $data['actions'][] = [
                        'type' => $parts[0],
                        'value' => isset($parts[1]) ? $parts[1] : '',
                    ];
                    break;
            }
        }
        return self::normalize_recipe($data);
    }

    /**
     * Time-based (scheduled) recipe triggers. These are not Fluent Boards events — they are
     * evaluated by the periodic time scan, not the live event pipeline.
     */
    public static function time_triggers()
    {
        return [
            'task_stale'    => __('Task inactive for N days (stuck)', 'fluent-boards-ai-automation'),
            'task_overdue'  => __('Task overdue', 'fluent-boards-ai-automation'),
            'task_due_soon' => __('Task due within N days', 'fluent-boards-ai-automation'),
        ];
    }

    public static function is_time_trigger($trigger)
    {
        return array_key_exists($trigger, self::time_triggers());
    }

    /** All triggers selectable for recipes: live Fluent Boards events + time-based ones. */
    public static function selectable_triggers()
    {
        return FBAIA_Helpers::available_triggers() + self::time_triggers();
    }

    private static function normalize_recipe(array $item)
    {
        $triggers = self::selectable_triggers();
        $trigger = sanitize_key($item['trigger'] ?? '');
        if ($trigger === '' || !isset($triggers[$trigger])) {
            return [];
        }

        $conditions = [];
        foreach ((array) ($item['conditions'] ?? []) as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            $field = sanitize_key(str_replace([' ', '-', '.'], '_', $cond['field'] ?? ''));
            $op = sanitize_key($cond['op'] ?? $cond['operator'] ?? 'equals');
            if ($field === '') {
                continue;
            }
            $conditions[] = [
                'field' => $field,
                'op' => self::valid_op($op),
                'value' => sanitize_text_field((string) ($cond['value'] ?? '')),
            ];
        }

        $actions = [];
        foreach ((array) ($item['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = sanitize_key($action['type'] ?? '');
            if (!in_array($type, ['notify', 'flag', 'set_priority', 'create_subtasks', 'add_comment'], true)) {
                continue;
            }
            $actions[] = [
                'type' => $type,
                'value' => sanitize_text_field((string) ($action['value'] ?? '')),
                'to' => sanitize_text_field((string) ($action['to'] ?? '')),
                'message' => sanitize_textarea_field((string) ($action['message'] ?? '')),
                'items' => array_values(array_filter(array_map('sanitize_text_field', (array) ($action['items'] ?? [])))),
            ];
        }

        if (empty($actions)) {
            return [];
        }

        $run_mode = sanitize_key($item['run_mode'] ?? 'review');
        $match = sanitize_key($item['match'] ?? 'all');

        return [
            'name' => sanitize_text_field($item['name'] ?? 'Recipe'),
            'enabled' => FBAIA_Helpers::to_bool($item['enabled'] ?? false),
            'trigger' => $trigger,
            'days' => max(0, min(365, (int) ($item['days'] ?? 3))),
            'board_allowlist' => sanitize_text_field($item['board_allowlist'] ?? ''),
            'match' => in_array($match, ['all', 'any'], true) ? $match : 'all',
            'conditions' => $conditions,
            'actions' => $actions,
            'run_mode' => in_array($run_mode, ['review', 'auto'], true) ? $run_mode : 'review',
        ];
    }

    /**
     * Build a recipes array from the visual builder's structured POST data
     * ($_POST['fbaia_recipe']). Returns a sanitized recipes list (same shape as parse()).
     */
    public static function from_structured($raw)
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = [];
            foreach ((array) ($row['conditions'] ?? []) as $c) {
                if (!is_array($c) || trim((string) ($c['field'] ?? '')) === '') {
                    continue;
                }
                $conditions[] = [
                    'field' => $c['field'] ?? '',
                    'op' => $c['op'] ?? 'equals',
                    'value' => $c['value'] ?? '',
                ];
            }
            $actions = [];
            foreach ((array) ($row['actions'] ?? []) as $a) {
                if (!is_array($a) || trim((string) ($a['type'] ?? '')) === '') {
                    continue;
                }
                $actions[] = [
                    'type' => $a['type'] ?? '',
                    'value' => $a['value'] ?? '',
                    'to' => $a['to'] ?? '',
                    'message' => $a['message'] ?? '',
                ];
            }
            $recipe = self::normalize_recipe([
                'name' => $row['name'] ?? 'Recipe',
                'enabled' => !empty($row['enabled']),
                'trigger' => $row['trigger'] ?? '',
                'days' => $row['days'] ?? 3,
                'board_allowlist' => $row['board_allowlist'] ?? '',
                'match' => $row['match'] ?? 'all',
                'run_mode' => $row['run_mode'] ?? 'review',
                'conditions' => $conditions,
                'actions' => $actions,
            ]);
            if ($recipe) {
                $out[] = $recipe;
            }
        }
        return array_slice($out, 0, self::MAX_RECIPES);
    }

    private static function valid_op($op)
    {
        $allowed = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'gt', 'lt', 'in'];
        return in_array($op, $allowed, true) ? $op : 'equals';
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * Evaluate and execute matching recipes for an event. Returns a summary list of what ran.
     */
    public static function run($event, array $payload, $adapter = null)
    {
        $settings = FBAIA_Helpers::get_settings();
        if (($settings['recipes_enabled'] ?? 'no') !== 'yes') {
            return [];
        }
        $event = sanitize_key($event);
        $board_id = FBAIA_Helpers::extract_board_id($payload);
        $allow_mutations = ($settings['recipe_allow_mutations'] ?? 'no') === 'yes';
        $adapter = $adapter ?: new FBAIA_FluentBoards_Adapter();

        $results = [];
        foreach (self::all() as $recipe) {
            if (!($recipe['enabled'] ?? false) || ($recipe['trigger'] ?? '') !== $event) {
                continue;
            }
            if (!empty($recipe['board_allowlist']) && $board_id && !FBAIA_Helpers::board_is_allowed($board_id, $recipe['board_allowlist'])) {
                continue;
            }
            if (!self::conditions_pass($recipe, $payload, $event)) {
                continue;
            }
            foreach ($recipe['actions'] as $action) {
                $results[] = self::execute($recipe, $action, $payload, $adapter, $allow_mutations);
            }
        }
        return $results;
    }

    public static function has_time_recipes()
    {
        foreach (self::all() as $recipe) {
            if (($recipe['enabled'] ?? false) && self::is_time_trigger($recipe['trigger'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scheduled scan for time-based recipes (stuck / overdue / due-soon). Runs on cron. Uses a
     * per-recipe/per-task/per-day transient so a matching task is only acted on once a day.
     */
    public static function time_scan($adapter = null)
    {
        $settings = FBAIA_Helpers::get_settings();
        if (($settings['recipes_enabled'] ?? 'no') !== 'yes') {
            return [];
        }
        $adapter = $adapter ?: new FBAIA_FluentBoards_Adapter();
        $allow_mutations = ($settings['recipe_allow_mutations'] ?? 'no') === 'yes';
        $limit = 50;
        $now = time();
        $results = [];

        foreach (self::all() as $recipe) {
            if (!($recipe['enabled'] ?? false) || !self::is_time_trigger($recipe['trigger'] ?? '')) {
                continue;
            }
            $trigger = $recipe['trigger'];
            $days = max(0, (int) ($recipe['days'] ?? 3));
            $allowlist = $recipe['board_allowlist'] ?? '';

            if ($trigger === 'task_stale') {
                $tasks = $adapter->get_stale_tasks(gmdate('Y-m-d H:i:s', $now - ($days * DAY_IN_SECONDS)), $limit, $allowlist);
            } elseif ($trigger === 'task_overdue') {
                $tasks = $adapter->get_due_tasks(gmdate('Y-m-d H:i:s', $now), $limit, $allowlist);
            } elseif ($trigger === 'task_due_soon') {
                $tasks = $adapter->get_tasks_due_within(gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + ($days * DAY_IN_SECONDS)), $limit, $allowlist);
            } else {
                continue;
            }

            if (is_wp_error($tasks) || !is_array($tasks)) {
                continue;
            }

            foreach ($tasks as $task) {
                $payload = ['task' => $adapter->task_to_array($task)];
                $task_id = FBAIA_Helpers::extract_task_id($payload);
                if (!$task_id) {
                    continue;
                }
                if (!self::conditions_pass($recipe, $payload, $trigger)) {
                    continue;
                }
                $key = 'fbaia_recipe_t_' . md5(($recipe['name'] ?? '') . '|' . $trigger . '|' . $task_id . '|' . gmdate('Y-m-d'));
                if (get_transient($key)) {
                    continue;
                }
                set_transient($key, 1, DAY_IN_SECONDS);
                foreach ($recipe['actions'] as $action) {
                    $results[] = self::execute($recipe, $action, $payload, $adapter, $allow_mutations);
                }
            }
        }
        return $results;
    }

    public static function conditions_pass(array $recipe, array $payload, $event)
    {
        $conditions = $recipe['conditions'] ?? [];
        if (empty($conditions)) {
            return true;
        }
        $match_all = ($recipe['match'] ?? 'all') !== 'any';
        foreach ($conditions as $cond) {
            $ok = self::compare(self::resolve_field($payload, $cond['field'], $event), $cond['op'], $cond['value']);
            if ($match_all && !$ok) {
                return false;
            }
            if (!$match_all && $ok) {
                return true;
            }
        }
        return $match_all;
    }

    public static function resolve_field(array $payload, $field, $event = '')
    {
        switch ($field) {
            case 'event':
                return $event;
            case 'board_id':
                return FBAIA_Helpers::extract_board_id($payload);
            case 'task_id':
                return FBAIA_Helpers::extract_task_id($payload);
            case 'title':
                return FBAIA_Helpers::extract_task_title($payload);
        }

        $task = isset($payload['task']) && is_array($payload['task']) ? $payload['task'] : [];
        $aliases = [
            'priority' => $task['priority'] ?? $payload['priority'] ?? '',
            'status' => $task['status'] ?? $payload['status'] ?? '',
            'stage_id' => $task['stage_id'] ?? $payload['stage_id'] ?? '',
            'due_at' => $task['due_at'] ?? $payload['due_at'] ?? '',
            'description' => $task['description'] ?? $payload['description'] ?? '',
            'label' => $payload['label'] ?? '',
            'comment' => $payload['comment']['description'] ?? '',
            'assignee' => $payload['assignee'] ?? '',
        ];
        if (array_key_exists($field, $aliases)) {
            return $aliases[$field];
        }

        // Dotted path fallback (field stored with underscores; try direct key too).
        if (isset($payload[$field])) {
            return $payload[$field];
        }
        return '';
    }

    public static function compare($actual, $op, $expected)
    {
        $a = is_scalar($actual) ? (string) $actual : '';
        $b = (string) $expected;
        switch ($op) {
            case 'equals':
                return strcasecmp(trim($a), trim($b)) === 0;
            case 'not_equals':
                return strcasecmp(trim($a), trim($b)) !== 0;
            case 'contains':
                return $b !== '' && stripos($a, $b) !== false;
            case 'not_contains':
                return $b === '' || stripos($a, $b) === false;
            case 'is_empty':
                return trim($a) === '';
            case 'is_not_empty':
                return trim($a) !== '';
            case 'gt':
                return is_numeric($a) && is_numeric($b) && (float) $a > (float) $b;
            case 'lt':
                return is_numeric($a) && is_numeric($b) && (float) $a < (float) $b;
            case 'in':
                $list = array_map('trim', explode(',', strtolower($b)));
                return in_array(strtolower(trim($a)), $list, true);
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    private static function execute(array $recipe, array $action, array $payload, $adapter, $allow_mutations)
    {
        $type = $action['type'];
        $task_id = FBAIA_Helpers::extract_task_id($payload);
        $board_id = FBAIA_Helpers::extract_board_id($payload);
        $name = $recipe['name'] ?? 'Recipe';

        // Non-mutating actions always run.
        if ($type === 'notify') {
            return self::do_notify($name, $action, $payload, $task_id);
        }
        if ($type === 'flag') {
            self::log('warning', 'Recipe flag', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id]);
            self::audit('recipe_flagged', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'flagged']);
            return ['recipe' => $name, 'action' => 'flag', 'status' => 'flagged'];
        }

        // Mutating actions are gated. When held, record them in the review queue so an admin
        // can approve and apply them later — no silent changes.
        $can_mutate = $allow_mutations && (($recipe['run_mode'] ?? 'review') === 'auto');
        if (!$can_mutate) {
            if (class_exists('FBAIA_Recipe_Queue')) {
                FBAIA_Recipe_Queue::record($name, $action, $task_id, $board_id);
            }
            self::log('info', 'Recipe mutating action held for review', ['recipe' => $name, 'action' => $type, 'task_id' => $task_id]);
            self::audit('recipe_action_held', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'held']);
            return ['recipe' => $name, 'action' => $type, 'status' => 'held'];
        }

        $result = self::apply_mutation($type, $action, $task_id, $board_id, $adapter, $name);
        return ['recipe' => $name, 'action' => $type, 'status' => $result['status'], 'count' => $result['count'] ?? null];
    }

    /**
     * Perform a mutating recipe action against Fluent Boards. Shared by the auto path and by
     * the review-queue approval path (which is an explicit, authorized human action).
     *
     * @return array{status:string,count?:int}
     */
    public static function apply_mutation($type, array $action, $task_id, $board_id, $adapter = null, $name = 'Recipe')
    {
        $adapter = $adapter ?: new FBAIA_FluentBoards_Adapter();
        $task_id = absint($task_id);
        $board_id = absint($board_id);

        if ($type === 'set_priority') {
            $result = $adapter->update_task_priority($task_id, sanitize_key($action['value'] ?? ''));
            $status = is_wp_error($result) ? 'failed' : 'applied';
            self::log(is_wp_error($result) ? 'error' : 'success', 'Recipe set priority', ['recipe' => $name, 'task_id' => $task_id, 'priority' => $action['value'] ?? '', 'status' => $status]);
            self::audit('recipe_priority_set', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => $status]);
            return ['status' => $status];
        }

        if ($type === 'create_subtasks') {
            $items = !empty($action['items']) ? $action['items'] : array_filter(array_map('trim', explode('|', (string) ($action['value'] ?? ''))));
            $count = 0;
            foreach (array_slice($items, 0, 20) as $title) {
                $title = sanitize_text_field((string) $title);
                if ($title === '') {
                    continue;
                }
                $r = $adapter->create_subtask($task_id, $title);
                if (!is_wp_error($r)) {
                    $count++;
                }
            }
            self::log('success', 'Recipe created subtasks', ['recipe' => $name, 'task_id' => $task_id, 'count' => $count]);
            self::audit('recipe_subtasks_created', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id, 'count' => $count, 'status' => 'applied']);
            return ['status' => 'applied', 'count' => $count];
        }

        if ($type === 'add_comment') {
            $settings = FBAIA_Helpers::get_settings();
            $privacy = ($settings['write_private_comment'] ?? 'yes') === 'yes' ? 'private' : 'public';
            $text = FBAIA_Helpers::GENERATED_MARKER . "\n<strong>" . esc_html($name) . "</strong>\n<p>" . esc_html(($action['message'] ?? '') ?: ($action['value'] ?? '')) . '</p>'
                . '<p><small>' . esc_html(FBAIA_FluentBoards_Adapter::GENERATED_TEXT_MARKER) . '</small></p>';
            $result = $adapter->create_ai_comment($task_id, $board_id, $text, $privacy);
            $status = is_wp_error($result) ? 'failed' : 'applied';
            self::log(is_wp_error($result) ? 'error' : 'success', 'Recipe added comment', ['recipe' => $name, 'task_id' => $task_id, 'status' => $status]);
            self::audit('recipe_comment_added', ['recipe' => $name, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => $status]);
            return ['status' => $status];
        }

        return ['status' => 'unknown'];
    }

    private static function do_notify($name, array $action, array $payload, $task_id)
    {
        $settings = FBAIA_Helpers::get_settings();
        $to = sanitize_email($action['to'] ?: ($action['value'] ?: ''));
        if (!$to) {
            $to = sanitize_email($settings['notify_email'] ?? get_option('admin_email'));
        }
        if (!$to) {
            return ['recipe' => $name, 'action' => 'notify', 'status' => 'skipped_no_email'];
        }
        $title = FBAIA_Helpers::extract_task_title($payload);
        $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $name . ($task_id ? ' #' . $task_id : '');
        $body = ($action['message'] ?: $name) . "\n\n"
            . ($task_id ? ('Task: #' . $task_id . ($title ? ' — ' . $title : '') . "\n") : '')
            . 'Site: ' . site_url();
        $sent = wp_mail($to, $subject, $body);
        self::log($sent ? 'success' : 'error', 'Recipe notification email', ['recipe' => $name, 'task_id' => $task_id, 'email' => $to, 'status' => $sent ? 'sent' : 'failed']);
        self::audit('recipe_notified', ['recipe' => $name, 'task_id' => $task_id, 'status' => $sent ? 'sent' : 'failed']);
        return ['recipe' => $name, 'action' => 'notify', 'status' => $sent ? 'sent' : 'failed'];
    }

    private static function log($level, $message, array $context)
    {
        if (class_exists('FBAIA_Logger')) {
            FBAIA_Logger::add($level, $message, $context);
        }
    }

    private static function audit($action, array $context)
    {
        if (class_exists('FBAIA_Audit_Trail')) {
            FBAIA_Audit_Trail::record($action, $context);
        }
    }

    public static function example_template()
    {
        return wp_json_encode([
            [
                'name' => 'Alert on urgent priority',
                'enabled' => false,
                'trigger' => 'task_priority_changed',
                'match' => 'all',
                'conditions' => [['field' => 'priority', 'op' => 'equals', 'value' => 'urgent']],
                'actions' => [['type' => 'notify', 'to' => '', 'message' => 'A task was set to urgent.'], ['type' => 'flag']],
                'run_mode' => 'review',
            ],
            [
                'name' => 'New task without due date',
                'enabled' => false,
                'trigger' => 'task_created',
                'match' => 'all',
                'conditions' => [['field' => 'due_at', 'op' => 'is_empty', 'value' => '']],
                'actions' => [['type' => 'notify', 'to' => '', 'message' => 'New task has no due date — please set one.']],
                'run_mode' => 'review',
            ],
            [
                'name' => 'Auto-bump blocked tasks',
                'enabled' => false,
                'trigger' => 'task_dependency_added',
                'match' => 'all',
                'conditions' => [],
                'actions' => [['type' => 'set_priority', 'value' => 'high']],
                'run_mode' => 'auto',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
