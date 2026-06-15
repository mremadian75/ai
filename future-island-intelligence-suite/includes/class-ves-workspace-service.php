<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Workspace resolver for self-serve/private-beta access.
 *
 * Preference order:
 *  1. active ves_workspace_members row for the current user;
 *  2. legacy Memory/workspace inference;
 *  3. compatibility fallback keyed by current user id.
 *
 * Client-supplied workspace IDs are deliberately ignored by resolve_for_request().
 */
final class VES_Workspace_Service {

    /** Resolve the current workspace server-side. Client params are ignored. */
    public static function current_workspace_id($user_id = null): int {
        $uid = $user_id === null && function_exists('get_current_user_id') ? (int) get_current_user_id() : (int) $user_id;
        $uid = max(0, $uid);

        if ($uid > 0 && class_exists('VES_Workspace_Membership_Store')) {
            try {
                $member = VES_Workspace_Membership_Store::first_active_membership($uid);
                if (is_array($member) && (int) ($member['workspace_id'] ?? 0) > 0) {
                    return (int) $member['workspace_id'];
                }
            } catch (\Throwable $e) {
                // Compatibility fallback below keeps older installs working.
            }
        }

        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'workspace_id_for_user')) {
            try {
                $ws = max(0, (int) VES_Memory_Records::workspace_id_for_user($uid));
                if ($ws > 0) { return $ws; }
            } catch (\Throwable $e) {
                // Keep compatibility fallback; never block the request on legacy memory lookup.
            }
        }

        return $uid > 0 ? $uid : 1;
    }

    /** Describe the resolved workspace without trusting tenant ids from request params. */
    public static function current_workspace($user_id = null): array {
        $uid = $user_id === null && function_exists('get_current_user_id') ? (int) get_current_user_id() : (int) $user_id;
        $workspace_id = self::current_workspace_id($uid);
        $member = null;
        $workspace = null;
        if ($uid > 0 && class_exists('VES_Workspace_Membership_Store')) {
            try {
                $member = VES_Workspace_Membership_Store::get_member($workspace_id, $uid, true);
                $workspace = VES_Workspace_Membership_Store::get_workspace($workspace_id);
            } catch (\Throwable $e) {
                $member = null; $workspace = null;
            }
        }
        return [
            'workspace_id' => $workspace_id,
            'public_id' => is_array($workspace) ? (string) ($workspace['public_id'] ?? '') : '',
            'name' => is_array($workspace) ? (string) ($workspace['name'] ?? '') : '',
            'status' => is_array($workspace) ? (string) ($workspace['status'] ?? 'active') : 'active',
            'role' => is_array($member) ? (string) ($member['role'] ?? '') : '',
            'member_status' => is_array($member) ? (string) ($member['status'] ?? '') : '',
            'resolved_server_side' => true,
            'policy' => is_array($member) && (string) ($member['status'] ?? '') === 'active' ? 'real_workspace_membership' : 'operator_compatibility_fallback',
            'membership_model' => class_exists('VES_Workspace_Membership_Store') ? 'additive_tables_with_fallback' : 'compatibility_wrapper',
        ];
    }

    /** True when a row/object is in the current/resolved workspace. */
    public static function object_in_workspace(array $row, int $workspace_id): bool {
        $object_workspace = max(0, (int) ($row['workspace_id'] ?? 0));
        return $workspace_id > 0 && $object_workspace === $workspace_id;
    }

    /** Assert a run belongs to the resolved/current workspace. */
    public static function assert_run_workspace(int $run_id, int $workspace_id) {
        if ($run_id <= 0 || $workspace_id <= 0) { return self::err('ves_workspace_bad_run', 'Run and workspace are required.'); }
        if (!class_exists('VES_Run_Service')) { return self::err('ves_workspace_run_service_missing', 'Run service unavailable.'); }
        return VES_Run_Service::assert_workspace($run_id, $workspace_id);
    }

    /** Ignore client-supplied workspace ids deliberately. */
    public static function resolve_for_request($request = null, $user_id = null): array {
        $workspace = self::current_workspace($user_id);
        $workspace['client_workspace_ignored'] = true;
        return $workspace;
    }

    /** Convenience write APIs for onboarding/tests. Mutations remain explicit. */
    public static function create_workspace(array $args) {
        if (!class_exists('VES_Workspace_Membership_Store')) { return self::err('ves_workspace_store_missing', 'Workspace store unavailable.'); }
        return VES_Workspace_Membership_Store::create_workspace($args);
    }

    public static function add_member(int $workspace_id, int $user_id, string $role, array $args = []) {
        if (!class_exists('VES_Workspace_Membership_Store')) { return self::err('ves_workspace_store_missing', 'Workspace store unavailable.'); }
        return VES_Workspace_Membership_Store::add_member($workspace_id, $user_id, $role, $args);
    }

    public static function get_member(int $workspace_id, int $user_id, bool $include_inactive = false): ?array {
        if (!class_exists('VES_Workspace_Membership_Store')) { return null; }
        return VES_Workspace_Membership_Store::get_member($workspace_id, $user_id, $include_inactive);
    }

    private static function err($code, $message) {
        return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message];
    }
}
