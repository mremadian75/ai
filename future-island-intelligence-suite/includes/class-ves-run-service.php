<?php
if (!defined('ABSPATH')) { exit; }

/** Thin domain wrapper for canonical runs. */
final class VES_Run_Service {
    public static function create_run($args) { return class_exists('VES_Run_Store') ? VES_Run_Store::create_run(is_array($args) ? $args : []) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function get_run($run_id) { return class_exists('VES_Run_Store') ? VES_Run_Store::get_run($run_id) : null; }
    public static function find_by_idempotency_key($workspace_id, $idempotency_key) { return class_exists('VES_Run_Store') ? VES_Run_Store::find_by_idempotency_key($workspace_id, $idempotency_key) : null; }
    public static function mark_running($run_id) { return class_exists('VES_Run_Store') ? VES_Run_Store::mark_running($run_id) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function mark_completed($run_id, $summary = []) { return class_exists('VES_Run_Store') ? VES_Run_Store::mark_completed($run_id, $summary) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function mark_partial($run_id, $summary = []) { return class_exists('VES_Run_Store') ? VES_Run_Store::mark_partial($run_id, $summary) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function mark_failed($run_id, $error_summary, $metadata = []) { return class_exists('VES_Run_Store') ? VES_Run_Store::mark_failed($run_id, $error_summary, $metadata) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function mark_timed_out($run_id, $metadata = []) { return class_exists('VES_Run_Store') ? VES_Run_Store::mark_timed_out($run_id, $metadata) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function cancel_run($run_id) { return class_exists('VES_Run_Store') ? VES_Run_Store::cancel_run($run_id) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function assert_workspace($run_id, $workspace_id) { return class_exists('VES_Run_Store') ? VES_Run_Store::assert_workspace($run_id, $workspace_id) : new WP_Error('ves_run_store_missing', 'Run store unavailable.'); }
    public static function list_runs_for_workspace($workspace_id, $args = []) { return class_exists('VES_Run_Store') ? VES_Run_Store::list_runs_for_workspace($workspace_id, is_array($args) ? $args : []) : []; }
}
