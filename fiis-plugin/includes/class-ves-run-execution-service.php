<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Run_Execution_Service {
    const SOURCE_JOB_HOOK = 'ves_as_run_source_job';
    const COLLECT_JOB_HOOK = 'ves_as_collect_source_result_job';
    const FINALIZE_JOB_HOOK = 'ves_as_finalize_run_job';

    private static function bundle_key($bundle_id) {
        return function_exists('ves_trend_bundle_key') ? ves_trend_bundle_key($bundle_id) : ('ves_trend_bundle_' . sanitize_key((string) $bundle_id));
    }

    private static function now_mysql() {
        return current_time('mysql');
    }

    public static function make_bundle_id() {
        try {
            return 'vestr-' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'vestr-' . strtolower(wp_generate_password(24, false, false));
        }
    }

    public static function get_bundle($bundle_id) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return null;
        }
        $bundle = get_transient(self::bundle_key($bundle_id));
        if (is_array($bundle)) {
            return $bundle;
        }
        if (class_exists('VES_Trend_Run_Store')) {
            $row = VES_Trend_Run_Store::get_run_by_bundle_id($bundle_id);
            if ($row) {
                return [
                    'bundle_id' => $bundle_id,
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'session_key' => (string) ($row['session_key'] ?? ''),
                    'request' => (array) ($row['request'] ?? []),
                    'query_plan' => (array) ($row['query_plan'] ?? []),
                    'sources' => (array) ($row['sources'] ?? []),
                    'final' => is_array($row['final_payload'] ?? null) ? $row['final_payload'] : [],
                    'status' => (string) ($row['run_status'] ?? 'queued'),
                ];
            }
        }
        return null;
    }

    public static function save_bundle($bundle_id, $bundle) {
        set_transient(self::bundle_key($bundle_id), $bundle, 2 * HOUR_IN_SECONDS);
    }


    private static function classify_provider_error($error) {
        if (!is_wp_error($error)) { return 'failed_unknown'; }
        $code = strtolower((string) $error->get_error_code());
        $msg = strtolower((string) $error->get_error_message());
        $data = is_array($error->get_error_data()) ? $error->get_error_data() : [];
        $provider_state = strtolower((string) ($data['provider_state'] ?? ''));
        $blob = $code . ' ' . $msg . ' ' . $provider_state;
        if (strpos($blob, 'memory') !== false) { return 'running'; }
        if (preg_match('/(source is temporarily busy|actor is temporarily busy|provider busy|try again in a few minutes|too many concurrent runs|rate limited by provider|capacity|temporarily unavailable|temporarily busy|rental busy|apify capacity)/i', $blob)) {
            return 'provider_busy';
        }
        if ((strpos($blob, 'rate limit') !== false || strpos($blob, 'rate_limited') !== false) && strpos($blob, 'provider') !== false) { return 'provider_busy'; }
        if (strpos($blob, 'timeout') !== false || strpos($blob, 'timed') !== false) { return 'failed_timeout'; }
        if (strpos($blob, 'schema') !== false || strpos($blob, 'input') !== false || strpos($blob, 'invalid') !== false || strpos($blob, 'field') !== false) { return 'failed_contract'; }
        if (strpos($blob, 'rental') !== false || strpos($blob, 'permission') !== false || strpos($blob, 'access') !== false || strpos($blob, 'forbidden') !== false || strpos($blob, '401') !== false || strpos($blob, '403') !== false) { return 'failed_access'; }
        return 'failed_provider';
    }

    private static function request_id() {
        try {
            return strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'ves-' . strtolower(wp_generate_password(12, false, false));
        }
    }

    private static function sanitize_source_placeholder($source_key, $source) {
        return [
            'label' => sanitize_text_field((string) ($source['label'] ?? $source_key)),
            'actor_slug' => sanitize_text_field((string) ($source['actor_slug'] ?? '')),
            'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
            'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
            'platform' => sanitize_key((string) ($source['platform'] ?? '')),
            'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
            'stage' => max(1, (int) ($source['stage'] ?? 1)),
            'status' => ((int) ($source['stage'] ?? 1) > 1) ? 'planned' : 'QUEUED',
            'dispatch_status' => ((int) ($source['stage'] ?? 1) > 1) ? 'planned' : 'pending',
            'scrape_status' => ((int) ($source['stage'] ?? 1) > 1) ? 'planned' : 'QUEUED',
            'parse_status' => 'not_started',
            'analytical_usability_status' => 'unknown',
            'notes' => '',
            'run_id' => '',
            'input' => is_array($source['input'] ?? null) ? $source['input'] : [],
            'attempts' => 0,
            'adapter_status' => sanitize_text_field((string) ($source['adapter_status'] ?? '')),
            'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
        ];
    }

    public static function create_trend_run($request, $actor = []) {
        // v0.9.10.9: uploaded plugin updates may skip activation hooks. Build the
        // storage layer here as a last safety net before creating a runnable run.
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::create_table();
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::create_table();
        }

        $built = class_exists('VES_Trend_Query_Planner') ? VES_Trend_Query_Planner::build((array) $request) : [
            'request' => (array) $request,
            'query_plan' => [],
            'payloads' => [],
            'request_hash' => md5(wp_json_encode($request)),
        ];
        $provider_cost = class_exists('VES_Config') ? VES_Config::validate_trend_provider_cost_guard((array) ($built['payloads'] ?? [])) : [];
        if (is_wp_error($provider_cost)) {
            $error_data = $provider_cost->get_error_data();
            $raw_provider_cost = is_array($error_data['provider_cost'] ?? null) ? $error_data['provider_cost'] : [];
            $allow_over_budget = !empty($actor['allow_provider_over_budget']) && function_exists('current_user_can') && current_user_can('manage_options');
            $allow_over_budget = (bool) apply_filters('ves_trend_provider_cost_over_budget_allowed', $allow_over_budget, $raw_provider_cost, $request, $actor);
            if (!$allow_over_budget) {
                if (class_exists('VES_Admin')) {
                    VES_Admin::record_diagnostic('provider_cost_guard_blocked', $provider_cost->get_error_message(), [
                        'provider_cost' => $raw_provider_cost,
                        'stage' => 'create_trend_run',
                    ]);
                }
                return $provider_cost;
            }
            $provider_cost = array_merge($raw_provider_cost, [
                'limit_exceeded' => true,
                'override_accepted' => true,
                'override_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                'note' => 'Admin accepted external provider-cost over-budget run. Internal credit checks still apply separately.',
            ]);
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('provider_cost_guard_overridden', 'Admin accepted provider-cost over-budget Trend Finder run.', [
                    'provider_cost' => $provider_cost,
                    'stage' => 'create_trend_run',
                ]);
            }
        }
        $provider_cost = is_array($provider_cost) ? $provider_cost : [];

        $bundle_id = self::make_bundle_id();
        $user_id = !empty($actor['owner_user_id']) ? (int) $actor['owner_user_id'] : (is_user_logged_in() ? (int) get_current_user_id() : 0);
        $session_key = !empty($actor['session_key']) ? sanitize_key((string) $actor['session_key']) : (class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : '');
        $sources = [];
        foreach ((array) ($built['payloads'] ?? []) as $source_key => $source) {
            $sources[$source_key] = self::sanitize_source_placeholder($source_key, $source);
        }
        $bundle = [
            'bundle_id' => $bundle_id,
            'request' => (array) ($built['request'] ?? []),
            'query_plan' => (array) ($built['query_plan'] ?? []),
            'request_hash' => sanitize_text_field((string) ($built['request_hash'] ?? '')),
            'sources' => $sources,
            'source_payloads' => (array) ($built['payloads'] ?? []),
            'provider_cost' => $provider_cost,
            'user_id' => $user_id,
            'session_key' => sanitize_key((string) $session_key),
            'status' => 'QUEUED',
            'final' => [],
            'created_at' => self::now_mysql(),
            'updated_at' => self::now_mysql(),
            'actor' => is_array($actor) ? $actor : [],
            'monitor_id' => !empty($actor['monitor_id']) ? sanitize_text_field((string) $actor['monitor_id']) : '',
        ];
        self::save_bundle($bundle_id, $bundle);

        $run_id = 0;
        if (class_exists('VES_Trend_Run_Store')) {
            $run_id = VES_Trend_Run_Store::create_run($bundle_id, $bundle['request'], $bundle['query_plan'], $sources, (string) $bundle['request_hash'], ['user_id' => $user_id, 'session_key' => $session_key]);
            if ($run_id <= 0 && method_exists('VES_Trend_Run_Store', 'create_table')) {
                VES_Trend_Run_Store::create_table();
                $run_id = VES_Trend_Run_Store::create_run($bundle_id, $bundle['request'], $bundle['query_plan'], $sources, (string) $bundle['request_hash'], ['user_id' => $user_id, 'session_key' => $session_key]);
            }
            VES_Trend_Run_Store::set_status($bundle_id, 'queued');
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::info((int) $run_id, 'dispatcher', 'Trend run created.', ['bundle_id' => $bundle_id]);
        }

        $reserve = VES_Usage_Reconciliation_Service::reserve_for_bundle($bundle_id, $user_id, !empty($actor['trigger']) && $actor['trigger'] === 'monitor' ? 'background_monitor' : 'trend_run', [
            'topic' => sanitize_text_field((string) ($bundle['request']['topic'] ?? '')),
            'country' => sanitize_text_field((string) ($bundle['request']['country'] ?? '')),
        ]);
        if (is_wp_error($reserve)) {
            self::mark_failed($bundle_id, $reserve->get_error_message(), ['stage' => 'reserve_usage']);
            return new WP_Error('usage_reservation_failed', $reserve->get_error_message(), [
                'status' => (int) (($reserve->get_error_data()['status'] ?? 402) ?: 402),
                'error_category' => 'usage_reservation_failed',
                'stage' => 'reserve_usage',
                'credits_reserved' => false,
                'final_credit_settled' => false,
                'credit_voided' => false,
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
        }
        foreach ($sources as $source_key => $source_meta) {
            if ((int) ($source_meta['stage'] ?? 1) > 1 || strtolower((string) ($source_meta['status'] ?? '')) === 'planned') {
                continue;
            }
            VES_Queue::enqueue(self::SOURCE_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => $source_key,
                'attempt' => 1,
                'run_id' => $run_id,
            ], 'ves', 1);
        }
        return [
            'bundle_id' => $bundle_id,
            'query_plan' => $bundle['query_plan'],
            'sources' => array_values($sources),
            'provider_cost' => $provider_cost,
            'run_id' => $run_id,
        ];
    }

    public static function create_monitor_run_from_monitor($monitor) {
        if (!is_array($monitor) || empty($monitor['id'])) {
            return 0;
        }
        $request = [
            'topic' => (string) ($monitor['topic'] ?? ''),
            'country' => (string) ($monitor['country'] ?? 'None'),
            'duration' => (string) ($monitor['duration'] ?? '7d'),
            'reason' => (string) ($monitor['reason'] ?? ''),
        ];
        $created = self::create_trend_run($request, [
            'monitor_id' => sanitize_text_field((string) $monitor['id']),
            'owner_user_id' => (int) ($monitor['owner_user_id'] ?? 0),
            'trigger' => 'monitor',
        ]);
        if (is_wp_error($created) || empty($created['bundle_id'])) {
            return 0;
        }
        return 1;
    }

    private static function source_terminal($status) {
        $raw = strtolower(str_replace('-', '_', trim((string) $status)));
        return in_array($raw, [
            'succeeded', 'success', 'completed',
            'skipped', 'skipped_invalid_input',
            'provider_busy', 'failed_contract', 'failed_provider', 'failed_empty', 'failed_normalization', 'failed_timeout', 'failed_access', 'failed_unknown',
            'failed', 'aborted', 'timed_out', 'timeout', 'config_error', 'dispatch_error', 'stale_needs_recovery',
            'usable', 'ambient_only', 'no_relevant_signal', 'partial'
        ], true);
    }

    private static function all_sources_terminal($sources) {
        foreach ((array) $sources as $source) {
            if (!self::source_terminal((string) ($source['status'] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private static function has_active_unfinished_source($sources) {
        foreach ((array) $sources as $source) {
            $status = strtolower(str_replace('-', '_', trim((string) ($source['status'] ?? ''))));
            if ($status === 'planned') {
                continue;
            }
            if (!self::source_terminal($status)) {
                return true;
            }
        }
        return false;
    }

    private static function maybe_schedule_next_stage($bundle_id, $run_id = 0) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || !empty($bundle['final'])) { return; }
        $sources = is_array($bundle['sources'] ?? null) ? $bundle['sources'] : [];
        if (self::has_active_unfinished_source($sources)) { return; }
        $next_stage = null;
        foreach ($sources as $source) {
            $status = strtolower(str_replace('-', '_', trim((string) ($source['status'] ?? ''))));
            if ($status !== 'planned') { continue; }
            $stage = max(2, (int) ($source['stage'] ?? 2));
            if ($next_stage === null || $stage < $next_stage) { $next_stage = $stage; }
        }
        if ($next_stage === null) {
            if (self::all_sources_terminal($sources)) { self::schedule_finalize($bundle_id, (int) $run_id); }
            return;
        }
        foreach ($sources as $source_key => $source) {
            $status = strtolower(str_replace('-', '_', trim((string) ($source['status'] ?? ''))));
            if ($status !== 'planned' || (int) ($source['stage'] ?? 2) !== (int) $next_stage) { continue; }
            $bundle['sources'][$source_key]['status'] = 'QUEUED';
            $bundle['sources'][$source_key]['dispatch_status'] = 'pending';
            $bundle['sources'][$source_key]['scrape_status'] = 'QUEUED';
            VES_Queue::enqueue(self::SOURCE_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => sanitize_key((string) $source_key),
                'attempt' => 1,
                'run_id' => (int) $run_id,
            ], 'ves', 1);
        }
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::info((int) $run_id, 'dispatcher', 'Trend Finder staged source expansion queued.', ['bundle_id' => $bundle_id, 'stage' => $next_stage]);
        }
    }


    private static function source_execution_key($bundle, $source_key, $actor_slug, $input, $mode = '') {
        $bundle = is_array($bundle) ? $bundle : [];
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        // v0.9.30.1 idempotency fix: the execution key must be derived from STABLE
        // normalized inputs only. Previously $run_part fell back to bundle_id first,
        // and bundle_id is freshly generated on every create_trend_run() call — so two
        // identical Trend Finder requests (double-click, reload, retry) produced
        // different keys and each dispatched its own Apify run. We now key on the
        // stable request_hash + workspace/user context so the same meaningful request
        // reuses/blocks instead of duplicating provider jobs. bundle_id is no longer
        // part of the key.
        $request_hash = sanitize_text_field((string) ($bundle['request_hash'] ?? ''));
        if ($request_hash === '') {
            // Deterministic fallback: hash the normalized request itself, never bundle_id.
            $request_hash = md5((string) wp_json_encode(self::sort_recursive_for_hash($request)));
        }
        $user_part = (int) ($bundle['user_id'] ?? 0);
        $run_part = $user_part . ':' . $request_hash;
        $source_key = sanitize_key((string) $source_key);
        $actor_slug = strtolower(trim((string) $actor_slug));
        $mode = sanitize_key((string) ($mode ?: ($request['trendType'] ?? ($request['mode'] ?? 'trend_finder'))));
        $normalized_input = is_array($input) ? $input : [];
        $normalized_input = self::sort_recursive_for_hash($normalized_input);
        $normalized_query_hash = md5((string) wp_json_encode($normalized_input));
        return md5($run_part . '|' . $source_key . '|' . $actor_slug . '|' . $normalized_query_hash . '|' . $mode);
    }

    private static function source_execution_transient_key($source_execution_key) {
        return 'ves_src_exec_' . md5((string) $source_execution_key);
    }

    private static function source_execution_option_key($source_execution_key) {
        return 'ves_src_exec_opt_' . md5((string) $source_execution_key);
    }

    private static function get_recorded_source_execution($source_execution_key) {
        $source_execution_key = (string) $source_execution_key;
        if ($source_execution_key === '') { return null; }
        $record = get_transient(self::source_execution_transient_key($source_execution_key));
        if (is_array($record) && !empty($record)) { return $record; }
        $record = get_option(self::source_execution_option_key($source_execution_key), null);
        return (is_array($record) && !empty($record)) ? $record : null;
    }

    private static function sort_recursive_for_hash($value) {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::sort_recursive_for_hash($v);
        }
        return $value;
    }

    private static function record_source_execution($source_execution_key, $data) {
        $source_execution_key = (string) $source_execution_key;
        if ($source_execution_key === '') {
            return;
        }
        $data = is_array($data) ? $data : [];
        $data['source_execution_key'] = $source_execution_key;
        set_transient(self::source_execution_transient_key($source_execution_key), $data, 6 * HOUR_IN_SECONDS);
        $option_key = self::source_execution_option_key($source_execution_key);
        if (get_option($option_key, null) === null) {
            add_option($option_key, $data, '', 'no');
        } else {
            update_option($option_key, $data, false);
        }
    }

    private static function expand_source_summary($ui_sources, $source_summary) {
        $ui_sources = is_array($ui_sources) ? $ui_sources : [];
        $source_summary = is_array($source_summary) ? $source_summary : [];
        $providers_succeeded = 0;
        $providers_failed = 0;
        $raw_rows = 0;
        $normalized_rows = 0;
        $usable_rows = (int) ($source_summary['usable_records'] ?? 0);
        $direct_evidence = (int) ($source_summary['direct_matches'] ?? 0);
        $adjacent_evidence = (int) ($source_summary['adjacent_matches'] ?? 0);
        $noisy_discarded_evidence = (int) ($source_summary['discarded_records'] ?? 0);
        foreach ($ui_sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $status = strtoupper((string) ($source['status'] ?? ''));
            $dispatch_status = strtolower((string) ($source['dispatch_status'] ?? ''));
            if (in_array($dispatch_status, ['ok', 'ok_reused'], true) || in_array($status, ['SUCCEEDED', 'COMPLETED'], true)) {
                $providers_succeeded++;
            } elseif ($dispatch_status !== '' || strpos($status, 'FAILED') !== false || in_array($status, ['TIMEOUT', 'ABORTED'], true)) {
                $providers_failed++;
            }
            $raw_rows += (int) ($source['raw_count'] ?? ($source['raw_rows'] ?? ($source['item_count'] ?? 0)));
            $normalized_rows += (int) ($source['normalized_count'] ?? ($source['normalized_rows'] ?? ($source['usable_count'] ?? 0)));
            $usable_rows += (int) ($source['usable_rows'] ?? 0);
            $direct_evidence += (int) ($source['direct_evidence'] ?? 0);
            $adjacent_evidence += (int) ($source['adjacent_evidence'] ?? 0);
            $noisy_discarded_evidence += (int) ($source['noisy_discarded_evidence'] ?? ($source['discarded_rows'] ?? 0));
        }
        $source_summary['sources_queried'] = count($ui_sources);
        $source_summary['providers_succeeded'] = max((int) ($source_summary['providers_succeeded'] ?? 0), $providers_succeeded);
        $source_summary['providers_failed'] = max((int) ($source_summary['providers_failed'] ?? 0), $providers_failed);
        $source_summary['raw_rows'] = max((int) ($source_summary['raw_rows'] ?? 0), $raw_rows);
        $source_summary['normalized_rows'] = max((int) ($source_summary['normalized_rows'] ?? 0), $normalized_rows, (int) ($source_summary['usable_records'] ?? 0));
        $source_summary['usable_rows'] = max((int) ($source_summary['usable_rows'] ?? 0), $usable_rows, (int) ($source_summary['usable_records'] ?? 0));
        $source_summary['direct_evidence'] = max((int) ($source_summary['direct_evidence'] ?? 0), $direct_evidence, (int) ($source_summary['direct_matches'] ?? 0));
        $source_summary['adjacent_evidence'] = max((int) ($source_summary['adjacent_evidence'] ?? 0), $adjacent_evidence, (int) ($source_summary['adjacent_matches'] ?? 0), (int) ($source_summary['ambient_signals'] ?? 0));
        $source_summary['noisy_discarded_evidence'] = max((int) ($source_summary['noisy_discarded_evidence'] ?? 0), $noisy_discarded_evidence);
        return $source_summary;
    }

    private static function provider_collection_status($source_summary) {
        $source_summary = is_array($source_summary) ? $source_summary : [];
        $succeeded = (int) ($source_summary['providers_succeeded'] ?? 0);
        $failed = (int) ($source_summary['providers_failed'] ?? 0);
        if ($succeeded > 0 && $failed > 0) {
            return 'partial';
        }
        if ($succeeded > 0) {
            return 'succeeded';
        }
        return $failed > 0 ? 'failed' : 'not_started';
    }

    private static function normalization_status($source_summary) {
        $source_summary = is_array($source_summary) ? $source_summary : [];
        $raw = (int) ($source_summary['raw_rows'] ?? 0);
        $normalized = (int) ($source_summary['normalized_rows'] ?? 0);
        $usable = (int) ($source_summary['usable_rows'] ?? 0);
        if ($usable > 0) {
            return 'usable';
        }
        if ($normalized > 0) {
            return 'normalized_low_quality';
        }
        if ($raw > 0) {
            return 'non_normalizable';
        }
        return 'empty';
    }

    private static function analytical_confidence_status($source_summary, $confidence_mode = '') {
        $source_summary = is_array($source_summary) ? $source_summary : [];
        if ((string) $confidence_mode === 'validated') {
            return 'validated';
        }
        $direct = (int) ($source_summary['direct_evidence'] ?? 0);
        $adjacent = (int) ($source_summary['adjacent_evidence'] ?? 0);
        $usable = (int) ($source_summary['usable_rows'] ?? 0);
        if ($direct > 0 && $usable > 0) {
            return 'low_confidence';
        }
        if ($adjacent > 0 || $usable > 0) {
            return 'needs_validation';
        }
        return 'insufficient';
    }

    private static function user_visible_lifecycle_status($source_summary, $run_status, $confidence_mode = '') {
        $source_summary = is_array($source_summary) ? $source_summary : [];
        $provider_status = self::provider_collection_status($source_summary);
        $normalization_status = self::normalization_status($source_summary);
        $confidence_status = self::analytical_confidence_status($source_summary, $confidence_mode);
        $usable = (int) ($source_summary['usable_rows'] ?? 0);
        $adjacent = (int) ($source_summary['adjacent_evidence'] ?? 0);
        $raw = (int) ($source_summary['raw_rows'] ?? 0);
        if ($provider_status === 'failed' || ($provider_status === 'not_started' && $raw <= 0 && $usable <= 0)) {
            return 'failed';
        }
        if ($usable > 0 && $confidence_status === 'validated') {
            return 'completed';
        }
        if ($usable > 0 && $confidence_status === 'needs_validation') {
            return 'needs_validation';
        }
        if ($usable > 0) {
            return 'completed_low_confidence';
        }
        if ($adjacent > 0 || $normalization_status === 'normalized_low_quality') {
            return 'needs_validation';
        }
        if ($raw > 0) {
            return 'completed_with_limitations';
        }
        return sanitize_key((string) ($run_status ?: 'failed'));
    }

    private static function retryable_dispatch_status($status) {
        return in_array((string) $status, ['QUEUED', '', 'UNKNOWN', 'WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT', 'provider_busy'], true);
    }

    private static function start_source($source_key, $source, $request_id, $bundle = []) {
        $source_key = sanitize_key((string) $source_key);
        $source = is_array($source) ? $source : [];
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        if (class_exists('VES_Source_Adapter_Registry')) {
            $source = VES_Source_Adapter_Registry::prepare_source_payload($source_key, $source, $request);
        }
        $actor_slug = trim((string) ($source['actor_slug'] ?? ''));
        $provider_hint = strtolower($source_key . ' ' . (string) ($source['platform'] ?? '') . ' ' . (string) ($source['source_family'] ?? '') . ' ' . $actor_slug);
        $provider_key = '';
        foreach ([
            'google_trends' => 'google_trends',
            'google_news' => 'google_news',
            'google_search' => 'google_search',
            'youtube' => 'youtube',
            'tiktok' => 'tiktok',
            'reddit' => 'reddit',
            'twitter' => 'twitter',
            'x' => 'twitter',
            'instagram' => 'instagram',
            'semrush' => 'semrush',
            'meta_ads' => 'meta_ads',
            'facebook_ads' => 'meta_ads',
            'google_ads' => 'google_ads',
        ] as $needle_provider => $mapped_provider) {
            if (strpos($provider_hint, $needle_provider) !== false) { $provider_key = $mapped_provider; break; }
        }
        if ($provider_key !== '' && class_exists('VES_Access_Control')) {
            $gate_user_id = (int) ($bundle['user_id'] ?? get_current_user_id());
            if (!VES_Access_Control::user_can_access_provider($gate_user_id, $provider_key)) {
                if (class_exists('VES_Admin')) {
                    VES_Admin::record_diagnostic('trend_provider_locked', 'Trend Finder source blocked by provider gate before dispatch.', [
                        'source_key' => $source_key,
                        'provider_key' => $provider_key,
                        'actor_slug' => $actor_slug,
                    ]);
                }
                return [
                    'label' => $source['label'] ?? $source_key,
                    'actor_slug' => $actor_slug,
                    'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                    'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                    'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                    'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                    'status' => 'LOCKED',
                    'dispatch_status' => 'provider_locked',
                    'scrape_status' => 'not_started',
                    'parse_status' => 'not_started',
                    'analytical_usability_status' => 'failed',
                    'notes' => 'Fuente bloqueada por el plan actual antes de llamar al proveedor.',
                    'run_id' => '',
                    'input' => $source['input'] ?? [],
                    'attempts' => 0,
                    'dispatch_attempt_count' => 0,
                    'source_execution_key' => '',
                    'source_execution_reused' => false,
                    'provider_cost_usd' => 0,
                    'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
                ];
            }
        }
        $source_execution_mode = sanitize_key((string) (($request['trendType'] ?? ($request['mode'] ?? 'trend_finder')) ?: 'trend_finder'));
        $source_execution_key = self::source_execution_key($bundle, $source_key, $actor_slug, $source['input'] ?? [], $source_execution_mode);
        $dispatch_attempt_count = max(1, (int) ($source['attempts'] ?? 0) + 1);
        if ($actor_slug === '') {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => '',
                'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                'status' => 'skipped_invalid_input',
                'dispatch_status' => 'skipped_invalid_input',
                'scrape_status' => 'skipped_invalid_input',
                'parse_status' => 'not_started',
                'analytical_usability_status' => 'failed',
                'notes' => 'Actor slug no configurado.',
                'run_id' => '',
                'input' => $source['input'] ?? [],
                'attempts' => $dispatch_attempt_count,
                'dispatch_attempt_count' => $dispatch_attempt_count,
                'source_execution_key' => $source_execution_key,
                'source_execution_reused' => false,
                'provider_cost_usd' => 0,
                'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
            ];
        }
        if (class_exists('VES_Source_Adapter_Registry')) {
            $valid = VES_Source_Adapter_Registry::validate_input($source_key, $actor_slug, $source['input'] ?? []);
            if (is_wp_error($valid)) {
                if (class_exists('VES_Admin')) {
                    VES_Admin::record_diagnostic('adapter_input_invalid', $valid->get_error_message(), $valid->get_error_data());
                }
                $safe_adapter_message = 'This source is not configured correctly. Admin diagnostics has details.';
                return [
                    'label' => $source['label'] ?? $source_key,
                    'actor_slug' => $actor_slug,
                    'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                    'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                    'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                    'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                    'status' => 'skipped_invalid_input',
                    'dispatch_status' => 'skipped_invalid_input',
                    'scrape_status' => 'skipped_invalid_input',
                    'parse_status' => 'not_started',
                    'analytical_usability_status' => 'failed',
                    'notes' => $safe_adapter_message,
                    'run_id' => '',
                    'input' => $source['input'] ?? [],
                    'attempts' => $dispatch_attempt_count,
                    'dispatch_attempt_count' => $dispatch_attempt_count,
                    'source_execution_key' => $source_execution_key,
                    'source_execution_reused' => false,
                    'provider_cost_usd' => 0,
                    'adapter_status' => 'skipped_invalid_input',
                    'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
                    'adapter_error' => $safe_adapter_message,
                    'adapter_error_code' => sanitize_key((string) $valid->get_error_code()),
                ];
            }
        }
        $existing_execution = self::get_recorded_source_execution($source_execution_key);
        // v0.9.30.1: a recorded execution only blocks a new dispatch when it points at a
        // provider run that is still usable (running or succeeded). If the previous run
        // is in a terminal-failed state (FAILED/ABORTED/TIMED-OUT/ERROR/DISPATCH_ERROR),
        // it must NOT permanently block a valid retry — we fall through to a fresh
        // dispatch and log the decision for admin diagnostics.
        $existing_status = is_array($existing_execution)
            ? strtoupper((string) ($existing_execution['status'] ?? ''))
            : '';
        $existing_failed_terminal = $existing_status !== '' && (
            in_array($existing_status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'TIMED_OUT', 'ERROR', 'DISPATCH_ERROR'], true)
            || strpos($existing_status, 'FAILED') !== false
        );
        if (is_array($existing_execution) && !empty($existing_execution['provider_run_id']) && $existing_failed_terminal) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('duplicate_retry_allowed_after_failure', 'Previous provider run for this source key was terminal-failed; allowing a fresh retry dispatch instead of reusing it.', [
                    'source_key' => $source_key,
                    'actor_slug' => $actor_slug,
                    'previous_provider_run_id' => sanitize_text_field((string) $existing_execution['provider_run_id']),
                    'previous_status' => $existing_status,
                    'source_execution_key' => $source_execution_key,
                ]);
            }
            $existing_execution = null;
        }
        if (is_array($existing_execution) && !empty($existing_execution['provider_run_id'])) {
            $existing_run_id = sanitize_text_field((string) $existing_execution['provider_run_id']);
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('trend_duplicate_dispatch_prevented', 'Source dispatch reused an existing provider run instead of launching a duplicate.', [
                    'source_key' => $source_key,
                    'actor_slug' => $actor_slug,
                    'provider_run_id' => $existing_run_id,
                    'source_execution_key' => $source_execution_key,
                ]);
            }
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                'status' => sanitize_text_field((string) ($existing_execution['status'] ?? 'RUNNING')),
                'dispatch_status' => 'ok_reused',
                'scrape_status' => sanitize_text_field((string) ($existing_execution['status'] ?? 'RUNNING')),
                'parse_status' => 'pending',
                'analytical_usability_status' => 'unknown',
                'notes' => 'Dispatch duplicado evitado; se reutiliza la ejecución del proveedor existente.',
                'run_id' => $existing_run_id,
                'provider_run_id' => $existing_run_id,
                'input' => $source['input'] ?? [],
                'attempts' => (int) ($existing_execution['dispatch_attempt_count'] ?? 1),
                'dispatch_attempt_count' => (int) ($existing_execution['dispatch_attempt_count'] ?? 1),
                'last_dispatch_at' => sanitize_text_field((string) ($existing_execution['last_dispatch_at'] ?? self::now_mysql())),
                'provider_cost_usd' => (float) ($existing_execution['provider_cost_usd'] ?? 0),
                'source_execution_key' => $source_execution_key,
                'source_execution_reused' => true,
                'apify_slot_id' => sanitize_text_field((string) ($existing_execution['apify_slot_id'] ?? '')),
                'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
            ];
        }
        $slot = VES_Apify_Client::acquire_dispatch_slot($actor_slug, (int) ($bundle['user_id'] ?? 0), ['source_key' => $source_key, 'request_id' => $request_id]);
        if (is_wp_error($slot)) {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                'status' => 'WAITING_RETRY',
                'dispatch_status' => 'waiting_retry',
                'scrape_status' => 'WAITING_RETRY',
                'parse_status' => 'not_started',
                'analytical_usability_status' => 'unknown',
                'notes' => $slot->get_error_message(),
                'run_id' => '',
                'input' => $source['input'] ?? [],
                'attempts' => $dispatch_attempt_count,
                'dispatch_attempt_count' => $dispatch_attempt_count,
                'source_execution_key' => $source_execution_key,
                'source_execution_reused' => false,
                'provider_cost_usd' => 0,
                'apify_slot_id' => '',
                'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
            ];
        }
        // v0.9.24.7: token is sent via Authorization header; do not include in URL options.
        $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : ['waitForFinish' => 0];
        $options['waitForFinish'] = 0;
        $url = function_exists('ves_make_run_url') ? ves_make_run_url($actor_slug, $options) : '';
        $response = VES_Apify_Client::request('POST', $url, wp_json_encode($source['input'] ?? []));
        if (is_wp_error($response)) {
            VES_Apify_Client::release_dispatch_slot((string) $slot);
            $err_data = is_array($response->get_error_data()) ? $response->get_error_data() : [];
            $provider_state = (string) ($err_data['provider_state'] ?? '');
            $is_memory_limit = $response->get_error_code() === 'apify_memory_limit' || $provider_state === 'QUEUED_RETRY_MEMORY_LIMIT';
            $canonical_error_status = $is_memory_limit ? 'QUEUED_RETRY_MEMORY_LIMIT' : self::classify_provider_error($response);
            $is_provider_busy = $canonical_error_status === 'provider_busy';
            $support_code = 'FI-' . strtoupper(substr(md5($source_key . '|' . $actor_slug . '|' . self::request_id()), 0, 8));
            if ($is_memory_limit && class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('apify_memory_limit', $response->get_error_message(), ['actor_slug' => $actor_slug, 'source_key' => $source_key]);
            }
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
                'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
                'platform' => sanitize_key((string) ($source['platform'] ?? '')),
                'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
                'status' => $canonical_error_status,
                'dispatch_status' => $is_memory_limit ? 'queued_retry_memory_limit' : $canonical_error_status,
                'scrape_status' => $canonical_error_status,
                'parse_status' => 'not_started',
                'analytical_usability_status' => $is_memory_limit ? 'unknown' : 'failed',
                'notes' => $is_memory_limit ? 'Proveedor sin capacidad de memoria ahora; se reintentará en cola.' : ($is_provider_busy ? 'Esta fuente está temporalmente ocupada.' : $response->get_error_message()),
                'error_code' => $canonical_error_status,
                'retryable' => $is_provider_busy,
                'support_code' => $support_code,
                'useful_evidence_count' => 0,
                'safeDiagnostic' => $is_provider_busy ? [
                    'retryable' => true,
                    'support_code' => $support_code,
                    'source_key' => $source_key,
                    'stage' => 'dispatch',
                    'recommended_action' => 'retry_reduced_scope',
                    'message' => 'Esta fuente está temporalmente ocupada.',
                ] : [],
                'actions' => $is_provider_busy ? ['retry', 'reduced_scope', 'diagnostic'] : [],
                'run_id' => '',
                'input' => $source['input'] ?? [],
                'attempts' => $dispatch_attempt_count,
                'dispatch_attempt_count' => $dispatch_attempt_count,
                'source_execution_key' => $source_execution_key,
                'source_execution_reused' => false,
                'provider_cost_usd' => 0,
                'apify_slot_id' => '',
                'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
            ];
        }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $run_id = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        $provider_status = sanitize_text_field((string) ($run_body['data']['status'] ?? 'RUNNING'));
        if ($run_id !== '') { VES_Apify_Client::register_run_id_for_slot((string) $slot, $run_id); }
        self::record_source_execution($source_execution_key, [
            'provider_run_id' => $run_id,
            'source_key' => $source_key,
            'actor_slug' => $actor_slug,
            'status' => $provider_status,
            'dispatch_attempt_count' => $dispatch_attempt_count,
            'last_dispatch_at' => self::now_mysql(),
            'provider_cost_usd' => 0,
            'apify_slot_id' => (string) $slot,
        ]);
        return [
            'label' => $source['label'] ?? $source_key,
            'actor_slug' => $actor_slug,
            'source_family' => sanitize_key((string) ($source['source_family'] ?? 'market_context')),
            'source_role' => sanitize_key((string) ($source['source_role'] ?? 'source')),
            'platform' => sanitize_key((string) ($source['platform'] ?? '')),
            'fallback_to' => sanitize_key((string) ($source['fallback_to'] ?? '')),
            'status' => $provider_status,
            'dispatch_status' => 'ok',
            'scrape_status' => $provider_status,
            'parse_status' => 'pending',
            'analytical_usability_status' => 'unknown',
            'notes' => '',
            'run_id' => $run_id,
            'provider_run_id' => $run_id,
            'input' => $source['input'] ?? [],
            'attempts' => $dispatch_attempt_count,
            'dispatch_attempt_count' => $dispatch_attempt_count,
            'last_dispatch_at' => self::now_mysql(),
            'provider_cost_usd' => 0,
            'source_execution_key' => $source_execution_key,
            'source_execution_reused' => false,
            'apify_slot_id' => (string) $slot,
            'adapter_key' => sanitize_key((string) ($source['adapter_key'] ?? '')),
        ];
    }

    public static function start_queued_sources($bundle_id, $run_id = 0) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || !empty($bundle['final']) || in_array((string) ($bundle['status'] ?? ''), ['ABORTED', 'FAILED'], true)) {
            return $bundle;
        }
        $source_payloads = is_array($bundle['source_payloads'] ?? null) ? $bundle['source_payloads'] : [];
        $changed = false;
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $existing) {
            $source_key = sanitize_key((string) $source_key);
            if ($source_key === '' || empty($source_payloads[$source_key])) {
                continue;
            }
            $existing_status = (string) ($existing['status'] ?? 'QUEUED');
            $already_dispatched = !empty($existing['run_id']) || (($existing['dispatch_status'] ?? '') === 'ok');
            if ($already_dispatched || self::source_terminal($existing_status) || !self::retryable_dispatch_status($existing_status)) {
                continue;
            }
            $started = self::start_source($source_key, $source_payloads[$source_key], self::request_id(), $bundle);
            $bundle['sources'][$source_key] = $started;
            $bundle['status'] = 'RUNNING';
            $bundle['updated_at'] = self::now_mysql();
            $changed = true;
            if (in_array((string) ($started['status'] ?? ''), ['WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true)) {
                VES_Queue::enqueue_retry(self::SOURCE_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'source_key' => $source_key,
                    'attempt' => 2,
                    'run_id' => (int) $run_id,
                ], max(2, (int) ($existing['attempts'] ?? 1) + 1), 90);
                continue;
            }
            if (!empty($started['run_id'])) {
                VES_Queue::enqueue(self::COLLECT_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'source_key' => $source_key,
                    'attempt' => 1,
                    'run_id' => (int) $run_id,
                ], 'ves', 60);
            } elseif (in_array((string) ($started['status'] ?? ''), ['WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true)) {
                VES_Queue::enqueue_retry(self::SOURCE_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'source_key' => $source_key,
                    'run_id' => (int) $run_id,
                ], max(2, (int) ($existing['attempts'] ?? 1) + 1), 120);
            }
        }
        if ($changed) {
            self::save_bundle($bundle_id, $bundle);
            if (class_exists('VES_Trend_Run_Store')) {
                VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
            }
        }
        self::maybe_schedule_next_stage($bundle_id, (int) $run_id);
        return $bundle;
    }


    private static function recover_stale_sources($bundle, $run_id = 0) {
        if (!is_array($bundle) || !empty($bundle['final'])) { return $bundle; }
        $created = strtotime((string) ($bundle['created_at'] ?? ''));
        if (!$created || (time() - $created) < 20 * MINUTE_IN_SECONDS) { return $bundle; }
        $changed = false;
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            if (!is_array($source)) { continue; }
            if (self::source_terminal((string) ($source['status'] ?? ''))) { continue; }
            $bundle['sources'][$source_key]['status'] = 'TIMEOUT';
            $bundle['sources'][$source_key]['scrape_status'] = 'TIMEOUT';
            $bundle['sources'][$source_key]['dispatch_status'] = 'stale_needs_recovery';
            $bundle['sources'][$source_key]['analytical_usability_status'] = 'failed';
            $bundle['sources'][$source_key]['notes'] = 'Trend run exceeded the watchdog window. Finalizing with available evidence; rerun or validate sources manually.';
            $changed = true;
            if (!empty($source['apify_slot_id']) && class_exists('VES_Apify_Client')) {
                VES_Apify_Client::release_dispatch_slot((string) $source['apify_slot_id']);
            } elseif (!empty($source['run_id']) && class_exists('VES_Apify_Client')) {
                VES_Apify_Client::release_dispatch_slot_for_run((string) $source['run_id']);
            }
        }
        if ($changed) {
            $bundle['status'] = 'stale_needs_recovery';
            $bundle['updated_at'] = self::now_mysql();
            self::save_bundle((string) ($bundle['bundle_id'] ?? ''), $bundle);
            if (class_exists('VES_Trend_Run_Store')) {
                VES_Trend_Run_Store::update_sources((string) ($bundle['bundle_id'] ?? ''), $bundle['sources'], 'stale_needs_recovery');
            }
            if (class_exists('VES_Run_Log_Service')) {
                VES_Run_Log_Service::warn((int) $run_id, 'watchdog', 'Trend run marked stale_needs_recovery and will finalize with available evidence.', []);
            }
        }
        return $bundle;
    }

    private static function maybe_mark_stale_sources($bundle_id, $bundle, $run_id = 0) {
        if (!is_array($bundle) || !empty($bundle['final'])) { return $bundle; }
        $created_ts = strtotime((string) ($bundle['created_at'] ?? $bundle['updated_at'] ?? ''));
        if (!$created_ts || (time() - $created_ts) < 20 * MINUTE_IN_SECONDS) { return $bundle; }
        $changed = false;
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            $status = (string) ($source['status'] ?? '');
            if (self::source_terminal($status)) { continue; }
            if (!empty($source['run_id']) && class_exists('VES_Apify_Client')) {
                VES_Apify_Client::release_dispatch_slot_for_run((string) $source['run_id']);
            }
            $bundle['sources'][$source_key]['status'] = 'STALE_NEEDS_RECOVERY';
            $bundle['sources'][$source_key]['dispatch_status'] = 'stale_needs_recovery';
            $bundle['sources'][$source_key]['scrape_status'] = 'STALE_NEEDS_RECOVERY';
            $bundle['sources'][$source_key]['parse_status'] = 'not_started';
            $bundle['sources'][$source_key]['analytical_usability_status'] = 'failed';
            $bundle['sources'][$source_key]['notes'] = 'Run source watchdog: stale running/retry source marked partial so the run can finalize diagnostically.';
            $changed = true;
        }
        if ($changed) {
            $bundle['status'] = 'STALE_NEEDS_RECOVERY';
            $bundle['updated_at'] = self::now_mysql();
            self::save_bundle($bundle_id, $bundle);
            if (class_exists('VES_Trend_Run_Store')) {
                VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'stale_needs_recovery');
            }
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('trend_run_stale_watchdog', 'Stale Trend Finder run marked for partial/fallback finalization.', ['bundle_id' => sanitize_text_field((string) $bundle_id), 'run_id' => (int) $run_id]);
            }
        }
        return $bundle;
    }

    public static function tick_trend_run($bundle_id) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') {
            return null;
        }
        $run_id = 0;
        if (class_exists('VES_Trend_Run_Store')) {
            $row = VES_Trend_Run_Store::get_run_by_bundle_id($bundle_id);
            $run_id = $row ? (int) ($row['id'] ?? 0) : 0;
        }
        self::start_queued_sources($bundle_id, $run_id);
        $bundle = self::get_bundle($bundle_id);
        $bundle = self::maybe_mark_stale_sources($bundle_id, $bundle, $run_id);
        if (!$bundle || !empty($bundle['final'])) {
            return $bundle;
        }
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            $status = (string) ($source['status'] ?? '');
            if (self::source_terminal($status) || empty($source['run_id'])) {
                continue;
            }
            self::collect_source_result_job([
                'bundle_id' => $bundle_id,
                'source_key' => sanitize_key((string) $source_key),
                'attempt' => max(1, (int) ($source['attempts'] ?? 1)),
                'run_id' => $run_id,
            ]);
        }
        $bundle = self::get_bundle($bundle_id);
        if ($bundle && empty($bundle['final'])) {
            $bundle = self::recover_stale_sources($bundle, $run_id);
        }
        if ($bundle && empty($bundle['final'])) {
            self::maybe_schedule_next_stage($bundle_id, (int) $run_id);
            $bundle = self::get_bundle($bundle_id);
        }
        return $bundle;
    }

    public static function run_source_job($payload) {
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $source_key = sanitize_key((string) ($payload['source_key'] ?? ''));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || $source_key === '') {
            return;
        }
        if (!empty($bundle['final']) || in_array((string) ($bundle['status'] ?? ''), ['ABORTED', 'FAILED'], true)) {
            return;
        }
        $source_payloads = is_array($bundle['source_payloads'] ?? null) ? $bundle['source_payloads'] : [];
        if (empty($source_payloads[$source_key])) {
            return;
        }
        $existing = is_array($bundle['sources'][$source_key] ?? null) ? $bundle['sources'][$source_key] : [];
        if (!empty($existing['run_id']) || (($existing['dispatch_status'] ?? '') === 'ok') || self::source_terminal((string) ($existing['status'] ?? ''))) {
            return;
        }
        $started = self::start_source($source_key, $source_payloads[$source_key], self::request_id(), $bundle);
        $bundle['sources'][$source_key] = $started;
        $bundle['status'] = 'RUNNING';
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
        }
        if (in_array((string) ($started['status'] ?? ''), ['WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true)) {
            VES_Queue::enqueue_retry(self::SOURCE_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => $source_key,
                'attempt' => max(2, (int) ($payload['attempt'] ?? 1) + 1),
                'run_id' => (int) ($payload['run_id'] ?? 0),
            ], max(2, (int) ($payload['attempt'] ?? 1) + 1), 90);
            return;
        }
        if (!empty($started['run_id'])) {
            VES_Queue::enqueue(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => $source_key,
                'attempt' => 1,
                'run_id' => (int) ($payload['run_id'] ?? 0),
            ], 'ves', 60);
            return;
        }
        // v0.9.24.7: Dead code removed — the WAITING_RETRY check above this block
        // is unreachable; run_source_job always returns after the run_id block.
        self::maybe_schedule_next_stage($bundle_id, (int) ($payload['run_id'] ?? 0));
    }

    public static function collect_source_result_job($payload) {
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $source_key = sanitize_key((string) ($payload['source_key'] ?? ''));
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || $source_key === '' || empty($bundle['sources'][$source_key])) {
            return;
        }
        if (!empty($bundle['final']) || in_array((string) ($bundle['status'] ?? ''), ['ABORTED', 'FAILED'], true)) {
            return;
        }
        $source = $bundle['sources'][$source_key];
        $run_id = sanitize_text_field((string) ($source['run_id'] ?? ''));
        if ($run_id === '') {
            $bundle['sources'][$source_key]['status'] = 'FAILED';
            $bundle['sources'][$source_key]['notes'] = 'Run ID ausente.';
            self::save_bundle($bundle_id, $bundle);
            if (class_exists('VES_Trend_Run_Store')) {
                VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
            }
            self::schedule_finalize($bundle_id, (int) ($payload['run_id'] ?? 0));
            return;
        }
        $response = VES_Apify_Client::fetch_run($run_id, 0);
        if (is_wp_error($response)) {
            if ($attempt >= 10) {
                $bundle['sources'][$source_key]['status'] = 'TIMEOUT';
                $bundle['sources'][$source_key]['notes'] = $response->get_error_message();
                self::save_bundle($bundle_id, $bundle);
                if (class_exists('VES_Trend_Run_Store')) {
                    VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
                }
                self::schedule_finalize($bundle_id, (int) ($payload['run_id'] ?? 0));
                return;
            }
            VES_Queue::enqueue_retry(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => $source_key,
                'run_id' => (int) ($payload['run_id'] ?? 0),
            ], $attempt + 1, min(300, 30 * $attempt));
            return;
        }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $new_status = sanitize_text_field((string) ($run_body['data']['status'] ?? ($source['status'] ?? 'RUNNING')));
        $bundle['sources'][$source_key]['status'] = $new_status;
        $bundle['sources'][$source_key]['scrape_status'] = $new_status;
        $bundle['sources'][$source_key]['notes'] = sanitize_text_field((string) ($run_body['data']['statusMessage'] ?? ($source['notes'] ?? '')));
        $bundle['sources'][$source_key]['attempts'] = $attempt;
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
        }
        // v0.9.24.7: was 'apify_slot' (never set) — key is 'apify_slot_id'.
        if (self::source_terminal($new_status) && !empty($bundle['sources'][$source_key]['apify_slot_id']) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) {
            VES_Apify_Client::release_dispatch_slot((string) $bundle['sources'][$source_key]['apify_slot_id']);
        }
        if (!self::source_terminal($new_status)) {
            VES_Queue::enqueue_retry(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'source_key' => $source_key,
                'run_id' => (int) ($payload['run_id'] ?? 0),
            ], $attempt + 1, 60);
            return;
        }
        if (!empty($source['apify_slot_id'])) {
            VES_Apify_Client::release_dispatch_slot((string) $source['apify_slot_id']);
        } elseif ($run_id !== '') {
            VES_Apify_Client::release_dispatch_slot_for_run($run_id);
        }
        self::maybe_schedule_next_stage($bundle_id, (int) ($payload['run_id'] ?? 0));
    }

    private static function project_context_memory_terms($project_context) {
        $terms = [];
        foreach ((array) ($project_context['workspace_knowledge'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['title', 'summary'] as $key) {
                if (!empty($item[$key])) {
                    $terms[] = (string) $item[$key];
                }
            }
            if (!empty($item['tags']) && is_array($item['tags'])) {
                $terms = array_merge($terms, array_map('strval', $item['tags']));
            }
        }
        return array_slice(array_values(array_filter($terms)), 0, 16);
    }


    private static function build_fallback_log($ui_sources, $query_plan = []) {
        $plan_sources = is_array($query_plan['source_plan'] ?? null) ? $query_plan['source_plan'] : [];
        $by_key = [];
        foreach ($plan_sources as $src) {
            if (is_array($src) && !empty($src['source_key'])) { $by_key[sanitize_key((string) $src['source_key'])] = $src; }
        }
        $log = [];
        foreach ((array) $ui_sources as $source_key => $source) {
            $source_key = sanitize_key((string) $source_key);
            $fallback = sanitize_key((string) ($source['fallback_to'] ?? ($by_key[$source_key]['fallback_to'] ?? '')));
            if ($fallback === '') { continue; }
            $status = sanitize_key((string) ($source['source_status'] ?? $source['status'] ?? ''));
            $trigger = '';
            if (in_array($status, ['failed_contract','skipped_invalid_input'], true)) { $trigger = 'input_contract_failed'; }
            elseif (in_array($status, ['failed_provider','failed_access','failed_timeout','failed_unknown'], true)) { $trigger = 'provider_failed'; }
            elseif ($status === 'ambient_only' || ((int) ($source['direct_matches'] ?? 0) === 0 && (int) ($source['captured_rows'] ?? 0) > 0)) { $trigger = 'no_direct_topic_match'; }
            elseif ($status === 'failed_empty') { $trigger = 'empty_provider_result'; }
            if ($trigger === '') { continue; }
            $log[] = [
                'attempted_source' => $source_key,
                'fallback_source' => $fallback,
                'trigger_reason' => $trigger,
                'budget_allowed' => true,
                'credits_reserved' => null,
                'result_status' => sanitize_key((string) (($ui_sources[$fallback]['source_status'] ?? $ui_sources[$fallback]['status'] ?? 'not_attempted'))),
            ];
        }
        return $log;
    }

    private static function source_fetch_limit($source_key, $source = []) {
        $source_key = sanitize_key((string) $source_key);
        if ($source_key === 'twitter_trends') {
            return class_exists('VES_Config') ? VES_Config::trend_max_twitter_trend_items() : 40;
        }
        if ($source_key === 'youtube_trending') {
            return 20;
        }
        return 80;
    }


    private static function trend_source_diagnostics($ui_sources) {
        $diagnostics = [];
        foreach ((array) $ui_sources as $src) {
            $source_key = sanitize_key((string) ($src['source_key'] ?? $src['label'] ?? 'source'));
            $status = sanitize_key((string) ($src['source_status'] ?? $src['status'] ?? $src['dispatch_status'] ?? 'unknown'));
            $dispatch = sanitize_key((string) ($src['dispatch_status'] ?? ''));
            if ($status === 'provider_busy' || $dispatch === 'provider_busy') {
                $support = sanitize_text_field((string) ($src['support_code'] ?? ('FI-' . strtoupper(substr(md5($source_key), 0, 8)))));
                $diagnostics[] = [
                    'source_key' => $source_key,
                    'status' => 'provider_busy',
                    'error_code' => 'provider_busy',
                    'retryable' => true,
                    'support_code' => $support,
                    'useful_evidence_count' => 0,
                    'message' => 'Esta fuente está temporalmente ocupada.',
                    'stage' => 'dispatch',
                    'recommended_action' => 'retry_reduced_scope',
                ];
                continue;
            }
            if (in_array($status, ['failed_provider','failed_timeout','failed_access','failed_contract','failed_empty','failed_normalization','failed_unknown'], true)) {
                $diagnostics[] = [
                    'source_key' => $source_key,
                    'status' => $status,
                    'error_code' => $status,
                    'retryable' => in_array($status, ['failed_timeout','failed_provider'], true),
                    'support_code' => sanitize_text_field((string) ($src['support_code'] ?? ('FI-' . strtoupper(substr(md5($source_key . $status), 0, 8))))),
                    'useful_evidence_count' => 0,
                    'message' => sanitize_text_field((string) ($src['notes'] ?? $src['exclusion_reason'] ?? 'La fuente no devolvió evidencia usable.')),
                    'stage' => 'dispatch',
                ];
            }
        }
        return $diagnostics;
    }

    private static function trend_useful_evidence_count($source_summary) {
        $source_summary = is_array($source_summary) ? $source_summary : [];
        return max(
            0,
            (int) ($source_summary['usable_records'] ?? 0),
            (int) ($source_summary['usable_rows'] ?? 0),
            (int) ($source_summary['direct_evidence'] ?? 0),
            (int) ($source_summary['direct_matches'] ?? 0),
            (int) ($source_summary['adjacent_evidence'] ?? 0),
            (int) ($source_summary['adjacent_matches'] ?? 0)
        );
    }

    private static function build_retryable_trend_diagnostic($bundle, $request, $ui_sources, $source_summary, $request_id) {
        $diagnostics = self::trend_source_diagnostics($ui_sources);
        $busy_count = 0;
        foreach ($diagnostics as $diag) { if (($diag['error_code'] ?? '') === 'provider_busy') { $busy_count++; } }
        $support = !empty($diagnostics[0]['support_code']) ? (string) $diagnostics[0]['support_code'] : ('FI-' . strtoupper(substr(md5((string) ($bundle['bundle_id'] ?? '') . '|' . $request_id), 0, 8)));
        $message = $busy_count > 0
            ? 'Las fuentes están temporalmente ocupadas. No se consumieron créditos finales. Reintenta en unos minutos o usa una búsqueda reducida.'
            : 'No se obtuvo evidencia usable. No se consumieron créditos finales. Reintenta en unos minutos o usa una búsqueda reducida.';
        return [
            'platform' => 'trend_finder',
            'status' => 'RETRYABLE_DIAGNOSTIC',
            'run_status' => 'retryable_diagnostic',
            'run_lifecycle_status' => 'retryable_diagnostic',
            'user_visible_lifecycle_status' => 'retryable_diagnostic',
            'provider_collection_status' => 'failed',
            'normalization_status' => 'empty',
            'analytical_confidence_status' => 'insufficient',
            'user_message' => $message,
            'message' => $message,
            'error_code' => $busy_count > 0 ? 'provider_busy' : 'no_usable_evidence',
            'retryable' => true,
            'safeDiagnostic' => [
                'retryable' => true,
                'support_code' => $support,
                'source_key' => (string) ($diagnostics[0]['source_key'] ?? ''),
                'stage' => 'dispatch',
                'recommended_action' => 'retry_reduced_scope',
            ],
            'actions' => ['retry', 'reduced_scope', 'diagnostic'],
            'confidence_mode' => 'insufficient_evidence',
            'fallback_used' => true,
            'validation_required' => true,
            'can_generate_brief' => false,
            'can_generate_test_brief' => false,
            'brief_readiness_status' => 'locked_no_insights',
            'memory_type' => 'source_failure_history',
            'report_status' => 'retryable_diagnostic',
            'report_mode' => 'retryable_diagnostic',
            'analysis' => $message,
            'analysis_structured' => [
                'executive_summary' => $message,
                'data_quality_summary' => [
                    'overall_confidence' => 'insuficiente',
                    'direct_topic_signal' => 'sin evidencia usable',
                    'what_is_inference_vs_evidence' => 'No se generó insight porque las fuentes no devolvieron evidencia usable.',
                    'main_blindspots' => ['Fuentes ocupadas o sin evidencia usable.'],
                ],
                'prioritized_trends' => [],
            ],
            'analysis_format' => 'diagnostic',
            'model' => 'Proveedor no disponible',
            'requested' => [
                'topic' => $request['topic'] ?? '',
                'country' => $request['country'] ?? 'None',
                'country_label' => function_exists('ves_trend_label_country') ? ves_trend_label_country($request['country'] ?? 'None') : ($request['country'] ?? 'None'),
                'duration' => $request['duration'] ?? '7d',
                'duration_label' => function_exists('ves_trend_duration_label') ? ves_trend_duration_label($request['duration'] ?? '7d') : ($request['duration'] ?? '7d'),
                'reason' => $request['reason'] ?? '',
            ],
            'sources' => $ui_sources,
            'source_diagnostics' => $diagnostics,
            'source_summary' => $source_summary,
            'sources_queried' => (int) ($source_summary['sources_queried'] ?? count($ui_sources)),
            'providers_succeeded' => (int) ($source_summary['providers_succeeded'] ?? 0),
            'providers_failed' => (int) ($source_summary['providers_failed'] ?? count($diagnostics)),
            'raw_provider_rows' => (int) ($source_summary['raw_rows'] ?? 0),
            'normalized_rows' => (int) ($source_summary['normalized_rows'] ?? 0),
            'usable_rows' => 0,
            'direct_evidence' => 0,
            'adjacent_evidence' => 0,
            'successful_sources' => 0,
            'usable_sources' => 0,
            'partial_sources' => (int) ($source_summary['partial_sources'] ?? 0),
            'failed_sources' => (int) ($source_summary['failed_sources'] ?? count($diagnostics)),
            'total_sources' => (int) ($source_summary['total_sources'] ?? count($ui_sources)),
            'evidence_coverage_summary' => is_array($source_summary['evidence_coverage_summary'] ?? null) ? $source_summary['evidence_coverage_summary'] : [],
            'source_quality_breakdown' => is_array($source_summary['source_quality_breakdown'] ?? null) ? $source_summary['source_quality_breakdown'] : [],
            'useful_evidence_count' => 0,
            'usable_records' => 0,
            'credits_reserved' => true,
            'final_credit_settled' => false,
            'credit_voided' => true,
            'usage_ledger' => [
                'credits_reserved' => true,
                'final_credit_settled' => false,
                'credit_voided' => true,
                'useful_evidence_count' => 0,
            ],
            'fusion_summary' => [],
            'fallback_log' => [],
            'insights' => [],
            'brief_ready_insights' => [],
            'generated_at' => current_time('mysql'),
            'request_id' => $request_id,
            'bundle_id' => $bundle['bundle_id'] ?? '',
            'provider_cost' => is_array($bundle['provider_cost'] ?? null) ? $bundle['provider_cost'] : [],
            'query_plan' => is_array($bundle['query_plan'] ?? null) ? $bundle['query_plan'] : [],
        ];
    }

    private static function finalize_bundle($bundle, $request_id) {
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        if (!empty($bundle['query_plan']['expanded_queries']) && is_array($bundle['query_plan']['expanded_queries'])) {
            $request['expanded_queries'] = $bundle['query_plan']['expanded_queries'];
        }
        if (!empty($bundle['query_plan']['negative_terms']) && is_array($bundle['query_plan']['negative_terms'])) {
            $request['negative_terms'] = $bundle['query_plan']['negative_terms'];
        }
        $sources = is_array($bundle['sources'] ?? null) ? $bundle['sources'] : [];
        $ui_sources = [];
        foreach ($sources as $source_key => $source) {
            $status = (string) ($source['status'] ?? 'UNKNOWN');
            $raw_items = [];
            if (strtolower((string) $status) === 'succeeded' && !empty($source['run_id'])) {
                $raw_items = VES_Apify_Client::fetch_items($source['run_id'], self::source_fetch_limit($source_key, $source));
                if (is_wp_error($raw_items)) {
                    $status = 'FAILED';
                    $source['status'] = 'FAILED';
                    $source['notes'] = $raw_items->get_error_message();
                    $raw_items = [];
                }
            }
            // v0.9.9.3: honour date_from on background trend bundles too.
            $date_from_iso = (string) ($request['date_from'] ?? '');
            if ($date_from_iso !== '' && function_exists('ves_filter_items_by_date_from') && is_array($raw_items) && !empty($raw_items)) {
                list($raw_items, ) = ves_filter_items_by_date_from($raw_items, $date_from_iso);
            }
            if (class_exists('VES_Trend_Source_Evaluator')) {
                $ui_sources[$source_key] = VES_Trend_Source_Evaluator::evaluate_source($source_key, array_merge((array) $source, ['status' => $status]), $raw_items, $request);
            }
        }
        $owner_user_id = !empty($bundle['user_id']) ? (int) $bundle['user_id'] : 0;
        $project_context = class_exists('VES_Project_Context') && $owner_user_id > 0 ? VES_Project_Context::build_ai_context_block($owner_user_id, [
            'platform' => 'trend_finder',
            'topic' => $request['topic'] ?? '',
            'focus' => $request['reason'] ?? '',
            'country' => $request['country'] ?? '',
            'reason' => $request['reason'] ?? '',
        ]) : null;
        $related_memory = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::get_related_memory_context([
            'user_id' => $owner_user_id,
            'platform' => 'trend_finder',
            'topic' => $request['topic'] ?? '',
            'focus' => $request['reason'] ?? '',
            'country' => $request['country'] ?? '',
            'brand_name' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '',
            'primary_goal' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['primary_goal'] ?? '') : '',
            'website_url' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['website_url'] ?? '') : '',
            'markets' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['markets'] ?? '') : '',
            'competitors' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['competitors'] ?? '') : '',
            'workspace_knowledge_terms' => self::project_context_memory_terms($project_context),
        ], 5) : [];
        $source_summary = class_exists('VES_Trend_Source_Evaluator') ? VES_Trend_Source_Evaluator::summarize_run_sources($ui_sources) : [
            'total_sources' => count($ui_sources),
            'usable_sources' => count($ui_sources),
            'partial_sources' => 0,
            'failed_sources' => 0,
            'metric_average' => 0,
        ];
        $source_summary = self::expand_source_summary($ui_sources, $source_summary);
        $fusion = class_exists('VES_Trend_Fusion_Engine') ? VES_Trend_Fusion_Engine::fuse($ui_sources, $source_summary, $request) : [];
        $useful_evidence_count = self::trend_useful_evidence_count($source_summary);
        if ($useful_evidence_count <= 0) {
            return self::build_retryable_trend_diagnostic($bundle, $request, $ui_sources, $source_summary, $request_id);
        }
        $source_diagnostics = self::trend_source_diagnostics($ui_sources);
        $has_source_failures = !empty($source_diagnostics) || (int) ($source_summary['failed_sources'] ?? 0) > 0 || (int) ($source_summary['provider_busy_sources'] ?? 0) > 0;
        $report_mode = sanitize_key((string) ($fusion['report_mode'] ?? ''));
        $brief_readiness_status = sanitize_key((string) ($fusion['brief_readiness_status'] ?? 'validation_needed'));
        $run_status = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_status($source_summary) : 'completed';
        $confidence_mode = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_mode($source_summary) : 'validated';
        if ($report_mode === 'trend_confirmed') { $confidence_mode = 'validated'; }
        elseif (in_array($report_mode, ['trend_emerging','weak_signal_validation_needed','no_actionable_trend_found','source_failure_partial_report'], true)) { $confidence_mode = 'exploratory'; }
        $fallback_log = self::build_fallback_log($ui_sources, is_array($bundle['query_plan'] ?? null) ? $bundle['query_plan'] : []);
        $ai_request = array_merge($request, [
            'normalized_request' => $request,
            'source_plan' => is_array($bundle['query_plan']['source_plan'] ?? null) ? $bundle['query_plan']['source_plan'] : [],
            'expanded_queries' => is_array($bundle['query_plan']['expanded_queries'] ?? null) ? $bundle['query_plan']['expanded_queries'] : ($request['expanded_queries'] ?? []),
            'source_diagnostics' => $ui_sources,
            'source_coverage_summary' => $source_summary,
            'fallback_log' => $fallback_log,
            'fusion_result' => $fusion,
            'report_mode' => $report_mode,
            'brief_readiness' => $brief_readiness_status,
            'credit_audit' => [
                'provider_cost' => is_array($bundle['provider_cost'] ?? null) ? $bundle['provider_cost'] : [],
                'useful_signal_count' => (int) ($source_summary['usable_records'] ?? 0),
                'refundable_failed_sources' => (int) ($source_summary['failed_sources'] ?? 0),
            ],
        ]);
        $analysis = VES_OpenAI_Client::analyze_trend_bundle($ai_request, $ui_sources, $related_memory, $project_context);
        if (is_wp_error($analysis)) {
            return $analysis;
        }
        $analysis_structured = is_array($analysis['analysis_structured'] ?? null) ? $analysis['analysis_structured'] : null;
        $fallback_used = !empty($analysis['fallback_used']) || !empty($analysis_structured['fallback_used']) || (($analysis['confidence_mode'] ?? '') === 'diagnostic_fallback') || (($analysis_structured['confidence_mode'] ?? '') === 'diagnostic_fallback');
        if ($fallback_used) {
            $run_status = 'fallback_completed';
            $confidence_mode = 'diagnostic_fallback';
        }
        $insights = (!$fallback_used && class_exists('VES_Trend_Scoring')) ? VES_Trend_Scoring::build_insights_from_analysis($analysis_structured, $source_summary, $confidence_mode) : [];
        if ($report_mode === '') {
            $report_mode = $fallback_used ? 'source_failure_partial_report' : (($confidence_mode === 'validated') ? 'trend_confirmed' : 'weak_signal_validation_needed');
        }
        if ($fallback_used) {
            $brief_readiness_status = 'locked_no_insights';
        }
        $validated_enough_for_brief = !$fallback_used && $brief_readiness_status === 'ready_for_campaign_brief' && (int) ($source_summary['usable_sources'] ?? 0) >= 2 && (int) ($source_summary['direct_matches'] ?? 0) > 0;
        $can_generate_test_brief = !$fallback_used && $brief_readiness_status === 'ready_for_test_brief';
        $brief_ready_insights = $validated_enough_for_brief ? array_map(static function ($insight) {
            return [
                'insight_id' => 0,
                'trend_name' => sanitize_text_field((string) ($insight['trend_name'] ?? 'Trend')),
                'fit_score' => $insight['fit_score'] ?? null,
                'evidence_score' => $insight['evidence_score'] ?? null,
                'confidence_score' => $insight['confidence_score'] ?? null,
                'actionability_score' => $insight['actionability_score'] ?? null,
                'priority_score' => $insight['priority_score'] ?? null,
                'status' => sanitize_key((string) ($insight['status'] ?? 'new')),
            ];
        }, $insights) : [];
        $provider_collection_status = self::provider_collection_status($source_summary);
        $normalization_status = self::normalization_status($source_summary);
        $analytical_confidence_status = self::analytical_confidence_status($source_summary, $confidence_mode);
        $user_visible_lifecycle_status = self::user_visible_lifecycle_status($source_summary, $run_status, $confidence_mode);
        if ($run_status === 'failed' && $user_visible_lifecycle_status !== 'failed') {
            $run_status = 'partial';
        }
        $trend_user_message = 'Ejecución completada.';
        if (!empty($has_source_failures)) {
            $trend_user_message = 'Ejecución completada con evidencia parcial. Algunas fuentes no estuvieron disponibles.';
            $report_mode = $report_mode === 'trend_confirmed' ? 'source_failure_partial_report' : ($report_mode ?: 'source_failure_partial_report');
            $brief_readiness_status = 'locked_no_insights';
            $validated_enough_for_brief = false;
        } elseif (in_array($user_visible_lifecycle_status, ['needs_validation', 'completed_low_confidence', 'completed_with_limitations', 'partial'], true)) {
            $trend_user_message = 'Ejecución completada con evidencia limitada. Algunas fuentes devolvieron datos, pero la evidencia no es suficiente para una recomendación de tendencia segura. Usa el plan de validación antes de convertirlo en campaña.';
        } elseif ($user_visible_lifecycle_status === 'failed') {
            $trend_user_message = 'La ejecución falló porque ninguna fuente devolvió evidencia usable o parcial.';
        }
        return [
            'platform' => 'trend_finder',
            'status' => 'SUCCEEDED',
            'run_status' => $run_status,
            'run_lifecycle_status' => $run_status,
            'user_visible_lifecycle_status' => $user_visible_lifecycle_status,
            'provider_collection_status' => $provider_collection_status,
            'normalization_status' => $normalization_status,
            'analytical_confidence_status' => $analytical_confidence_status,
            'user_message' => $trend_user_message,
            'message' => $trend_user_message,
            'actions' => !empty($has_source_failures) ? ['diagnostic'] : [],
            'source_diagnostics' => !empty($source_diagnostics) ? $source_diagnostics : [],
            'useful_evidence_count' => (int) ($useful_evidence_count ?? 0),
            'credits_reserved' => true,
            'final_credit_settled' => true,
            'credit_voided' => false,
            'usage_ledger' => [
                'credits_reserved' => true,
                'final_credit_settled' => true,
                'credit_voided' => false,
                'useful_evidence_count' => (int) ($useful_evidence_count ?? 0),
            ],
            'confidence_mode' => $confidence_mode,
            'fallback_used' => $fallback_used,
            'validation_required' => $fallback_used || !in_array($brief_readiness_status, ['ready_for_campaign_brief','ready_for_test_brief'], true),
            'can_generate_brief' => !empty($has_source_failures) ? false : $validated_enough_for_brief,
            'can_generate_test_brief' => $can_generate_test_brief,
            'brief_readiness_status' => $fallback_used ? 'locked_no_insights' : $brief_readiness_status,
            'memory_type' => $fallback_used ? 'source_failure_history' : (($report_mode === 'trend_confirmed') ? 'validated_insight' : (($report_mode === 'trend_emerging') ? 'weak_signal_history' : (($report_mode === 'source_failure_partial_report') ? 'source_failure_history' : 'weak_signal_history'))),
            'report_status' => $fallback_used ? 'fallback_diagnostic' : (($report_mode === 'trend_confirmed') ? 'validated_report' : (($report_mode === 'no_actionable_trend_found') ? 'no_actionable_trend_found' : 'validation_report')),
            'report_mode' => $report_mode,
            'fusion_summary' => $fusion,
            'fallback_log' => $fallback_log,
            'analysis' => $analysis['analysis'],
            'analysis_structured' => $analysis_structured,
            'analysis_format' => $analysis['format'] ?? 'text',
            'model' => $analysis['model'] ?? '',
            'requested' => [
                'topic' => $request['topic'] ?? '',
                'country' => $request['country'] ?? 'None',
                'country_label' => function_exists('ves_trend_label_country') ? ves_trend_label_country($request['country'] ?? 'None') : ($request['country'] ?? 'None'),
                'duration' => $request['duration'] ?? '7d',
                'duration_label' => function_exists('ves_trend_duration_label') ? ves_trend_duration_label($request['duration'] ?? '7d') : ($request['duration'] ?? '7d'),
                'reason' => $request['reason'] ?? '',
            ],
            'sources' => $ui_sources,
            'source_summary' => $source_summary,
            'sources_queried' => (int) ($source_summary['sources_queried'] ?? count($ui_sources)),
            'providers_succeeded' => (int) ($source_summary['providers_succeeded'] ?? 0),
            'providers_failed' => (int) ($source_summary['providers_failed'] ?? 0),
            'raw_provider_rows' => (int) ($source_summary['raw_rows'] ?? 0),
            'normalized_rows' => (int) ($source_summary['normalized_rows'] ?? 0),
            'usable_rows' => (int) ($source_summary['usable_rows'] ?? ($source_summary['usable_records'] ?? 0)),
            'direct_evidence' => (int) ($source_summary['direct_evidence'] ?? ($source_summary['direct_matches'] ?? 0)),
            'adjacent_evidence' => (int) ($source_summary['adjacent_evidence'] ?? ($source_summary['ambient_signals'] ?? 0)),
            'noisy_discarded_evidence' => (int) ($source_summary['noisy_discarded_evidence'] ?? 0),
            'successful_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'usable_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'partial_sources' => (int) ($source_summary['partial_sources'] ?? 0),
            'failed_sources' => (int) ($source_summary['failed_sources'] ?? 0),
            'total_sources' => (int) ($source_summary['total_sources'] ?? 0),
            'evidence_coverage_summary' => is_array($source_summary['evidence_coverage_summary'] ?? null) ? $source_summary['evidence_coverage_summary'] : [],
            'source_quality_breakdown' => is_array($source_summary['source_quality_breakdown'] ?? null) ? $source_summary['source_quality_breakdown'] : [],
            'ambient_signals' => (int) ($source_summary['ambient_signals'] ?? 0),
            'format_signals' => (int) ($source_summary['format_signals'] ?? 0),
            'pain_point_signals' => (int) ($source_summary['pain_point_signals'] ?? 0),
            'generated_at' => current_time('mysql'),
            'request_id' => $request_id,
            'bundle_id' => $bundle['bundle_id'] ?? '',
            'project_context_used' => !empty($project_context),
            'related_reports_used' => is_array($related_memory) ? count($related_memory) : 0,
            'provider_cost' => is_array($bundle['provider_cost'] ?? null) ? $bundle['provider_cost'] : (class_exists('VES_Config') ? VES_Config::estimate_trend_provider_cost((array) ($bundle['source_payloads'] ?? [])) : []),
            'query_plan' => is_array($bundle['query_plan'] ?? null) ? $bundle['query_plan'] : [],
            'insights' => $insights,
            'brief_ready_insights' => $brief_ready_insights,
        ];
    }

    private static function schedule_finalize($bundle_id, $run_id) {
        VES_Queue::enqueue_unique(self::FINALIZE_JOB_HOOK, [
            'bundle_id' => $bundle_id,
            'run_id' => (int) $run_id,
            '_ves_job_key' => 'finalize_' . md5((string) $bundle_id),
        ], 'ves', 5);
    }

    public static function finalize_run_job($payload) {
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) {
            return;
        }
        if (!empty($bundle['final'])) {
            return;
        }
        $final = self::finalize_bundle($bundle, self::request_id());
        if (is_wp_error($final)) {
            self::mark_failed($bundle_id, $final->get_error_message(), ['stage' => 'finalize']);
            return;
        }
        $memory_meta = [];
        if (class_exists('VES_Temp_Memory')) {
            $memory_meta = VES_Temp_Memory::save_trend_report($bundle['request'] ?? [], $final);
            if (!empty($memory_meta['id'])) {
                $final['memory_entry_id'] = (int) $memory_meta['id'];
            }
            if (!empty($memory_meta['search_hash'])) {
                $comparison = VES_Temp_Memory::build_trend_comparison($final, (string) $memory_meta['search_hash'], (int) ($memory_meta['id'] ?? 0));
                if (!empty($comparison)) {
                    $final['comparison'] = $comparison;
                }
            }
            $final['trend_history'] = VES_Temp_Memory::get_recent_trend_reports(10, [
                'topic' => (string) (($bundle['request']['topic'] ?? '')),
                'country' => (string) (($bundle['request']['country'] ?? '')),
                'duration' => (string) (($bundle['request']['duration'] ?? '')),
            ]);
        }
        if (class_exists('VES_Trend_Run_Store')) {
            $run_row = VES_Trend_Run_Store::get_run_by_bundle_id($bundle_id);
            if ($run_row && !empty($final['memory_entry_id'])) {
                VES_Trend_Run_Store::attach_memory_entry($bundle_id, (int) $final['memory_entry_id']);
            }
            if ($run_row && class_exists('VES_Trend_Insight_Store')) {
                $insight_ids = VES_Trend_Insight_Store::replace_for_run((int) $run_row['id'], (int) ($final['memory_entry_id'] ?? 0), (array) ($final['insights'] ?? []));
                $final['insight_ids'] = $insight_ids;
                if (!empty($final['memory_entry_id']) && !empty($final['can_generate_brief'])) {
                    $final['brief_ready_insights'] = VES_Trend_Insight_Store::get_brief_ready_insights_for_memory_entry((int) $final['memory_entry_id']);
                } elseif (empty($final['can_generate_brief'])) {
                    $final['brief_ready_insights'] = [];
                }
                VES_Trend_Run_Store::complete_run($bundle_id, (string) ($final['run_status'] ?? 'completed'), $final, (string) ($final['confidence_mode'] ?? ''), (int) ($final['memory_entry_id'] ?? 0));
            }
        }
        if (!empty($final['memory_entry_id']) && class_exists('VES_Temp_Memory')) {
            $final['trend_briefs'] = VES_Temp_Memory::get_recent_trend_briefs((int) $final['memory_entry_id'], 8);
        }
        if (!empty($bundle['monitor_id']) && class_exists('VES_Monitor_Store')) {
            VES_Monitor_Store::complete_monitor_run($bundle['monitor_id'], [
                'last_status' => in_array((string) ($final['run_status'] ?? 'failed'), ['completed', 'partial', 'fallback_completed', 'completed_low_confidence', 'needs_validation', 'completed_with_limitations'], true) ? 'succeeded' : 'failed',
                'last_average_score' => self::metric_from_report($final),
                'last_run_note' => sanitize_text_field((string) ($final['analysis_structured']['overall_take'] ?? 'Monitor ejecutado.')),
            ]);
        }
        VES_Usage_Reconciliation_Service::finalize_for_bundle($bundle_id, (string) ($final['run_status'] ?? 'failed'), 'Trend Finder finalizado', $final);
        $bundle['final'] = $final;
        $bundle['status'] = (string) ($final['status'] ?? 'SUCCEEDED');
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Run_Log_Service') && class_exists('VES_Trend_Run_Store')) {
            $run_row = VES_Trend_Run_Store::get_run_by_bundle_id($bundle_id);
            if ($run_row) {
                VES_Run_Log_Service::info((int) $run_row['id'], 'finalizer', 'Trend run finalized.', ['run_status' => $final['run_status'] ?? 'completed']);
            }
        }
    }

    private static function metric_from_report($final) {
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $scores = [];
        foreach ((array) ($structured['prioritized_trends'] ?? []) as $row) {
            if (isset($row['fit_score'])) {
                $scores[] = (float) $row['fit_score'];
            }
        }
        if (empty($scores)) {
            return null;
        }
        return round(array_sum($scores) / count($scores), 1);
    }

    public static function abort_trend_run($bundle_id, $message = '') {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) {
            return false;
        }
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            if (self::source_terminal((string) ($source['status'] ?? ''))) {
                continue;
            }
            if (!empty($source['run_id'])) {
                VES_Apify_Client::abort_run((string) $source['run_id']);
            }
            $bundle['sources'][$source_key]['status'] = 'ABORTED';
            $bundle['sources'][$source_key]['notes'] = sanitize_text_field($message !== '' ? $message : 'Abortado por el usuario.');
        }
        $bundle['status'] = 'ABORTED';
        $bundle['final'] = [
            'platform' => 'trend_finder',
            'status' => 'ABORTED',
            'run_status' => 'failed',
            'requested' => [
                'topic' => (string) (($bundle['request']['topic'] ?? '')),
            ],
            'generated_at' => current_time('mysql'),
        ];
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::update_sources($bundle_id, $bundle['sources'], 'aborted');
            VES_Trend_Run_Store::complete_run($bundle_id, 'aborted', $bundle['final'], 'insufficient_evidence', 0);
            $run_row = VES_Trend_Run_Store::get_run_by_bundle_id($bundle_id);
            if ($run_row) {
                VES_Run_Log_Service::warn((int) $run_row['id'], 'dispatcher', 'Trend run aborted.', []);
            }
        }
        VES_Usage_Reconciliation_Service::finalize_for_bundle($bundle_id, 'failed', $message !== '' ? $message : 'Trend run aborted');
        return true;
    }

    public static function mark_failed($bundle_id, $error = '', $context = []) {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) {
            return;
        }
        $bundle['status'] = 'FAILED';
        $bundle['final'] = [
            'platform' => 'trend_finder',
            'status' => 'FAILED',
            'run_status' => 'failed',
            'message' => sanitize_text_field((string) $error),
            'context' => is_array($context) ? $context : [],
            'generated_at' => current_time('mysql'),
        ];
        self::save_bundle($bundle_id, $bundle);
        if (!empty($bundle['monitor_id']) && class_exists('VES_Monitor_Store')) {
            VES_Monitor_Store::fail_monitor_run($bundle['monitor_id'], $error !== '' ? $error : 'Monitor fallido.');
        }
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::complete_run($bundle_id, 'failed', $bundle['final'], 'insufficient_evidence', 0);
        }
        VES_Usage_Reconciliation_Service::finalize_for_bundle($bundle_id, 'failed', $error !== '' ? $error : 'Trend run failed');
    }

    public static function mark_failed_by_id($run_id, $error = '', $context = []) {
        $run_id = (int) $run_id;
        if ($run_id <= 0 || !class_exists('VES_Trend_Run_Store')) {
            return;
        }
        global $wpdb;
        $bundle_id = $wpdb->get_var($wpdb->prepare('SELECT bundle_id FROM ' . VES_Trend_Run_Store::table_name() . ' WHERE id = %d LIMIT 1', $run_id));
        if ($bundle_id) {
            self::mark_failed((string) $bundle_id, $error, $context);
        }
    }
}
