<?php
/**
 * Phase 9D/9E low fix — memory set_status logging honesty (real service).
 *
 * Proves:
 *  1. a SUCCESSFUL DB update logs status_changed and writes the review ledger
 *  2. a FAILED DB update logs status_change_failed, writes NO ledger decision,
 *     never logs status_changed, and returns false
 *
 * Run: php tests/test-ves-memory-status-log-9de.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function wp_json_encode($d,$o=0){return json_encode($d,$o);}
function current_time($t='mysql',$g=0){return '2026-06-15 14:00:00';}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
function get_current_user_id(){return 6;}
function apply_filters($t,$v){return $v;}
$GLOBALS['__o']=[];

final class VES_Log {
    public static $events = [];
    public static function info($mod, $msg, $ctx = []) { self::$events[] = ['info', $mod, $msg, $ctx]; }
    public static function warn($mod, $msg, $ctx = []) { self::$events[] = ['warn', $mod, $msg, $ctx]; }
}
final class VES_Memory_Records { public static function table_name() { return 'wp_ves_memory_records'; } }
final class VES_Review_Decision_Ledger {
    public static $records = [];
    public static function record(array $a) { self::$records[] = $a; return count(self::$records); }
    public static function transition_allowed($t, $f, $to) { return !in_array($f, ['rejected', 'archived', 'expired'], true); }
}

class MemWpdb {
    public $fail_update = false;
    public $row;
    public function get_row($sql, $o = null) { return $this->row; }
    public function prepare($sql, $args = []) { return $sql; }
    public function update($t, $d, $w) { return $this->fail_update ? false : 1; }
}
$GLOBALS['wpdb'] = new MemWpdb();
$GLOBALS['wpdb']->row = [
    'id' => 5, 'workspace_id' => 2, 'memory_type' => 'voice_rule', 'is_pinned' => 0,
    'content_json' => json_encode(['brand_context' => ['status' => 'candidate']]),
];

require_once dirname(__DIR__) . '/includes/class-ves-brand-context-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Success path: status_changed + ledger ─────────────────────────────────
VES_Log::$events = []; VES_Review_Decision_Ledger::$records = [];
$res = VES_Brand_Context_Service::set_status(5, 'active', 6);
$log = json_encode(VES_Log::$events);
$ok($res === true, 'successful update returns true');
$ok(strpos($log, 'status_changed') !== false, 'success logs status_changed');
$ok(strpos($log, 'status_change_failed') === false, 'success never logs status_change_failed');
$ok(count(VES_Review_Decision_Ledger::$records) === 1, 'success writes exactly one ledger decision');

// ── 2. Failure path: status_change_failed, NO ledger, false ─────────────────
VES_Log::$events = []; VES_Review_Decision_Ledger::$records = [];
$GLOBALS['wpdb']->fail_update = true;
$res = VES_Brand_Context_Service::set_status(5, 'active', 6);
$log = json_encode(VES_Log::$events);
$ok($res === false, 'failed update returns false');
$ok(strpos($log, 'status_change_failed') !== false, 'failure logs status_change_failed');
$ok(strpos($log, '"status_changed"') === false && strpos($log, 'Brand context status_changed') === false, 'failure NEVER logs status_changed');
$ok(count(VES_Review_Decision_Ledger::$records) === 0, 'failure writes no ledger decision');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
