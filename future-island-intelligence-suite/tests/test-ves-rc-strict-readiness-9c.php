<?php
/**
 * Phase 9C.4 — strict readiness mode contract (real readiness service).
 *
 * Proves:
 *  1. strict WITHOUT any live validation => blocked
 *  2. strict with only a MANUAL option => blocked (unverified_manual)
 *  3. strict with a valid evidence pack but a hard rail missing => blocked
 *  4. strict with open settlement_required markers => blocked
 *  5. strict with everything green => ready_for_pilot_review, production_ready
 *     STILL false
 *  6. non-strict behavior is unchanged by the flag's absence
 *
 * Run: php tests/test-ves-rc-strict-readiness-9c.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.4.0'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc2'); }

function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-14 14:00:00'; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function get_current_user_id() { return 1; }
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
$GLOBALS['__opts'] = [];

// Healthy-stack stubs (probe-compatible with every readiness check).
final class VES_Intelligence_Store { public static function insight_transition_matrix_active() { return true; } }
final class VES_Insight_Evidence_Validator { public static function validate_insight($i, $c) { return ['minimum_requirements_met' => false]; } }
final class VES_Insight_Lifecycle_Service {}
final class VES_Brand_Context_Service { public static function is_trusted($row) { return in_array((string)($row['status'] ?? ''), ['active','pinned'], true) && empty($row['expires_at']); } }
final class VES_Generation_Context_Resolver {}
final class VES_Generation_Prompt_Package_Builder { public static function execution_enabled() { return false; } }
final class VES_Operator_Queue_Service { public static function build(array $a = []) { return []; } }
final class VES_Signal_Room { public static function render_html($w = 0) { return ''; } }
final class VES_Workbench { public static function render_brief($a = []) { return ''; } public static function render_draft($a = []) { return ''; } }
final class VES_Review_State {}
final class VES_AI_Usage_Tracker {}
final class VES_Usage_Billing {
    public static function reserve_usage($a=null,$b=null,$c=null,$d=null,$e=null) {}
    public static function settle_reserved_usage($a=null,$b=null,$c=null,$d=null) {}
    public static function void_reserved_usage($a=null,$b=null) {}
}
final class VES_Apify_Client { const MIN_CHARGE_CEILING_USD = 0.1; const MAX_CHARGE_CEILING_USD = 50.0; }
final class VES_Apify_Actor_Registry { public static function is_allowed_slug($s) { return false; } }
final class VES_Trend_Observation_Store {
    public static $index = true;
    public static function idempotency_migration_report() { return ['unique_index_present' => self::$index, 'duplicate_groups' => 0, 'safe_to_index' => true, 'note' => '']; }
}
final class VES_Staging_Validation_Service { public static function schema_health() { return ['status' => 'ok', 'missing' => [], 'available' => true]; } }
final class VES_Config { public static function hard_max_charge_usd() { return 3.0; } public static function get_token() { return 'apify_api_x'; } }
final class VES_Release_Candidate_Page {}
final class VES_Workspace_Guard { public static $active = true; public static function guard_active() { return self::$active; } }
final class VES_Review_Decision_Ledger { public static function ledger_active() { return true; } }
final class VES_Usage_Settlement {
    public static $required = 0;
    public static function semantics_active() { return true; }
    public static function settlement_health() { return ['available' => true, 'reserved_stale' => 0, 'settlement_required' => self::$required, 'healthy' => self::$required === 0]; }
}
final class VES_Security_Event_Log {
    public static function summary() { return ['total' => 0, 'by_type' => [], 'available' => true]; }
    public static function record($t, $d, $c = []) { return true; }
    public static function scrub($s) { return (string) $s; }
}
final class VES_Job_Rails { public static function status() { return ['available' => true, 'tracked_keys' => 0, 'dead_letters' => 0, 'max_retries' => 3, 'healthy' => true]; } }
final class VES_RC_Evidence_Pack {
    public static function live_validation_state() {
        $raw = get_option('ves_rc_live_validation', []);
        if (!is_array($raw) || empty($raw['status'])) { return ['status' => 'unrun', 'recorded_at' => '', 'evidence_pack_hash' => '', 'note' => '']; }
        $hash = strtolower((string) ($raw['evidence_pack_hash'] ?? ''));
        $is_pack = (string) ($raw['source'] ?? '') === 'evidence_pack' && preg_match('/^[a-f0-9]{64}$/', $hash);
        if ($is_pack && !empty($raw['files_verified']) && $raw['status'] === 'passed') {
            return ['status' => 'passed', 'files_verified' => true, 'recorded_at' => (string) ($raw['recorded_at'] ?? ''), 'evidence_pack_hash' => $hash, 'note' => ''];
        }
        if ($is_pack) { return ['status' => 'json_only_unverified', 'recorded_at' => (string) ($raw['recorded_at'] ?? ''), 'evidence_pack_hash' => '', 'note' => '']; }
        return ['status' => 'unverified_manual', 'recorded_at' => (string) ($raw['recorded_at'] ?? ''), 'evidence_pack_hash' => '', 'note' => ''];
    }
}
final class VES_External_Egress_Inventory {
    public static $unknown = 0;
    public static function inventory() { return []; }
    public static function summary() { return ['available' => true, 'total' => 23, 'by_classification' => [], 'unknown_count' => self::$unknown, 'unguarded_run_start_count' => 0, 'single_dispatch_gate' => self::$unknown === 0]; }
    public static function for_provider($p) {
        if ($p === 'openai') { return [['provider'=>'openai','class'=>'VES_OpenAI_Client','method'=>'request','classification'=>'ai_provider_gated','guarded'=>true,'notes'=>[]]]; }
        if ($p === 'stripe') { return [['provider'=>'stripe','class'=>'VES_Stripe_Billing','method'=>'api_request','classification'=>'billing_provider_explicit','guarded'=>true,'notes'=>[]]]; }
        return [];
    }
}

require_once dirname(__DIR__) . '/includes/class-ves-rc-readiness-service.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};

// ── 1. Strict without evidence => blocked ────────────────────────────────────
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'blocked', 'strict without evidence pack is BLOCKED');
$ok(strpos(implode(' ', $r['blockers']), 'verified evidence pack') !== false, 'strict blocker names the missing evidence pack');
$ok(($r['production_ready'] ?? true) === false, 'production_ready false');

// ── 2. Strict with manual option only => blocked ─────────────────────────────
$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'recorded_at' => '2026-06-14', 'note' => 'manual'];
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'blocked', 'strict with manual-only option is BLOCKED');
$ok(strpos(implode(' ', $r['blockers']), 'unverified_manual') !== false, 'strict blocker names unverified_manual');

// ── 2b. Phase 9E: JSON-only pack state (no files_verified) => blocked ────────
$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => str_repeat('cd', 32), 'recorded_at' => '2026-06-14'];
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'blocked', 'strict with a JSON-only (file-unverified) pack is BLOCKED');
$ok(strpos(implode(' ', $r['blockers']), 'json_only_unverified') !== false, 'strict blocker names json_only_unverified');

// ── 3. Valid evidence but a hard rail missing => blocked ─────────────────────
$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => str_repeat('cd', 32), 'files_verified' => true, 'recorded_at' => '2026-06-14'];
VES_Workspace_Guard::$active = false;
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'blocked', 'strict with broken workspace guard is BLOCKED despite valid evidence');
$ok(strpos(implode(' ', $r['blockers']), 'hard rail not fully active') !== false, 'strict blocker names the inactive rail');
VES_Workspace_Guard::$active = true;

// ── 4. Open settlement markers => blocked ────────────────────────────────────
VES_Usage_Settlement::$required = 2;
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'blocked' && strpos(implode(' ', $r['blockers']), 'settlement_required') !== false, 'strict with open settlement markers is BLOCKED');
VES_Usage_Settlement::$required = 0;

// ── 5. Everything green => ready_for_pilot_review, never production ─────────
$r = VES_RC_Readiness_Service::report(['strict' => true]);
$ok(($r['status'] ?? '') === 'ready_for_pilot_review', 'strict all-green is ready_for_pilot_review');
$ok(($r['production_ready'] ?? true) === false, 'strict pass STILL does not grant production_ready');
$ok(($r['strict'] ?? false) === true, 'report flags strict mode');

// ── 6. Non-strict unchanged ──────────────────────────────────────────────────
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'ready_for_staging', 'non-strict with evidence-backed pass stays ready_for_staging');
$ok(($r['strict'] ?? true) === false, 'non-strict report not flagged strict');
unset($GLOBALS['__opts']['ves_rc_live_validation']);
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'ready_with_warnings', 'non-strict without validation stays ready_with_warnings');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
