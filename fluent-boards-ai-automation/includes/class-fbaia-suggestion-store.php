<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Suggestion_Store
{
    const OPTION = 'fbaia_suggestions';
    const MAX_ITEMS = 250;

    public static function record($event, array $payload, array $ai_payload, array $meta = [])
    {
        if ((FBAIA_Helpers::get_settings()['store_ai_suggestions'] ?? 'yes') !== 'yes') {
            return '';
        }

        $items = self::all();
        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('fbaia_', true));
        $item = [
            'id' => $id,
            'created_at' => current_time('mysql', true),
            'created_by' => get_current_user_id(),
            'status' => 'pending',
            'event' => sanitize_key($event),
            'task_id' => FBAIA_Helpers::extract_task_id($payload),
            'board_id' => FBAIA_Helpers::extract_board_id($payload),
            'task_title' => FBAIA_Helpers::extract_task_title($payload),
            'summary' => sanitize_textarea_field($ai_payload['summary'] ?? ''),
            'recommended_priority' => sanitize_key($ai_payload['recommended_priority'] ?? ''),
            'confidence' => isset($ai_payload['confidence']) ? (float) $ai_payload['confidence'] : 0,
            'risk_score' => isset($ai_payload['decision_quality']['risk_score']) ? (float) $ai_payload['decision_quality']['risk_score'] : 0,
            'impact_score' => isset($ai_payload['decision_quality']['impact_score']) ? (float) $ai_payload['decision_quality']['impact_score'] : 0,
            'effort_score' => isset($ai_payload['decision_quality']['effort_score']) ? (float) $ai_payload['decision_quality']['effort_score'] : 0,
            'task_type' => sanitize_text_field($ai_payload['decision_quality']['task_type'] ?? ''),
            'owner_name' => sanitize_text_field($ai_payload['owner_recommendation']['name'] ?? ''),
            'reviewer_name' => sanitize_text_field($ai_payload['reviewer_recommendation']['name'] ?? ''),
            'knowledge_gap_count' => is_array($ai_payload['knowledge_gaps'] ?? null) ? count($ai_payload['knowledge_gaps']) : 0,
            'decision_brief' => sanitize_textarea_field($ai_payload['decision_brief']['headline'] ?? ''),
            'matched_playbook_count' => is_array($ai_payload['policy_controls']['matched_playbooks'] ?? null) ? count($ai_payload['policy_controls']['matched_playbooks']) : 0,
            'automation_safe' => FBAIA_Helpers::to_bool($ai_payload['automation_safe'] ?? false),
            'skip_comment' => FBAIA_Helpers::to_bool($ai_payload['skip_comment'] ?? false),
            'payload' => FBAIA_Helpers::normalize($payload),
            'ai_payload' => FBAIA_Helpers::normalize($ai_payload),
            'meta' => FBAIA_Helpers::normalize($meta),
            'feedback' => '',
            'feedback_by' => 0,
            'feedback_at' => '',
        ];

        array_unshift($items, $item);
        $items = array_slice($items, 0, self::MAX_ITEMS);
        update_option(self::OPTION, $items, false);
        return $id;
    }

    public static function all($status = '')
    {
        $items = get_option(self::OPTION, []);
        if (!is_array($items)) {
            return [];
        }
        if ($status === '') {
            return $items;
        }
        return array_values(array_filter($items, function ($item) use ($status) {
            return isset($item['status']) && $item['status'] === $status;
        }));
    }

    public static function get($id)
    {
        $id = sanitize_text_field($id);
        foreach (self::all() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    public static function update($id, array $changes)
    {
        $id = sanitize_text_field($id);
        $items = self::all();
        $updated = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }
            foreach ($changes as $key => $value) {
                $item[$key] = FBAIA_Helpers::normalize($value);
            }
            $updated = true;
            break;
        }
        if ($updated) {
            update_option(self::OPTION, $items, false);
        }
        return $updated;
    }

    public static function apply($id)
    {
        $item = self::get($id);
        if (!$item) {
            return new WP_Error('suggestion_not_found', __('Suggestion was not found.', 'fluent-boards-ai-automation'));
        }
        if (($item['status'] ?? '') === 'applied') {
            return ['applied' => false, 'reason' => 'already_applied'];
        }

        $settings = FBAIA_Helpers::get_settings();
        $actions = new FBAIA_Internal_Actions($settings);
        $ai_payload = is_array($item['ai_payload'] ?? null) ? $item['ai_payload'] : [];
        $ai_payload['suggestion_id'] = $id;
        $ai_payload['approval_status'] = 'approved';
        if (!isset($ai_payload['manager_decision']) || !is_array($ai_payload['manager_decision'])) {
            $ai_payload['manager_decision'] = [];
        }
        $ai_payload['manager_decision']['approve_priority_change'] = true;
        $ai_payload['manager_decision']['approve_subtasks'] = true;
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $actions->apply($item['event'] ?? 'external_event', $payload, $ai_payload, $id, true);

        self::update($id, [
            'status' => 'applied',
            'applied_at' => current_time('mysql', true),
            'applied_by' => get_current_user_id(),
        ]);
        FBAIA_Logger::add('success', 'Suggestion approved and applied', ['suggestion_id' => $id, 'task_id' => $item['task_id'] ?? 0]);
        if (class_exists('FBAIA_Audit_Trail')) {
            FBAIA_Audit_Trail::record('suggestion_applied', ['suggestion_id' => $id, 'task_id' => $item['task_id'] ?? 0, 'board_id' => $item['board_id'] ?? 0, 'status' => 'applied']);
        }
        return ['applied' => true];
    }

    public static function reject($id, $feedback = '')
    {
        $item = self::get($id);
        if (!$item) {
            return new WP_Error('suggestion_not_found', __('Suggestion was not found.', 'fluent-boards-ai-automation'));
        }
        self::update($id, [
            'status' => 'rejected',
            'feedback' => sanitize_textarea_field($feedback),
            'feedback_by' => get_current_user_id(),
            'feedback_at' => current_time('mysql', true),
        ]);
        FBAIA_Logger::add('info', 'Suggestion rejected with feedback', ['suggestion_id' => $id, 'task_id' => $item['task_id'] ?? 0]);
        if (class_exists('FBAIA_Audit_Trail')) {
            FBAIA_Audit_Trail::record('suggestion_rejected', ['suggestion_id' => $id, 'task_id' => $item['task_id'] ?? 0, 'board_id' => $item['board_id'] ?? 0, 'status' => 'rejected', 'feedback' => $feedback]);
        }
        return ['rejected' => true];
    }

    public static function feedback_context($query_text, $limit = 5)
    {
        $settings = FBAIA_Helpers::get_settings();
        if (($settings['feedback_learning_enabled'] ?? 'yes') !== 'yes') {
            return [];
        }

        $limit = max(0, min(10, absint($limit)));
        if (!$limit) {
            return [];
        }

        $candidates = array_filter(self::all(), function ($item) {
            return in_array($item['status'] ?? '', ['applied', 'rejected'], true);
        });
        $scored = [];
        foreach ($candidates as $item) {
            $text = implode(' ', [
                $item['event'] ?? '',
                $item['task_title'] ?? '',
                $item['summary'] ?? '',
                $item['recommended_priority'] ?? '',
                $item['feedback'] ?? '',
                wp_json_encode($item['ai_payload'] ?? []),
            ]);
            $scored[] = [
                'score' => self::keyword_score($query_text, $text),
                'created_at' => strtotime($item['created_at'] ?? '') ?: 0,
                'item' => $item,
            ];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $b['created_at'] - $a['created_at'];
            }
            return $b['score'] - $a['score'];
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            if ($row['score'] <= 0 && count($out) >= 2) {
                continue;
            }
            $item = $row['item'];
            $out[] = [
                'source' => 'suggestion_feedback',
                'status' => sanitize_key($item['status'] ?? ''),
                'event' => sanitize_key($item['event'] ?? ''),
                'task_title' => sanitize_text_field($item['task_title'] ?? ''),
                'summary' => FBAIA_Helpers::limit_chars($item['summary'] ?? '', 500),
                'priority' => sanitize_key($item['recommended_priority'] ?? ''),
                'feedback' => FBAIA_Helpers::limit_chars($item['feedback'] ?? '', 500),
                'score' => (int) $row['score'],
            ];
        }
        return $out;
    }

    public static function stats()
    {
        $items = self::all();
        $stats = ['total' => count($items), 'pending' => 0, 'applied' => 0, 'rejected' => 0, 'avg_confidence' => 0, 'high_risk' => 0, 'avg_risk' => 0, 'high_impact' => 0, 'knowledge_gaps' => 0, 'top_task_types' => []];
        $confidence_total = 0;
        $risk_total = 0;
        foreach ($items as $item) {
            $status = $item['status'] ?? 'pending';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
            $confidence_total += (float) ($item['confidence'] ?? 0);
            $risk = (float) ($item['risk_score'] ?? 0);
            $risk_total += $risk;
            if ($risk >= 0.7) {
                $stats['high_risk']++;
            }
            if ((float) ($item['impact_score'] ?? 0) >= 0.7) {
                $stats['high_impact']++;
            }
            $stats['knowledge_gaps'] += (int) ($item['knowledge_gap_count'] ?? 0);
            $type = sanitize_text_field($item['task_type'] ?? 'unknown');
            if ($type === '') { $type = 'unknown'; }
            if (!isset($stats['top_task_types'][$type])) { $stats['top_task_types'][$type] = 0; }
            $stats['top_task_types'][$type]++;
        }
        $stats['avg_confidence'] = count($items) ? round($confidence_total / count($items), 2) : 0;
        $stats['avg_risk'] = count($items) ? round($risk_total / count($items), 2) : 0;
        arsort($stats['top_task_types']);
        $stats['top_task_types'] = array_slice($stats['top_task_types'], 0, 6, true);
        return $stats;
    }

    private static function keyword_score($needle_text, $haystack_text)
    {
        $needles = self::keywords($needle_text);
        $haystack = ' ' . strtolower((string) $haystack_text) . ' ';
        $score = 0;
        foreach ($needles as $word => $weight) {
            if (strpos($haystack, strtolower($word)) !== false) {
                $score += $weight;
            }
        }
        return $score;
    }

    private static function keywords($text)
    {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $tokens = preg_split('/[^\p{L}\p{N}_@.\-]+/u', $text);
        $stop = ['the','and','for','with','from','this','that','have','has','will','task','board','comment','created','updated','changed','user','your','you','در','از','به','برای','که','این','آن','های','است','شد','شود','یک','یا','و','را','با'];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            $out[$token] = isset($out[$token]) ? $out[$token] + 1 : 1;
        }
        arsort($out);
        return array_slice($out, 0, 80, true);
    }
}
