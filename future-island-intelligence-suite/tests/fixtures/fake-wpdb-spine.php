<?php
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/../../'); }
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($v) { return $v instanceof WP_Error; } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = false) { return '2026-06-13 12:00:00'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 42; } }
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $GLOBALS['fi_options'][$k] ?? $default; } }
if (!function_exists('update_option')) { function update_option($k, $v, $autoload = null) { $GLOBALS['fi_options'][$k] = $v; return true; } }
if (!function_exists('dbDelta')) { function dbDelta($sql) { $GLOBALS['fi_dbdelta_sql'][] = $sql; return []; } }

class FI_Spine_Fake_WPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $tables = [];
    public $queries = [];
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function query($sql) { $this->queries[] = $sql; return true; }
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%(d|f|s)/', static function ($m) use (&$args, &$i) {
            $arg = $args[$i++] ?? '';
            if ($m[1] === 'd') { return (string) (int) $arg; }
            if ($m[1] === 'f') { return (string) (float) $arg; }
            return "'" . str_replace("'", "''", (string) $arg) . "'";
        }, $query);
    }
    public function insert($table, $data, $formats = null) {
        if (!isset($this->tables[$table])) { $this->tables[$table] = []; }
        $this->insert_id++;
        $row = $data;
        $row['id'] = $this->insert_id;
        $this->tables[$table][] = $row;
        return 1;
    }
    public function update($table, $data, $where, $formats = null, $where_formats = null) {
        if (!isset($this->tables[$table])) { return 0; }
        $n = 0;
        foreach ($this->tables[$table] as &$row) {
            $ok = true;
            foreach ((array) $where as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) { $ok = false; break; }
            }
            if ($ok) { foreach ($data as $k => $v) { $row[$k] = $v; } $n++; }
        }
        unset($row);
        return $n;
    }
    public function get_row($sql, $output = null) { $rows = $this->filter_rows($sql); return $rows[0] ?? null; }
    public function get_results($sql, $output = null) {
        $rows = $this->filter_rows($sql);
        usort($rows, static function ($a, $b) { return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0); });
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) { $rows = array_slice($rows, 0, (int) $m[1]); }
        return $rows;
    }
    public function get_var($sql) { $row = $this->get_row($sql, ARRAY_A); if (!is_array($row) || empty($row)) { return null; } return reset($row); }
    private function filter_rows($sql) {
        if (!preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $sql, $m)) { return []; }
        $table = $m[1];
        $rows = $this->tables[$table] ?? [];
        $conds = [];
        if (preg_match_all('/\b([a-zA-Z0-9_]+)\s*=\s*(\'[^\']*\'|[0-9]+)/', $sql, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) { if (ctype_digit((string) $c[1])) { continue; } $conds[$c[1]] = trim($c[2], "'"); }
        }
        return array_values(array_filter($rows, static function ($row) use ($conds) {
            foreach ($conds as $k => $v) {
                if ((string) ($row[$k] ?? '') !== (string) $v) { return false; }
            }
            return true;
        }));
    }
}

function fi_spine_reset_db() {
    $GLOBALS['wpdb'] = new FI_Spine_Fake_WPDB();
    $GLOBALS['fi_options'] = [];
    $GLOBALS['fi_dbdelta_sql'] = [];
}
function fi_spine_ok($condition, $message) {
    global $fi_spine_checks, $fi_spine_failures;
    $fi_spine_checks++;
    if (!$condition) { $fi_spine_failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}
function fi_spine_finish($label) {
    global $fi_spine_checks, $fi_spine_failures;
    $failed = count($fi_spine_failures);
    if ($failed > 0) { echo $label . ': ' . ($fi_spine_checks - $failed) . " passed, {$failed} failed\n"; exit(1); }
    echo $label . " passed: {$fi_spine_checks} / {$fi_spine_checks}\n";
}
$fi_spine_checks = 0;
$fi_spine_failures = [];
fi_spine_reset_db();
