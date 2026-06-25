<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Audit_Trail
{
    const OPTION = 'fbaia_audit_trail';
    const MAX_ITEMS = 500;

    public static function record($action, array $context = [])
    {
        $settings = FBAIA_Helpers::get_settings();
        $limit = max(50, min(2000, (int) ($settings['audit_retention'] ?? self::MAX_ITEMS)));
        $items = self::all();
        $items = is_array($items) ? $items : [];
        $items[] = [
            'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('fbaia_audit_', true)),
            'time' => current_time('mysql', true),
            'actor_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'task_id' => isset($context['task_id']) ? absint($context['task_id']) : 0,
            'board_id' => isset($context['board_id']) ? absint($context['board_id']) : 0,
            'suggestion_id' => isset($context['suggestion_id']) ? sanitize_text_field($context['suggestion_id']) : '',
            'status' => isset($context['status']) ? sanitize_key($context['status']) : 'recorded',
            'context' => FBAIA_Helpers::normalize($context),
        ];
        $items = array_slice($items, -1 * $limit);
        update_option(self::OPTION, $items, false);
    }

    public static function all($limit = 0)
    {
        $items = get_option(self::OPTION, []);
        if (!is_array($items)) {
            return [];
        }
        $items = array_reverse($items);
        $limit = absint($limit);
        return $limit ? array_slice($items, 0, $limit) : $items;
    }

    public static function clear()
    {
        delete_option(self::OPTION);
    }

    public static function stats()
    {
        $items = self::all();
        $stats = [
            'total' => count($items),
            'actions' => [],
            'last_action_at' => '',
            'automation_writes' => 0,
            'human_decisions' => 0,
        ];
        foreach ($items as $item) {
            $action = sanitize_key($item['action'] ?? 'unknown');
            if (!isset($stats['actions'][$action])) {
                $stats['actions'][$action] = 0;
            }
            $stats['actions'][$action]++;
            if ($stats['last_action_at'] === '') {
                $stats['last_action_at'] = $item['time'] ?? '';
            }
            if (in_array($action, ['comment_written', 'priority_updated', 'subtasks_created', 'admin_email_sent'], true)) {
                $stats['automation_writes']++;
            }
            if (in_array($action, ['suggestion_applied', 'suggestion_rejected'], true)) {
                $stats['human_decisions']++;
            }
        }
        arsort($stats['actions']);
        return $stats;
    }
}
