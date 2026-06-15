<?php
if (!defined('ABSPATH')) { exit; }

/** Workspace-scoped permission abstraction with real-membership preference and legacy admin fallback. */
final class VES_Permission_Service {
    const CAPABILITIES = [
        'can_view_workspace', 'can_run_playbook', 'can_review_outputs', 'can_manage_sources',
        'can_view_usage', 'can_record_outcome', 'can_manage_memory', 'can_manage_members', 'can_export_reports',
    ];

    public static function can(string $capability, int $workspace_id = 0, $user_id = null): bool {
        $capability = self::clean_key($capability, 80);
        if (!in_array($capability, self::CAPABILITIES, true)) { return false; }
        if (function_exists('is_user_logged_in') && !is_user_logged_in()) { return false; }

        $uid = $user_id === null && function_exists('get_current_user_id') ? (int) get_current_user_id() : (int) $user_id;
        $uid = max(0, $uid);
        $resolved = class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id($uid) : ($uid > 0 ? $uid : 1);
        $target = $workspace_id > 0 ? $workspace_id : $resolved;

        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }

        if ($uid > 0 && class_exists('VES_Workspace_Membership_Store')) {
            $member = VES_Workspace_Membership_Store::get_member($target, $uid, true);
            if (is_array($member) && (string) ($member['status'] ?? '') === 'active') {
                if ($resolved !== $target) { return false; }
                $map = VES_Workspace_Membership_Store::capability_map_for_member($member);
                return !empty($map[$capability]);
            }
        }

        if (function_exists('apply_filters')) {
            return (bool) apply_filters('ves_workspace_permission_can', false, $capability, $target, $uid);
        }
        return false;
    }

    public static function can_view_workspace(int $workspace_id = 0): bool { return self::can('can_view_workspace', $workspace_id); }
    public static function can_run_playbook(int $workspace_id = 0): bool { return self::can('can_run_playbook', $workspace_id); }
    public static function can_review_outputs(int $workspace_id = 0): bool { return self::can('can_review_outputs', $workspace_id); }
    public static function can_manage_sources(int $workspace_id = 0): bool { return self::can('can_manage_sources', $workspace_id); }
    public static function can_view_usage(int $workspace_id = 0): bool { return self::can('can_view_usage', $workspace_id); }
    public static function can_record_outcome(int $workspace_id = 0): bool { return self::can('can_record_outcome', $workspace_id); }
    public static function can_manage_memory(int $workspace_id = 0): bool { return self::can('can_manage_memory', $workspace_id); }
    public static function can_manage_members(int $workspace_id = 0): bool { return self::can('can_manage_members', $workspace_id); }
    public static function can_export_reports(int $workspace_id = 0): bool { return self::can('can_export_reports', $workspace_id); }

    public static function map_for_workspace(int $workspace_id = 0, $user_id = null): array {
        $out = [];
        foreach (self::CAPABILITIES as $cap) { $out[$cap] = self::can($cap, $workspace_id, $user_id); }
        return $out;
    }

    public static function role_capability_map(string $role): array {
        return class_exists('VES_Workspace_Membership_Store') ? VES_Workspace_Membership_Store::capabilities_for_role($role) : array_fill_keys(self::CAPABILITIES, false);
    }

    private static function clean_key(string $s, int $max): string {
        $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s));
        return substr($s, 0, $max);
    }
}
