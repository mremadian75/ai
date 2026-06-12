<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Backfill_Runner {
    const LOCK_OPTION = 'ves_trend_backfill_lock';
    const CURRENT_JOB_OPTION = 'ves_trend_backfill_current_job';
    const LAST_PLAN_OPTION = 'ves_trend_backfill_last_plan';
    const JOB_PREFIX = 'ves_trend_backfill_job_';

    private static function make_job_id() {
        try {
            return 'bf_' . gmdate('Ymd_His') . '_' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'bf_' . gmdate('Ymd_His') . '_' . strtolower(wp_generate_password(10, false, false));
        }
    }

    public static function lock($job_id, $args = []) {
        $existing = get_option(self::LOCK_OPTION, []);
        if (is_array($existing) && !empty($existing['job_id']) && (string) ($existing['job_id'] ?? '') !== (string) $job_id) {
            return new WP_Error('backfill_locked', 'Another trend backfill job is already locked.');
        }
        update_option(self::LOCK_OPTION, [
            'job_id' => sanitize_text_field((string) $job_id),
            'args' => is_array($args) ? $args : [],
            'started_at' => current_time('mysql', true),
        ], false);
        update_option(self::CURRENT_JOB_OPTION, sanitize_text_field((string) $job_id), false);
        return true;
    }

    public static function unlock($job_id) {
        $existing = get_option(self::LOCK_OPTION, []);
        if (is_array($existing) && (string) ($existing['job_id'] ?? '') === (string) $job_id) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function job_option_name($job_id) {
        return self::JOB_PREFIX . sanitize_key((string) $job_id);
    }

    public static function save_job_state($job_id, $state) {
        update_option(self::job_option_name($job_id), is_array($state) ? $state : [], false);
    }

    public static function get_job_state($job_id) {
        $state = get_option(self::job_option_name($job_id), []);
        return is_array($state) ? $state : [];
    }

    public static function build_plan($args = []) {
        $batch_size = isset($args['batch_size']) ? max(1, min(500, (int) $args['batch_size'])) : 100;
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 0;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $scanned = 0;
        $eligible = 0;
        $already_backfilled = 0;
        $skipped = 0;
        $est_runs = 0;
        $est_insights = 0;
        $ownerless = 0;
        $malformed = 0;
        $conflicts = 0;
        $samples = [];
        $plan_rows = [];
        while (true) {
            $fetch_limit = $limit > 0 ? min($batch_size, max(0, $limit - $scanned)) : $batch_size;
            if ($fetch_limit <= 0) {
                break;
            }
            $rows = VES_Temp_Memory::get_trend_reports_for_backfill(array_merge($args, ['limit' => $fetch_limit, 'offset' => $offset]));
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $scanned++;
                $normalized = VES_Legacy_Trend_Report_Normalizer::normalize_report_row($row, $args);
                $existing = VES_Trend_Run_Store::get_run_by_legacy_key($normalized['legacy_key']);
                if (!empty($existing['id'])) {
                    $already_backfilled++;
                    $normalized['action'] = 'skip_existing';
                    $normalized['existing_run_id'] = (int) $existing['id'];
                } elseif (!$normalized['eligible']) {
                    $skipped++;
                    if (in_array('ownerless', (array) $normalized['errors'], true)) {
                        $ownerless++;
                    }
                    if (in_array('empty_payload', (array) $normalized['errors'], true) || in_array('missing_prioritized_trends', (array) $normalized['errors'], true)) {
                        $malformed++;
                    }
                    $normalized['action'] = 'skip';
                } else {
                    $eligible++;
                    $est_runs++;
                    $est_insights += (int) $normalized['estimated_insight_count'];
                    $normalized['action'] = 'create';
                }
                if (count($samples) < 12) {
                    $samples[] = [
                        'memory_id' => (int) $normalized['memory_id'],
                        'owner' => $normalized['user_id'] > 0 ? (string) $normalized['user_id'] : ($normalized['session_key'] ?: '?'),
                        'date' => (string) $normalized['created_at'],
                        'run_status' => (string) $normalized['run_status'],
                        'mode' => (string) $normalized['confidence_mode'],
                        'insights' => (int) $normalized['estimated_insight_count'],
                        'action' => (string) $normalized['action'],
                        'errors' => implode(',', (array) $normalized['errors']),
                    ];
                }
                $plan_rows[] = [
                    'memory_id' => (int) $normalized['memory_id'],
                    'legacy_key' => (string) $normalized['legacy_key'],
                    'bundle_id' => (string) $normalized['bundle_id'],
                    'eligible' => !empty($normalized['eligible']),
                    'action' => (string) $normalized['action'],
                    'errors' => (array) $normalized['errors'],
                    'estimated_insight_count' => (int) $normalized['estimated_insight_count'],
                ];
                if ($limit > 0 && $scanned >= $limit) {
                    break 2;
                }
            }
            $offset += count($rows);
        }
        $plan = [
            'generated_at' => current_time('mysql', true),
            'args' => $args,
            'summary' => [
                'scanned_rows' => $scanned,
                'eligible_rows' => $eligible,
                'already_backfilled' => $already_backfilled,
                'skipped_rows' => $skipped,
                'estimated_runs_to_create' => $est_runs,
                'estimated_insights_to_create' => $est_insights,
                'ownerless_rows' => $ownerless,
                'malformed_rows' => $malformed,
                'conflict_rows' => $conflicts,
            ],
            'samples' => $samples,
            'rows' => $plan_rows,
        ];
        update_option(self::LAST_PLAN_OPTION, $plan, false);
        return $plan;
    }

    private static function prepare_job_state($job_id, $args, $plan) {
        return [
            'job_id' => $job_id,
            'status' => 'running',
            'args' => $args,
            'plan_summary' => $plan['summary'] ?? [],
            'started_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
            'last_processed_memory_id' => 0,
            'processed_count' => 0,
            'inserted_run_count' => 0,
            'inserted_insight_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
            'last_error' => '',
        ];
    }

    private static function checkpoint($job_id, $state) {
        $state['updated_at'] = current_time('mysql', true);
        self::save_job_state($job_id, $state);
    }

    public static function run($args = [], $resume_job_id = '') {
        $job_id = $resume_job_id !== '' ? sanitize_text_field((string) $resume_job_id) : self::make_job_id();
        $plan = self::build_plan($args);
        $lock = self::lock($job_id, $args);
        if (is_wp_error($lock)) {
            return $lock;
        }
        $state = $resume_job_id !== '' ? self::get_job_state($job_id) : [];
        if (empty($state)) {
            $state = self::prepare_job_state($job_id, $args, $plan);
            self::save_job_state($job_id, $state);
        }
        $batch_size = isset($args['batch_size']) ? max(1, min(500, (int) $args['batch_size'])) : 100;
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 0;
        $offset = 0;
        try {
            while (true) {
                $fetch_limit = $limit > 0 ? min($batch_size, max(0, $limit - (int) $state['processed_count'])) : $batch_size;
                if ($fetch_limit <= 0) {
                    break;
                }
                $rows = VES_Temp_Memory::get_trend_reports_for_backfill(array_merge($args, ['limit' => $fetch_limit, 'offset' => $offset]));
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    $offset++;
                    $memory_id = (int) ($row['id'] ?? 0);
                    if ($memory_id <= (int) ($state['last_processed_memory_id'] ?? 0) && !empty($resume_job_id)) {
                        continue;
                    }
                    $normalized = VES_Legacy_Trend_Report_Normalizer::normalize_report_row($row, $args);
                    if (!empty($args['skip_existing']) && VES_Trend_Run_Store::get_run_by_legacy_key($normalized['legacy_key'])) {
                        $state['processed_count']++;
                        $state['skipped_count']++;
                        $state['last_processed_memory_id'] = $memory_id;
                        self::checkpoint($job_id, $state);
                        continue;
                    }
                    if (empty($normalized['eligible'])) {
                        $state['processed_count']++;
                        $state['skipped_count']++;
                        $state['last_processed_memory_id'] = $memory_id;
                        self::checkpoint($job_id, $state);
                        continue;
                    }
                    $run_id = VES_Trend_Run_Store::create_legacy_run($normalized['bundle_id'], [
                        'user_id' => $normalized['user_id'],
                        'session_key' => $normalized['session_key'],
                        'request_hash' => $normalized['request_hash'],
                        'run_status' => $normalized['run_status'],
                        'confidence_mode' => $normalized['confidence_mode'],
                        'requested_topic' => $normalized['requested_topic'],
                        'country_code' => $normalized['country_code'],
                        'duration_code' => $normalized['duration_code'],
                        'request' => $normalized['request'],
                        'query_plan' => $normalized['query_plan'],
                        'sources' => $normalized['sources'],
                        'final_payload' => array_merge((array) $normalized['final_payload'], [
                            '_backfill' => [
                                'job_id' => $job_id,
                                'source_memory_id' => $memory_id,
                                'created_at' => current_time('mysql', true),
                            ],
                        ]),
                        'memory_entry_id' => $memory_id,
                        'backfill_job_id' => $job_id,
                        'legacy_memory_id' => $memory_id,
                        'legacy_key' => $normalized['legacy_key'],
                        'created_at' => $normalized['created_at'],
                        'completed_at' => $normalized['created_at'],
                    ]);
                    if ($run_id <= 0) {
                        $state['processed_count']++;
                        $state['error_count']++;
                        $state['last_processed_memory_id'] = $memory_id;
                        $state['last_error'] = 'Failed to create legacy run for memory ' . $memory_id;
                        self::checkpoint($job_id, $state);
                        continue;
                    }
                    $insight_ids = VES_Trend_Insight_Store::insert_legacy_insights($run_id, $memory_id, $normalized['insights'], [
                        'user_id' => $normalized['user_id'],
                        'session_key' => $normalized['session_key'],
                        'backfill_job_id' => $job_id,
                        'legacy_memory_id' => $memory_id,
                        'legacy_key_prefix' => $normalized['legacy_key'],
                        'default_status' => sanitize_key((string) ($args['default_status'] ?? 'archived')),
                        'created_at' => $normalized['created_at'],
                    ]);
                    $existing_run = VES_Trend_Run_Store::get_run_by_bundle_id($normalized['bundle_id']);
                    $final_payload = is_array($existing_run['final_payload'] ?? null) ? $existing_run['final_payload'] : (array) $normalized['final_payload'];
                    $final_payload['insight_ids'] = array_values(array_map('intval', $insight_ids));
                    $final_payload['memory_entry_id'] = $memory_id;
                    VES_Trend_Run_Store::complete_run($normalized['bundle_id'], $normalized['run_status'], $final_payload, $normalized['confidence_mode'], $memory_id);
                    VES_Temp_Memory::update_trend_report_backfill_links($memory_id, $run_id, $job_id);
                    $state['processed_count']++;
                    $state['inserted_run_count']++;
                    $state['inserted_insight_count'] += count($insight_ids);
                    $state['last_processed_memory_id'] = $memory_id;
                    self::checkpoint($job_id, $state);
                    if (!empty($args['sleep_ms'])) {
                        usleep(max(0, (int) $args['sleep_ms']) * 1000);
                    }
                    if ($limit > 0 && (int) $state['processed_count'] >= $limit) {
                        break 2;
                    }
                }
            }
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql', true);
            self::checkpoint($job_id, $state);
            self::unlock($job_id);
            return $state;
        } catch (Throwable $e) {
            $state['status'] = 'failed';
            $state['last_error'] = $e->getMessage();
            $state['completed_at'] = current_time('mysql', true);
            self::checkpoint($job_id, $state);
            self::unlock($job_id);
            return new WP_Error('backfill_failed', $e->getMessage(), $state);
        }
    }

    public static function status($job_id = '') {
        if ($job_id === '') {
            $job_id = (string) get_option(self::CURRENT_JOB_OPTION, '');
        }
        if ($job_id === '') {
            return [];
        }
        $state = self::get_job_state($job_id);
        if (!empty($state)) {
            $state['run_summary'] = VES_Trend_Run_Store::get_job_summary($job_id);
            $state['insight_count'] = VES_Trend_Insight_Store::count_by_backfill_job($job_id);
        }
        return $state;
    }

    public static function rollback($job_id) {
        $job_id = sanitize_text_field((string) $job_id);
        if ($job_id === '') {
            return new WP_Error('missing_job_id', 'Missing backfill job id.');
        }
        $deleted_insights = VES_Trend_Insight_Store::delete_by_backfill_job($job_id);
        $deleted_runs = VES_Trend_Run_Store::delete_by_backfill_job($job_id);
        $state = self::get_job_state($job_id);
        $state['status'] = 'rolled_back';
        $state['rolled_back_at'] = current_time('mysql', true);
        $state['deleted_runs'] = $deleted_runs;
        $state['deleted_insights'] = $deleted_insights;
        self::save_job_state($job_id, $state);
        self::unlock($job_id);
        return ['job_id' => $job_id, 'deleted_runs' => $deleted_runs, 'deleted_insights' => $deleted_insights];
    }
}
