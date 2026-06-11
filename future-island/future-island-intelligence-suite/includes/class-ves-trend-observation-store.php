<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Observation_Store — Phase 4 (DTF V2) trend observation layer.
 *
 * A trend observation is a single measured datapoint (a hashtag/keyword/mention
 * count, a source item count, an engagement value, …) normalized into a stable,
 * dedupable row that the series builder + scorer can aggregate deterministically.
 *
 * Additive table `{prefix}ves_trend_observations`. Workspace-scoped, idempotent
 * migration, secret-scrubbed metadata. No LLM, no external calls.
 */
final class VES_Trend_Observation_Store {

    const TABLE_SLUG  = 'ves_trend_observations';
    const DB_VERSION  = '1.1.0';
    const VERSION_OPT = 'ves_trend_observations_db_version';
    const MAX_META_BYTES = 8000;

    const TYPES = ['keyword', 'hashtag', 'mention', 'topic', 'source_count', 'engagement', 'citation', 'other'];

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
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            signal_id bigint(20) unsigned NOT NULL DEFAULT 0,
            evidence_id bigint(20) unsigned NOT NULL DEFAULT 0,
            observation_type varchar(32) NOT NULL DEFAULT 'other',
            term varchar(190) NOT NULL DEFAULT '',
            normalized_term varchar(190) NOT NULL DEFAULT '',
            platform varchar(40) NOT NULL DEFAULT '',
            provider varchar(40) NOT NULL DEFAULT '',
            observed_at datetime NULL,
            value_number decimal(18,4) NOT NULL DEFAULT 0,
            value_text longtext NULL,
            raw_count int unsigned NOT NULL DEFAULT 0,
            normalized_value decimal(18,4) NOT NULL DEFAULT 0,
            source_url text NULL,
            canonical_hash varchar(64) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY normalized_term (normalized_term),
            KEY observation_type (observation_type),
            KEY observed_at (observed_at),
            KEY canonical_hash (canonical_hash),
            KEY source_id (source_id),
            KEY signal_id (signal_id),
            UNIQUE KEY ws_canonical (workspace_id,canonical_hash)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    /**
     * Normalize an observation payload + compute its canonical dedup hash.
     * @return array data ready for insert (or with '_error' => WP_Error)
     */
    public static function normalize_observation(array $data): array {
        $out = [];
        $out['workspace_id'] = max(0, (int) ($data['workspace_id'] ?? 0));
        $type = self::sanitize_key((string) ($data['observation_type'] ?? 'other'), 32);
        if (!in_array($type, self::TYPES, true)) {
            return ['_error' => new WP_Error('ves_trend_invalid_type', "Invalid observation_type '{$type}'.")];
        }
        $out['observation_type'] = $type;

        $term = (string) ($data['term'] ?? '');
        $normalized = self::normalize_term($term, $type);
        $out['term'] = self::sanitize_text($term, 190);
        $out['normalized_term'] = substr($normalized, 0, 190);
        $out['platform'] = self::sanitize_key((string) ($data['platform'] ?? ''), 40);
        $out['provider'] = self::sanitize_key((string) ($data['provider'] ?? ''), 40);
        $out['run_id'] = self::sanitize_text((string) ($data['run_id'] ?? ''), 80);
        $out['source_id'] = max(0, (int) ($data['source_id'] ?? 0));
        $out['signal_id'] = max(0, (int) ($data['signal_id'] ?? 0));
        $out['evidence_id'] = max(0, (int) ($data['evidence_id'] ?? 0));

        $observed = self::sanitize_datetime($data['observed_at'] ?? null);
        if ($observed === null) { $observed = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
        $out['observed_at'] = $observed;
        $out['value_number'] = round((float) ($data['value_number'] ?? 0), 4);
        $out['raw_count'] = max(0, (int) ($data['raw_count'] ?? 0));
        $out['normalized_value'] = round((float) ($data['normalized_value'] ?? $out['value_number']), 4);
        $out['value_text'] = isset($data['value_text']) ? self::sanitize_text((string) $data['value_text'], 1000) : null;
        $out['source_url'] = isset($data['source_url']) ? self::sanitize_url((string) $data['source_url']) : null;

        // Dedup bucket: day-level so repeated same-day counts collapse to one row.
        $bucket = substr($observed, 0, 10);
        $basis = implode('|', [$out['workspace_id'], $type, $out['normalized_term'], $out['platform'], $out['provider'], $bucket, $out['source_id'], $out['signal_id']]);
        $out['canonical_hash'] = hash('sha256', $basis);
        $out['metadata'] = self::prepare_metadata($data['metadata'] ?? []);
        return $out;
    }

    /**
     * Create an observation, or accumulate into the existing one with the same
     * canonical hash (same workspace+type+term+platform+day bucket). On dedup it
     * sums value_number/raw_count (same-bucket counts) and returns the existing id.
     * @return int|WP_Error
     */
    public static function create_or_get_observation(array $data) {
        $norm = self::normalize_observation($data);
        if (isset($norm['_error'])) { return $norm['_error']; }
        if ($norm['workspace_id'] <= 0) { return new WP_Error('ves_trend_missing_workspace', 'workspace_id is required.'); }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return new WP_Error('ves_trend_no_db', 'Database unavailable.'); }

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE workspace_id = %d AND canonical_hash = %s ORDER BY id ASC LIMIT 1',
            $norm['workspace_id'], $norm['canonical_hash']
        ));
        $now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        if ($existing > 0) {
            $cur = $wpdb->get_row($wpdb->prepare('SELECT value_number, raw_count FROM ' . self::table_name() . ' WHERE id = %d', $existing), ARRAY_A);
            $wpdb->update(self::table_name(), [
                'value_number' => round((float) ($cur['value_number'] ?? 0) + $norm['value_number'], 4),
                'raw_count' => max(0, (int) ($cur['raw_count'] ?? 0) + $norm['raw_count']),
                'updated_at' => $now,
            ], ['id' => $existing], ['%f', '%d', '%s'], ['%d']);
            return $existing;
        }
        $row = array_merge($norm, ['created_at' => $now, 'updated_at' => $now]);
        $formats = ['%d','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s','%f','%s','%d','%f','%s','%s','%s','%s','%s'];
        // Order row keys to match formats.
        $ordered = [
            'workspace_id' => $row['workspace_id'], 'run_id' => $row['run_id'], 'source_id' => $row['source_id'],
            'signal_id' => $row['signal_id'], 'evidence_id' => $row['evidence_id'], 'observation_type' => $row['observation_type'],
            'term' => $row['term'], 'normalized_term' => $row['normalized_term'], 'platform' => $row['platform'],
            'provider' => $row['provider'], 'observed_at' => $row['observed_at'], 'value_number' => $row['value_number'],
            'value_text' => $row['value_text'], 'raw_count' => $row['raw_count'], 'normalized_value' => $row['normalized_value'],
            'source_url' => $row['source_url'], 'canonical_hash' => $row['canonical_hash'], 'metadata' => $row['metadata'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
        $ok = $wpdb->insert(self::table_name(), $ordered, $formats);
        if ($ok) { return (int) $wpdb->insert_id; }
        // Phase 9A.4 — concurrent-replay safety: with the ws_canonical UNIQUE index,
        // a racing duplicate insert fails at the DB. Resolve deterministically by
        // re-reading the winning row and accumulating into it (same as the normal
        // dedup path) instead of failing the caller.
        $winner = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE workspace_id = %d AND canonical_hash = %s ORDER BY id ASC LIMIT 1',
            $norm['workspace_id'], $norm['canonical_hash']
        ));
        if ($winner > 0) {
            $cur = $wpdb->get_row($wpdb->prepare('SELECT value_number, raw_count FROM ' . self::table_name() . ' WHERE id = %d', $winner), ARRAY_A);
            $wpdb->update(self::table_name(), [
                'value_number' => round((float) ($cur['value_number'] ?? 0) + $norm['value_number'], 4),
                'raw_count' => max(0, (int) ($cur['raw_count'] ?? 0) + $norm['raw_count']),
                'updated_at' => $now,
            ], ['id' => $winner], ['%f', '%d', '%s'], ['%d']);
            return $winner;
        }
        return new WP_Error('ves_trend_insert_failed', 'Could not persist observation.');
    }

    /**
     * Phase 9A.4 — read-only idempotency migration report. dbDelta cannot add the
     * ws_canonical UNIQUE index while duplicate (workspace_id, canonical_hash)
     * rows exist; this reports duplicates and whether the index is live so the
     * operator can act. Writes nothing.
     */
    public static function idempotency_migration_report(): array {
        global $wpdb;
        $out = ['unique_index_present' => false, 'duplicate_groups' => 0, 'safe_to_index' => false, 'note' => ''];
        if (!isset($wpdb) || !is_object($wpdb)) { $out['note'] = 'db_unavailable'; return $out; }
        if (method_exists($wpdb, 'get_results')) {
            $indexes = $wpdb->get_results('SHOW INDEX FROM ' . self::table_name(), ARRAY_A);
            foreach ((array) $indexes as $ix) {
                if ((string) ($ix['Key_name'] ?? '') === 'ws_canonical' && (int) ($ix['Non_unique'] ?? 1) === 0) {
                    $out['unique_index_present'] = true;
                    break;
                }
            }
        }
        if (method_exists($wpdb, 'get_var')) {
            $dupes = $wpdb->get_var('SELECT COUNT(*) FROM (SELECT workspace_id, canonical_hash FROM ' . self::table_name() . " WHERE canonical_hash <> '' GROUP BY workspace_id, canonical_hash HAVING COUNT(*) > 1) d");
            $out['duplicate_groups'] = max(0, (int) $dupes);
        }
        $out['safe_to_index'] = $out['duplicate_groups'] === 0;
        if (!$out['unique_index_present'] && !$out['safe_to_index']) {
            $out['note'] = 'Duplicate observation groups exist; dbDelta cannot add the unique index until they are consolidated. Application-level dedup remains active.';
        }
        return $out;
    }

    /** List observations (workspace-scoped) for the series builder / diagnostics. */
    public static function list_observations(array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $where = '1=1'; $params = [];
        foreach ([
            'workspace_id' => '%d', 'normalized_term' => '%s', 'observation_type' => '%s', 'platform' => '%s', 'provider' => '%s', 'run_id' => '%s',
        ] as $col => $fmt) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $where .= " AND {$col} = {$fmt}";
                $params[] = $fmt === '%d' ? (int) $filters[$col] : (string) $filters[$col];
            }
        }
        if (!empty($filters['from'])) { $where .= ' AND observed_at >= %s'; $params[] = (string) $filters['from']; }
        if (!empty($filters['to'])) { $where .= ' AND observed_at <= %s'; $params[] = (string) $filters['to']; }
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 2000)));
        $sql = 'SELECT * FROM ' . self::table_name() . " WHERE {$where} ORDER BY observed_at ASC, id ASC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function count_by(string $column, int $workspace_id = 0, int $limit = 20): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $column = preg_replace('/[^a-z0-9_]/i', '', $column);
        $where = '1=1'; $params = [];
        if ($workspace_id > 0) { $where .= ' AND workspace_id = %d'; $params[] = $workspace_id; }
        $sql = "SELECT {$column} AS k, COUNT(*) AS c FROM " . self::table_name() . " WHERE {$where} GROUP BY {$column} ORDER BY c DESC LIMIT %d";
        $params[] = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = []; foreach ((array) $rows as $r) { $out[(string) ($r['k'] ?? '')] = (int) ($r['c'] ?? 0); }
        return $out;
    }

    /**
     * Distinct (workspace, normalized_term, observation_type, platform, provider)
     * groups for the rebuild service. @return array list of key arrays.
     */
    public static function distinct_terms(array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }
        $where = '1=1'; $params = [];
        foreach (['workspace_id' => '%d', 'observation_type' => '%s', 'platform' => '%s', 'provider' => '%s', 'normalized_term' => '%s'] as $col => $fmt) {
            if (isset($filters[$col]) && $filters[$col] !== '') { $where .= " AND {$col} = {$fmt}"; $params[] = $fmt === '%d' ? (int) $filters[$col] : (string) $filters[$col]; }
        }
        if (!empty($filters['from'])) { $where .= ' AND observed_at >= %s'; $params[] = (string) $filters['from']; }
        if (!empty($filters['to'])) { $where .= ' AND observed_at <= %s'; $params[] = (string) $filters['to']; }
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 1000)));
        $sql = 'SELECT workspace_id, normalized_term, observation_type, platform, provider, COUNT(*) AS point_count,
                       MIN(observed_at) AS first_seen_at, MAX(observed_at) AS last_seen_at
                FROM ' . self::table_name() . " WHERE {$where}
                GROUP BY workspace_id, normalized_term, observation_type, platform, provider
                ORDER BY point_count DESC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** Collect distinct linked source/signal/evidence ids for a term group. */
    public static function collect_links(array $filters = []): array {
        $rows = self::list_observations(array_merge($filters, ['limit' => 5000]));
        $out = ['source_ids' => [], 'signal_ids' => [], 'evidence_ids' => []];
        foreach ($rows as $r) {
            foreach (['source_id' => 'source_ids', 'signal_id' => 'signal_ids', 'evidence_id' => 'evidence_ids'] as $col => $key) {
                $id = (int) ($r[$col] ?? 0);
                if ($id > 0 && !in_array($id, $out[$key], true)) { $out[$key][] = $id; }
            }
        }
        return $out;
    }

    public static function total(int $workspace_id = 0): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return 0; }
        if ($workspace_id > 0) { return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE workspace_id = %d', $workspace_id)); }
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table_name());
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    public static function normalize_term(string $term, string $type = ''): string {
        $t = trim($term);
        if ($type === 'hashtag') { $t = ltrim($t, '#'); }
        $t = strtolower($t);
        $t = preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }

    private static function sanitize_text(string $v, int $max): string {
        $v = function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v));
        return substr($v, 0, $max);
    }
    private static function sanitize_key(string $v, int $max): string {
        $v = function_exists('sanitize_key') ? sanitize_key($v) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $v));
        return substr($v, 0, $max);
    }
    private static function sanitize_url(string $v): string {
        if ($v === '') { return ''; }
        $v = function_exists('esc_url_raw') ? esc_url_raw($v) : trim($v);
        return substr((string) $v, 0, 2048);
    }
    private static function sanitize_datetime($v): ?string {
        if (!is_string($v) || $v === '') { return null; }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) { return strlen($v) === 10 ? $v . ' 00:00:00' : $v; }
        $ts = strtotime($v);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
    private static function prepare_metadata($metadata): string {
        $metadata = is_array($metadata) ? $metadata : [];
        $clean = [];
        foreach ($metadata as $k => $v) {
            if (in_array(strtolower((string) $k), self::FORBIDDEN_META_KEYS, true)) { continue; }
            if (is_scalar($v) || $v === null) { $clean[strtolower((string) $k)] = is_string($v) ? self::scrub($v) : $v; }
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
