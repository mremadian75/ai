<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Source_Job_Service {
    public static function allowed_statuses(): array {
        return [
            'planned',
            'waiting_for_provider',
            'queued',
            'running',
            'completed',
            'completed_no_relevant_evidence',
            'retryable_failed',
            'failed',
            'skipped',
            'paused_by_configuration',
        ];
    }

    public static function sanitize_status(string $status, string $fallback = 'planned'): string {
        $status = sanitize_key($status);
        $fallback = sanitize_key($fallback);
        if (!in_array($fallback, self::allowed_statuses(), true)) {
            $fallback = 'planned';
        }
        return in_array($status, self::allowed_statuses(), true) ? $status : $fallback;
    }

    public static function create_jobs(int $run_id, array $plan, array $request): array {
        $jobs = [];
        foreach ((array) ($plan['sources'] ?? []) as $source_key => $source_plan) {
            $source_key = sanitize_key((string) $source_key);
            if ($source_key === '' || !FIDTF_Settings::source_enabled($source_key)) { continue; }
            $payload_request = array_merge($request, [
                'planner_mode' => sanitize_key((string) ($plan['planner_mode'] ?? 'local_fallback')),
            ]);
            $payload = FIDTF_Source_Dispatcher::payload_for($source_key, (array) $source_plan, $payload_request);
            $jobs[] = self::insert_job([
                'run_id' => $run_id,
                'source_key' => $source_key,
                'provider' => $source_key === 'ai_research' ? 'ai_bridge' : 'apify_bridge',
                'actor_id' => FIDTF_Source_Dispatcher::actor_for($source_key),
                'status' => self::sanitize_status('planned'),
                'query_payload_json' => wp_json_encode($payload),
                'updated_at' => current_time('mysql'),
            ]);
        }
        return $jobs;
    }

    public static function maybe_dispatch_jobs(int $run_id, array $jobs, array $plan, array $request): array {
        $results = [];
        foreach ($jobs as $job) {
            $job_id = (int) ($job['id'] ?? 0);
            $source_key = sanitize_key((string) ($job['source_key'] ?? ''));
            $source_plan = (array) (($plan['sources'] ?? [])[$source_key] ?? []);
            $dispatch_request = array_merge($request, [
                'planner_mode' => sanitize_key((string) ($plan['planner_mode'] ?? 'local_fallback')),
            ]);
            $dispatch = self::normalize_dispatch_result(FIDTF_Source_Dispatcher::dispatch_source($source_key, $source_plan, $dispatch_request));

            if (!empty($dispatch['items'])) {
                $ingested = self::ingest_items($run_id, $job_id, $source_key, $dispatch['items'], $request, $source_plan);
                $job_status = self::sanitize_status($ingested['relevant_count'] > 0 ? 'completed' : 'completed_no_relevant_evidence');
                self::update_job($job_id, [
                    'status' => $job_status,
                    'provider_run_id' => sanitize_text_field((string) ($dispatch['provider_run_id'] ?? '')),
                    'raw_count' => (int) $ingested['raw_count'],
                    'provider_dataset_rows' => (int) ($dispatch['provider_dataset_rows'] ?? 0),
                    'provider_dataset_total_rows' => (int) ($dispatch['provider_dataset_total_rows'] ?? $dispatch['discovery_dataset_total_rows'] ?? 0),
                    'flattened_raw_items' => (int) ($dispatch['flattened_raw_items'] ?? $ingested['raw_count']),
                    'normalized_count' => (int) $ingested['normalized_count'],
                    'relevant_count' => (int) $ingested['relevant_count'],
                    'error_code' => sanitize_text_field((string) ($dispatch['error_code'] ?? '')),
                    'retryable' => !empty($dispatch['retryable']) ? 1 : 0,
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                $dispatch['job_id'] = $job_id;
                $dispatch['source_key'] = $source_key;
                $dispatch['job_status'] = $job_status;
                $dispatch['ingested'] = $ingested;
                $dispatch['raw_count'] = (int) $ingested['raw_count'];
                $dispatch['normalized_count'] = (int) $ingested['normalized_count'];
                $dispatch['relevant_count'] = (int) $ingested['relevant_count'];
                $dispatch['provider_dataset_rows'] = (int) ($dispatch['provider_dataset_rows'] ?? 0);
                $dispatch['flattened_raw_items'] = (int) ($dispatch['flattened_raw_items'] ?? $ingested['raw_count']);
                $dispatch['normalized_items'] = (int) $ingested['normalized_count'];
                $dispatch['relevant_items'] = (int) $ingested['relevant_count'];
                $dispatch['message'] = self::message_for_ingest_counts($source_key, $dispatch['flattened_raw_items'], $ingested['normalized_count'], $ingested['relevant_count']);
                self::persist_dispatch_diagnostics($job_id, $dispatch);
                self::record_scrape_telemetry($run_id, $source_key, $dispatch, $job_status);
                $results[] = $dispatch;
                continue;
            }

            $job_status = self::sanitize_status((string) ($dispatch['status'] ?? 'queued'), 'queued');
            if (!$dispatch['ok'] && in_array($dispatch['error_code'], ['global_live_disabled', 'live_dispatch_disabled', 'ai_research_bridge_missing', 'provider_bridge_missing', 'tiktok_live_bridge_disabled', 'source_live_bridge_disabled', 'tiktok_provider_unavailable', 'core_apify_unavailable', 'core_apify_client_unavailable', 'core_apify_client_incomplete', 'direct_token_missing', 'external_filter_missing', 'provider_not_ready', 'tiktok_live_not_ready'], true)) {
                $job_status = self::sanitize_status('waiting_for_provider');
            } elseif (!$dispatch['ok'] && $dispatch['error_code'] === 'tiktok_provider_memory_limit') {
                // Memory limit errors are retryable; preserve retryable_failed so polling retries.
                $job_status = self::sanitize_status('retryable_failed');
            } elseif ($job_status === 'completed') {
                $job_status = self::sanitize_status('completed_no_relevant_evidence');
            }
            if (in_array($job_status, ['queued', 'running'], true) && sanitize_text_field((string) ($dispatch['provider_run_id'] ?? '')) === '') {
                $job_status = self::sanitize_status('failed');
                $dispatch['ok'] = false;
                $dispatch['error_code'] = $source_key . '_provider_invalid_run_response';
                $dispatch['retryable'] = false;
                $dispatch['message'] = ucfirst(str_replace('_', ' ', $source_key)) . ' provider returned an invalid run response.';
            }
            if ($source_key === 'tiktok' && $job_status === 'skipped' && FIDTF_Settings::tiktok_dispatch_allowed()) {
                $job_status = self::sanitize_status('failed');
                if (empty($dispatch['message'])) {
                    $dispatch['message'] = 'TikTok live dispatch was intended but provider dispatch did not start. Check admin diagnostics.';
                }
            }
            // v0.3.16: waiting_for_provider on TikTok when dispatch is allowed means the
            // provider was configured but the actual request could not be made (e.g.
            // missing_backend_token, core client incomplete, actor unavailable). Promote
            // to failed so the run surfaces as partial (actionable) rather than silently
            // staying as planned_waiting_for_sources, which misleads the user.
            if ($source_key === 'tiktok' && $job_status === 'waiting_for_provider' && FIDTF_Settings::tiktok_dispatch_allowed()) {
                $job_status = self::sanitize_status('failed');
                if (empty($dispatch['message'])) {
                    $dispatch['message'] = 'TikTok live dispatch was blocked by provider configuration. Check admin diagnostics for the specific reason.';
                }
            }

            $row = [
                'status' => $job_status,
                'provider_run_id' => sanitize_text_field((string) ($dispatch['provider_run_id'] ?? '')),
                'raw_count' => (int) ($dispatch['raw_count'] ?? 0),
                'provider_dataset_rows' => (int) ($dispatch['provider_dataset_rows'] ?? 0),
                'provider_dataset_total_rows' => (int) ($dispatch['provider_dataset_total_rows'] ?? $dispatch['discovery_dataset_total_rows'] ?? 0),
                'flattened_raw_items' => (int) ($dispatch['flattened_raw_items'] ?? $dispatch['raw_count'] ?? 0),
                'normalized_count' => (int) ($dispatch['normalized_count'] ?? 0),
                'relevant_count' => (int) ($dispatch['relevant_count'] ?? 0),
                'error_code' => sanitize_text_field((string) ($dispatch['error_code'] ?? '')),
                'retryable' => !empty($dispatch['retryable']) ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ];
            if (in_array($job_status, ['completed_no_relevant_evidence', 'failed', 'skipped'], true)) {
                $row['completed_at'] = current_time('mysql');
            }
            self::update_job($job_id, $row);
            $dispatch['job_id'] = $job_id;
            $dispatch['source_key'] = $source_key;
            $dispatch['job_status'] = $job_status;
            $dispatch['normalized_items'] = (int) ($dispatch['normalized_count'] ?? 0);
            $dispatch['relevant_items'] = (int) ($dispatch['relevant_count'] ?? 0);
            $dispatch['message'] = self::message_for_dispatch_state($source_key, $job_status, $dispatch);
            self::record_refresh_backoff_if_needed($job_id, $dispatch);
            self::persist_dispatch_diagnostics($job_id, $dispatch);
            self::record_scrape_telemetry($run_id, $source_key, $dispatch, $job_status);
            $results[] = $dispatch;
        }
        return $results;
    }


    public static function maybe_refresh_running_jobs(int $run_id, array $request = []): array {
        if ($run_id <= 0 || !FIDTF_Settings::tiktok_polling_enabled()) {
            return [];
        }
        $results = [];
        foreach (self::get_jobs($run_id) as $job) {
            $job_id = (int) ($job['id'] ?? 0);
            $source_key = sanitize_key((string) ($job['source_key'] ?? ''));
            $status = self::sanitize_status((string) ($job['status'] ?? ''), 'planned');
            $zero_repair_candidate = self::is_tiktok_zero_count_repair_candidate($job);
            $force_refresh = !empty($request['_fidtf_force_provider_refresh']);
            $refreshable_source = ($source_key === 'tiktok') || FIDTF_Settings::source_live_ready($source_key);
            if (!$refreshable_source || (!in_array($status, ['queued', 'running', 'retryable_failed'], true) && !$zero_repair_candidate && !$force_refresh)) { continue; }
            $provider_run_id = sanitize_text_field((string) ($job['provider_run_id'] ?? ''));
            if ($provider_run_id === '' && $status !== 'retryable_failed') { continue; }
            if (!$zero_repair_candidate && !$force_refresh && self::refresh_is_backing_off($job_id)) { continue; }
            if (!self::acquire_refresh_lock($job_id, $zero_repair_candidate || $force_refresh)) { continue; }

            $payload = self::payload_for_refresh($job, $request, $zero_repair_candidate || $force_refresh);
            if (empty($payload)) { self::release_refresh_lock($job_id); continue; }
            $request_context = !empty($payload['request_context']) && is_array($payload['request_context']) ? (array) $payload['request_context'] : $request;
            $source_plan = !empty($payload['source_plan']) && is_array($payload['source_plan']) ? (array) $payload['source_plan'] : [];
            $adapter = ($source_key === 'tiktok') ? new FIDTF_TikTok_Live_Adapter() : new FIDTF_Generic_Apify_Live_Adapter($source_key);
            $dispatch = $provider_run_id === ''
                ? self::normalize_dispatch_result($adapter->start($payload))
                : self::normalize_dispatch_result($adapter->refresh($job, $payload));
            self::release_refresh_lock($job_id);
            self::record_refresh_backoff_if_needed($job_id, $dispatch);

            if (!empty($dispatch['items'])) {
                $ingested = self::ingest_items($run_id, $job_id, $source_key, $dispatch['items'], $request_context, $source_plan);
                $job_status = self::sanitize_status($ingested['relevant_count'] > 0 ? 'completed' : 'completed_no_relevant_evidence');
                self::update_job($job_id, [
                    'status' => $job_status,
                    'provider_run_id' => sanitize_text_field((string) ($dispatch['provider_run_id'] ?? $provider_run_id)),
                    'raw_count' => (int) $ingested['raw_count'],
                    'provider_dataset_rows' => (int) ($dispatch['provider_dataset_rows'] ?? 0),
                    'provider_dataset_total_rows' => (int) ($dispatch['provider_dataset_total_rows'] ?? $dispatch['discovery_dataset_total_rows'] ?? 0),
                    'flattened_raw_items' => (int) ($dispatch['flattened_raw_items'] ?? $ingested['raw_count']),
                    'normalized_count' => (int) $ingested['normalized_count'],
                    'relevant_count' => (int) $ingested['relevant_count'],
                    'error_code' => sanitize_text_field((string) ($dispatch['error_code'] ?? '')),
                    'retryable' => !empty($dispatch['retryable']) ? 1 : 0,
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                $dispatch['ingested'] = $ingested;
                $dispatch['raw_count'] = (int) $ingested['raw_count'];
                $dispatch['normalized_count'] = (int) $ingested['normalized_count'];
                $dispatch['relevant_count'] = (int) $ingested['relevant_count'];
                $dispatch['provider_dataset_rows'] = (int) ($dispatch['provider_dataset_rows'] ?? 0);
                $dispatch['flattened_raw_items'] = (int) ($dispatch['flattened_raw_items'] ?? $ingested['raw_count']);
                $dispatch['normalized_items'] = (int) $ingested['normalized_count'];
                $dispatch['relevant_items'] = (int) $ingested['relevant_count'];
                $dispatch['message'] = self::message_for_ingest_counts($source_key, $dispatch['flattened_raw_items'], $ingested['normalized_count'], $ingested['relevant_count']);
                $dispatch['job_status'] = $job_status;
                $dispatch['source_key'] = $source_key;
                $dispatch['job_id'] = $job_id;
                self::persist_dispatch_diagnostics($job_id, $dispatch);
                self::record_scrape_telemetry($run_id, $source_key, $dispatch, $job_status);
                $results[] = $dispatch;
                continue;
            }

            $job_status = self::sanitize_status((string) ($dispatch['status'] ?? 'running'), 'running');
            if (!$dispatch['ok'] && !empty($dispatch['retryable'])) {
                $job_status = 'retryable_failed';
            } elseif (!$dispatch['ok']) {
                $job_status = 'failed';
            }
            if ($job_status === 'skipped') {
                $job_status = 'failed';
                if (empty($dispatch['message'])) {
                    $dispatch['message'] = ucfirst(str_replace('_', ' ', $source_key)) . ' live dispatch was intended but provider refresh did not return usable evidence. Check admin diagnostics.';
                }
            }
            $row = [
                'status' => $job_status,
                'raw_count' => (int) ($dispatch['raw_count'] ?? 0),
                'provider_dataset_rows' => (int) ($dispatch['provider_dataset_rows'] ?? 0),
                'provider_dataset_total_rows' => (int) ($dispatch['provider_dataset_total_rows'] ?? $dispatch['discovery_dataset_total_rows'] ?? 0),
                'flattened_raw_items' => (int) ($dispatch['flattened_raw_items'] ?? $dispatch['raw_count'] ?? 0),
                'normalized_count' => (int) ($dispatch['normalized_count'] ?? 0),
                'relevant_count' => (int) ($dispatch['relevant_count'] ?? 0),
                'error_code' => sanitize_text_field((string) ($dispatch['error_code'] ?? '')),
                'retryable' => !empty($dispatch['retryable']) ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ];
            if (sanitize_text_field((string) ($dispatch['provider_run_id'] ?? '')) !== '') {
                $row['provider_run_id'] = sanitize_text_field((string) $dispatch['provider_run_id']);
            }
            if (in_array($job_status, ['completed_no_relevant_evidence', 'failed'], true)) {
                $row['completed_at'] = current_time('mysql');
            }
            self::update_job($job_id, $row);
            if (!empty($zero_repair_candidate) && in_array($job_status, ['completed_no_relevant_evidence', 'failed'], true)) {
                self::mark_tiktok_zero_count_repair_checked($job_id, $dispatch);
            }
            $dispatch['job_status'] = $job_status;
            $dispatch['source_key'] = $source_key;
            $dispatch['job_id'] = $job_id;
            $dispatch['normalized_items'] = (int) ($dispatch['normalized_count'] ?? 0);
            $dispatch['relevant_items'] = (int) ($dispatch['relevant_count'] ?? 0);
            $dispatch['message'] = self::message_for_dispatch_state($source_key, $job_status, $dispatch);
            self::persist_dispatch_diagnostics($job_id, $dispatch);
            self::record_scrape_telemetry($run_id, $source_key, $dispatch, $job_status);
            $results[] = $dispatch;
        }
        return $results;
    }


    private static function is_tiktok_zero_count_repair_candidate(array $job): bool {
        $status = self::sanitize_status((string) ($job['status'] ?? ''), 'planned');
        if ($status !== 'completed_no_relevant_evidence') { return false; }
        if (sanitize_key((string) ($job['source_key'] ?? '')) !== 'tiktok') { return false; }
        if (sanitize_text_field((string) ($job['provider_run_id'] ?? '')) === '') { return false; }
        if ((int) ($job['raw_count'] ?? 0) > 0 || (int) ($job['normalized_count'] ?? 0) > 0 || (int) ($job['relevant_count'] ?? 0) > 0) { return false; }
        // v0.3.21: Do not permanently suppress zero-count repair. Old builds could
        // close a TikTok job before Apify's dataset became readable; a one-shot
        // repair marker then locked the user into Raw 0 / Normalized 0 until the
        // transient expired. Normal refresh backoff still protects the provider.
        return true;
    }

    private static function mark_tiktok_zero_count_repair_checked(int $job_id, array $dispatch): void {
        // v0.3.21: intentionally no-op. Refresh backoff is enough protection and
        // repeated safe repair is required for old falsely-finalized TikTok jobs.
    }

    private static function refresh_is_backing_off(int $job_id): bool {
        if ($job_id <= 0) { return false; }
        $key = 'fidtf_tiktok_refresh_backoff_' . $job_id;
        if (function_exists('get_transient')) {
            return (bool) get_transient($key);
        }
        return false;
    }

    private static function acquire_refresh_lock(int $job_id, bool $force = false): bool {
        if ($job_id <= 0) { return false; }
        $lock_key = 'fidtf_tiktok_refresh_lock_' . $job_id;
        $last_key = 'fidtf_tiktok_refresh_last_' . $job_id;
        if (function_exists('get_transient') && get_transient($lock_key)) { return false; }
        if (!$force && function_exists('get_transient') && get_transient($last_key)) { return false; }
        if (function_exists('set_transient')) {
            set_transient($lock_key, 1, 25);
            set_transient($last_key, 1, $force ? 5 : 25);
        }
        return true;
    }

    private static function release_refresh_lock(int $job_id): void {
        if ($job_id <= 0 || !function_exists('delete_transient')) { return; }
        delete_transient('fidtf_tiktok_refresh_lock_' . $job_id);
    }

    private static function record_refresh_backoff_if_needed(int $job_id, array $dispatch): void {
        if ($job_id <= 0 || empty($dispatch['retryable']) || !function_exists('set_transient')) { return; }
        $code = sanitize_key((string) ($dispatch['error_code'] ?? ''));
        $ttl = $code === 'provider_rate_limited' ? 120 : ($code === 'provider_busy' ? 75 : 60);
        set_transient('fidtf_tiktok_refresh_backoff_' . $job_id, 1, $ttl);
    }

    private static function payload_from_job(array $job): array {
        if (empty($job['query_payload_json'])) { return []; }
        $decoded = json_decode((string) $job['query_payload_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function payload_for_refresh(array $job, array $request = [], bool $allow_minimal = false): array {
        $payload = self::payload_from_job($job);
        if (!empty($payload) || !$allow_minimal) { return $payload; }
        // v0.3.22: old false-zero jobs may still have a valid Apify run id while
        // carrying an empty/legacy payload. Refresh only needs source context and
        // a sane limit; build a minimal canonical payload instead of abandoning repair.
        $max_items = FIDTF_Settings::tiktok_default_limit();
        return [
            'source_key' => 'tiktok',
            'schema_version' => 'fidtf.source_payload.v1',
            'planner_mode' => sanitize_key((string) ($request['planner_mode'] ?? 'repair_refresh')),
            'request_context' => FIDTF_AI_Planner::request_context($request),
            'source_plan' => [
                'source_key' => 'tiktok',
                'queries' => array_values(array_map('sanitize_text_field', (array) ($request['keywords'] ?? []))),
                'hashtags' => [],
                'market_hints' => !empty($request['market']) ? [sanitize_text_field((string) $request['market'])] : [],
                'language_hints' => !empty($request['language']) ? [sanitize_text_field((string) $request['language'])] : [],
                'max_items' => $max_items,
                'sort_strategy' => 'relevance_then_engagement',
            ],
            'limits' => [
                'max_items' => $max_items,
                'admin_limit' => FIDTF_Settings::tiktok_max_limit(),
            ],
            'provider_intent' => 'live_collect',
            'live_dispatch_enabled' => true,
            'global_live_dispatch_enabled' => FIDTF_Settings::live_dispatch_enabled(),
            'tiktok_live_bridge_enabled' => FIDTF_Settings::tiktok_live_bridge_enabled(),
            'max_items' => $max_items,
        ];
    }


    private static function default_dispatch_diagnostics(): array {
        return [
            'provider_mode_used' => '',
            'request_attempted' => false,
            'outcome_reason' => '',
            'discovery_actor_id' => '',
            'enrichment_actor_id' => '',
            'discovery_actor_input' => [],
            'discovery_run_id' => '',
            'discovery_dataset_rows' => 0,
            'discovery_dataset_total_rows' => 0,
            'discovery_flattened_items' => 0,
            'normalized_items' => 0,
            'relevant_items' => 0,
            'enrichment_attempted' => false,
            'enrichment_run_ids' => [],
            'enrichment_comments_count' => 0,
        ];
    }

    private static function copy_dispatch_diagnostics(array $result, array $base): array {
        $diag = self::default_dispatch_diagnostics();
        foreach (array_keys($diag) as $key) {
            if (array_key_exists($key, $result)) { $base[$key] = $result[$key]; }
        }
        if (empty($base['discovery_dataset_rows'])) { $base['discovery_dataset_rows'] = (int) ($base['provider_dataset_rows'] ?? 0); }
        if (empty($base['discovery_dataset_total_rows'])) { $base['discovery_dataset_total_rows'] = (int) ($base['provider_dataset_total_rows'] ?? 0); }
        if (empty($base['discovery_flattened_items'])) { $base['discovery_flattened_items'] = (int) ($base['flattened_raw_items'] ?? $base['raw_count'] ?? 0); }
        if (empty($base['normalized_items'])) { $base['normalized_items'] = (int) ($base['normalized_count'] ?? 0); }
        if (empty($base['relevant_items'])) { $base['relevant_items'] = (int) ($base['relevant_count'] ?? 0); }
        return array_merge($diag, $base);
    }

    public static function normalize_dispatch_result($result): array {
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            $retryable = !empty($data['retryable']);
            return self::copy_dispatch_diagnostics($data, [
                'ok' => false,
                'status' => $retryable ? 'retryable_failed' : 'skipped',
                'provider_run_id' => '',
                'raw_count' => 0,
                'provider_dataset_rows' => 0,
                'provider_dataset_total_rows' => 0,
                'flattened_raw_items' => 0,
                'normalized_count' => 0,
                'relevant_count' => 0,
                'error_code' => sanitize_key((string) $result->get_error_code()),
                'retryable' => $retryable,
                'message' => sanitize_text_field((string) $result->get_error_message()),
                'retry_after' => max(0, (int) ($data['retry_after'] ?? 0)),
                'items' => [],
                'dispatch_attempted' => !empty($data['dispatch_attempted']) || !empty($data['request_attempted']),
            ]);
        }

        if (!is_array($result)) {
            return self::copy_dispatch_diagnostics([], [
                'ok' => false,
                'status' => 'failed',
                'provider_run_id' => '',
                'raw_count' => 0,
                'provider_dataset_rows' => 0,
                'provider_dataset_total_rows' => 0,
                'flattened_raw_items' => 0,
                'normalized_count' => 0,
                'relevant_count' => 0,
                'error_code' => 'invalid_provider_result',
                'retryable' => false,
                'message' => 'Provider bridge returned an invalid result shape.',
                'items' => [],
            ]);
        }

        if (self::is_list_array($result) && !array_key_exists('items', $result)) {
            $result = ['ok' => true, 'status' => 'completed', 'items' => $result];
        }

        $items = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (is_array($item)) { $items[] = $item; }
            if (count($items) >= 1000) { break; }
        }
        // Dispatch result statuses remain compatible with ['queued', 'running', 'completed', 'failed', 'retryable_failed', 'skipped']; they are sanitized through the central source-job model.
        $status = self::sanitize_status(
            (string) ($result['status'] ?? ''),
            !empty($items) ? 'completed' : 'queued'
        );
        $ok = array_key_exists('ok', $result)
            ? (bool) $result['ok']
            : !in_array($status, ['failed', 'retryable_failed', 'skipped'], true);

        return self::copy_dispatch_diagnostics($result, [
            'ok' => $ok,
            'status' => $status,
            'provider_run_id' => sanitize_text_field((string) ($result['provider_run_id'] ?? $result['run_id'] ?? '')),
            'raw_count' => max(0, (int) ($result['flattened_raw_items'] ?? $result['raw_count'] ?? count($items))),
            'provider_dataset_rows' => max(0, (int) ($result['provider_dataset_rows'] ?? 0)),
            'provider_dataset_total_rows' => max(0, (int) ($result['provider_dataset_total_rows'] ?? $result['discovery_dataset_total_rows'] ?? 0)),
            'flattened_raw_items' => max(0, (int) ($result['flattened_raw_items'] ?? $result['raw_count'] ?? count($items))),
            'normalized_count' => max(0, (int) ($result['normalized_count'] ?? 0)),
            'relevant_count' => max(0, (int) ($result['relevant_count'] ?? 0)),
            'error_code' => sanitize_key((string) ($result['error_code'] ?? '')),
            'retryable' => !empty($result['retryable']) || $status === 'retryable_failed',
            'message' => sanitize_text_field((string) ($result['message'] ?? '')),
            'retry_after' => max(0, (int) ($result['retry_after'] ?? 0)),
            'items' => $items,
            'dispatch_attempted' => !empty($result['dispatch_attempted']) || !empty($result['request_attempted']),
        ]);
    }

    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    public static function ingest_items(int $run_id, int $source_job_id, string $source_key, array $raw_items, array $request, array $source_plan = []): array {
        $normalized = FIDTF_Normalizer::normalize_many($source_key, $raw_items);
        $scored = FIDTF_Relevance_Filter::filter_items($normalized, $request, $source_plan);
        $deduped = self::dedupe_scored_items($scored);
        $inserted = 0;
        $relevant = 0;
        $filtered_out = 0;

        // v0.3.26: when a provider refresh/reprocess succeeds, replace stale
        // evidence for this source job instead of appending to the old scoring
        // output. This prevents previous broad/generic matches from lingering in
        // the frontend after the stricter relevance model is deployed.
        if ($source_job_id > 0 && count($normalized) > 0) {
            self::delete_items_for_job($source_job_id);
        }

        foreach ($deduped as $item) {
            if (empty($item['is_relevant'])) {
                $filtered_out++;
                continue;
            }
            $relevant++;
            if (self::insert_item($run_id, $source_job_id, $source_key, $item)) { $inserted++; }
        }

        $view_diag = ($source_key === 'tiktok' && method_exists('FIDTF_Relevance_Filter', 'summarize_view_diagnostics'))
            ? FIDTF_Relevance_Filter::summarize_view_diagnostics($deduped)
            : ['view_count_missing' => 0, 'below_min_views' => 0, 'view_gate_passed' => 0];
        if ($source_job_id > 0 && function_exists('set_transient')) {
            $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            set_transient('fidtf_view_diag_' . $source_job_id, $view_diag, $day);
        }

        return [
            'raw_count' => count($raw_items),
            'normalized_count' => count($normalized),
            'deduped_count' => count($deduped),
            'inserted_count' => $inserted,
            'relevant_count' => $relevant,
            'filtered_out_count' => $filtered_out,
            'view_count_missing' => $view_diag['view_count_missing'],
            'below_min_views' => $view_diag['below_min_views'],
            'view_gate_passed' => $view_diag['view_gate_passed'],
            'storage_policy' => 'Only relevant, deduped evidence items are stored and exposed to the frontend.',
        ];
    }


    public static function message_for_ingest_counts(string $source_key, int $flattened_raw_items, int $normalized_items, int $relevant_items, string $fallback = ''): string {
        $source_key = sanitize_key($source_key);
        if ($source_key === 'tiktok') {
            if ($fallback !== '' && $flattened_raw_items <= 0 && $normalized_items <= 0 && $relevant_items <= 0) {
                return $fallback;
            }
            if ($flattened_raw_items <= 0) {
                return 'Discovery returned zero posts';
            }
            if ($normalized_items <= 0) {
                return 'TikTok data was returned, but the normalizer could not extract usable post fields.';
            }
            if ($relevant_items <= 0) {
                return 'Discovery returned posts but relevance filter rejected them';
            }
            return 'TikTok discovery completed';
        }
        if ($flattened_raw_items <= 0) { return $fallback !== '' ? $fallback : ucfirst(str_replace('_', ' ', $source_key)) . ' provider returned zero usable rows.'; }
        if ($normalized_items <= 0) { return ucfirst(str_replace('_', ' ', $source_key)) . ' data was returned, but the normalizer could not extract usable fields.'; }
        if ($relevant_items <= 0) { return ucfirst(str_replace('_', ' ', $source_key)) . ' returned data, but relevance filtering rejected all rows.'; }
        return $fallback !== '' ? $fallback : ucfirst(str_replace('_', ' ', $source_key)) . ' collection completed.';
    }

    public static function message_for_dispatch_state(string $source_key, string $job_status, array $dispatch): string {
        $source_key = sanitize_key($source_key);
        $job_status = self::sanitize_status($job_status, 'planned');
        $fallback = sanitize_text_field((string) ($dispatch['message'] ?? ''));
        $error_code = sanitize_key((string) ($dispatch['error_code'] ?? ''));
        if ($source_key === 'tiktok') {
            if ($job_status === 'waiting_for_provider' || $job_status === 'paused_by_configuration') {
                return $fallback !== '' ? $fallback : 'TikTok live provider is not ready. Dispatch is blocked before collection.';
            }
            if ($job_status === 'skipped') {
                return $fallback !== '' ? $fallback : 'TikTok live dispatch was skipped before provider collection. Check admin diagnostics.';
            }
            if ($job_status === 'retryable_failed') {
                if ($error_code === 'provider_rate_limited') { return 'TikTok provider is rate limited. The job will retry automatically.'; }
                if ($error_code === 'provider_busy') { return 'TikTok provider is temporarily busy. The job will retry automatically.'; }
                return $fallback !== '' ? $fallback : 'TikTok provider dispatch failed temporarily. The job will retry automatically.';
            }
            if ($job_status === 'failed') {
                return $fallback !== '' ? $fallback : 'TikTok provider dispatch failed.';
            }
            if (in_array($job_status, ['queued', 'running'], true)) {
                return $fallback !== '' ? $fallback : 'TikTok provider run has started. Waiting for provider result.';
            }
        }
        return self::message_for_ingest_counts(
            $source_key,
            (int) ($dispatch['flattened_raw_items'] ?? $dispatch['raw_count'] ?? 0),
            (int) ($dispatch['normalized_count'] ?? 0),
            (int) ($dispatch['relevant_count'] ?? 0),
            $fallback
        );
    }

    public static function view_diagnostics_for_job(int $source_job_id): array {
        if ($source_job_id <= 0 || !function_exists('get_transient')) { return []; }
        $value = get_transient('fidtf_view_diag_' . $source_job_id);
        return is_array($value) ? $value : [];
    }


    /**
     * Phase 3 (Part E) — emit one Apify/scrape usage-telemetry row per finished
     * source job. Apify responses carry no cost/compute data, so this records the
     * actor/dataset/run + item counts with cost=0 and pricing_source=unavailable.
     * Additive, isolated (never throws), and does NOT charge credits.
     */
    private static function record_scrape_telemetry(int $run_id, string $source_key, array $dispatch, string $job_status): void {
        if (!class_exists('VES_AI_Usage_Tracker') || !method_exists('VES_AI_Usage_Tracker', 'record_scrape')) { return; }
        // Only Apify-backed sources; the AI-research bridge is tracked via the OpenAI path.
        if ($source_key === 'ai_research') { return; }
        $provider_run_id = sanitize_text_field((string) ($dispatch['provider_run_id'] ?? ''));
        $actor_id = sanitize_text_field((string) ($dispatch['discovery_actor_id'] ?? $dispatch['actor_id'] ?? ''));
        if ($provider_run_id === '' && $actor_id === '') { return; } // nothing dispatched
        try {
            $ws = 0; $uid = 0;
            if (class_exists('FIDTF_Run_Service') && method_exists('FIDTF_Run_Service', 'get_run')) {
                $run = FIDTF_Run_Service::get_run($run_id);
                $ws = (int) ($run['workspace_id'] ?? 0);
                $uid = (int) ($run['user_id'] ?? 0);
            }
            $is_completed = (strpos((string) $job_status, 'completed') === 0);
            $item_count = (int) ($dispatch['raw_count'] ?? $dispatch['provider_dataset_rows'] ?? 0);
            // Part C: idempotency key prevents a double-record for the same actor run.
            $telemetry_key = 'fidtf:' . $run_id . ':' . sanitize_key($source_key) . ':' . ($provider_run_id !== '' ? $provider_run_id : $actor_id);
            $usage_event_id = (int) VES_AI_Usage_Tracker::record_scrape([
                'module' => 'deep_trend_finder',
                'operation_type' => 'apify_actor_run',
                'workspace_id' => $ws,
                'user_id' => $uid,
                'run_id' => (string) $run_id,
                'status' => $is_completed ? 'completed' : 'failed',
                'error_code' => $is_completed ? '' : sanitize_text_field((string) ($dispatch['error_code'] ?? $job_status)),
                'actor_id' => $actor_id,
                'dataset_id' => sanitize_text_field((string) ($dispatch['discovery_dataset_id'] ?? $dispatch['provider_dataset_id'] ?? '')),
                'apify_run_id' => $provider_run_id,
                'item_count' => $item_count,
                'telemetry_key' => $telemetry_key,
                // Cost/compute is not exposed by the Apify run/dataset endpoints (Part D).
                'estimated_provider_cost' => 0.0,
                'pricing_source' => 'unavailable',
                'metadata' => [
                    'source_key' => sanitize_key($source_key),
                    'actor_name' => $actor_id,
                    'item_count' => $item_count,
                    'usage_source' => 'fidtf_dispatch',
                ],
            ]);
            // Part B: stash scrape usage_event_ids on a run-scoped transient so the
            // report builder can backlink the Source it later creates to these events.
            if ($usage_event_id > 0 && $is_completed) { self::remember_scrape_usage_event($run_id, $usage_event_id); }
        } catch (\Throwable $e) {
            if (class_exists('VES_Log')) { VES_Log::warn('deep_trend_finder', 'scrape_telemetry_failed', ['run_id' => $run_id, 'error_code' => 'scrape_telemetry_failed']); }
        }
    }

    /** Part B: run-scoped accumulator of scrape usage_event_ids (cross-request safe). */
    private static function scrape_usage_transient_key(int $run_id): string { return 'fidtf_scrape_uids_' . (int) $run_id; }

    private static function remember_scrape_usage_event(int $run_id, int $usage_event_id): void {
        if ($run_id <= 0 || $usage_event_id <= 0 || !function_exists('get_transient') || !function_exists('set_transient')) { return; }
        $key = self::scrape_usage_transient_key($run_id);
        $ids = get_transient($key);
        $ids = is_array($ids) ? $ids : [];
        if (!in_array($usage_event_id, $ids, true)) { $ids[] = $usage_event_id; }
        $ids = array_slice($ids, -20); // bounded
        set_transient($key, $ids, 6 * 3600);
    }

    /** Returns the scrape usage_event_ids accumulated for a run (for Source backlinking). */
    public static function get_scrape_usage_events(int $run_id): array {
        if ($run_id <= 0 || !function_exists('get_transient')) { return []; }
        $ids = get_transient(self::scrape_usage_transient_key($run_id));
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function persist_dispatch_diagnostics(int $source_job_id, array $dispatch): void {
        if ($source_job_id <= 0 || !function_exists('set_transient')) { return; }
        $diag = [
            'addon_version' => defined('FIDTF_VERSION') ? FIDTF_VERSION : '',
            'request_attempted' => !empty($dispatch['request_attempted']) || !empty($dispatch['dispatch_attempted']),
            'provider_mode_used' => sanitize_text_field((string) ($dispatch['provider_mode_used'] ?? '')),
            'outcome_reason' => sanitize_text_field((string) ($dispatch['outcome_reason'] ?? $dispatch['error_code'] ?? '')),
            'error_code' => sanitize_key((string) ($dispatch['error_code'] ?? '')),
            'error_message' => sanitize_text_field((string) ($dispatch['message'] ?? '')),
            'retryable' => !empty($dispatch['retryable']),
            'retry_after' => max(0, (int) ($dispatch['retry_after'] ?? 0)),
            'discovery_actor_id' => sanitize_text_field((string) ($dispatch['discovery_actor_id'] ?? '')),
            'enrichment_actor_id' => sanitize_text_field((string) ($dispatch['enrichment_actor_id'] ?? '')),
            'actor_input_profile' => sanitize_key((string) ($dispatch['actor_input_profile'] ?? '')),
            'discovery_actor_input' => is_array($dispatch['discovery_actor_input'] ?? null) ? (array) $dispatch['discovery_actor_input'] : [],
            'discovery_run_id' => sanitize_text_field((string) ($dispatch['discovery_run_id'] ?? $dispatch['provider_run_id'] ?? '')),
            'discovery_dataset_rows' => (int) ($dispatch['discovery_dataset_rows'] ?? $dispatch['provider_dataset_rows'] ?? 0),
            'discovery_dataset_total_rows' => (int) ($dispatch['discovery_dataset_total_rows'] ?? $dispatch['provider_dataset_total_rows'] ?? 0),
            'discovery_flattened_items' => (int) ($dispatch['discovery_flattened_items'] ?? $dispatch['flattened_raw_items'] ?? $dispatch['raw_count'] ?? 0),
            'normalized_items' => (int) ($dispatch['normalized_items'] ?? $dispatch['normalized_count'] ?? 0),
            'relevant_items' => (int) ($dispatch['relevant_items'] ?? $dispatch['relevant_count'] ?? 0),
            'enrichment_attempted' => !empty($dispatch['enrichment_attempted']),
            'enrichment_run_ids' => array_values(array_map('sanitize_text_field', (array) ($dispatch['enrichment_run_ids'] ?? []))),
            'enrichment_comments_count' => (int) ($dispatch['enrichment_comments_count'] ?? 0),
        ];
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        if (function_exists('set_transient')) {
            set_transient('fidtf_dispatch_diag_' . $source_job_id, $diag, $day);
        }
        // v0.3.24: transients are not a durable diagnostic store. Persist the
        // provider row counts and sanitized diagnostics on the source job so old
        // Apify runs do not lose their evidence-count context after object-cache
        // eviction or the 24h transient TTL.
        self::update_job($source_job_id, [
            'provider_dataset_rows' => max(0, (int) ($diag['discovery_dataset_rows'] ?? 0)),
            'provider_dataset_total_rows' => max(0, (int) ($diag['discovery_dataset_total_rows'] ?? 0)),
            'flattened_raw_items' => max(0, (int) ($diag['discovery_flattened_items'] ?? 0)),
            'diagnostics_json' => wp_json_encode($diag),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function dispatch_diagnostics_for_job(int $source_job_id): array {
        if ($source_job_id <= 0) { return []; }
        if (function_exists('get_transient')) {
            $value = get_transient('fidtf_dispatch_diag_' . $source_job_id);
            if (is_array($value)) { return $value; }
        }
        $job = self::get_job($source_job_id);
        if (!empty($job['diagnostics_json'])) {
            $decoded = json_decode((string) $job['diagnostics_json'], true);
            if (is_array($decoded)) { return $decoded; }
        }
        return [];
    }

    public static function get_jobs(int $run_id): array {
        global $wpdb;
        if (!self::db_ready()) { return []; }
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FIDTF_DB::table('source_jobs') . ' WHERE run_id = %d ORDER BY id ASC', $run_id), (defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'));
    }

    public static function get_job(int $source_job_id): array {
        global $wpdb;
        if ($source_job_id <= 0 || !self::db_ready()) { return []; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FIDTF_DB::table('source_jobs') . ' WHERE id = %d', $source_job_id), (defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'));
        return is_array($row) ? $row : [];
    }

    public static function get_items(int $run_id, bool $relevant_only = false, array $args = []): array {
        global $wpdb;
        if (!self::db_ready()) { return []; }
        $max_limit = !empty($args['internal']) ? 200 : 50;
        $limit = max(1, min($max_limit, (int) ($args['per_page'] ?? $args['limit'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $where = ['run_id = %d'];
        $values = [$run_id];
        if ($relevant_only) { $where[] = 'relevance_score >= %d'; $values[] = 1; }
        $source = sanitize_key((string) ($args['source'] ?? ''));
        if ($source !== '') { $where[] = 'source_key = %s'; $values[] = $source; }
        $tier = sanitize_key((string) ($args['relevance_tier'] ?? ''));
        if ($tier !== '') {
            $range = self::tier_range($tier);
            if ($range) {
                $where[] = 'relevance_score >= %d';
                $values[] = (int) $range[0];
                if ($range[1] !== null) {
                    $where[] = 'relevance_score < %d';
                    $values[] = (int) $range[1];
                }
            }
        }
        $query_limit = $relevant_only ? max($limit, min($max_limit * 3, $limit * 3)) : $limit;
        $values[] = $query_limit;
        $values[] = $offset;
        $sql = 'SELECT * FROM ' . FIDTF_DB::table('items') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY relevance_score DESC, id ASC LIMIT %d OFFSET %d';
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$values), (defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'));
        if ($relevant_only) {
            $rows = array_values(array_filter($rows, [__CLASS__, 'row_is_report_relevant']));
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    private static function row_is_report_relevant(array $row): bool {
        $score = (float) ($row['relevance_score'] ?? 0);
        if ($score < 1) { return false; }
        $decoded = [];
        if (!empty($row['normalized_json'])) {
            $maybe = json_decode((string) $row['normalized_json'], true);
            if (is_array($maybe)) { $decoded = $maybe; }
        }
        $tier = sanitize_key((string) ($decoded['evidence_tier'] ?? ''));
        $use = sanitize_key((string) ($decoded['recommended_use'] ?? ''));
        if ($tier === 'noise' || $use === 'hide') { return false; }
        // v0.3.49 live QA: capped outside-market or language-mismatch social rows
        // may still have non-zero scores. They should not load as evidence cards.
        if ($score < 35 && $tier === '') { return false; }
        return true;
    }

    public static function update_job(int $job_id, array $row): void {
        global $wpdb;
        if ($job_id <= 0 || !self::db_ready()) { return; }
        if (isset($row['status'])) {
            $row['status'] = self::sanitize_status((string) $row['status']);
        }
        $row = self::filter_source_job_row($row);
        if (empty($row)) { return; }
        $wpdb->update(FIDTF_DB::table('source_jobs'), $row, ['id' => $job_id]);
    }

    private static function insert_job(array $row): array {
        global $wpdb;
        if (self::db_ready()) {
            $insert = self::filter_source_job_row($row);
            $wpdb->insert(FIDTF_DB::table('source_jobs'), $insert);
            $row['id'] = (int) $wpdb->insert_id;
        } else {
            $row['id'] = 0;
        }
        return $row;
    }

    private static function filter_source_job_row(array $row): array {
        $columns = self::source_job_columns();
        if (empty($columns)) { return $row; }
        return array_intersect_key($row, array_flip($columns));
    }

    private static function source_job_columns(): array {
        static $columns = null;
        if ($columns !== null) { return $columns; }
        global $wpdb;
        $columns = [];
        if (!self::db_ready() || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) { return $columns; }
        try {
            $table = FIDTF_DB::table('source_jobs');
            $values = $wpdb->get_col('DESC ' . $table, 0);
            if (is_array($values)) {
                $columns = array_values(array_filter(array_map('strval', $values)));
            }
        } catch (Throwable $e) {
            $columns = [];
        }
        return $columns;
    }

    private static function delete_items_for_job(int $source_job_id): void {
        global $wpdb;
        if ($source_job_id <= 0 || !self::db_ready() || !is_object($wpdb) || !method_exists($wpdb, 'delete')) { return; }
        $wpdb->delete(FIDTF_DB::table('items'), ['source_job_id' => $source_job_id], ['%d']);
    }

    private static function insert_item(int $run_id, int $source_job_id, string $source_key, array $item): bool {
        global $wpdb;
        if (!self::db_ready()) { return false; }
        $row = [
            'run_id' => $run_id,
            'source_job_id' => $source_job_id,
            'source_key' => sanitize_key($source_key),
            'external_id' => sanitize_text_field((string) ($item['external_id'] ?? '')),
            'url' => esc_url_raw((string) ($item['url'] ?? '')),
            'author' => sanitize_text_field((string) ($item['author'] ?? '')),
            'title' => sanitize_text_field((string) ($item['title'] ?? '')),
            'caption_or_text' => sanitize_textarea_field((string) ($item['caption_or_text'] ?? '')),
            'published_at' => $item['published_at'] ?? null,
            'metrics_json' => wp_json_encode($item['metrics'] ?? []),
            'media_json' => wp_json_encode($item['media'] ?? []),
            'transcript_json' => wp_json_encode($item['transcript'] ?? []),
            'comments_sample_json' => wp_json_encode($item['comments_sample'] ?? []),
            'raw_json' => wp_json_encode($item['raw'] ?? []),
            'normalized_json' => wp_json_encode($item),
            'relevance_score' => (float) ($item['relevance_score'] ?? 0),
            'relevance_reason' => sanitize_textarea_field((string) ($item['relevance_reason'] ?? '')),
            'evidence_quality_json' => wp_json_encode($item['evidence_quality'] ?? []),
            'created_at' => current_time('mysql'),
        ];
        return false !== $wpdb->insert(FIDTF_DB::table('items'), $row);
    }

    private static function dedupe_scored_items(array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $key = self::fingerprint($item);
            if (!isset($out[$key]) || (float) ($item['relevance_score'] ?? 0) > (float) ($out[$key]['relevance_score'] ?? 0)) {
                $out[$key] = $item;
            }
        }
        usort($out, static function($a, $b) {
            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });
        return array_values($out);
    }

    private static function fingerprint(array $item): string {
        $source = sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? 'unknown'));
        $external = sanitize_text_field((string) ($item['external_id'] ?? ''));
        $url = esc_url_raw((string) ($item['url'] ?? ''));
        if ($external !== '') { return $source . '|id|' . self::safe_lower($external); }
        if ($url !== '') { return $source . '|url|' . self::safe_lower($url); }
        return $source . '|hash|' . md5(wp_json_encode($item));
    }


    private static function safe_lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function tier_range(string $tier): ?array {
        switch ($tier) {
            case 'direct': return [70, null];
            case 'adjacent': return [50, 70];
            case 'weak': return [35, 50];
            case 'noise': return [0, 35];
            default: return null;
        }
    }

    private static function db_ready(): bool {
        global $wpdb;
        return is_object($wpdb) && method_exists($wpdb, 'insert') && isset($wpdb->prefix);
    }
}
