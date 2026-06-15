<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Workspace_Guard — Phase 9A.3 tenant/workspace isolation service.
 *
 * Single place to answer: "does this object belong to this workspace?" Used by
 * the prompt package builder, workbenches, operator queue, CLI previews and
 * memory context retrieval. Rules:
 *   - cross-workspace reads/writes are refused with a safe WP_Error;
 *   - an object with NO workspace (0/missing) is 'unknown' and only usable when
 *     the caller explicitly passes allow_unknown (never silently mapped to a
 *     workspace, never silently defaulted to workspace 1);
 *   - every mismatch is recorded as a scrubbed security event.
 * Read-only; never mutates objects. PHP 7.4 compatible.
 */
final class VES_Workspace_Guard {

    const OBJECT_TYPES = ['source', 'signal', 'evidence', 'insight', 'brief', 'draft', 'memory_record'];

    /** Validate a workspace id argument. @return int|WP_Error */
    public static function validate_workspace_id($workspace_id) {
        if (!is_numeric($workspace_id)) {
            return new WP_Error('ves_workspace_invalid', 'Workspace id must be numeric.');
        }
        $ws = (int) $workspace_id;
        if ($ws <= 0) {
            return new WP_Error('ves_workspace_invalid', 'Workspace id must be a positive integer.');
        }
        return $ws;
    }

    /**
     * Assert an object belongs to the expected workspace.
     * $object may be an id (loaded via the store) or an already-loaded row array.
     * $opts: ['allow_unknown' => bool] — explicit opt-in for workspace-less rows.
     * @return true|WP_Error
     */
    public static function assert_object_in_workspace($object_type, $object, $expected_workspace_id, array $opts = []) {
        $expected = self::validate_workspace_id($expected_workspace_id);
        if (is_wp_error($expected)) { return $expected; }
        $object_type = self::clean_key($object_type);
        if (!in_array($object_type, self::OBJECT_TYPES, true)) {
            return new WP_Error('ves_workspace_bad_type', 'Unknown object type for workspace check.');
        }

        $row = is_array($object) ? $object : self::load($object_type, (int) $object);
        if (!is_array($row) || empty($row)) {
            return new WP_Error('ves_workspace_object_missing', ucfirst($object_type) . ' not found for workspace check.');
        }

        $actual = array_key_exists('workspace_id', $row) ? (int) $row['workspace_id'] : 0;
        if ($actual <= 0) {
            if (!empty($opts['allow_unknown'])) { return true; }
            self::log_mismatch($object_type, $row, $expected, 'unknown_workspace');
            return new WP_Error('ves_workspace_unknown', ucfirst($object_type) . ' has no workspace; explicit safe fallback required.', [
                'object_type' => $object_type, 'expected_workspace' => $expected,
            ]);
        }
        if ($actual !== $expected) {
            self::log_mismatch($object_type, $row, $expected, 'cross_workspace');
            return new WP_Error('ves_workspace_mismatch', ucfirst($object_type) . ' belongs to a different workspace.', [
                'object_type' => $object_type, 'expected_workspace' => $expected, 'actual_workspace' => $actual,
            ]);
        }
        return true;
    }

    /** Drop rows that are not in the expected workspace (defensive list filter). */
    public static function filter_rows_to_workspace(array $rows, $expected_workspace_id) {
        $expected = self::validate_workspace_id($expected_workspace_id);
        if (is_wp_error($expected)) { return []; }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['workspace_id'] ?? 0) === $expected) { $out[] = $row; }
        }
        return $out;
    }

    /** Readiness probe: guard active and refusing cross-workspace access. */
    public static function guard_active() {
        $probe = self::assert_object_in_workspace('insight', ['id' => 1, 'workspace_id' => 2], 1);
        $unknown = self::assert_object_in_workspace('insight', ['id' => 1], 1);
        $ok = self::assert_object_in_workspace('insight', ['id' => 1, 'workspace_id' => 1], 1);
        return is_wp_error($probe) && $probe->get_error_code() === 'ves_workspace_mismatch'
            && is_wp_error($unknown) && $unknown->get_error_code() === 'ves_workspace_unknown'
            && $ok === true;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private static function load($object_type, $id) {
        if ($id <= 0) { return null; }
        if ($object_type === 'memory_record') {
            if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'get_memory_record')) {
                return VES_Intelligence_Store::get_memory_record($id);
            }
            return null;
        }
        $getter = 'get_' . $object_type;
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', $getter)) {
            return VES_Intelligence_Store::$getter($id);
        }
        return null;
    }

    private static function log_mismatch($object_type, array $row, $expected, $kind) {
        if (class_exists('VES_Security_Event_Log')) {
            VES_Security_Event_Log::record('workspace_mismatch', 'Workspace guard refused ' . $kind . ' access to ' . $object_type . '.', [
                'object_type' => $object_type,
                'object_id' => (int) ($row['id'] ?? 0),
                'expected_workspace' => (int) $expected,
                'actual_workspace' => (int) ($row['workspace_id'] ?? 0),
            ]);
        }
    }

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }
}
