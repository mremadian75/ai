<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Internal_Actions
{
    private $settings;
    private $adapter;

    public function __construct($settings = null, $adapter = null)
    {
        $this->settings = $settings ?: FBAIA_Helpers::get_settings();
        $this->adapter = $adapter ?: new FBAIA_FluentBoards_Adapter();
    }

    public function apply($event, array $payload, array $ai_payload, $suggestion_id = '', $manual_override = false)
    {
        $mode = $this->settings['mode'] ?? 'internal_ai_actions';
        if ($mode === 'internal_log_only') {
            $task_id = FBAIA_Helpers::extract_task_id($payload);
            FBAIA_Logger::add('info', 'Internal log-only mode: AI result stored in logs', [
                'event' => $event,
                'task_id' => $task_id,
                'summary' => $ai_payload['summary'] ?? '',
            ]);
            if (class_exists('FBAIA_Audit_Trail')) {
                FBAIA_Audit_Trail::record('ai_result_logged', ['event' => $event, 'task_id' => $task_id, 'summary' => $ai_payload['summary'] ?? '']);
            }
            return;
        }

        $policy = sanitize_key($this->settings['action_execution_policy'] ?? 'review_first');
        $review_first = ($policy === 'review_first' && !$manual_override);

        if (($this->settings['action_ai_comment'] ?? 'yes') === 'yes' || $mode === 'internal_ai_comment') {
            $this->write_ai_comment($event, $payload, $ai_payload);
        }

        if ($mode === 'internal_ai_comment') {
            return;
        }

        if ($review_first) {
            $held_task_id = FBAIA_Helpers::extract_task_id($payload);
            FBAIA_Logger::add('info', 'Auto-actions held for human review', [
                'event' => $event,
                'task_id' => $held_task_id,
                'suggestion_id' => $suggestion_id,
                'policy' => $policy,
            ]);
            if (class_exists('FBAIA_Audit_Trail')) {
                FBAIA_Audit_Trail::record('auto_actions_held', ['event' => $event, 'task_id' => $held_task_id, 'suggestion_id' => $suggestion_id, 'policy' => $policy]);
            }
            if (($this->settings['action_email_admin'] ?? 'no') === 'yes') {
                $this->maybe_email_admin($event, $payload, $ai_payload);
            }
            return;
        }

        if (($this->settings['action_update_priority'] ?? 'no') === 'yes') {
            $this->update_priority($event, $payload, $ai_payload);
        }

        if (($this->settings['action_create_subtasks'] ?? 'no') === 'yes') {
            $this->create_subtasks($event, $payload, $ai_payload);
        }

        if (($this->settings['action_email_admin'] ?? 'no') === 'yes') {
            $this->maybe_email_admin($event, $payload, $ai_payload);
        }
    }

    public function write_ai_comment($event, array $payload, array $ai_payload)
    {
        $task_id = FBAIA_Helpers::extract_task_id($payload);
        $board_id = FBAIA_Helpers::extract_board_id($payload);

        if (!$task_id && isset($payload['comment']['task_id'])) {
            $task_id = absint($payload['comment']['task_id']);
        }
        if (!$board_id && isset($payload['comment']['board_id'])) {
            $board_id = absint($payload['comment']['board_id']);
        }

        if (!$board_id && $task_id) {
            $task = $this->adapter->get_task($task_id);
            $task_array = $task ? $this->adapter->task_to_array($task) : [];
            if (!empty($task_array['board_id'])) {
                $board_id = absint($task_array['board_id']);
            }
        }

        if (!$task_id || !$board_id) {
            FBAIA_Logger::add('warning', 'Could not write AI comment because task or board ID was missing', ['event' => $event]);
            if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('comment_skipped', ['event' => $event, 'status' => 'missing_task_or_board']); }
            return;
        }

        if (!$this->should_write_comment($event, $ai_payload)) {
            FBAIA_Logger::add('info', 'AI comment skipped because result was low-actionability or below comment threshold', [
                'event' => $event,
                'task_id' => $task_id,
                'confidence' => $ai_payload['confidence'] ?? null,
                'skip_comment' => FBAIA_Helpers::to_bool($ai_payload['skip_comment'] ?? false),
            ]);
            if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('comment_skipped', ['event' => $event, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'low_actionability']); }
            return;
        }

        $cooldown_minutes = max(0, min(1440, (int) ($this->settings['comment_cooldown_minutes'] ?? 15)));
        $is_high_signal = !empty($ai_payload['risk_flags']) || !empty($ai_payload['blocked_by']) || (($ai_payload['recommended_priority'] ?? '') === 'urgent') || (($ai_payload['decision_quality']['risk_score'] ?? 0) >= 0.75);
        $cooldown_key = '';
        $cooldown_ttl = 0;
        if ($cooldown_minutes > 0 && !$is_high_signal) {
            $cooldown_key = 'fbaia_comment_cooldown_' . md5($task_id . '|' . $event);
            $cooldown_ttl = $cooldown_minutes * MINUTE_IN_SECONDS;
            if (get_transient($cooldown_key)) {
                FBAIA_Logger::add('info', 'AI comment skipped because task/event cooldown is active', ['event' => $event, 'task_id' => $task_id, 'cooldown_minutes' => $cooldown_minutes]);
                if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('comment_skipped', ['event' => $event, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'cooldown']); }
                return;
            }
        }

        $privacy = ($this->settings['write_private_comment'] ?? 'yes') === 'yes' ? 'private' : 'public';
        $comment = $this->adapter->format_ai_comment($ai_payload, $event);
        $created = $this->adapter->create_ai_comment($task_id, $board_id, $comment, $privacy);

        if (is_wp_error($created)) {
            FBAIA_Logger::add('error', 'AI comment write-back failed', ['event' => $event, 'error' => $created->get_error_message()]);
            if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('comment_failed', ['event' => $event, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'failed', 'error' => $created->get_error_message()]); }
            return;
        }

        if ($cooldown_key && $cooldown_ttl > 0) {
            set_transient($cooldown_key, 1, $cooldown_ttl);
        }

        FBAIA_Logger::add('success', 'AI comment added to task', ['event' => $event, 'task_id' => $task_id, 'board_id' => $board_id]);
        if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('comment_written', ['event' => $event, 'task_id' => $task_id, 'board_id' => $board_id, 'status' => 'success', 'confidence' => $ai_payload['confidence'] ?? null, 'suggestion_id' => $ai_payload['suggestion_id'] ?? '']); }
    }

    private function should_write_comment($event, array $ai_payload)
    {
        if (($this->settings['suppress_low_value_comments'] ?? 'yes') !== 'yes') {
            return true;
        }

        if (FBAIA_Helpers::to_bool($ai_payload['skip_comment'] ?? false)) {
            return false;
        }

        $minimum = max(0, min(1, (float) ($this->settings['minimum_comment_confidence'] ?? 0.35)));
        $confidence = isset($ai_payload['confidence']) ? (float) $ai_payload['confidence'] : 0.5;
        $has_actionable_content = !empty($ai_payload['next_actions'])
            || !empty($ai_payload['work_breakdown'])
            || !empty($ai_payload['risk_flags'])
            || !empty($ai_payload['dependencies'])
            || !empty($ai_payload['blocked_by'])
            || !empty($ai_payload['clarifying_questions'])
            || !empty($ai_payload['recommended_priority']);

        if (!$has_actionable_content && $confidence < $minimum) {
            return false;
        }

        return true;
    }

    private function update_priority($event, array $payload, array $ai_payload)
    {
        $task_id = FBAIA_Helpers::extract_task_id($payload);
        $priority = $ai_payload['recommended_priority'] ?? null;

        if (!$task_id || !$priority) {
            return;
        }

        $threshold = max(0, min(1, (float) ($this->settings['confidence_threshold'] ?? 0.55)));
        $confidence = isset($ai_payload['confidence']) ? (float) $ai_payload['confidence'] : 0.5;
        if ($confidence < $threshold) {
            FBAIA_Logger::add('info', 'Priority update skipped because AI confidence was below threshold', [
                'event' => $event,
                'task_id' => $task_id,
                'confidence' => $confidence,
                'threshold' => $threshold,
            ]);
            return;
        }

        if (!FBAIA_Helpers::to_bool($ai_payload['automation_safe'] ?? false)) {
            FBAIA_Logger::add('info', 'Priority update skipped because AI did not mark automation as safe', [
                'event' => $event,
                'task_id' => $task_id,
                'priority' => $priority,
            ]);
            return;
        }

        if (!empty($ai_payload['manager_decision']) && is_array($ai_payload['manager_decision']) && !FBAIA_Helpers::to_bool($ai_payload['manager_decision']['approve_priority_change'] ?? false)) {
            FBAIA_Logger::add('info', 'Priority update skipped because manager_decision did not recommend approval', [
                'event' => $event,
                'task_id' => $task_id,
                'priority' => $priority,
            ]);
            return;
        }

        $updated = $this->adapter->update_task_priority($task_id, $priority);
        if (is_wp_error($updated)) {
            FBAIA_Logger::add('error', 'Priority update failed', ['event' => $event, 'task_id' => $task_id, 'error' => $updated->get_error_message()]);
            if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('priority_update_failed', ['event' => $event, 'task_id' => $task_id, 'priority' => $priority, 'status' => 'failed', 'error' => $updated->get_error_message()]); }
            return;
        }

        FBAIA_Logger::add(!empty($updated['updated']) ? 'success' : 'info', 'Priority automation processed', [
            'event' => $event,
            'task_id' => $task_id,
            'priority' => $priority,
            'updated' => !empty($updated['updated']),
        ]);
        if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('priority_updated', ['event' => $event, 'task_id' => $task_id, 'priority' => $priority, 'status' => !empty($updated['updated']) ? 'updated' : 'no_change']); }
    }

    private function create_subtasks($event, array $payload, array $ai_payload)
    {
        $task_id = FBAIA_Helpers::extract_task_id($payload);
        if (!$task_id) {
            return;
        }

        // Avoid creating subtasks for every small event. This is intentionally conservative.
        if (!in_array($event, ['task_created', 'external_event'], true)) {
            return;
        }

        $threshold = max(0, min(1, (float) ($this->settings['confidence_threshold'] ?? 0.55)));
        $confidence = isset($ai_payload['confidence']) ? (float) $ai_payload['confidence'] : 0.5;
        if ($confidence < $threshold) {
            FBAIA_Logger::add('info', 'Subtask creation skipped because AI confidence was below threshold', [
                'event' => $event,
                'task_id' => $task_id,
                'confidence' => $confidence,
                'threshold' => $threshold,
            ]);
            return;
        }

        if (!FBAIA_Helpers::to_bool($ai_payload['automation_safe'] ?? false)) {
            FBAIA_Logger::add('info', 'Subtask creation skipped because AI did not mark automation as safe', [
                'event' => $event,
                'task_id' => $task_id,
            ]);
            return;
        }

        if (!empty($ai_payload['manager_decision']) && is_array($ai_payload['manager_decision']) && !FBAIA_Helpers::to_bool($ai_payload['manager_decision']['approve_subtasks'] ?? false)) {
            FBAIA_Logger::add('info', 'Subtask creation skipped because manager_decision did not recommend approval', [
                'event' => $event,
                'task_id' => $task_id,
            ]);
            return;
        }

        $items = $ai_payload['internal_actions']['create_subtasks'] ?? [];
        if (empty($items) && !empty($ai_payload['work_breakdown']) && is_array($ai_payload['work_breakdown'])) {
            $items = array_filter(array_map(function ($item) {
                if (is_array($item)) {
                    return $item['title'] ?? '';
                }
                return is_scalar($item) ? (string) $item : '';
            }, $ai_payload['work_breakdown']));
        }
        if (empty($items) && !empty($ai_payload['next_actions'])) {
            $items = $ai_payload['next_actions'];
        }
        if (!is_array($items) || empty($items)) {
            return;
        }

        $max = max(1, min(20, (int) ($this->settings['max_subtasks_per_task'] ?? 5)));
        $created_count = 0;
        foreach (array_slice($items, 0, $max) as $title) {
            $title = sanitize_text_field((string) $title);
            if ($title === '') {
                continue;
            }
            $created = $this->adapter->create_subtask($task_id, $title);
            if (is_wp_error($created)) {
                FBAIA_Logger::add('error', 'Subtask automation failed', ['event' => $event, 'task_id' => $task_id, 'title' => $title, 'error' => $created->get_error_message()]);
                continue;
            }
            $created_count++;
        }

        if ($created_count > 0) {
            FBAIA_Logger::add('success', 'Subtasks created by AI automation', ['event' => $event, 'task_id' => $task_id, 'count' => $created_count]);
            if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('subtasks_created', ['event' => $event, 'task_id' => $task_id, 'count' => $created_count, 'status' => 'success']); }
        }
    }

    private function maybe_email_admin($event, array $payload, array $ai_payload)
    {
        $notify_email = sanitize_email($this->settings['notify_email'] ?? get_option('admin_email'));
        if (!$notify_email) {
            return;
        }

        $notify_only_risky = ($this->settings['notify_only_risky'] ?? 'yes') === 'yes';
        $should_notify = FBAIA_Helpers::to_bool($ai_payload['internal_actions']['notify_admin'] ?? false) || !empty($ai_payload['risk_flags']) || !empty($ai_payload['blocked_by']) || (($ai_payload['recommended_priority'] ?? '') === 'urgent') || (($ai_payload['decision_quality']['risk_score'] ?? 0) >= 0.75);
        if ($notify_only_risky && !$should_notify) {
            return;
        }

        $task_id = FBAIA_Helpers::extract_task_id($payload);
        $title = FBAIA_Helpers::extract_task_title($payload);
        $subject = sprintf('[%s] Fluent Boards AI alert%s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $task_id ? ' #' . $task_id : '');
        $body = $this->build_email_body($event, $task_id, $title, $ai_payload);

        $sent = wp_mail($notify_email, $subject, $body);
        FBAIA_Logger::add($sent ? 'success' : 'error', $sent ? 'Admin notification email sent' : 'Admin notification email failed', [
            'event' => $event,
            'task_id' => $task_id,
            'email' => $notify_email,
        ]);
        if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('admin_email_sent', ['event' => $event, 'task_id' => $task_id, 'email' => $notify_email, 'status' => $sent ? 'sent' : 'failed']); }
    }

    private function build_email_body($event, $task_id, $title, array $ai_payload)
    {
        $lines = [];
        $lines[] = 'Fluent Boards AI Automation';
        $lines[] = 'Event: ' . sanitize_key($event);
        if ($task_id) {
            $lines[] = 'Task ID: ' . (int) $task_id;
        }
        if ($title) {
            $lines[] = 'Task: ' . $title;
        }
        $lines[] = '';
        $lines[] = 'Summary:';
        $lines[] = $ai_payload['summary'] ?? '';
        if (!empty($ai_payload['recommended_priority'])) {
            $lines[] = '';
            $lines[] = 'Recommended priority: ' . $ai_payload['recommended_priority'];
        }
        if (!empty($ai_payload['risk_flags'])) {
            $lines[] = '';
            $lines[] = 'Risk flags:';
            foreach ($ai_payload['risk_flags'] as $risk) {
                $lines[] = '- ' . $risk;
            }
        }
        if (!empty($ai_payload['blocked_by'])) {
            $lines[] = '';
            $lines[] = 'Blocked by:';
            foreach ($ai_payload['blocked_by'] as $blocker) {
                $lines[] = '- ' . $blocker;
            }
        }
        if (!empty($ai_payload['owner_recommendation']) && is_array($ai_payload['owner_recommendation'])) {
            $owner = $ai_payload['owner_recommendation'];
            if (!empty($owner['name']) || !empty($owner['role']) || !empty($owner['reason'])) {
                $lines[] = '';
                $lines[] = 'Suggested owner: ' . trim(($owner['name'] ?? '') . ' | ' . ($owner['role'] ?? '') . ' | ' . ($owner['email'] ?? ''));
                if (!empty($owner['reason'])) {
                    $lines[] = 'Owner reason: ' . $owner['reason'];
                }
            }
        }
        if (!empty($ai_payload['next_actions'])) {
            $lines[] = '';
            $lines[] = 'Next actions:';
            foreach ($ai_payload['next_actions'] as $action) {
                $lines[] = '- ' . $action;
            }
        }
        if (!empty($ai_payload['acceptance_criteria'])) {
            $lines[] = '';
            $lines[] = 'Acceptance criteria:';
            foreach ($ai_payload['acceptance_criteria'] as $item) {
                $lines[] = '- ' . $item;
            }
        }
        if (!empty($ai_payload['clarifying_questions'])) {
            $lines[] = '';
            $lines[] = 'Clarifying questions:';
            foreach ($ai_payload['clarifying_questions'] as $item) {
                $lines[] = '- ' . $item;
            }
        }
        $lines[] = '';
        $lines[] = 'Site: ' . site_url();
        return implode("\n", $lines);
    }

    public function process_overdue_scan()
    {
        if (($this->settings['overdue_scan_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $limit = (int) ($this->settings['overdue_scan_limit'] ?? 25);
        $tasks = $this->adapter->get_due_tasks(gmdate('Y-m-d H:i:s'), $limit, $this->settings['board_allowlist'] ?? '');
        if (is_wp_error($tasks)) {
            FBAIA_Logger::add('error', 'Overdue scan failed', ['error' => $tasks->get_error_message()]);
            return;
        }

        $count = 0;
        foreach ($tasks as $task) {
            $task_array = $this->adapter->task_to_array($task);
            $task_id = isset($task_array['id']) ? absint($task_array['id']) : 0;
            $board_id = isset($task_array['board_id']) ? absint($task_array['board_id']) : 0;
            if (!$task_id || !$board_id) {
                continue;
            }

            $fingerprint = 'fbaia_overdue_' . md5($task_id . '|' . gmdate('Y-m-d'));
            if (get_transient($fingerprint)) {
                continue;
            }
            set_transient($fingerprint, 1, DAY_IN_SECONDS);

            if (($this->settings['overdue_comment_enabled'] ?? 'yes') === 'yes') {
                $content = FBAIA_Helpers::GENERATED_MARKER . "\n<strong>AI Automation Reminder</strong>\n<p>This task appears to be overdue. Please review owner, deadline, and next action.</p>\n<p><small>" . esc_html(self::class) . ' · ' . esc_html(FBAIA_FluentBoards_Adapter::GENERATED_TEXT_MARKER) . '</small></p>';
                $privacy = ($this->settings['write_private_comment'] ?? 'yes') === 'yes' ? 'private' : 'public';
                $created = $this->adapter->create_ai_comment($task_id, $board_id, $content, $privacy);
                if (is_wp_error($created)) {
                    FBAIA_Logger::add('error', 'Overdue reminder comment failed', ['task_id' => $task_id, 'error' => $created->get_error_message()]);
                } else {
                    $count++;
                }
            }
        }

        if (($this->settings['overdue_email_enabled'] ?? 'no') === 'yes' && $count > 0) {
            $email = sanitize_email($this->settings['notify_email'] ?? get_option('admin_email'));
            if ($email) {
                wp_mail($email, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Fluent Boards overdue scan', 'Overdue scan processed ' . $count . ' task reminder(s).');
            }
        }

        FBAIA_Logger::add('info', 'Overdue scan completed', ['processed' => $count]);
        if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('overdue_scan_completed', ['processed' => $count, 'status' => 'complete']); }
    }

    public function send_daily_digest()
    {
        if (($this->settings['daily_digest_enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $email = sanitize_email($this->settings['daily_digest_email'] ?? get_option('admin_email'));
        if (!$email) {
            FBAIA_Logger::add('warning', 'Daily digest skipped because email is empty');
            return;
        }

        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $tasks = $this->adapter->get_recent_tasks($since, 50, $this->settings['board_allowlist'] ?? '');
        if (is_wp_error($tasks)) {
            FBAIA_Logger::add('error', 'Daily digest query failed', ['error' => $tasks->get_error_message()]);
            return;
        }

        $lines = [];
        $lines[] = 'Fluent Boards Daily Digest';
        $lines[] = 'Site: ' . site_url();
        $lines[] = 'Window: last 24 hours';
        $lines[] = '';

        if (empty($tasks)) {
            $lines[] = 'No recently updated tasks found.';
        } else {
            foreach ($tasks as $task) {
                $data = $this->adapter->task_to_array($task);
                $parts = [];
                $parts[] = '#' . (isset($data['id']) ? absint($data['id']) : '?');
                $parts[] = isset($data['title']) ? sanitize_text_field($data['title']) : 'Untitled task';
                if (!empty($data['status'])) {
                    $parts[] = 'status: ' . sanitize_text_field($data['status']);
                }
                if (!empty($data['priority'])) {
                    $parts[] = 'priority: ' . sanitize_text_field($data['priority']);
                }
                if (!empty($data['due_at'])) {
                    $parts[] = 'due: ' . sanitize_text_field($data['due_at']);
                }
                $lines[] = '- ' . implode(' | ', $parts);
            }
        }

        $sent = wp_mail($email, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Fluent Boards daily digest', implode("\n", $lines));
        FBAIA_Logger::add($sent ? 'success' : 'error', $sent ? 'Daily digest email sent' : 'Daily digest email failed', ['email' => $email, 'tasks' => is_array($tasks) ? count($tasks) : 0]);
        if (class_exists('FBAIA_Audit_Trail')) { FBAIA_Audit_Trail::record('daily_digest_sent', ['email' => $email, 'tasks' => is_array($tasks) ? count($tasks) : 0, 'status' => $sent ? 'sent' : 'failed']); }
    }

    public function send_weekly_intelligence_digest()
    {
        if (($this->settings['weekly_intelligence_enabled'] ?? 'no') !== 'yes') {
            return;
        }
        if (!class_exists('FBAIA_Insights')) {
            FBAIA_Logger::add('warning', 'Weekly intelligence digest skipped because insights engine is missing');
            return;
        }
        FBAIA_Insights::send_weekly_digest($this->settings['weekly_intelligence_email'] ?? '');
    }
}
