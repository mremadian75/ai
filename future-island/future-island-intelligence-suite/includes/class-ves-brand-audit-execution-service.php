<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit_Execution_Service {
    const SOURCE_JOB_HOOK = 'ves_as_brand_audit_source_job';
    const COLLECT_JOB_HOOK = 'ves_as_brand_audit_collect_job';
    const FINALIZE_JOB_HOOK = 'ves_as_brand_audit_finalize_job';

    private static function ttl() {
        return 24 * HOUR_IN_SECONDS;
    }

    private static function terminal_statuses() {
        return ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'CONFIG_ERROR', 'DISPATCH_ERROR', 'PARTIAL', 'SKIPPED_INVALID_INPUT'];
    }

    private static function now_ts() {
        return time();
    }

    private static function now_mysql() {
        return gmdate('c', self::now_ts());
    }

    private static function iso_after($seconds) {
        return gmdate('c', self::now_ts() + max(0, (int) $seconds));
    }

    private static function ts_value($value) {
        if (is_numeric($value)) { return (int) $value; }
        $value = trim((string) $value);
        if ($value === '') { return 0; }
        // WordPress current_time('mysql') returns site-local time without timezone.
        // Parse that format with the WordPress timezone, not the PHP/server timezone.
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $value)) {
            try {
                $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
                if ($dt instanceof DateTimeImmutable) { return (int) $dt->getTimestamp(); }
            } catch (Throwable $e) {}
        }
        $ts = strtotime($value);
        return ($ts !== false) ? (int) $ts : 0;
    }

    private static function source_ready_for_collect($source) {
        $source = is_array($source) ? $source : [];
        $next = self::ts_value($source['next_collect_at'] ?? '');
        return $next <= 0 || $next <= self::now_ts();
    }

    private static function next_collect_attempt($source, $fallback = 1) {
        $source = is_array($source) ? $source : [];
        $existing = max(0, (int) ($source['collect_attempt'] ?? 0));
        return max((int) $fallback, $existing + 1);
    }

    private static function source_collect_delay($source, $attempt, $default = 25) {
        $attempt = max(1, (int) $attempt);
        $source_type = sanitize_key((string) ($source['source_type'] ?? ''));
        $base = $source_type === 'google_intel' ? 20 : $default;
        return min(75, max($base, $base + (($attempt - 1) * 10)));
    }

    private static function source_timeout_seconds($source) {
        $source_type = sanitize_key((string) ($source['source_type'] ?? ''));
        if ($source_type === 'google_intel') { return 240; }
        if ($source_type === 'social_apify') { return 300; }
        return 120;
    }

    private static function source_runtime_seconds($source) {
        $started = self::ts_value($source['started_at'] ?? ($source['dispatched_at'] ?? ''));
        return $started > 0 ? max(0, self::now_ts() - $started) : 0;
    }

    private static function max_runtime_seconds($bundle) {
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $depth = sanitize_key((string) ($request['depth'] ?? 'standard'));
        if ($depth === 'light') { return 12 * MINUTE_IN_SECONDS; }
        if ($depth === 'deep') { return 35 * MINUTE_IN_SECONDS; }
        return 20 * MINUTE_IN_SECONDS;
    }

    private static function mark_source_partial_due_timeout($source, $message = '') {
        $source = is_array($source) ? $source : [];
        $source['status'] = 'PARTIAL';
        $source['analytical_usability_status'] = 'limited';
        $source['dispatch_status'] = !empty($source['dispatch_status']) ? $source['dispatch_status'] : 'timed_out_locally';
        $source['notes'] = $message !== '' ? $message : 'Source exceeded the local Brand Audit polling window and was marked partial so the report can finish.';
        $source['completed_at'] = self::now_mysql();
        $source['next_collect_at'] = '';
        return $source;
    }

    private static function force_finalize_if_stale($bundle) {
        if (!is_array($bundle) || !empty($bundle['final'])) { return $bundle; }
        $created_ts = self::ts_value($bundle['created_at'] ?? '');
        if ($created_ts <= 0 || (self::now_ts() - $created_ts) < self::max_runtime_seconds($bundle)) { return $bundle; }
        $changed = false;
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            if (!is_array($source)) { continue; }
            $status = strtoupper((string) ($source['status'] ?? ''));
            if (!in_array($status, self::terminal_statuses(), true)) {
                $bundle['sources'][$source_key] = self::mark_source_partial_due_timeout($source, 'Brand Audit reached the maximum runtime window. This source was left partial and the report was finalized with available evidence.');
                $changed = true;
            }
        }
        if ($changed) {
            $bundle['status'] = 'partial';
            $bundle['updated_at'] = self::now_mysql();
            self::save_bundle((string) ($bundle['bundle_id'] ?? ''), $bundle);
            if (class_exists('VES_Brand_Audit_Run_Store')) {
                VES_Brand_Audit_Run_Store::update_sources((string) ($bundle['bundle_id'] ?? ''), $bundle['sources'], 'partial');
            }
        }
        return $bundle;
    }

    private static function progress_summary($sources) {
        $sources = (array) $sources;
        $done = 0;
        $total = count($sources);
        foreach ($sources as $source) {
            if (in_array(strtoupper((string) ($source['status'] ?? '')), self::terminal_statuses(), true)) {
                $done++;
            }
        }
        return ['done' => $done, 'total' => $total];
    }


    private static function source_public_stage($source) {
        $source = is_array($source) ? $source : [];
        $status = strtoupper((string) ($source['status'] ?? ''));
        $dispatch = sanitize_key((string) ($source['dispatch_status'] ?? ''));
        if ($status === 'QUEUED' || $status === '') { return 'queued'; }
        if (in_array($status, ['WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true)) { return 'waiting_retry'; }
        if ($status === 'RUNNING') {
            if (empty($source['run_id'])) { return $dispatch === 'started' ? 'dispatching' : 'queued'; }
            return 'collecting';
        }
        if ($status === 'SUCCEEDED') { return 'completed'; }
        if ($status === 'PARTIAL') { return 'partial'; }
        if (in_array($status, ['TIMED-OUT', 'TIMEOUT'], true)) { return 'timed_out'; }
        if ($status === 'CONFIG_ERROR') { return 'config_error'; }
        if ($status === 'DISPATCH_ERROR') { return 'dispatch_error'; }
        if ($status === 'ABORTED') { return 'aborted'; }
        if ($status === 'FAILED') { return 'failed'; }
        return 'unknown';
    }

    private static function source_public_stage_label($stage) {
        $stage = sanitize_key((string) $stage);
        $labels = [
            'queued' => 'Queued',
            'dispatching' => 'Dispatching',
            'waiting_retry' => 'Waiting retry',
            'collecting' => 'Collecting',
            'completed' => 'Completed',
            'partial' => 'Partial',
            'timed_out' => 'Timed out',
            'config_error' => 'Config issue',
            'dispatch_error' => 'Dispatch issue',
            'failed' => 'Failed',
            'aborted' => 'Aborted',
            'unknown' => 'Pending review',
        ];
        return $labels[$stage] ?? 'Unknown';
    }

    private static function source_public_message($source) {
        $source = is_array($source) ? $source : [];
        $stage = self::source_public_stage($source);
        $notes = sanitize_textarea_field((string) ($source['notes'] ?? ''));
        $provider = strtoupper((string) ($source['provider_status'] ?? ''));
        if ($stage === 'waiting_retry') {
            return 'Provider capacity reached. This source is queued for a safe retry.';
        }
        if ($notes !== '') {
            $lower = strtolower($notes);
            if (strpos($lower, 'run id') !== false || strpos($lower, 'apify client') !== false) { return 'Source unavailable. It will not be used as evidence.'; }
            if (strpos($lower, 'input.keyword') !== false || strpos($lower, 'skipped_invalid_input') !== false || strpos($lower, 'invalid-input') !== false || strpos($lower, 'required actor input') !== false) { return 'Source skipped due configuration.'; }
            if (strpos($lower, 'heavy-source active-run limit') !== false || strpos($lower, 'capacity') !== false) { return 'Source pending retry.'; }
            if (strpos($lower, 'dispatch_error') !== false || strpos($lower, 'dispatch failed') !== false) { return 'Source unavailable.'; }
            if (strpos($lower, 'post does not exist') !== false || strpos($lower, 'not_found') !== false) {
                return 'The provider could not find this post/profile. Verify the URL or handle and retry.';
            }
            if (strpos($lower, 'missing_apify_token') !== false || strpos($lower, 'api key') !== false || strpos($lower, 'falta configurar') !== false) {
                return 'Provider API key is missing or invalid. Check admin settings.';
            }
            if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false || strpos($lower, 'cURL error 28') !== false) {
                return 'The source took too long. It was preserved as partial so the Brand Audit can continue.';
            }
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                return strlen($notes) > 240 ? substr($notes, 0, 237) . '...' : $notes;
            }
            return 'Source has a technical diagnostic. It is shown as limited evidence.';
        }
        if ($stage === 'queued') { return 'Waiting to be dispatched.'; }
        if ($stage === 'dispatching') { return 'Starting provider run.'; }
        if ($stage === 'collecting') { return $provider ? 'Provider run is ' . $provider . '; waiting for results.' : 'Collecting provider results.'; }
        if ($stage === 'completed') { return 'Source completed with usable evidence.'; }
        if ($stage === 'partial') { return 'Source returned limited evidence and will be treated as a blind spot.'; }
        if ($stage === 'config_error') { return 'Source cannot run until configuration is fixed.'; }
        if ($stage === 'dispatch_error') { return 'Provider run could not be started.'; }
        if ($stage === 'failed') { return 'Source failed and will be shown as a limitation.'; }
        return 'Source status available.';
    }

    private static function source_ui_row($source) {
        $source = is_array($source) ? $source : [];
        $stage = self::source_public_stage($source);
        $started_ts = self::ts_value($source['started_at'] ?? ($source['dispatched_at'] ?? ''));
        return [
            'label' => sanitize_text_field((string) ($source['label'] ?? 'Source')),
            'source_type' => sanitize_key((string) ($source['source_type'] ?? '')),
            'platform' => sanitize_key((string) ($source['platform'] ?? '')),
            'status' => strtoupper((string) ($source['status'] ?? '')) === 'UNKNOWN' ? 'PENDING_REVIEW' : sanitize_text_field((string) ($source['status'] ?? '')),
            'stage' => $stage,
            'stage_label' => self::source_public_stage_label($stage),
            'dispatch_status' => sanitize_text_field((string) ($source['dispatch_status'] ?? '')),
            'provider_status' => sanitize_text_field((string) ($source['provider_status'] ?? '')),
            'message' => self::source_public_message($source),
            'notes' => (function_exists('current_user_can') && current_user_can('manage_options')) ? sanitize_textarea_field((string) ($source['notes'] ?? '')) : '',
            'item_count' => (int) ($source['item_count'] ?? (is_array($source['result'] ?? null) ? ($source['result']['all_items_count'] ?? 0) : 0)),
            'run_id' => sanitize_text_field((string) ($source['run_id'] ?? '')),
            'elapsed_seconds' => $started_ts > 0 ? max(0, self::now_ts() - $started_ts) : 0,
            'next_collect_at' => sanitize_text_field((string) ($source['next_collect_at'] ?? '')),
        ];
    }

    public static function sources_for_ui($sources) {
        return array_map(static function ($source) { return self::source_ui_row($source); }, array_values((array) $sources));
    }

    private static function bundle_phase($bundle) {
        $bundle = is_array($bundle) ? $bundle : [];
        if (!empty($bundle['final']) && is_array($bundle['final'])) {
            $final_status = sanitize_key((string) ($bundle['final']['run_status'] ?? $bundle['status'] ?? 'completed'));
            if (in_array($final_status, ['partial', 'failed', 'aborted'], true)) { return $final_status; }
            return 'completed';
        }
        $status = sanitize_key((string) ($bundle['status'] ?? 'queued'));
        if (in_array($status, ['completed', 'partial', 'failed', 'aborted'], true)) { return $status; }
        if ($status === 'analyzing') { return 'analyzing'; }
        $sources = (array) ($bundle['sources'] ?? []);
        if (!$sources) { return 'queued'; }
        $all_terminal = true;
        $has_started = false;
        foreach ($sources as $source) {
            $source = is_array($source) ? $source : [];
            $source_status = strtoupper((string) ($source['status'] ?? ''));
            if ($source_status !== 'QUEUED' && $source_status !== '') { $has_started = true; }
            if (!in_array($source_status, self::terminal_statuses(), true)) { $all_terminal = false; }
        }
        if ($all_terminal) { return 'analyzing'; }
        return $has_started ? 'collecting' : 'queued';
    }

    private static function bundle_phase_label($phase) {
        $phase = sanitize_key((string) $phase);
        $labels = [
            'queued' => 'Queued',
            'running' => 'Running',
            'collecting' => 'Collecting evidence',
            'analyzing' => 'Analyzing evidence',
            'completed' => 'Completed',
            'partial' => 'Completed with limitations',
            'failed' => 'Failed',
            'aborted' => 'Aborted',
        ];
        return $labels[$phase] ?? 'Running';
    }

    public static function summarize_bundle_for_ui($bundle) {
        if (!is_array($bundle)) { return null; }
        $sources = (array) ($bundle['sources'] ?? []);
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $progress = self::progress_summary($sources);
        $created_ts = self::ts_value($bundle['created_at'] ?? '');
        $updated_ts = self::ts_value($bundle['updated_at'] ?? '');
        return [
            'bundle_id' => sanitize_text_field((string) ($bundle['bundle_id'] ?? '')),
            'run_id' => (int) ($bundle['run_id'] ?? 0),
            'status' => sanitize_key((string) ($bundle['status'] ?? 'running')),
            'brand_name' => sanitize_text_field((string) ($request['brand_name'] ?? 'Brand Audit')),
            'depth' => sanitize_key((string) ($request['depth'] ?? 'standard')),
            'output_type' => sanitize_key((string) ($request['output_type'] ?? 'report_deck')),
            'created_at' => sanitize_text_field((string) ($bundle['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($bundle['updated_at'] ?? '')),
            'elapsed_seconds' => $created_ts > 0 ? max(0, self::now_ts() - $created_ts) : 0,
            'idle_seconds' => $updated_ts > 0 ? max(0, self::now_ts() - $updated_ts) : 0,
            'progress' => $progress,
            'run_phase' => self::bundle_phase($bundle),
            'phase_label' => self::bundle_phase_label(self::bundle_phase($bundle)),
            'sources' => self::sources_for_ui($sources),
            'final_available' => !empty($bundle['final']) && is_array($bundle['final']),
        ];
    }

    public static function get_active_runs_for_current_scope($limit = 5, $kick = true, $include_finished = false) {
        if (!class_exists('VES_Brand_Audit_Run_Store')) { return []; }
        $statuses = $include_finished ? ['queued', 'running', 'partial', 'completed', 'failed', 'aborted'] : ['queued', 'running', 'partial'];
        $rows = VES_Brand_Audit_Run_Store::get_recent_runs_for_current_scope($statuses, $limit, 72);
        $out = [];
        foreach ($rows as $row) {
            $bundle_id = sanitize_text_field((string) ($row['bundle_id'] ?? ''));
            if ($bundle_id === '') { continue; }
            $row_status = sanitize_key((string) ($row['run_status'] ?? ''));
            if ($kick && in_array($row_status, ['queued', 'running', 'partial'], true)) {
                self::tick_brand_audit_run($bundle_id);
            }
            $bundle = self::get_bundle($bundle_id);
            if (!$bundle || !self::bundle_access_allowed($bundle)) { continue; }
            if (!$include_finished && !empty($bundle['final'])) { continue; }
            $summary = self::summarize_bundle_for_ui($bundle);
            if ($summary) { $out[] = $summary; }
        }
        return $out;
    }
    public static function make_bundle_id() {
        try {
            return 'vesba-' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'vesba-' . strtolower(wp_generate_password(24, false, false));
        }
    }

    public static function bundle_key($bundle_id) {
        return 'ves_brand_audit_bundle_' . md5((string) $bundle_id);
    }

    public static function get_bundle($bundle_id) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '') { return null; }
        $bundle = get_transient(self::bundle_key($bundle_id));
        if (is_array($bundle)) { return $bundle; }
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            $row = VES_Brand_Audit_Run_Store::get_run_by_bundle_id($bundle_id);
            if (is_array($row)) {
                $bundle = [
                    'bundle_id' => $row['bundle_id'],
                    'run_id' => (int) ($row['id'] ?? 0),
                    'user_id' => $row['user_id'] ?? null,
                    'session_key' => (string) ($row['session_key'] ?? ''),
                    'request' => $row['request'] ?? [],
                    'query_plan' => $row['query_plan'] ?? [],
                    'sources' => $row['sources'] ?? [],
                    'evidence_pack' => $row['evidence_pack'] ?? [],
                    'final' => $row['final_payload'] ?? null,
                    'status' => sanitize_key((string) ($row['run_status'] ?? 'queued')),
                    'usage_key' => self::usage_key($bundle_id),
                    'created_at' => $row['created_at'] ?? self::now_mysql(),
                    'updated_at' => $row['updated_at'] ?? self::now_mysql(),
                ];
                self::save_bundle($bundle_id, $bundle);
                return $bundle;
            }
        }
        return null;
    }

    public static function save_bundle($bundle_id, $bundle) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '' || !is_array($bundle)) { return; }
        set_transient(self::bundle_key($bundle_id), $bundle, self::ttl());
    }

    private static function current_scope() {
        if (is_user_logged_in()) {
            $user_id = (int) get_current_user_id();
            return ['user_id' => $user_id, 'session_key' => 'user:' . $user_id];
        }
        $session_key = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : 'guest';
        return ['user_id' => 0, 'session_key' => sanitize_key((string) $session_key)];
    }

    public static function bundle_access_allowed($bundle) {
        if (!is_array($bundle)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        $bundle_user_id = (int) ($bundle['user_id'] ?? 0);
        if ($bundle_user_id > 0) {
            return is_user_logged_in() && get_current_user_id() === $bundle_user_id;
        }
        $session_key = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : '';
        return $session_key !== '' && hash_equals((string) ($bundle['session_key'] ?? ''), (string) $session_key);
    }

    private static function event_type_for_request($request) {
        $depth = sanitize_key((string) ($request['depth'] ?? 'standard'));
        if ($depth === 'light') { return 'brand_audit_light'; }
        if ($depth === 'deep') { return 'brand_audit_deep'; }
        return 'brand_audit_standard';
    }

    private static function usage_key($bundle_id) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        return $bundle_id !== '' ? 'brand_audit_run:' . $bundle_id : '';
    }

    private static function reserve_usage($bundle_id, $request) {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing')) { return true; }
        $usage_key = self::usage_key($bundle_id);
        $event_type = self::event_type_for_request($request);
        return VES_Usage_Billing::reserve_usage(get_current_user_id(), $event_type, $usage_key, 'Reserva Brand Audit', [
            'usage_key' => $usage_key,
            'target_type' => 'brand_audit_run',
            'target_id' => $bundle_id,
            'brand_name' => sanitize_text_field((string) ($request['brand_name'] ?? '')),
            'country' => sanitize_text_field((string) ($request['country'] ?? '')),
            'depth' => sanitize_key((string) ($request['depth'] ?? 'standard')),
        ]);
    }

    private static function finalize_usage($bundle_id, $run_status, $message = '') {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing')) { return; }
        $usage_key = self::usage_key($bundle_id);
        if ($usage_key === '') { return; }
        // v0.9.24.7: 'fallback_completed' means Apify ran; charge credits.
        if (in_array((string) $run_status, ['completed', 'partial', 'fallback_completed'], true)) {
            VES_Usage_Billing::post_reserved_usage($usage_key, $message !== '' ? $message : 'Brand Audit confirmado');
        } else {
            VES_Usage_Billing::void_reserved_usage($usage_key, $message !== '' ? $message : 'Brand Audit cancelado o sin evidencia');
        }
    }

    public static function create_brand_audit_run($request, $actor = []) {
        if (!class_exists('VES_Brand_Audit') || !class_exists('VES_Brand_Audit_Query_Planner') || !class_exists('VES_Brand_Audit_Run_Store')) {
            return new WP_Error('brand_audit_missing', 'Brand Audit no esta disponible.');
        }
        $request = VES_Brand_Audit::sanitize_request($request);
        if (empty($request['brand_name'])) {
            return new WP_Error('brand_audit_missing_brand', 'Debes indicar el nombre de la marca.', ['status' => 400]);
        }

        $bundle_id = self::make_bundle_id();
        $planned = VES_Brand_Audit_Query_Planner::build($request);
        $sources = is_array($planned['sources'] ?? null) ? $planned['sources'] : [];
        $request_hash = sanitize_text_field((string) ($planned['request_hash'] ?? ''));
        $scope = self::current_scope();
        $provider_cost = self::validate_provider_cost_preflight($sources);
        if (is_wp_error($provider_cost)) { return $provider_cost; }

        $reserved = self::reserve_usage($bundle_id, $request);
        if (is_wp_error($reserved)) { return $reserved; }

        $run_id = VES_Brand_Audit_Run_Store::create_run($bundle_id, $request, $planned['query_plan'] ?? [], $sources, $request_hash, $scope);
        if (!$run_id) {
            self::finalize_usage($bundle_id, 'failed', 'No se pudo crear el Brand Audit.');
            return new WP_Error('brand_audit_store_failed', 'No se pudo crear el Brand Audit.', ['status' => 500]);
        }

        $bundle = [
            'bundle_id' => $bundle_id,
            'run_id' => (int) $run_id,
            'user_id' => !empty($scope['user_id']) ? (int) $scope['user_id'] : 0,
            'session_key' => (string) ($scope['session_key'] ?? ''),
            'request' => $request,
            'query_plan' => $planned['query_plan'] ?? [],
            'payloads' => $planned['payloads'] ?? [],
            'sources' => $sources,
            'status' => 'queued',
            'usage_key' => self::usage_key($bundle_id),
            'created_at' => self::now_mysql(),
            'updated_at' => self::now_mysql(),
            'trigger' => sanitize_key((string) ($actor['trigger'] ?? 'manual')),
            'source_preview' => is_array($planned['source_preview'] ?? null) ? $planned['source_preview'] : [],
            'provider_cost' => $provider_cost,
        ];
        self::save_bundle($bundle_id, $bundle);
        VES_Brand_Audit_Run_Store::set_status($bundle_id, 'queued');

        if (class_exists('VES_Queue')) {
            $batch_size = class_exists('VES_Config') && method_exists('VES_Config', 'brand_audit_batch_size') ? max(1, (int) VES_Config::brand_audit_batch_size()) : 6;
            $index = 0;
            foreach (array_keys($sources) as $source_key) {
                $phase = self::source_phase(is_array($sources[$source_key] ?? null) ? $sources[$source_key] : []);
                $delay = 1 + (($phase - 1) * 75) + (int) floor($index / $batch_size) * 15;
                $index++;
                VES_Queue::enqueue_unique(self::SOURCE_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'run_id' => (int) $run_id,
                    'source_key' => sanitize_key((string) $source_key),
                    '_ves_job_key' => 'brand_audit_source_' . md5($bundle_id . '|' . $source_key),
                ], 'ves_brand_audit', $delay);
            }
            VES_Queue::enqueue_unique(self::FINALIZE_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'run_id' => (int) $run_id,
                '_ves_job_key' => 'brand_audit_finalize_' . md5($bundle_id),
            ], 'ves_brand_audit', 8);
        }

        return ['bundle_id' => $bundle_id, 'run_id' => (int) $run_id, 'query_plan' => $planned['query_plan'] ?? [], 'sources' => $sources, 'source_preview' => $planned['source_preview'] ?? [], 'provider_cost' => $provider_cost];
    }

    private static function validate_provider_cost_preflight($sources) {
        $sources = is_array($sources) ? $sources : [];
        $estimate = class_exists('VES_Apify_Actor_Registry') ? VES_Apify_Actor_Registry::estimate_sources($sources) : ['provider' => 'apify', 'source_count' => count($sources), 'estimated_usd' => 0.0, 'items' => []];
        $source_count = (int) ($estimate['source_count'] ?? count($sources));
        $max_sources = class_exists('VES_Config') && method_exists('VES_Config', 'apify_max_sources_per_run') ? (int) VES_Config::apify_max_sources_per_run() : 30;
        $max_cost = class_exists('VES_Config') && method_exists('VES_Config', 'max_external_provider_cost_per_run') ? (float) VES_Config::max_external_provider_cost_per_run() : 0.0;
        $estimated = (float) ($estimate['estimated_usd'] ?? 0.0);
        $estimate['max_sources_per_run'] = $max_sources;
        $estimate['max_estimated_cost_per_run_usd'] = $max_cost;
        if ($max_sources > 0 && $source_count > $max_sources) {
            return new WP_Error('ves_provider_preflight_too_many_sources', 'This run exceeds your current credit/provider limit. Reduce source count, reduce max results, or ask your admin to raise the limit.', ['status' => 402, 'provider_cost' => $estimate, 'admin_note' => 'Brand Audit preflight blocked by source count.']);
        }
        if ($max_cost > 0 && $estimated > $max_cost) {
            return new WP_Error('ves_provider_preflight_cost_limit', 'This run exceeds your current credit/provider limit. Reduce source count, reduce max results, or ask your admin to raise the limit.', ['status' => 402, 'provider_cost' => $estimate, 'admin_note' => 'Brand Audit preflight blocked by provider cost estimate.']);
        }
        return $estimate;
    }

    public static function start_queued_sources($bundle_id, $run_id = 0) {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) { return null; }
        $bundle['status'] = 'running';
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            if (!is_array($source)) { continue; }
            $status = strtoupper((string) ($source['status'] ?? 'QUEUED'));
            if (in_array($status, ['QUEUED', 'WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true)) {
                $source['dispatch_status'] = $status === 'QUEUED' ? 'queued' : 'waiting_retry';
                $source['notes'] = $source['notes'] ?? 'Queued until a dispatch slot is available.';
                $bundle['sources'][$source_key] = $source;
            }
        }
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            VES_Brand_Audit_Run_Store::update_sources($bundle_id, $bundle['sources'], 'running');
        }
        return $bundle;
    }

    private static function source_phase($source) {
        return max(1, (int) ((is_array($source) ? ($source['phase'] ?? 1) : 1)));
    }

    private static function source_is_heavy($source) {
        $source = is_array($source) ? $source : [];
        return !empty($source['is_heavy_source']) || sanitize_key((string) ($source['source_type'] ?? '')) === 'social_apify';
    }

    private static function phase_ready_to_dispatch($bundle, $source) {
        $phase = self::source_phase($source);
        if ($phase <= 1) { return true; }
        foreach ((array) ($bundle['sources'] ?? []) as $candidate) {
            if (!is_array($candidate)) { continue; }
            if (self::source_phase($candidate) >= $phase) { continue; }
            $status = strtoupper((string) ($candidate['status'] ?? ''));
            if (!in_array($status, self::terminal_statuses(), true) && $status !== 'SKIPPED_INVALID_INPUT') { return false; }
        }
        return true;
    }

    private static function current_heavy_running_in_phase($bundle, $source) {
        $phase = self::source_phase($source);
        $count = 0;
        foreach ((array) ($bundle['sources'] ?? []) as $candidate) {
            if (!is_array($candidate) || !self::source_is_heavy($candidate) || self::source_phase($candidate) !== $phase) { continue; }
            if (strtoupper((string) ($candidate['status'] ?? '')) === 'RUNNING') { $count++; }
        }
        return $count;
    }

    private static function max_retry_attempts($source) {
        return max(1, (int) ((is_array($source) ? ($source['max_retry_attempts'] ?? 4) : 4)));
    }

    private static function schedule_source_retry($bundle, $source_key, $source, $delay = 90, $message = '') {
        $attempt = max(0, (int) ($source['retry_attempt'] ?? 0)) + 1;
        $source['retry_attempt'] = $attempt;
        $source['max_retry_attempts'] = self::max_retry_attempts($source);
        $source['retry_after_seconds'] = max(30, (int) $delay);
        $source['retry_eta'] = self::iso_after((int) $source['retry_after_seconds']);
        if ($attempt >= $source['max_retry_attempts']) {
            $source['status'] = 'PARTIAL';
            $source['dispatch_status'] = 'retry_budget_exhausted';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = $message !== '' ? $message : 'Retry budget exhausted; source marked as partial blind spot.';
            $source['completed_at'] = self::now_mysql();
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $source['status'] = 'WAITING_RETRY';
        $source['dispatch_status'] = 'waiting_retry';
        $source['analytical_usability_status'] = 'pending';
        $source['notes'] = $message !== '' ? $message : 'Source pending retry.';
        self::update_source($bundle, $source_key, $source);
        self::requeue_source_job((string) ($bundle['bundle_id'] ?? ''), (int) ($bundle['run_id'] ?? 0), $source_key, $delay);
    }

    private static function requeue_source_job($bundle_id, $run_id, $source_key, $delay = 90) {
        if (!class_exists('VES_Queue')) { return; }
        VES_Queue::enqueue_unique(self::SOURCE_JOB_HOOK, [
            'bundle_id' => sanitize_text_field((string) $bundle_id),
            'run_id' => (int) $run_id,
            'source_key' => sanitize_key((string) $source_key),
            '_ves_job_key' => 'brand_audit_source_retry_' . md5((string) $bundle_id . '|' . (string) $source_key . '|' . time()),
        ], 'ves_brand_audit', max(30, (int) $delay));
    }

    private static function update_source($bundle, $source_key, $source, $status = '') {
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        if ($bundle_id === '') { return $bundle; }
        $bundle['sources'][$source_key] = $source;
        $bundle['updated_at'] = self::now_mysql();
        $bundle['status'] = in_array(strtoupper((string) ($source['status'] ?? '')), self::terminal_statuses(), true) ? ($bundle['status'] ?? 'running') : 'running';
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            VES_Brand_Audit_Run_Store::update_sources($bundle_id, $bundle['sources'], $status !== '' ? $status : 'running');
        }
        return $bundle;
    }

    public static function run_source_job($payload) {
        $payload = is_array($payload) ? $payload : [];
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $source_key = sanitize_key((string) ($payload['source_key'] ?? ''));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || $source_key === '' || empty($bundle['sources'][$source_key])) { return; }
        if (!empty($bundle['final'])) { return; }
        $source = is_array($bundle['sources'][$source_key]) ? $bundle['sources'][$source_key] : [];
        $current_status = strtoupper((string) ($source['status'] ?? ''));
        if (in_array($current_status, self::terminal_statuses(), true)) {
            return;
        }
        if (!self::phase_ready_to_dispatch($bundle, $source)) {
            $source['status'] = 'QUEUED';
            $source['dispatch_status'] = 'queued_for_phase';
            $source['notes'] = 'Queued until earlier Brand Audit source phase completes.';
            self::update_source($bundle, $source_key, $source);
            self::requeue_source_job($bundle_id, (int) ($bundle['run_id'] ?? 0), $source_key, 60);
            return;
        }
        if (self::source_is_heavy($source) && self::current_heavy_running_in_phase($bundle, $source) >= (class_exists('VES_Config') ? VES_Config::brand_audit_max_heavy_sources_per_phase() : 2)) {
            self::schedule_source_retry($bundle, $source_key, $source, 75, 'Source pending retry because the phase heavy-source limit is active.');
            return;
        }
        $source_type = sanitize_key((string) ($source['source_type'] ?? 'placeholder'));

        if ($source_type === 'google_intel') {
            if ($current_status === 'RUNNING' && !empty($source['run_id'])) {
                self::collect_source_result_job([
                    'bundle_id' => $bundle_id,
                    'run_id' => (int) ($bundle['run_id'] ?? 0),
                    'source_key' => $source_key,
                    'attempt' => 1,
                ]);
                return;
            }
            self::run_google_intel_source($bundle, $source_key, $source);
            return;
        }

        if ($source_type === 'social_apify') {
            if ($current_status === 'RUNNING' && !empty($source['run_id'])) {
                self::collect_source_result_job([
                    'bundle_id' => $bundle_id,
                    'run_id' => (int) ($bundle['run_id'] ?? 0),
                    'source_key' => $source_key,
                    'attempt' => 1,
                ]);
                return;
            }
            self::run_social_apify_source($bundle, $source_key, $source);
            return;
        }

        $source['status'] = 'SUCCEEDED';
        $source['dispatch_status'] = $source_type === 'internal' ? 'internal' : 'placeholder';
        $source['analytical_usability_status'] = $source_type === 'internal' ? 'usable' : 'limited';
        $source['item_count'] = 1;
        $source['notes'] = $source_type === 'internal'
            ? 'Internal Brand Audit source completed.'
            : 'Sprint 2 placeholder completed. This adapter will be replaced in a later sprint.';
        $source['highlights'] = self::placeholder_highlights($source_key, $bundle['request'] ?? []);
        $source['completed_at'] = self::now_mysql();
        self::update_source($bundle, $source_key, $source);
    }

    private static function run_google_intel_source($bundle, $source_key, $source) {
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        $module_key = sanitize_key((string) ($source['module_key'] ?? ''));
        if (!class_exists('VES_Google_Intel') || !class_exists('VES_Apify_Client') || !class_exists('VES_Config')) {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'missing_dependency';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Google Intelligence dependencies are not available.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        if (!VES_Config::get_token()) {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'missing_apify_token';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Falta configurar la API key de Apify para ejecutar esta fuente real.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $actor_slug = method_exists('VES_Google_Intel', 'actor_slug_for_module') ? VES_Google_Intel::actor_slug_for_module($module_key) : '';
        if (class_exists('VES_Source_Adapter_Registry') && in_array($module_key, ['trends', 'news'], true)) {
            $input = VES_Source_Adapter_Registry::normalize_brand_audit_google_payload($source_key, $module_key, $actor_slug, is_array($source['input'] ?? null) ? $source['input'] : [], is_array($bundle['request'] ?? null) ? $bundle['request'] : []);
            $validated = VES_Source_Adapter_Registry::validate_input($module_key === 'news' ? 'google_news' : 'google_trends', $actor_slug, $input);
            if (is_wp_error($validated)) {
                if (method_exists('VES_Source_Adapter_Registry', 'invalid_input_source')) {
                    $source = VES_Source_Adapter_Registry::invalid_input_source($source_key, $source, $validated);
                } else {
                    $source['status'] = 'SKIPPED_INVALID_INPUT';
                    $source['dispatch_status'] = 'skipped_invalid_input';
                    $source['analytical_usability_status'] = 'limited';
                    $source['notes'] = $validated->get_error_message();
                }
                $source['completed_at'] = self::now_mysql();
                self::update_source($bundle, $source_key, $source);
                return;
            }
        } else {
            $input = VES_Google_Intel::build_input($module_key, is_array($source['input'] ?? null) ? $source['input'] : []);
            if (is_wp_error($input)) {
                $source['status'] = 'CONFIG_ERROR';
                $source['dispatch_status'] = 'invalid_input';
                $source['analytical_usability_status'] = 'limited';
                $source['notes'] = $input->get_error_message();
                self::update_source($bundle, $source_key, $source);
                return;
            }
        }
        $source['actor_input'] = self::redact_array($input);
        $response = ($actor_slug !== '' && method_exists('VES_Google_Intel', 'run_actor_with_slug')) ? VES_Google_Intel::run_actor_with_slug($actor_slug, $input, 0) : VES_Google_Intel::run_actor($module_key, $input, 0);
        if (is_wp_error($response)) {
            if (self::try_google_source_fallback($bundle, $source_key, $source, $response->get_error_message())) {
                return;
            }
            $source['status'] = 'DISPATCH_ERROR';
            $source['dispatch_status'] = 'dispatch_failed';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = $response->get_error_message();
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
        $run_id = sanitize_text_field((string) ($data['id'] ?? ''));
        $status = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));
        $source['run_id'] = $run_id;
        $source['provider_status'] = $status;
        $source['dispatch_status'] = 'dispatched';
        if ($run_id === '') {
            $source['status'] = 'DISPATCH_ERROR';
            $source['notes'] = 'The Google Intelligence actor did not return a run ID.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        if ($status === 'SUCCEEDED') {
            self::apply_google_intel_result($bundle, $source_key, $source, 0, 1);
            return;
        }
        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            $source['status'] = $status;
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = self::provider_message($data, 'Google Intelligence run ended without usable output.');
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $source['status'] = 'RUNNING';
        $source['analytical_usability_status'] = 'pending';
        $source['notes'] = 'Google Intelligence run dispatched and still running.';
        $source['dispatched_at'] = self::now_mysql();
        $source['collect_attempt'] = 0;
        $source['next_collect_at'] = self::iso_after(20);
        self::update_source($bundle, $source_key, $source);
        if (class_exists('VES_Queue')) {
            VES_Queue::enqueue_unique(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'run_id' => (int) ($bundle['run_id'] ?? 0),
                'source_key' => $source_key,
                'attempt' => 1,
                '_ves_job_key' => 'brand_audit_collect_' . md5($bundle_id . '|' . $source_key . '|1'),
            ], 'ves_brand_audit', 20);
        }
    }


    private static function release_social_slot($source) {
        if (!class_exists('VES_Apify_Client') || !is_array($source)) { return; }
        if (!empty($source['apify_slot_id']) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) {
            VES_Apify_Client::release_dispatch_slot((string) $source['apify_slot_id']);
            return;
        }
        if (!empty($source['run_id']) && method_exists('VES_Apify_Client', 'release_dispatch_slot_for_run')) {
            VES_Apify_Client::release_dispatch_slot_for_run((string) $source['run_id']);
        }
    }

    private static function run_social_apify_source($bundle, $source_key, $source) {
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        if (!class_exists('VES_Apify_Client') || !class_exists('VES_Config')) {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'missing_dependency';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Social source dependencies are not available.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        if (!VES_Config::get_token()) {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'missing_apify_token';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Falta configurar la API key de Apify para ejecutar esta fuente social.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $actor_slug = trim((string) ($source['actor_slug'] ?? ''));
        if ($actor_slug === '') {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'missing_actor';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Actor slug no configurado para esta red social.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $input = is_array($source['input'] ?? null) ? $source['input'] : [];
        if (empty($input)) {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'invalid_input';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'No hay input valido para esta fuente social.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $source['actor_input'] = self::redact_array($input);
        $slot = method_exists('VES_Apify_Client', 'acquire_dispatch_slot') ? VES_Apify_Client::acquire_dispatch_slot($actor_slug, (int) ($bundle['user_id'] ?? 0), ['brand_audit' => $bundle_id, 'source_key' => $source_key, 'context' => 'brand_audit_social_dispatch']) : '';
        if (is_wp_error($slot)) {
            self::schedule_source_retry($bundle, $source_key, $source, 90, $slot->get_error_message());
            return;
        }
        $source['apify_slot_id'] = (string) $slot;
        $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : ['waitForFinish' => 0];
        $options['waitForFinish'] = 0;
        $url = function_exists('ves_make_run_url') ? ves_make_run_url($actor_slug, $options) : '';
        if ($url === '') {
            $source['status'] = 'CONFIG_ERROR';
            $source['dispatch_status'] = 'invalid_actor_url';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'No se pudo construir la URL del actor social.';
            if (!empty($slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot((string) $slot); }
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $response = VES_Apify_Client::request('POST', $url, wp_json_encode($input));
        if (is_wp_error($response)) {
            if (!empty($slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot((string) $slot); }
            $err_data = is_array($response->get_error_data()) ? $response->get_error_data() : [];
            $provider_state = (string) ($err_data['provider_state'] ?? '');
            $memory_limit = $response->get_error_code() === 'apify_memory_limit' || $provider_state === 'QUEUED_RETRY_MEMORY_LIMIT';
            $retryable = $memory_limit || $provider_state === 'WAITING_RETRY' || $response->get_error_code() === 'apify_transport';
            $source['status'] = $memory_limit ? 'QUEUED_RETRY_MEMORY_LIMIT' : ($retryable ? 'WAITING_RETRY' : 'DISPATCH_ERROR');
            $source['dispatch_status'] = $memory_limit ? 'queued_retry_memory_limit' : ($retryable ? 'waiting_retry' : 'dispatch_failed');
            $source['analytical_usability_status'] = $retryable ? 'pending' : 'failed';
            $source['notes'] = $memory_limit ? 'Proveedor sin capacidad de memoria ahora; la fuente queda en cola de reintento.' : $response->get_error_message();
            if ($retryable) { self::schedule_source_retry($bundle, $source_key, $source, $memory_limit ? 180 : 90, $source['notes']); }
            else { self::update_source($bundle, $source_key, $source); }
            return;
        }
        $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
        $run_id = sanitize_text_field((string) ($data['id'] ?? ''));
        $status = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));
        $source['run_id'] = $run_id;
        $source['provider_status'] = $status;
        $source['dispatch_status'] = 'dispatched';
        if ($run_id !== '' && !empty($source['apify_slot_id']) && method_exists('VES_Apify_Client', 'register_run_id_for_slot')) {
            VES_Apify_Client::register_run_id_for_slot((string) $source['apify_slot_id'], $run_id);
        }
        if ($run_id === '') {
            $source['status'] = 'DISPATCH_ERROR';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = 'The social actor did not return a run ID.';
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        if ($status === 'SUCCEEDED') {
            self::apply_social_apify_result($bundle, $source_key, $source, 1);
            return;
        }
        if (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            $source['status'] = $status;
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = self::provider_message($data, 'Social actor run ended without usable output.');
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $source['status'] = 'RUNNING';
        $source['analytical_usability_status'] = 'pending';
        $source['notes'] = 'Social actor dispatched and still running.';
        $source['dispatched_at'] = self::now_mysql();
        $source['collect_attempt'] = 0;
        $source['next_collect_at'] = self::iso_after(25);
        self::update_source($bundle, $source_key, $source);
        if (class_exists('VES_Queue')) {
            VES_Queue::enqueue_unique(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'run_id' => (int) ($bundle['run_id'] ?? 0),
                'source_key' => $source_key,
                'attempt' => 1,
                '_ves_job_key' => 'brand_audit_social_collect_' . md5($bundle_id . '|' . $source_key . '|1'),
            ], 'ves_brand_audit', 25);
        }
    }

    private static function apply_social_apify_result($bundle, $source_key, $source, $attempt = 1) {
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        $attempt = self::next_collect_attempt($source, $attempt);
        $source['collect_attempt'] = $attempt;
        $source['last_collect_at'] = self::now_mysql();
        $run_id = sanitize_text_field((string) ($source['run_id'] ?? ''));
        if ($run_id === '' || !class_exists('VES_Apify_Client')) {
            if ($run_id === '' && $attempt <= 1 && max(0, (int) ($source['retry_attempt'] ?? 0)) < self::max_retry_attempts($source)) {
                self::schedule_source_retry($bundle, $source_key, $source, 60, 'Social source dispatch did not return a run ID. Re-dispatch queued once.');
                return;
            }
            $source['status'] = 'DISPATCH_ERROR';
            $source['dispatch_status'] = 'missing_run_id';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = 'Admin diagnostic: source collect blocked because provider run ID or Apify client was missing.';
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $response = VES_Apify_Client::fetch_run($run_id, 0);
        if (is_wp_error($response)) {
            if (self::source_runtime_seconds($source) >= self::source_timeout_seconds($source)) {
                $source = self::mark_source_partial_due_timeout($source, 'Social run status could not be fetched before the local timeout window.');
                self::release_social_slot($source);
                self::update_source($bundle, $source_key, $source);
                return;
            }
            if ($attempt < 8 && class_exists('VES_Queue')) {
                $source['status'] = 'RUNNING';
                $source['analytical_usability_status'] = 'pending';
                $source['notes'] = 'Social run status could not be fetched yet. Scheduled another collection attempt.';
                $source['next_collect_at'] = self::iso_after(self::source_collect_delay($source, $attempt, 25));
                self::update_source($bundle, $source_key, $source);
                VES_Queue::enqueue_unique(self::COLLECT_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'run_id' => (int) ($bundle['run_id'] ?? 0),
                    'source_key' => $source_key,
                    'attempt' => $attempt + 1,
                    '_ves_job_key' => 'brand_audit_social_collect_' . md5($bundle_id . '|' . $source_key . '|' . ($attempt + 1)),
                ], 'ves_brand_audit', min(150, 25 * $attempt));
                return;
            }
            $source['status'] = 'TIMEOUT';
            $source['dispatch_status'] = 'collect_failed';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = $response->get_error_message();
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
        $provider_status = strtoupper((string) ($data['status'] ?? ($source['provider_status'] ?? 'UNKNOWN')));
        $source['provider_status'] = $provider_status;
        if (!in_array($provider_status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            if (self::source_runtime_seconds($source) >= self::source_timeout_seconds($source)) {
                $source = self::mark_source_partial_due_timeout($source, 'Social source is still running upstream after the local timeout window. The Brand Audit will continue with available evidence.');
                self::release_social_slot($source);
                self::update_source($bundle, $source_key, $source);
                return;
            }
            if ($attempt < 9 && class_exists('VES_Queue')) {
                $source['status'] = 'RUNNING';
                $source['analytical_usability_status'] = 'pending';
                $source['notes'] = 'Social source still running upstream. Scheduled another collection attempt.';
                $source['next_collect_at'] = self::iso_after(self::source_collect_delay($source, $attempt, 30));
                self::update_source($bundle, $source_key, $source);
                VES_Queue::enqueue_unique(self::COLLECT_JOB_HOOK, [
                    'bundle_id' => $bundle_id,
                    'run_id' => (int) ($bundle['run_id'] ?? 0),
                    'source_key' => $source_key,
                    'attempt' => $attempt + 1,
                    '_ves_job_key' => 'brand_audit_social_collect_' . md5($bundle_id . '|' . $source_key . '|' . ($attempt + 1)),
                ], 'ves_brand_audit', min(180, 30 * $attempt));
                return;
            }
            $source['status'] = 'PARTIAL';
            $source['analytical_usability_status'] = 'limited';
            $source['notes'] = 'Social source did not finish within the polling window. Partial run can still complete.';
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        if (in_array($provider_status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            $source['status'] = $provider_status;
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = self::provider_message($data, 'Social source failed upstream.');
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $limit = self::social_fetch_limit($source);
        $items = VES_Apify_Client::fetch_items($run_id, $limit);
        if (is_wp_error($items)) {
            $source['status'] = 'FAILED';
            $source['dispatch_status'] = 'collect_failed';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = $items->get_error_message();
            $source['completed_at'] = self::now_mysql();
            self::release_social_slot($source);
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $platform = sanitize_key((string) ($source['platform'] ?? 'social'));
        $normalized = self::normalize_social_items($platform, is_array($items) ? $items : [], 40);
        $source['status'] = count($normalized) > 0 ? 'SUCCEEDED' : 'PARTIAL';
        $source['dispatch_status'] = 'collected';
        $source['analytical_usability_status'] = count($normalized) > 0 ? 'usable' : 'limited';
        $source['item_count'] = count(is_array($items) ? $items : []);
        $source['result'] = [
            'platform' => $platform,
            'run_id' => $run_id,
            'status' => $provider_status,
            'item_count_preview' => count($normalized),
            'all_items_count' => count(is_array($items) ? $items : []),
            'display_items' => $normalized,
            'analysis_items' => $normalized,
            'analysis_ready' => count($normalized) > 0,
            'result_quality' => count($normalized) > 0 ? 'usable' : 'limited',
            'status_message' => count($normalized) > 0 ? 'Social evidence collected.' : (function_exists('__ves_empty_hint') ? __ves_empty_hint($platform) : 'No readable social items returned.'),
        ];
        $source['highlights'] = self::highlights_from_social_items($platform, $normalized);
        $source['notes'] = count($normalized) > 0 ? 'Real social evidence collected from ' . $platform . '.' : 'Social actor succeeded but returned no readable items.';
        $source['completed_at'] = self::now_mysql();
        self::release_social_slot($source);
        self::update_source($bundle, $source_key, $source);
    }

    public static function collect_source_result_job($payload) {
        $payload = is_array($payload) ? $payload : [];
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $source_key = sanitize_key((string) ($payload['source_key'] ?? ''));
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || $source_key === '' || empty($bundle['sources'][$source_key]) || !empty($bundle['final'])) { return; }
        $source = is_array($bundle['sources'][$source_key]) ? $bundle['sources'][$source_key] : [];
        $source_type = sanitize_key((string) ($source['source_type'] ?? ''));
        if ($source_type === 'google_intel') {
            if (empty($source['run_id'])) {
                $source['status'] = 'DISPATCH_ERROR';
                $source['dispatch_status'] = 'missing_run_id';
                $source['analytical_usability_status'] = 'failed';
                $source['notes'] = 'Admin diagnostic: Google source collect blocked because provider run ID was missing.';
                $source['completed_at'] = self::now_mysql();
                self::update_source($bundle, $source_key, $source);
                return;
            }
            self::apply_google_intel_result($bundle, $source_key, $source, 10, $attempt);
            return;
        }
        if ($source_type === 'social_apify') {
            if (empty($source['run_id'])) {
                self::schedule_source_retry($bundle, $source_key, $source, 60, 'Social source collect blocked because provider run ID was missing. Re-dispatch queued if retry budget remains.');
                return;
            }
            self::apply_social_apify_result($bundle, $source_key, $source, $attempt);
            return;
        }
    }

    private static function apply_google_intel_result($bundle, $source_key, $source, $wait_for_finish = 0, $attempt = 1) {
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        $attempt = self::next_collect_attempt($source, $attempt);
        $source['collect_attempt'] = $attempt;
        $source['last_collect_at'] = self::now_mysql();
        $module_key = sanitize_key((string) ($source['module_key'] ?? ''));
        $run_id = sanitize_text_field((string) ($source['run_id'] ?? ''));
        if ($run_id === '' || !class_exists('VES_Google_Intel')) {
            $source['status'] = 'FAILED';
            $source['notes'] = 'Cannot collect source result because run ID or Google Intelligence is missing.';
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $formatted = VES_Google_Intel::fetch_response($module_key, $run_id, $wait_for_finish);
        if (is_wp_error($formatted)) {
            if (self::try_google_source_fallback($bundle, $source_key, $source, $formatted->get_error_message())) {
                return;
            }
            $source['status'] = 'FAILED';
            $source['dispatch_status'] = 'collect_failed';
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = $formatted->get_error_message();
            self::update_source($bundle, $source_key, $source);
            return;
        }
        $provider_status = strtoupper((string) ($formatted['status'] ?? 'UNKNOWN'));
        $quality = sanitize_key((string) ($formatted['result_quality'] ?? 'pending'));
        $source['provider_status'] = $provider_status;
        $source['result'] = self::trim_google_result($formatted);
        $source['item_count'] = (int) ($formatted['all_items_count'] ?? ($formatted['item_count_preview'] ?? 0));
        $source['highlights'] = self::highlights_from_google_result($module_key, $formatted);

        if ($provider_status === 'SUCCEEDED') {
            if ($quality === 'usable') {
                $source['status'] = 'SUCCEEDED';
                $source['analytical_usability_status'] = 'usable';
                $source['notes'] = 'Real Google Intelligence evidence collected.';
            } else {
                $source['status'] = 'PARTIAL';
                $source['analytical_usability_status'] = 'limited';
                $source['notes'] = !empty($formatted['status_message']) ? (string) $formatted['status_message'] : 'Source completed but returned limited readable evidence.';
            }
            $source['completed_at'] = self::now_mysql();
            self::update_source($bundle, $source_key, $source);
            return;
        }

        if (in_array($provider_status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            $upstream_message = !empty($formatted['status_message']) ? (string) $formatted['status_message'] : 'Source failed upstream.';
            if (self::try_google_source_fallback($bundle, $source_key, $source, $upstream_message)) {
                return;
            }
            $source['status'] = $provider_status;
            $source['analytical_usability_status'] = 'failed';
            $source['notes'] = $upstream_message;
            $source['completed_at'] = self::now_mysql();
            self::update_source($bundle, $source_key, $source);
            return;
        }

        if (self::source_runtime_seconds($source) >= self::source_timeout_seconds($source)) {
            $source = self::mark_source_partial_due_timeout($source, 'Google Intelligence source is still running upstream after the local timeout window. The Brand Audit will continue with available evidence.');
            self::update_source($bundle, $source_key, $source);
            return;
        }

        if ($attempt < 9 && class_exists('VES_Queue')) {
            $source['status'] = 'RUNNING';
            $source['analytical_usability_status'] = 'pending';
            $source['notes'] = 'Source still running upstream. Scheduled another collection attempt.';
            $source['next_collect_at'] = self::iso_after(self::source_collect_delay($source, $attempt, 20));
            self::update_source($bundle, $source_key, $source);
            VES_Queue::enqueue_unique(self::COLLECT_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'run_id' => (int) ($bundle['run_id'] ?? 0),
                'source_key' => $source_key,
                'attempt' => $attempt + 1,
                '_ves_job_key' => 'brand_audit_collect_' . md5($bundle_id . '|' . $source_key . '|' . ($attempt + 1)),
            ], 'ves_brand_audit', min(120, 20 * $attempt));
            return;
        }

        $source['status'] = 'PARTIAL';
        $source['analytical_usability_status'] = 'limited';
        $source['notes'] = 'Source did not finish within Sprint 2 polling window. Partial run can still complete.';
        $source['completed_at'] = self::now_mysql();
        self::update_source($bundle, $source_key, $source);
    }

    private static function try_google_source_fallback($bundle, $source_key, $source, $reason = '') {
        $module_key = sanitize_key((string) ($source['module_key'] ?? ''));
        if ($module_key === 'crawler' || $source_key === 'website_crawler') {
            return self::fallback_website_snapshot($bundle, $source_key, $source, $reason);
        }
        if ($source_key === 'google_news' || $module_key === 'news') {
            return self::fallback_news_via_search($bundle, $source_key, $source, $reason);
        }
        return false;
    }


    private static function validate_public_fetch_url($url) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if ($url === '') { return new WP_Error('invalid_url', 'Invalid website URL.'); }
        $parts = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return new WP_Error('invalid_url', 'Only public HTTP/HTTPS URLs are allowed.');
        }
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            return new WP_Error('blocked_url', 'Local or internal URLs are blocked.');
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = function_exists('gethostbynamel') ? gethostbynamel($host) : false;
            if (is_array($resolved)) { $ips = $resolved; }
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new WP_Error('blocked_url', 'URLs resolving to private or reserved networks are blocked.');
            }
        }
        return $url;
    }

    private static function safe_public_get($url, $args = []) {
        $url = self::validate_public_fetch_url($url);
        if (is_wp_error($url)) { return $url; }
        // v0.9.24.75 — User-Agent is sent to every scraped site during Brand Deep
        // Audit. Hardcoding a previous client's domain (https://future-island.club)
        // leaks tenant identity to every crawled origin. Use the current site URL
        // and plugin version instead; allow override via filter for power users.
        $audit_ua = sprintf(
            'Mozilla/5.0 (compatible; %s/%s; +%s)',
            'BrandAuditBot',
            defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : 'unknown',
            esc_url_raw(home_url('/'))
        );
        $audit_ua = (string) apply_filters('ves_brand_audit_user_agent', $audit_ua);
        $defaults = [
            'timeout' => 18,
            'redirection' => 0,
            'limit_response_size' => 350000,
            'headers' => [
                'User-Agent' => $audit_ua,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
        $response = wp_safe_remote_get($url, array_replace_recursive($defaults, is_array($args) ? $args : []));
        if (is_wp_error($response)) { return $response; }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if ($location) {
                $redirect = self::validate_public_fetch_url((string) $location);
                if (!is_wp_error($redirect)) {
                    return wp_safe_remote_get($redirect, array_replace_recursive($defaults, ['redirection' => 0], is_array($args) ? $args : []));
                }
            }
        }
        return $response;
    }

    private static function fallback_website_snapshot($bundle, $source_key, $source, $reason = '') {
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $url = esc_url_raw((string) ($request['website_url'] ?? ($source['input']['start_urls'] ?? '')));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) { return false; }
        $response = self::safe_public_get($url);
        if (is_wp_error($response)) { return false; }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 400 || trim($body) === '') { return false; }
        $title = self::extract_html_title($body);
        $description = self::extract_meta_description($body);
        $h1s = self::extract_headings($body, 'h1', 4);
        $h2s = self::extract_headings($body, 'h2', 6);
        $plain = self::html_to_excerpt($body, 1200);
        $host = wp_parse_url($url, PHP_URL_HOST);
        $item = [
            'title' => $title !== '' ? $title : ($host ?: $url),
            'url' => $url,
            'source' => (string) $host,
            'text' => self::truncate_text(implode(' ', array_filter([$description, $plain])), 600),
            'badge' => 'Website snapshot',
            'headings' => ['h1' => $h1s, 'h2' => $h2s],
        ];
        $source['status'] = 'SUCCEEDED';
        $source['provider_status'] = 'FALLBACK';
        $source['dispatch_status'] = 'wp_remote_fallback';
        $source['analytical_usability_status'] = 'usable';
        $source['item_count'] = 1;
        $source['result'] = [
            'module' => 'crawler',
            'module_label' => 'Website Content Crawler',
            'run_id' => '',
            'status' => 'FALLBACK',
            'item_count_preview' => 1,
            'all_items_count' => 1,
            'display_items' => [$item],
            'analysis_items' => [$item],
            'analysis_ready' => true,
            'result_quality' => 'usable',
            'runtime_secs' => null,
            'total_charge_usd' => null,
            'status_message' => 'WordPress HTTP fallback used after Apify crawler issue: ' . sanitize_text_field((string) $reason),
        ];
        $source['highlights'] = array_values(array_filter([
            $title !== '' ? 'Website title: ' . $title : '',
            $description !== '' ? 'Meta description captured.' : '',
            $h1s ? 'H1: ' . implode(' | ', array_slice($h1s, 0, 2)) : '',
        ]));
        if (!$source['highlights']) { $source['highlights'] = ['Website homepage text captured via fallback.']; }
        $source['notes'] = 'Website evidence collected via WordPress HTTP fallback after Apify crawler issue.';
        $source['completed_at'] = self::now_mysql();
        self::update_source($bundle, $source_key, $source);
        return true;
    }

    private static function fallback_news_via_search($bundle, $source_key, $source, $reason = '') {
        if (!class_exists('VES_Google_Intel') || !class_exists('VES_Config') || !VES_Config::get_token()) { return false; }
        $input = is_array($source['input'] ?? null) ? $source['input'] : [];
        $query = sanitize_text_field((string) ($input['query'] ?? ''));
        if ($query === '') { return false; }
        $search_input = VES_Google_Intel::build_input('search', [
            'query' => $query,
            'country_code' => $input['gl'] ?? '',
            'search_language' => $input['hl'] ?? '',
            'max_pages' => 1,
            'quick_date_range' => 'm3',
        ]);
        if (is_wp_error($search_input)) { return false; }
        $response = VES_Google_Intel::run_actor('search', $search_input, 0);
        if (is_wp_error($response)) { return false; }
        $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
        $run_id = sanitize_text_field((string) ($data['id'] ?? ''));
        if ($run_id === '') { return false; }
        // Do not block Brand Audit finalization on a secondary news fallback run.
        // Store the fallback run ID for diagnostics and finish this source as limited.
        $source['run_id'] = $run_id;
        $source['fallback_module_key'] = 'search';
        $source['provider_status'] = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));
        $source['dispatch_status'] = 'news_search_fallback_dispatched';
        $source['status'] = 'PARTIAL';
        $source['analytical_usability_status'] = 'limited';
        $source['item_count'] = 0;
        $source['highlights'] = ['News fallback was dispatched through Google Search, but it was not awaited to avoid blocking Brand Audit finalization.'];
        $source['notes'] = 'News fallback dispatched asynchronously after the original issue: ' . sanitize_text_field((string) $reason);
        $source['completed_at'] = self::now_mysql();
        self::update_source($bundle, $source_key, $source);
        return true;
    }

    private static function extract_html_title($html) {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', (string) $html, $m)) {
            return self::truncate_text(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 180);
        }
        return '';
    }

    private static function extract_meta_description($html) {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/is', (string) $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/is', (string) $html, $m)) {
            return self::truncate_text(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 320);
        }
        return '';
    }

    private static function extract_headings($html, $tag, $max = 5) {
        $tag = preg_replace('/[^a-z0-9]/i', '', (string) $tag);
        if ($tag === '') { return []; }
        preg_match_all('/<' . $tag . '[^>]*>(.*?)<\/' . $tag . '>/is', (string) $html, $matches);
        $out = [];
        foreach ((array) ($matches[1] ?? []) as $heading) {
            $text = self::truncate_text(html_entity_decode(wp_strip_all_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 160);
            if ($text !== '') { $out[] = $text; }
            if (count($out) >= $max) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function html_to_excerpt($html, $max = 1200) {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', (string) $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::truncate_text($text, $max);
    }

    private static function truncate_text($text, $max = 240) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        if ($text === '') { return ''; }
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) { return $text; }
        return (function_exists('mb_substr') ? mb_substr($text, 0, max(0, $max - 1)) : substr($text, 0, max(0, $max - 1))) . '...';
    }
    private static function provider_message($data, $fallback = '') {
        $data = is_array($data) ? $data : [];
        foreach (['statusMessage', 'status_message', 'errorMessage', 'error_message'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) { return sanitize_text_field((string) $data[$key]); }
        }
        return sanitize_text_field((string) $fallback);
    }

    private static function trim_google_result($formatted) {
        $formatted = is_array($formatted) ? $formatted : [];
        return [
            'module' => sanitize_key((string) ($formatted['module'] ?? '')),
            'module_label' => sanitize_text_field((string) ($formatted['module_label'] ?? '')),
            'run_id' => sanitize_text_field((string) ($formatted['run_id'] ?? '')),
            'status' => sanitize_text_field((string) ($formatted['status'] ?? '')),
            'item_count_preview' => (int) ($formatted['item_count_preview'] ?? 0),
            'all_items_count' => (int) ($formatted['all_items_count'] ?? 0),
            'display_items' => array_slice((array) ($formatted['display_items'] ?? []), 0, 18),
            'analysis_items' => array_slice((array) ($formatted['analysis_items'] ?? []), 0, 18),
            'analysis_ready' => !empty($formatted['analysis_ready']),
            'result_quality' => sanitize_key((string) ($formatted['result_quality'] ?? 'pending')),
            'runtime_secs' => isset($formatted['runtime_secs']) ? (float) $formatted['runtime_secs'] : null,
            'total_charge_usd' => isset($formatted['total_charge_usd']) ? (float) $formatted['total_charge_usd'] : null,
            'status_message' => sanitize_textarea_field((string) ($formatted['status_message'] ?? '')),
        ];
    }

    private static function highlights_from_google_result($module_key, $formatted) {
        $items = array_slice((array) ($formatted['analysis_items'] ?? $formatted['display_items'] ?? []), 0, 4);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $title = trim((string) ($item['title'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            if ($title !== '') { $out[] = sanitize_text_field($title); }
            elseif ($text !== '') { $out[] = sanitize_text_field($text); }
        }
        if ($out) { return $out; }
        $count = (int) ($formatted['all_items_count'] ?? 0);
        return [$count > 0 ? sprintf('%d readable items collected from %s.', $count, sanitize_key((string) $module_key)) : 'Source completed with limited readable evidence.'];
    }

    private static function redact_array($input) {
        $input = is_array($input) ? $input : [];
        $json = wp_json_encode($input);
        if (is_string($json) && strlen($json) > 8000) { return ['summary' => 'Input too large to display safely.']; }
        return $input;
    }

    private static function placeholder_highlights($source_key, $request) {
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? 'Brand'));
        $map = [
            'brand_profile' => ["Brand profile initialized for {$brand}.", 'Request sanitized and ready for evidence collection.'],
            'social_snapshot' => ['Social URLs, hashtags and channels mapped.', 'Real social scraper adapters will be attached after the evidence pipeline is stable.'],
            'maps_reviews' => ['Google Maps review collection requested.', 'Maps connector will collect places, ratings and review snippets when available.'],
        ];
        return $map[$source_key] ?? ['Source completed.'];
    }

    public static function tick_brand_audit_run($bundle_id) {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) { return null; }
        if (!empty($bundle['final'])) { return $bundle; }
        self::start_queued_sources($bundle_id, (int) ($bundle['run_id'] ?? 0));
        $bundle = self::get_bundle($bundle_id);
        $bundle = self::force_finalize_if_stale($bundle);
        if (!empty($bundle['final'])) { return $bundle; }
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            $status = strtoupper((string) ($source['status'] ?? ''));
            if (in_array($status, ['QUEUED', 'WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true) || ($status === 'RUNNING' && empty($source['run_id']))) {
                self::run_source_job(['bundle_id' => $bundle_id, 'run_id' => (int) ($bundle['run_id'] ?? 0), 'source_key' => $source_key]);
            } elseif ($status === 'RUNNING' && in_array(($source['source_type'] ?? ''), ['google_intel', 'social_apify'], true) && !empty($source['run_id'])) {
                if (!self::source_ready_for_collect($source)) { continue; }
                self::collect_source_result_job(['bundle_id' => $bundle_id, 'run_id' => (int) ($bundle['run_id'] ?? 0), 'source_key' => $source_key, 'attempt' => self::next_collect_attempt($source)]);
            }
        }
        $bundle = self::get_bundle($bundle_id);
        $bundle = self::force_finalize_if_stale($bundle);
        if (!empty($bundle['final'])) { return $bundle; }
        $all_terminal = true;
        foreach ((array) ($bundle['sources'] ?? []) as $source) {
            if (!in_array(strtoupper((string) ($source['status'] ?? '')), self::terminal_statuses(), true)) {
                $all_terminal = false;
                break;
            }
        }
        if ($all_terminal && empty($bundle['final'])) {
            self::finalize_bundle($bundle, 'poll');
        }
        return self::get_bundle($bundle_id);
    }

    public static function finalize_run_job($payload) {
        $payload = is_array($payload) ? $payload : [];
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || !empty($bundle['final'])) { return; }
        self::tick_brand_audit_run($bundle_id);
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle || !empty($bundle['final'])) { return; }
        $bundle = self::force_finalize_if_stale($bundle);
        if (!empty($bundle['final'])) { return; }
        $has_running = false;
        foreach ((array) ($bundle['sources'] ?? []) as $source) {
            if (strtoupper((string) ($source['status'] ?? '')) === 'RUNNING') { $has_running = true; break; }
        }
        if ($has_running && class_exists('VES_Queue')) {
            VES_Queue::enqueue_unique(self::FINALIZE_JOB_HOOK, [
                'bundle_id' => $bundle_id,
                'run_id' => (int) ($bundle['run_id'] ?? 0),
                '_ves_job_key' => 'brand_audit_finalize_retry_' . md5($bundle_id . '|' . time()),
            ], 'ves_brand_audit', 45);
            return;
        }
        self::finalize_bundle($bundle, 'queue');
    }

    private static function social_fetch_limit($source) {
        $input = is_array($source['input'] ?? null) ? $source['input'] : [];
        foreach (['resultsLimit', 'maxItems', 'maxResults', 'resultsPerPage'] as $key) {
            if (isset($input[$key]) && (int) $input[$key] > 0) {
                return max(10, min(120, (int) $input[$key] + 10));
            }
        }
        return 80;
    }

    private static function deep_value($array, $paths) {
        $array = is_array($array) ? $array : [];
        foreach ((array) $paths as $path) {
            $cursor = $array;
            foreach (explode('.', (string) $path) as $part) {
                if (is_array($cursor) && array_key_exists($part, $cursor)) {
                    $cursor = $cursor[$part];
                } else {
                    $cursor = null;
                    break;
                }
            }
            if (is_scalar($cursor) && trim((string) $cursor) !== '') { return (string) $cursor; }
        }
        return '';
    }

    private static function first_url_from_raw($raw, $platform = '') {
        $url = self::deep_value($raw, ['url', 'postUrl', 'postURL', 'videoUrl', 'videoURL', 'webVideoUrl', 'webVideoURL', 'link', 'permalink', 'shortCodeUrl', 'displayUrl', 'facebookUrl']);
        if ($url !== '') { return esc_url_raw($url); }
        if ($platform === 'instagram') {
            $shortcode = self::deep_value($raw, ['shortCode', 'shortcode']);
            if ($shortcode !== '') { return esc_url_raw('https://www.instagram.com/p/' . $shortcode . '/'); }
        }
        return '';
    }

    private static function number_from_raw($raw, $paths) {
        $value = self::deep_value($raw, $paths);
        if ($value === '') { return 0; }
        if (is_numeric($value)) { return (float) $value; }
        $value = strtolower(str_replace([',', ' '], ['', ''], $value));
        $mult = 1;
        if (substr($value, -1) === 'k') { $mult = 1000; $value = substr($value, 0, -1); }
        if (substr($value, -1) === 'm') { $mult = 1000000; $value = substr($value, 0, -1); }
        return is_numeric($value) ? (float) $value * $mult : 0;
    }

    private static function normalize_social_items($platform, $items, $limit = 24) {
        $platform = sanitize_key((string) $platform);
        $out = [];
        foreach ((array) $items as $idx => $raw) {
            if (!is_array($raw)) { continue; }
            $title = self::deep_value($raw, ['title', 'videoTitle', 'name', 'caption', 'text', 'fullText', 'description', 'desc']);
            $text = self::deep_value($raw, ['text', 'fullText', 'caption', 'description', 'desc', 'videoDescription', 'title']);
            $author = self::deep_value($raw, ['author', 'authorName', 'authorMeta.name', 'ownerUsername', 'ownerFullName', 'username', 'user.username', 'user.name', 'channelName', 'channelTitle', 'pageName']);
            $url = self::first_url_from_raw($raw, $platform);
            $views = self::number_from_raw($raw, ['views', 'viewCount', 'playCount', 'videoViewCount', 'videoPlayCount', 'stats.playCount']);
            $likes = self::number_from_raw($raw, ['likes', 'likeCount', 'likesCount', 'diggCount', 'favorites', 'favoriteCount', 'stats.diggCount']);
            $comments = self::number_from_raw($raw, ['comments', 'commentCount', 'commentsCount', 'stats.commentCount']);
            $shares = self::number_from_raw($raw, ['shares', 'shareCount', 'sharesCount', 'stats.shareCount']);
            $saves = self::number_from_raw($raw, ['saves', 'collectCount', 'bookmarkCount', 'stats.collectCount']);
            $published_at = self::deep_value($raw, ['publishedAt', 'publishDate', 'date', 'createdAt', 'timestamp', 'takenAt', 'createTime', 'uploadDate']);
            $duration = self::deep_value($raw, ['duration', 'durationText', 'videoDuration']);
            if ($title === '' && $text === '' && $url === '' && $author === '') { continue; }
            $out[] = [
                'platform' => $platform,
                'title' => self::truncate_text($title !== '' ? $title : $text, 180),
                'url' => $url,
                'source' => $author !== '' ? $author : $platform,
                'text' => self::truncate_text($text, 520),
                'badge' => ucfirst(str_replace('_', ' ', $platform)),
                'metrics' => [
                    'views' => $views,
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'saves' => $saves,
                    'engagement_total' => $likes + $comments + $shares + $saves,
                ],
                'published_at' => sanitize_text_field((string) $published_at),
                'duration' => sanitize_text_field((string) $duration),
                'raw_index' => (int) $idx,
            ];
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    private static function highlights_from_social_items($platform, $items) {
        $out = [];
        foreach (array_slice((array) $items, 0, 5) as $item) {
            if (!is_array($item)) { continue; }
            $title = trim((string) ($item['title'] ?? ''));
            $metrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
            $suffix = '';
            if (!empty($metrics['views'])) { $suffix = ' (' . number_format_i18n((float) $metrics['views']) . ' views)'; }
            elseif (!empty($metrics['engagement_total'])) { $suffix = ' (' . number_format_i18n((float) $metrics['engagement_total']) . ' engagements)'; }
            if ($title !== '') { $out[] = ucfirst($platform) . ': ' . self::truncate_text($title, 160) . $suffix; }
        }
        return $out ?: [ucfirst($platform) . ' returned readable social evidence.'];
    }

    private static function social_metric_score($platform, $metrics) {
        $platform = sanitize_key((string) $platform);
        $metrics = is_array($metrics) ? $metrics : [];
        $views = (float) ($metrics['views'] ?? 0);
        $likes = (float) ($metrics['likes'] ?? 0);
        $comments = (float) ($metrics['comments'] ?? 0);
        $shares = (float) ($metrics['shares'] ?? 0);
        $saves = (float) ($metrics['saves'] ?? 0);
        $engagement = (float) ($metrics['engagement_total'] ?? ($likes + $comments + $shares + $saves));
        if (in_array($platform, ['youtube', 'tiktok'], true) && $views > 0) {
            return $views + ($engagement * 3);
        }
        return $engagement > 0 ? $engagement : ($likes + $comments + $shares + $saves + $views);
    }

    private static function new_social_stat_row($entity_role, $entity_name, $platform = '') {
        return [
            'entity_role' => sanitize_key((string) $entity_role),
            'entity_name' => sanitize_text_field((string) $entity_name),
            'platform' => sanitize_key((string) $platform),
            'items' => 0,
            'views' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'saves' => 0,
            'engagement_total' => 0,
            'metric_score' => 0,
            'top_post_title' => '',
            'top_post_url' => '',
            'top_post_score' => 0,
        ];
    }

    private static function add_social_stat_item($row, $platform, $item) {
        $row = is_array($row) ? $row : self::new_social_stat_row('', '', $platform);
        $item = is_array($item) ? $item : [];
        $metrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
        $views = (float) ($metrics['views'] ?? 0);
        $likes = (float) ($metrics['likes'] ?? 0);
        $comments = (float) ($metrics['comments'] ?? 0);
        $shares = (float) ($metrics['shares'] ?? 0);
        $saves = (float) ($metrics['saves'] ?? 0);
        $engagement = (float) ($metrics['engagement_total'] ?? ($likes + $comments + $shares + $saves));
        $score = self::social_metric_score($platform, $metrics);
        $row['items'] = (int) ($row['items'] ?? 0) + 1;
        $row['views'] = (float) ($row['views'] ?? 0) + $views;
        $row['likes'] = (float) ($row['likes'] ?? 0) + $likes;
        $row['comments'] = (float) ($row['comments'] ?? 0) + $comments;
        $row['shares'] = (float) ($row['shares'] ?? 0) + $shares;
        $row['saves'] = (float) ($row['saves'] ?? 0) + $saves;
        $row['engagement_total'] = (float) ($row['engagement_total'] ?? 0) + $engagement;
        $row['metric_score'] = (float) ($row['metric_score'] ?? 0) + $score;
        if ($score > (float) ($row['top_post_score'] ?? 0)) {
            $row['top_post_score'] = $score;
            $row['top_post_title'] = sanitize_text_field((string) ($item['title'] ?? ''));
            $row['top_post_url'] = esc_url_raw((string) ($item['url'] ?? ''));
        }
        return $row;
    }

    private static function finalize_social_stat_row($row) {
        $row = is_array($row) ? $row : [];
        $items = max(0, (int) ($row['items'] ?? 0));
        $engagement = (float) ($row['engagement_total'] ?? 0);
        $views = (float) ($row['views'] ?? 0);
        $row['avg_engagement_per_item'] = $items > 0 ? round($engagement / $items, 2) : 0;
        $row['avg_views_per_item'] = $items > 0 ? round($views / $items, 2) : 0;
        $row['engagement_rate_vs_views'] = $views > 0 ? round(($engagement / $views) * 100, 3) : null;
        $row['coverage_status'] = $items >= 20 ? 'minimum_20_reached' : ($items > 0 ? 'below_minimum_20' : 'no_items');
        return $row;
    }

    private static function build_social_platform_evidence($sources) {
        $platforms = [];
        $posts = [];
        $entity_platform_stats = [];
        $entity_totals = [];
        foreach ((array) $sources as $key => $source) {
            if (!is_array($source) || ($source['source_type'] ?? '') !== 'social_apify') { continue; }
            $platform = sanitize_key((string) ($source['platform'] ?? $key));
            $entity_role = sanitize_key((string) ($source['entity_role'] ?? 'brand'));
            $entity_name = sanitize_text_field((string) ($source['entity_name'] ?? ($entity_role === 'competitor' ? 'Competitor' : 'Brand')));
            $entity_key = sanitize_key((string) ($source['entity_key'] ?? ($entity_role . '_' . md5($entity_name))));
            $variant = sanitize_key((string) ($source['source_variant'] ?? ''));
            $items = self::source_result_items($sources, $key);
            $platforms[$key] = [
                'source_key' => sanitize_key((string) $key),
                'label' => sanitize_text_field((string) ($source['label'] ?? $platform)),
                'status' => strtoupper((string) ($source['status'] ?? '')),
                'mode' => sanitize_key((string) ($source['mode'] ?? '')),
                'entity_role' => $entity_role,
                'entity_name' => $entity_name,
                'entity_key' => $entity_key,
                'source_variant' => $variant,
                'platform' => $platform,
                'item_count' => (int) ($source['item_count'] ?? count($items)),
                'readable_items' => count($items),
                'minimum_expected_items' => 20,
                'items' => array_slice($items, 0, 24),
                'notes' => sanitize_text_field((string) ($source['notes'] ?? '')),
            ];
            $stat_key = $entity_key . '|' . $platform;
            if (!isset($entity_platform_stats[$stat_key])) {
                $entity_platform_stats[$stat_key] = self::new_social_stat_row($entity_role, $entity_name, $platform);
            }
            if (!isset($entity_totals[$entity_key])) {
                $entity_totals[$entity_key] = self::new_social_stat_row($entity_role, $entity_name, 'all');
                $entity_totals[$entity_key]['platforms_observed'] = [];
            }
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                $item['platform'] = $platform;
                $item['entity_role'] = $entity_role;
                $item['entity_name'] = $entity_name;
                $item['entity_key'] = $entity_key;
                $item['source_variant'] = $variant;
                $item['source_key'] = sanitize_key((string) $key);
                $posts[] = $item;
                $entity_platform_stats[$stat_key] = self::add_social_stat_item($entity_platform_stats[$stat_key], $platform, $item);
                $entity_totals[$entity_key] = self::add_social_stat_item($entity_totals[$entity_key], $platform, $item);
                $entity_totals[$entity_key]['platforms_observed'][$platform] = true;
            }
        }
        usort($posts, static function($a, $b) {
            $am = is_array($a['metrics'] ?? null) ? $a['metrics'] : [];
            $bm = is_array($b['metrics'] ?? null) ? $b['metrics'] : [];
            $as = self::social_metric_score((string) ($a['platform'] ?? ''), $am);
            $bs = self::social_metric_score((string) ($b['platform'] ?? ''), $bm);
            return $bs <=> $as;
        });
        foreach ($entity_platform_stats as $k => $row) { $entity_platform_stats[$k] = self::finalize_social_stat_row($row); }
        foreach ($entity_totals as $k => $row) {
            $row['platforms_observed'] = array_keys((array) ($row['platforms_observed'] ?? []));
            $entity_totals[$k] = self::finalize_social_stat_row($row);
        }
        $entity_platform_stats_values = array_values($entity_platform_stats);
        usort($entity_platform_stats_values, static function($a, $b) {
            return ((float) ($b['metric_score'] ?? 0)) <=> ((float) ($a['metric_score'] ?? 0));
        });
        $entity_totals_values = array_values($entity_totals);
        usort($entity_totals_values, static function($a, $b) {
            return ((float) ($b['metric_score'] ?? 0)) <=> ((float) ($a['metric_score'] ?? 0));
        });
        $blindspots = [];
        foreach ($platforms as $source_key => $row) {
            if ((int) ($row['readable_items'] ?? 0) < 20) {
                $blindspots[] = sprintf('%s / %s collected %d readable item(s), below the intended 20-item minimum.', (string) ($row['entity_name'] ?? ''), (string) ($row['platform'] ?? ''), (int) ($row['readable_items'] ?? 0));
            }
        }
        return [
            'platforms' => $platforms,
            'posts' => array_slice($posts, 0, 120),
            'top_posts' => array_slice($posts, 0, 20),
            'entity_platform_stats' => $entity_platform_stats_values,
            'entity_comparison' => $entity_totals_values,
            'minimum_posts_per_entity_platform' => 20,
            'coverage_blindspots' => $blindspots,
            'earned_mentions' => [],
            'content_territories' => [],
            'coverage_summary' => $posts ? 'Observed social evidence collected from ' . count($platforms) . ' entity/platform source(s).' : 'No readable social post/profile evidence collected yet.',
        ];
    }

    private static function source_result_items($sources, $key) {
        $source = is_array($sources[$key] ?? null) ? $sources[$key] : [];
        $result = is_array($source['result'] ?? null) ? $source['result'] : [];
        $items = is_array($result['analysis_items'] ?? null) ? $result['analysis_items'] : [];
        if (!$items) { $items = is_array($result['display_items'] ?? null) ? $result['display_items'] : []; }
        return array_slice($items, 0, 40);
    }

    private static function source_display_items($sources, $key) {
        $source = is_array($sources[$key] ?? null) ? $sources[$key] : [];
        $result = is_array($source['result'] ?? null) ? $source['result'] : [];
        $items = is_array($result['display_items'] ?? null) ? $result['display_items'] : [];
        if (!$items) { $items = is_array($result['analysis_items'] ?? null) ? $result['analysis_items'] : []; }
        return array_slice($items, 0, 12);
    }

    private static function first_scalar_from_array($array, $keys) {
        $array = is_array($array) ? $array : [];
        foreach ((array) $keys as $key) {
            if (isset($array[$key]) && is_scalar($array[$key]) && trim((string) $array[$key]) !== '') {
                return (string) $array[$key];
            }
        }
        return '';
    }

    private static function review_rows_from_place_raw($raw, $max = 8) {
        $raw = is_array($raw) ? $raw : [];
        $buckets = [];
        foreach (['reviews', 'reviewsData', 'userReviews', 'reviewItems'] as $key) {
            if (!empty($raw[$key]) && is_array($raw[$key])) { $buckets[] = $raw[$key]; }
        }
        $out = [];
        foreach ($buckets as $reviews) {
            foreach ($reviews as $review) {
                if (!is_array($review)) { continue; }
                $text = self::first_scalar_from_array($review, ['text', 'textTranslated', 'reviewText', 'comment', 'snippet', 'description']);
                $text = self::truncate_text($text, 360);
                if ($text === '') { continue; }
                $out[] = [
                    'text' => $text,
                    'rating' => self::first_scalar_from_array($review, ['stars', 'rating', 'score']),
                    'published_at' => self::first_scalar_from_array($review, ['publishedAtDate', 'publishedAt', 'date', 'relativePublishTimeDescription']),
                    'author' => self::first_scalar_from_array($review, ['name', 'authorName', 'userName', 'reviewerName']),
                ];
                if (count($out) >= $max) { return $out; }
            }
        }
        return $out;
    }

    private static function build_maps_review_evidence($sources) {
        $items = self::source_display_items($sources, 'maps_reviews');
        $places = [];
        $review_items = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
            $place = [
                'title' => sanitize_text_field((string) ($item['title'] ?? ($raw['title'] ?? ($raw['name'] ?? '')))),
                'url' => esc_url_raw((string) ($item['url'] ?? ($raw['website'] ?? ($raw['url'] ?? '')))),
                'address' => sanitize_text_field((string) ($item['source'] ?? ($raw['address'] ?? ''))),
                'category' => sanitize_text_field((string) ($raw['categoryName'] ?? ($raw['category'] ?? ''))),
                'rating' => isset($raw['totalScore']) ? (string) $raw['totalScore'] : (isset($raw['rating']) ? (string) $raw['rating'] : ''),
                'reviews_count' => isset($raw['reviewsCount']) ? (int) $raw['reviewsCount'] : (isset($raw['reviews_count']) ? (int) $raw['reviews_count'] : 0),
                'text' => sanitize_textarea_field((string) ($item['text'] ?? '')),
                'badge' => sanitize_text_field((string) ($item['badge'] ?? 'Maps')),
            ];
            if (trim(implode('', array_map('strval', $place))) !== '') { $places[] = $place; }
            $reviews = self::review_rows_from_place_raw($raw, 8);
            foreach ($reviews as $review) {
                $review['place_title'] = $place['title'];
                $review_items[] = $review;
                if (count($review_items) >= 16) { break 2; }
            }
        }
        return [
            'places' => array_slice($places, 0, 8),
            'review_items' => array_slice($review_items, 0, 16),
            'positive_drivers' => [],
            'negative_drivers' => [],
            'trust_signals' => [],
            'coverage_summary' => $places ? 'Google Maps places/ratings evidence collected.' . ($review_items ? ' Review snippets are available.' : ' No readable review text was returned by the provider.') : 'No usable Google Maps/review evidence collected yet.',
        ];
    }

    private static function build_evidence_pack($bundle) {
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $sources = is_array($bundle['sources'] ?? null) ? $bundle['sources'] : [];
        $summary = class_exists('VES_Brand_Audit_Source_Evaluator') ? VES_Brand_Audit_Source_Evaluator::summarize($sources) : [];
        $blindspots = [];
        $coverage = [];
        foreach ($sources as $key => $source) {
            if (!is_array($source)) { continue; }
            $status = strtoupper((string) ($source['status'] ?? ''));
            $coverage[$key] = [
                'label' => sanitize_text_field((string) ($source['label'] ?? $key)),
                'source_type' => sanitize_key((string) ($source['source_type'] ?? '')),
                'status' => $status,
                'item_count' => (int) ($source['item_count'] ?? 0),
                'notes' => sanitize_text_field((string) ($source['notes'] ?? '')),
            ];
            if (($source['source_type'] ?? '') === 'placeholder') {
                $blindspots[] = sanitize_text_field((string) ($source['label'] ?? $key)) . ' is still a placeholder in this build.';
            } elseif (in_array($status, ['CONFIG_ERROR', 'DISPATCH_ERROR', 'FAILED', 'TIMEOUT', 'TIMED-OUT'], true)) {
                $blindspots[] = sanitize_text_field((string) ($source['label'] ?? $key)) . ' returned ' . $status . '.';
            }
        }

        $website_items = self::source_result_items($sources, 'website_crawler');
        $search_items = self::source_result_items($sources, 'google_search');
        $news_items = self::source_result_items($sources, 'google_news');
        $trend_items = self::source_result_items($sources, 'google_trends');
        $competitor_items = self::source_result_items($sources, 'competitor_search');
        $maps_evidence = self::build_maps_review_evidence($sources);
        $social_evidence = self::build_social_platform_evidence($sources);
        if (class_exists('VES_Brand_Audit_Scoring')) {
            $social_evidence = VES_Brand_Audit_Scoring::classify_social_evidence($social_evidence, $request);
        }
        $search_clusters = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::cluster_search_intent($search_items, (array) ($bundle['query_plan'] ?? [])) : [];
        $review_analysis = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::analyze_reviews($maps_evidence, $search_items) : [];
        $competitor_context = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::build_competitor_context($competitor_items, $request, (array) ($bundle['query_plan'] ?? [])) : [
            'competitors' => array_values((array) ($request['competitors'] ?? [])),
            'competitor_queries' => array_values((array) ($bundle['query_plan']['competitor_queries'] ?? [])),
            'competitor_search_items' => $competitor_items,
            'gap_matrix' => [],
            'white_space' => [],
        ];
        $source_quality_detail = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::build_source_quality($sources, $summary) : ['summary' => $summary, 'families' => [], 'cards' => []];

        return [
            'brand_profile' => [
                'brand_name' => sanitize_text_field((string) ($request['brand_name'] ?? '')),
                'website_url' => esc_url_raw((string) ($request['website_url'] ?? '')),
                'country' => sanitize_text_field((string) ($request['country'] ?? '')),
                'industry' => sanitize_text_field((string) ($request['industry'] ?? '')),
                'audit_goal' => sanitize_textarea_field((string) ($request['audit_goal'] ?? '')),
                'depth' => sanitize_key((string) ($request['depth'] ?? 'standard')),
            ],
            'owned_web' => [
                'positioning_summary' => $website_items ? 'Website evidence collected from public pages.' : 'No usable website evidence collected yet.',
                'pages' => $website_items,
                'claims' => [],
                'products_services' => [],
                'ctas' => [],
                'content_gaps' => [],
            ],
            'search_intent' => [
                'top_queries' => array_values((array) ($bundle['query_plan']['source_queries'] ?? [])),
                'google_search_items' => $search_items,
                'intent_clusters' => $search_clusters,
                'paa_questions' => array_values(array_filter($search_items, static function($item) {
                    return is_array($item) && strtolower((string) ($item['badge'] ?? '')) === 'question';
                })),
                'review_intent' => array_values((array) ($bundle['query_plan']['review_queries'] ?? [])),
            ],
            'public_web_news' => [
                'news_items' => $news_items,
                'coverage_summary' => $news_items ? 'News/public coverage evidence collected.' : 'No usable news coverage collected yet.',
            ],
            'trend_interest' => [
                'trend_items' => $trend_items,
                'terms_compared' => array_filter(array_map('trim', explode("\n", (string) ($sources['google_trends']['input']['search_terms'] ?? '')))),
            ],
            'social_owned' => array_merge([
                'channels_submitted' => array_filter([
                    'instagram' => $request['instagram_url'] ?? '',
                    'tiktok' => $request['tiktok_url'] ?? '',
                    'youtube' => $request['youtube_url'] ?? '',
                    'linkedin' => $request['linkedin_url'] ?? '',
                    'x_twitter' => $request['twitter_url'] ?? '',
                    'facebook' => $request['facebook_url'] ?? '',
                ]),
                'hashtags' => array_values((array) ($request['hashtags'] ?? [])),
            ], $social_evidence),
            'reviews_maps' => array_merge([
                'google_maps_url' => esc_url_raw((string) ($request['google_maps_url'] ?? '')),
            ], $maps_evidence, $review_analysis),
            'competitor_context' => $competitor_context,
            'source_quality' => array_merge($summary, [
                'blindspots' => $blindspots,
                'coverage' => $coverage,
                'detail' => $source_quality_detail,
                'family_summary' => $source_quality_detail['families'] ?? [],
                'status_cards' => $source_quality_detail['cards'] ?? [],
            ]),
        ];
    }

    private static function evidence_pack_highlights($evidence_pack) {
        $evidence_pack = is_array($evidence_pack) ? $evidence_pack : [];
        $out = [];
        foreach ([
            ['owned_web', 'pages', 'Website'],
            ['search_intent', 'google_search_items', 'Search'],
            ['public_web_news', 'news_items', 'News/Search coverage'],
            ['trend_interest', 'trend_items', 'Trends'],
            ['social_owned', 'top_posts', 'Social'],
            ['social_owned', 'posts', 'Social'],
            ['reviews_maps', 'places', 'Maps'],
            ['reviews_maps', 'review_items', 'Review'],
        ] as $spec) {
            [$section, $key, $label] = $spec;
            $items = is_array($evidence_pack[$section][$key] ?? null) ? $evidence_pack[$section][$key] : [];
            foreach (array_slice($items, 0, 3) as $item) {
                if (!is_array($item)) { continue; }
                $title = trim((string) ($item['title'] ?? ''));
                $text = trim((string) ($item['text'] ?? ''));
                if ($title !== '') { $out[] = $label . ': ' . self::truncate_text($title, 180); }
                elseif ($text !== '') { $out[] = $label . ': ' . self::truncate_text($text, 180); }
                if (count($out) >= 10) { return $out; }
            }
        }
        return $out;
    }
    private static function brand_audit_competitor_names($request) {
        $request = is_array($request) ? $request : [];
        $items = $request['competitors'] ?? [];
        if (is_string($items)) { $items = preg_split('/[\n,]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['name'] ?? $item['brand_name'] ?? $item['title'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $name = trim(sanitize_text_field((string) $item));
            if ($name !== '') { $out[] = $name; }
        }
        return array_values(array_unique($out));
    }

    private static function brand_audit_platforms_from_sources($sources) {
        $sources = is_array($sources) ? $sources : [];
        $platforms = [];
        foreach ($sources as $key => $source) {
            if (!is_array($source)) { continue; }
            foreach ([$source['platform'] ?? '', $source['source_platform'] ?? '', $source['family'] ?? '', $key] as $candidate) {
                $candidate = sanitize_key((string) $candidate);
                if ($candidate === '') { continue; }
                if (in_array($candidate, ['social_owned', 'social', 'owned_social'], true)) {
                    if (!empty($source['input']['platform'])) { $candidate = sanitize_key((string) $source['input']['platform']); }
                }
                $platforms[$candidate] = true;
            }
        }
        return array_keys($platforms);
    }

    public static function finalize_bundle($bundle, $request_id = '') {
        if (!is_array($bundle)) { return null; }
        $bundle_id = sanitize_text_field((string) ($bundle['bundle_id'] ?? ''));
        if ($bundle_id === '') { return null; }
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        $sources = is_array($bundle['sources'] ?? null) ? $bundle['sources'] : [];
        $summary = class_exists('VES_Brand_Audit_Source_Evaluator') ? VES_Brand_Audit_Source_Evaluator::summarize($sources) : ['usable_sources' => 0, 'partial_sources' => 0, 'failed_sources' => 0, 'total_sources' => count($sources)];
        $confidence_gate = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::confidence_gate($summary, ['ai_success' => false]) : ['level' => 'exploratory', 'score' => 50, 'reasons' => [], 'blocks' => []];
        $confidence_mode = (string) ($confidence_gate['level'] ?? 'exploratory');
        $evidence_pack = self::build_evidence_pack($bundle);
        $bundle['status'] = 'analyzing';
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Brand_Audit_Run_Store')) { VES_Brand_Audit_Run_Store::update_sources($bundle_id, $sources, 'running'); }

        $brand = sanitize_text_field((string) ($request['brand_name'] ?? 'Brand'));
        $usable = (int) ($summary['usable_sources'] ?? 0);
        $partial = (int) ($summary['partial_sources'] ?? 0);
        $failed = (int) ($summary['failed_sources'] ?? 0);
        $evidence_highlights = self::evidence_pack_highlights($evidence_pack);
        $analysis_structured = [
            'executive_summary' => sprintf('Brand Audit for %s completed with %d usable, %d partial and %d failed source(s). OpenAI strategic interpretation was not available, so this is the local fallback report.', $brand, $usable, $partial, $failed),
            'strategic_diagnosis' => [
                'main_finding' => $evidence_highlights ? 'Initial evidence was collected and is ready for strategic interpretation.' : 'The run completed, but evidence is still too thin for strategic interpretation.',
                'why_it_matters' => 'The system should only generate recommendations after evidence is visible, traceable and source-aware.',
                'confidence' => $confidence_mode,
                'evidence_summary' => array_merge([
                    sprintf('%d usable source(s)', $usable),
                    sprintf('%d partial source(s)', $partial),
                    sprintf('%d failed source(s)', $failed),
                ], array_slice($evidence_highlights, 0, 6)),
            ],
            'brand_positioning_readout' => [
                'what_brand_says' => array_slice($evidence_highlights, 0, 3),
                'what_market_sees' => [],
                'positioning_gap' => ['OpenAI analysis was not available for deeper positioning interpretation.'],
            ],
            'search_intent_readout' => [
                'main_intents' => array_values((array) ($evidence_pack['search_intent']['top_queries'] ?? [])),
                'decision_stage_needs' => array_values((array) ($evidence_pack['search_intent']['review_intent'] ?? [])),
                'unanswered_questions' => [],
                'seo_content_opportunities' => [],
            ],
            'social_readout' => [
                'owned_content_patterns' => [],
                'earned_content_patterns' => [],
                'top_attention_assets' => [],
                'weak_or_missing_territories' => ['Social evidence is collected when platform actors return readable posts, videos, tweets or public visibility items.'],
            ],
            'review_and_trust_readout' => [
                'positive_proof' => [],
                'friction_points' => [],
                'trust_gap' => [],
                'reassurance_opportunities' => [],
            ],
            'competitor_gap_matrix' => [],
            'market_opportunities' => [],
            'opportunity_territories' => [],
            'data_health_findings' => [
                [
                    'title' => 'Evidence collected for analysis',
                    'type' => 'data_health',
                    'evidence_refs' => [],
                    'evidence' => $evidence_highlights ?: ['No strong evidence excerpts were available in this run.'],
                    'business_implication' => 'The strategy layer should reason over visible evidence instead of blind prompts.',
                    'recommended_action' => 'Check OpenAI configuration or retry with a stronger evidence pack.',
                    'confidence' => 'operational',
                ],
                [
                    'title' => 'Remaining blind spots',
                    'type' => 'data_health',
                    'evidence_refs' => [],
                    'evidence' => $evidence_pack['source_quality']['blindspots'] ?? [],
                    'business_implication' => 'Sources marked failed or partial should not be treated as real market evidence.',
                    'recommended_action' => 'Keep source status visible; treat failed, partial or zero-result platform actors as blind spots, not evidence.',
                    'confidence' => 'operational',
                ],
            ],
            'recommended_next_actions' => [
                'Configure/verify OpenAI API key and model if the AI layer did not run.',
                'Review social source status cards and retry failed or partial platform actors; Maps/review evidence is collected when a Google Maps URL is provided.',
                'Improve deck-ready HTML and optional PDF/PPT export after the evidence and strategy layers are stable.',
            ],
            'deck_outline' => [
                [
                    'slide_number' => 1,
                    'title' => 'Brand Audit Evidence Snapshot',
                    'main_message' => 'First real evidence layer collected for ' . $brand . '.',
                    'evidence_points' => [sprintf('%d usable sources', $usable), sprintf('%d partial sources', $partial), sprintf('%d failed sources', $failed)],
                    'visual_suggestion' => 'Source status cards by website/search/news/trends/social/maps.',
                ],
                [
                    'slide_number' => 2,
                    'title' => 'Evidence Pack Ready for Strategy AI',
                    'main_message' => 'The next step is to convert collected evidence into strategic diagnosis and recommendations.',
                    'evidence_points' => ['Owned web evidence', 'Search intent evidence', 'News/public web evidence', 'Trend interest evidence'],
                    'visual_suggestion' => 'Evidence pack to insight engine diagram.',
                ],
            ],
            'limitations' => ['Local fallback report. OpenAI did not provide the final strategic interpretation.', 'Social evidence is now collected through YouTube, TikTok, Instagram, X/Twitter, Facebook and LinkedIn public visibility sources when providers return usable data. Maps/review evidence is collected when a Google Maps URL is provided and the provider returns usable data.'],
        ];
        $analysis_text = $analysis_structured['executive_summary'];
        $ai_meta = ['ai_used' => false, 'ai_model' => '', 'ai_error' => ''];
        $bundle_user_id = max(0, (int) ($bundle['user_id'] ?? 0));
        if ($bundle_user_id <= 0 && function_exists('get_current_user_id')) { $bundle_user_id = (int) get_current_user_id(); }
        $workspace_id = class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'infer_workspace_id')
            ? (int) VES_Memory_Records::infer_workspace_id(array_merge((array) $request, ['user_id' => $bundle_user_id]), $bundle_user_id)
            : $bundle_user_id;
        $project_id = class_exists('VES_Projects') ? (int) VES_Projects::resolve_project_from_request(array_merge((array) $request, ['user_id' => $bundle_user_id, 'workspace_id' => $workspace_id]), $bundle_user_id, $workspace_id) : 0;
        $competitors_for_ai = self::brand_audit_competitor_names($request);
        $platforms_for_ai = self::brand_audit_platforms_from_sources($sources);
        $memory_args = [
            'workspace_id' => $workspace_id,
            'project_id' => $project_id,
            'user_id' => $bundle_user_id,
            'brand_name' => $brand,
            'competitors' => $competitors_for_ai,
            'country' => $request['country'] ?? '',
            'industry' => $request['industry'] ?? '',
            'research_goal' => $request['audit_goal'] ?? '',
            'audit_goal' => $request['audit_goal'] ?? '',
            'platforms' => $platforms_for_ai,
            'run_type' => 'brand_audit',
            'context_type' => 'brand_audit_final_analysis',
        ];
        $related_memory = [];
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'build_prompt_pack')) {
            $memory_pack = VES_Memory_Records::build_prompt_pack($memory_args, 8);
            $memory_records = is_array($memory_pack['records'] ?? null) ? $memory_pack['records'] : [];
            if (!empty($memory_records)) {
                $related_memory[] = [
                    'entry_type' => 'persistent_workspace_memory',
                    'summary' => (string) ($memory_pack['summary'] ?? ''),
                    'records' => $memory_records,
                ];
            }
        }
        if (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'get_related_memory_context')) {
            $temp_memory = VES_Temp_Memory::get_related_memory_context($memory_args, 5);
            if (is_array($temp_memory) && !empty($temp_memory)) {
                $related_memory = array_merge($related_memory, $temp_memory);
            }
        }
        $project_context = null;
        if ($bundle_user_id > 0 && class_exists('VES_Project_Context') && method_exists('VES_Project_Context', 'build_ai_context_block')) {
            $project_context = VES_Project_Context::build_ai_context_block($bundle_user_id, $memory_args);
        }
        $request_for_ai = array_merge((array) $request, [
            'workspace_id' => $workspace_id,
            'project_id' => $project_id,
            'workspace_name' => $request['workspace_name'] ?? (class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace'),
            'competitors' => $competitors_for_ai,
            'platforms_collected' => $platforms_for_ai,
            'run_type' => 'brand_audit',
        ]);

        $evidence_summary = [];
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'store_brand_audit_evidence_pack')) {
            $evidence_summary = VES_Evidence_Store::store_brand_audit_evidence_pack($bundle_id, $bundle_id, array_merge($request_for_ai, ['user_id' => $bundle_user_id, 'workspace_id' => $workspace_id, 'project_id' => $project_id]), $evidence_pack, $sources);
            if (is_array($evidence_summary)) {
                $evidence_summary['source_health'] = $summary;
                $evidence_pack['_normalized_evidence_summary'] = $evidence_summary;
                $request_for_ai['evidence_summary'] = $evidence_summary;
                if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                    VES_Usage_Billing::record_meter_event($bundle_user_id, 'evidence_items_stored', 'brand_audit', $bundle_id, 'Normalized evidence stored', [
                        'count' => (int) ($evidence_summary['stored'] ?? $evidence_summary['total'] ?? 0),
                        'workspace_id' => $workspace_id,
                        'project_id' => $project_id,
                    ]);
                }
            }
            if (method_exists('VES_Evidence_Store', 'prompt_items')) {
                $request_for_ai['evidence_items_compact'] = VES_Evidence_Store::prompt_items($bundle_id, 80);
                $request_for_ai['allowed_evidence_refs'] = method_exists('VES_Evidence_Store', 'evidence_refs_for_run') ? VES_Evidence_Store::evidence_refs_for_run($bundle_id) : [];
                $evidence_pack['_evidence_items_compact'] = $request_for_ai['evidence_items_compact'];
                $evidence_pack['_allowed_evidence_refs'] = $request_for_ai['allowed_evidence_refs'];
            }
        }

        $metric_pipeline = ['stored' => 0, 'metrics' => []];
        if (class_exists('VES_Metric_Snapshots') && method_exists('VES_Metric_Snapshots', 'calculate_from_evidence')) {
            $metric_pipeline = VES_Metric_Snapshots::calculate_from_evidence($bundle_id, $workspace_id, $bundle_user_id, $request_for_ai, $evidence_summary);
            $request_for_ai['metric_snapshots'] = $metric_pipeline['metrics'] ?? [];
            $evidence_pack['_metric_snapshots'] = $request_for_ai['metric_snapshots'];
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                VES_Usage_Billing::record_meter_event($bundle_user_id, 'metric_snapshots_created', 'brand_audit', $bundle_id, 'Metric snapshots calculated', [
                    'stored' => (int) ($metric_pipeline['stored'] ?? 0),
                    'workspace_id' => $workspace_id,
                ]);
            }
        }
        $pattern_pipeline = ['stored' => 0, 'patterns' => []];
        if (class_exists('VES_Pattern_Candidates') && method_exists('VES_Pattern_Candidates', 'extract_from_evidence')) {
            $pattern_pipeline = VES_Pattern_Candidates::extract_from_evidence($bundle_id, $workspace_id, $bundle_user_id, $request_for_ai, $evidence_summary);
            $request_for_ai['pattern_candidates'] = $pattern_pipeline['patterns'] ?? [];
            $evidence_pack['_pattern_candidates'] = $request_for_ai['pattern_candidates'];
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                VES_Usage_Billing::record_meter_event($bundle_user_id, 'pattern_candidates_created', 'brand_audit', $bundle_id, 'Pattern candidates extracted', [
                    'stored' => (int) ($pattern_pipeline['stored'] ?? 0),
                    'workspace_id' => $workspace_id,
                ]);
            }
        }

        if ($usable > 0 && class_exists('VES_OpenAI_Client') && method_exists('VES_OpenAI_Client', 'analyze_brand_audit_bundle') && class_exists('VES_Config') && VES_Config::get_openai_api_key() !== '') {
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                VES_Usage_Billing::record_meter_event($bundle_user_id, 'brand_audit_final_ai_analysis', 'brand_audit', $bundle_id, 'Brand Audit final AI analysis requested', [
                    'workspace_id' => $workspace_id,
                    'memory_context_used' => !empty($related_memory),
                    'project_context_used' => !empty($project_context),
                ]);
            }
            $ai_result = VES_OpenAI_Client::analyze_brand_audit_bundle($request_for_ai, $evidence_pack, $related_memory, $project_context);
            if (!is_wp_error($ai_result) && is_array($ai_result) && !empty($ai_result['analysis_structured']) && is_array($ai_result['analysis_structured'])) {
                $analysis_structured = $ai_result['analysis_structured'];
                if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'validate_ai_reference_payload')) {
                    $analysis_structured = VES_Evidence_Store::validate_ai_reference_payload($bundle_id, $analysis_structured);
                }
                $analysis_text = !empty($ai_result['analysis']) ? (string) $ai_result['analysis'] : (string) ($analysis_structured['executive_summary'] ?? $analysis_text);
                $ai_meta = [
                    'ai_used' => true,
                    'ai_model' => sanitize_text_field((string) ($ai_result['model'] ?? '')),
                    'prompt_version' => sanitize_text_field((string) ($ai_result['prompt_version'] ?? '')),
                    'input_hash' => sanitize_text_field((string) ($ai_result['input_hash'] ?? '')),
                    'ai_error' => '',
                ];
            } else {
                $error_message = is_wp_error($ai_result) ? $ai_result->get_error_message() : 'OpenAI returned an invalid Brand Audit structure.';
                $ai_meta['ai_error'] = sanitize_text_field((string) $error_message);
                if (!isset($analysis_structured['limitations']) || !is_array($analysis_structured['limitations'])) { $analysis_structured['limitations'] = []; }
                $analysis_structured['limitations'][] = 'AI strategy layer unavailable. The report uses local fallback interpretation.';
                $analysis_structured['executive_summary'] = trim((string) ($analysis_structured['executive_summary'] ?? '')) . ' AI strategy layer unavailable; this report uses local fallback interpretation.';
                $analysis_text = $analysis_structured['executive_summary'];
                if (class_exists('VES_Admin')) {
                    VES_Admin::record_diagnostic('brand_audit_openai_failed', $ai_meta['ai_error'], ['brand' => $brand, 'bundle_id' => $bundle_id]);
                }
            }
        } elseif ($usable > 0) {
            $ai_meta['ai_error'] = 'OpenAI API key is not configured.';
            if (!isset($analysis_structured['limitations']) || !is_array($analysis_structured['limitations'])) { $analysis_structured['limitations'] = []; }
            $analysis_structured['limitations'][] = 'AI strategy layer unavailable. The report uses local fallback interpretation.';
            $analysis_structured['executive_summary'] = trim((string) ($analysis_structured['executive_summary'] ?? '')) . ' AI strategy layer unavailable; this report uses local fallback interpretation.';
            $analysis_text = $analysis_structured['executive_summary'];
        }

        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'validate_ai_reference_payload')) {
            $analysis_structured = VES_Evidence_Store::validate_ai_reference_payload($bundle_id, $analysis_structured);
        }
        $ai_success = !empty($ai_meta['ai_used']);
        $summary['openai_failed'] = !$ai_success;
        $confidence_gate = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::confidence_gate($summary, ['ai_success' => $ai_success]) : $confidence_gate;
        $confidence_mode = (string) ($confidence_gate['level'] ?? $confidence_mode);
        $market_opportunities = array_values(is_array($analysis_structured['market_opportunities'] ?? null) ? $analysis_structured['market_opportunities'] : (is_array($analysis_structured['opportunity_territories'] ?? null) && $ai_success ? $analysis_structured['opportunity_territories'] : []));
        $market_opportunities = array_values(array_filter($market_opportunities, static function($item) {
            return is_array($item) && (!empty($item['evidence_refs']) || !empty($item['evidence']));
        }));
        if (!$ai_success) { $market_opportunities = []; }
        $data_health_findings = array_values(is_array($analysis_structured['data_health_findings'] ?? null) ? $analysis_structured['data_health_findings'] : []);
        $analysis_structured['market_opportunities'] = $market_opportunities;
        $analysis_structured['opportunity_territories'] = $market_opportunities;
        $analysis_structured['data_health_findings'] = $data_health_findings;
        if (!$ai_success) {
            $analysis_structured['insights'] = [];
            $analysis_structured['validated_insights'] = [];
        }
        $compact_evidence_pack_for_final = self::compact_evidence_pack_for_final_payload($evidence_pack);

        $run_status = ($usable > 0 && (($partial + $failed) > 0 || $confidence_mode !== 'validated')) ? 'partial' : ($usable > 0 ? 'completed' : 'partial');
        $report_status_label = $run_status === 'partial' ? 'Completed with partial evidence' : 'Completed';
        $final = [
            'platform' => 'brand_audit',
            'status' => 'SUCCEEDED',
            'run_status' => $run_status,
            'report_status_label' => $report_status_label,
            'confidence_mode' => $confidence_mode,
            'confidence_gate' => $confidence_gate,
            'strategic_confidence' => $confidence_gate,
            'requested' => [
                'brand_name' => $brand,
                'website_url' => $request['website_url'] ?? '',
                'country' => $request['country'] ?? '',
                'language' => $request['language'] ?? '',
                'industry' => $request['industry'] ?? '',
                'depth' => $request['depth'] ?? 'standard',
                'output_type' => $request['output_type'] ?? 'report_deck',
            ],
            'analysis' => $analysis_text,
            'analysis_structured' => $analysis_structured,
            'market_opportunities' => $market_opportunities,
            'data_health_findings' => $data_health_findings,
            'source_health_summary' => $summary,
            'project_id' => $project_id,
            'provider_cost' => is_array($bundle['provider_cost'] ?? null) ? $bundle['provider_cost'] : [],
            'report' => ['title' => 'Brand Deep Audit - ' . $brand, 'summary' => $analysis_structured['executive_summary']],
            'deck' => is_array($analysis_structured['deck_outline'] ?? null) ? $analysis_structured['deck_outline'] : [],
            'evidence_pack' => $compact_evidence_pack_for_final,
            'sources' => $sources,
            'usable_sources' => $usable,
            'partial_sources' => $partial,
            'failed_sources' => $failed,
            'total_sources' => (int) ($summary['total_sources'] ?? 0),
            'generated_at' => self::now_mysql(),
            'bundle_id' => $bundle_id,
            'ai_used' => !empty($ai_meta['ai_used']),
            'ai_model' => $ai_meta['ai_model'] ?? '',
            'prompt_version' => $ai_meta['prompt_version'] ?? (class_exists('VES_Brand_Audit_Report_Schema') ? VES_Brand_Audit_Report_Schema::PROMPT_VERSION : ''),
            'input_hash' => $ai_meta['input_hash'] ?? '',
            'ai_error' => $ai_meta['ai_error'] ?? '',
        ];

        if (class_exists('VES_Brand_Audit_Run_Store')) { VES_Brand_Audit_Run_Store::update_evidence_pack($bundle_id, $compact_evidence_pack_for_final); }

        if (empty($evidence_summary) && class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'store_brand_audit_evidence_pack')) {
            $evidence_summary = VES_Evidence_Store::store_brand_audit_evidence_pack($bundle_id, $bundle_id, array_merge($request, ['user_id' => $bundle_user_id, 'workspace_id' => $workspace_id, 'project_id' => $project_id]), $evidence_pack, $sources);
        }
        if (is_array($evidence_summary) && !empty($evidence_summary)) {
            $final['evidence_summary'] = $evidence_summary;
            $final['workspace_id'] = $workspace_id;
            $final['project_id'] = $project_id;
            $final['memory_context_used'] = !empty($related_memory);
            $final['project_context_used'] = !empty($project_context);
        }

        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'validate_ai_reference_payload')) {
            $analysis_structured = VES_Evidence_Store::validate_ai_reference_payload($bundle_id, $analysis_structured);
            $final['analysis_structured'] = $analysis_structured;
        }
        if (class_exists('VES_Brand_Audit_Report_Schema')) {
            $evidence_items_for_report = class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'prompt_items') ? VES_Evidence_Store::prompt_items($bundle_id, 80) : [];
            $report_schema = VES_Brand_Audit_Report_Schema::normalize($analysis_structured, [
                'request' => $request_for_ai,
                'model' => (string) ($final['ai_model'] ?? ''),
                'prompt_version' => (string) ($final['prompt_version'] ?? ''),
                'input_hash' => (string) ($final['input_hash'] ?? ''),
                'generated_at' => (string) ($final['generated_at'] ?? self::now_mysql()),
                'confidence' => (float) (($confidence_gate['score'] ?? 0) / 100),
            ], $evidence_summary, $evidence_items_for_report);
            $analysis_structured['report_schema'] = $report_schema;
            $final['report_schema'] = $report_schema;
            $final['analysis_structured'] = $analysis_structured;
            $final['report'] = [
                'title' => 'Brand Deep Audit - ' . $brand,
                'summary' => (string) ($report_schema['executive_verdict']['summary'] ?? $final['analysis']),
                'status' => (string) ($report_schema['report_meta']['status'] ?? $final['run_status']),
            ];
        }

        $intelligence_records = [
            'metrics' => [
                'stored' => (int) ($metric_pipeline['stored'] ?? 0),
                'records' => class_exists('VES_Metric_Snapshots') ? VES_Metric_Snapshots::list_by_run($bundle_id, 80) : [],
            ],
            'patterns' => [
                'stored' => (int) ($pattern_pipeline['stored'] ?? 0),
                'records' => class_exists('VES_Pattern_Candidates') ? VES_Pattern_Candidates::list_by_run($bundle_id, 80) : [],
            ],
            'insights' => ['stored' => 0, 'records' => []],
            'opportunities' => ['stored' => 0, 'records' => []],
        ];
        if (!empty($ai_meta['ai_used']) && class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'create_from_final_ai_output')) {
            $intelligence_records['insights'] = VES_Insight_Records::create_from_final_ai_output($bundle_id, $workspace_id, $bundle_user_id, $request_for_ai, $analysis_structured);
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                VES_Usage_Billing::record_meter_event($bundle_user_id, 'insight_records_created', 'brand_audit', $bundle_id, 'Insight records persisted', [
                    'stored' => (int) ($intelligence_records['insights']['stored'] ?? 0),
                    'workspace_id' => $workspace_id,
                ]);
            }
        }
        if (!empty($ai_meta['ai_used']) && class_exists('VES_Opportunity_Records') && method_exists('VES_Opportunity_Records', 'create_from_final_ai_output')) {
            $intelligence_records['opportunities'] = VES_Opportunity_Records::create_from_final_ai_output($bundle_id, $workspace_id, $bundle_user_id, $request_for_ai, $analysis_structured, $intelligence_records['insights']['records'] ?? []);
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
                VES_Usage_Billing::record_meter_event($bundle_user_id, 'opportunity_records_created', 'brand_audit', $bundle_id, 'Opportunity records persisted', [
                    'stored' => (int) ($intelligence_records['opportunities']['stored'] ?? 0),
                    'workspace_id' => $workspace_id,
                ]);
            }
        }
        $final['intelligence_records'] = $intelligence_records;
        $final['metric_snapshots'] = array_values(is_array($intelligence_records['metrics']['records'] ?? null) ? $intelligence_records['metrics']['records'] : []);
        $final['pattern_candidates'] = array_values(is_array($intelligence_records['patterns']['records'] ?? null) ? $intelligence_records['patterns']['records'] : []);
        $final['insight_records'] = array_values(is_array($intelligence_records['insights']['records'] ?? null) ? $intelligence_records['insights']['records'] : []);
        $final['opportunity_records'] = array_values(is_array($intelligence_records['opportunities']['records'] ?? null) ? $intelligence_records['opportunities']['records'] : []);
        $final['brief_records'] = array_values(is_array($final['brief_records'] ?? null) ? $final['brief_records'] : []);
        $final['workflow_events'] = array_values(is_array($final['workflow_events'] ?? null) ? $final['workflow_events'] : []);

        $memory_entry_id = 0;
        if (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'save_brand_audit_report')) {
            $memory_entry_id = (int) VES_Temp_Memory::save_brand_audit_report($request, $final);
        }
        if ($memory_entry_id > 0) { $final['memory_entry_id'] = $memory_entry_id; }

        $persistent_memory_id = 0;
        $persistent_memory_ids = [];
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'create_records_from_brand_audit')) {
            $persistent_memory_ids = VES_Memory_Records::create_records_from_brand_audit($workspace_id, $bundle_user_id, array_merge($request, ['workspace_id' => $workspace_id, 'user_id' => $bundle_user_id]), $final);
            $persistent_memory_id = (int) ($persistent_memory_ids['research_summary'] ?? 0);
        } elseif (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'create_from_brand_audit')) {
            $persistent_memory_id = (int) VES_Memory_Records::create_from_brand_audit($workspace_id, $bundle_user_id, $request, $final);
        }
        if ($persistent_memory_id > 0) { $final['memory_record_id'] = $persistent_memory_id; }
        if (!empty($persistent_memory_ids)) { $final['memory_record_ids'] = $persistent_memory_ids; }
        if (!empty($persistent_memory_ids) && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $bundle_user_id > 0) {
            VES_Usage_Billing::record_meter_event($bundle_user_id, 'memory_record_created', 'brand_audit', $bundle_id, 'Brand Audit memory records created', [
                'count' => count($persistent_memory_ids),
                'workspace_id' => $workspace_id,
            ]);
        }

        if (class_exists('VES_Brand_Audit_Run_Store')) { VES_Brand_Audit_Run_Store::complete_run($bundle_id, $run_status, self::compact_final_payload_for_store($final), $confidence_mode, $memory_entry_id); }
        self::finalize_usage($bundle_id, $run_status, 'Brand Audit confirmado');
        $bundle['status'] = $run_status;
        $bundle['evidence_pack'] = $compact_evidence_pack_for_final;
        $bundle['final'] = self::compact_final_payload_for_store($final);
        $bundle['updated_at'] = self::now_mysql();
        self::save_bundle($bundle_id, $bundle);
        return $final;
    }

    private static function compact_evidence_pack_for_final_payload($value, $depth = 0) {
        if ($depth === 0 && class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'compact_evidence_pack_for_final_payload')) {
            return VES_Evidence_Store::compact_evidence_pack_for_final_payload(is_array($value) ? $value : []);
        }
        if ($depth > 6) { return '[truncated_depth]'; }
        if (is_scalar($value) || $value === null) {
            if (is_string($value) && strlen($value) > 6000) { return substr($value, 0, 6000); }
            return $value;
        }
        if (!is_array($value)) { return ''; }
        $drop_keys = ['raw_payload', 'raw_payload_json', 'raw_items', 'items_raw', 'dataset_items', 'html', 'body'];
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            $safe_key = is_int($key) ? '' : sanitize_key((string) $key);
            if ($safe_key !== '' && in_array($safe_key, $drop_keys, true)) { continue; }
            if ($i >= 120) { $out['_truncated'] = true; break; }
            $out[$key] = self::compact_evidence_pack_for_final_payload($item, $depth + 1);
            $i++;
        }
        return $out;
    }

    private static function compact_final_payload_for_store($final) {
        $final = is_array($final) ? $final : [];
        if (class_exists('VES_Evidence_Store') && method_exists('VES_Evidence_Store', 'compact_final_payload_for_store')) {
            return VES_Evidence_Store::compact_final_payload_for_store($final);
        }
        if (isset($final['evidence_pack'])) { $final['evidence_pack'] = self::compact_evidence_pack_for_final_payload($final['evidence_pack']); }
        return self::compact_evidence_pack_for_final_payload($final);
    }

    public static function abort_brand_audit_run($bundle_id, $message = '') {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) { return false; }
        foreach ((array) ($bundle['sources'] ?? []) as $source_key => $source) {
            if (!is_array($source)) { continue; }
            if (!in_array(strtoupper((string) ($source['status'] ?? '')), ['SUCCEEDED', 'FAILED', 'ABORTED'], true)) {
                if (!empty($source['run_id']) && class_exists('VES_Apify_Client')) { VES_Apify_Client::abort_run((string) $source['run_id']); }
                $source['status'] = 'ABORTED';
                $source['dispatch_status'] = 'aborted';
                $source['notes'] = $message !== '' ? $message : 'Abortado por el usuario.';
                $bundle['sources'][$source_key] = $source;
            }
        }
        $bundle['status'] = 'aborted';
        $bundle['final'] = ['platform' => 'brand_audit', 'status' => 'ABORTED', 'run_status' => 'aborted', 'analysis' => $message !== '' ? $message : 'Brand Audit abortado.', 'sources' => $bundle['sources'], 'bundle_id' => sanitize_text_field((string) $bundle_id)];
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            VES_Brand_Audit_Run_Store::update_sources($bundle_id, $bundle['sources'], 'aborted');
            VES_Brand_Audit_Run_Store::complete_run($bundle_id, 'aborted', $bundle['final'], 'insufficient_evidence', 0);
        }
        self::finalize_usage($bundle_id, 'aborted', 'Brand Audit abortado');
        return true;
    }

    public static function mark_failed($bundle_id, $message, $context = []) {
        $bundle = self::get_bundle($bundle_id);
        if (!$bundle) { return; }
        $bundle['status'] = 'failed';
        $bundle['final'] = ['platform' => 'brand_audit', 'status' => 'FAILED', 'run_status' => 'failed', 'analysis' => sanitize_text_field((string) $message), 'context' => is_array($context) ? $context : [], 'bundle_id' => sanitize_text_field((string) $bundle_id)];
        self::save_bundle($bundle_id, $bundle);
        if (class_exists('VES_Brand_Audit_Run_Store')) { VES_Brand_Audit_Run_Store::complete_run($bundle_id, 'failed', $bundle['final'], 'insufficient_evidence', 0); }
        self::finalize_usage($bundle_id, 'failed', $message);
    }

    public static function mark_failed_by_id($run_id, $message, $context = []) {
        if (!class_exists('VES_Brand_Audit_Run_Store')) { return; }
        $row = VES_Brand_Audit_Run_Store::get_run_by_id((int) $run_id);
        if (!empty($row['bundle_id'])) { self::mark_failed((string) $row['bundle_id'], $message, $context); }
    }
}
