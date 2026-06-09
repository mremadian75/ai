<?php
/**
 * Phase 1 cost-spine unit tests — VES_Cost_Estimator + VES_AI_Usage_Tracker.
 *
 * Self-contained: defines minimal WP shims + a capturing MockWpdb. Makes NO real
 * API calls (it only feeds the tracker pre-decoded fake OpenAI responses).
 *
 * Run: php tests/test-ves-cost-spine.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0) { return json_encode($d, $o); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-06-06 12:00:00'; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return (int) ($GLOBALS['__uid'] ?? 0); } }
if (!function_exists('dbDelta')) { function dbDelta($sql) { $GLOBALS['__dbdelta'][] = $sql; return []; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__tr'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $t = 0) { $GLOBALS['__tr'][$k] = $v; return true; } }
$GLOBALS['__tr'] = [];

class MockWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $inserts = [];
    public $show_tables = true;
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function query($sql) { return true; }
    public function insert($table, $row, $formats = null) { $this->insert_id++; $this->inserts[] = ['table' => $table, 'row' => $row, 'formats' => $formats]; return 1; }
    public function prepare($sql, $args = []) { return is_array($args) ? vsprintf(str_replace(['%s','%d','%f'], ['%s','%d','%f'], $sql), array_map('strval', $args)) : $sql; }
    public function get_var($sql) { return $this->show_tables ? 'wp_ves_ai_usage_events' : null; }
    public function get_results($sql, $type = null) { return $GLOBALS['__rows'] ?? []; }
    public function get_row($sql, $type = null) { return $GLOBALS['__row'] ?? null; }
}
$GLOBALS['wpdb'] = new MockWpdb();

require_once dirname(__DIR__) . '/includes/class-ves-cost-estimator.php';
require_once dirname(__DIR__) . '/includes/class-ves-ai-usage-tracker.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$approx = function ($a, $b, $eps = 0.0001) { return abs((float) $a - (float) $b) < $eps; };

// ── VES_Cost_Estimator ───────────────────────────────────────────────────────
$p = VES_Cost_Estimator::model_pricing('gpt-4.1-mini');
$ok($p['source'] === 'known' && $approx($p['input'], 0.40), 'known model pricing resolves (gpt-4.1-mini input=0.40)');

$p2 = VES_Cost_Estimator::model_pricing('gpt-4.1-mini-2025-04-14');
$ok($p2['source'] === 'prefix' && $p2['matched'] === 'gpt-4.1-mini', 'dated model id resolves via longest-prefix match');

$p3 = VES_Cost_Estimator::model_pricing('totally-unknown-xyz');
$ok($p3['source'] === 'fallback', 'unknown model falls back to conservative pricing');

$cost = VES_Cost_Estimator::estimate_provider_cost('gpt-4.1-mini', 1000000, 1000000);
$ok($approx($cost, 2.00), 'provider cost math: 1M in + 1M out @ gpt-4.1-mini = $2.00');

$tok = VES_Cost_Estimator::estimate_tokens_from_text(str_repeat('a', 400));
$ok($tok === 115, 'token estimate is conservative (400 chars -> 115 tokens)');

$credits = VES_Cost_Estimator::estimate_credits_from_usd(0.02);
$ok($approx($credits, 1.0), 'credit estimate: $0.02 -> 1.0 credit at default ratio');

$est = VES_Cost_Estimator::estimate_operation(['model' => 'gpt-4.1-mini', 'input_text' => str_repeat('x', 4000), 'max_output_tokens' => 800]);
$ok(isset($est['estimated_provider_cost'], $est['estimated_credits'], $est['input_tokens']) && $est['input_tokens'] > 0, 'estimate_operation returns full estimate shape');

// ── VES_AI_Usage_Tracker::record (success, Responses usage) ──────────────────
$GLOBALS['wpdb']->inserts = [];
$resp = ['output' => 'ok', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150]];
$id = VES_AI_Usage_Tracker::record_openai_call([
    'module' => 'unit', 'operation_type' => 'analyze', 'model' => 'gpt-4.1-mini',
    'started_at' => microtime(true) - 0.05, 'response' => $resp,
    'metadata' => ['purpose' => 'fast_analysis'],
]);
$row = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok($id > 0, 'record() returns an insert id');
$ok((int) $row['input_tokens'] === 100 && (int) $row['output_tokens'] === 50 && (int) $row['total_tokens'] === 150, 'record() captures Responses-API token usage');
$ok($row['status'] === 'completed' && $row['provider'] === 'openai', 'record() marks success as completed/openai');
// A1: a completed call with real usage populates ACTUAL cost, not estimated.
$ok($approx($row['actual_provider_cost'], 0.00012), 'A1: completed call populates actual_provider_cost from real tokens (0.00012)');
$ok($approx($row['estimated_provider_cost'], 0.0), 'A1: completed call does NOT mislabel actual cost as estimated (estimated=0)');
$ok((int) $row['latency_ms'] >= 40, 'record() computes latency_ms from started_at');

// A1: an explicit preflight-style estimated row populates ESTIMATED cost, not actual.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record(['module' => 'unit', 'operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'status' => 'estimated', 'input_tokens' => 100, 'output_tokens' => 50]);
$rowEst = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok($approx($rowEst['estimated_provider_cost'], 0.00012) && $approx($rowEst['actual_provider_cost'], 0.0), 'A1: estimated row populates estimated_provider_cost and leaves actual=0');

// A1b: a completed call with REAL usage but an UNKNOWN model (fallback/approximate
// pricing, pricing_source != 'known') must report cost as ESTIMATED, not ACTUAL —
// we only claim "actual" when the price itself is known (deep-debug honesty fix).
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record(['module' => 'unit', 'operation_type' => 'analyze', 'model' => 'some-unlisted-model-xyz', 'status' => 'completed', 'input_tokens' => 100, 'output_tokens' => 50]);
$rowApprox = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok(($rowApprox['pricing_source'] ?? '') !== 'known', 'A1b: unknown model yields a non-"known" pricing_source');
$ok($approx($rowApprox['actual_provider_cost'] ?? -1, 0.0), 'A1b: approximate-priced completed call does NOT report actual cost');
$ok(($rowApprox['estimated_provider_cost'] ?? 0) > 0, 'A1b: approximate-priced completed call reports cost as estimated');

// A1: a failed call never fabricates an actual cost.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['module' => 'unit', 'operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'response' => new WP_Error('upstream_unavailable', 'down'), 'input_tokens' => 100, 'output_tokens' => 50]);
$rowFail = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok($rowFail['status'] === 'failed' && $approx($rowFail['actual_provider_cost'], 0.0) && $rowFail['error_code'] === 'upstream_unavailable', 'A1: failed call records error_code and does not fake actual cost');

// A2: ambient request context fills workspace/run/module when not passed explicitly.
VES_AI_Usage_Tracker::set_context(['workspace_id' => 42, 'run_id' => 'run-xyz', 'module' => 'ambient_mod', 'user_id' => 9]);
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'response' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]]);
$rowCtx = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok((int) $rowCtx['workspace_id'] === 42 && $rowCtx['run_id'] === 'run-xyz' && $rowCtx['module'] === 'ambient_mod' && (int) $rowCtx['user_id'] === 9, 'A2: ambient context fills workspace_id/run_id/module/user_id');
// Explicit args still override ambient.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'workspace_id' => 7, 'response' => ['usage' => []]]);
$ok((int) ($GLOBALS['wpdb']->inserts[0]['row']['workspace_id'] ?? 0) === 7, 'A2: explicit workspace_id overrides ambient');
VES_AI_Usage_Tracker::clear_context();
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'response' => ['usage' => []]]);
$ok((int) ($GLOBALS['wpdb']->inserts[0]['row']['workspace_id'] ?? -1) === 0, 'A2: clear_context() resets ambient to 0');

// Fix 4: try/finally clears ambient context even when the operation throws.
VES_AI_Usage_Tracker::set_context(['workspace_id' => 77]);
try {
    try { throw new RuntimeException('boom'); }
    finally { VES_AI_Usage_Tracker::clear_context(); }
} catch (\Throwable $e) { /* swallow */ }
$ok(empty(VES_AI_Usage_Tracker::get_context()), 'Fix4: try/finally clears ambient context on an exception path (no leak)');

// A5: scrape telemetry groundwork records provider=apify with actor/dataset metadata.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_scrape(['module' => 'social_scraper', 'model' => '', 'actor_id' => 'apify/x', 'dataset_id' => 'ds_1', 'item_count' => 25, 'status' => 'completed']);
$rowS = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$metaS = json_decode((string) ($rowS['metadata'] ?? '{}'), true);
$ok($rowS['provider'] === 'apify' && $rowS['operation_type'] === 'scrape' && ($metaS['actor_id'] ?? '') === 'apify/x' && (int) ($metaS['item_count'] ?? 0) === 25, 'A5: record_scrape stores apify provider + actor/dataset/item_count metadata');

// ── record (WP_Error -> failed) ──────────────────────────────────────────────
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['module' => 'unit', 'operation_type' => 'analyze', 'model' => 'gpt-4.1-mini', 'response' => new WP_Error('rate_limited', 'slow down')]);
$rowE = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok($rowE['status'] === 'failed' && $rowE['error_code'] === 'rate_limited', 'record() maps WP_Error to failed + error_code');
$ok((int) $rowE['total_tokens'] === 0, 'record() failed call has zero tokens');

// ── record (Chat-shape usage) ────────────────────────────────────────────────
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['module' => 'unit', 'operation_type' => 'x', 'model' => 'gpt-4.1-mini', 'response' => ['usage' => ['prompt_tokens' => 30, 'completion_tokens' => 20, 'total_tokens' => 50]]]);
$rowC = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok((int) $rowC['input_tokens'] === 30 && (int) $rowC['output_tokens'] === 20, 'record() maps Chat-API prompt/completion tokens');

// ── cache_hit flag ───────────────────────────────────────────────────────────
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['module' => 'unit', 'operation_type' => 'x', 'model' => 'gpt-4.1-mini', 'cache_hit' => true, 'response' => ['usage' => []]]);
$ok((int) ($GLOBALS['wpdb']->inserts[0]['row']['cache_hit'] ?? 0) === 1, 'record() persists cache_hit=1');

// ── forbidden metadata is dropped + secrets scrubbed ─────────────────────────
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call([
    'module' => 'unit', 'operation_type' => 'x', 'model' => 'gpt-4.1-mini', 'response' => ['usage' => []],
    'metadata' => ['prompt' => 'SECRET PROMPT TEXT', 'api_key' => 'sk-LEAKEDKEY1234567', 'note' => 'safe', 'leaked' => 'token Bearer abc.def.ghi'],
]);
$meta_json = (string) ($GLOBALS['wpdb']->inserts[0]['row']['metadata'] ?? '{}');
$meta = json_decode($meta_json, true);
$ok(strpos($meta_json, 'SECRET PROMPT TEXT') === false, 'metadata never stores raw prompt text');
$ok(!isset($meta['prompt']) && !isset($meta['api_key']), 'forbidden metadata keys (prompt, api_key) are dropped');
$ok(($meta['note'] ?? '') === 'safe', 'safe metadata keys are preserved');
$ok(isset($meta['leaked']) && strpos($meta['leaked'], 'Bearer abc') === false, 'bearer token in metadata value is redacted');

// ── scrub_secrets + extract_usage_tokens ─────────────────────────────────────
$scrubbed = VES_AI_Usage_Tracker::scrub_secrets('key sk-ABCDEFGH123456 and Bearer xy.z and apify_api_ABCDEFGH123');
$ok(strpos($scrubbed, 'sk-ABCDEFGH') === false && strpos($scrubbed, 'apify_api_ABC') === false && strpos($scrubbed, 'Bearer xy') === false, 'scrub_secrets redacts OpenAI/Apify/Bearer secrets');

$t = VES_AI_Usage_Tracker::extract_usage_tokens(['input_tokens' => 7, 'output_tokens' => 3]);
$ok($t['input'] === 7 && $t['output'] === 3 && $t['total'] === 10, 'extract_usage_tokens derives total when absent');

// ── preflight (advisory, non-blocking with no billing) ───────────────────────
$pf = VES_AI_Usage_Tracker::preflight(['model' => 'gpt-4.1-mini', 'input_text' => 'hello world', 'max_output_tokens' => 200, 'operation_type' => 'analyze', 'module' => 'unit']);
$ok($pf['allowed'] === true && is_float($pf['estimated_credits']) && $pf['reason'] === 'advisory_estimate_recorded', 'preflight is advisory/non-blocking and returns an estimate');

// Fix 2: preflight with record_estimate=true persists an 'estimated' usage row.
$GLOBALS['wpdb']->inserts = [];
$pf2 = VES_AI_Usage_Tracker::preflight(['model' => 'gpt-4.1-mini', 'input_text' => str_repeat('z', 800), 'max_output_tokens' => 500, 'operation_type' => 'trend_analysis', 'module' => 'deep_trend_finder', 'workspace_id' => 11, 'user_id' => 4, 'run_id' => 'r1', 'record_estimate' => true]);
$rowPf = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok(!empty($rowPf), 'Fix2: preflight(record_estimate) inserts a usage row');
$ok(($rowPf['status'] ?? '') === 'estimated', 'Fix2: persisted preflight row has status=estimated');
$ok(($rowPf['estimated_provider_cost'] ?? 0) > 0 && $approx($rowPf['actual_provider_cost'] ?? -1, 0.0), 'Fix2: estimated row populates estimated cost, not actual');
$ok((int) ($rowPf['workspace_id'] ?? 0) === 11 && $rowPf['module'] === 'deep_trend_finder' && $rowPf['operation_type'] === 'trend_analysis' && $rowPf['run_id'] === 'r1', 'Fix2: estimated row carries module/operation/workspace/run');
$ok($pf2['allowed'] === true, 'Fix2: preflight remains non-blocking with record_estimate');

// ── create_table idempotent ──────────────────────────────────────────────────
$GLOBALS['__opts'] = [];
VES_AI_Usage_Tracker::create_table();
$first = get_option(VES_AI_Usage_Tracker::VERSION_OPT);
VES_AI_Usage_Tracker::create_table(); // second call should early-return on version match
$ok($first === VES_AI_Usage_Tracker::DB_VERSION, 'create_table sets the schema version option (idempotent guard)');

// ── Phase 3B Part B: usage_event_id return + last_event_id ───────────────────
$GLOBALS['wpdb']->inserts = [];
$oid = VES_AI_Usage_Tracker::record_openai_call(['module'=>'u','operation_type'=>'x','model'=>'gpt-4.1-mini','response'=>['usage'=>['input_tokens'=>1,'output_tokens'=>1,'total_tokens'=>2]]]);
$ok(is_int($oid) && $oid>0, 'B: record_openai_call returns inserted usage_event_id');
$ok(VES_AI_Usage_Tracker::last_event_id('openai')===$oid, 'B: last_event_id("openai") returns the last openai event id');

$pfB = VES_AI_Usage_Tracker::preflight(['model'=>'gpt-4.1-mini','input_text'=>'hello','max_output_tokens'=>100,'operation_type'=>'x','module'=>'u','record_estimate'=>true]);
$ok(isset($pfB['usage_event_id']) && (int)$pfB['usage_event_id']>0, 'B: preflight(record_estimate) returns usage_event_id');

// ── Part C: record_scrape returns id, telemetry_key dedup guard ──────────────
$GLOBALS['wpdb']->inserts = [];
$sid1 = VES_AI_Usage_Tracker::record_scrape(['module'=>'deep_trend_finder','actor_id'=>'a/b','item_count'=>5,'status'=>'completed','telemetry_key'=>'run1:src1']);
$ok(is_int($sid1) && $sid1>0, 'C: record_scrape returns inserted usage_event_id');
$ok(VES_AI_Usage_Tracker::last_event_id('apify')===$sid1, 'C: last_event_id("apify") tracks scrape events');
$sid2 = VES_AI_Usage_Tracker::record_scrape(['module'=>'deep_trend_finder','actor_id'=>'a/b','item_count'=>5,'status'=>'completed','telemetry_key'=>'run1:src1']);
$ok($sid2===0, 'C: duplicate telemetry_key is guarded (returns 0, no second row)');

// ── Part D: row-level pricing_source ─────────────────────────────────────────
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_scrape(['module'=>'deep_trend_finder','actor_id'=>'a/b','item_count'=>3,'status'=>'completed']);
$scrapeRow = $GLOBALS['wpdb']->inserts[0]['row'] ?? [];
$ok(($scrapeRow['pricing_source'] ?? '')==='unavailable', 'D: scrape row stores pricing_source=unavailable at row level');
$ok($approx($scrapeRow['actual_provider_cost'] ?? -1, 0.0) && $approx($scrapeRow['estimated_provider_cost'] ?? -1, 0.0), 'D: scrape row has zero cost (no fabrication)');

// explicit pricing_source override on an LLM-style row.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record(['module'=>'u','operation_type'=>'x','model'=>'gpt-4.1-mini','status'=>'completed','input_tokens'=>10,'output_tokens'=>5,'pricing_source'=>'manual_override']);
$ok(($GLOBALS['wpdb']->inserts[0]['row']['pricing_source'] ?? '')==='manual_override', 'D: explicit pricing_source overrides the model-derived value');
// normal LLM record still derives model pricing source.
$GLOBALS['wpdb']->inserts = [];
VES_AI_Usage_Tracker::record_openai_call(['module'=>'u','operation_type'=>'x','model'=>'gpt-4.1-mini','response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>5,'total_tokens'=>15]]]);
$ok(($GLOBALS['wpdb']->inserts[0]['row']['pricing_source'] ?? '')==='known', 'D: LLM record still derives model pricing_source ("known")');

echo "\nVES cost-spine tests: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
