<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Record_Store — Phase 4 persistence for trend scores/classifications.
 *
 * Upserts one record per (workspace, normalized_term, observation_type, platform,
 * provider) with the deterministic scores + classification, and preserves
 * evidence/signal/source links (JSON arrays, never destructively overwritten).
 * Additive table `{prefix}ves_trend_records`. Secret-scrubbed metadata.
 */
final class VES_Trend_Record_Store {

    const TABLE_SLUG  = 'ves_trend_records';
    const DB_VERSION  = '1.0.0';
    const VERSION_OPT = 'ves_trend_records_db_version';
    const MAX_META_BYTES = 8000;

    const CLASSIFICATIONS = ['emerging', 'sustained', 'peaking', 'fading', 'spike', 'noise', 'insufficient_data'];

    const FORBIDDEN_META_KEYS = [
        'prompt', 'input', 'messages', 'message', 'response', 'raw_response', 'raw',
        'api_key', 'apikey', 'token', 'bearer', 'authorization', 'auth',
        'password', 'secret', 'webhook_secret', 'key',
    ];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $table = self::table_name();
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            run_id varchar(80) NOT NULL DEFAULT '',
            normalized_term varchar(190) NOT NULL DEFAULT '',
            display_term varchar(190) NOT NULL DEFAULT '',
            observation_type varchar(32) NOT NULL DEFAULT 'other',
            platform varchar(40) NOT NULL DEFAULT '',
            provider varchar(40) NOT NULL DEFAULT '',
            classification varchar(24) NOT NULL DEFAULT 'insufficient_data',
            trend_score decimal(5,2) NOT NULL DEFAULT 0,
            momentum_score decimal(5,2) NOT NULL DEFAULT 0,
            consistency_score decimal(5,2) NOT NULL DEFAULT 0,
            freshness_score decimal(5,2) NOT NULL DEFAULT 0,
            volatility_score decimal(5,2) NOT NULL DEFAULT 0,
            spike_score decimal(5,2) NOT NULL DEFAULT 0,
            confidence_score decimal(5,2) NOT NULL DEFAULT 0,
            point_count int unsigned NOT NULL DEFAULT 0,
            first_seen_at datetime NULL,
            last_seen_at datetime NULL,
            evidence_ids_json longtext NULL,
            signal_ids_json longtext NULL,
            source_ids_json longtext NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY normalized_term (normalized_term),
            KEY classification (classification),
            KEY trend_score (trend_score),
            KEY confidence_score (confidence_score),
            KEY last_seen_at (last_seen_at),
            KEY run_id (run_id)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    /**
     * Upsert a trend record by (workspace, normalized_term, observation_type,
     * platform, provider). Preserves existing evidence/signal/source links (merged,
     * never destroyed). @return int|WP_Error
     */
    public static function create_or_update_trend_record(array $data) {
        global $wpdb;
        $workspace_id = max(0, (int) ($data['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return new WP_Error('ves_trend_missing_workspace', 'workspace_id is required.'); }
        $classification = (string) ($data['classification'] ?? 'insufficient_data');
        if (!in_array($classification, self::CLASSIFICATIONS, true)) {
            return new WP_Error('ves_trend_invalid_class', "Invalid classification '{$classification}'.");
        }
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_trend_no_db', 'Database unavailable.'); }

        $normalized_term = self::s((string) ($data['normalized_term'] ?? ''), 190);
        $observation_type = self::k((string) ($data['observation_type'] ?? 'other'), 32);
        $platform = self::k((string) ($data['platform'] ?? ''), 40);
        $provider = self::k((string) ($data['provider'] ?? ''), 40);
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d AND normalized_term = %s AND observation_type = %s AND platform = %s AND provider = %s ORDER BY id ASC LIMIT 1',
            $workspace_id, $normalized_term, $observation_type, $platform, $provider
        ), ARRAY_A);

        $scores = [
            'classification'    => $classification,
            'trend_score'       => self::clamp($data['trend_score'] ?? 0),
            'momentum_score'    => self::clamp($data['momentum_score'] ?? 0),
            'consistency_score' => self::clamp($data['consistency_score'] ?? 0),
            'freshness_score'   => self::clamp($data['freshness_score'] ?? 0),
            'volatility_score'  => self::clamp($data['volatility_score'] ?? 0),
            'spike_score'       => self::clamp($data['spike_score'] ?? 0),
            'confidence_score'  => self::clamp($data['confidence_score'] ?? 0),
            'point_count'       => max(0, (int) ($data['point_count'] ?? 0)),
            'run_id'            => self::s((string) ($data['run_id'] ?? ''), 80),
            'display_term'      => self::s((string) ($data['display_term'] ?? $data['term'] ?? $normalized_term), 190),
            'last_seen_at'      => self::dt($data['last_seen_at'] ?? null) ?: $now,
            'metadata'          => self::prepare_metadata($data['metadata'] ?? []),
            'updated_at'        => $now,
        ];
        // Merge link arrays (preserve existing).
        $evi = self::merge_ids($existing['evidence_ids_json'] ?? '[]', $data['evidence_ids'] ?? []);
        $sig = self::merge_ids($existing['signal_ids_json'] ?? '[]', $data['signal_ids'] ?? []);
        $src = self::merge_ids($existing['source_ids_json'] ?? '[]', $data['source_ids'] ?? []);
        $scores['evidence_ids_json'] = wp_json_encode($evi);
        $scores['signal_ids_json']   = wp_json_encode($sig);
        $scores['source_ids_json']   = wp_json_encode($src);

        if (is_array($existing) && !empty($existing['id'])) {
            $wpdb->update(self::table_name(), $scores, ['id' => (int) $existing['id']], null, ['%d']);
            return (int) $existing['id'];
        }
        $scores['workspace_id'] = $workspace_id;
        $scores['normalized_term'] = $normalized_term;
        $scores['observation_type'] = $observation_type;
        $scores['platform'] = $platform;
        $scores['provider'] = $provider;
        $scores['first_seen_at'] = self::dt($data['first_seen_at'] ?? null) ?: $now;
        $scores['created_at'] = $now;
        $ok = $wpdb->insert(self::table_name(), $scores);
        return $ok ? (int) $wpdb->insert_id : new WP_Error('ves_trend_insert_failed', 'Could not persist trend record.');
    }

    /** Find an existing record id by upsert key (0 if none). For created/updated detection. */
    public static function find_record(int $workspace_id, string $normalized_term, string $observation_type, string $platform = '', string $provider = ''): int {
        global $wpdb;
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE workspace_id = %d AND normalized_term = %s AND observation_type = %s AND platform = %s AND provider = %s ORDER BY id ASC LIMIT 1',
            $workspace_id, self::s($normalized_term, 190), self::k($observation_type, 32), self::k($platform, 40), self::k($provider, 40)
        ));
    }

    public static function get_trend_record(int $id) {
        global $wpdb;
        if ($id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    public static function list_trend_records(array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $where = '1=1'; $params = [];
        if (isset($filters['workspace_id'])) { $where .= ' AND workspace_id = %d'; $params[] = (int) $filters['workspace_id']; }
        if (!empty($filters['classification'])) { $where .= ' AND classification = %s'; $params[] = self::k((string) $filters['classification'], 24); }
        if (isset($filters['min_score'])) { $where .= ' AND trend_score >= %f'; $params[] = (float) $filters['min_score']; }
        if (isset($filters['min_confidence'])) { $where .= ' AND confidence_score >= %f'; $params[] = (float) $filters['min_confidence']; }
        $order = 'trend_score DESC';
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $sql = 'SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY {$order} LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return array_map([__CLASS__, 'decode'], $rows);
    }

    public static function link_evidence(int $trend_record_id, int $evidence_id) { return self::append_link($trend_record_id, 'evidence_ids_json', $evidence_id); }
    public static function link_signal(int $trend_record_id, int $signal_id)     { return self::append_link($trend_record_id, 'signal_ids_json', $signal_id); }
    public static function link_source(int $trend_record_id, int $source_id)     { return self::append_link($trend_record_id, 'source_ids_json', $source_id); }

    private static function append_link(int $id, string $column, int $value) {
        global $wpdb;
        if ($id <= 0 || $value <= 0 || !isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_trend_bad_link', 'Invalid link.'); }
        $cur = $wpdb->get_var($wpdb->prepare('SELECT ' . $column . ' FROM ' . self::table_name() . ' WHERE id = %d', $id));
        if ($cur === null) { return new WP_Error('ves_trend_not_found', 'Trend record not found.'); }
        $ids = self::merge_ids((string) $cur, [$value]);
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $wpdb->update(self::table_name(), [$column => wp_json_encode($ids), 'updated_at' => $now], ['id' => $id], ['%s', '%s'], ['%d']);
        return true;
    }

    // ── diagnostics (Part G) ────────────────────────────────────────────────────
    public static function total(int $workspace_id = 0): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        if ($workspace_id > 0) { return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE workspace_id = %d', $workspace_id)); }
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table_name());
    }

    public static function count_by_classification(int $workspace_id = 0): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $where = '1=1'; $params = [];
        if ($workspace_id > 0) { $where .= ' AND workspace_id = %d'; $params[] = $workspace_id; }
        $sql = 'SELECT classification AS k, COUNT(*) AS c FROM ' . self::table_name() . " WHERE {$where} GROUP BY classification ORDER BY c DESC";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $out = []; foreach ((array) $rows as $r) { $out[(string) ($r['k'] ?? '')] = (int) ($r['c'] ?? 0); }
        return $out;
    }

    /** Count records with no evidence link (diagnostics). */
    public static function count_missing_evidence(int $workspace_id = 0): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        $ws = $workspace_id > 0 ? ' AND workspace_id = ' . (int) $workspace_id : '';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name() . " WHERE (evidence_ids_json IS NULL OR evidence_ids_json = '' OR evidence_ids_json = '[]'){$ws}");
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function decode(array $row): array {
        foreach (['evidence_ids_json' => 'evidence_ids', 'signal_ids_json' => 'signal_ids', 'source_ids_json' => 'source_ids'] as $col => $alias) {
            $arr = json_decode((string) ($row[$col] ?? '[]'), true);
            $row[$alias] = is_array($arr) ? array_values(array_map('intval', $arr)) : [];
        }
        $meta = json_decode((string) ($row['metadata'] ?? ''), true);
        $row['metadata'] = is_array($meta) ? $meta : [];
        return $row;
    }
    private static function merge_ids($existing_json, $new): array {
        $arr = is_array($existing_json) ? $existing_json : json_decode((string) $existing_json, true);
        $arr = is_array($arr) ? $arr : [];
        foreach ((array) $new as $v) { $i = (int) $v; if ($i > 0) { $arr[] = $i; } }
        $out = [];
        foreach ($arr as $v) { $i = (int) $v; if ($i > 0 && !in_array($i, $out, true)) { $out[] = $i; } }
        return array_values($out);
    }
    private static function clamp($v): float { $v = (float) $v; if ($v < 0) { return 0.0; } if ($v > 100) { return 100.0; } return round($v, 2); }
    private static function s(string $v, int $max): string { $v = function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v)); return substr($v, 0, $max); }
    private static function k(string $v, int $max): string { $v = function_exists('sanitize_key') ? sanitize_key($v) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $v)); return substr($v, 0, $max); }
    private static function dt($v): ?string {
        if (!is_string($v) || $v === '') { return null; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', trim($v))) { return strlen(trim($v)) === 10 ? trim($v) . ' 00:00:00' : trim($v); }
        $ts = strtotime($v); return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
    private static function prepare_metadata($metadata): string {
        $metadata = is_array($metadata) ? $metadata : [];
        $clean = [];
        foreach ($metadata as $k => $v) {
            if (in_array(strtolower((string) $k), self::FORBIDDEN_META_KEYS, true)) { continue; }
            if (is_scalar($v) || $v === null) { $clean[strtolower((string) $k)] = is_string($v) ? self::scrub($v) : $v; }
            elseif (is_array($v)) { $flat = []; foreach ($v as $k2 => $v2) { if (in_array(strtolower((string) $k2), self::FORBIDDEN_META_KEYS, true)) { continue; } if (is_scalar($v2) || $v2 === null) { $flat[strtolower((string) $k2)] = is_string($v2) ? self::scrub($v2) : $v2; } } if ($flat) { $clean[strtolower((string) $k)] = $flat; } }
        }
        $json = function_exists('wp_json_encode') ? wp_json_encode($clean) : json_encode($clean);
        if (!is_string($json)) { $json = '{}'; }
        if (strlen($json) > self::MAX_META_BYTES) { $json = '{"truncated":true}'; }
        return $json;
    }
    private static function scrub(string $v): string {
        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'scrub_secrets')) { return VES_AI_Usage_Tracker::scrub_secrets($v); }
        return (string) preg_replace(['/sk-[A-Za-z0-9_\-]{8,}/', '/Bearer\s+[A-Za-z0-9_\-.]+/i', '/apify_api_[A-Za-z0-9]{8,}/'], '[redacted]', $v);
    }
}
