<?php
if (!defined('ABSPATH')) { exit; }

/**
 * FI_Memory_Service — memory candidates with an explicit review decision.
 * Memory is continuity, not evidence: nothing here feeds generation context
 * unless approved, and nothing is deleted by review actions. No fine-tuning,
 * no cross-tenant learning — and none is claimed.
 */
final class FI_Memory_Service {

    const ACTION_APPROVE = 'fi_memory_approve';
    const ACTION_REJECT  = 'fi_memory_reject';

    public static function store_available(): bool {
        return class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'update_memory_review');
    }

    public static function workspace_id(): int {
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'workspace_id_for_user') && function_exists('get_current_user_id')) {
            $ws = (int) VES_Memory_Records::workspace_id_for_user(get_current_user_id());
            if ($ws > 0) { return $ws; }
        }
        return 1;
    }

    public static function records(int $workspace_id, int $limit = 30): array {
        if (!class_exists('VES_Intelligence_Store')) { return []; }
        try {
            return (array) VES_Intelligence_Store::list_memory_records(['workspace_id' => $workspace_id, 'limit' => $limit]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Review decision processor (capability/nonce live in the handler). */
    public static function process_review(array $in, string $decision) {
        if (!self::store_available()) {
            return new WP_Error('fi_memory_store_missing', 'Memory review is unavailable: intelligence store not loaded.');
        }
        $ws = max(0, (int) ($in['workspace_id'] ?? 0));
        $memory_id = (int) ($in['memory_id'] ?? 0);
        if ($ws <= 0 || $memory_id <= 0) {
            return new WP_Error('fi_memory_bad_input', 'workspace_id and memory_id are required.');
        }
        $record = VES_Intelligence_Store::get_memory_record($memory_id);
        if (!is_array($record) || (int) ($record['id'] ?? 0) !== $memory_id) {
            return new WP_Error('fi_memory_not_found', 'Memory record not found.');
        }
        if ((int) ($record['workspace_id'] ?? 0) !== $ws) {
            return new WP_Error('ves_workspace_mismatch', 'Memory record belongs to a different workspace.');
        }
        $reason = function_exists('sanitize_text_field') ? sanitize_text_field((string) ($in['reason'] ?? '')) : trim(strip_tags((string) ($in['reason'] ?? '')));
        return VES_Intelligence_Store::update_memory_review($memory_id, $decision, $reason);
    }

    public static function register_actions(): void {
        if (!function_exists('add_action')) { return; }
        add_action('admin_post_' . self::ACTION_APPROVE, [__CLASS__, 'handle_approve']);
        add_action('admin_post_' . self::ACTION_REJECT, [__CLASS__, 'handle_reject']);
    }

    public static function handle_approve() { self::handle(self::ACTION_APPROVE, 'approved', 'memory_approved'); }
    public static function handle_reject()  { self::handle(self::ACTION_REJECT, 'rejected', 'memory_rejected'); }

    private static function handle(string $action, string $decision, string $notice): void {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die('Insufficient permissions.', '', ['response' => 403]); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer($action); }
        $in = isset($_POST) && is_array($_POST) ? $_POST : [];
        $in = function_exists('wp_unslash') ? wp_unslash($in) : $in;
        $res = self::process_review($in, $decision);
        $args = ['page' => FI_Memory_Module::PAGE_SLUG];
        if (is_wp_error($res)) {
            $args['fi_err'] = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $res->get_error_code()));
        } else {
            $args['fi_notice'] = $notice;
            $args['fi_id'] = (int) ($res['memory_id'] ?? 0);
        }
        $url = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        $url = function_exists('add_query_arg') ? add_query_arg($args, $url) : $url . '?' . http_build_query($args);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        if (!defined('VES_INTAKE_NO_EXIT')) { exit; }
    }

    public static function status(): array {
        if (!class_exists('VES_Intelligence_Store')) {
            return ['state' => 'unavailable', 'detail' => 'Intelligence store is not loaded.'];
        }
        if (!self::store_available()) {
            return ['state' => 'read_only', 'detail' => 'Records are viewable; review decisions need the updated store.'];
        }
        return ['state' => 'available', 'detail' => 'Candidates carry pending_review until a human decision. Memory is continuity, not evidence.'];
    }
}
