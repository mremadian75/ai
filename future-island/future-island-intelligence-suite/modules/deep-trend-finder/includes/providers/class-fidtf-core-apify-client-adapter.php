<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Adapter from the Deep Trend Finder add-on contract to the real Future Island
 * Core Apify client static API. This class intentionally does not read or expose
 * Core tokens; all provider auth remains inside VES_Apify_Client::request().
 */
final class FIDTF_Core_Apify_Client_Adapter {
    private $last_provider_dataset_rows = 0;
    private $last_flattened_raw_items = 0;
    private $last_provider_dataset_total_rows = 0;
    const API_BASE = 'https://api.apify.com/v2';

    public static function required_methods(): array {
        return ['request', 'fetch_run', 'fetch_items'];
    }

    public static function diagnostics(): array {
        $class_exists = class_exists('VES_Apify_Client');
        $methods = [];
        $missing = [];
        foreach (self::required_methods() as $method) {
            $ok = $class_exists && method_exists('VES_Apify_Client', $method);
            $methods[$method] = $ok;
            if (!$ok) { $missing[] = $method; }
        }
        return [
            'core_active' => defined('VES_PLUGIN_VERSION') || class_exists('VES_Plugin') || class_exists('VES_Config') || $class_exists,
            'ves_apify_client_available' => $class_exists,
            'methods' => $methods,
            'missing_methods' => $missing,
            'complete' => $class_exists && empty($missing),
        ];
    }

    public static function available(): bool {
        $diag = self::diagnostics();
        return !empty($diag['complete']);
    }

    public function start(array $context, array $actor_input) {
        $guard = $this->guard_available();
        if (is_wp_error($guard)) { return $guard; }

        $actor_id = sanitize_text_field((string) ($context['discovery_actor_id'] ?? $context['actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id()));
        $slot_id = $this->acquire_core_slot($actor_id, (array) ($context['payload'] ?? []));
        if (is_wp_error($slot_id)) { return $this->map_core_error($slot_id); }

        $url = $this->run_url($actor_id);
        try {
            $body = wp_json_encode($actor_input);
            $response = call_user_func(['VES_Apify_Client', 'request'], 'POST', $url, $body);
        } catch (Throwable $e) {
            $this->release_core_slot($slot_id);
            return new WP_Error('tiktok_provider_exception', 'TikTok provider could not start.', ['status' => 500, 'retryable' => false]);
        }

        if (is_wp_error($response)) {
            $this->release_core_slot($slot_id);
            return $this->map_core_error($response);
        }

        $normalized = $this->normalize_start_response($response, $context, $actor_input);
        if (is_wp_error($normalized)) {
            $this->release_core_slot($slot_id);
            return $normalized;
        }

        $run_id = sanitize_text_field((string) ($normalized['provider_run_id'] ?? ''));
        if ($slot_id && $run_id !== '' && method_exists('VES_Apify_Client', 'register_run_id_for_slot')) {
            call_user_func(['VES_Apify_Client', 'register_run_id_for_slot'], $slot_id, $run_id);
        }
        if ($slot_id && in_array((string) ($normalized['status'] ?? ''), ['completed', 'failed'], true)) {
            $this->release_core_slot($slot_id);
        }
        return $normalized;
    }

    public function refresh(array $context, int $limit = 0) {
        $guard = $this->guard_available();
        if (is_wp_error($guard)) {
            return new WP_Error('tiktok_refresh_blocked_by_configuration', 'TikTok refresh is blocked by provider configuration.', ['status' => 503, 'retryable' => true]);
        }
        $run_id = sanitize_text_field((string) ($context['provider_run_id'] ?? ''));
        if ($run_id === '') {
            return new WP_Error('missing_provider_run_id', 'TikTok job has no provider run id to refresh.', ['status' => 400, 'retryable' => false]);
        }
        $limit = $limit > 0 ? $limit : $this->limit_from_actor_input((array) ($context['actor_input'] ?? []));
        try {
            $response = call_user_func(['VES_Apify_Client', 'fetch_run'], $run_id, 0);
        } catch (Throwable $e) {
            return new WP_Error('tiktok_provider_exception', 'TikTok provider refresh failed.', ['status' => 500, 'retryable' => true]);
        }
        if (is_wp_error($response)) { return $this->map_core_error($response); }
        return $this->normalize_refresh_response($response, $run_id, $limit, $context);
    }

    private function guard_available() {
        $diag = self::diagnostics();
        if (empty($diag['ves_apify_client_available'])) {
            return new WP_Error('core_apify_client_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        if (empty($diag['complete'])) {
            return new WP_Error('core_apify_client_incomplete', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false, 'missing_methods' => $diag['missing_methods']]);
        }
        return true;
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

    private function normalize_start_response($response, array $context, array $actor_input) {
        $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
        $data = $this->body_data($body);
        $items = $this->items_from_response($body);
        $run_id = $this->extract_run_id($body);
        $status = strtoupper((string) ($data['status'] ?? $body['status'] ?? ''));
        if (!empty($items)) {
            return $this->completed_result($run_id, $items, $context, $actor_input);
        }
        if ($run_id === '') {
            return new WP_Error('tiktok_provider_invalid_run_response', 'TikTok provider returned an invalid run response.', ['status' => 502, 'retryable' => false]);
        }
        // Apify READY is not a finished run. It means the run has been created/queued.
        // Fetching the dataset at READY returns zero rows and prematurely closes live jobs.
        if ($this->apify_run_succeeded($status)) {
            $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? $body['defaultDatasetId'] ?? ''));
            $items = $this->fetch_items_for_run($run_id, $this->limit_from_actor_input($actor_input), $dataset_id);
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($run_id, $items, $context, (array) ($context['actor_input'] ?? []));
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('tiktok_provider_failed', 'TikTok provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return [
            'ok' => true,
            'status' => $this->non_terminal_status_for_response($status, 'queued'),
            'provider_run_id' => $run_id,
            'items' => [],
            'provider_mode_used' => 'tiktok_discovery',
            'request_attempted' => true,
            'outcome_reason' => 'run_queued',
            'discovery_actor_id' => sanitize_text_field((string) ($context['discovery_actor_id'] ?? $context['actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id())),
            'enrichment_actor_id' => FIDTF_Settings::tiktok_enrichment_actor_id(),
            'discovery_actor_input' => $actor_input,
            'discovery_run_id' => $run_id,
            'enrichment_attempted' => false,
            'enrichment_run_ids' => [],
            'enrichment_comments_count' => 0,
        ];
    }

    private function normalize_refresh_response($response, string $run_id, int $limit, array $context = []) {
        $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
        $data = $this->body_data($body);
        $status = strtoupper((string) ($data['status'] ?? $body['status'] ?? ''));
        if ($this->apify_run_succeeded($status)) {
            $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? $body['defaultDatasetId'] ?? ''));
            $items = $this->fetch_items_for_run($run_id, $limit, $dataset_id);
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($run_id, $items, $context, (array) ($context['actor_input'] ?? []));
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('tiktok_provider_failed', 'TikTok provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return [
            'ok' => true,
            'status' => $this->non_terminal_status_for_response($status, 'running'),
            'provider_run_id' => $run_id,
            'items' => [],
            'provider_mode_used' => 'tiktok_discovery',
            'request_attempted' => true,
            'outcome_reason' => 'run_running',
            'discovery_actor_id' => sanitize_text_field((string) ($context['discovery_actor_id'] ?? $context['actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id())),
            'enrichment_actor_id' => FIDTF_Settings::tiktok_enrichment_actor_id(),
            'discovery_actor_input' => (array) ($context['actor_input'] ?? []),
            'discovery_run_id' => $run_id,
            'enrichment_attempted' => false,
            'enrichment_run_ids' => [],
            'enrichment_comments_count' => 0,
        ];
    }

    private function fetch_items_for_run(string $run_id, int $limit, string $dataset_id = '') {
        $limit = max(1, min(300, $limit));
        if ($dataset_id !== '') {
            $this->last_provider_dataset_total_rows = $this->fetch_dataset_total_count($dataset_id);
        }
        try {
            $items = call_user_func(['VES_Apify_Client', 'fetch_items'], $run_id, $limit);
        } catch (Throwable $e) {
            return new WP_Error('tiktok_dataset_fetch_failed', 'TikTok dataset fetch failed.', ['status' => 500, 'retryable' => true]);
        }
        if (is_wp_error($items)) { return $this->map_core_error($items); }
        $sanitized = $this->sanitize_items(is_array($items) ? $items : []);
        // v0.3.21: Some Apify/Core client combinations can resolve the run as
        // SUCCEEDED while /actor-runs/{runId}/dataset/items is still empty or
        // stale. The run metadata already exposes defaultDatasetId, so fall back
        // to the canonical dataset endpoint before declaring zero evidence.
        if (empty($sanitized) && $dataset_id !== '') {
            $fallback = $this->fetch_items_for_dataset($dataset_id, $limit);
            if (is_wp_error($fallback)) { return $fallback; }
            if (!empty($fallback)) { return $fallback; }
        }
        return $sanitized;
    }

    private function fetch_items_for_dataset(string $dataset_id, int $limit) {
        if (!method_exists('VES_Apify_Client', 'request')) {
            return [];
        }
        $dataset_id = sanitize_text_field($dataset_id);
        if ($dataset_id === '') { return []; }
        $limit = max(1, min(300, $limit));
        $base = self::API_BASE . '/datasets/' . rawurlencode($dataset_id) . '/items';
        $args = [
            'format' => 'json',
            'clean' => 'true',
            'limit' => $limit,
        ];
        $url = function_exists('add_query_arg') ? add_query_arg($args, $base) : $base . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
        try {
            $response = call_user_func(['VES_Apify_Client', 'request'], 'GET', $url);
        } catch (Throwable $e) {
            return new WP_Error('tiktok_dataset_fetch_failed', 'TikTok dataset fallback fetch failed.', ['status' => 500, 'retryable' => true]);
        }
        if (is_wp_error($response)) { return $this->map_core_error($response); }
        $body = is_array($response) ? ($response['body'] ?? $response) : [];
        $items = $this->sanitize_items(is_array($body) ? $body : []);
        if ($this->last_provider_dataset_total_rows < $this->last_provider_dataset_rows) {
            $this->last_provider_dataset_total_rows = $this->last_provider_dataset_rows;
        }
        return $items;
    }

    private function fetch_dataset_total_count(string $dataset_id): int {
        if (!method_exists('VES_Apify_Client', 'request')) { return 0; }
        $dataset_id = sanitize_text_field($dataset_id);
        if ($dataset_id === '') { return 0; }
        $url = self::API_BASE . '/datasets/' . rawurlencode($dataset_id);
        try {
            $response = call_user_func(['VES_Apify_Client', 'request'], 'GET', $url);
        } catch (Throwable $e) {
            return 0;
        }
        if (is_wp_error($response)) { return 0; }
        $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
        $data = is_array($body['data'] ?? null) ? (array) $body['data'] : $body;
        return max(0, (int) ($data['itemCount'] ?? $data['cleanItemCount'] ?? 0));
    }

    private function map_core_error($error) {
        if (!is_wp_error($error)) { return $error; }
        $code = sanitize_key((string) $error->get_error_code());
        $data = (array) $error->get_error_data();
        if (in_array($code, ['apify_concurrency_limit', 'apify_concurrency_lock_busy', 'apify_transport', 'apify_rate_limited', 'apify_upstream_unavailable'], true)) {
            $mapped = $code === 'apify_rate_limited' ? 'provider_rate_limited' : 'provider_busy';
            $retry_after = (int) ($data['retry_after'] ?? ($mapped === 'provider_rate_limited' ? 120 : 75));
            return new WP_Error($mapped, 'TikTok provider is temporarily unavailable.', ['status' => (int) ($data['status'] ?? 503), 'retryable' => true, 'retry_after' => $retry_after]);
        }
        if (in_array($code, ['missing_backend_token', 'apify_actor_rental_required'], true)) {
            return new WP_Error('tiktok_provider_unavailable', 'TikTok provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        // v0.3.17: handle actor-level errors that previously fell through to the
        // generic message, losing diagnostic context.
        if ($code === 'apify_invalid_input') {
            return new WP_Error('tiktok_provider_invalid_input', 'TikTok actor rejected the run input (HTTP 400). Check the actor slug and input schema in admin settings.', ['status' => 400, 'retryable' => false, 'provider_state' => 'DISPATCH_INVALID_INPUT']);
        }
        if ($code === 'apify_memory_limit') {
            return new WP_Error('tiktok_provider_memory_limit', 'TikTok actor exceeded Apify memory limit. The run will be retried.', ['status' => 503, 'retryable' => true, 'retry_after' => 90, 'provider_state' => 'QUEUED_RETRY_MEMORY_LIMIT']);
        }
        return new WP_Error($code !== '' ? $code : 'tiktok_provider_failed', 'TikTok provider returned an error.', ['status' => (int) ($data['status'] ?? 502), 'retryable' => !empty($data['retryable'])]);
    }

    private function extract_run_id(array $body): string {
        $data = $this->body_data($body);
        foreach ([$data, $body] as $src) {
            foreach (['id', 'runId', 'provider_run_id'] as $key) {
                if (!empty($src[$key])) { return sanitize_text_field((string) $src[$key]); }
            }
        }
        return '';
    }

    private function body_data(array $body): array {
        return is_array($body['data'] ?? null) ? (array) $body['data'] : $body;
    }

    private function items_from_response(array $body): array {
        foreach (['items', 'results', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && $this->is_list_array($body[$key])) {
                return $this->sanitize_items($body[$key]);
            }
        }
        return [];
    }

    private function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    private function sanitize_items(array $items): array {
        $this->last_provider_dataset_rows = FIDTF_Provider_TikTok::provider_dataset_row_count($items);
        $out = FIDTF_Provider_TikTok::flatten_scraptik_dataset_items($items);
        $this->last_flattened_raw_items = count($out);
        return $out;
    }

    private function completed_result(string $provider_run_id, array $items, array $context = [], array $actor_input = []): array {
        $enrichment = $this->maybe_enrich_items($items, $context);
        $items = (array) ($enrichment['items'] ?? $items);
        $count = count($items);
        $status = $count > 0 ? 'completed' : 'completed_no_relevant_evidence';
        if ($count > 0) {
            $message = 'TikTok discovery completed.';
            $outcome_reason = 'evidence_returned';
        } elseif ($this->last_provider_dataset_rows > 0 || $this->last_provider_dataset_total_rows > 0) {
            $message = 'TikTok discovery returned rows but no usable posts inside.';
            $outcome_reason = 'flattened_empty';
        } else {
            $message = 'Discovery returned zero posts';
            $outcome_reason = 'zero_dataset_items';
        }
        if (!empty($enrichment['attempted'])) {
            $message .= ' TikTok enrichment completed.';
        } elseif ($count > 0 && empty($enrichment['selected_aweme_ids'])) {
            $message .= ' Enrichment skipped: no selected post aweme_id';
        }
        $discovery_actor_id = sanitize_text_field((string) ($context['discovery_actor_id'] ?? $context['actor_id'] ?? FIDTF_Settings::tiktok_discovery_actor_id()));
        $enrichment_actor_id = sanitize_text_field((string) ($context['enrichment_actor_id'] ?? FIDTF_Settings::tiktok_enrichment_actor_id()));
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
            'discovery_actor_id' => $discovery_actor_id,
            'enrichment_actor_id' => $enrichment_actor_id,
            'discovery_actor_input' => $actor_input,
            'discovery_run_id' => $provider_run_id,
            'discovery_dataset_rows' => $this->last_provider_dataset_rows,
            'discovery_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'discovery_flattened_items' => $this->last_flattened_raw_items,
            'enrichment_attempted' => !empty($enrichment['attempted']),
            'enrichment_run_ids' => (array) ($enrichment['run_ids'] ?? []),
            'enrichment_comments_count' => (int) ($enrichment['comments_count'] ?? 0),
            'enrichment_provider_mode' => 'tiktok_enrichment',
        ];
    }

    private function limit_from_actor_input(array $actor_input): int {
        foreach (['maxItems', 'resultsPerPage', 'searchPosts_count'] as $key) {
            if (isset($actor_input[$key])) {
                return max(1, min(300, (int) $actor_input[$key]));
            }
        }
        return FIDTF_Settings::tiktok_default_limit();
    }

    private function maybe_enrich_items(array $items, array $context): array {
        $selected_ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $aweme_id = FIDTF_Provider_TikTok::extract_aweme_id($item);
            if ($aweme_id !== '') { $selected_ids[] = $aweme_id; }
            if (count($selected_ids) >= 3) { break; }
        }
        $selected_ids = array_values(array_unique($selected_ids));
        if (empty($selected_ids)) {
            return ['items' => $items, 'attempted' => false, 'selected_aweme_ids' => [], 'run_ids' => [], 'comments_count' => 0];
        }

        $enrichment_actor_id = FIDTF_Settings::tiktok_enrichment_actor_id();
        $run_ids = [];
        $comments_count = 0;
        foreach ($selected_ids as $aweme_id) {
            $input = FIDTF_Provider_TikTok::build_enrichment_actor_input($aweme_id, 30, 0);
            $url = $this->run_url($enrichment_actor_id);
            try {
                $response = call_user_func(['VES_Apify_Client', 'request'], 'POST', $url, wp_json_encode($input));
            } catch (Throwable $e) {
                continue;
            }
            if (is_wp_error($response) || !is_array($response)) { continue; }
            $body = (array) ($response['body'] ?? $response);
            $run_id = $this->extract_run_id($body);
            if ($run_id !== '') { $run_ids[] = $run_id; }
            $rows = $this->raw_items_from_response($body);
            if (empty($rows)) { continue; }
            foreach ($items as $idx => $item) {
                if (!is_array($item) || FIDTF_Provider_TikTok::extract_aweme_id($item) !== $aweme_id) { continue; }
                $before = isset($item['comments']) && is_array($item['comments']) ? count($item['comments']) : 0;
                $items[$idx] = FIDTF_Provider_TikTok::merge_enrichment_payload($item, $rows);
                $after = isset($items[$idx]['comments']) && is_array($items[$idx]['comments']) ? count($items[$idx]['comments']) : $before;
                $comments_count += max(0, $after - $before);
                break;
            }
        }
        return ['items' => $items, 'attempted' => true, 'selected_aweme_ids' => $selected_ids, 'run_ids' => array_values(array_unique($run_ids)), 'comments_count' => $comments_count];
    }

    private function raw_items_from_response(array $body): array {
        foreach (['items', 'results', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && $this->is_list_array($body[$key])) {
                return $body[$key];
            }
        }
        $data = $this->body_data($body);
        foreach (['items', 'results', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && $this->is_list_array($data[$key])) {
                return $data[$key];
            }
        }
        return [];
    }

    private function acquire_core_slot(string $actor_id, array $payload) {
        if (!method_exists('VES_Apify_Client', 'acquire_dispatch_slot')) { return ''; }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $context = [
            'source' => 'deep_trend_finder_tiktok',
            'dispatch_plan_hash' => md5(wp_json_encode($payload)),
        ];
        return call_user_func(['VES_Apify_Client', 'acquire_dispatch_slot'], $actor_id, $user_id, $context);
    }

    private function release_core_slot($slot_id): void {
        if (!$slot_id || is_wp_error($slot_id) || !method_exists('VES_Apify_Client', 'release_dispatch_slot')) { return; }
        call_user_func(['VES_Apify_Client', 'release_dispatch_slot'], $slot_id);
    }


    private function run_url(string $actor_id): string {
        if (function_exists('ves_prepare_run_options') && function_exists('ves_make_run_url')) {
            $options = ves_prepare_run_options();
            // v0.3.10: mirror Core social-media scraper exactly. Core's
            // VES_Run_Execution_Service::start_source_apify() does:
            //   $options = ves_prepare_run_options();
            //   $options['waitForFinish'] = 0;
            // The async pattern is what Core polls afterwards via fetch_run().
            $options['waitForFinish'] = 0;
            return ves_make_run_url($actor_id, $options);
        }
        $query = [
            'build' => 'latest',
            'waitForFinish' => 0,
            'timeout' => 0,
        ];
        if (function_exists('ves_hard_max_charge_usd')) {
            $query['maxTotalChargeUsd'] = ves_hard_max_charge_usd();
        }
        $base = self::API_BASE . '/acts/' . $this->actor_path($actor_id) . '/runs';
        if (function_exists('add_query_arg')) {
            return add_query_arg($query, $base);
        }
        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function actor_path(string $actor_id): string {
        return rawurlencode(str_replace('/', '~', trim($actor_id)));
    }
}
