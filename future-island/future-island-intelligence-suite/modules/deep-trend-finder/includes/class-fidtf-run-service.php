<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Run_Service {
    public static function allowed_statuses(): array {
        return [
            'created',
            'planned_waiting_for_sources',
            'running',
            'partial',
            'evidence_ingested',
            'completed',
            'completed_no_relevant_evidence',
            'failed',
        ];
    }

    public static function sanitize_status(string $status, string $fallback = 'created'): string {
        $status = sanitize_key($status);
        $fallback = sanitize_key($fallback);
        if (!in_array($fallback, self::allowed_statuses(), true)) {
            $fallback = 'created';
        }
        return in_array($status, self::allowed_statuses(), true) ? $status : $fallback;
    }

    public static function sanitize_request(array $input): array {
        $defaults = [
            'user_brief' => '',
            'objective' => '',
            'market' => 'Spain',
            'language' => 'es',
            'date_range' => 'last_30_days',
            'research_mode' => 'creative_validation',
            'brand' => '',
            'company' => '',
            'competitors' => [],
            'audience' => '',
            'exclude_terms' => [],
            'channels' => ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'],
            'keywords' => [],
        ];
        $input = array_merge($defaults, $input);
        $channels = [];
        foreach ((array) $input['channels'] as $channel) {
            $channel = sanitize_key((string) $channel);
            if ($channel !== '' && FIDTF_Settings::source_enabled($channel)) { $channels[] = $channel; }
        }
        if (empty($channels)) { $channels = ['google_trends', 'google_news', 'ai_research']; }
        $brand = sanitize_text_field((string) ($input['brand'] ?? ''));
        $company = sanitize_text_field((string) ($input['company'] ?? ''));
        if ($brand === '' && $company !== '') { $brand = $company; }
        if ($company === '' && $brand !== '') { $company = $brand; }
        return [
            'user_brief' => sanitize_textarea_field((string) $input['user_brief']),
            'objective' => sanitize_textarea_field((string) $input['objective']),
            'market' => sanitize_text_field((string) $input['market']),
            'language' => sanitize_key((string) $input['language']),
            'date_range' => self::sanitize_date_range((string) $input['date_range']),
            'research_mode' => self::sanitize_research_mode((string) ($input['research_mode'] ?? 'creative_validation')),
            'brand' => $brand,
            'company' => $company,
            'competitors' => self::sanitize_list($input['competitors'], 20),
            'audience' => sanitize_text_field((string) $input['audience']),
            'exclude_terms' => self::sanitize_list($input['exclude_terms'], 30),
            'channels' => array_values(array_unique($channels)),
            'keywords' => self::sanitize_list($input['keywords'], 30),
        ];
    }

    public static function create_run(array $input, int $user_id = 0) {
        $request = self::sanitize_request($input);
        if (trim($request['user_brief']) === '' && empty($request['keywords'])) {
            return new WP_Error('missing_brief', 'Please provide a brief, keywords, or both.', ['status' => 400]);
        }

        $preflight = FIDTF_Settings::live_preflight_status((array) ($request['channels'] ?? []));
        $run_intent = self::run_intent_from_preflight($request, $preflight);
        $plan = FIDTF_AI_Planner::build_plan($request);
        $estimate = FIDTF_Credit_Service::estimate($request, array_keys((array) ($plan['sources'] ?? [])));
        $run_id = self::insert_run($request, $plan, $estimate, $user_id);
        if (is_wp_error($run_id)) { return $run_id; }

        $reservation = FIDTF_Credit_Service::reserve($run_id, $user_id, (float) $estimate['estimated_credits'], ['request' => $request]);
        self::update_run($run_id, [
            'credits_reserved' => (float) ($reservation['credits_reserved'] ?? 0),
            'updated_at' => current_time('mysql'),
        ]);

        $jobs = FIDTF_Source_Job_Service::create_jobs($run_id, $plan, $request);
        $dispatch_results = FIDTF_Source_Job_Service::maybe_dispatch_jobs($run_id, $jobs, $plan, $request);
        $jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
        $status = self::status_from_jobs($jobs);
        self::update_run($run_id, ['status' => $status, 'updated_at' => current_time('mysql')]);

        return [
            'run_id' => $run_id,
            'status' => $status,
            'request' => $request,
            'plan' => $plan,
            'credit_estimate' => $estimate,
            'credit_reservation' => $reservation,
            'credit_mode' => FIDTF_Credit_Service::credit_mode([
                'status' => $status,
                'credits_reserved' => (float) ($reservation['credits_reserved'] ?? 0),
                'final_credits_settled' => 0,
            ]),
            'credits_reserved' => (float) ($reservation['credits_reserved'] ?? 0),
            'final_credits_settled' => 0.0,
            'jobs' => $jobs,
            'dispatch_results' => $dispatch_results,
            'dispatch_diagnostics' => self::dispatch_diagnostics($dispatch_results),
            'preflight' => $preflight,
            'run_intent' => $run_intent,
            'message' => self::message_for_jobs($jobs, $run_intent),
        ];
    }


    public static function status_from_jobs(array $jobs): string {
        if (empty($jobs)) { return 'planned_waiting_for_sources'; }
        $has_running = false;
        $has_relevant = false;
        $has_completed_no_relevant = false;
        $has_failed = false;
        foreach ($jobs as $job) {
            $status = FIDTF_Source_Job_Service::sanitize_status((string) ($job['status'] ?? ''), 'planned');
            $relevant = (int) ($job['relevant_count'] ?? 0);
            if (in_array($status, ['queued', 'running', 'retryable_failed'], true)) { $has_running = true; }
            if ($status === 'completed' && $relevant > 0) { $has_relevant = true; }
            if ($status === 'completed_no_relevant_evidence') { $has_completed_no_relevant = true; }
            if (in_array($status, ['failed', 'skipped'], true)) { $has_failed = true; }
        }
        if ($has_relevant) { return 'evidence_ingested'; }
        if ($has_running) { return 'running'; }
        if ($has_completed_no_relevant) { return 'completed_no_relevant_evidence'; }
        if ($has_failed) { return 'partial'; }
        return 'planned_waiting_for_sources';
    }

    private static function run_intent_from_preflight(array $request, array $preflight): string {
        $live_sources = (array) ($preflight['live_sources'] ?? []);
        if (!empty($live_sources)) {
            return in_array('tiktok', array_map('sanitize_key', $live_sources), true) && count($live_sources) === 1
                ? 'live_tiktok_collection'
                : 'live_multi_source_collection';
        }
        return 'planned_brief_only';
    }

    private static function dispatch_diagnostics(array $dispatch_results): array {
        $out = [];
        foreach ($dispatch_results as $result) {
            if (!is_array($result)) { continue; }
            $source = sanitize_key((string) ($result['source_key'] ?? ''));
            if ($source === '') { continue; }
            $status = sanitize_key((string) ($result['job_status'] ?? $result['status'] ?? ''));
            $error = sanitize_key((string) ($result['error_code'] ?? ''));
            $provider_run_id = sanitize_text_field((string) ($result['provider_run_id'] ?? ''));
            $attempted = $provider_run_id !== '' || in_array($status, ['queued', 'running', 'completed', 'completed_no_relevant_evidence'], true);
            if (in_array($error, ['global_live_disabled', 'live_dispatch_disabled', 'tiktok_live_bridge_disabled', 'source_live_bridge_disabled', 'core_apify_unavailable', 'core_apify_client_unavailable', 'core_apify_client_incomplete', 'direct_token_missing', 'external_filter_missing', 'provider_not_ready', 'tiktok_provider_unavailable'], true)) {
                $attempted = false;
            }
            $out[$source] = [
                'dispatch_attempted' => $attempted,
                'reason' => $error !== '' ? $error : ($attempted ? 'provider_run_started' : 'planned_only'),
                'provider_mode' => $source === 'tiktok' ? FIDTF_Settings::tiktok_provider_mode() : (FIDTF_Settings::source_live_ready($source) ? 'generic_apify_live' : 'planned_only'),
                'result' => $status !== '' ? $status : 'planned',
                'timestamp' => current_time('mysql'),
                'output_counts' => [
                    'provider_dataset_rows' => max(0, (int) ($result['provider_dataset_rows'] ?? 0)),
                    'flattened_raw_items' => max(0, (int) ($result['flattened_raw_items'] ?? $result['raw_count'] ?? 0)),
                    'normalized_items' => max(0, (int) ($result['normalized_count'] ?? 0)),
                    'relevant_items' => max(0, (int) ($result['relevant_count'] ?? 0)),
                ],
            ];
        }
        return $out;
    }

    private static function message_for_jobs(array $jobs, string $run_intent = 'planned_brief_only'): string {
        $running = [];
        $completed = [];
        $failed = [];
        $retryable = [];
        foreach ($jobs as $job) {
            $source = sanitize_key((string) ($job['source_key'] ?? ''));
            $status = FIDTF_Source_Job_Service::sanitize_status((string) ($job['status'] ?? ''), 'planned');
            if (in_array($status, ['queued', 'running'], true)) { $running[] = $source; }
            if ($status === 'retryable_failed') { $retryable[] = $source; }
            if ($status === 'completed' && (int) ($job['relevant_count'] ?? 0) > 0) { $completed[] = $source; }
            if (in_array($status, ['failed', 'skipped', 'retryable_failed'], true)) { $failed[] = $source; }
        }
        if (!empty($completed)) {
            return 'Evidence was collected and ingested from ' . implode(', ', array_map([__CLASS__, 'human_source_label'], $completed)) . '.';
        }
        if (!empty($running)) {
            return 'Live collection started for ' . implode(', ', array_map([__CLASS__, 'human_source_label'], $running)) . '. Waiting for provider results.';
        }
        if (!empty($retryable)) {
            if (in_array('tiktok', $retryable, true)) {
                // TikTok live dispatch was skipped before provider collection. Check admin diagnostics. It is not planned-only.
                return 'TikTok trend run is waiting for a retryable provider response. It is not planned-only.';
            }
            return 'Live collection is waiting for a retryable provider response from ' . implode(', ', array_map([__CLASS__, 'human_source_label'], $retryable)) . '. It is not planned-only.';
        }
        if (!empty($failed)) {
            return 'Live collection could not complete for ' . implode(', ', array_map([__CLASS__, 'human_source_label'], $failed)) . '. Check diagnostics.';
        }
        if ($run_intent === 'live_multi_source_collection') {
            return 'Multi-source trend run created. Provider dispatch is pending or waiting for results.';
        }
        if ($run_intent === 'live_tiktok_collection') {
            return 'TikTok trend run created. Provider dispatch is pending or waiting for a valid provider response.';
        }
        return 'Planned trend brief created. No live source will run with the current settings.';
    }

    private static function human_source_label(string $source): string {
        return ucwords(str_replace('_', ' ', sanitize_key($source)));
    }

    public static function ingest_source_results(int $run_id, int $source_job_id, string $source_key, array $items) {
        $source_key = sanitize_key($source_key);
        $run = self::get_run($run_id);
        if (empty($run)) {
            return new WP_Error('fidtf_run_not_found', 'Run not found.', ['status' => 404]);
        }
        if ($source_key === '' || !FIDTF_Settings::source_enabled($source_key)) {
            return new WP_Error('fidtf_invalid_source_key', 'Invalid source_key.', ['status' => 400]);
        }

        if ($source_job_id > 0) {
            $job = FIDTF_Source_Job_Service::get_job($source_job_id);
            if (empty($job) || (int) ($job['run_id'] ?? 0) !== $run_id) {
                return new WP_Error('fidtf_invalid_source_job_id', 'source_job_id does not belong to this run.', ['status' => 400]);
            }
            $job_source = sanitize_key((string) ($job['source_key'] ?? ''));
            if ($job_source !== $source_key) {
                return new WP_Error('fidtf_source_mismatch', 'source_key does not match the source job.', ['status' => 400]);
            }
        } elseif (!self::current_user_is_admin()) {
            return new WP_Error('fidtf_manual_ingest_admin_only', 'Manual ingest without source_job_id is admin-only.', ['status' => 403]);
        }

        $request = self::request_from_run($run);
        $plan = [];
        if (!empty($run['ai_planner_output_json'])) {
            $plan = json_decode((string) $run['ai_planner_output_json'], true) ?: [];
        }
        $source_plan = (array) (($plan['sources'] ?? [])[$source_key] ?? []);
        $result = FIDTF_Source_Job_Service::ingest_items($run_id, $source_job_id, $source_key, $items, self::sanitize_request($request), $source_plan);

        if ($source_job_id > 0) {
            FIDTF_Source_Job_Service::update_job($source_job_id, [
                'status' => $result['relevant_count'] > 0 ? 'completed' : 'completed_no_relevant_evidence',
                'raw_count' => (int) $result['raw_count'],
                'normalized_count' => (int) $result['normalized_count'],
                'relevant_count' => (int) $result['relevant_count'],
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        // Recompute aggregate status from all jobs so a zero-evidence ingest
        // does not incorrectly mark the run as 'evidence_ingested'.
        $all_jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
        $new_status = self::status_from_jobs($all_jobs);
        self::update_run($run_id, ['status' => $new_status, 'updated_at' => current_time('mysql')]);
        return $result;
    }

    public static function get_run(int $run_id): array {
        global $wpdb;
        if ($run_id <= 0 || !self::db_ready()) { return []; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FIDTF_DB::table('runs') . ' WHERE id = %d', $run_id), (defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'));
        return is_array($row) ? $row : [];
    }

    public static function current_user_can_access_run(int $run_id, int $user_id): bool {
        $run = self::get_run($run_id);
        if (empty($run)) { return false; }
        return self::user_can_access_run_row($run, $user_id);
    }

    public static function get_run_for_user(int $run_id, int $user_id) {
        $run = self::get_run($run_id);
        if (empty($run)) {
            return new WP_Error('fidtf_run_not_found', 'Run not found.', ['status' => 404]);
        }
        if (!self::user_can_access_run_row($run, $user_id)) {
            return new WP_Error('fidtf_run_forbidden', 'You do not have access to this run.', ['status' => 403]);
        }
        return $run;
    }

    public static function update_run(int $run_id, array $row): void {
        global $wpdb;
        if ($run_id <= 0 || !self::db_ready()) { return; }
        if (isset($row['status'])) {
            $row['status'] = self::sanitize_status((string) $row['status']);
        }
        $wpdb->update(FIDTF_DB::table('runs'), $row, ['id' => $run_id]);
    }

    public static function current_workspace_id(): int {
        if (class_exists('VES_Workspace_Context') && method_exists('VES_Workspace_Context', 'current_workspace_id')) {
            return max(0, (int) VES_Workspace_Context::current_workspace_id());
        }
        if (class_exists('VES_Config') && method_exists('VES_Config', 'current_workspace_id')) {
            return max(0, (int) VES_Config::current_workspace_id());
        }
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'workspace_id_for_user') && function_exists('get_current_user_id')) {
            return max(0, (int) VES_Memory_Records::workspace_id_for_user(get_current_user_id()));
        }
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'infer_workspace_id') && function_exists('get_current_user_id')) {
            return max(0, (int) VES_Memory_Records::infer_workspace_id(['user_id' => get_current_user_id()], get_current_user_id()));
        }
        return 0;
    }

    public static function current_user_is_admin(): bool {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    private static function user_can_access_run_row(array $run, int $user_id): bool {
        if (self::current_user_is_admin()) { return true; }
        if ($user_id > 0 && (int) ($run['user_id'] ?? 0) === $user_id) { return true; }
        $current_workspace = self::current_workspace_id();
        $run_workspace = (int) ($run['workspace_id'] ?? 0);
        return $current_workspace > 0 && $run_workspace > 0 && $current_workspace === $run_workspace;
    }

    private static function request_from_run(array $run): array {
        $request = [];
        if (!empty($run['channels_json'])) { $request['channels'] = json_decode((string) $run['channels_json'], true) ?: []; }
        if (!empty($run['keywords_json'])) { $request['keywords'] = json_decode((string) $run['keywords_json'], true) ?: []; }
        if (!empty($run['request_meta_json'])) {
            $meta = json_decode((string) $run['request_meta_json'], true) ?: [];
            if (is_array($meta)) { $request = array_merge($request, $meta); }
        }
        if (!empty($run['ai_planner_output_json'])) {
            $plan = json_decode((string) $run['ai_planner_output_json'], true) ?: [];
            if (!empty($plan['request_context']) && is_array($plan['request_context'])) {
                $request = array_merge($request, $plan['request_context']);
            }
        }
        foreach (['user_brief', 'objective', 'market', 'language', 'date_range', 'research_mode'] as $key) {
            if (isset($run[$key])) { $request[$key] = $run[$key]; }
        }
        return $request;
    }

    private static function insert_run(array $request, array $plan, array $estimate, int $user_id) {
        global $wpdb;
        if (!self::db_ready()) {
            return new WP_Error('database_unavailable', 'Deep Trend Finder database is not available.', ['status' => 500]);
        }
        $workspace_id = self::current_workspace_id();
        $inserted = $wpdb->insert(FIDTF_DB::table('runs'), [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'status' => self::sanitize_status('created'),
            'user_brief' => $request['user_brief'],
            'objective' => $request['objective'],
            'market' => $request['market'],
            'language' => $request['language'],
            'date_range' => $request['date_range'],
            'channels_json' => wp_json_encode($request['channels']),
            'keywords_json' => wp_json_encode($request['keywords']),
            'request_meta_json' => wp_json_encode(self::request_meta($request)),
            'ai_planner_output_json' => wp_json_encode($plan),
            'credit_estimate' => (float) ($estimate['estimated_credits'] ?? 0),
            'credits_reserved' => 0,
            'final_credits_settled' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        if (false === $inserted) {
            return new WP_Error('run_insert_failed', 'Could not create Deep Trend Finder run.', ['status' => 500]);
        }
        return (int) $wpdb->insert_id;
    }

    private static function request_meta(array $request): array {
        return [
            'brand' => sanitize_text_field((string) ($request['brand'] ?? '')),
            'company' => sanitize_text_field((string) ($request['company'] ?? '')),
            'competitors' => self::sanitize_list($request['competitors'] ?? [], 20),
            'audience' => sanitize_text_field((string) ($request['audience'] ?? '')),
            'exclude_terms' => self::sanitize_list($request['exclude_terms'] ?? [], 30),
            'date_range' => self::sanitize_date_range((string) ($request['date_range'] ?? 'last_30_days')),
            'research_mode' => self::sanitize_research_mode((string) ($request['research_mode'] ?? 'creative_validation')),
            'objective' => sanitize_textarea_field((string) ($request['objective'] ?? '')),
            'market' => sanitize_text_field((string) ($request['market'] ?? '')),
            'language' => sanitize_key((string) ($request['language'] ?? '')),
        ];
    }


    private static function sanitize_research_mode(string $value): string {
        $value = sanitize_key($value);
        $allowed = [
            'category_trend_discovery',
            'brand_opportunity',
            'competitor_benchmark',
            'creative_mechanics_mining',
            'campaign_validation',
            'creative_validation',
        ];
        return in_array($value, $allowed, true) ? $value : 'creative_validation';
    }

    private static function sanitize_date_range(string $value): string {
        $value = sanitize_key($value);
        $allowed = ['last_7_days', 'last_14_days', 'last_30_days', 'last_90_days', 'this_month', 'custom'];
        return in_array($value, $allowed, true) ? $value : 'last_30_days';
    }

    private static function sanitize_list($value, int $limit = 30): array {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n|,/', $value);
        }
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item !== '') { $out[] = sanitize_text_field(ltrim($item, '#')) ; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, $limit))));
    }

    private static function db_ready(): bool {
        global $wpdb;
        return is_object($wpdb) && method_exists($wpdb, 'insert') && isset($wpdb->prefix);
    }
}
