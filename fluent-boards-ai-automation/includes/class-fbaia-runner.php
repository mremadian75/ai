<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Runner
{
    private $registered = false;

    public function register()
    {
        add_action('fluent_boards_loaded', [$this, 'register_fluent_hooks'], 30);
        add_action('init', [$this, 'register_fluent_hooks'], 99);
        add_action('fbaia_process_event', [$this, 'process_event'], 10, 2);
        add_action('fbaia_overdue_scan', [$this, 'process_overdue_scan']);
        add_action('fbaia_daily_digest', [$this, 'send_daily_digest']);
        add_action('fbaia_weekly_intelligence_digest', [$this, 'send_weekly_intelligence_digest']);
        add_action('fbaia_recipe_time_scan', [$this, 'run_recipe_time_scan']);
    }

    public function run_recipe_time_scan()
    {
        if (class_exists('FBAIA_Recipes')) {
            FBAIA_Recipes::time_scan();
        }
    }

    public function register_fluent_hooks()
    {
        if ($this->registered) {
            return;
        }

        // Dependency guard: only attach Fluent Boards task hooks when Fluent Boards is
        // actually present. If it is missing/inactive we degrade gracefully and attach
        // nothing, so this add-on can never interfere with a site that has no Fluent Boards.
        $adapter = new FBAIA_FluentBoards_Adapter();
        if (!$adapter->is_available()) {
            return;
        }

        $this->registered = true;

        add_action('fluent_boards/task_created', [$this, 'on_task_created'], 10, 1);
        add_action('fluent_boards/task_completed_activity', [$this, 'on_task_completed'], 10, 2);
        add_action('fluent_boards/task_stage_updated', [$this, 'on_task_stage_changed'], 10, 2);
        add_action('fluent_boards/task_date_changed', [$this, 'on_task_date_changed'], 10, 2);
        add_action('fluent_boards/task_due_date_changed', [$this, 'on_task_due_date_changed'], 10, 2);
        add_action('fluent_boards/task_priority_changed', [$this, 'on_task_priority_changed'], 10, 2);
        add_action('fluent_boards/task_label', [$this, 'on_task_label_changed'], 10, 3);
        add_action('fluent_boards/comment_created', [$this, 'on_comment_created'], 10, 1);
        add_action('fluent_boards/task_assignee_added', [$this, 'on_assignee_added'], 10, 2);
        add_action('fluent_boards/task_archived', [$this, 'on_task_archived'], 10, 1);
        add_action('fluent_boards/board_stage_added', [$this, 'on_stage_added'], 10, 2);
        add_action('fluent_boards/subtask_added', [$this, 'on_subtask_added'], 10, 2);

        add_action('fluent_boards/repeat_task_created', [$this, 'on_repeat_task_created'], 10, 2);
        add_action('fluent_boards/task_custom_field_changed', [$this, 'on_custom_field_changed'], 10, 5);
        add_action('fluent_boards/task_attachment_added', [$this, 'on_task_attachment_added'], 10, 1);
        add_action('fluent_boards/task_attachment_deleted', [$this, 'on_task_attachment_deleted'], 10, 1);

        // Dependency events (Fluent Boards Pro). Dependencies and blockers are high-signal for
        // task intelligence, so the assistant can react when a task is blocked or unblocked.
        add_action('fluent_boards/task_dependency_added', [$this, 'on_task_dependency_added'], 10, 2);
        add_action('fluent_boards/task_dependency_removed', [$this, 'on_task_dependency_removed'], 10, 2);
    }

    public function on_task_dependency_added($task = null, $dependency = null)
    {
        $this->queue('task_dependency_added', $this->dependency_payload($task, $dependency));
    }

    public function on_task_dependency_removed($task = null, $dependency = null)
    {
        $this->queue('task_dependency_removed', $this->dependency_payload($task, $dependency));
    }

    /**
     * Build a normalized payload for dependency events. Fluent Boards may pass the task as an
     * object/array or as a numeric id, so handle both robustly.
     */
    private function dependency_payload($task, $dependency)
    {
        $payload = [];
        if (is_numeric($task)) {
            $payload['task_id'] = (int) $task;
        } elseif ($task !== null) {
            $payload['task'] = $task;
        }
        if (is_numeric($dependency)) {
            $payload['dependency_task_id'] = (int) $dependency;
        } elseif ($dependency !== null) {
            $payload['dependency'] = $dependency;
        }
        return $payload;
    }

    public function on_task_created($task)
    {
        $this->queue('task_created', ['task' => $task]);
    }

    public function on_task_completed($task, $value)
    {
        if ($value !== 'closed') {
            return;
        }
        $this->queue('task_completed', ['task' => $task, 'status' => $value]);
    }

    public function on_task_stage_changed($task, $old_stage_id)
    {
        $this->queue('task_stage_changed', ['task' => $task, 'old_stage_id' => $old_stage_id]);
    }

    public function on_task_date_changed($task, $old_dates)
    {
        $this->queue('task_date_changed', ['task' => $task, 'old_dates' => $old_dates]);
    }

    public function on_task_due_date_changed($task, $old_started_at)
    {
        $this->queue('task_date_changed', ['task' => $task, 'old_started_at' => $old_started_at]);
    }

    public function on_task_priority_changed($task, $old_priority)
    {
        $this->queue('task_priority_changed', ['task' => $task, 'old_priority' => $old_priority]);
    }

    public function on_task_label_changed($task, $label, $action)
    {
        $this->queue('task_label_changed', ['task' => $task, 'label' => $label, 'label_action' => $action]);
    }

    public function on_comment_created($comment)
    {
        $payload = ['comment' => $comment];
        if (FBAIA_Helpers::payload_contains_generated_marker($payload)) {
            return;
        }
        $this->queue('comment_created', $payload);
    }

    public function on_assignee_added($task, $assignee)
    {
        $this->queue('assignee_added', ['task' => $task, 'assignee' => $assignee]);
    }

    public function on_task_archived($task)
    {
        $this->queue('task_archived', ['task' => $task]);
    }

    public function on_stage_added($stage, $board)
    {
        $this->queue('stage_added', ['stage' => $stage, 'board' => $board]);
    }

    public function on_subtask_added($parent_task, $subtask)
    {
        $this->queue('subtask_added', ['parent_task' => $parent_task, 'subtask' => $subtask]);
    }

    public function on_repeat_task_created($new_task, $source_task)
    {
        $this->queue('repeat_task_created', ['task' => $new_task, 'source_task' => $source_task]);
    }

    public function on_custom_field_changed($task_id, $custom_field, $old_value, $processed_value, $is_new_custom_field)
    {
        $this->queue('custom_field_changed', [
            'task_id' => $task_id,
            'custom_field' => $custom_field,
            'old_value' => $old_value,
            'new_value' => $processed_value,
            'is_new_custom_field' => (bool) $is_new_custom_field,
        ]);
    }

    public function on_task_attachment_added($attachment)
    {
        $this->queue('task_attachment_added', ['attachment' => $attachment]);
    }

    public function on_task_attachment_deleted($attachment)
    {
        $this->queue('task_attachment_deleted', ['attachment' => $attachment]);
    }

    public function queue($event, array $payload, $force = false)
    {
        $settings = FBAIA_Helpers::get_settings();
        if (($settings['enabled'] ?? 'no') !== 'yes') {
            return ['queued' => false, 'reason' => 'disabled'];
        }

        $event = sanitize_key($event);
        // Queue the event when it is an enabled trigger OR an enabled automation recipe targets
        // it — so recipes can react to events even if the global AI trigger is off.
        $recipe_match = (($settings['recipes_enabled'] ?? 'no') === 'yes')
            && class_exists('FBAIA_Recipes')
            && FBAIA_Recipes::has_enabled_for_event($event);
        if (!$force && !$recipe_match && !in_array($event, $settings['triggers'] ?? [], true)) {
            return ['queued' => false, 'reason' => 'trigger_not_enabled', 'event' => $event];
        }

        if (FBAIA_Helpers::payload_contains_generated_marker($payload)) {
            return ['queued' => false, 'reason' => 'generated_comment_ignored', 'event' => $event];
        }

        $payload = FBAIA_Helpers::normalize($payload);
        $board_id = FBAIA_Helpers::extract_board_id($payload);
        if ($board_id && !FBAIA_Helpers::board_is_allowed($board_id, $settings['board_allowlist'] ?? '')) {
            return ['queued' => false, 'reason' => 'board_not_allowed', 'event' => $event, 'board_id' => $board_id];
        }

        $fingerprint = FBAIA_Helpers::event_fingerprint($event, $payload);
        $dedupe_window = max(0, (int) ($settings['dedupe_window_seconds'] ?? 60));
        $dedupe_key = '';
        if ($dedupe_window > 0) {
            $dedupe_key = 'fbaia_dedupe_' . $fingerprint;
            if (get_transient($dedupe_key)) {
                FBAIA_Logger::add('info', 'Duplicate event skipped', ['event' => $event, 'task_id' => FBAIA_Helpers::extract_task_id($payload), 'board_id' => $board_id]);
                return ['queued' => false, 'reason' => 'duplicate', 'event' => $event];
            }
        }

        if (function_exists('as_enqueue_async_action')) {
            $scheduled = as_enqueue_async_action('fbaia_process_event', [$event, $payload], 'fluent-boards-ai-automation', true);
        } else {
            $scheduled = wp_schedule_single_event(time() + 5, 'fbaia_process_event', [$event, $payload]);
        }

        if (is_wp_error($scheduled) || false === $scheduled) {
            $message = is_wp_error($scheduled) ? $scheduled->get_error_message() : 'WordPress did not schedule the event.';
            FBAIA_Logger::add('error', 'Could not queue internal automation event', [
                'event' => $event,
                'task_id' => FBAIA_Helpers::extract_task_id($payload),
                'board_id' => $board_id,
                'error' => $message,
            ]);
            return ['queued' => false, 'reason' => 'schedule_failed', 'event' => $event, 'error' => $message];
        }

        if ($dedupe_key && $dedupe_window > 0) {
            set_transient($dedupe_key, 1, $dedupe_window);
        }

        FBAIA_Logger::add('info', 'Queued internal automation event', [
            'event' => $event,
            'task_id' => FBAIA_Helpers::extract_task_id($payload),
            'board_id' => $board_id,
            'fingerprint' => $fingerprint,
            'force' => (bool) $force,
        ]);

        return ['queued' => true, 'event' => $event, 'task_id' => FBAIA_Helpers::extract_task_id($payload), 'board_id' => $board_id];
    }

    public function process_event($event, $payload)
    {
        $settings = FBAIA_Helpers::get_settings();
        if (($settings['enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $event = sanitize_key($event);
        $payload = is_array($payload) ? FBAIA_Helpers::normalize($payload) : [];
        if (FBAIA_Helpers::payload_contains_generated_marker($payload)) {
            return;
        }

        // Deterministic automation recipes (monday-style) run independently of the AI layer.
        if (class_exists('FBAIA_Recipes') && ($settings['recipes_enabled'] ?? 'no') === 'yes') {
            FBAIA_Recipes::run($event, $payload);
        }

        $mode = $settings['mode'] ?? 'internal_ai_actions';
        if ($mode === 'internal_log_only') {
            FBAIA_Logger::add('info', 'Processed event in log-only mode', ['event' => $event, 'task_id' => FBAIA_Helpers::extract_task_id($payload)]);
            return;
        }

        $client = new FBAIA_OpenAI_Client($settings);
        $ai = $client->complete($event, $payload);
        if (is_wp_error($ai)) {
            FBAIA_Logger::add('error', 'AI request failed', ['event' => $event, 'error' => $ai->get_error_message()]);
            return;
        }

        $ai_payload = $ai['parsed'] ?? [];
        if (class_exists('FBAIA_Automation_Playbooks')) {
            $playbooks = new FBAIA_Automation_Playbooks($settings);
            $ai_payload = $playbooks->enforce($event, $payload, is_array($ai_payload) ? $ai_payload : []);
        }
        $suggestion_id = '';
        if (class_exists('FBAIA_Suggestion_Store') && (($settings['store_ai_suggestions'] ?? 'yes') === 'yes')) {
            $suggestion_id = FBAIA_Suggestion_Store::record($event, $payload, $ai_payload, [
                'model' => $ai['model'] ?? '',
                'usage' => $ai['usage'] ?? [],
                'context_summary' => $ai['context_summary'] ?? [],
            ]);
            if ($suggestion_id) {
                $ai_payload['suggestion_id'] = $suggestion_id;
                $ai_payload['approval_status'] = 'pending_review';
            }
        }

        FBAIA_Logger::add('success', 'AI analysis generated', [
            'event' => $event,
            'task_id' => FBAIA_Helpers::extract_task_id($payload),
            'suggestion_id' => $suggestion_id,
            'model' => $ai['model'] ?? '',
            'usage' => $ai['usage'] ?? [],
            'context_summary' => $ai['context_summary'] ?? [],
        ]);

        $actions = new FBAIA_Internal_Actions($settings);
        $actions->apply($event, $payload, $ai_payload, $suggestion_id, false);
    }

    public function process_overdue_scan()
    {
        $actions = new FBAIA_Internal_Actions(FBAIA_Helpers::get_settings());
        $actions->process_overdue_scan();
    }

    public function send_daily_digest()
    {
        $actions = new FBAIA_Internal_Actions(FBAIA_Helpers::get_settings());
        $actions->send_daily_digest();
    }

    public function send_weekly_intelligence_digest()
    {
        $actions = new FBAIA_Internal_Actions(FBAIA_Helpers::get_settings());
        $actions->send_weekly_intelligence_digest();
    }
}
