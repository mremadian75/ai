<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Decision_Edge_Service {
    public static function add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $relation_type, $args = []) {
        return class_exists('VES_Decision_Edge_Store') ? VES_Decision_Edge_Store::add_edge($workspace_id, $run_id, $from_type, $from_id, $to_type, $to_id, $relation_type, is_array($args) ? $args : []) : new WP_Error('ves_edge_store_missing', 'Decision edge store unavailable.');
    }
    public static function list_edges_for_run($workspace_id, $run_id) { return class_exists('VES_Decision_Edge_Store') ? VES_Decision_Edge_Store::list_edges_for_run($workspace_id, $run_id) : []; }
    public static function list_edges_for_target($workspace_id, $target_type, $target_id) { return class_exists('VES_Decision_Edge_Store') ? VES_Decision_Edge_Store::list_edges_for_target($workspace_id, $target_type, $target_id) : []; }
    public static function list_edges_from_source($workspace_id, $source_type, $source_id) { return class_exists('VES_Decision_Edge_Store') ? VES_Decision_Edge_Store::list_edges_from_source($workspace_id, $source_type, $source_id) : []; }
}
