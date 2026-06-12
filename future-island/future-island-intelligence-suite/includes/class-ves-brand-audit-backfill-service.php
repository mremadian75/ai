<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin-only historical intelligence backfill for Brand Audit runs.
 * Rebuilds queryable evidence/metrics/patterns/insights/opportunities without rerunning OpenAI.
 */
final class VES_Brand_Audit_Backfill_Service {
    const LOCK_TTL = 600;

    private static function operation_id($scope = '') {
        $scope = sanitize_key((string) $scope);
        return 'bf_' . gmdate('YmdHis') . '_' . substr(md5($scope . ':' . wp_generate_uuid4()), 0, 10);
    }

    private static function lock_key($scope) {
        $scope = sanitize_key((string) $scope);
        return 'ves_backfill_lock_' . ($scope !== '' ? $scope : 'unknown');
    }

    private static function acquire_lock($scope, $operation_id, $force_unlock = false) {
        $key = self::lock_key($scope);
        if ($force_unlock) { delete_transient($key); }
        $existing = get_transient($key);
        if (!empty($existing)) {
            return new WP_Error('backfill_locked', 'Another backfill is already running for this scope.', ['lock' => $existing, 'scope' => $scope]);
        }
        set_transient($key, ['operation_id' => sanitize_text_field((string) $operation_id), 'scope' => sanitize_key((string) $scope), 'started_at' => current_time('mysql')], self::LOCK_TTL);
        return $key;
    }

    private static function release_lock($lock_key) {
        $lock_key = sanitize_key((string) $lock_key);
        if ($lock_key !== '') { delete_transient($lock_key); }
    }

    public static function force_unlock($scope) {
        $key = self::lock_key($scope);
        delete_transient($key);
        return ['scope' => sanitize_key((string) $scope), 'released' => true];
    }

    public static function backfill_single($bundle_id, $options = []) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        $options = is_array($options) ? $options : [];
        $dry_run = !empty($options['dry_run']);
        $rebuild = !empty($options['rebuild']);
        $operation_id = sanitize_text_field((string) ($options['operation_id'] ?? self::operation_id('single_' . $bundle_id)));
        if ($bundle_id === '') { return new WP_Error('invalid_bundle_id', 'Missing Brand Audit bundle id.'); }
        if (!class_exists('VES_Brand_Audit_Execution_Service')) { return new WP_Error('missing_brand_audit_service', 'Brand Audit service is unavailable.'); }
        $lock = '';
        if (!$dry_run) {
            $lock = self::acquire_lock('run_' . md5($bundle_id), $operation_id, !empty($options['force_unlock']));
            if (is_wp_error($lock)) { return $lock; }
        }
        try {
            $bundle = VES_Brand_Audit_Execution_Service::get_bundle($bundle_id);
            if (!$bundle) { return new WP_Error('bundle_not_found', 'Brand Audit run not found.'); }
            return self::backfill_bundle($bundle, $dry_run, $rebuild, $operation_id);
        } catch (Throwable $e) {
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['run_id' => $bundle_id, 'entity_type' => 'brand_audit', 'entity_id' => $bundle_id, 'event_type' => 'backfill_failed', 'actor_role' => 'admin', 'source' => 'backfill', 'request_id' => $operation_id, 'note' => $e->getMessage()]);
            }
            return new WP_Error('backfill_exception', $e->getMessage());
        } finally {
            if (is_string($lock) && $lock !== '') { self::release_lock($lock); }
        }
    }

    public static function backfill_recent($limit = 5, $options = []) {
        global $wpdb;
        $limit = max(1, min(25, (int) $limit));
        $options = is_array($options) ? $options : [];
        $dry_run = !empty($options['dry_run']);
        $operation_id = sanitize_text_field((string) ($options['operation_id'] ?? self::operation_id('recent')));
        if (!class_exists('VES_Brand_Audit_Run_Store')) { return new WP_Error('missing_run_store', 'Brand Audit run store is unavailable.'); }
        $lock = '';
        if (!$dry_run) {
            $lock = self::acquire_lock('recent', $operation_id, !empty($options['force_unlock']));
            if (is_wp_error($lock)) { return $lock; }
        }
        try {
            VES_Brand_Audit_Run_Store::create_table();
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT bundle_id FROM ' . VES_Brand_Audit_Run_Store::table_name() . ' WHERE run_status IN (%s,%s) AND (final_payload_json IS NOT NULL OR evidence_pack_json IS NOT NULL) ORDER BY updated_at DESC, id DESC LIMIT %d',
                'completed',
                'partial',
                $limit
            ), ARRAY_A);
            $results = [];
            foreach ((array) $rows as $row) {
                $bundle_id = sanitize_text_field((string) ($row['bundle_id'] ?? ''));
                if ($bundle_id === '') { continue; }
                $single_options = array_merge($options, ['operation_id' => $operation_id . '_' . substr(md5($bundle_id), 0, 8), 'force_unlock' => false]);
                $result = self::backfill_single($bundle_id, $single_options);
                $results[] = is_wp_error($result) ? ['bundle_id' => $bundle_id, 'error' => $result->get_error_message()] : $result;
            }
            return ['mode' => 'recent', 'limit' => $limit, 'operation_id' => $operation_id, 'results' => $results];
        } catch (Throwable $e) {
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['entity_type' => 'brand_audit', 'entity_id' => 'recent', 'event_type' => 'backfill_failed', 'actor_role' => 'admin', 'source' => 'backfill', 'request_id' => $operation_id, 'note' => $e->getMessage()]);
            }
            return new WP_Error('backfill_recent_exception', $e->getMessage());
        } finally {
            if (is_string($lock) && $lock !== '') { self::release_lock($lock); }
        }
    }

    public static function schema_health() {
        $classes = ['VES_Evidence_Store','VES_Memory_Records','VES_Assistant_Threads','VES_Metric_Snapshots','VES_Pattern_Candidates','VES_Insight_Records','VES_Opportunity_Records','VES_Brief_Records','VES_Workflow_Events'];
        $health = [];
        foreach ($classes as $class) {
            if (class_exists($class) && method_exists($class, 'schema_health_check')) {
                $health[$class] = $class::schema_health_check();
            }
        }
        return $health;
    }

    public static function repair_schema() {
        $classes = ['VES_Evidence_Store','VES_Memory_Records','VES_Assistant_Threads','VES_Metric_Snapshots','VES_Pattern_Candidates','VES_Insight_Records','VES_Opportunity_Records','VES_Brief_Records','VES_Workflow_Events'];
        $result = [];
        foreach ($classes as $class) {
            if (class_exists($class) && method_exists($class, 'maybe_repair_schema')) {
                $result[$class] = $class::maybe_repair_schema();
            }
        }
        return $result;
    }

    private static function backfill_bundle($bundle, $dry_run, $rebuild, $operation_id = '') {
        $bundle = is_array($bundle) ? $bundle : [];
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        if ($bundle_id === '') { return new WP_Error('invalid_bundle', 'Invalid Brand Audit bundle.'); }
        $operation_id = sanitize_text_field((string) ($operation_id !== '' ? $operation_id : self::operation_id('bundle_' . $bundle_id)));
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $final = is_array($bundle['final'] ?? null) ? $bundle['final'] : [];
        $evidence_pack = is_array($bundle['evidence_pack'] ?? null) ? $bundle['evidence_pack'] : (is_array($final['evidence_pack'] ?? null) ? $final['evidence_pack'] : []);
        $sources = is_array($bundle['sources'] ?? null) ? $bundle['sources'] : [];
        $user_id = max(0, (int) ($bundle['user_id'] ?? $request['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($request, ['user_id' => $user_id]), $user_id) : $user_id;
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : (is_array($final['structured'] ?? null) ? $final['structured'] : []);
        $before = self::counts_for_run($bundle_id);
        $summary = [
            'bundle_id' => $bundle_id,
            'operation_id' => $operation_id,
            'dry_run' => (bool) $dry_run,
            'rebuild' => (bool) $rebuild,
            'status' => sanitize_key((string) ($bundle['status'] ?? 'unknown')),
            'before' => $before,
            'would_store_evidence_from_pack' => self::approx_evidence_count($evidence_pack),
            'actions' => [],
        ];
        if ($dry_run) { $summary['after'] = $before; return $summary; }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => get_current_user_id(), 'run_id' => $bundle_id, 'entity_type' => 'brand_audit', 'entity_id' => $bundle_id, 'event_type' => 'backfill_started', 'actor_role' => 'admin', 'source' => 'backfill', 'request_id' => $operation_id, 'note' => 'Admin intelligence backfill started.', 'meta' => ['rebuild' => $rebuild]]);
        }
        try {
            if ($rebuild) {
                foreach (['VES_Metric_Snapshots','VES_Pattern_Candidates','VES_Insight_Records','VES_Opportunity_Records'] as $class) {
                    if (class_exists($class) && method_exists($class, 'delete_for_run')) { $class::delete_for_run($bundle_id); }
                }
                $summary['actions'][] = 'deleted_existing_intelligence_records';
            }
            if (class_exists('VES_Evidence_Store')) {
                if (!empty($evidence_pack)) {
                    $evidence_summary = VES_Evidence_Store::store_brand_audit_evidence_pack($bundle_id, $bundle_id, array_merge($request, ['user_id' => $user_id, 'workspace_id' => $workspace_id]), $evidence_pack, $sources);
                    $summary['evidence_summary'] = $evidence_summary;
                    $summary['actions'][] = 'stored_or_merged_evidence';
                }
                if (method_exists('VES_Evidence_Store', 'repair_evidence_refs_for_run')) {
                    $summary['evidence_ref_repair'] = VES_Evidence_Store::repair_evidence_refs_for_run($bundle_id);
                    $summary['actions'][] = 'repaired_evidence_refs';
                }
            }
            $current = self::counts_for_run($bundle_id);
            $evidence_summary = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::summary_by_run($bundle_id) : [];
            if (($rebuild || (int) ($current['metrics'] ?? 0) <= 0) && class_exists('VES_Metric_Snapshots')) {
                $summary['metrics'] = VES_Metric_Snapshots::calculate_from_evidence($bundle_id, $workspace_id, $user_id, $request, $evidence_summary);
                $summary['actions'][] = 'calculated_metric_snapshots';
            }
            if (($rebuild || (int) ($current['patterns'] ?? 0) <= 0) && class_exists('VES_Pattern_Candidates')) {
                $summary['patterns'] = VES_Pattern_Candidates::extract_from_evidence($bundle_id, $workspace_id, $user_id, $request, $evidence_summary);
                $summary['actions'][] = 'extracted_pattern_candidates';
            }
            if (!empty($structured) && class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'validate_ai_reference_payload')) {
                $structured = VES_Evidence_Store::validate_ai_reference_payload($bundle_id, $structured);
                $summary['actions'][] = 'validated_ai_evidence_refs';
            }
            if (!empty($structured) && ($rebuild || (int) ($current['insights'] ?? 0) <= 0) && class_exists('VES_Insight_Records')) {
                $summary['insights'] = VES_Insight_Records::create_from_final_ai_output($bundle_id, $workspace_id, $user_id, $request, $structured);
                $summary['actions'][] = 'persisted_insight_records';
            }
            $insight_records = class_exists('VES_Insight_Records') ? VES_Insight_Records::list_by_run($bundle_id, 60) : [];
            if (!empty($structured) && ($rebuild || (int) ($current['opportunities'] ?? 0) <= 0) && class_exists('VES_Opportunity_Records')) {
                $summary['opportunities'] = VES_Opportunity_Records::create_from_final_ai_output($bundle_id, $workspace_id, $user_id, $request, $structured, $insight_records);
                $summary['actions'][] = 'persisted_opportunity_records';
            }
            $summary['after'] = self::counts_for_run($bundle_id);
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event')) {
                VES_Usage_Billing::record_meter_event(get_current_user_id(), 'brand_audit_intelligence_backfilled', 'brand_audit', $bundle_id, 'Admin intelligence backfill', ['rebuild' => $rebuild, 'after' => $summary['after'], 'operation_id' => $operation_id], 'meter:backfill:' . $bundle_id . ':' . ($rebuild ? 'rebuild' : 'fill') . ':' . $operation_id);
            }
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => get_current_user_id(), 'run_id' => $bundle_id, 'entity_type' => 'brand_audit', 'entity_id' => $bundle_id, 'event_type' => 'backfill_completed', 'actor_role' => 'admin', 'source' => 'backfill', 'request_id' => $operation_id, 'note' => 'Admin intelligence backfill completed.', 'meta' => $summary['after']]);
            }
            return $summary;
        } catch (Throwable $e) {
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => get_current_user_id(), 'run_id' => $bundle_id, 'entity_type' => 'brand_audit', 'entity_id' => $bundle_id, 'event_type' => 'backfill_failed', 'actor_role' => 'admin', 'source' => 'backfill', 'request_id' => $operation_id, 'note' => $e->getMessage()]);
            }
            return new WP_Error('backfill_bundle_exception', $e->getMessage());
        }
    }

    private static function counts_for_run($run_id) {
        $run_id = sanitize_text_field((string) $run_id);
        return [
            'evidence' => class_exists('VES_Evidence_Store') ? VES_Evidence_Store::count_by_run($run_id) : 0,
            'metrics' => class_exists('VES_Metric_Snapshots') ? VES_Metric_Snapshots::count_by_run($run_id) : 0,
            'patterns' => class_exists('VES_Pattern_Candidates') ? VES_Pattern_Candidates::count_by_run($run_id) : 0,
            'insights' => class_exists('VES_Insight_Records') ? VES_Insight_Records::count_by_run($run_id) : 0,
            'opportunities' => class_exists('VES_Opportunity_Records') ? VES_Opportunity_Records::count_by_run($run_id) : 0,
            'briefs' => class_exists('VES_Brief_Records') ? count(VES_Brief_Records::list_by_run($run_id, 200)) : 0,
        ];
    }

    private static function approx_evidence_count($value) {
        if (!is_array($value)) { return 0; }
        $count = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['url']) || isset($item['title']) || isset($item['text']) || isset($item['caption'])) { $count++; }
                else { $count += self::approx_evidence_count($item); }
            }
        }
        return min(10000, $count);
    }
}
