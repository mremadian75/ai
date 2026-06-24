<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_FluentBoards_Adapter
{
    const GENERATED_TEXT_MARKER = 'FBAIA_GENERATED_COMMENT';

    public function is_available()
    {
        return class_exists('FluentBoards\\App\\Models\\Task') || did_action('fluent_boards_loaded');
    }

    public function create_ai_comment($task_id, $board_id, $content, $privacy = 'private')
    {
        $task_id = absint($task_id);
        $board_id = absint($board_id);
        $content = wp_kses_post($content);

        if (!$task_id || !$board_id || $content === '') {
            return new WP_Error('invalid_comment_data', __('Missing task ID, board ID, or comment content.', 'fluent-boards-ai-automation'));
        }

        if (!class_exists('FluentBoards\\App\\Models\\Comment')) {
            return new WP_Error('comment_model_missing', __('Fluent Boards Comment model is not available.', 'fluent-boards-ai-automation'));
        }

        $user_id = $this->get_bot_user_id();
        $user = $user_id ? get_userdata($user_id) : null;
        $author_name = $user && !empty($user->display_name) ? $user->display_name : 'AI Automation';
        $author_email = $user && !empty($user->user_email) ? $user->user_email : get_option('admin_email');

        $data = [
            'task_id'      => $task_id,
            'board_id'     => $board_id,
            'description'  => $content,
            'type'         => 'comment',
            'privacy'      => $privacy === 'public' ? 'public' : 'private',
            'status'       => 'published',
            'author_name'  => $author_name,
            'author_email' => $author_email,
            'author_ip'    => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'created_by'   => $user_id,
            'settings'     => [
                'raw_description' => wp_strip_all_tags($content),
                'source' => 'fluent_boards_ai_automation',
                'marker' => self::GENERATED_TEXT_MARKER,
            ],
        ];

        try {
            $class = 'FluentBoards\\App\\Models\\Comment';
            $comment = $class::create($data);
            $this->increment_task_comment_count($task_id);
            do_action('fbaia/comment_created', $comment, $task_id, $board_id);
            return $comment;
        } catch (Throwable $e) {
            return new WP_Error('comment_create_failed', $e->getMessage());
        }
    }

    public function format_ai_comment(array $ai, $event)
    {
        $lines = [];
        $lines[] = FBAIA_Helpers::GENERATED_MARKER;
        $lines[] = '<strong>AI Project Intelligence</strong>';
        if (!empty($ai['suggestion_id'])) {
            $status = !empty($ai['approval_status']) ? sanitize_key($ai['approval_status']) : 'pending_review';
            $lines[] = '<p><em>Suggestion ID:</em> <code>' . esc_html($ai['suggestion_id']) . '</code> · <strong>' . esc_html($status) . '</strong></p>';
        }

        if (!empty($ai['summary'])) {
            $lines[] = '<p><strong>Summary:</strong> ' . esc_html($ai['summary']) . '</p>';
        }

        if (!empty($ai['why_this_matters'])) {
            $lines[] = '<p><strong>Why it matters:</strong> ' . esc_html($ai['why_this_matters']) . '</p>';
        }

        if (!empty($ai['decision_brief']) && is_array($ai['decision_brief'])) {
            $brief = $ai['decision_brief'];
            if (!empty($brief['headline']) || !empty($brief['recommended_manager_action']) || !empty($brief['do_next'])) {
                $lines[] = '<div class="fbaia-decision-brief"><p><strong>Decision brief:</strong> ' . esc_html($brief['headline'] ?? '') . '</p>'
                    . (!empty($brief['recommended_manager_action']) ? '<p><small><strong>Manager action:</strong> ' . esc_html($brief['recommended_manager_action']) . '</small></p>' : '')
                    . (!empty($brief['do_next']) ? '<p><small><strong>Do next:</strong> ' . esc_html($brief['do_next']) . '</small></p>' : '')
                    . '</div>';
            }
        }

        $meta = [];
        if (!empty($ai['recommended_priority'])) {
            $meta[] = '<strong>Priority:</strong> ' . esc_html($ai['recommended_priority']);
        }
        if (isset($ai['confidence'])) {
            $meta[] = '<strong>Confidence:</strong> ' . esc_html(round((float) $ai['confidence'] * 100)) . '%';
        }
        if (!empty($ai['impact'])) {
            $meta[] = '<strong>Impact:</strong> ' . esc_html($ai['impact']);
        }
        if (!empty($ai['effort'])) {
            $meta[] = '<strong>Effort:</strong> ' . esc_html($ai['effort']);
        }
        if (!empty($meta)) {
            $lines[] = '<p>' . implode(' &nbsp;|&nbsp; ', $meta) . '</p>';
        }

        if (!empty($ai['task_quality']) && is_array($ai['task_quality'])) {
            $quality = $ai['task_quality'];
            $parts = [];
            if (isset($quality['score'])) {
                $parts[] = '<strong>Task quality:</strong> ' . esc_html(round((float) $quality['score'] * 100)) . '%';
            }
            if (!empty($quality['grade'])) {
                $parts[] = '<strong>Grade:</strong> ' . esc_html($quality['grade']);
            }
            if (!empty($parts)) {
                $lines[] = '<p>' . implode(' &nbsp;|&nbsp; ', $parts) . '</p>';
            }
            if (!empty($quality['reason'])) {
                $lines[] = '<p><small><strong>Task quality reason:</strong> ' . esc_html($quality['reason']) . '</small></p>';
            }
            $this->append_list($lines, 'Task quality gaps', $quality['missing_fields'] ?? []);
            $this->append_list($lines, 'Task quality improvements', $quality['improvement_suggestions'] ?? []);
        }

        if (!empty($ai['ai_council']) && is_array($ai['ai_council'])) {
            $lines[] = '<p><strong>AI council:</strong></p><ul>';
            foreach (array_slice($ai['ai_council'], 0, 5) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = !empty($item['lens']) ? esc_html($item['lens']) . ': ' : '';
                $observation = esc_html($item['observation'] ?? '');
                $recommendation = !empty($item['recommendation']) ? '<br><small>' . esc_html($item['recommendation']) . '</small>' : '';
                $risk = !empty($item['risk']) ? ' <small>[' . esc_html($item['risk']) . ' risk]</small>' : '';
                if ($observation || $recommendation) {
                    $lines[] = '<li><strong>' . $label . '</strong>' . $observation . $risk . $recommendation . '</li>';
                }
            }
            $lines[] = '</ul>';
        }

        if (!empty($ai['raci']) && is_array($ai['raci'])) {
            $raci = $ai['raci'];
            $parts = [];
            foreach (['responsible' => 'Responsible', 'accountable' => 'Accountable'] as $key => $label) {
                if (!empty($raci[$key])) {
                    $parts[] = '<strong>' . esc_html($label) . ':</strong> ' . esc_html($raci[$key]);
                }
            }
            if (!empty($raci['consulted']) && is_array($raci['consulted'])) {
                $parts[] = '<strong>Consulted:</strong> ' . esc_html(implode(', ', array_slice($raci['consulted'], 0, 5)));
            }
            if (!empty($raci['informed']) && is_array($raci['informed'])) {
                $parts[] = '<strong>Informed:</strong> ' . esc_html(implode(', ', array_slice($raci['informed'], 0, 5)));
            }
            if (!empty($parts)) {
                $lines[] = '<p>' . implode('<br>', $parts) . (!empty($raci['reason']) ? '<br><small>' . esc_html($raci['reason']) . '</small>' : '') . '</p>';
            }
        }

        if (!empty($ai['meeting_recommendation']) && is_array($ai['meeting_recommendation'])) {
            $meeting = $ai['meeting_recommendation'];
            $needed = FBAIA_Helpers::to_bool($meeting['needed'] ?? false) ? 'Yes' : 'No';
            $type = !empty($meeting['type']) ? ' · ' . esc_html($meeting['type']) : '';
            $lines[] = '<p><strong>Meeting needed:</strong> ' . esc_html($needed) . $type . (!empty($meeting['reason']) ? '<br><small>' . esc_html($meeting['reason']) . '</small>' : '') . '</p>';
            $this->append_list($lines, 'Meeting agenda', $meeting['agenda'] ?? []);
        }

        if (!empty($ai['decision_quality']) && is_array($ai['decision_quality'])) {
            $quality = $ai['decision_quality'];
            $quality_parts = [];
            if (!empty($quality['task_type'])) {
                $quality_parts[] = '<strong>Type:</strong> ' . esc_html($quality['task_type']);
            }
            foreach (['urgency_score' => 'Urgency', 'impact_score' => 'Impact score', 'effort_score' => 'Effort score', 'risk_score' => 'Risk score'] as $key => $label) {
                if (isset($quality[$key])) {
                    $quality_parts[] = '<strong>' . esc_html($label) . ':</strong> ' . esc_html(round((float) $quality[$key] * 100)) . '%';
                }
            }
            if (!empty($quality_parts)) {
                $lines[] = '<p>' . implode(' &nbsp;|&nbsp; ', $quality_parts) . '</p>';
            }
            if (!empty($quality['confidence_reason'])) {
                $lines[] = '<p><small><strong>Confidence reason:</strong> ' . esc_html($quality['confidence_reason']) . '</small></p>';
            }
        }

        if (!empty($ai['internal_actions']['priority_reason'])) {
            $lines[] = '<p><small><strong>Priority reason:</strong> ' . esc_html($ai['internal_actions']['priority_reason']) . '</small></p>';
        }

        if (!empty($ai['manager_decision']) && is_array($ai['manager_decision'])) {
            $manager = $ai['manager_decision'];
            $parts = [];
            $parts[] = '<strong>Priority approval:</strong> ' . (FBAIA_Helpers::to_bool($manager['approve_priority_change'] ?? false) ? 'recommended' : 'not recommended');
            $parts[] = '<strong>Subtask approval:</strong> ' . (FBAIA_Helpers::to_bool($manager['approve_subtasks'] ?? false) ? 'recommended' : 'not recommended');
            $lines[] = '<p>' . implode(' &nbsp;|&nbsp; ', $parts) . (!empty($manager['reason']) ? '<br><small>' . esc_html($manager['reason']) . '</small>' : '') . '</p>';
        }

        if (!empty($ai['owner_recommendation']) && is_array($ai['owner_recommendation'])) {
            $owner = $ai['owner_recommendation'];
            $owner_parts = [];
            if (!empty($owner['name'])) {
                $owner_parts[] = esc_html($owner['name']);
            }
            if (!empty($owner['role'])) {
                $owner_parts[] = esc_html($owner['role']);
            }
            if (!empty($owner['email'])) {
                $owner_parts[] = esc_html($owner['email']);
            }
            if (!empty($owner_parts) || !empty($owner['reason'])) {
                $lines[] = '<p><strong>Suggested owner:</strong> ' . implode(' · ', $owner_parts) . (!empty($owner['reason']) ? '<br><small>' . esc_html($owner['reason']) . '</small>' : '') . '</p>';
            }
        }

        if (!empty($ai['reviewer_recommendation']) && is_array($ai['reviewer_recommendation'])) {
            $reviewer = $ai['reviewer_recommendation'];
            $reviewer_parts = [];
            if (!empty($reviewer['name'])) {
                $reviewer_parts[] = esc_html($reviewer['name']);
            }
            if (!empty($reviewer['role'])) {
                $reviewer_parts[] = esc_html($reviewer['role']);
            }
            if (!empty($reviewer['email'])) {
                $reviewer_parts[] = esc_html($reviewer['email']);
            }
            if (!empty($reviewer_parts) || !empty($reviewer['reason'])) {
                $lines[] = '<p><strong>Suggested reviewer:</strong> ' . implode(' · ', $reviewer_parts) . (!empty($reviewer['reason']) ? '<br><small>' . esc_html($reviewer['reason']) . '</small>' : '') . '</p>';
            }
        }

        if (!empty($ai['due_date_suggestion']) && is_array($ai['due_date_suggestion'])) {
            $due = $ai['due_date_suggestion'];
            if (!empty($due['date']) || !empty($due['reason'])) {
                $lines[] = '<p><strong>Due date suggestion:</strong> ' . esc_html($due['date'] ?? 'No specific date') . (!empty($due['reason']) ? '<br><small>' . esc_html($due['reason']) . '</small>' : '') . '</p>';
            }
        }

        $this->append_list($lines, 'Next actions', $ai['next_actions'] ?? []);
        $this->append_work_breakdown($lines, $ai['work_breakdown'] ?? []);
        $this->append_list($lines, 'Acceptance criteria', $ai['acceptance_criteria'] ?? []);
        $this->append_list($lines, 'Quality gates', $ai['quality_gates'] ?? []);
        $this->append_list($lines, 'Dependencies', $ai['dependencies'] ?? []);
        $this->append_list($lines, 'Blocked by', $ai['blocked_by'] ?? []);
        $this->append_list($lines, 'Risk flags', $ai['risk_flags'] ?? []);
        $this->append_list($lines, 'Knowledge gaps', $ai['knowledge_gaps'] ?? []);
        $this->append_list($lines, 'Team coordination', $ai['team_coordination'] ?? []);
        if (!empty($ai['escalation']) && is_array($ai['escalation']) && !empty($ai['escalation']['needed'])) {
            $lines[] = '<p><strong>Escalation:</strong> ' . esc_html($ai['escalation']['role_or_person'] ?? '') . '<br><small>' . esc_html($ai['escalation']['reason'] ?? '') . '</small></p>';
        }
        $this->append_list($lines, 'Clarifying questions', $ai['clarifying_questions'] ?? []);

        if (!empty($ai['policy_controls']['matched_playbooks']) && is_array($ai['policy_controls']['matched_playbooks'])) {
            $playbook_names = [];
            foreach (array_slice($ai['policy_controls']['matched_playbooks'], 0, 4) as $item) {
                if (is_array($item) && !empty($item['name'])) {
                    $playbook_names[] = $item['name'];
                }
            }
            if (!empty($playbook_names)) {
                $lines[] = '<p><strong>Matched playbooks:</strong> ' . esc_html(implode(' · ', $playbook_names)) . '</p>';
            }
        }

        if (!empty($ai['context_used'])) {
            $lines[] = '<p><strong>Context used:</strong> ' . esc_html(implode(' · ', array_slice($ai['context_used'], 0, 6))) . '</p>';
        }

        $this->append_list($lines, 'Learning notes', $ai['learning_notes'] ?? []);

        if (!empty($ai['comment'])) {
            $lines[] = '<hr><p>' . wp_kses_post($ai['comment']) . '</p>';
        }
        $lines[] = '<p><small>Generated from event: ' . esc_html($event) . ' · ' . esc_html(self::GENERATED_TEXT_MARKER) . '</small></p>';
        return implode("\n", $lines);
    }

    private function append_list(array &$lines, $title, $items)
    {
        if (empty($items) || !is_array($items)) {
            return;
        }
        $lines[] = '<p><strong>' . esc_html($title) . ':</strong></p><ul>';
        foreach (array_slice($items, 0, 8) as $item) {
            $lines[] = '<li>' . esc_html($item) . '</li>';
        }
        $lines[] = '</ul>';
    }

    private function append_work_breakdown(array &$lines, $items)
    {
        if (empty($items) || !is_array($items)) {
            return;
        }
        $lines[] = '<p><strong>Work breakdown:</strong></p><ul>';
        foreach (array_slice($items, 0, 8) as $item) {
            if (is_scalar($item)) {
                $lines[] = '<li>' . esc_html($item) . '</li>';
                continue;
            }
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }
            $detail = esc_html($item['title']);
            if (!empty($item['owner_role'])) {
                $detail .= ' <small>(' . esc_html($item['owner_role']) . ')</small>';
            }
            if (!empty($item['acceptance'])) {
                $detail .= '<br><small>' . esc_html($item['acceptance']) . '</small>';
            }
            $lines[] = '<li>' . $detail . '</li>';
        }
        $lines[] = '</ul>';
    }

    public function get_task_context($task_id, $comment_limit = 8, $subtask_limit = 15)
    {
        $task_id = absint($task_id);
        $task = $this->get_task($task_id);
        if (!$task) {
            return ['available' => false, 'reason' => 'task_not_found', 'task_id' => $task_id];
        }

        $task_array = $this->task_to_array($task);
        $context = [
            'available' => true,
            'task_id' => $task_id,
            'task' => $task_array,
            'comments' => $this->get_recent_comments($task_id, $comment_limit),
            'subtasks' => $this->get_subtasks($task_id, $subtask_limit),
            'signals' => $this->extract_task_signals($task_array),
        ];

        return FBAIA_Helpers::normalize($context);
    }

    private function get_recent_comments($task_id, $limit)
    {
        $limit = max(0, min(30, absint($limit)));
        if (!$limit || !class_exists('FluentBoards\App\Models\Comment')) {
            return [];
        }

        try {
            $class = 'FluentBoards\App\Models\Comment';
            $rows = $class::where('task_id', (int) $task_id)->orderBy('id', 'desc')->limit($limit)->get();
            $out = [];
            foreach ($rows as $row) {
                $data = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : get_object_vars($row);
                $description = isset($data['description']) ? wp_strip_all_tags((string) $data['description']) : '';
                if (strpos($description, self::GENERATED_TEXT_MARKER) !== false || strpos($description, 'fbaia-generated') !== false) {
                    continue;
                }
                $out[] = [
                    'id' => isset($data['id']) ? absint($data['id']) : 0,
                    'author_name' => sanitize_text_field($data['author_name'] ?? ''),
                    'created_at' => sanitize_text_field($data['created_at'] ?? ''),
                    'description' => FBAIA_Helpers::limit_chars($description, 1200),
                ];
            }
            return array_reverse($out);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function get_subtasks($task_id, $limit)
    {
        $limit = max(0, min(50, absint($limit)));
        if (!$limit || !class_exists('FluentBoards\App\Models\Task')) {
            return [];
        }

        try {
            $class = 'FluentBoards\App\Models\Task';
            $rows = $class::where('parent_id', (int) $task_id)->orderBy('position', 'asc')->limit($limit)->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = $this->task_to_array($row);
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function extract_task_signals(array $task)
    {
        $signals = [];
        foreach (['status', 'priority', 'stage_id', 'board_id', 'due_at', 'started_at', 'created_at', 'updated_at'] as $key) {
            if (isset($task[$key]) && $task[$key] !== '') {
                $signals[$key] = is_scalar($task[$key]) ? sanitize_text_field((string) $task[$key]) : FBAIA_Helpers::normalize($task[$key]);
            }
        }
        if (!empty($task['title'])) {
            $signals['title_length'] = strlen((string) $task['title']);
        }
        if (!empty($task['description'])) {
            $signals['description_length'] = strlen(wp_strip_all_tags((string) $task['description']));
        }
        return $signals;
    }

    public function get_task($task_id)
    {
        $task_id = absint($task_id);
        if (!$task_id || !class_exists('FluentBoards\\App\\Models\\Task')) {
            return null;
        }
        try {
            $class = 'FluentBoards\\App\\Models\\Task';
            return $class::find($task_id);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function update_task_priority($task_id, $priority)
    {
        $priority = sanitize_key($priority);
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            return new WP_Error('invalid_priority', __('Invalid task priority.', 'fluent-boards-ai-automation'));
        }

        $task = $this->get_task($task_id);
        if (!$task) {
            return new WP_Error('task_not_found', __('Task was not found.', 'fluent-boards-ai-automation'));
        }

        try {
            $old = isset($task->priority) ? (string) $task->priority : '';
            if ($old === $priority) {
                return ['updated' => false, 'reason' => 'same_priority', 'priority' => $priority];
            }
            $task->priority = $priority;
            $task->save();
            do_action('fbaia/task_priority_updated', $task, $old, $priority);
            return ['updated' => true, 'old_priority' => $old, 'priority' => $priority];
        } catch (Throwable $e) {
            return new WP_Error('priority_update_failed', $e->getMessage());
        }
    }

    public function create_subtask($parent_task_id, $title)
    {
        $parent_task_id = absint($parent_task_id);
        $title = sanitize_text_field($title);

        if (!$parent_task_id || $title === '') {
            return new WP_Error('invalid_subtask_data', __('Missing parent task ID or subtask title.', 'fluent-boards-ai-automation'));
        }
        if (!class_exists('FluentBoards\\App\\Models\\Task')) {
            return new WP_Error('task_model_missing', __('Fluent Boards Task model is not available.', 'fluent-boards-ai-automation'));
        }

        $parent = $this->get_task($parent_task_id);
        if (!$parent) {
            return new WP_Error('parent_task_not_found', __('Parent task was not found.', 'fluent-boards-ai-automation'));
        }

        try {
            $class = 'FluentBoards\\App\\Models\\Task';
            $position = $this->get_next_subtask_position($parent_task_id);
            $data = [
                'parent_id' => $parent->id,
                'title' => $title,
                'board_id' => isset($parent->board_id) ? (int) $parent->board_id : 0,
                'status' => 'open',
                'priority' => 'low',
                'due_at' => null,
                'position' => $position,
                'created_by' => get_current_user_id() ?: $this->get_bot_user_id(),
            ];
            $subtask = $class::create($data);
            do_action('fbaia/subtask_created', $parent, $subtask);
            return $subtask;
        } catch (Throwable $e) {
            return new WP_Error('subtask_create_failed', $e->getMessage());
        }
    }

    public function get_due_tasks($before_gmt, $limit = 25, $allowlist = '')
    {
        if (!class_exists('FluentBoards\\App\\Models\\Task')) {
            return new WP_Error('task_model_missing', __('Fluent Boards Task model is not available.', 'fluent-boards-ai-automation'));
        }

        $limit = max(1, min(100, absint($limit)));
        $before_gmt = sanitize_text_field($before_gmt);

        try {
            $class = 'FluentBoards\\App\\Models\\Task';
            $query = $class::query()
                ->where('status', '!=', 'closed')
                ->where('due_at', '<', $before_gmt)
                ->orderBy('due_at', 'asc')
                ->limit($limit);

            $tasks = $query->get();
            $out = [];
            foreach ($tasks as $task) {
                $board_id = isset($task->board_id) ? (int) $task->board_id : 0;
                if ($board_id && !FBAIA_Helpers::board_is_allowed($board_id, $allowlist)) {
                    continue;
                }
                $out[] = $task;
            }
            return $out;
        } catch (Throwable $e) {
            return new WP_Error('due_task_query_failed', $e->getMessage());
        }
    }

    public function get_recent_tasks($since_gmt, $limit = 50, $allowlist = '')
    {
        if (!class_exists('FluentBoards\\App\\Models\\Task')) {
            return new WP_Error('task_model_missing', __('Fluent Boards Task model is not available.', 'fluent-boards-ai-automation'));
        }

        $limit = max(1, min(100, absint($limit)));
        $since_gmt = sanitize_text_field($since_gmt);

        try {
            $class = 'FluentBoards\\App\\Models\\Task';
            $query = $class::query()
                ->where('updated_at', '>=', $since_gmt)
                ->orderBy('updated_at', 'desc')
                ->limit($limit);

            $tasks = $query->get();
            $out = [];
            foreach ($tasks as $task) {
                $board_id = isset($task->board_id) ? (int) $task->board_id : 0;
                if ($board_id && !FBAIA_Helpers::board_is_allowed($board_id, $allowlist)) {
                    continue;
                }
                $out[] = $task;
            }
            return $out;
        } catch (Throwable $e) {
            return new WP_Error('recent_task_query_failed', $e->getMessage());
        }
    }

    public function task_to_array($task)
    {
        if (is_object($task) && method_exists($task, 'toArray')) {
            return FBAIA_Helpers::normalize($task->toArray());
        }
        return FBAIA_Helpers::normalize($task);
    }

    private function get_next_subtask_position($parent_task_id)
    {
        try {
            $class = 'FluentBoards\\App\\Models\\Task';
            $last = $class::where('parent_id', (int) $parent_task_id)->orderBy('position', 'desc')->first();
            if ($last && isset($last->position)) {
                return ((int) $last->position) + 1;
            }
        } catch (Throwable $e) {
            // Safe fallback below.
        }
        return 1;
    }

    private function get_bot_user_id()
    {
        $current = get_current_user_id();
        if ($current) {
            return $current;
        }

        $admins = get_users([
            'role' => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ]);

        if (!empty($admins[0])) {
            return absint($admins[0]);
        }

        return 1;
    }

    private function increment_task_comment_count($task_id)
    {
        $task = $this->get_task($task_id);
        if (!$task) {
            return;
        }
        try {
            $settings = is_array($task->settings) ? $task->settings : [];
            $settings['comments_count'] = isset($settings['comments_count']) ? ((int) $settings['comments_count'] + 1) : 1;
            $task->settings = $settings;
            $task->save();
        } catch (Throwable $e) {
            // Non-critical. Fluent Boards may maintain comment counts elsewhere.
        }
    }
}
