<?php
if (!defined('ABSPATH')) { exit; }

/** Thin wrapper for append-only pattern observations. */
final class VES_Pattern_Observation_Service {
    public static function create_table($force = false): void { if (class_exists('VES_Pattern_Observation_Store')) { VES_Pattern_Observation_Store::create_table($force); } }
    public static function record_pattern(array $args) { return class_exists('VES_Pattern_Observation_Store') ? VES_Pattern_Observation_Store::record_pattern($args) : new WP_Error('ves_pattern_store_missing', 'Pattern observation store unavailable.'); }
    public static function list_for_workspace($workspace_id, array $args = []): array { return class_exists('VES_Pattern_Observation_Store') ? VES_Pattern_Observation_Store::list_for_workspace((int) $workspace_id, $args) : []; }
}
