<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_TikTok_Live_Adapter {
    const API_BASE = 'https://api.apify.com/v2';
    private $last_provider_dataset_rows = 0;
    private $last_flattened_raw_items = 0;
    private $last_provider_dataset_total_rows = 0;

    public function start(array $canonical_payload) {
        if (sanitize_key((string) ($canonical_payload['source_key'] ?? '')) !== 'tiktok') {
            return new WP_Error('invalid_tiktok_payload', 'TikTok adapter received a non-TikTok payload.', ['status' => 400, 'retryable' => false]);
        }

        $actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload($canonical_payload);
        $validation = FIDTF_Provider_TikTok::validate_discovery_actor_input(FIDTF_Settings::tiktok_discovery_actor_id(), $actor_input);
        if (is_wp_error($validation)) { return $validation; }
        $context = $this->context($canonical_payload, $actor_input);
        $mode = FIDTF_Settings::tiktok_effective_provider_mode();

        if ($mode === 'external_filter') {
            $external = apply_filters('fidtf_tiktok_live_dispatch_result', null, $context, $canonical_payload, $actor_input);
            if (is_array($external) || is_wp_error($external)) { return $this->sanitize_external_result($external); }
            $legacy = apply_filters('fidtf_dispatch_source_job', null, $context, $canonical_payload['source_plan'] ?? [], $canonical_payload['request_context'] ?? []);
            if (is_array($legacy) || is_wp_error($legacy)) { return $this->sanitize_external_result($legacy); }
            return new WP_Error('tiktok_provider_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }

        if ($mode === 'core_apify_client') {
            $core = $this->run_with_core_client($context, $canonical_payload, $actor_input);
            if (is_array($core) || is_wp_error($core)) { return $this->sanitize_external_result($core); }
            return new WP_Error('tiktok_provider_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }

        return $this->start_direct_apify_run($context, $actor_input);
    }

    public function refresh(array $job, array $canonical_payload) {
        $provider_run_id = sanitize_text_field((string) ($job['provider_run_id'] ?? ''));
        if ($provider_run_id === '') {
            return new WP_Error('missing_provider_run_id', 'TikTok job has no provider run id to refresh.', ['status' => 400, 'retryable' => false]);
        }

        $actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload($canonical_payload);
        $context = $this->context($canonical_payload, $actor_input);
        $context['provider_run_id'] = $provider_run_id;
        $context['job_id'] = (int) ($job['id'] ?? 0);
        $mode = FIDTF_Settings::tiktok_effective_provider_mode();

        if ($mode === 'external_filter') {
            $external = apply_filters('fidtf_tiktok_live_refresh_result', null, $context, $canonical_payload, $actor_input);
            if (is_array($external) || is_wp_error($external)) { return $this->sanitize_external_result($external); }
            return ['ok' => true, 'status' => 'running', 'provider_run_id' => $provider_run_id, 'items' => [], 'message' => 'TikTok provider run is still pending.'];
        }

        if ($mode === 'core_apify_client') {
            $core = $this->refresh_with_core_client($context, $canonical_payload, $actor_input);
            if (is_array($core) || is_wp_error($core)) { return $this->sanitize_external_result($core); }
            return ['ok' => true, 'status' => 'running', 'provider_run_id' => $provider_run_id, 'items' => [], 'message' => 'TikTok provider run is still pending.'];
        }

        return $this->refresh_direct_apify_run($context, $provider_run_id);
    }

    private function context(array $canonical_payload, array $actor_input): array {
        return [
            'source_key' => 'tiktok',
            'provider' => 'tiktok_live_bridge',
            'provider_mode' => FIDTF_Settings::tiktok_effective_provider_mode(),
            'configured_provider_mode' => FIDTF_Settings::tiktok_provider_mode(),
            'actor_id' => FIDTF_Settings::tiktok_discovery_actor_id(),
            'discovery_actor_id' => FIDTF_Settings::tiktok_discovery_actor_id(),
            'enrichment_actor_id' => FIDTF_Settings::tiktok_enrichment_actor_id(),
            'actor_input' => $actor_input,
            'discovery_actor_input' => $actor_input,
            'payload' => $canonical_payload,
            'has_token' => FIDTF_Settings::tiktok_apify_token() !== '',
        ];
    }

    private function run_with_core_client(array $context, array $canonical_payload, array $actor_input) {
        $external = apply_filters('fidtf_tiktok_core_apify_client', null, $context, $canonical_payload, $actor_input);
        if (is_wp_error($external) || is_array($external)) { return $external; }
        if (!class_exists('FIDTF_Core_Apify_Client_Adapter')) {
            return new WP_Error('core_apify_client_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        $adapter = new FIDTF_Core_Apify_Client_Adapter();
        return $adapter->start($context, $actor_input);
    }

    private function refresh_with_core_client(array $context, array $canonical_payload, array $actor_input) {
        $external = apply_filters('fidtf_tiktok_core_apify_client', null, $context, $canonical_payload, $actor_input);
        if (is_wp_error($external) || is_array($external)) { return $external; }
        if (!class_exists('FIDTF_Core_Apify_Client_Adapter')) {
            return new WP_Error('tiktok_refresh_blocked_by_configuration', 'TikTok refresh is blocked by provider configuration.', ['status' => 503, 'retryable' => true]);
        }
        $adapter = new FIDTF_Core_Apify_Client_Adapter();
        return $adapter->refresh($context, $this->limit_from_actor_input($actor_input));
    }

    /**
     * Phase 9D.2 — legacy direct run-start is DISABLED. TikTok dispatch may only
     * go through the core gate (FIDTF_Core_Apify_Client_Adapter →
     * VES_Apify_Client::request with fail-closed allowlist + hard charge
     * ceiling). Without the core client this FAILS CLOSED with a scrubbed
     * security event — never an unguarded wp_remote_post. Reads stay read-only.
     */
    private function start_direct_apify_run(array $context, array $actor_input) {
        if (class_exists('FIDTF_Core_Apify_Client_Adapter') && FIDTF_Core_Apify_Client_Adapter::available()) {
            $core = $this->run_with_core_client($context, [], $actor_input);
            if (is_array($core) || is_wp_error($core)) { return $this->sanitize_external_result($core); }
        }
        if (class_exists('VES_Security_Event_Log')) {
            VES_Security_Event_Log::record('provider_dispatch_blocked', 'DTF TikTok Apify dispatch blocked: core provider client unavailable (fail-closed, legacy direct path disabled).', [
                'module' => 'deep_trend_finder',
                'source_key' => 'tiktok',
            ]);
        }
        return new WP_Error('tiktok_dispatch_blocked_fail_closed', 'TikTok dispatch is blocked: the guarded provider client is unavailable and direct dispatch is disabled.', ['status' => 503, 'retryable' => false, 'request_attempted' => false]);
    }

    private function apify_run_succeeded(string $status): bool {
        return strtoupper($status) === 'SUCCEEDED';
    }

    private function apify_run_failed(string $status): bool {
        return in_array(strtoupper($status), ['FAILED', 'ABORTED', 'TIMED-OUT'], true);
    }

    private function non_terminal_status_for_response(string $status, string $default = 'running'): string {
        $status = strtoupper($status);
        if (in_array($status, ['READY', 'STARTING'], true)) { return 'queued'; }
        if (in_array($status, ['RUNNING', 'TIMING-OUT'], true)) { return 'running'; }
        return $default;
    }

    private function refresh_direct_apify_run(array $context, string $provider_run_id) {
        $token = FIDTF_Settings::tiktok_apify_token();
        if ($token === '') {
            return new WP_Error('tiktok_provider_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        if (!function_exists('wp_remote_get')) {
            return new WP_Error('tiktok_provider_unavailable', 'WordPress HTTP API is unavailable.', ['status' => 503, 'retryable' => false]);
        }

        $run_url = self::API_BASE . '/actor-runs/' . rawurlencode($provider_run_id);
        $response = wp_remote_get($run_url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 12,
        ]);
        if (is_wp_error($response)) { return $this->map_http_error($response); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        if ($code === 429) { return new WP_Error('provider_rate_limited', 'TikTok provider is rate limited.', ['status' => 429, 'retryable' => true]); }
        if ($code >= 500) { return new WP_Error('provider_busy', 'TikTok provider is temporarily unavailable.', ['status' => $code, 'retryable' => true]); }
        if ($code < 200 || $code >= 300) { return new WP_Error('tiktok_provider_failed', 'TikTok provider returned an error.', ['status' => $code, 'retryable' => false]); }
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? (array) ($decoded['data'] ?? $decoded) : [];
        $status = strtoupper((string) ($data['status'] ?? ''));
        $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? ''));
        // READY is queued, not terminal. Only fetch dataset after SUCCEEDED.
        if ($this->apify_run_succeeded($status)) {
            $items = $dataset_id !== '' ? $this->fetch_dataset_items($dataset_id, $this->limit_from_actor_input((array) ($context['actor_input'] ?? []))) : [];
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($provider_run_id, $items, $context, (array) ($context['actor_input'] ?? []));
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('tiktok_provider_failed', 'TikTok provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return ['ok' => true, 'status' => $this->non_terminal_status_for_response($status, 'running'), 'provider_run_id' => $provider_run_id, 'items' => []];
    }

    private function fetch_dataset_items(string $dataset_id, int $limit) {
        // v0.3.32 live QA: Clockworks TikTok search datasets can have
        // cleanItemCount=0 even when valid itemCount rows exist. Fetch raw
        // rows and rely on FIDTF_Normalizer instead of Apify clean filtering.
        $token = FIDTF_Settings::tiktok_apify_token();
        $this->last_provider_dataset_total_rows = $this->fetch_dataset_total_count($dataset_id);
        $base = self::API_BASE . '/datasets/' . rawurlencode($dataset_id) . '/items';
        $args = [
            'format' => 'json',
            'clean' => 'false',
            'limit' => max(1, min(300, $limit)),
        ];
        $url = function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) { return $this->map_http_error($response); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        if ($code === 429) { return new WP_Error('provider_rate_limited', 'TikTok provider is rate limited.', ['status' => 429, 'retryable' => true]); }
        if ($code >= 500) { return new WP_Error('provider_busy', 'TikTok provider is temporarily unavailable.', ['status' => $code, 'retryable' => true]); }
        if ($code < 200 || $code >= 300) { return new WP_Error('tiktok_dataset_fetch_failed', 'TikTok dataset fetch failed.', ['status' => $code, 'retryable' => false]); }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) { return []; }
        $items = $this->sanitize_items($decoded);
        if ($this->last_provider_dataset_total_rows < $this->last_provider_dataset_rows) {
            $this->last_provider_dataset_total_rows = $this->last_provider_dataset_rows;
        }
        return $items;
    }

    private function fetch_dataset_total_count(string $dataset_id): int {
        $token = FIDTF_Settings::tiktok_apify_token();
        if ($token === '' || !function_exists('wp_remote_get')) { return 0; }
        $url = self::API_BASE . '/datasets/' . rawurlencode($dataset_id);
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) { return 0; }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        if ($code < 200 || $code >= 300) { return 0; }
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) { return 0; }
        $data = is_array($decoded['data'] ?? null) ? (array) $decoded['data'] : $decoded;
        return max(0, (int) ($data['itemCount'] ?? $data['cleanItemCount'] ?? 0));
    }

    private function normalize_http_run_response($response, array $context = [], array $actor_input = []) {
        if (is_wp_error($response)) { return $this->map_http_error($response); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        if ($code === 429) { return new WP_Error('provider_rate_limited', 'TikTok provider is rate limited.', ['status' => 429, 'retryable' => true]); }
        if ($code >= 500) { return new WP_Error('provider_busy', 'TikTok provider is temporarily unavailable.', ['status' => $code, 'retryable' => true]); }
        if ($code === 401 || $code === 403) { return new WP_Error('tiktok_provider_unavailable', 'TikTok provider is not configured.', ['status' => $code, 'retryable' => false]); }
        if ($code < 200 || $code >= 300) { return new WP_Error('tiktok_provider_failed', 'TikTok provider returned an error.', ['status' => $code, 'retryable' => false]); }
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? (array) ($decoded['data'] ?? $decoded) : [];
        $run_id = $this->extract_run_id($decoded, $data);
        $status = strtoupper((string) ($data['status'] ?? ''));
        if ($run_id === '' && !empty($data['items']) && is_array($data['items'])) {
            $items = $this->sanitize_items((array) $data['items']);
            return $this->completed_result('', $items, $context, $actor_input);
        }
        if ($run_id === '') {
            return new WP_Error('tiktok_provider_invalid_run_response', 'TikTok provider returned an invalid run response.', ['status' => 502, 'retryable' => false]);
        }
        if ($this->apify_run_succeeded($status)) {
            $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? ''));
            $items = $dataset_id !== '' ? $this->fetch_dataset_items($dataset_id, $this->limit_from_actor_input($actor_input)) : [];
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($run_id, $items, $context, $actor_input);
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('tiktok_provider_failed', 'TikTok provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return ['ok' => true, 'status' => $this->non_terminal_status_for_response($status, 'queued'), 'provider_run_id' => $run_id, 'items' => []];
    }

    private function extract_run_id($decoded, array $data): string {
        foreach ([$data, is_array($decoded) ? $decoded : []] as $src) {
            foreach (['id', 'runId', 'provider_run_id'] as $key) {
                if (!empty($src[$key])) { return sanitize_text_field((string) $src[$key]); }
            }
        }
        return '';
    }

    private function sanitize_external_result($result) {
        if (is_wp_error($result)) { return $result; }
        if (!is_array($result)) { return $result; }
        if (!empty($result['items']) && is_array($result['items'])) {
            $result['provider_dataset_rows'] = FIDTF_Provider_TikTok::provider_dataset_row_count((array) $result['items']);
            $result['items'] = $this->sanitize_items((array) $result['items']);
            $result['flattened_raw_items'] = count($result['items']);
            $result['raw_count'] = count($result['items']);
        }
        $status = sanitize_key((string) ($result['status'] ?? ''));
        $provider_run_id = sanitize_text_field((string) ($result['provider_run_id'] ?? $result['run_id'] ?? ''));
        if (empty($result['items']) && in_array($status, ['queued', 'running'], true) && $provider_run_id === '') {
            return new WP_Error('tiktok_provider_invalid_run_response', 'TikTok provider returned an invalid run response.', ['status' => 502, 'retryable' => false]);
        }
        if (!empty($result['error_code']) && in_array(sanitize_key((string) $result['error_code']), ['provider_busy', 'provider_rate_limited'], true)) {
            $result['retryable'] = true;
            $result['status'] = 'retryable_failed';
        }
        return $result;
    }

    private function sanitize_items(array $items): array {
        $this->last_provider_dataset_rows = FIDTF_Provider_TikTok::provider_dataset_row_count($items);
        $out = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items($items);
        $this->last_flattened_raw_items = count($out);
        return $out;
    }

    private function completed_result(string $provider_run_id, array $items, array $context = [], array $actor_input = []): array {
        $count = count($items);
        $status = $count > 0 ? 'completed' : 'completed_no_relevant_evidence';
        if ($count > 0) {
            $message = 'TikTok discovery completed.';
            $outcome_reason = 'evidence_returned';
        } elseif ($this->last_provider_dataset_rows > 0 || $this->last_provider_dataset_total_rows > 0) {
            $message = 'TikTok provider returned dataset rows, but the add-on could not extract usable posts yet.';
            $outcome_reason = 'flattened_empty';
        } else {
            $message = 'Discovery returned zero posts';
            $outcome_reason = 'zero_dataset_items';
        }
        return [
            'ok' => true,
            'status' => $status,
            'provider_run_id' => $provider_run_id,
            'items' => $items,
            'raw_count' => $count,
            'provider_dataset_rows' => $this->last_provider_dataset_rows,
            'provider_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'flattened_raw_items' => $this->last_flattened_raw_items,
            'provider_mode_used' => 'tiktok_discovery',
            'request_attempted' => true,
            'outcome_reason' => $outcome_reason,
            'message' => $message,
            'discovery_actor_id' => sanitize_text_field((string) ($context['discovery_actor_id'] ?? $context['actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id())),
            'enrichment_actor_id' => FIDTF_Settings::tiktok_enrichment_actor_id(),
            'discovery_actor_input' => !empty($actor_input) ? $actor_input : (array) ($context['actor_input'] ?? []),
            'discovery_run_id' => $provider_run_id,
            'discovery_dataset_rows' => $this->last_provider_dataset_rows,
            'discovery_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'discovery_flattened_items' => $this->last_flattened_raw_items,
            'enrichment_attempted' => false,
            'enrichment_run_ids' => [],
            'enrichment_comments_count' => 0,
            'enrichment_provider_mode' => 'tiktok_enrichment',
        ];
    }

    private function limit_from_actor_input(array $actor_input): int {
        foreach (['maxItems', 'resultsPerPage', 'searchPosts_count'] as $key) {
            if (isset($actor_input[$key])) { return max(1, min(300, (int) $actor_input[$key])); }
        }
        return FIDTF_Settings::tiktok_default_limit();
    }

    private function map_http_error($error) {
        return new WP_Error('provider_busy', is_wp_error($error) ? $error->get_error_message() : 'TikTok provider request failed.', ['status' => 503, 'retryable' => true]);
    }

    private function actor_path(string $actor_id): string {
        $actor_id = str_replace('/', '~', trim($actor_id));
        return rawurlencode($actor_id);
    }
}
