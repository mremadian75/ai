<?php
if (!defined('ABSPATH')) { exit; }

/** Append/idempotency ledger for provider row ingestion. */
final class VES_Provider_Ingestion_Store {
    const TABLE_SLUG = 'ves_provider_ingestions';
    const DB_VERSION = '1.1.0';
    const VERSION_OPT = 'ves_provider_ingestions_db_version';

    public static function table_name() { global $wpdb; return $wpdb->prefix . self::TABLE_SLUG; }

    public static function create_table($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $table = self::table_name();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            run_id bigint(20) unsigned NOT NULL,
            provider_family varchar(60) NOT NULL,
            provider_key varchar(80) NOT NULL,
            provider_run_id varchar(191) NOT NULL DEFAULT '',
            idempotency_key varchar(191) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'completed',
            row_count int unsigned NOT NULL DEFAULT 0,
            accepted_count int unsigned NOT NULL DEFAULT 0,
            rejected_count int unsigned NOT NULL DEFAULT 0,
            result_json longtext NULL,
            reviewed_at datetime NULL,
            archived_at datetime NULL,
            updated_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY status (status),
            KEY provider (provider_family, provider_key),
            KEY created_at (created_at),
            UNIQUE KEY idempotency_key (idempotency_key)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    public static function find(string $idempotency_key) {
        global $wpdb;
        $idempotency_key = self::clean_text($idempotency_key, 191);
        if ($idempotency_key === '' || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE idempotency_key = %s LIMIT 1', $idempotency_key), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    public static function get(int $id) {
        global $wpdb;
        if ($id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    public static function list(array $filters = []): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return []; }
        $built = self::build_where($filters);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql = 'SELECT * FROM ' . self::table_name();
        if (!empty($built['where'])) { $sql .= ' WHERE ' . implode(' AND ', $built['where']); }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $args = array_merge($built['args'], [500]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        $decoded = array_map([__CLASS__, 'decode'], is_array($rows) ? $rows : []);
        $filtered = self::post_filter($decoded, $filters);
        return array_slice($filtered, $offset, $limit);
    }

    public static function count(array $filters = []): int {
        $filters['limit'] = 500;
        $filters['offset'] = 0;
        return count(self::list($filters));
    }

    public static function bulk_mark_reviewed(array $ids): int {
        $count = 0;
        foreach (self::int_list($ids) as $id) { if (self::mark_reviewed($id)) { $count++; } }
        return $count;
    }

    public static function bulk_archive(array $ids): int {
        $count = 0;
        foreach (self::int_list($ids) as $id) { if (self::archive($id)) { $count++; } }
        return $count;
    }

    public static function void_usage_if_no_value(int $id) {
        $row = self::get($id);
        if (!is_array($row)) { return self::err('ves_ingestion_not_found', 'Provider ingestion not found.'); }
        if (self::created_value_count($row) > 0 || (int) ($row['accepted_count'] ?? 0) > 0) {
            return self::err('ves_ingestion_void_blocked_value_created', 'Usage void blocked because accepted rows or canonical value were created.');
        }
        return self::void_usage($id) ? true : self::err('ves_ingestion_void_failed', 'Provider ingestion usage could not be voided.');
    }

    private static function build_where(array $filters): array {
        $where = [];
        $args = [];
        foreach (['workspace_id','run_id'] as $int_key) {
            $value = max(0, (int) ($filters[$int_key] ?? 0));
            if ($value > 0) { $where[] = $int_key . ' = %d'; $args[] = $value; }
        }
        foreach (['provider_family' => 60, 'provider_key' => 80, 'status' => 40] as $key => $max) {
            $value = self::clean_key((string) ($filters[$key] ?? ''), $max);
            if ($value !== '') { $where[] = $key . ' = %s'; $args[] = $value; }
        }
        return ['where' => $where, 'args' => $args];
    }

    private static function post_filter(array $rows, array $filters): array {
        $reviewed = self::clean_key((string) ($filters['reviewed'] ?? ''), 20);
        $has_rejected = array_key_exists('has_rejected', $filters) ? $filters['has_rejected'] : '';
        $date_from = self::date_value((string) ($filters['date_from'] ?? ''));
        $date_to = self::date_value((string) ($filters['date_to'] ?? ''));
        return array_values(array_filter($rows, static function($row) use ($reviewed, $has_rejected, $date_from, $date_to) {
            if ($reviewed === 'reviewed' && empty($row['reviewed_at'])) { return false; }
            if ($reviewed === 'unreviewed' && !empty($row['reviewed_at'])) { return false; }
            if ($has_rejected !== '') {
                $wanted = !empty($has_rejected);
                if (((int) ($row['rejected_count'] ?? 0) > 0) !== $wanted) { return false; }
            }
            $created = (string) ($row['created_at'] ?? '');
            if ($date_from !== '' && $created < $date_from) { return false; }
            if ($date_to !== '' && $created > $date_to) { return false; }
            return true;
        }));
    }

    public static function record(array $args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return 0; }
        $result = is_array($args['result'] ?? null) ? $args['result'] : [];
        $now = self::now();
        $row = [
            'workspace_id' => max(0, (int) ($args['workspace_id'] ?? 0)),
            'run_id' => max(0, (int) ($args['run_id'] ?? 0)),
            'provider_family' => self::clean_key((string) ($args['provider_family'] ?? ''), 60),
            'provider_key' => self::clean_key((string) ($args['provider_key'] ?? ''), 80),
            'provider_run_id' => self::clean_text((string) ($args['provider_run_id'] ?? ''), 191),
            'idempotency_key' => self::clean_text((string) ($args['idempotency_key'] ?? ''), 191),
            'status' => self::status((string) ($args['status'] ?? 'completed')),
            'row_count' => max(0, (int) ($args['row_count'] ?? 0)),
            'accepted_count' => max(0, (int) ($args['accepted_count'] ?? 0)),
            'rejected_count' => max(0, (int) ($args['rejected_count'] ?? 0)),
            'result_json' => self::encode(class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($result) : $result),
            'updated_at' => $now,
            'created_at' => $now,
        ];
        if ($row['workspace_id'] <= 0 || $row['run_id'] <= 0 || $row['idempotency_key'] === '') { return 0; }
        $existing = self::find($row['idempotency_key']);
        if (is_array($existing)) { return (int) ($existing['id'] ?? 0); }
        $ok = $wpdb->insert(self::table_name(), $row, ['%d','%d','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s']);
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    public static function update_status(int $id, string $status, array $extra = []) {
        global $wpdb;
        if ($id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return false; }
        $row = ['status' => self::status($status), 'updated_at' => self::now()];
        foreach (['reviewed_at','archived_at'] as $field) { if (isset($extra[$field])) { $row[$field] = self::clean_text((string) $extra[$field], 30); } }
        $formats = array_fill(0, count($row), '%s');
        return $wpdb->update(self::table_name(), $row, ['id' => $id], $formats, ['%d']) !== false;
    }

    public static function mark_reviewed(int $id) { return self::update_status($id, 'reviewed', ['reviewed_at' => self::now()]); }
    public static function archive(int $id) { return self::update_status($id, 'archived', ['archived_at' => self::now()]); }

    public static function request_reprocess(int $id) { return self::update_status($id, 'reprocess_requested'); }

    public static function void_usage(int $id) {
        $row = self::get($id);
        if (!is_array($row)) { return false; }
        if (class_exists('VES_Usage_Service')) {
            VES_Usage_Service::void_provider_ingestion([
                'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                'run_id' => (int) ($row['run_id'] ?? 0),
                'target_type' => 'provider_ingestion',
                'target_id' => (string) $id,
                'idempotency_key' => 'void-provider-ingestion-' . $id,
                'module' => 'provider_contract',
                'message' => 'Provider ingestion usage voided by operator',
                'context' => ['provider_family' => (string) ($row['provider_family'] ?? ''), 'provider_key' => (string) ($row['provider_key'] ?? '')],
            ]);
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::append((int) ($row['workspace_id'] ?? 0), (int) ($row['run_id'] ?? 0), 'info', 'provider_ingestion', 'usage_voided', 'Provider ingestion usage void requested.', ['ingestion_id' => $id]);
        }
        return self::update_status($id, 'voided');
    }

    private static function created_value_count(array $row): int {
        $result = is_array($row['result'] ?? null) ? $row['result'] : [];
        $created = is_array($result['created'] ?? null) ? $result['created'] : [];
        $count = 0;
        foreach (['sources','signals','evidence','insights','briefs','drafts'] as $bucket) {
            $count += is_array($created[$bucket] ?? null) ? count($created[$bucket]) : 0;
        }
        return $count;
    }

    private static function decode(array $row): array {
        foreach (['id','workspace_id','run_id','row_count','accepted_count','rejected_count'] as $k) { $row[$k] = (int) ($row[$k] ?? 0); }
        $j = json_decode((string) ($row['result_json'] ?? ''), true);
        $row['result'] = class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact(is_array($j) ? $j : []) : (is_array($j) ? $j : []);
        unset($row['result_json']);
        return $row;
    }
    private static function status(string $s): string { $s = self::clean_key($s, 40); return in_array($s, ['completed','partial','failed','reviewed','voided','reprocess_requested','archived'], true) ? $s : 'completed'; }
    private static function encode($v): string { $j = function_exists('wp_json_encode') ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : json_encode($v); return is_string($j) ? $j : '{}'; }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function date_value(string $s): string { $s = trim($s); return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $s) ? $s : ''; }
    private static function int_list(array $ids): array { $out = []; foreach ($ids as $id) { $id = (int) $id; if ($id > 0) { $out[] = $id; } } return array_values(array_unique($out)); }
    private static function is_err($v): bool { return function_exists('is_wp_error') ? is_wp_error($v) : ($v instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message]; }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
}
