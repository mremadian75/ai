<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Monitor_Runner {
    const SCAN_HOOK = 'ves_monitor_scan_due';
    const POLL_HOOK = 'ves_monitor_poll_pending';
    const PENDING_OPTION = 'ves_monitor_pending_jobs';
    const PENDING_LOCK_KEY = 'ves_monitor_pending_jobs_lock';
    const PENDING_LOCK_TTL = 120;

    public static function register_hooks() {
        add_filter('cron_schedules', [__CLASS__, 'register_schedules']);
        add_action(self::SCAN_HOOK, [__CLASS__, 'process_due_monitors']);
        add_action(self::POLL_HOOK, [__CLASS__, 'poll_pending_jobs']);
    }

    public static function register_schedules($schedules) {
        if (!isset($schedules['ves_five_minutes'])) {
            $schedules['ves_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'VE Every 5 Minutes',
            ];
        }
        if (!isset($schedules['ves_fifteen_minutes'])) {
            $schedules['ves_fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => 'VE Every 15 Minutes',
            ];
        }
        return $schedules;
    }

    public static function activate() {
        self::ensure_schedules();
    }

    public static function deactivate() {
        foreach ([self::SCAN_HOOK, self::POLL_HOOK] as $hook) {
            if (class_exists('VES_Queue')) {
                VES_Queue::cancel($hook);
            }
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    public static function ensure_schedules() {
        if (class_exists('VES_Queue')) {
            VES_Queue::schedule_recurring(self::SCAN_HOOK, 'ves_fifteen_minutes');
            VES_Queue::schedule_recurring(self::POLL_HOOK, 'ves_five_minutes');
            return;
        }
        if (!wp_next_scheduled(self::SCAN_HOOK)) {
            wp_schedule_event(time() + 120, 'ves_fifteen_minutes', self::SCAN_HOOK);
        }
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + 180, 'ves_five_minutes', self::POLL_HOOK);
        }
    }

    private static function acquire_pending_lock($timeout_seconds = 5) {
        $timeout_seconds = max(1, (int) $timeout_seconds);
        $token = wp_generate_password(24, false, false) . ':' . microtime(true);
        $deadline = microtime(true) + $timeout_seconds;

        do {
            $existing = get_option(self::PENDING_LOCK_KEY, null);
            if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] < time()) {
                delete_option(self::PENDING_LOCK_KEY);
            }

            $created = add_option(self::PENDING_LOCK_KEY, [
                'token' => $token,
                'expires_at' => time() + self::PENDING_LOCK_TTL,
                'created_at' => time(),
            ], '', 'no');

            if ($created) {
                return $token;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function release_pending_lock($token) {
        $token = (string) $token;
        if ($token === '') {
            return;
        }
        $existing = get_option(self::PENDING_LOCK_KEY, null);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
            delete_option(self::PENDING_LOCK_KEY);
        }
    }

    private static function get_pending_jobs() {
        $value = get_option(self::PENDING_OPTION, []);
        return is_array($value) ? $value : [];
    }

    private static function save_pending_jobs($jobs) {
        update_option(self::PENDING_OPTION, $jobs, false);
    }

    private static function make_job_id() {
        try {
            return 'vesjob-' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'vesjob-' . strtolower(wp_generate_password(16, false, false));
        }
    }

    private static function log($source, $message, $context = []) {
        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic($source, $message, $context);
        }
    }

    private static function source_terminal($status) {
        return in_array((string) $status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'CONFIG_ERROR', 'DISPATCH_ERROR'], true);
    }

    private static function start_source($source_key, $source) {
        $actor_slug = trim((string) ($source['actor_slug'] ?? ''));
        if ($actor_slug === '') {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => '',
                'status' => 'CONFIG_ERROR',
                'notes' => 'Actor slug no configurado.',
                'run_id' => '',
                'input' => $source['input'] ?? [],
            ];
        }
        $options = ves_prepare_run_options();
        $options['waitForFinish'] = 0;
        $url = ves_make_run_url($actor_slug, $options);
        $response = VES_Apify_Client::request('POST', $url, wp_json_encode($source['input'] ?? []));
        if (is_wp_error($response)) {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'status' => 'DISPATCH_ERROR',
                'notes' => $response->get_error_message(),
                'run_id' => '',
                'input' => $source['input'] ?? [],
            ];
        }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        return [
            'label' => $source['label'] ?? $source_key,
            'actor_slug' => $actor_slug,
            'status' => sanitize_text_field((string) ($run_body['data']['status'] ?? 'RUNNING')),
            'notes' => '',
            'run_id' => sanitize_text_field((string) ($run_body['data']['id'] ?? '')),
            'input' => $source['input'] ?? [],
        ];
    }

    public static function process_due_monitors() {
        if (!class_exists('VES_Monitor_Store')) {
            return;
        }
        $due = VES_Monitor_Store::get_due_monitors(3);
        if (empty($due)) {
            return;
        }
        foreach ($due as $monitor) {
            $reserved = VES_Monitor_Store::reserve_due_monitor($monitor['id']);
            if (!$reserved) {
                continue;
            }
            if (!empty($reserved['owner_user_id']) && class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel((int) $reserved['owner_user_id'])) {
                VES_Monitor_Store::fail_monitor_run($reserved['id'], 'El usuario ya no tiene acceso al panel del SaaS.');
                continue;
            }
            $created = class_exists('VES_Run_Execution_Service') ? VES_Run_Execution_Service::create_monitor_run_from_monitor($reserved) : 0;
            if (!$created) {
                VES_Monitor_Store::fail_monitor_run($reserved['id'], 'No se pudo crear el run en cola para este monitor.');
                continue;
            }
            self::log('monitor_dispatch', 'Background monitor enqueued', [
                'monitor_id' => $reserved['id'],
                'topic' => $reserved['topic'],
                'cadence' => $reserved['cadence'],
            ]);
        }
    }

    private static function build_ui_sources($sources, $request = []) {
        $ui_sources = [];
        foreach ($sources as $source_key => $source) {
            $status = (string) ($source['status'] ?? 'UNKNOWN');
            $raw_items = [];
            if ($status === 'SUCCEEDED' && !empty($source['run_id'])) {
                $raw_items = VES_Apify_Client::fetch_items($source['run_id'], 80);
                if (is_wp_error($raw_items)) {
                    $status = 'FAILED';
                    $source['status'] = 'FAILED';
                    $source['notes'] = $raw_items->get_error_message();
                    $raw_items = [];
                }
            }
            // v0.9.9.3: honour date_from on monitor runs too.
            $date_from_iso = (string) ($request['date_from'] ?? '');
            if ($date_from_iso !== '' && function_exists('ves_filter_items_by_date_from') && is_array($raw_items) && !empty($raw_items)) {
                list($raw_items, ) = ves_filter_items_by_date_from($raw_items, $date_from_iso);
            }
            if (class_exists('VES_Trend_Source_Evaluator')) {
                $ui_sources[$source_key] = VES_Trend_Source_Evaluator::evaluate_source($source_key, array_merge((array) $source, ['status' => $status]), $raw_items, $request);
            } else {
                $highlights = function_exists('ves_trend_extract_highlights') ? ves_trend_extract_highlights($source_key, $raw_items) : [];
                $compact_items = function_exists('ves_trend_compact_items_for_ai') ? ves_trend_compact_items_for_ai($source_key, $raw_items) : array_slice((array) $raw_items, 0, 20);
                $ui_sources[$source_key] = [
                    'label' => $source['label'] ?? $source_key,
                    'status' => $status,
                    'item_count' => is_array($raw_items) ? count($raw_items) : 0,
                    'notes' => $source['notes'] ?? '',
                    'highlights' => $highlights,
                    'compact_items' => $compact_items,
                    'analytical_usability_status' => $status === 'SUCCEEDED' ? 'usable' : 'failed',
                ];
            }
        }
        return $ui_sources;
    }

    private static function finalize_job($job) {

        $request = is_array($job['request'] ?? null) ? $job['request'] : [];
        $ui_sources = self::build_ui_sources($job['sources'] ?? [], $request);
        $owner_user_id = (int) ($job['owner_user_id'] ?? 0);
        $project_context = class_exists('VES_Project_Context') && $owner_user_id > 0 ? VES_Project_Context::build_ai_context_block($owner_user_id, [
            'platform' => 'trend_finder',
            'topic' => $request['topic'] ?? '',
            'focus' => $request['reason'] ?? '',
            'country' => $request['country'] ?? '',
            'reason' => $request['reason'] ?? '',
        ]) : null;
        $workspace_terms = [];
        foreach ((array) ($project_context['workspace_knowledge'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            foreach (['title', 'summary'] as $key) {
                if (!empty($item[$key])) {
                    $workspace_terms[] = (string) $item[$key];
                }
            }
            if (!empty($item['tags']) && is_array($item['tags'])) {
                $workspace_terms = array_merge($workspace_terms, array_map('strval', $item['tags']));
            }
        }
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
            'workspace_knowledge_terms' => array_slice(array_values(array_filter($workspace_terms)), 0, 16),
        ], 5) : [];
        $analysis = VES_OpenAI_Client::analyze_trend_bundle($request, $ui_sources, $related_memory, $project_context);
        if (is_wp_error($analysis)) {
            return $analysis;
        }
        $source_summary = class_exists('VES_Trend_Source_Evaluator') ? VES_Trend_Source_Evaluator::summarize_run_sources($ui_sources) : [
            'total_sources' => count($ui_sources),
            'usable_sources' => count($ui_sources),
            'partial_sources' => 0,
            'failed_sources' => 0,
            'metric_average' => 0,
        ];
        $run_status = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_status($source_summary) : 'completed';
        $confidence_mode = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_mode($source_summary) : 'validated';
        return [
            'platform' => 'trend_finder',
            'status' => 'SUCCEEDED',
            'run_status' => $run_status,
            'confidence_mode' => $confidence_mode,
            'analysis' => $analysis['analysis'],
            'analysis_structured' => $analysis['analysis_structured'] ?? null,
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
            'successful_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'usable_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'partial_sources' => (int) ($source_summary['partial_sources'] ?? 0),
            'failed_sources' => (int) ($source_summary['failed_sources'] ?? 0),
            'total_sources' => (int) ($source_summary['total_sources'] ?? 0),
            'generated_at' => current_time('mysql'),
            'project_context_used' => !empty($project_context),
            'related_reports_used' => is_array($related_memory) ? count($related_memory) : 0,
            'bundle_id' => $job['job_id'] ?? '',
        ];
    }

    private static function metric_from_report($final) {
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $scores = [];
        foreach ((array) ($structured['prioritized_trends'] ?? []) as $row) {
            if (isset($row['fit_score'])) {
                $scores[] = (float) $row['fit_score'];
            }
        }
        foreach ((array) ($structured['opportunity_board'] ?? []) as $row) {
            if (isset($row['opportunity_score'])) {
                $scores[] = (float) $row['opportunity_score'];
            }
        }
        if (empty($scores)) {
            return null;
        }
        return round(array_sum($scores) / count($scores), 1);
    }

    private static function best_score_from_report($final) {
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $best = 0;
        foreach ((array) ($structured['prioritized_trends'] ?? []) as $row) {
            $best = max($best, (int) ($row['fit_score'] ?? 0));
        }
        foreach ((array) ($structured['opportunity_board'] ?? []) as $row) {
            $best = max($best, (int) ($row['opportunity_score'] ?? 0));
        }
        return $best;
    }

    private static function top_trend_names($final, $limit = 3) {
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $names = [];
        foreach ((array) ($structured['prioritized_trends'] ?? []) as $row) {
            $name = sanitize_text_field((string) ($row['trend_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
            if (count($names) >= $limit) {
                break;
            }
        }
        return $names;
    }

    private static function send_alert_if_needed($monitor, $final, $snapshot_id, $comparison, $avg_score) {
        $threshold = max(1, min(10, (int) ($monitor['alert_threshold'] ?? 7)));
        $best_score = self::best_score_from_report($final);
        if ($best_score < $threshold && (float) $avg_score < $threshold) {
            return '';
        }
        $email = sanitize_email((string) ($monitor['owner_email'] ?? ''));
        if ($email === '' && !empty($monitor['owner_user_id'])) {
            $user = get_userdata((int) $monitor['owner_user_id']);
            if ($user instanceof WP_User) {
                $email = sanitize_email((string) $user->user_email);
            }
        }
        $top_trends = self::top_trend_names($final, 4);
        $subject = sprintf('[VE Social] Alert: %s alcanzó %d/10', sanitize_text_field((string) ($monitor['name'] ?? $monitor['topic'] ?? 'Monitor')), max($best_score, (int) round((float) $avg_score)));
        $lines = [
            'Tu monitor automático ha detectado una señal que supera el threshold configurado.',
            '',
            'Monitor: ' . sanitize_text_field((string) ($monitor['name'] ?? 'Monitor')),
            'Tema: ' . sanitize_text_field((string) ($monitor['topic'] ?? '')),
            'País: ' . sanitize_text_field((string) ($monitor['country'] ?? 'None')),
            'Rango: ' . sanitize_text_field((string) ($monitor['duration'] ?? '7d')),
            'Snapshot ID: #' . (int) $snapshot_id,
            'Best score: ' . $best_score . '/10',
            'Average score: ' . ($avg_score !== null ? $avg_score . '/10' : 'n/d'),
        ];
        if (!empty($top_trends)) {
            $lines[] = 'Top trends: ' . implode(', ', $top_trends);
        }
        if (!empty($comparison['new_trends'])) {
            $lines[] = 'Nuevas señales: ' . implode(', ', array_slice((array) $comparison['new_trends'], 0, 3));
        }
        $lines[] = '';
        $lines[] = 'Panel: ' . VES_Auth::get_dashboard_url();
        $message = implode("
", $lines);
        if ($email !== '') {
            wp_mail($email, $subject, $message);
        }
        self::log('monitor_alert', 'Background monitor alert sent', [
            'monitor_id' => $monitor['id'] ?? '',
            'snapshot_id' => $snapshot_id,
            'email' => $email !== '' ? 'sent' : 'missing',
            'best_score' => $best_score,
        ]);
        return 'Alert enviada: best ' . $best_score . '/10';
    }

    public static function poll_pending_jobs() {
        $token = self::acquire_pending_lock();
        if (!$token) {
            self::log('monitor_pending_lock', 'Skipped monitor pending poll because the pending-job lock is active.', []);
            return;
        }

        try {
        $jobs = self::get_pending_jobs();
        if (empty($jobs)) {
            return;
        }
        foreach ($jobs as $job_id => $job) {
            if (!is_array($job)) {
                unset($jobs[$job_id]);
                continue;
            }
            $all_terminal = true;
            foreach ((array) ($job['sources'] ?? []) as $source_key => $source) {
                $status = (string) ($source['status'] ?? 'UNKNOWN');
                if (self::source_terminal($status)) {
                    continue;
                }
                $run_id = sanitize_text_field((string) ($source['run_id'] ?? ''));
                if ($run_id === '') {
                    $job['sources'][$source_key]['status'] = 'FAILED';
                    $job['sources'][$source_key]['notes'] = 'Run ID ausente.';
                    continue;
                }
                $response = VES_Apify_Client::fetch_run($run_id, 0);
                if (is_wp_error($response)) {
                    $all_terminal = false;
                    continue;
                }
                $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
                $new_status = sanitize_text_field((string) ($run_body['data']['status'] ?? $status));
                $job['sources'][$source_key]['status'] = $new_status;
                $job['sources'][$source_key]['notes'] = sanitize_text_field((string) ($run_body['data']['statusMessage'] ?? ($source['notes'] ?? '')));
                if (!self::source_terminal($new_status)) {
                    $all_terminal = false;
                }
            }
            $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
            $job['updated_at'] = current_time('mysql');

            $age_seconds = time() - (strtotime((string) ($job['created_at'] ?? '')) ?: time());
            if (!$all_terminal && $age_seconds < 4 * HOUR_IN_SECONDS) {
                $jobs[$job_id] = $job;
                continue;
            }

            $monitor = class_exists('VES_Monitor_Store') ? VES_Monitor_Store::get_monitor_any_owner($job['monitor_id'] ?? '') : null;
            if (!$monitor) {
                unset($jobs[$job_id]);
                continue;
            }

            $final = self::finalize_job($job);
            if (is_wp_error($final)) {
                if (!empty($job['usage_key']) && class_exists('VES_Usage_Billing')) {
                    VES_Usage_Billing::void_reserved_usage((string) $job['usage_key'], $final->get_error_message());
                }
                VES_Monitor_Store::fail_monitor_run($monitor['id'], $final->get_error_message());
                self::log('monitor_finalize_error', $final->get_error_message(), ['monitor_id' => $monitor['id'], 'job_id' => $job_id]);
                unset($jobs[$job_id]);
                continue;
            }

            $memory_meta = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::save_trend_report_for_user((int) ($monitor['owner_user_id'] ?? 0), $job['request'] ?? [], $final) : [];
            if (!empty($job['usage_key']) && class_exists('VES_Usage_Billing')) {
                if (in_array((string) ($final['run_status'] ?? 'failed'), ['completed','partial'], true)) {
                    VES_Usage_Billing::post_reserved_usage((string) $job['usage_key'], 'Monitor automático confirmado');
                } else {
                    VES_Usage_Billing::void_reserved_usage((string) $job['usage_key'], 'Monitor sin evidencia utilizable');
                }
            }
            $snapshot_id = (int) ($memory_meta['id'] ?? 0);
            $comparison = !empty($memory_meta['search_hash']) && class_exists('VES_Temp_Memory')
                ? VES_Temp_Memory::build_trend_comparison_for_user((int) ($monitor['owner_user_id'] ?? 0), $final, (string) $memory_meta['search_hash'], $snapshot_id)
                : null;
            $avg_score = self::metric_from_report($final);
            $alert_message = self::send_alert_if_needed($monitor, $final, $snapshot_id, $comparison, $avg_score);
            $note = 'Snapshot #' . $snapshot_id . ' guardado automáticamente.';
            if ($avg_score !== null) {
                $note .= ' Score medio ' . $avg_score . '/10.';
            }
            if (!empty($comparison['new_trends'])) {
                $note .= ' Nuevas señales: ' . implode(', ', array_slice((array) $comparison['new_trends'], 0, 2)) . '.';
            }
            VES_Monitor_Store::complete_monitor_run($monitor['id'], [
                'last_status' => 'succeeded',
                'last_snapshot_id' => $snapshot_id,
                'last_average_score' => $avg_score,
                'last_run_note' => $note,
                'last_alert_message' => $alert_message,
            ]);
            self::log('monitor_finalize_ok', 'Background monitor finalized', [
                'monitor_id' => $monitor['id'],
                'snapshot_id' => $snapshot_id,
                'job_id' => $job_id,
                'avg_score' => $avg_score,
            ]);
            unset($jobs[$job_id]);
        }
        self::save_pending_jobs($jobs);
    
        } finally {
            self::release_pending_lock($token);
        }
    }
}
