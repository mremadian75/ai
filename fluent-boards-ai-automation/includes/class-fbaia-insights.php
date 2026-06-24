<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Insights
{
    public static function snapshot()
    {
        $suggestions = class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::all() : [];
        $logs = FBAIA_Logger::all();
        $audit = class_exists('FBAIA_Audit_Trail') ? FBAIA_Audit_Trail::all(200) : [];
        $stats = [
            'generated_at' => current_time('mysql', true),
            'suggestions_total' => count($suggestions),
            'pending' => 0,
            'applied' => 0,
            'rejected' => 0,
            'high_risk' => 0,
            'urgent_priority' => 0,
            'avg_confidence' => 0,
            'top_task_types' => [],
            'top_events' => [],
            'top_risks' => [],
            'knowledge_gaps' => [],
            'recent_errors' => [],
            'automation_writes' => 0,
            'human_decisions' => 0,
            'matched_playbooks' => 0,
            'usage' => class_exists('FBAIA_Usage_Tracker') ? FBAIA_Usage_Tracker::snapshot() : [],
        ];
        $confidence_total = 0;
        foreach ($suggestions as $item) {
            $status = $item['status'] ?? 'pending';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
            $confidence_total += (float) ($item['confidence'] ?? 0);
            if ((float) ($item['risk_score'] ?? 0) >= 0.7) {
                $stats['high_risk']++;
            }
            if (($item['recommended_priority'] ?? '') === 'urgent') {
                $stats['urgent_priority']++;
            }
            $stats['matched_playbooks'] += (int) ($item['matched_playbook_count'] ?? 0);
            self::bump($stats['top_events'], $item['event'] ?? 'unknown');
            $ai = is_array($item['ai_payload'] ?? null) ? $item['ai_payload'] : [];
            self::bump($stats['top_task_types'], $ai['decision_quality']['task_type'] ?? 'unknown');
            foreach (array_slice((array) ($ai['risk_flags'] ?? []), 0, 4) as $risk) {
                self::bump($stats['top_risks'], FBAIA_Helpers::limit_chars($risk, 120));
            }
            foreach (array_slice((array) ($ai['knowledge_gaps'] ?? []), 0, 4) as $gap) {
                self::bump($stats['knowledge_gaps'], FBAIA_Helpers::limit_chars($gap, 120));
            }
        }
        $stats['avg_confidence'] = count($suggestions) ? round($confidence_total / count($suggestions), 2) : 0;
        foreach ($logs as $log) {
            if (in_array($log['level'] ?? '', ['error', 'warning'], true)) {
                $stats['recent_errors'][] = $log;
            }
            if (count($stats['recent_errors']) >= 8) {
                break;
            }
        }
        foreach ($audit as $item) {
            $action = $item['action'] ?? '';
            if (in_array($action, ['comment_written', 'priority_updated', 'subtasks_created', 'admin_email_sent'], true)) {
                $stats['automation_writes']++;
            }
            if (in_array($action, ['suggestion_applied', 'suggestion_rejected'], true)) {
                $stats['human_decisions']++;
            }
        }
        foreach (['top_task_types','top_events','top_risks','knowledge_gaps'] as $key) {
            arsort($stats[$key]);
            $stats[$key] = array_slice($stats[$key], 0, 8, true);
        }
        return $stats;
    }

    public static function render_text_report(array $snapshot = null)
    {
        $snapshot = $snapshot ?: self::snapshot();
        $lines = [];
        $lines[] = 'Fluent Boards AI Weekly Intelligence';
        $lines[] = 'Generated: ' . ($snapshot['generated_at'] ?? '');
        $lines[] = '';
        $lines[] = 'Suggestions: ' . (int) ($snapshot['suggestions_total'] ?? 0) . ' total, ' . (int) ($snapshot['pending'] ?? 0) . ' pending, ' . (int) ($snapshot['applied'] ?? 0) . ' applied, ' . (int) ($snapshot['rejected'] ?? 0) . ' rejected.';
        $lines[] = 'Risk: ' . (int) ($snapshot['high_risk'] ?? 0) . ' high-risk suggestions, ' . (int) ($snapshot['urgent_priority'] ?? 0) . ' urgent priority recommendations.';
        $lines[] = 'Average confidence: ' . round((float) ($snapshot['avg_confidence'] ?? 0) * 100) . '%.';
        if (!empty($snapshot['usage'])) {
            $lines[] = 'AI usage this month: ' . (int) ($snapshot['usage']['month']['total_tokens'] ?? 0) . ' tokens across ' . (int) ($snapshot['usage']['month']['calls'] ?? 0) . ' calls.';
        }
        $lines[] = 'Matched playbooks across suggestions: ' . (int) ($snapshot['matched_playbooks'] ?? 0) . '.';
        $lines[] = '';
        self::append_map($lines, 'Top task types', $snapshot['top_task_types'] ?? []);
        self::append_map($lines, 'Top events', $snapshot['top_events'] ?? []);
        self::append_map($lines, 'Recurring risks', $snapshot['top_risks'] ?? []);
        self::append_map($lines, 'Knowledge gaps to document', $snapshot['knowledge_gaps'] ?? []);
        if (!empty($snapshot['recent_errors'])) {
            $lines[] = '';
            $lines[] = 'Recent problems:';
            foreach (array_slice($snapshot['recent_errors'], 0, 6) as $log) {
                $lines[] = '- [' . ($log['level'] ?? '') . '] ' . ($log['message'] ?? '');
            }
        }
        return implode("\n", $lines);
    }

    public static function send_weekly_digest($email = '')
    {
        $settings = FBAIA_Helpers::get_settings();
        $email = sanitize_email($email ?: ($settings['weekly_intelligence_email'] ?? get_option('admin_email')));
        if (!$email) {
            return false;
        }
        $snapshot = self::snapshot();
        $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Fluent Boards AI weekly intelligence';
        $sent = wp_mail($email, $subject, self::render_text_report($snapshot));
        FBAIA_Logger::add($sent ? 'success' : 'error', $sent ? 'Weekly intelligence digest sent' : 'Weekly intelligence digest failed', ['email' => $email]);
        if (class_exists('FBAIA_Audit_Trail')) {
            FBAIA_Audit_Trail::record('weekly_intelligence_digest', ['status' => $sent ? 'sent' : 'failed', 'email' => $email]);
        }
        return $sent;
    }

    private static function bump(array &$map, $key)
    {
        $key = sanitize_text_field((string) $key);
        if ($key === '') {
            $key = 'unknown';
        }
        if (!isset($map[$key])) {
            $map[$key] = 0;
        }
        $map[$key]++;
    }

    private static function append_map(array &$lines, $title, array $map)
    {
        if (empty($map)) {
            return;
        }
        $lines[] = '';
        $lines[] = $title . ':';
        foreach ($map as $key => $count) {
            $lines[] = '- ' . $key . ' (' . (int) $count . ')';
        }
    }
}
