<?php
/**
 * v0.1 RC — rc-readiness-check service contract.
 *
 * Proves:
 *  1. with core services missing the report is BLOCKED and says which ones
 *  2. with a healthy stack the report is ready_* but live validation reads
 *     UNRUN until an operator records it — and production_ready is ALWAYS false
 *  3. safety probes actually probe (a broken trust boundary / matrix blocks)
 *  4. feature flags ON produce warnings
 *  5. report() never mutates state (no update_option calls)
 *  6. output is JSON-encodable and carries checked_at
 *
 * Run: php tests/test-ves-rc-readiness-check.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opt_writes'][] = $k; $GLOBALS['__opts'][$k] = $v; return true; }
function current_time($t = 'mysql', $g = 0) { return '2026-06-11 11:00:00'; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
$GLOBALS['__opts'] = [];
$GLOBALS['__opt_writes'] = [];

require_once dirname(__DIR__) . '/includes/class-ves-rc-readiness-service.php';

$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: $l\n"); } };

// ── 1. Nothing loaded → BLOCKED, live validation UNRUN, never production ────
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'blocked', 'empty stack reports blocked');
$ok(($r['live_validation']['status'] ?? '') === 'unrun', 'live validation reads UNRUN when never recorded');
$ok(($r['production_ready'] ?? true) === false, 'production_ready is false');
$ok(!empty($r['blockers']) && strpos(implode(' ', $r['blockers']), 'VES_Intelligence_Store') !== false, 'missing core services are named as blockers');
$ok(!empty($r['checked_at']), 'checked_at present');
$ok(is_string(json_encode($r)) && json_encode($r) !== false, 'report is JSON-encodable');
$warn_text = implode(' ', (array) $r['warnings']);
$ok(strpos($warn_text, 'UNRUN') !== false, 'warnings call out the unrun live validation');

// ── 2. Healthy stack stubs ───────────────────────────────────────────────────
// NOTE: declared inside a conditional block so PHP binds them at RUNTIME —
// scenario 1 above must run with no services defined.
if (!defined('FIS_VERSION')) { define('FIS_VERSION', '1.3.0'); }
if (!defined('FIS_RC_LABEL')) { define('FIS_RC_LABEL', 'v0.1-rc1'); }
if (!class_exists('VES_Intelligence_Store')) {
final class VES_Intelligence_Store {
    public static $matrix_active = true;
    public static function insight_transition_matrix_active() { return self::$matrix_active; }
}
final class VES_Insight_Evidence_Validator {
    public static $let_empty_pass = false;
    public static function validate_insight($insight, $ctx) { return ['minimum_requirements_met' => self::$let_empty_pass]; }
}
final class VES_Insight_Lifecycle_Service {}
final class VES_Brand_Context_Service {
    public static $trust_everything = false;
    public static function is_trusted($row) {
        if (self::$trust_everything) { return true; }
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['active', 'pinned'], true)) { return false; }
        $exp = (string) ($row['expires_at'] ?? '');
        if ($exp !== '' && strtotime($exp) < strtotime('2026-06-11')) { return false; }
        return true;
    }
}
final class VES_Generation_Context_Resolver {}
final class VES_Generation_Prompt_Package_Builder {
    public static function execution_enabled() { return (bool) get_option('ves_generation_execution_enabled', false); }
}
final class VES_Operator_Queue_Service { public static function build(array $a = []) { return []; } }
final class VES_Signal_Room { public static function render_html($w = 0) { return ''; } }
final class VES_Workbench { public static function render_brief($a = []) { return ''; } public static function render_draft($a = []) { return ''; } }
final class VES_Review_State {}
final class VES_AI_Usage_Tracker {}
final class VES_Usage_Billing {
    public static function reserve_usage($a = null, $b = null, $c = null, $d = null, $e = null) {}
    public static function settle_reserved_usage($a = null, $b = null, $c = null, $d = null) {}
    public static function void_reserved_usage($a = null, $b = null) {}
}
final class VES_Apify_Client { const MIN_CHARGE_CEILING_USD = 0.1; const MAX_CHARGE_CEILING_USD = 50.0; }
final class VES_Apify_Actor_Registry { public static function is_allowed_slug($s) { return false; } }
final class VES_Trend_Observation_Store {
    public static function idempotency_migration_report() { return ['unique_index_present' => true, 'duplicate_groups' => 0, 'safe_to_index' => true, 'note' => '']; }
}
final class VES_Staging_Validation_Service { public static function schema_health() { return ['status' => 'ok', 'missing' => [], 'available' => true]; } }
final class VES_Config {
    public static $token = '';
    public static function hard_max_charge_usd() { return 3.0; }
    public static function get_token() { return self::$token; }
}
final class VES_Release_Candidate_Page {}
// Phase 9 production-rails stubs (probe-compatible).
final class VES_Workspace_Guard {
    public static $active = true;
    public static function guard_active() { return self::$active; }
}
final class VES_Review_Decision_Ledger {
    public static $active = true;
    public static function ledger_active() { return self::$active; }
}
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
final class VES_Job_Rails {
    public static $dead = 0;
    public static function status() { return ['available' => true, 'tracked_keys' => 0, 'dead_letters' => self::$dead, 'max_retries' => 3, 'healthy' => self::$dead === 0]; }
}
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
// Phase 9D egress stubs (probe-compatible).
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
} // end runtime-bound stub block

$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') !== 'blocked', 'healthy stack is not blocked');
$ok(($r['status'] ?? '') === 'ready_with_warnings', 'healthy stack with unrun live validation is ready_with_warnings');
$ok(($r['production_ready'] ?? true) === false, 'production_ready stays false even when healthy');

// ── 3. Phase 9B.3 — manual option is NOT trusted; evidence pack is ───────────
VES_Config::$token = 'apify_api_x';
$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'recorded_at' => '2026-06-12 09:00:00', 'note' => 'ops signoff'];
$r = VES_RC_Readiness_Service::report();
$ok(($r['live_validation']['status'] ?? '') === 'unverified_manual', 'manually-written option WITHOUT evidence pack reads unverified_manual, never passed');
$ok(($r['status'] ?? '') === 'ready_with_warnings', 'manual option keeps the report at ready_with_warnings');

$GLOBALS['__opts']['ves_rc_live_validation'] = ['status' => 'passed', 'source' => 'evidence_pack', 'evidence_pack_hash' => str_repeat('ab', 32), 'files_verified' => true, 'recorded_at' => '2026-06-12 09:00:00'];
$r = VES_RC_Readiness_Service::report();
$ok(($r['live_validation']['status'] ?? '') === 'passed', 'evidence-pack-backed validation is surfaced as passed');
$ok(($r['status'] ?? '') === 'ready_for_staging', 'fully healthy + evidence-backed validation reaches ready_for_staging');
$ok(($r['production_ready'] ?? true) === false, 'production_ready is STILL false — command cannot grant it');
unset($GLOBALS['__opts']['ves_rc_live_validation']);

// ── 4. Safety probes really probe ────────────────────────────────────────────
VES_Brand_Context_Service::$trust_everything = true;
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'blocked' && strpos(implode(' ', $r['blockers']), 'Untrusted memory') !== false, 'broken memory trust boundary BLOCKS');
VES_Brand_Context_Service::$trust_everything = false;

VES_Intelligence_Store::$matrix_active = false;
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'blocked', 'inactive transition matrix BLOCKS');
VES_Intelligence_Store::$matrix_active = true;

VES_Insight_Evidence_Validator::$let_empty_pass = true;
$r = VES_RC_Readiness_Service::report();
$ok(strpos(implode(' ', $r['blockers']), 'Evidence gate FAILED') !== false, 'evidence gate that passes zero evidence BLOCKS');
VES_Insight_Evidence_Validator::$let_empty_pass = false;

// ── 5. Feature flag ON warns ─────────────────────────────────────────────────
$GLOBALS['__opts']['ves_generation_execution_enabled'] = true;
$r = VES_RC_Readiness_Service::report();
$ok(($r['status'] ?? '') === 'ready_with_warnings' && strpos(implode(' ', $r['warnings']), 'ves_generation_execution_enabled') !== false, 'generation execution flag ON produces a warning');
$GLOBALS['__opts']['ves_generation_execution_enabled'] = false;

// ── 6. No mutation ───────────────────────────────────────────────────────────
$GLOBALS['__opt_writes'] = [];
VES_RC_Readiness_Service::report();
$ok($GLOBALS['__opt_writes'] === [], 'report() performed zero option writes (read-only)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
