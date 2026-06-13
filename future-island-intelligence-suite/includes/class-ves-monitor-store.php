<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Monitor_Store {
    const OPTION_KEY = 'ves_saved_monitors';
    const TABLE_SLUG = 'ves_trend_monitors';
    const DB_VERSION = '1';
    const LOCK_KEY = 'ves_saved_monitors_lock';
    const LOCK_TTL = 60;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    private static function db_version_option() {
        return 'ves_trend_monitors_db_version';
    }

    public static function create_table() {
        $version_option = self::db_version_option();
        if (get_option($version_option) === self::DB_VERSION && self::table_exists()) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id varchar(80) NOT NULL,
            owner varchar(120) NOT NULL,
            owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            owner_email varchar(190) NULL,
            name varchar(190) NOT NULL,
            topic varchar(190) NOT NULL,
            country varchar(40) NOT NULL DEFAULT 'None',
            duration varchar(20) NOT NULL DEFAULT '7d',
            cadence varchar(20) NOT NULL DEFAULT 'manual',
            alert_threshold tinyint(3) unsigned NOT NULL DEFAULT 7,
            next_run_at datetime NULL,
            last_run_at datetime NULL,
            last_status varchar(24) NOT NULL DEFAULT 'idle',
            last_snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_average_score decimal(6,2) NULL,
            last_alerted_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            data_json longtext NULL,
            PRIMARY KEY  (id),
            KEY owner (owner),
            KEY owner_user_id (owner_user_id),
            KEY cadence (cadence),
            KEY last_status (last_status),
            KEY next_run_at (next_run_at),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option($version_option, self::DB_VERSION, false);
        self::migrate_option_to_table();
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table_has_rows() {
        if (!self::table_exists()) {
            return false;
        }
        global $wpdb;
        // v0.9.24.7: wrap in prepare per WPCS even for static table names.
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0;
    }

    private static function migrate_option_to_table() {
        if (!self::table_exists() || self::table_has_rows()) {
            return;
        }
        $option_items = get_option(self::OPTION_KEY, []);
        if (!is_array($option_items) || empty($option_items)) {
            return;
        }
        foreach ($option_items as $row) {
            $monitor = self::sanitize_monitor($row);
            if (($monitor['id'] ?? '') !== '') {
                self::upsert_table_row($monitor);
            }
        }
    }

    private static function acquire_lock($timeout_seconds = 5) {
        $timeout_seconds = max(1, (int) $timeout_seconds);
        $token = wp_generate_password(24, false, false) . ':' . microtime(true);
        $deadline = microtime(true) + $timeout_seconds;

        do {
            $existing = get_option(self::LOCK_KEY, null);
            if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] < time()) {
                delete_option(self::LOCK_KEY);
            }

            $created = add_option(self::LOCK_KEY, [
                'token' => $token,
                'expires_at' => time() + self::LOCK_TTL,
                'created_at' => time(),
            ], '', 'no');

            if ($created) {
                return $token;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function release_lock($token) {
        $token = (string) $token;
        if ($token === '') {
            return;
        }
        $existing = get_option(self::LOCK_KEY, null);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
            delete_option(self::LOCK_KEY);
        }
    }

    private static function lock_error() {
        return new WP_Error('monitor_store_locked', 'Monitor storage is temporarily locked. Please try again in a few seconds.');
    }

    private static function owner_key() {
        if (is_user_logged_in()) {
            return 'user:' . (int) get_current_user_id();
        }
        if (class_exists('VES_Temp_Memory')) {
            return 'session:' . sanitize_key((string) VES_Temp_Memory::maybe_set_session_cookie());
        }
        return 'session:guest';
    }

    private static function now_mysql() {
        return current_time('mysql');
    }

    private static function normalize_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    private static function next_run_for_cadence($cadence, $base_ts = null, $seed_immediate = false) {
        $cadence = sanitize_key((string) $cadence);
        if ($cadence === 'manual') {
            return '';
        }
        $base_ts = $base_ts ?: current_time('timestamp', true);
        if ($seed_immediate) {
            return gmdate('Y-m-d H:i:s', $base_ts + (15 * MINUTE_IN_SECONDS));
        }
        if ($cadence === 'daily') {
            return gmdate('Y-m-d H:i:s', $base_ts + DAY_IN_SECONDS);
        }
        if ($cadence === 'weekly') {
            return gmdate('Y-m-d H:i:s', $base_ts + WEEK_IN_SECONDS);
        }
        return '';
    }

    private static function owner_identity_defaults() {
        return [
            'owner' => self::owner_key(),
            'owner_user_id' => is_user_logged_in() ? (int) get_current_user_id() : 0,
            'owner_email' => is_user_logged_in() ? sanitize_email((string) wp_get_current_user()->user_email) : '',
        ];
    }

    private static function sanitize_monitor($row) {
        $row = is_array($row) ? $row : [];
        $allowed_cadence = ['manual', 'daily', 'weekly'];
        $allowed_status = ['idle', 'scheduled', 'running', 'succeeded', 'failed', 'paused'];
        $cadence = sanitize_key((string) ($row['cadence'] ?? 'manual'));
        if (!in_array($cadence, $allowed_cadence, true)) {
            $cadence = 'manual';
        }
        $status = sanitize_key((string) ($row['last_status'] ?? ($cadence === 'manual' ? 'idle' : 'scheduled')));
        if (!in_array($status, $allowed_status, true)) {
            $status = $cadence === 'manual' ? 'idle' : 'scheduled';
        }
        $monitor = [
            'id' => sanitize_text_field((string) ($row['id'] ?? '')),
            'name' => sanitize_text_field((string) ($row['name'] ?? 'Trend monitor')),
            'topic' => sanitize_text_field((string) ($row['topic'] ?? '')),
            'country' => sanitize_text_field((string) ($row['country'] ?? 'None')),
            'duration' => sanitize_key((string) ($row['duration'] ?? '7d')),
            'reason' => sanitize_textarea_field((string) ($row['reason'] ?? '')),
            'cadence' => $cadence,
            'alert_threshold' => max(1, min(10, (int) ($row['alert_threshold'] ?? 7))),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
            'next_run_at' => self::normalize_datetime($row['next_run_at'] ?? ''),
            'last_run_at' => self::normalize_datetime($row['last_run_at'] ?? ''),
            'last_status' => $status,
            'last_snapshot_id' => max(0, (int) ($row['last_snapshot_id'] ?? 0)),
            'last_average_score' => isset($row['last_average_score']) && $row['last_average_score'] !== '' ? round((float) $row['last_average_score'], 1) : null,
            'last_alerted_at' => self::normalize_datetime($row['last_alerted_at'] ?? ''),
            'last_run_note' => sanitize_text_field((string) ($row['last_run_note'] ?? '')),
            'last_alert_message' => sanitize_text_field((string) ($row['last_alert_message'] ?? '')),
            'owner' => sanitize_text_field((string) ($row['owner'] ?? '')),
            'owner_user_id' => max(0, (int) ($row['owner_user_id'] ?? 0)),
            'owner_email' => sanitize_email((string) ($row['owner_email'] ?? '')),
        ];
        if ($monitor['cadence'] === 'manual') {
            $monitor['next_run_at'] = '';
            if ($monitor['last_status'] === 'scheduled') {
                $monitor['last_status'] = 'idle';
            }
        }
        return $monitor;
    }

    private static function table_row_to_monitor($row) {
        $row = is_array($row) ? $row : [];
        $json = [];
        if (!empty($row['data_json'])) {
            $decoded = json_decode((string) $row['data_json'], true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        return self::sanitize_monitor(array_merge($json, [
            'id' => (string) ($row['id'] ?? ''),
            'owner' => (string) ($row['owner'] ?? ''),
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'topic' => (string) ($row['topic'] ?? ''),
            'country' => (string) ($row['country'] ?? 'None'),
            'duration' => (string) ($row['duration'] ?? '7d'),
            'cadence' => (string) ($row['cadence'] ?? 'manual'),
            'alert_threshold' => (int) ($row['alert_threshold'] ?? 7),
            'next_run_at' => (string) ($row['next_run_at'] ?? ''),
            'last_run_at' => (string) ($row['last_run_at'] ?? ''),
            'last_status' => (string) ($row['last_status'] ?? 'idle'),
            'last_snapshot_id' => (int) ($row['last_snapshot_id'] ?? 0),
            'last_average_score' => $row['last_average_score'] ?? null,
            'last_alerted_at' => (string) ($row['last_alerted_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ]));
    }

    private static function upsert_table_row($monitor) {
        if (!self::table_exists()) {
            return false;
        }
        global $wpdb;
        $monitor = self::sanitize_monitor($monitor);
        $id = sanitize_text_field((string) ($monitor['id'] ?? ''));
        if ($id === '') {
            return false;
        }
        $payload = wp_json_encode($monitor, JSON_UNESCAPED_UNICODE);
        return false !== $wpdb->replace(self::table_name(), [
            'id' => $id,
            'owner' => sanitize_text_field((string) ($monitor['owner'] ?? '')),
            'owner_user_id' => max(0, (int) ($monitor['owner_user_id'] ?? 0)),
            'owner_email' => sanitize_email((string) ($monitor['owner_email'] ?? '')),
            'name' => sanitize_text_field((string) ($monitor['name'] ?? 'Trend monitor')),
            'topic' => sanitize_text_field((string) ($monitor['topic'] ?? '')),
            'country' => sanitize_text_field((string) ($monitor['country'] ?? 'None')),
            'duration' => sanitize_key((string) ($monitor['duration'] ?? '7d')),
            'cadence' => sanitize_key((string) ($monitor['cadence'] ?? 'manual')),
            'alert_threshold' => max(1, min(10, (int) ($monitor['alert_threshold'] ?? 7))),
            'next_run_at' => !empty($monitor['next_run_at']) ? $monitor['next_run_at'] : null,
            'last_run_at' => !empty($monitor['last_run_at']) ? $monitor['last_run_at'] : null,
            'last_status' => sanitize_key((string) ($monitor['last_status'] ?? 'idle')),
            'last_snapshot_id' => max(0, (int) ($monitor['last_snapshot_id'] ?? 0)),
            'last_average_score' => isset($monitor['last_average_score']) && $monitor['last_average_score'] !== '' ? (float) $monitor['last_average_score'] : null,
            'last_alerted_at' => !empty($monitor['last_alerted_at']) ? $monitor['last_alerted_at'] : null,
            'created_at' => !empty($monitor['created_at']) ? $monitor['created_at'] : self::now_mysql(),
            'updated_at' => !empty($monitor['updated_at']) ? $monitor['updated_at'] : self::now_mysql(),
            'data_json' => is_string($payload) ? $payload : '{}',
        ], [
            '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d','%f','%s','%s','%s','%s'
        ]);
    }

    private static function all() {
        if (self::table_exists()) {
            self::migrate_option_to_table();
            global $wpdb;
            // v0.9.24.7: use prepare-compatible form.
            $table = self::table_name();
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC", ARRAY_A);
            if (is_array($rows)) {
                return array_map([__CLASS__, 'table_row_to_monitor'], $rows);
            }
        }
        $value = get_option(self::OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    private static function save_all($items) {
        $items = array_values(array_filter(array_map([__CLASS__, 'sanitize_monitor'], (array) $items), static function ($row) {
            return is_array($row) && (string) ($row['id'] ?? '') !== '';
        }));

        if (self::table_exists()) {
            global $wpdb;
            $wpdb->query('DELETE FROM ' . self::table_name());
            foreach ($items as $item) {
                self::upsert_table_row($item);
            }
            return;
        }

        update_option(self::OPTION_KEY, array_values($items), false);
    }

    public static function get_monitors() {
        $owner = self::owner_key();
        $items = array_values(array_filter(self::all(), static function ($row) use ($owner) {
            return is_array($row) && (string) ($row['owner'] ?? '') === $owner;
        }));
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return array_map([__CLASS__, 'sanitize_monitor'], $items);
    }

    public static function get_monitors_for_user($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $items = array_values(array_filter(self::all(), static function ($row) use ($user_id) {
            return is_array($row) && (int) ($row['owner_user_id'] ?? 0) === $user_id;
        }));
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return array_map([__CLASS__, 'sanitize_monitor'], $items);
    }

    public static function get_monitor_for_user($id, $user_id) {
        $id = sanitize_text_field((string) $id);
        $user_id = (int) $user_id;
        if ($id === '' || $user_id <= 0) {
            return null;
        }
        foreach (self::get_monitors_for_user($user_id) as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    public static function get_monitor($id) {
        $id = sanitize_text_field((string) $id);
        if ($id === '') {
            return null;
        }
        foreach (self::get_monitors() as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    public static function get_monitor_any_owner($id) {
        $id = sanitize_text_field((string) $id);
        if ($id === '') {
            return null;
        }
        foreach (self::all() as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                return self::sanitize_monitor($row);
            }
        }
        return null;
    }

    public static function current_owner_can_access_monitor($id) {
        if (current_user_can('manage_options')) {
            return true;
        }
        $monitor = self::get_monitor_any_owner($id);
        if (!$monitor) {
            return true;
        }
        return (string) ($monitor['owner'] ?? '') === self::owner_key();
    }

    public static function save_monitor($data) {
        $token = self::acquire_lock();
        if (!$token) {
            return self::lock_error();
        }

        try {
            $data = is_array($data) ? $data : [];
            $identity = self::owner_identity_defaults();
            $monitor = self::sanitize_monitor(array_merge($data, [
                'owner' => $identity['owner'],
                'owner_user_id' => $identity['owner_user_id'],
                'owner_email' => $identity['owner_email'],
            ]));
            if ($monitor['topic'] === '') {
                return new WP_Error('missing_topic', 'El monitor necesita un tema o categoría.');
            }
            if ($monitor['name'] === '' || $monitor['name'] === 'Trend monitor') {
                $monitor['name'] = $monitor['topic'];
            }
            if ($monitor['id'] === '') {
                try {
                    $monitor['id'] = 'vesm-' . strtolower(wp_generate_uuid4());
                } catch (Throwable $e) {
                    $monitor['id'] = 'vesm-' . strtolower(wp_generate_password(16, false, false));
                }
            }
            $now = self::now_mysql();
            $monitor['updated_at'] = $now;
            if ($monitor['created_at'] === '') {
                $monitor['created_at'] = $now;
            }
            if ($monitor['cadence'] === 'manual') {
                $monitor['next_run_at'] = '';
                if ($monitor['last_status'] === 'scheduled') {
                    $monitor['last_status'] = 'idle';
                }
            } elseif ($monitor['next_run_at'] === '') {
                $monitor['last_status'] = $monitor['last_status'] === 'running' ? 'running' : 'scheduled';
                $monitor['next_run_at'] = self::next_run_for_cadence($monitor['cadence'], current_time('timestamp', true), true);
            }

            $items = self::all();
            $saved = false;
            foreach ($items as $idx => $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $monitor['id'] && (string) ($row['owner'] ?? '') === $monitor['owner']) {
                    $existing = self::sanitize_monitor($row);
                    foreach (['last_run_at','next_run_at','last_status','last_snapshot_id','last_average_score','last_alerted_at','last_run_note','last_alert_message','owner','owner_user_id','owner_email','created_at'] as $preserve_key) {
                        $incoming = $monitor[$preserve_key] ?? null;
                        $existing_value = $existing[$preserve_key] ?? null;
                        if ($incoming === '' || $incoming === null || $incoming === 0 || $incoming === 'idle') {
                            if ($existing_value !== '' && $existing_value !== null) {
                                $monitor[$preserve_key] = $existing_value;
                            }
                        }
                    }
                    if ($monitor['cadence'] !== 'manual' && ($monitor['next_run_at'] ?? '') === '') {
                        $monitor['next_run_at'] = $existing['next_run_at'] ?? '';
                    }
                    if (($data['cadence'] ?? '') !== 'manual' && !empty($existing['last_status']) && empty($data['last_status'])) {
                        $monitor['last_status'] = $existing['last_status'];
                    }
                    $items[$idx] = $monitor;
                    $saved = true;
                    break;
                }
            }
            if (!$saved) {
                $items[] = $monitor;
            }
            self::save_all($items);
            return self::sanitize_monitor($monitor);
        } finally {
            self::release_lock($token);
        }
    }

    public static function delete_monitor($id) {
        $token = self::acquire_lock();
        if (!$token) {
            return false;
        }

        try {
            $id = sanitize_text_field((string) $id);
            if ($id === '') {
                return false;
            }
            $owner = self::owner_key();
            $before = self::all();
            $after = array_values(array_filter($before, static function ($row) use ($id, $owner) {
                return !(is_array($row) && (string) ($row['id'] ?? '') === $id && (string) ($row['owner'] ?? '') === $owner);
            }));
            if (count($before) === count($after)) {
                return false;
            }
            self::save_all($after);
            return true;
        } finally {
            self::release_lock($token);
        }
    }

    public static function get_due_monitors($limit = 3) {
        $limit = max(1, min(10, (int) $limit));
        $now_ts = current_time('timestamp', true);
        $items = [];
        foreach (self::all() as $row) {
            $monitor = self::sanitize_monitor($row);
            if ($monitor['owner_user_id'] <= 0 || $monitor['cadence'] === 'manual' || $monitor['last_status'] === 'running') {
                continue;
            }
            $next_ts = $monitor['next_run_at'] !== '' ? strtotime($monitor['next_run_at']) : 0;
            if ($next_ts && $next_ts > $now_ts) {
                continue;
            }
            $items[] = $monitor;
        }
        usort($items, static function ($a, $b) {
            return strcmp((string) ($a['next_run_at'] ?? ''), (string) ($b['next_run_at'] ?? ''));
        });
        return array_slice($items, 0, $limit);
    }

    public static function reserve_due_monitor($id) {
        $token = self::acquire_lock();
        if (!$token) {
            return null;
        }

        try {
            $id = sanitize_text_field((string) $id);
            if ($id === '') {
                return null;
            }
            $items = self::all();
            foreach ($items as $idx => $row) {
                $monitor = self::sanitize_monitor($row);
                if ($monitor['id'] !== $id) {
                    continue;
                }
                if ($monitor['cadence'] === 'manual' || $monitor['last_status'] === 'running' || (int) ($monitor['owner_user_id'] ?? 0) <= 0) {
                    return null;
                }
                $next_ts = $monitor['next_run_at'] !== '' ? strtotime($monitor['next_run_at']) : 0;
                if ($next_ts && $next_ts > current_time('timestamp', true)) {
                    return null;
                }
                $monitor['last_status'] = 'running';
                $monitor['last_run_note'] = 'Monitor ejecutándose en segundo plano.';
                $monitor['updated_at'] = self::now_mysql();
                $items[$idx] = $monitor;
                self::save_all($items);
                return $monitor;
            }
            return null;
        } finally {
            self::release_lock($token);
        }
    }

    public static function complete_monitor_run($id, $args = []) {
        $token = self::acquire_lock();
        if (!$token) {
            return null;
        }

        try {
            $id = sanitize_text_field((string) $id);
            $args = is_array($args) ? $args : [];
            if ($id === '') {
                return null;
            }
            $items = self::all();
            foreach ($items as $idx => $row) {
                $monitor = self::sanitize_monitor($row);
                if ($monitor['id'] !== $id) {
                    continue;
                }
                $status = sanitize_key((string) ($args['last_status'] ?? 'succeeded'));
                if (!in_array($status, ['succeeded', 'failed', 'scheduled', 'idle'], true)) {
                    $status = 'succeeded';
                }
                $monitor['last_status'] = $status;
                $monitor['last_run_at'] = self::now_mysql();
                $monitor['updated_at'] = $monitor['last_run_at'];
                $monitor['last_snapshot_id'] = max(0, (int) ($args['last_snapshot_id'] ?? $monitor['last_snapshot_id'] ?? 0));
                $monitor['last_average_score'] = isset($args['last_average_score']) && $args['last_average_score'] !== '' ? round((float) $args['last_average_score'], 1) : $monitor['last_average_score'];
                $monitor['last_run_note'] = sanitize_text_field((string) ($args['last_run_note'] ?? $monitor['last_run_note'] ?? ''));
                if (!empty($args['last_alert_message'])) {
                    $monitor['last_alert_message'] = sanitize_text_field((string) $args['last_alert_message']);
                    $monitor['last_alerted_at'] = self::now_mysql();
                }
                if ($monitor['cadence'] === 'manual') {
                    $monitor['next_run_at'] = '';
                } elseif ($status !== 'running') {
                    $monitor['next_run_at'] = self::next_run_for_cadence($monitor['cadence'], current_time('timestamp', true), false);
                }
                $items[$idx] = $monitor;
                self::save_all($items);
                return $monitor;
            }
            return null;
        } finally {
            self::release_lock($token);
        }
    }

    public static function fail_monitor_run($id, $note = '') {
        return self::complete_monitor_run($id, [
            'last_status' => 'failed',
            'last_run_note' => sanitize_text_field((string) $note),
        ]);
    }
}
