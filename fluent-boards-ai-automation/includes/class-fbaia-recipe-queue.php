<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Review queue for HELD recipe actions.
 *
 * When a mutating recipe action cannot run automatically (recipe is review-mode, or the global
 * "allow recipe auto-changes" opt-in is off), it is recorded here instead of being silently
 * dropped or silently applied. An admin can then Approve (apply it) or Reject (discard it).
 */
class FBAIA_Recipe_Queue
{
    const OPTION = 'fbaia_recipe_actions';
    const MAX_ITEMS = 200;

    public static function record($recipe_name, array $action, $task_id, $board_id)
    {
        $items = self::all();
        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('fbaia_ra_', true));
        $item = [
            'id' => $id,
            'created_at' => current_time('mysql', true),
            'status' => 'pending',
            'recipe' => sanitize_text_field($recipe_name),
            'action_type' => sanitize_key($action['type'] ?? ''),
            'value' => sanitize_text_field($action['value'] ?? ''),
            'message' => sanitize_textarea_field($action['message'] ?? ''),
            'items' => array_values(array_filter(array_map('sanitize_text_field', (array) ($action['items'] ?? [])))),
            'task_id' => absint($task_id),
            'board_id' => absint($board_id),
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
            return ($item['status'] ?? '') === $status;
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

    public static function stats()
    {
        $items = self::all();
        $stats = ['total' => count($items), 'pending' => 0, 'applied' => 0, 'rejected' => 0];
        foreach ($items as $item) {
            $s = $item['status'] ?? 'pending';
            if (isset($stats[$s])) {
                $stats[$s]++;
            }
        }
        return $stats;
    }

    private static function set_status($id, $status, array $extra = [])
    {
        $id = sanitize_text_field($id);
        $items = self::all();
        $changed = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['status'] = sanitize_key($status);
                foreach ($extra as $k => $v) {
                    $item[$k] = $v;
                }
                $changed = true;
                break;
            }
        }
        unset($item);
        if ($changed) {
            update_option(self::OPTION, $items, false);
        }
        return $changed;
    }

    /**
     * Approve a held action: apply it now (explicit, authorized admin action) regardless of the
     * global auto-mutation toggle.
     */
    public static function approve($id, $adapter = null)
    {
        $item = self::get($id);
        if (!$item) {
            return new WP_Error('recipe_action_not_found', __('Held action was not found.', 'fluent-boards-ai-automation'));
        }
        if (($item['status'] ?? '') !== 'pending') {
            return ['applied' => false, 'reason' => 'already_resolved'];
        }
        if (!class_exists('FBAIA_Recipes')) {
            return new WP_Error('recipes_missing', __('Recipes engine is not available.', 'fluent-boards-ai-automation'));
        }

        $action = [
            'type' => $item['action_type'] ?? '',
            'value' => $item['value'] ?? '',
            'message' => $item['message'] ?? '',
            'items' => $item['items'] ?? [],
        ];
        $result = FBAIA_Recipes::apply_mutation(
            $item['action_type'] ?? '',
            $action,
            $item['task_id'] ?? 0,
            $item['board_id'] ?? 0,
            $adapter,
            $item['recipe'] ?? 'Recipe'
        );

        self::set_status($id, 'applied', [
            'applied_at' => current_time('mysql', true),
            'applied_by' => get_current_user_id(),
            'apply_status' => $result['status'] ?? '',
        ]);
        if (class_exists('FBAIA_Logger')) {
            FBAIA_Logger::add('success', 'Held recipe action approved & applied', ['id' => $id, 'task_id' => $item['task_id'] ?? 0, 'action' => $item['action_type'] ?? '']);
        }
        return ['applied' => true, 'result' => $result];
    }

    public static function reject($id)
    {
        $item = self::get($id);
        if (!$item) {
            return new WP_Error('recipe_action_not_found', __('Held action was not found.', 'fluent-boards-ai-automation'));
        }
        self::set_status($id, 'rejected', [
            'rejected_at' => current_time('mysql', true),
            'rejected_by' => get_current_user_id(),
        ]);
        if (class_exists('FBAIA_Logger')) {
            FBAIA_Logger::add('info', 'Held recipe action rejected', ['id' => $id, 'task_id' => $item['task_id'] ?? 0]);
        }
        return ['rejected' => true];
    }

    public static function clear()
    {
        delete_option(self::OPTION);
    }
}
