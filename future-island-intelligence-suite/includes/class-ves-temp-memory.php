<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Temp_Memory {
    const TABLE_SLUG = 'ves_temp_memory';
    const CRON_HOOK  = 'ves_cleanup_temp_memory';
    const SEARCH_RETENTION_DAYS = 7;
    const TREND_RETENTION_DAYS  = 90;
    const DB_VERSION = '2.4';

    public static function register_hooks() {
        add_action(self::CRON_HOOK, [__CLASS__, 'cleanup_expired']);
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function maybe_set_session_cookie() {
        if (is_user_logged_in()) {
            return 'user:' . get_current_user_id();
        }

        $cookie_name = 'ves_temp_session';
        if (!empty($_COOKIE[$cookie_name])) {
            return sanitize_key((string) $_COOKIE[$cookie_name]);
        }

        $session = 'ves_' . strtolower(wp_generate_password(20, false, false));
        if (!headers_sent()) {
            setcookie($cookie_name, $session, [
                'expires'  => time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS),
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[$cookie_name] = $session;
        }
        return sanitize_key($session);
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function create_table() {
        $version_option = 'ves_temp_memory_db_version';
        if (get_option($version_option) === self::DB_VERSION && self::table_exists()) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NULL,
            session_key varchar(64) NOT NULL,
            entry_type varchar(24) NOT NULL DEFAULT 'search',
            platform varchar(32) NOT NULL,
            search_mode varchar(32) NOT NULL,
            search_hash varchar(64) NOT NULL,
            report_title varchar(190) NULL,
            targets longtext NULL,
            language_code varchar(16) NULL,
            country_code varchar(16) NULL,
            min_likes int(11) unsigned NOT NULL DEFAULT 0,
            run_id varchar(64) NULL,
            raw_items int(11) unsigned NOT NULL DEFAULT 0,
            filtered_items int(11) unsigned NOT NULL DEFAULT 0,
            results_json longtext NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY expires_at (expires_at),
            KEY search_hash (search_hash),
            KEY session_key (session_key),
            KEY user_id (user_id),
            KEY entry_type (entry_type),
            KEY platform (platform)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option($version_option, self::DB_VERSION, false);
    }

    public static function schedule_cleanup() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function clear_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function cleanup_expired() {
        if (!self::table_exists()) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", current_time('mysql', true)));
    }

    private static function current_scope() {
        return [
            'user_id' => is_user_logged_in() ? (int) get_current_user_id() : null,
            'session_key' => self::maybe_set_session_cookie(),
        ];
    }

    private static function build_scope_where($scope, &$params) {
        if (!empty($scope['user_id'])) {
            $params[] = (int) $scope['user_id'];
            return 'user_id = %d';
        }

        $params[] = (string) ($scope['session_key'] ?? '');
        return 'session_key = %s';
    }

    private static function prepare_search_payload($data) {
        $payload = is_array($data) ? $data : [];
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $payload['items'] = [];
        }

        if (count($payload['items']) > 150) {
            $payload['items'] = array_slice($payload['items'], 0, 150);
        }

        foreach ($payload['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['ves_transcript', 'transcript', 'captionText', 'subtitleText'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key]) && strlen($item[$key]) > 4000) {
                    $item[$key] = mb_substr($item[$key], 0, 4000) . '…';
                }
            }
        }
        unset($item);

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return wp_json_encode(['platform' => $payload['platform'] ?? '', 'items' => []]);
        }

        if (strlen($json) > 1800000) {
            $payload['items'] = array_map(static function ($item) {
                if (!is_array($item)) return $item;
                unset($item['ves_transcript'], $item['transcript'], $item['raw']);
                return $item;
            }, $payload['items']);
            $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        return is_string($json) ? $json : '{}';
    }

    private static function prepare_trend_payload($response) {
        $payload = is_array($response) ? $response : [];
        $prepared = [
            'platform' => 'trend_finder',
            'status' => sanitize_text_field((string) ($payload['status'] ?? 'SUCCEEDED')),
            'requested' => [
                'topic' => sanitize_text_field((string) ($payload['requested']['topic'] ?? '')),
                'country' => sanitize_text_field((string) ($payload['requested']['country'] ?? 'None')),
                'country_label' => sanitize_text_field((string) ($payload['requested']['country_label'] ?? 'Worldwide')),
                'duration' => sanitize_key((string) ($payload['requested']['duration'] ?? '7d')),
                'duration_label' => sanitize_text_field((string) ($payload['requested']['duration_label'] ?? '7d')),
                'reason' => sanitize_textarea_field((string) ($payload['requested']['reason'] ?? '')),
            ],
            'successful_sources' => (int) ($payload['successful_sources'] ?? 0),
            'usable_sources' => (int) ($payload['usable_sources'] ?? ($payload['successful_sources'] ?? 0)),
            'partial_sources' => (int) ($payload['partial_sources'] ?? 0),
            'failed_sources' => (int) ($payload['failed_sources'] ?? 0),
            'total_sources' => (int) ($payload['total_sources'] ?? 0),
            'confidence_mode' => sanitize_key((string) ($payload['confidence_mode'] ?? '')),
            'run_status' => sanitize_key((string) ($payload['run_status'] ?? '')),
            'fallback_used' => !empty($payload['fallback_used']),
            'validation_required' => !empty($payload['validation_required']),
            'can_generate_brief' => !array_key_exists('can_generate_brief', $payload) || !empty($payload['can_generate_brief']),
            'memory_type' => self::safe_payload_memory_type($payload),
            'report_status' => sanitize_key((string) ($payload['report_status'] ?? ($payload['run_status'] ?? ''))),
            'report_mode' => sanitize_key((string) ($payload['report_mode'] ?? '')),
            'brief_readiness_status' => sanitize_key((string) ($payload['brief_readiness_status'] ?? '')),
            'can_generate_test_brief' => !empty($payload['can_generate_test_brief']),
            'reusable_as_insight' => in_array(sanitize_key((string) ($payload['report_mode'] ?? '')), ['trend_confirmed','trend_emerging'], true),
            'usable_for_context' => !in_array(sanitize_key((string) ($payload['report_mode'] ?? '')), ['no_actionable_trend_found','source_failure_partial_report'], true),
            'memory_status' => sanitize_key((string) ($payload['memory_status'] ?? (class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::memory_status_for_payload($payload) : 'validated'))),
            'needs_repair' => !empty($payload['needs_repair']),
            'evidence_coverage_summary' => is_array($payload['evidence_coverage_summary'] ?? null) ? $payload['evidence_coverage_summary'] : [],
            'source_quality_breakdown' => is_array($payload['source_quality_breakdown'] ?? null) ? $payload['source_quality_breakdown'] : [],
            'insight_ids' => array_values(array_map('intval', (array) ($payload['insight_ids'] ?? []))),
            'generated_at' => sanitize_text_field((string) ($payload['generated_at'] ?? current_time('mysql'))),
            'model' => sanitize_text_field((string) ($payload['model'] ?? '')),
            'analysis_format' => sanitize_text_field((string) ($payload['analysis_format'] ?? 'text')),
            'analysis' => is_string($payload['analysis'] ?? null) ? mb_substr($payload['analysis'], 0, 24000) : '',
            'analysis_structured' => is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : null,
            'sources' => [],
        ];
        if (!empty($payload['_backfill']) && is_array($payload['_backfill'])) {
            $prepared['_backfill'] = [
                'run_id' => (int) ($payload['_backfill']['run_id'] ?? 0),
                'job_id' => sanitize_text_field((string) ($payload['_backfill']['job_id'] ?? '')),
                'linked_at' => sanitize_text_field((string) ($payload['_backfill']['linked_at'] ?? '')),
            ];
        }

        $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
        foreach ($sources as $key => $source) {
            if (!is_array($source)) {
                continue;
            }
            $prepared['sources'][$key] = [
                'label' => sanitize_text_field((string) ($source['label'] ?? $key)),
                'status' => sanitize_text_field((string) ($source['status'] ?? 'UNKNOWN')),
                'item_count' => (int) ($source['item_count'] ?? 0),
                'notes' => sanitize_text_field((string) ($source['notes'] ?? '')),
                'analytical_usability_status' => sanitize_key((string) ($source['analytical_usability_status'] ?? '')),
                'evidence_role' => sanitize_key((string) ($source['evidence_role'] ?? '')),
                'output_validation_status' => sanitize_key((string) ($source['output_validation_status'] ?? '')),
                'usable_records_count' => (int) ($source['usable_records_count'] ?? 0),
                'valid_records_count' => (int) ($source['valid_records_count'] ?? 0),
                'rejected_records_count' => (int) ($source['rejected_records_count'] ?? 0),
                'direct_match_count' => (int) ($source['direct_match_count'] ?? 0),
                'adjacent_match_count' => (int) ($source['adjacent_match_count'] ?? 0),
                'source_quality_class' => sanitize_key((string) ($source['source_quality_class'] ?? '')),
                'source_quality_classification' => sanitize_key((string) ($source['source_quality_classification'] ?? ($source['source_quality_class'] ?? ''))),
                'source_family' => sanitize_key((string) ($source['source_family'] ?? '')),
                'source_role' => sanitize_key((string) ($source['source_role'] ?? '')),
                'source_status' => sanitize_key((string) ($source['source_status'] ?? '')),
                'ambient_signals' => (int) ($source['ambient_signals'] ?? 0),
                'format_signals' => (int) ($source['format_signals'] ?? 0),
                'pain_point_signals' => (int) ($source['pain_point_signals'] ?? 0),
                'discarded_rows' => (int) ($source['discarded_rows'] ?? 0),
                'dispatch_status' => sanitize_key((string) ($source['dispatch_status'] ?? '')),
                'exclusion_reason' => sanitize_text_field((string) ($source['exclusion_reason'] ?? '')),
                'metric_completeness_score' => isset($source['metric_completeness_score']) ? round((float) $source['metric_completeness_score'], 2) : 0,
                'geo_fit_score' => isset($source['geo_fit_score']) ? round((float) $source['geo_fit_score'], 2) : 0,
                'language_fit_score' => isset($source['language_fit_score']) ? round((float) $source['language_fit_score'], 2) : 0,
                'highlights' => array_values(array_slice(array_filter(array_map('sanitize_text_field', (array) ($source['highlights'] ?? []))), 0, 10)),
            ];
        }

        if (class_exists('VES_Trend_Label_Guard')) {
            $prepared['memory_status'] = VES_Trend_Label_Guard::memory_status_for_payload($prepared);
            $prepared['needs_repair'] = $prepared['memory_status'] === 'corrupted_legacy';
            if (!empty($prepared['analysis_structured']['prioritized_trends']) && is_array($prepared['analysis_structured']['prioritized_trends'])) {
                $removed_labels = [];
                $prepared['analysis_structured']['prioritized_trends'] = VES_Trend_Label_Guard::sanitize_prioritized_trends($prepared['analysis_structured']['prioritized_trends'], $removed_labels);
                if (!empty($removed_labels)) {
                    $prepared['memory_status'] = 'corrupted_legacy';
                    $prepared['needs_repair'] = true;
                    $prepared['corrupted_labels'] = array_values(array_slice($removed_labels, 0, 20));
                }
            }
        }

        $json = wp_json_encode($prepared, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '{}';
        }

        if (strlen($json) > 1800000) {
            if (is_array($prepared['analysis_structured'])) {
                foreach (['channel_readouts', 'prioritized_trends'] as $key) {
                    if (isset($prepared['analysis_structured'][$key]) && is_array($prepared['analysis_structured'][$key])) {
                        $prepared['analysis_structured'][$key] = array_slice($prepared['analysis_structured'][$key], 0, 8);
                    }
                }
            }
            $prepared['analysis'] = mb_substr((string) $prepared['analysis'], 0, 12000);
            $json = wp_json_encode($prepared, JSON_UNESCAPED_UNICODE);
        }

        return is_string($json) ? $json : '{}';
    }

    private static function decode_results_json($json) {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function insert_row($row) {
        global $wpdb;
        if (!self::table_exists()) {
            self::create_table();
        }
        $table = self::table_name();
        $wpdb->insert($table, $row, [
            '%d', // user_id
            '%s', // session_key
            '%s', // entry_type
            '%s', // platform
            '%s', // search_mode
            '%s', // search_hash
            '%s', // report_title
            '%s', // targets
            '%s', // language_code
            '%s', // country_code
            '%d', // min_likes
            '%s', // run_id
            '%d', // raw_items
            '%d', // filtered_items
            '%s', // results_json
            '%s', // created_at
            '%s', // expires_at
        ]);
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public static function save_search($platform, $src, $response) {
        $response = is_array($response) ? $response : [];
        $run_status = sanitize_key((string) ($response['run_lifecycle_status'] ?? $response['status'] ?? ''));
        $raw_rows = (int) ($response['raw_items'] ?? $response['raw_provider_returned_count'] ?? $response['item_count_preview'] ?? 0);
        $useful_rows = (int) ($response['useful_delivered_rows'] ?? $response['filtered_items'] ?? (is_array($response['items'] ?? null) ? count($response['items']) : 0));
        $adapter_status = sanitize_key((string) ($response['adapter_status'] ?? ''));
        if (in_array($run_status, ['failed','aborted','timed-out','timeout'], true) || $adapter_status === 'provider_rows_unusable_or_unmapped' || ($raw_rows <= 0 && $useful_rows <= 0)) {
            return 0;
        }
        if ($useful_rows <= 0 && !in_array($adapter_status, ['ok',''], true)) {
            return 0;
        }
        $scope = self::current_scope();
        $targets = isset($src['targets']) ? sanitize_textarea_field(wp_unslash((string) $src['targets'])) : '';
        $mode = sanitize_key((string) ($src['mode'] ?? ''));
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : '';
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($src['country'] ?? '') : '';
        $min_likes = function_exists('ves_requested_min_metric') ? (int) ves_requested_min_metric($src) : (function_exists('ves_clean_int') ? (int) ves_clean_int($src['minViews'] ?? ($src['minLikes'] ?? null), 0, null) : 0);
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::SEARCH_RETENTION_DAYS * DAY_IN_SECONDS));

        $search_fingerprint = [
            'platform'  => sanitize_key((string) $platform),
            'mode'      => $mode,
            'targets'   => $targets,
            'language'  => $language,
            'country'   => $country,
            'minLikes'  => $min_likes,
        ];
        $search_hash = hash('sha256', wp_json_encode($search_fingerprint));
        $results_json = self::prepare_search_payload($response);

        $entry_id = self::insert_row([
            'user_id'        => $scope['user_id'],
            'session_key'    => $scope['session_key'],
            'entry_type'     => 'search',
            'platform'       => sanitize_key((string) $platform),
            'search_mode'    => $mode,
            'search_hash'    => $search_hash,
            'report_title'   => null,
            'targets'        => $targets,
            'language_code'  => $language,
            'country_code'   => $country,
            'min_likes'      => $min_likes,
            'run_id'         => sanitize_text_field((string) ($response['run_id'] ?? '')),
            'raw_items'      => (int) ($response['raw_items'] ?? 0),
            'filtered_items' => (int) ($response['filtered_items'] ?? 0),
            'results_json'   => $results_json,
            'created_at'     => $created_at,
            'expires_at'     => $expires_at,
        ]);

        self::cleanup_expired();
        return (int) $entry_id;
    }

    public static function save_trend_report($request, $response) {
        $scope = self::current_scope();
        $topic = sanitize_text_field((string) ($request['topic'] ?? ''));
        $country = sanitize_text_field((string) ($request['country'] ?? 'None'));
        $duration = sanitize_key((string) ($request['duration'] ?? '7d'));
        $reason = sanitize_textarea_field((string) ($request['reason'] ?? ''));
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS));

        $fingerprint = [
            'platform' => 'trend_finder',
            'topic' => mb_strtolower($topic),
            'country' => $country,
            'duration' => $duration,
        ];
        $search_hash = hash('sha256', wp_json_encode($fingerprint));
        $sources = is_array($response['sources'] ?? null) ? $response['sources'] : [];
        $raw_items = 0;
        foreach ($sources as $source) {
            $raw_items += (int) ($source['item_count'] ?? 0);
        }

        $report_id = self::insert_row([
            'user_id'        => $scope['user_id'],
            'session_key'    => $scope['session_key'],
            'entry_type'     => 'trend_report',
            'platform'       => 'trend_finder',
            'search_mode'    => 'trend',
            'search_hash'    => $search_hash,
            'report_title'   => $topic !== '' ? $topic : 'Trend Finder',
            'targets'        => $reason,
            'language_code'  => null,
            'country_code'   => $country,
            'min_likes'      => 0,
            'run_id'         => sanitize_text_field((string) ($response['bundle_id'] ?? '')),
            'raw_items'      => $raw_items,
            'filtered_items' => (int) ($response['usable_sources'] ?? ($response['successful_sources'] ?? 0)),
            'results_json'   => self::prepare_trend_payload($response),
            'created_at'     => $created_at,
            'expires_at'     => $expires_at,
        ]);

        self::cleanup_expired();

        return [
            'id' => $report_id,
            'search_hash' => $search_hash,
        ];
    }

    public static function save_brand_audit_report($request, $response) {
        $scope = self::current_scope();
        $request = is_array($request) ? $request : [];
        $response = is_array($response) ? $response : [];
        $brand = sanitize_text_field((string) ($request['brand_name'] ?? 'Brand Audit'));
        $country = sanitize_text_field((string) ($request['country'] ?? ''));
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS));
        $payload = [
            'platform' => 'brand_audit',
            'status' => sanitize_text_field((string) ($response['status'] ?? 'SUCCEEDED')),
            'requested' => is_array($response['requested'] ?? null) ? $response['requested'] : $request,
            'analysis' => is_string($response['analysis'] ?? null) ? mb_substr($response['analysis'], 0, 24000) : '',
            'analysis_structured' => is_array($response['analysis_structured'] ?? null) ? $response['analysis_structured'] : [],
            'report' => is_array($response['report'] ?? null) ? $response['report'] : [],
            'deck' => is_array($response['deck'] ?? null) ? $response['deck'] : [],
            'evidence_pack' => is_array($response['evidence_pack'] ?? null) ? $response['evidence_pack'] : [],
            'sources' => is_array($response['sources'] ?? null) ? $response['sources'] : [],
            'generated_at' => sanitize_text_field((string) ($response['generated_at'] ?? current_time('mysql'))),
        ];
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }
        if (strlen($json) > 1800000) {
            unset($payload['evidence_pack']);
            $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
            $json = is_string($json) ? $json : '{}';
        }
        $search_hash = hash('sha256', wp_json_encode([
            'platform' => 'brand_audit',
            'brand' => mb_strtolower($brand),
            'country' => $country,
        ]));
        return (int) self::insert_row([
            'user_id' => $scope['user_id'],
            'session_key' => $scope['session_key'],
            'entry_type' => 'brand_audit_report',
            'platform' => 'brand_audit',
            'search_mode' => 'brand_audit',
            'search_hash' => $search_hash,
            'report_title' => 'Brand Audit - ' . $brand,
            'targets' => sanitize_textarea_field((string) ($request['audit_goal'] ?? '')),
            'language_code' => sanitize_key((string) ($request['language'] ?? '')),
            'country_code' => $country,
            'min_likes' => 0,
            'run_id' => sanitize_text_field((string) ($response['bundle_id'] ?? '')),
            'raw_items' => (int) ($response['total_sources'] ?? 0),
            'filtered_items' => (int) ($response['usable_sources'] ?? 0),
            'results_json' => $json,
            'created_at' => $created_at,
            'expires_at' => $expires_at,
        ]);
    }

    public static function save_creative_intelligence_report($summary) {
        $scope = self::current_scope();
        $summary = is_array($summary) ? $summary : [];
        $result = is_array($summary['result'] ?? null) ? $summary['result'] : $summary;
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS));
        $title = sanitize_text_field((string) ($result['creative_summary'] ?? $summary['title'] ?? 'Creative Intelligence Analysis'));
        $payload = [
            'platform' => 'creative_intelligence',
            'entry_type' => 'creative_report',
            'summary' => $summary,
            'result' => $result,
            'generated_at' => $created_at,
        ];
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { $json = '{}'; }
        if (strlen($json) > 900000) {
            unset($payload['summary']['raw_asset'], $payload['result']['raw_asset']);
            $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
            $json = is_string($json) ? $json : '{}';
        }
        return (int) self::insert_row([
            'user_id' => $scope['user_id'],
            'session_key' => $scope['session_key'],
            'entry_type' => 'creative_report',
            'platform' => 'creative_intelligence',
            'search_mode' => sanitize_key((string) ($summary['analysis_mode'] ?? 'asset_analysis')),
            'search_hash' => hash('sha256', 'creative_intelligence|' . wp_json_encode($summary)),
            'report_title' => $title,
            'targets' => sanitize_textarea_field((string) ($summary['goal'] ?? $summary['context'] ?? '')),
            'language_code' => '',
            'country_code' => '',
            'min_likes' => 0,
            'run_id' => sanitize_text_field((string) ($summary['run_id'] ?? '')),
            'raw_items' => 1,
            'filtered_items' => 1,
            'results_json' => $json,
            'created_at' => $created_at,
            'expires_at' => $expires_at,
        ]);
    }


    private static function is_safe_trend_label($value, $field = '') {
        if (class_exists('VES_Trend_Label_Guard')) {
            return VES_Trend_Label_Guard::is_safe_label($value, $field);
        }
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '' || mb_strlen($value) > 140) { return false; }
        if (preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL)) { return false; }
        if (preg_match('/^\d+(?:[\.,]\d+)?\s*[kmb]?$/i', $value)) { return false; }
        if (!preg_match('/[\p{L}#@]/u', $value)) { return false; }
        return true;
    }

    private static function clean_trend_label($value, $field = 'trend_name') {
        if (class_exists('VES_Trend_Label_Guard')) {
            return VES_Trend_Label_Guard::clean_label($value, $field);
        }
        return self::is_safe_trend_label($value, $field) ? sanitize_text_field((string) $value) : '';
    }

    private static function trend_label_key($value) {
        if (class_exists('VES_Trend_Label_Guard')) {
            return VES_Trend_Label_Guard::trend_key($value);
        }
        $value = self::clean_trend_label($value);
        return trim(mb_strtolower($value));
    }

    private static function safe_payload_memory_type($payload) {
        $payload = is_array($payload) ? $payload : [];
        $explicit = sanitize_key((string) ($payload['memory_type'] ?? ''));
        $mode = sanitize_key((string) ($payload['report_mode'] ?? ''));
        $status = sanitize_key((string) ($payload['report_status'] ?? ($payload['run_status'] ?? '')));
        if (in_array($status, ['retryable_diagnostic', 'no_evidence', 'fallback_diagnostic'], true) || in_array($mode, ['retryable_diagnostic', 'source_failure_partial_report', 'no_actionable_trend_found'], true) || !empty($payload['fallback_used'])) {
            return $mode === 'source_failure_partial_report' ? 'source_failure_history' : 'source_failure_history';
        }
        if ($explicit !== '') {
            if ($explicit === 'validated_insight' && !in_array($mode, ['trend_confirmed'], true)) {
                return 'weak_signal_history';
            }
            return $explicit;
        }
        return $mode === 'trend_confirmed' ? 'validated_insight' : 'weak_signal_history';
    }

    private static function memory_status_for_payload($payload) {
        if (!is_array($payload) || empty($payload)) { return 'report_template_unreadable'; }
        if (class_exists('VES_Trend_Label_Guard')) {
            return VES_Trend_Label_Guard::memory_status_for_payload($payload);
        }
        $memory_type = sanitize_key((string) ($payload['memory_type'] ?? ''));
        $report_status = sanitize_key((string) ($payload['report_status'] ?? ($payload['run_status'] ?? '')));
        if (!empty($payload['fallback_used']) || in_array($report_status, ['fallback_diagnostic','retryable_diagnostic','no_evidence'], true) || in_array($memory_type, ['fallback_diagnostic','source_failure_history'], true)) { return 'fallback_diagnostic'; }
        return 'validated';
    }

    private static function summarize_trend_row($row, $payload) {
        $requested = is_array($payload['requested'] ?? null) ? $payload['requested'] : [];
        $structured = is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : [];
        $trends = is_array($structured['prioritized_trends'] ?? null) ? $structured['prioritized_trends'] : [];
        $corruption = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::payload_corruption($payload) : ['is_corrupted' => false, 'bad_labels' => []];
        $memory_status = self::memory_status_for_payload($payload);
        $top_names = [];
        $scores = [];
        foreach (array_slice($trends, 0, 3) as $trend) {
            $name = self::clean_trend_label($trend['trend_name'] ?? '', 'trend_name');
            if ($name !== '') {
                $top_names[] = $name;
            }
            if (isset($trend['fit_score'])) {
                $scores[] = (int) $trend['fit_score'];
            }
        }
        $avg_score = !empty($scores) ? round(array_sum($scores) / count($scores), 1) : null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'created_at' => sanitize_text_field((string) ($payload['generated_at'] ?? ($row['created_at'] ?? ''))),
            'topic' => sanitize_text_field((string) ($requested['topic'] ?? ($row['report_title'] ?? ''))),
            'country' => sanitize_text_field((string) ($requested['country'] ?? ($row['country_code'] ?? 'None'))),
            'country_label' => sanitize_text_field((string) ($requested['country_label'] ?? ($requested['country'] ?? 'Worldwide'))),
            'duration' => sanitize_key((string) ($requested['duration'] ?? '7d')),
            'duration_label' => sanitize_text_field((string) ($requested['duration_label'] ?? ($requested['duration'] ?? '7d'))),
            'successful_sources' => (int) ($payload['successful_sources'] ?? 0),
            'usable_sources' => (int) ($payload['usable_sources'] ?? ($row['filtered_items'] ?? 0)),
            'total_sources' => (int) ($payload['total_sources'] ?? 0),
            'confidence_mode' => sanitize_key((string) ($payload['confidence_mode'] ?? '')),
            'run_status' => sanitize_key((string) ($payload['run_status'] ?? '')),
            'memory_type' => sanitize_key((string) ($payload['memory_type'] ?? (!empty($payload['fallback_used']) ? 'fallback_diagnostic' : 'validated_ai_report'))),
            'report_status' => sanitize_key((string) ($payload['report_status'] ?? ($payload['run_status'] ?? ''))),
            'report_mode' => sanitize_key((string) ($payload['report_mode'] ?? '')),
            'brief_readiness_status' => sanitize_key((string) ($payload['brief_readiness_status'] ?? '')),
            'fallback_used' => !empty($payload['fallback_used']),
            'validation_required' => !empty($payload['validation_required']),
            'can_generate_brief' => !array_key_exists('can_generate_brief', $payload) || !empty($payload['can_generate_brief']),
            'can_generate_test_brief' => !empty($payload['can_generate_test_brief']),
            'reusable_as_insight' => !empty($payload['reusable_as_insight']),
            'memory_status' => $payload_unreadable ? 'report_template_unreadable' : $memory_status,
            'needs_repair' => $memory_status === 'corrupted_legacy' || $payload_unreadable,
            'corrupted_labels' => array_values(array_slice((array) ($corruption['bad_labels'] ?? []), 0, 12)),
            'top_trends' => array_values(array_slice(array_unique($top_names), 0, 3)),
            'average_fit_score' => $avg_score,
            'summary' => sanitize_text_field((string) ($structured['executive_summary'] ?? '')),
        ];
    }

    private static function sanitize_dashboard_date($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public static function get_recent_trend_reports($limit = 12, $filters = []) {
        return self::get_trend_dashboard([
            'limit' => $limit,
            'topic' => $filters['topic'] ?? '',
            'country' => $filters['country'] ?? '',
            'duration' => $filters['duration'] ?? '',
        ])['items'];
    }

    public static function get_trend_dashboard($args = []) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $limit = max(1, min(100, (int) ($args['limit'] ?? 24)));
        $topic = sanitize_text_field((string) ($args['topic'] ?? ''));
        $country = sanitize_text_field((string) ($args['country'] ?? ''));
        $duration = sanitize_key((string) ($args['duration'] ?? ''));
        $date_from = self::sanitize_dashboard_date($args['date_from'] ?? '');
        $date_to = self::sanitize_dashboard_date($args['date_to'] ?? '');

        $scope = self::current_scope();
        $params = [];
        $where = ["platform = 'trend_finder'", "entry_type = 'trend_report'"];
        $where[] = self::build_scope_where($scope, $params);

        if ($topic !== '') {
            $params[] = '%' . $wpdb->esc_like($topic) . '%';
            $where[] = 'report_title LIKE %s';
        }
        if ($country !== '') {
            $params[] = $country;
            $where[] = 'country_code = %s';
        }
        if ($date_from !== '') {
            $params[] = $date_from . ' 00:00:00';
            $where[] = 'created_at >= %s';
        }
        if ($date_to !== '') {
            $params[] = $date_to . ' 23:59:59';
            $where[] = 'created_at <= %s';
        }

        $params[] = 200;
        $sql = "SELECT id, report_title, country_code, filtered_items, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $payload = self::decode_results_json($row['results_json'] ?? '');
            if (empty($payload)) {
                continue;
            }
            $summary = self::summarize_trend_row($row, $payload);
            if ($duration !== '' && $summary['duration'] !== $duration) {
                continue;
            }
            $items[] = $summary;
        }

        return [
            'items' => array_slice($items, 0, $limit),
            'total' => count($items),
            'filters' => [
                'topic' => $topic,
                'country' => $country,
                'duration' => $duration,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'limit' => $limit,
            ],
        ];
    }

    public static function get_recent_memory_activity($limit = 12, $filters = []) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $limit = max(1, min(50, (int) $limit));
        $topic = sanitize_text_field((string) ($filters['topic'] ?? ''));
        $country = sanitize_text_field((string) ($filters['country'] ?? ''));
        $date_from = self::sanitize_dashboard_date($filters['date_from'] ?? '');
        $date_to = self::sanitize_dashboard_date($filters['date_to'] ?? '');

        $scope = self::current_scope();
        $params = [];
        $where = ["entry_type IN ('search','trend_report','trend_brief','ai_analysis')"];
        $where[] = self::build_scope_where($scope, $params);

        if ($topic !== '') {
            $like = '%' . $wpdb->esc_like($topic) . '%';
            $where[] = '(report_title LIKE %s OR targets LIKE %s OR platform LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($country !== '' && $country !== 'None') {
            $where[] = '(country_code = %s OR country_code = %s)';
            $params[] = $country;
            $params[] = strtolower($country);
        }
        if ($date_from !== '') {
            $params[] = $date_from . ' 00:00:00';
            $where[] = 'created_at >= %s';
        }
        if ($date_to !== '') {
            $params[] = $date_to . ' 23:59:59';
            $where[] = 'created_at <= %s';
        }

        $params[] = $limit;
        $sql = "SELECT id, entry_type, platform, report_title, targets, country_code, raw_items, filtered_items, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $summary = self::summarize_memory_row($row);
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'entry_type' => sanitize_key((string) ($row['entry_type'] ?? '')),
                'platform' => sanitize_key((string) ($row['platform'] ?? '')),
                'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
                'title' => sanitize_text_field((string) ($summary['title'] ?? ($row['report_title'] ?? ''))),
                'summary' => sanitize_text_field((string) ($summary['summary'] ?? '')),
                'signals' => array_values(array_slice(array_map('sanitize_text_field', (array) ($summary['signals'] ?? [])), 0, 4)),
                'country_code' => sanitize_text_field((string) ($row['country_code'] ?? '')),
                'raw_items' => (int) ($row['raw_items'] ?? 0),
                'filtered_items' => (int) ($row['filtered_items'] ?? 0),
                'memory_type' => sanitize_key((string) ($summary['memory_type'] ?? '')),
                'report_status' => sanitize_key((string) ($summary['report_status'] ?? '')),
                'confidence_mode' => sanitize_key((string) ($summary['confidence_mode'] ?? '')),
                'fallback_used' => !empty($summary['fallback_used']),
                'can_generate_brief' => !empty($summary['can_generate_brief']),
            ];
        }
        return $items;
    }

    public static function get_trend_report_by_id($report_id) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $report_id = (int) $report_id;
        if ($report_id <= 0) {
            return null;
        }
        $scope = self::current_scope();
        $params = [$report_id];
        $where = ['id = %d', "platform = 'trend_finder'", "entry_type = 'trend_report'"];
        $where[] = self::build_scope_where($scope, $params);
        $sql = "SELECT id, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['payload'] = self::decode_results_json($row['results_json'] ?? '');
        return !empty($row['payload']) ? $row : null;
    }

    private static function get_previous_trend_row($search_hash, $exclude_id = 0) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $scope = self::current_scope();
        $params = [];
        $where = ["platform = 'trend_finder'", "entry_type = 'trend_report'", 'search_hash = %s'];
        $params[] = $search_hash;
        $where[] = self::build_scope_where($scope, $params);
        if ($exclude_id > 0) {
            $where[] = 'id <> %d';
            $params[] = (int) $exclude_id;
        }
        $sql = "SELECT id, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['payload'] = self::decode_results_json($row['results_json'] ?? '');
        return !empty($row['payload']) ? $row : null;
    }

    private static function build_trend_comparison_from_rows($current_response, $previous_row, $current_row = null) {
        $current_structured = is_array($current_response['analysis_structured'] ?? null) ? $current_response['analysis_structured'] : [];
        $previous_payload = is_array($previous_row['payload'] ?? null) ? $previous_row['payload'] : [];
        $previous_structured = is_array($previous_payload['analysis_structured'] ?? null) ? $previous_payload['analysis_structured'] : [];
        $current_trends = is_array($current_structured['prioritized_trends'] ?? null) ? $current_structured['prioritized_trends'] : [];
        $previous_trends = is_array($previous_structured['prioritized_trends'] ?? null) ? $previous_structured['prioritized_trends'] : [];

        $current_map = [];
        $previous_map = [];
        $current_scores = [];
        $previous_scores = [];
        $removed_current = [];
        $removed_previous = [];
        foreach ($current_trends as $trend) {
            $label = self::clean_trend_label(is_array($trend) ? ($trend['trend_name'] ?? '') : '', 'trend_name');
            if ($label === '') {
                if (is_array($trend) && is_scalar($trend['trend_name'] ?? null)) { $removed_current[] = (string) $trend['trend_name']; }
                continue;
            }
            $name = self::trend_label_key($label);
            if ($name === '') { continue; }
            $current_map[$name] = $label;
            if (isset($trend['fit_score'])) $current_scores[] = (int) $trend['fit_score'];
        }
        foreach ($previous_trends as $trend) {
            $label = self::clean_trend_label(is_array($trend) ? ($trend['trend_name'] ?? '') : '', 'trend_name');
            if ($label === '') {
                if (is_array($trend) && is_scalar($trend['trend_name'] ?? null)) { $removed_previous[] = (string) $trend['trend_name']; }
                continue;
            }
            $name = self::trend_label_key($label);
            if ($name === '') { continue; }
            $previous_map[$name] = $label;
            if (isset($trend['fit_score'])) $previous_scores[] = (int) $trend['fit_score'];
        }

        $overlap_keys = array_values(array_intersect(array_keys($current_map), array_keys($previous_map)));
        $new_keys = array_values(array_diff(array_keys($current_map), array_keys($previous_map)));
        $dropped_keys = array_values(array_diff(array_keys($previous_map), array_keys($current_map)));

        $source_delta = [];
        $current_sources = is_array($current_response['sources'] ?? null) ? $current_response['sources'] : [];
        $previous_sources = is_array($previous_payload['sources'] ?? null) ? $previous_payload['sources'] : [];
        $source_keys = array_unique(array_merge(array_keys($current_sources), array_keys($previous_sources)));
        foreach ($source_keys as $key) {
            $source_delta[] = [
                'channel' => sanitize_text_field((string) (($current_sources[$key]['label'] ?? $previous_sources[$key]['label'] ?? $key))),
                'current_items' => (int) ($current_sources[$key]['item_count'] ?? 0),
                'previous_items' => (int) ($previous_sources[$key]['item_count'] ?? 0),
                'delta' => (int) ($current_sources[$key]['item_count'] ?? 0) - (int) ($previous_sources[$key]['item_count'] ?? 0),
            ];
        }

        $previous_created = strtotime((string) ($previous_payload['generated_at'] ?? $previous_row['created_at'] ?? ''));
        $current_created = strtotime((string) ($current_response['generated_at'] ?? ($current_row['created_at'] ?? 'now')));
        $days_apart = ($previous_created && $current_created) ? max(0, (int) round(abs($current_created - $previous_created) / DAY_IN_SECONDS)) : null;

        return [
            'current_report_id' => (int) ($current_row['id'] ?? 0),
            'current_generated_at' => sanitize_text_field((string) ($current_response['generated_at'] ?? ($current_row['created_at'] ?? ''))),
            'previous_report_id' => (int) ($previous_row['id'] ?? 0),
            'previous_generated_at' => sanitize_text_field((string) ($previous_payload['generated_at'] ?? $previous_row['created_at'] ?? '')),
            'days_apart' => $days_apart,
            'overlap_trends' => array_values(array_slice(array_map(static function ($key) use ($current_map) { return $current_map[$key]; }, $overlap_keys), 0, 6)),
            'new_trends' => array_values(array_slice(array_map(static function ($key) use ($current_map) { return $current_map[$key]; }, $new_keys), 0, 6)),
            'dropped_trends' => array_values(array_slice(array_map(static function ($key) use ($previous_map) { return $previous_map[$key]; }, $dropped_keys), 0, 6)),
            'average_fit_score_current' => !empty($current_scores) ? round(array_sum($current_scores) / count($current_scores), 1) : null,
            'average_fit_score_previous' => !empty($previous_scores) ? round(array_sum($previous_scores) / count($previous_scores), 1) : null,
            'successful_sources_current' => (int) ($current_response['successful_sources'] ?? 0),
            'successful_sources_previous' => (int) ($previous_payload['successful_sources'] ?? 0),
            'usable_sources_current' => (int) ($current_response['usable_sources'] ?? 0),
            'usable_sources_previous' => (int) ($previous_payload['usable_sources'] ?? 0),
            'confidence_mode_current' => sanitize_key((string) ($current_response['confidence_mode'] ?? '')),
            'confidence_mode_previous' => sanitize_key((string) ($previous_payload['confidence_mode'] ?? '')),
            'memory_status_current' => self::memory_status_for_payload($current_response),
            'memory_status_previous' => self::memory_status_for_payload($previous_payload),
            'corrupted_labels_removed' => array_values(array_unique(array_merge($removed_current, $removed_previous))),
            'source_item_delta' => $source_delta,
        ];
    }

    public static function build_trend_comparison($current_response, $search_hash, $current_report_id = 0) {
        $previous = self::get_previous_trend_row($search_hash, $current_report_id);
        if (!$previous) {
            return null;
        }

        return self::build_trend_comparison_from_rows($current_response, $previous, [
            'id' => (int) $current_report_id,
            'created_at' => sanitize_text_field((string) ($current_response['generated_at'] ?? '')),
        ]);
    }

    public static function compare_trend_report_ids($report_a_id, $report_b_id) {
        $row_a = self::get_trend_report_by_id($report_a_id);
        $row_b = self::get_trend_report_by_id($report_b_id);
        if (!$row_a || !$row_b) {
            return null;
        }

        $a_created = strtotime((string) ($row_a['payload']['generated_at'] ?? $row_a['created_at'] ?? '')) ?: 0;
        $b_created = strtotime((string) ($row_b['payload']['generated_at'] ?? $row_b['created_at'] ?? '')) ?: 0;
        $current_row = $a_created >= $b_created ? $row_a : $row_b;
        $previous_row = $current_row === $row_a ? $row_b : $row_a;

        $comparison = self::build_trend_comparison_from_rows($current_row['payload'], $previous_row, $current_row);
        if (!$comparison) {
            return null;
        }

        $comparison['selected_report_ids'] = [(int) ($row_a['id'] ?? 0), (int) ($row_b['id'] ?? 0)];
        return $comparison;
    }

    public static function save_trend_brief($report_id, $trend_name, $brief_format, $brief_response, $extra = []) {
        $scope = self::current_scope();
        $report_id = (int) $report_id;
        if ($report_id <= 0) {
            return 0;
        }
        $extra = is_array($extra) ? $extra : [];
        $payload = [
            'report_id' => $report_id,
            'trend_name' => sanitize_text_field((string) $trend_name),
            'brief_format' => sanitize_text_field((string) $brief_format),
            'insight_id' => !empty($extra['insight_id']) ? (int) $extra['insight_id'] : 0,
            'run_id_ref' => sanitize_text_field((string) ($extra['run_id'] ?? '')),
            'confidence_mode_at_creation' => sanitize_key((string) ($extra['confidence_mode'] ?? '')),
            'generated_at' => current_time('mysql'),
            'model' => sanitize_text_field((string) ($brief_response['model'] ?? '')),
            'analysis_format' => sanitize_text_field((string) ($brief_response['format'] ?? 'text')),
            'brief_text' => is_string($brief_response['brief_text'] ?? null) ? mb_substr($brief_response['brief_text'], 0, 24000) : '',
            'brief_structured' => is_array($brief_response['brief_structured'] ?? null) ? $brief_response['brief_structured'] : null,
        ];
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return 0;
        }
        return self::insert_row([
            'user_id' => $scope['user_id'],
            'session_key' => $scope['session_key'],
            'entry_type' => 'trend_brief',
            'platform' => 'trend_finder',
            'search_mode' => sanitize_key((string) $brief_format),
            'search_hash' => md5('brief|' . $report_id . '|' . $trend_name . '|' . microtime(true)),
            'report_title' => sanitize_text_field((string) $trend_name),
            'targets' => '',
            'language_code' => '',
            'country_code' => '',
            'min_likes' => 0,
            'run_id' => 'trend-report:' . $report_id,
            'raw_items' => 0,
            'filtered_items' => 0,
            'results_json' => $json,
            'created_at' => current_time('mysql', true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS)),
        ]);
    }

    public static function get_recent_trend_briefs($report_id, $limit = 10) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $report_id = (int) $report_id;
        if ($report_id <= 0) {
            return [];
        }
        $limit = max(1, min(20, (int) $limit));
        $scope = self::current_scope();
        $params = [];
        $where = ["platform = 'trend_finder'", "entry_type = 'trend_brief'", 'run_id = %s'];
        $params[] = 'trend-report:' . $report_id;
        $where[] = self::build_scope_where($scope, $params);
        $params[] = $limit;
        $sql = "SELECT id, report_title, search_mode, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $payload = self::decode_results_json($row['results_json'] ?? '');
            if (empty($payload)) {
                continue;
            }
            $structured = is_array($payload['brief_structured'] ?? null) ? $payload['brief_structured'] : [];
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => sanitize_text_field((string) ($payload['generated_at'] ?? ($row['created_at'] ?? ''))),
                'trend_name' => sanitize_text_field((string) ($payload['trend_name'] ?? ($row['report_title'] ?? ''))),
                'brief_format' => sanitize_text_field((string) ($payload['brief_format'] ?? ($row['search_mode'] ?? ''))),
                'brief_title' => sanitize_text_field((string) ($structured['brief_title'] ?? ($payload['trend_name'] ?? 'Brief'))),
                'summary' => sanitize_text_field((string) ($structured['strategic_angle'] ?? $structured['objective'] ?? '')),
                'brief_text' => is_string($payload['brief_text'] ?? null) ? (string) $payload['brief_text'] : '',
                'brief_structured' => !empty($structured) ? $structured : null,
                'model' => sanitize_text_field((string) ($payload['model'] ?? '')),
                'analysis_format' => sanitize_text_field((string) ($payload['analysis_format'] ?? 'text')),
            ];
        }
        return $items;
    }

    private static function get_previous_trend_row_for_scope($scope, $search_hash, $exclude_id = 0) {
        global $wpdb;
        $params = [];
        $where = ["platform = 'trend_finder'", "entry_type = 'trend_report'", 'search_hash = %s'];
        $params[] = $search_hash;
        $where[] = self::build_scope_where($scope, $params);
        if ($exclude_id > 0) {
            $where[] = 'id <> %d';
            $params[] = (int) $exclude_id;
        }
        $sql = "SELECT id, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['payload'] = self::decode_results_json($row['results_json'] ?? '');
        return !empty($row['payload']) ? $row : null;
    }

    private static function save_trend_report_with_scope($scope, $request, $response) {
        $topic = sanitize_text_field((string) ($request['topic'] ?? ''));
        $country = sanitize_text_field((string) ($request['country'] ?? 'None'));
        $duration = sanitize_key((string) ($request['duration'] ?? '7d'));
        $reason = sanitize_textarea_field((string) ($request['reason'] ?? ''));
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::TREND_RETENTION_DAYS * DAY_IN_SECONDS));

        $fingerprint = [
            'platform' => 'trend_finder',
            'topic' => mb_strtolower($topic),
            'country' => $country,
            'duration' => $duration,
        ];
        $search_hash = hash('sha256', wp_json_encode($fingerprint));
        $sources = is_array($response['sources'] ?? null) ? $response['sources'] : [];
        $raw_items = 0;
        foreach ($sources as $source) {
            $raw_items += (int) ($source['item_count'] ?? 0);
        }

        $report_id = self::insert_row([
            'user_id'        => !empty($scope['user_id']) ? (int) $scope['user_id'] : null,
            'session_key'    => (string) ($scope['session_key'] ?? ''),
            'entry_type'     => 'trend_report',
            'platform'       => 'trend_finder',
            'search_mode'    => 'trend',
            'search_hash'    => $search_hash,
            'report_title'   => $topic !== '' ? $topic : 'Trend Finder',
            'targets'        => $reason,
            'language_code'  => null,
            'country_code'   => $country,
            'min_likes'      => 0,
            'run_id'         => sanitize_text_field((string) ($response['bundle_id'] ?? '')),
            'raw_items'      => $raw_items,
            'filtered_items' => (int) ($response['usable_sources'] ?? ($response['successful_sources'] ?? 0)),
            'results_json'   => self::prepare_trend_payload($response),
            'created_at'     => $created_at,
            'expires_at'     => $expires_at,
        ]);

        self::cleanup_expired();

        return [
            'id' => $report_id,
            'search_hash' => $search_hash,
        ];
    }

    public static function save_trend_report_for_user($user_id, $request, $response) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return self::save_trend_report($request, $response);
        }
        return self::save_trend_report_with_scope([
            'user_id' => $user_id,
            'session_key' => 'user:' . $user_id,
        ], $request, $response);
    }

    public static function build_trend_comparison_for_user($user_id, $current_response, $search_hash, $current_report_id = 0) {
        $user_id = (int) $user_id;
        $scope = $user_id > 0 ? ['user_id' => $user_id, 'session_key' => 'user:' . $user_id] : self::current_scope();
        $previous = self::get_previous_trend_row_for_scope($scope, $search_hash, $current_report_id);
        if (!$previous) {
            return null;
        }
        return self::build_trend_comparison_from_rows($current_response, $previous, [
            'id' => (int) $current_report_id,
            'created_at' => sanitize_text_field((string) ($current_response['generated_at'] ?? '')),
        ]);
    }


    private static function normalize_text_for_match($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $text = preg_replace('/[^a-z0-9áéíóúñü\s\-\.]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', trim((string) $text));
        $parts = array_values(array_filter(array_map('trim', (array) $parts), static function ($token) {
            return $token !== '' && strlen($token) >= 3;
        }));
        return array_slice(array_values(array_unique($parts)), 0, 60);
    }

    private static function build_related_query_tokens($args = []) {
        $pieces = [];
        foreach (['topic','focus','targets','brand_name','primary_goal','website_url','country','platform','reason','markets','competitors'] as $key) {
            if (!empty($args[$key])) {
                $pieces[] = (string) $args[$key];
            }
        }
        if (!empty($args['title'])) {
            $pieces[] = (string) $args['title'];
        }
        if (!empty($args['workspace_knowledge_terms']) && is_array($args['workspace_knowledge_terms'])) {
            $pieces[] = implode(' ', array_map('strval', $args['workspace_knowledge_terms']));
        }
        return self::normalize_text_for_match(implode(' ', $pieces));
    }

    private static function summarize_memory_row($row) {
        $raw_json = (string) ($row['results_json'] ?? '');
        $payload = self::decode_results_json($raw_json);
        $payload_unreadable = ($raw_json !== '' && empty($payload));
        $entry_type = (string) ($row['entry_type'] ?? 'search');
        $platform = sanitize_key((string) ($row['platform'] ?? ''));
        $memory_status = self::memory_status_for_payload($payload);
        $corruption = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::payload_corruption($payload) : ['bad_labels' => []];
        $summary = [
            'id' => (int) ($row['id'] ?? 0),
            'entry_type' => $entry_type,
            'platform' => $platform,
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'title' => sanitize_text_field((string) ($row['report_title'] ?? '')),
            'summary' => '',
            'signals' => [],
            'country_code' => sanitize_text_field((string) ($row['country_code'] ?? '')),
            'confidence_mode' => sanitize_key((string) ($payload['confidence_mode'] ?? ($payload['run_status'] ?? 'unknown'))),
            'memory_type' => sanitize_key((string) ($payload['memory_type'] ?? (!empty($payload['fallback_used']) ? 'fallback_diagnostic' : 'validated_ai_report'))),
            'report_status' => sanitize_key((string) ($payload['report_status'] ?? ($payload['run_status'] ?? ''))),
            'report_mode' => sanitize_key((string) ($payload['report_mode'] ?? '')),
            'brief_readiness_status' => sanitize_key((string) ($payload['brief_readiness_status'] ?? '')),
            'fallback_used' => !empty($payload['fallback_used']),
            'validation_required' => !empty($payload['validation_required']),
            'can_generate_brief' => !array_key_exists('can_generate_brief', $payload) || !empty($payload['can_generate_brief']),
            'can_generate_test_brief' => !empty($payload['can_generate_test_brief']),
            'reusable_as_insight' => !empty($payload['reusable_as_insight']),
            'memory_status' => $payload_unreadable ? 'report_template_unreadable' : $memory_status,
            'needs_repair' => $memory_status === 'corrupted_legacy' || $payload_unreadable,
            'corrupted_labels' => array_values(array_slice((array) ($corruption['bad_labels'] ?? []), 0, 12)),
        ];

        if (in_array($entry_type, ['brand_audit_report', 'creative_report'], true)) {
            $structured = is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : [];
            $summary['summary'] = sanitize_text_field((string) ($structured['executive_summary'] ?? $payload['analysis'] ?? ''));
            foreach ((array) ($structured['opportunity_territories'] ?? []) as $territory) {
                if (!empty($territory['name'])) {
                    $summary['signals'][] = sanitize_text_field((string) $territory['name']);
                }
            }
            if (!empty($payload['requested']['brand_name'])) {
                $summary['signals'][] = sanitize_text_field((string) $payload['requested']['brand_name']);
            }
            $summary['signals'] = array_slice(array_values(array_unique($summary['signals'])), 0, 7);
            return $summary;
        }

        if ($entry_type === 'trend_report') {
            $structured = is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : [];
            $summary['summary'] = sanitize_text_field((string) ($structured['executive_summary'] ?? $payload['analysis'] ?? ''));
            foreach ((array) ($structured['prioritized_trends'] ?? []) as $trend) {
                $name = self::clean_trend_label($trend['trend_name'] ?? '', 'trend_name');
                if ($name !== '') {
                    $summary['signals'][] = $name;
                }
                if (!empty($trend['creative_angle']) && self::is_safe_trend_label($trend['creative_angle'], 'creative_angle')) {
                    $summary['signals'][] = sanitize_text_field((string) $trend['creative_angle']);
                }
            }
            if (!empty($payload['requested']['topic'])) {
                $summary['signals'][] = sanitize_text_field((string) $payload['requested']['topic']);
            }
            $summary['signals'] = array_slice(array_values(array_unique($summary['signals'])), 0, 7);
            return $summary;
        }

        if ($entry_type === 'trend_brief') {
            $structured = is_array($payload['brief_structured'] ?? null) ? $payload['brief_structured'] : [];
            $summary['summary'] = sanitize_text_field((string) ($structured['strategic_angle'] ?? $structured['objective'] ?? $payload['brief_text'] ?? ''));
            foreach (['trend_name','brief_format'] as $key) {
                if (!empty($payload[$key])) {
                    $summary['signals'][] = sanitize_text_field((string) $payload[$key]);
                }
            }
            if (!empty($structured['message_pillars']) && is_array($structured['message_pillars'])) {
                foreach (array_slice($structured['message_pillars'], 0, 4) as $pillar) {
                    $summary['signals'][] = sanitize_text_field((string) $pillar);
                }
            }
            return $summary;
        }

        if ($entry_type === 'ai_analysis') {
            $summary['summary'] = sanitize_text_field((string) ($payload['analysis_excerpt'] ?? ''));
            foreach (['item_title','item_author','focus'] as $key) {
                if (!empty($payload[$key])) {
                    $summary['signals'][] = sanitize_text_field((string) $payload[$key]);
                }
            }
            return $summary;
        }

        if ($entry_type === 'search') {
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $target_title = trim((string) ($row['targets'] ?? ''));
            if ($summary['title'] === '' && $target_title !== '') {
                $summary['title'] = sanitize_text_field(mb_substr(preg_replace('/\s+/', ' ', $target_title), 0, 120));
            }
            if ($summary['title'] === '') {
                $summary['title'] = sanitize_text_field(ucfirst((string) $platform) . ' scrape');
            }
            $summary['summary'] = sanitize_text_field((string) ($row['targets'] ?? ''));
            foreach (array_slice($items, 0, 4) as $item) {
                if (!is_array($item)) { continue; }
                $candidate = sanitize_text_field((string) (ves_first_non_empty($item['ves_title'] ?? '', $item['title'] ?? '', $item['ves_text'] ?? '', $item['text'] ?? '')));
                if ($candidate !== '') {
                    $summary['signals'][] = $candidate;
                }
            }
            return $summary;
        }

        $summary['summary'] = sanitize_text_field((string) ($row['targets'] ?? ''));
        return $summary;
    }

    private static function memory_phrase_bonus($haystack, $value, $points) {
        $value = strtolower(trim((string) $value));
        if ($value !== '' && strpos($haystack, $value) !== false) {
            return (float) $points;
        }
        return 0.0;
    }

    private static function memory_recency_bonus($created_at) {
        $ts = strtotime((string) $created_at);
        if (!$ts) {
            return 0.1;
        }
        $age = max(1, time() - $ts);
        if ($age <= DAY_IN_SECONDS * 3) {
            return 1.4;
        }
        if ($age <= DAY_IN_SECONDS * 14) {
            return 0.9;
        }
        if ($age <= DAY_IN_SECONDS * 45) {
            return 0.45;
        }
        return 0.15;
    }

    private static function memory_entry_type_bonus($entry_type) {
        $map = [
            'trend_report' => 1.4,
            'trend_brief' => 1.1,
            'ai_analysis' => 0.8,
            'search' => 0.7,
        ];
        return (float) ($map[(string) $entry_type] ?? 0.2);
    }

    private static function relevance_score_for_summary($summary, $tokens, $args = []) {
        $haystack_parts = [
            $summary['platform'] ?? '',
            $summary['title'] ?? '',
            $summary['summary'] ?? '',
            implode(' ', (array) ($summary['signals'] ?? [])),
            $summary['country_code'] ?? '',
        ];
        $haystack = strtolower(implode(' ', $haystack_parts));
        $score = 0.0;
        $matched = [];
        foreach ((array) $tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (strpos(strtolower((string) ($summary['title'] ?? '')), strtolower($token)) !== false) {
                $score += 2.6;
                $matched[] = $token;
                continue;
            }
            if (strpos(strtolower((string) ($summary['summary'] ?? '')), strtolower($token)) !== false) {
                $score += 2.0;
                $matched[] = $token;
                continue;
            }
            if (strpos($haystack, strtolower($token)) !== false) {
                $score += 1.2;
                $matched[] = $token;
            }
        }

        $score += self::memory_phrase_bonus($haystack, $args['topic'] ?? '', 3.4);
        $score += self::memory_phrase_bonus($haystack, $args['focus'] ?? '', 2.2);
        $score += self::memory_phrase_bonus($haystack, $args['brand_name'] ?? '', 2.8);
        $score += self::memory_phrase_bonus($haystack, $args['primary_goal'] ?? '', 1.4);

        if (!empty($args['platform']) && !empty($summary['platform']) && $summary['platform'] === $args['platform']) {
            $score += 1.0;
        }
        if (!empty($args['country']) && !empty($summary['country_code']) && strtolower((string) $args['country']) === strtolower((string) $summary['country_code'])) {
            $score += 1.2;
        }
        if (!empty($args['platform']) && $args['platform'] === 'trend_finder' && in_array((string) ($summary['entry_type'] ?? ''), ['trend_report', 'trend_brief'], true)) {
            $score += 0.8;
        }
        if (empty($matched) && !empty($summary['signals'])) {
            $score += 0.35;
        }

        $score += self::memory_entry_type_bonus((string) ($summary['entry_type'] ?? ''));
        $score += self::memory_recency_bonus((string) ($summary['created_at'] ?? ''));
        // v0.9.21.0: diagnostic memory entries are intentionally downweighted,
        // and normally excluded from AI context unless explicitly requested.
        if (!empty($summary['fallback_used']) || (($summary['memory_type'] ?? '') === 'fallback_diagnostic') || (($summary['confidence_mode'] ?? '') === 'diagnostic_fallback')) {
            $score *= 0.2;
        }
        if (in_array((string) ($summary['memory_status'] ?? ''), ['corrupted_legacy','needs_repair'], true)) {
            $score = 0.0;
        }
        return [$score, array_slice(array_values(array_unique($matched)), 0, 6)];
    }

    private static function resolve_scope_from_args($args = []) {
        $user_id = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        if ($user_id > 0) {
            return [
                'user_id' => $user_id,
                'session_key' => 'user:' . $user_id,
            ];
        }
        return self::current_scope();
    }

    public static function get_related_memory_context($args = [], $limit = 5) {
        global $wpdb;
        $limit = max(1, min(8, (int) $limit));
        $scope = self::resolve_scope_from_args($args);
        $params = [];
        $where = ["entry_type IN ('search','trend_report','trend_brief','ai_analysis')"];
        $where[] = self::build_scope_where($scope, $params);
        if (!empty($args['exclude_id'])) {
            $where[] = 'id <> %d';
            $params[] = (int) $args['exclude_id'];
        }
        $params[] = 60;
        $sql = "SELECT id, entry_type, platform, report_title, targets, country_code, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $tokens = self::build_related_query_tokens($args);
        $collected = [];
        foreach ((array) $rows as $row) {
            $summary = self::summarize_memory_row($row);
            if (empty($args['include_diagnostics']) && (!empty($summary['fallback_used']) || (($summary['memory_type'] ?? '') === 'fallback_diagnostic') || in_array((string) ($summary['memory_status'] ?? ''), ['fallback_diagnostic','corrupted_legacy','needs_repair'], true))) { continue; }
            [$score, $matched] = self::relevance_score_for_summary($summary, $tokens, [
                'platform' => $args['platform'] ?? '',
                'country' => $args['country'] ?? '',
                'country_code' => $row['country_code'] ?? '',
            ]);
            if ($score <= 0 && !empty($tokens)) {
                continue;
            }
            $summary['relevance_score'] = $score;
            $summary['matched_terms'] = $matched;
            $collected[] = $summary;
        }
        usort($collected, static function ($a, $b) {
            $scoreA = (float) ($a['relevance_score'] ?? 0);
            $scoreB = (float) ($b['relevance_score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            }
            return $scoreA < $scoreB ? 1 : -1;
        });
        return array_slice($collected, 0, $limit);
    }

    public static function save_ai_analysis($platform, $item, $focus, $analysis_response) {
        $scope = self::current_scope();
        $payload = [
            'platform' => sanitize_key((string) $platform),
            'item_title' => sanitize_text_field((string) ves_first_non_empty($item['title'] ?? '', $item['ves_title'] ?? '')),
            'item_author' => sanitize_text_field((string) ves_first_non_empty($item['author'] ?? '', $item['ves_author'] ?? '')),
            'item_url' => esc_url_raw((string) ves_first_non_empty($item['url'] ?? '', $item['ves_url'] ?? '')),
            'focus' => sanitize_text_field((string) $focus),
            'generated_at' => current_time('mysql'),
            'model' => sanitize_text_field((string) ($analysis_response['model'] ?? '')),
            'analysis_excerpt' => is_string($analysis_response['analysis'] ?? null) ? mb_substr(wp_strip_all_tags($analysis_response['analysis']), 0, 1200) : '',
            'analysis' => is_string($analysis_response['analysis'] ?? null) ? mb_substr($analysis_response['analysis'], 0, 16000) : '',
        ];
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return 0;
        }
        return self::insert_row([
            'user_id' => $scope['user_id'],
            'session_key' => $scope['session_key'],
            'entry_type' => 'ai_analysis',
            'platform' => sanitize_key((string) $platform),
            'search_mode' => 'single_item',
            'search_hash' => md5('ai|' . $platform . '|' . microtime(true)),
            'report_title' => sanitize_text_field((string) ($payload['item_title'] ?: 'AI analysis')),
            'targets' => sanitize_text_field((string) $focus),
            'language_code' => '',
            'country_code' => '',
            'min_likes' => 0,
            'run_id' => '',
            'raw_items' => 1,
            'filtered_items' => 1,
            'results_json' => $json,
            'created_at' => current_time('mysql', true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::SEARCH_RETENTION_DAYS * DAY_IN_SECONDS)),
        ]);
    }



    public static function get_memory_index($args = []) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $limit = max(1, min(100, (int) ($args['limit'] ?? 60)));
        $entry_type = sanitize_key((string) ($args['entry_type'] ?? 'all'));
        $platform = sanitize_key((string) ($args['platform'] ?? ''));
        $q = sanitize_text_field((string) ($args['q'] ?? ''));

        $allowed_types = ['search', 'ai_analysis', 'trend_report', 'trend_brief', 'brand_audit_report','creative_report'];
        $scope = self::current_scope();
        $params = [];
        $where = ["entry_type IN ('search','ai_analysis','trend_report','trend_brief','brand_audit_report','creative_report')"];
        $where[] = self::build_scope_where($scope, $params);

        if (in_array($entry_type, $allowed_types, true)) {
            $where[] = 'entry_type = %s';
            $params[] = $entry_type;
        }
        if ($platform !== '') {
            $where[] = 'platform = %s';
            $params[] = $platform;
        }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(report_title LIKE %s OR targets LIKE %s OR platform LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $params[] = $limit;
        $sql = "SELECT id, entry_type, platform, search_mode, report_title, targets, language_code, country_code, min_likes, run_id, raw_items, filtered_items, created_at, results_json FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        $counts = ['all' => 0, 'search' => 0, 'ai_analysis' => 0, 'trend_report' => 0, 'trend_brief' => 0, 'brand_audit_report' => 0, 'creative_report' => 0];
        foreach ((array) $rows as $row) {
            $summary = self::summarize_memory_row($row);
            $type = sanitize_key((string) ($row['entry_type'] ?? ''));
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            $counts['all']++;
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'entry_type' => $type,
                'platform' => sanitize_key((string) ($row['platform'] ?? '')),
                'search_mode' => sanitize_key((string) ($row['search_mode'] ?? '')),
                'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
                'title' => sanitize_text_field((string) ($summary['title'] ?? ($row['report_title'] ?? ''))),
                'summary' => sanitize_text_field((string) ($summary['summary'] ?? '')),
                'signals' => array_values(array_slice(array_map('sanitize_text_field', (array) ($summary['signals'] ?? [])), 0, 5)),
                'country_code' => sanitize_text_field((string) ($row['country_code'] ?? '')),
                'language_code' => sanitize_text_field((string) ($row['language_code'] ?? '')),
                'raw_items' => (int) ($row['raw_items'] ?? 0),
                'filtered_items' => (int) ($row['filtered_items'] ?? 0),
                'run_id' => sanitize_text_field((string) ($row['run_id'] ?? '')),
                'memory_status' => sanitize_key((string) ($summary['memory_status'] ?? 'validated')),
                'memory_type' => sanitize_key((string) ($summary['memory_type'] ?? '')),
                'report_status' => sanitize_key((string) ($summary['report_status'] ?? '')),
                'confidence_mode' => sanitize_key((string) ($summary['confidence_mode'] ?? '')),
                'fallback_used' => !empty($summary['fallback_used']),
                'needs_repair' => !empty($summary['needs_repair']),
            ];
        }

        return [
            'items' => $items,
            'counts' => $counts,
            'filters' => [
                'entry_type' => $entry_type,
                'platform' => $platform,
                'q' => $q,
                'limit' => $limit,
            ],
        ];
    }

    public static function get_memory_entry($entry_id) {
        if (!self::table_exists()) {
            self::create_table();
        }
        global $wpdb;
        $entry_id = (int) $entry_id;
        if ($entry_id <= 0) {
            return null;
        }
        $scope = self::current_scope();
        $params = [$entry_id];
        $where = ['id = %d'];
        $where[] = self::build_scope_where($scope, $params);
        $sql = "SELECT * FROM " . self::table_name() . " WHERE " . implode(' AND ', $where) . " LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $raw_json = (string) ($row['results_json'] ?? '');
        $payload = self::decode_results_json($raw_json);
        $payload_unreadable = ($raw_json !== '' && empty($payload));
        $summary = self::summarize_memory_row($row);
        $entry = [
            'id' => (int) ($row['id'] ?? 0),
            'entry_type' => sanitize_key((string) ($row['entry_type'] ?? '')),
            'platform' => sanitize_key((string) ($row['platform'] ?? '')),
            'search_mode' => sanitize_key((string) ($row['search_mode'] ?? '')),
            'title' => sanitize_text_field((string) ($summary['title'] ?? ($row['report_title'] ?? 'Memory'))),
            'summary' => sanitize_text_field((string) ($summary['summary'] ?? '')),
            'signals' => array_values(array_slice(array_map('sanitize_text_field', (array) ($summary['signals'] ?? [])), 0, 10)),
            'targets' => sanitize_textarea_field((string) ($row['targets'] ?? '')),
            'language_code' => sanitize_text_field((string) ($row['language_code'] ?? '')),
            'country_code' => sanitize_text_field((string) ($row['country_code'] ?? '')),
            'min_likes' => (int) ($row['min_likes'] ?? 0),
            'run_id' => sanitize_text_field((string) ($row['run_id'] ?? '')),
            'raw_items' => (int) ($row['raw_items'] ?? 0),
            'filtered_items' => (int) ($row['filtered_items'] ?? 0),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'memory_status' => sanitize_key((string) ($summary['memory_status'] ?? 'validated')),
            'memory_type' => sanitize_key((string) ($summary['memory_type'] ?? '')),
            'report_status' => sanitize_key((string) ($summary['report_status'] ?? '')),
            'confidence_mode' => sanitize_key((string) ($summary['confidence_mode'] ?? '')),
            'fallback_used' => !empty($summary['fallback_used']),
            'needs_repair' => !empty($summary['needs_repair']) || $payload_unreadable,
        ];
        if ($payload_unreadable) {
            $entry['memory_status'] = 'report_template_unreadable';
            $entry['report_status'] = 'report_template_unreadable';
            $entry['memory_type'] = 'source_failure_history';
            $payload = [
                'status' => 'REPORT_TEMPLATE_UNREADABLE',
                'message' => 'Este registro no se pudo abrir de forma segura. La lista de memoria sigue disponible.',
                'report_status' => 'report_template_unreadable',
                'memory_type' => 'source_failure_history',
            ];
        }
        return [
            'entry' => $entry,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }


    public static function count_corrupted_trend_memory($limit = 1000) {
        if (!self::table_exists()) { self::create_table(); }
        global $wpdb;
        $limit = max(1, min(5000, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, results_json FROM " . self::table_name() . " WHERE platform = 'trend_finder' AND entry_type = 'trend_report' ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        $count = 0;
        $examples = [];
        foreach ((array) $rows as $row) {
            $payload = self::decode_results_json($row['results_json'] ?? '');
            $corruption = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::payload_corruption($payload) : ['is_corrupted' => false, 'bad_labels' => []];
            if (!empty($corruption['is_corrupted'])) {
                $count++;
                if (count($examples) < 10) {
                    $examples[] = ['id' => (int) ($row['id'] ?? 0), 'bad_labels' => array_values(array_slice((array) ($corruption['bad_labels'] ?? []), 0, 8))];
                }
            }
        }
        return ['scanned' => count((array) $rows), 'corrupted' => $count, 'examples' => $examples];
    }

    public static function repair_corrupted_trend_memory($limit = 200, $dry_run = true) {
        if (!self::table_exists()) { self::create_table(); }
        global $wpdb;
        $limit = max(1, min(1000, (int) $limit));
        $dry_run = !empty($dry_run);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, results_json FROM " . self::table_name() . " WHERE platform = 'trend_finder' AND entry_type = 'trend_report' ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
        $summary = ['dry_run' => $dry_run, 'scanned' => count((array) $rows), 'corrupted' => 0, 'repaired' => 0, 'removed_labels' => [], 'updated_ids' => []];
        foreach ((array) $rows as $row) {
            $payload = self::decode_results_json($row['results_json'] ?? '');
            $corruption = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::payload_corruption($payload) : ['is_corrupted' => false];
            if (empty($corruption['is_corrupted'])) { continue; }
            $summary['corrupted']++;
            [$repaired_payload, $removed] = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::repair_payload($payload) : [$payload, []];
            $summary['removed_labels'] = array_values(array_unique(array_merge($summary['removed_labels'], $removed)));
            if ($dry_run) { continue; }
            $json = wp_json_encode($repaired_payload, JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $ok = $wpdb->update(self::table_name(), ['results_json' => $json], ['id' => (int) ($row['id'] ?? 0)], ['%s'], ['%d']);
                if ($ok !== false) {
                    $summary['repaired']++;
                    $summary['updated_ids'][] = (int) ($row['id'] ?? 0);
                }
            }
        }
        $summary['removed_labels'] = array_values(array_slice($summary['removed_labels'], 0, 50));
        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('trend_memory_repair', $dry_run ? 'Dry-run trend memory label repair completed.' : 'Trend memory label repair completed.', $summary);
        }
        return $summary;
    }


    public static function decode_results_payload_public($json) {
        return self::decode_results_json((string) $json);
    }

    public static function get_trend_reports_for_backfill($args = []) {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(5000, (int) $args['limit'])) : 200;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $where = ["platform = 'trend_finder'", "entry_type = 'trend_report'"];
        $params = [];
        if (!empty($args['from'])) {
            $where[] = 'created_at >= %s';
            $params[] = sanitize_text_field((string) $args['from']) . ' 00:00:00';
        }
        if (!empty($args['to'])) {
            $where[] = 'created_at <= %s';
            $params[] = sanitize_text_field((string) $args['to']) . ' 23:59:59';
        }
        if (!empty($args['days'])) {
            $days = max(1, (int) $args['days']);
            $where[] = 'created_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        }
        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['session_key'])) {
            $where[] = 'session_key = %s';
            $params[] = sanitize_key((string) $args['session_key']);
        }
        if (!empty($args['memory_ids']) && is_array($args['memory_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $args['memory_ids'])));
            if (!empty($ids)) {
                $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $params = array_merge($params, $ids);
            }
        }
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $row['payload'] = self::decode_results_json($row['results_json'] ?? '');
            $row['summary'] = self::summarize_memory_row($row);
            $items[] = $row;
        }
        return $items;
    }

    public static function update_trend_report_backfill_links($report_id, $run_id, $job_id) {
        global $wpdb;
        $report_id = (int) $report_id;
        if ($report_id <= 0) {
            return false;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT results_json FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $report_id), ARRAY_A);
        if (!is_array($row)) {
            return false;
        }
        $payload = self::decode_results_json($row['results_json'] ?? '');
        if (!is_array($payload)) {
            $payload = [];
        }
        if (!empty($payload['_backfill']['run_id']) && (int) $payload['_backfill']['run_id'] === (int) $run_id) {
            return true;
        }
        $payload['_backfill'] = [
            'run_id' => (int) $run_id,
            'job_id' => sanitize_text_field((string) $job_id),
            'linked_at' => current_time('mysql', true),
        ];
        $json = self::prepare_trend_payload($payload);
        return false !== $wpdb->update(self::table_name(), ['results_json' => $json], ['id' => $report_id], ['%s'], ['%d']);
    }

}
