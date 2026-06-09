<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_REST_Controller {
    const NS = 'future-island-dtf/v1';

    public static function register_routes(): void {
        register_rest_route(self::NS, '/preflight', [
            'methods' => ['GET', 'POST'],
            'callback' => [__CLASS__, 'preflight'],
            'permission_callback' => [__CLASS__, 'can_create'],
        ]);
        register_rest_route(self::NS, '/runs', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_run'],
            'permission_callback' => [__CLASS__, 'can_create'],
        ]);
        register_rest_route(self::NS, '/runs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_run'],
            'permission_callback' => [__CLASS__, 'can_create'],
        ]);
        register_rest_route(self::NS, '/runs/(?P<id>\d+)/report', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_report'],
            'permission_callback' => [__CLASS__, 'can_create'],
        ]);
        register_rest_route(self::NS, '/runs/(?P<id>\d+)/items', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_items'],
            'permission_callback' => [__CLASS__, 'can_create'],
        ]);
        register_rest_route(self::NS, '/runs/(?P<id>\d+)/ingest', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'ingest'],
            'permission_callback' => [__CLASS__, 'can_ingest'],
        ]);
        register_rest_route(self::NS, '/admin/openai-smoke-test', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'openai_smoke_test'],
            'permission_callback' => [__CLASS__, 'can_ingest'],
        ]);
    }


    public static function preflight(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = $request->get_params(); }
        $channels = [];
        if (!empty($params['channels']) && is_array($params['channels'])) {
            $channels = (array) $params['channels'];
        } elseif (!empty($params['channels']) && is_string($params['channels'])) {
            $channels = preg_split('/,/', (string) $params['channels']);
        }
        return rest_ensure_response(FIDTF_Settings::live_preflight_status((array) $channels));
    }

    public static function can_create() {
        if (!FIDTF_Dependency::mother_active()) {
            return new WP_Error('fidtf_dependency_missing', FIDTF_Dependency::missing_message(), ['status' => 503]);
        }
        if (!is_user_logged_in()) {
            return new WP_Error('fidtf_login_required', 'Login required.', ['status' => 401]);
        }
        if (!FIDTF_Dependency::can_use_frontend()) {
            return new WP_Error('fidtf_forbidden', 'You do not have access to Deep Trend Finder.', ['status' => 403]);
        }
        return true;
    }

    public static function can_ingest() {
        if (!is_user_logged_in()) {
            return new WP_Error('fidtf_login_required', 'Login required.', ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('fidtf_admin_required', 'Only administrators can ingest provider results.', ['status' => 403]);
        }
        return true;
    }

    public static function create_run(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = $request->get_params(); }
        $result = FIDTF_Run_Service::create_run($params, get_current_user_id());
        if (is_wp_error($result)) { return $result; }
        $result['jobs'] = self::prepare_jobs_for_response((array) ($result['jobs'] ?? []));
        $result['plan'] = self::prepare_plan_for_response((array) ($result['plan'] ?? []));
        if (!current_user_can('manage_options')) { unset($result['dispatch_diagnostics']); }
        return rest_ensure_response($result);
    }

    public static function get_run(WP_REST_Request $request) {
        $run_id = (int) $request['id'];
        $run = FIDTF_Run_Service::get_run_for_user($run_id, get_current_user_id());
        if (is_wp_error($run)) { return $run; }
        $run = self::refresh_run_jobs_and_status($run_id, $run, self::force_provider_refresh_requested($request));
        if (is_wp_error($run)) { return $run; }
        $jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
        return rest_ensure_response([
            'run' => self::prepare_run_for_response($run),
            'plan' => self::plan_from_run($run),
            'jobs' => self::prepare_jobs_for_response($jobs),
        ]);
    }

    public static function get_report(WP_REST_Request $request) {
        $run_id = (int) $request['id'];
        $run = FIDTF_Run_Service::get_run_for_user($run_id, get_current_user_id());
        if (is_wp_error($run)) { return $run; }
        // v0.3.20: report loading is also a safe refresh point. This repairs
        // old v0.3.18/v0.3.19 zero-count TikTok jobs whose Apify run later
        // succeeded after the frontend had already stopped polling.
        $run = self::refresh_run_jobs_and_status($run_id, $run, self::force_provider_refresh_requested($request));
        if (is_wp_error($run)) { return $run; }
        $force = (string) $request->get_param('force') === '1';
        if ($force && !current_user_can('manage_options')) {
            return new WP_Error('fidtf_force_rebuild_admin_only', 'Force rebuild is admin-only.', ['status' => 403]);
        }
        $report = FIDTF_Report_Service::build_or_get_report($run_id, $run, $force);
        return rest_ensure_response([
            'report' => $report,
            'html' => FIDTF_Report_Service::render_html($report),
        ]);
    }

    public static function get_items(WP_REST_Request $request) {
        $run_id = (int) $request['id'];
        $run = FIDTF_Run_Service::get_run_for_user($run_id, get_current_user_id());
        if (is_wp_error($run)) { return $run; }
        // v0.3.20: evidence loading must also attempt provider refresh/repair
        // before telling the user that no evidence exists.
        $run = self::refresh_run_jobs_and_status($run_id, $run, self::force_provider_refresh_requested($request));
        if (is_wp_error($run)) { return $run; }
        $args = [
            'page' => (int) ($request->get_param('page') ?: 1),
            'per_page' => max(1, min(50, (int) ($request->get_param('per_page') ?: 20))),
            'source' => sanitize_key((string) ($request->get_param('source') ?: '')),
            'relevance_tier' => sanitize_key((string) ($request->get_param('relevance_tier') ?: '')),
            'relevant_only' => (string) $request->get_param('relevant_only') !== '0',
        ];
        $items = FIDTF_Source_Job_Service::get_items($run_id, true, $args);
        return rest_ensure_response([
            'items' => self::prepare_items_for_response($items),
            'page' => max(1, (int) $args['page']),
            'per_page' => max(1, min(50, (int) $args['per_page'])),
            'has_more' => count($items) >= max(1, min(50, (int) $args['per_page'])),
        ]);
    }

    public static function openai_smoke_test(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = []; }
        return rest_ensure_response(FIDTF_AI_Bridge::run_openai_smoke_test($params));
    }

    public static function ingest(WP_REST_Request $request) {
        $run_id = (int) $request['id'];
        $params = $request->get_json_params();
        if (!is_array($params)) { $params = []; }
        $source_key = sanitize_key((string) ($params['source_key'] ?? ''));
        $source_job_id = (int) ($params['source_job_id'] ?? 0);
        $items = (array) ($params['items'] ?? []);
        if ($source_key === '' || empty($items)) {
            return new WP_Error('fidtf_invalid_ingest_payload', 'source_key and items are required.', ['status' => 400]);
        }
        $result = FIDTF_Run_Service::ingest_source_results($run_id, $source_job_id, $source_key, $items);
        if (is_wp_error($result)) { return $result; }
        return rest_ensure_response($result);
    }



    private static function refresh_run_jobs_and_status(int $run_id, array $run, bool $force_provider_refresh = false) {
        if ($run_id <= 0) { return $run; }
        $refresh_request = FIDTF_Run_Service::sanitize_request(self::request_context_from_run_row($run));
        if ($force_provider_refresh) { $refresh_request['_fidtf_force_provider_refresh'] = true; }
        FIDTF_Source_Job_Service::maybe_refresh_running_jobs($run_id, $refresh_request);
        $jobs = FIDTF_Source_Job_Service::get_jobs($run_id);
        $status = FIDTF_Run_Service::status_from_jobs($jobs);
        if ($status !== FIDTF_Run_Service::sanitize_status((string) ($run['status'] ?? ''), 'created')) {
            FIDTF_Run_Service::update_run($run_id, ['status' => $status, 'updated_at' => current_time('mysql')]);
            $refreshed = FIDTF_Run_Service::get_run_for_user($run_id, get_current_user_id());
            if (!is_wp_error($refreshed) && is_array($refreshed)) {
                $run = $refreshed;
            }
        }
        return $run;
    }


    private static function force_provider_refresh_requested(WP_REST_Request $request): bool {
        if (!current_user_can('manage_options')) { return false; }
        foreach (['force_provider_refresh', 'force_refresh'] as $key) {
            if ((string) $request->get_param($key) === '1') { return true; }
        }
        return false;
    }

    private static function request_context_from_run_row(array $run): array {
        $request = [];
        foreach (['user_brief', 'objective', 'market', 'language', 'date_range'] as $field) {
            if (isset($run[$field])) { $request[$field] = $run[$field]; }
        }
        if (!empty($run['keywords_json'])) { $request['keywords'] = json_decode((string) $run['keywords_json'], true) ?: []; }
        if (!empty($run['channels_json'])) { $request['channels'] = json_decode((string) $run['channels_json'], true) ?: []; }
        if (!empty($run['request_meta_json'])) {
            $meta = json_decode((string) $run['request_meta_json'], true);
            if (is_array($meta)) { $request = array_merge($request, $meta); }
        }
        return $request;
    }

    private static function prepare_run_for_response(array $run): array {
        return [
            'id' => (int) ($run['id'] ?? 0),
            'workspace_id' => (int) ($run['workspace_id'] ?? 0),
            'user_id' => (int) ($run['user_id'] ?? 0),
            'status' => FIDTF_Run_Service::sanitize_status((string) ($run['status'] ?? ''), 'created'),
            'user_brief' => sanitize_textarea_field((string) ($run['user_brief'] ?? '')),
            'objective' => sanitize_textarea_field((string) ($run['objective'] ?? '')),
            'market' => sanitize_text_field((string) ($run['market'] ?? '')),
            'language' => sanitize_key((string) ($run['language'] ?? '')),
            'date_range' => sanitize_key((string) ($run['date_range'] ?? '')),
            'credit_estimate' => (float) ($run['credit_estimate'] ?? 0),
            'credit_mode' => FIDTF_Credit_Service::credit_mode($run),
            'credits_reserved' => (float) ($run['credits_reserved'] ?? 0),
            'final_credits_settled' => (float) ($run['final_credits_settled'] ?? 0),
            'created_at' => sanitize_text_field((string) ($run['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($run['updated_at'] ?? '')),
            'completed_at' => sanitize_text_field((string) ($run['completed_at'] ?? '')),
        ];
    }

    private static function plan_from_run(array $run): array {
        $plan = [];
        if (!empty($run['ai_planner_output_json'])) {
            $decoded = json_decode((string) $run['ai_planner_output_json'], true);
            if (is_array($decoded)) { $plan = $decoded; }
        }
        return self::prepare_plan_for_response($plan);
    }

    private static function prepare_plan_for_response(array $plan): array {
        $out = [
            'planner_mode' => sanitize_key((string) ($plan['planner_mode'] ?? '')),
            'planner_notice' => sanitize_text_field((string) ($plan['planner_notice'] ?? '')),
            'validation_error' => sanitize_key((string) ($plan['validation_error'] ?? '')),
            'validation_notes' => array_values(array_map('sanitize_text_field', (array) ($plan['validation_notes'] ?? []))),
            'model_label' => sanitize_text_field((string) ($plan['model_label'] ?? '')),
            'brief_summary' => sanitize_textarea_field((string) ($plan['brief_summary'] ?? '')),
            'sources' => [],
            'request_context' => [],
        ];
        if (!empty($plan['request_context']) && is_array($plan['request_context'])) {
            $out['request_context'] = [
                'user_brief' => sanitize_textarea_field((string) ($plan['request_context']['user_brief'] ?? '')),
                'objective' => sanitize_textarea_field((string) ($plan['request_context']['objective'] ?? '')),
                'market' => sanitize_text_field((string) ($plan['request_context']['market'] ?? '')),
                'language' => sanitize_key((string) ($plan['request_context']['language'] ?? '')),
                'date_range' => sanitize_key((string) ($plan['request_context']['date_range'] ?? '')),
                'keywords' => array_values(array_map('sanitize_text_field', (array) ($plan['request_context']['keywords'] ?? []))),
                'brand' => sanitize_text_field((string) ($plan['request_context']['brand'] ?? '')),
                'company' => sanitize_text_field((string) ($plan['request_context']['company'] ?? '')),
                'competitors' => array_values(array_map('sanitize_text_field', (array) ($plan['request_context']['competitors'] ?? []))),
                'audience' => sanitize_text_field((string) ($plan['request_context']['audience'] ?? '')),
                'exclude_terms' => array_values(array_map('sanitize_text_field', (array) ($plan['request_context']['exclude_terms'] ?? []))),
                'channels' => array_values(array_map('sanitize_key', (array) ($plan['request_context']['channels'] ?? []))),
            ];
        }

        if (current_user_can('manage_options')) {
            if (!empty($plan['bridge_diagnostic'])) {
                $out['bridge_diagnostic'] = sanitize_text_field((string) $plan['bridge_diagnostic']);
            }
            if (!empty($plan['raw_text'])) {
                $out['raw_text'] = substr(sanitize_textarea_field((string) $plan['raw_text']), 0, 6000);
            }
        }
        foreach ((array) ($plan['sources'] ?? []) as $source => $source_plan) {
            if (!is_array($source_plan)) { continue; }
            $source_key = sanitize_key((string) ($source_plan['source_key'] ?? $source));
            if ($source_key === '') { continue; }
            $row = [
                'source_key' => $source_key,
                'max_items' => (int) ($source_plan['max_items'] ?? 0),
                'sort_strategy' => sanitize_text_field((string) ($source_plan['sort_strategy'] ?? '')),
                'notes' => sanitize_textarea_field((string) ($source_plan['notes'] ?? '')),
            ];
            foreach (self::public_source_plan_fields($source_key) as $field) {
                if (!array_key_exists($field, $source_plan)) { continue; }
                if (is_array($source_plan[$field])) {
                    $row[$field] = array_values(array_map('sanitize_text_field', (array) $source_plan[$field]));
                } else {
                    $row[$field] = sanitize_text_field((string) $source_plan[$field]);
                }
            }
            $out['sources'][$source_key] = $row;
        }
        return $out;
    }

    private static function public_source_plan_fields(string $source_key): array {
        switch (sanitize_key($source_key)) {
            case 'tiktok':
                return ['queries', 'hashtags', 'creator_or_format_hints', 'language_hints', 'market_hints'];
            case 'instagram':
                return ['queries', 'hashtags', 'profile_or_topic_hints', 'language_hints', 'market_hints'];
            case 'reddit':
                return ['queries', 'subreddits', 'time_range', 'language_hints', 'market_hints'];
            case 'google_trends':
                return ['seed_keywords', 'related_query_hints', 'geo', 'time_range', 'language_hints', 'market_hints'];
            case 'google_news':
                return ['queries', 'publisher_or_topic_hints', 'geo', 'language', 'time_range'];
            case 'ai_research':
                return ['research_questions', 'assumptions_to_test', 'market_questions', 'competitor_questions'];
            default:
                return ['queries', 'hashtags', 'language_hints', 'market_hints'];
        }
    }

    private static function payload_source_plan(array $payload): array {
        if (!empty($payload['source_plan']) && is_array($payload['source_plan'])) {
            return (array) $payload['source_plan'];
        }
        return $payload;
    }

    private static function derive_outcome_reason(string $source_key, string $status, bool $dispatch_attempted, int $raw_count, int $normalized_count, int $relevant_count): string {
        if (!$dispatch_attempted) { return 'planned_only'; }
        if (in_array($status, ['queued', 'running'], true)) { return 'run_running'; }
        if ($status === 'failed' || $status === 'retryable_failed' || $status === 'skipped') { return 'provider_failed'; }
        if ($status === 'completed') { return 'evidence_returned'; }
        if ($status === 'completed_no_relevant_evidence') {
            if ($raw_count === 0) { return 'zero_dataset_items'; }
            if ($normalized_count === 0) { return 'normalizer_extracted_zero'; }
            if ($relevant_count === 0) { return 'no_items_passed_relevance'; }
            return 'evidence_returned';
        }
        return 'planned_only';
    }

    private static function planned_text_fields_for_payload(array $payload): array {
        $plan = self::payload_source_plan($payload);
        $fields = ['queries', 'seed_keywords', 'research_questions', 'hashtags', 'creator_or_format_hints', 'profile_or_topic_hints', 'subreddits', 'related_query_hints', 'publisher_or_topic_hints', 'assumptions_to_test', 'market_questions', 'competitor_questions'];
        $out = [];
        foreach ($fields as $field) {
            foreach ((array) ($plan[$field] ?? []) as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') { $out[] = $item; }
            }
        }
        return array_values(array_unique(array_slice($out, 0, 30)));
    }

    private static function plan_summary_for_payload(array $payload): array {
        $plan = self::payload_source_plan($payload);
        $summary = [
            'queries' => [],
            'hashtags' => [],
            'creator_or_format_hints' => [],
            'profile_or_topic_hints' => [],
            'subreddits' => [],
            'seed_keywords' => [],
            'related_query_hints' => [],
            'publisher_or_topic_hints' => [],
            'research_questions' => [],
            'assumptions_to_test' => [],
            'market_questions' => [],
            'competitor_questions' => [],
            'geo' => '',
            'language' => '',
            'time_range' => '',
            'sort_strategy' => '',
            'max_items' => 0,
        ];
        foreach (['queries', 'hashtags', 'creator_or_format_hints', 'profile_or_topic_hints', 'subreddits', 'seed_keywords', 'related_query_hints', 'publisher_or_topic_hints', 'research_questions', 'assumptions_to_test', 'market_questions', 'competitor_questions'] as $field) {
            $summary[$field] = array_values(array_map('sanitize_text_field', (array) ($plan[$field] ?? [])));
        }
        foreach (['geo', 'language', 'time_range', 'sort_strategy'] as $field) {
            $summary[$field] = sanitize_text_field((string) ($plan[$field] ?? ''));
        }
        $summary['max_items'] = (int) ($plan['max_items'] ?? ($payload['limits']['max_items'] ?? ($payload['max_items'] ?? 0)));
        return $summary;
    }

    private static function prepare_jobs_for_response(array $jobs): array {
        $is_admin = current_user_can('manage_options');
        $out = [];
        foreach ($jobs as $job) {
            if (!is_array($job)) { continue; }
            $source_key_public = sanitize_key((string) ($job['source_key'] ?? ''));
            $stored_public_diag = method_exists('FIDTF_Source_Job_Service', 'dispatch_diagnostics_for_job')
                ? FIDTF_Source_Job_Service::dispatch_diagnostics_for_job((int) ($job['id'] ?? 0))
                : [];
            $safe_provider_rows = max(0, (int) ($stored_public_diag['discovery_dataset_rows'] ?? $job['provider_dataset_rows'] ?? 0));
            $safe_provider_total_rows = max($safe_provider_rows, (int) ($stored_public_diag['discovery_dataset_total_rows'] ?? $job['provider_dataset_total_rows'] ?? 0));
            $safe_flattened_items = max(0, (int) ($stored_public_diag['discovery_flattened_items'] ?? $job['raw_count'] ?? 0));
            $safe_normalized_items = max(0, (int) ($stored_public_diag['normalized_items'] ?? $job['normalized_count'] ?? 0));
            $safe_relevant_items = max(0, (int) ($stored_public_diag['relevant_items'] ?? $job['relevant_count'] ?? 0));
            $public = [
                'id' => (int) ($job['id'] ?? 0),
                'run_id' => (int) ($job['run_id'] ?? 0),
                'source_key' => $source_key_public,
                'status' => FIDTF_Source_Job_Service::sanitize_status((string) ($job['status'] ?? ''), 'planned'),
                'raw_count' => max((int) ($job['raw_count'] ?? 0), $safe_flattened_items),
                'provider_dataset_rows' => $safe_provider_rows,
                'provider_dataset_total_rows' => $safe_provider_total_rows,
                'flattened_raw_items' => $safe_flattened_items,
                'normalized_count' => max((int) ($job['normalized_count'] ?? 0), $safe_normalized_items),
                'relevant_count' => max((int) ($job['relevant_count'] ?? 0), $safe_relevant_items),
                'retryable' => !empty($job['retryable']),
            ];
            $payload = [];
            if (!empty($job['query_payload_json'])) {
                $decoded_payload = json_decode((string) $job['query_payload_json'], true);
                if (is_array($decoded_payload)) { $payload = $decoded_payload; }
            }
            $summary = self::plan_summary_for_payload($payload);
            $public['plan_summary'] = $summary;
            $public['planned_queries'] = self::planned_text_fields_for_payload($payload);
            $public['planned_hashtags'] = $summary['hashtags'];
            $public['limit'] = (int) ($summary['max_items'] ?? 0);
            if (!empty($summary['sort_strategy'])) { $public['sort_strategy'] = sanitize_text_field((string) $summary['sort_strategy']); }
            if (!empty($job['error_code'])) { $public['error_code'] = sanitize_text_field((string) $job['error_code']); }
            if ($is_admin) {
                $provider_run_id = sanitize_text_field((string) ($job['provider_run_id'] ?? ''));
                $error_code = sanitize_key((string) ($job['error_code'] ?? ''));
                $source_key = sanitize_key((string) ($job['source_key'] ?? ''));
                $status = FIDTF_Source_Job_Service::sanitize_status((string) ($job['status'] ?? ''), 'planned');
                $dispatch_attempted = $provider_run_id !== '' || in_array($status, ['queued', 'running', 'completed', 'completed_no_relevant_evidence'], true);
                if (in_array($error_code, ['global_live_disabled', 'live_dispatch_disabled', 'tiktok_live_bridge_disabled', 'source_live_bridge_disabled', 'core_apify_unavailable', 'core_apify_client_unavailable', 'core_apify_client_incomplete', 'direct_token_missing', 'external_filter_missing', 'provider_not_ready', 'tiktok_provider_unavailable'], true)) {
                    $dispatch_attempted = false;
                }
                $public['provider'] = sanitize_text_field((string) ($job['provider'] ?? ''));
                $public['actor_id'] = sanitize_text_field((string) ($job['actor_id'] ?? ''));
                $public['provider_run_id'] = $provider_run_id;
                $effective_mode = $source_key === 'tiktok' ? FIDTF_Settings::tiktok_effective_provider_mode() : (FIDTF_Settings::source_live_ready($source_key) ? 'generic_apify_live' : 'planned_only');
                $configured_mode = $source_key === 'tiktok' ? FIDTF_Settings::tiktok_provider_mode() : (FIDTF_Settings::source_live_ready($source_key) ? 'generic_apify_live' : 'planned_only');
                $raw_count = (int) ($job['raw_count'] ?? 0);
                $normalized_count = (int) ($job['normalized_count'] ?? 0);
                $relevant_count = (int) ($job['relevant_count'] ?? 0);
                $outcome_reason = $error_code !== ''
                    ? $error_code
                    : self::derive_outcome_reason($source_key, $status, $dispatch_attempted, $raw_count, $normalized_count, $relevant_count);
                $stored_diag = $stored_public_diag;
                $discovery_actor_input = [];
                if ($source_key === 'tiktok') {
                    $discovery_actor_input = is_array($stored_diag['discovery_actor_input'] ?? null)
                        ? (array) $stored_diag['discovery_actor_input']
                        : (!empty($payload) ? FIDTF_Provider_TikTok::build_actor_input_from_payload($payload) : []);
                } else {
                    $discovery_actor_input = is_array($stored_diag['discovery_actor_input'] ?? null)
                        ? (array) $stored_diag['discovery_actor_input']
                        : [];
                }
                foreach (['token', 'api_key', 'apikey', 'apiKey', 'password', 'Authorization', 'authorization'] as $secret_key) {
                    if (array_key_exists($secret_key, $discovery_actor_input)) {
                        unset($discovery_actor_input[$secret_key]);
                    }
                }
                $discovery_dataset_rows = (int) ($stored_diag['discovery_dataset_rows'] ?? $job['provider_dataset_rows'] ?? 0);
                $discovery_dataset_total_rows = max($discovery_dataset_rows, (int) ($stored_diag['discovery_dataset_total_rows'] ?? $job['provider_dataset_total_rows'] ?? 0));
                $discovery_flattened_items = (int) ($stored_diag['discovery_flattened_items'] ?? $raw_count);
                $diagnostic_mode_used = sanitize_text_field((string) ($stored_diag['provider_mode_used'] ?? ''));
                if ($diagnostic_mode_used === '') {
                    $diagnostic_mode_used = $source_key === 'tiktok' && $dispatch_attempted ? 'tiktok_discovery' : ($dispatch_attempted ? $effective_mode : 'none');
                }
                $public['dispatch_diagnostic'] = [
                    'dispatch_attempted' => $dispatch_attempted,
                    'request_attempted' => !empty($stored_diag['request_attempted']) || $dispatch_attempted,
                    'reason' => sanitize_text_field((string) ($stored_diag['outcome_reason'] ?? $outcome_reason)),
                    'outcome_reason' => sanitize_text_field((string) ($stored_diag['outcome_reason'] ?? $outcome_reason)),
                    'provider_mode' => $configured_mode,
                    'provider_mode_used' => $diagnostic_mode_used,
                    'actor_input_profile' => sanitize_key((string) ($stored_diag['actor_input_profile'] ?? '')),
                    'start_method' => $dispatch_attempted ? $diagnostic_mode_used : 'none',
                    'error_code' => sanitize_key((string) ($stored_diag['error_code'] ?? $error_code)),
                    'error_message' => sanitize_text_field((string) ($stored_diag['error_message'] ?? '')),
                    'retryable' => !empty($stored_diag['retryable']) || !empty($job['retryable']),
                    'retry_after' => max(0, (int) ($stored_diag['retry_after'] ?? 0)),
                    'timestamp' => sanitize_text_field((string) ($job['updated_at'] ?? '')),
                    'addon_version' => defined('FIDTF_VERSION') ? FIDTF_VERSION : '',
                    'discovery_actor_id' => sanitize_text_field((string) ($stored_diag['discovery_actor_id'] ?? ($source_key === 'tiktok' ? FIDTF_Settings::tiktok_discovery_actor_id() : FIDTF_Settings::actor_for($source_key)))),
                    'enrichment_actor_id' => sanitize_text_field((string) ($stored_diag['enrichment_actor_id'] ?? ($source_key === 'tiktok' ? FIDTF_Settings::tiktok_enrichment_actor_id() : ''))),
                    'discovery_actor_input' => $discovery_actor_input,
                    'discovery_run_id' => sanitize_text_field((string) ($stored_diag['discovery_run_id'] ?? $provider_run_id)),
                    'discovery_dataset_rows' => $discovery_dataset_rows,
                    'discovery_dataset_total_rows' => $discovery_dataset_total_rows,
                    'discovery_flattened_items' => $discovery_flattened_items,
                    'normalized_items' => (int) ($stored_diag['normalized_items'] ?? $normalized_count),
                    'relevant_items' => (int) ($stored_diag['relevant_items'] ?? $relevant_count),
                    'enrichment_attempted' => !empty($stored_diag['enrichment_attempted']),
                    'enrichment_run_ids' => array_values(array_map('sanitize_text_field', (array) ($stored_diag['enrichment_run_ids'] ?? []))),
                    'enrichment_comments_count' => (int) ($stored_diag['enrichment_comments_count'] ?? 0),
                    'output_counts' => [
                        'provider_dataset_rows' => $discovery_dataset_rows,
                        'provider_dataset_total_rows' => $discovery_dataset_total_rows,
                        'flattened_raw_items' => $discovery_flattened_items,
                        'normalized_items' => (int) ($stored_diag['normalized_items'] ?? $normalized_count),
                        'relevant_items' => (int) ($stored_diag['relevant_items'] ?? $relevant_count),
                    ],
                ];
                if (!empty($discovery_actor_input)) {
                    $public['dispatch_diagnostic']['actor_input_preview'] = array_slice($discovery_actor_input, 0, 12, true);
                    $public['dispatch_diagnostic']['sanitized_actor_input'] = $discovery_actor_input;
                } elseif (!empty($stored_diag['discovery_actor_input']) && is_array($stored_diag['discovery_actor_input'])) {
                    $public['dispatch_diagnostic']['actor_input_preview'] = array_slice((array) $stored_diag['discovery_actor_input'], 0, 12, true);
                }
                if ($source_key === 'tiktok' && method_exists('FIDTF_Source_Job_Service', 'view_diagnostics_for_job')) {
                    $view_diag = FIDTF_Source_Job_Service::view_diagnostics_for_job((int) ($job['id'] ?? 0));
                    if (!empty($view_diag)) {
                        $public['dispatch_diagnostic']['view_diagnostics'] = [
                            'view_count_missing' => (int) ($view_diag['view_count_missing'] ?? 0),
                            'below_min_views' => (int) ($view_diag['below_min_views'] ?? 0),
                            'view_gate_passed' => (int) ($view_diag['view_gate_passed'] ?? 0),
                            'configured_min_views' => FIDTF_Settings::tiktok_min_views(),
                        ];
                    }
                }
            }
            $out[] = $public;
        }
        return $out;
    }

    private static function prepare_items_for_response(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $item = [];
            if (is_array($row) && isset($row['normalized_json'])) {
                $decoded = json_decode((string) $row['normalized_json'], true);
                $item = is_array($decoded) ? $decoded : $row;
            } elseif (is_array($row)) {
                $item = $row;
            }
            if (empty($item)) { continue; }
            unset($item['raw']);
            $out[] = [
                'source_key' => sanitize_key((string) ($item['source_key'] ?? $item['source'] ?? '')),
                'external_id' => sanitize_text_field((string) ($item['external_id'] ?? '')),
                'url' => esc_url_raw((string) ($item['url'] ?? '')),
                'author' => sanitize_text_field((string) ($item['author'] ?? '')),
                'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                'caption_or_text' => sanitize_textarea_field((string) ($item['caption_or_text'] ?? '')),
                'published_at' => sanitize_text_field((string) ($item['published_at'] ?? '')),
                'metrics' => (array) ($item['metrics'] ?? []),
                'relevance_score' => (float) ($item['relevance_score'] ?? 0),
                'relevance_tier' => sanitize_key((string) ($item['relevance_tier'] ?? '')),
                'recommended_use' => sanitize_key((string) ($item['recommended_use'] ?? '')),
                'evidence_tier' => sanitize_key((string) ($item['evidence_tier'] ?? '')),
                'marketing_allowed_use' => sanitize_key((string) ($item['marketing_allowed_use'] ?? '')),
                'client_safe' => !empty($item['client_safe']),
                'risk_note' => sanitize_textarea_field((string) ($item['risk_note'] ?? '')),
                'signal_type' => sanitize_text_field((string) ($item['signal_type'] ?? '')),
                'relevance_reason' => sanitize_textarea_field((string) ($item['relevance_reason'] ?? '')),
                'evidence_quality' => (array) ($item['evidence_quality'] ?? []),
                'media' => (array) ($item['media'] ?? []),
                'transcript' => (array) ($item['transcript'] ?? []),
                'subtitles' => (array) ($item['subtitles'] ?? []),
                'hashtags' => array_values(array_map('sanitize_text_field', (array) ($item['hashtags'] ?? []))),
                'provider_query' => sanitize_text_field((string) ($item['provider_query'] ?? '')),
                'matched_keywords' => array_values(array_map('sanitize_text_field', (array) ($item['matched_keywords'] ?? []))),
                'matched_core_keywords' => array_values(array_map('sanitize_text_field', (array) ($item['matched_core_keywords'] ?? []))),
                'matched_expansion_keywords' => array_values(array_map('sanitize_text_field', (array) ($item['matched_expansion_keywords'] ?? []))),
            ];
        }
        return $out;
    }
}
