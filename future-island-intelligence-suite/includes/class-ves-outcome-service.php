<?php
if (!defined('ABSPATH')) { exit; }

/** Thin service facade for manual Outcome Receipts. */
final class VES_Outcome_Service {
    public static function create_table($force = false): void {
        if (class_exists('VES_Outcome_Store')) { VES_Outcome_Store::create_table($force); }
    }
    public static function record_outcome(array $args) {
        return class_exists('VES_Outcome_Store') ? VES_Outcome_Store::record_outcome($args) : self::err('ves_outcome_store_missing', 'Outcome store unavailable.');
    }
    public static function list_for_target($workspace_id, $target_type, $target_id): array {
        return class_exists('VES_Outcome_Store') ? VES_Outcome_Store::list_for_target((int) $workspace_id, (string) $target_type, (int) $target_id) : [];
    }
    public static function list_for_run($workspace_id, $run_id): array {
        return class_exists('VES_Outcome_Store') ? VES_Outcome_Store::list_for_run((int) $workspace_id, (int) $run_id) : [];
    }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
