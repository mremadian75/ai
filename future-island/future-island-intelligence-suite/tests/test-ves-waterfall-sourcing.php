<?php
/**
 * Phase 3 — VES_Waterfall_Sourcing.
 * Self-contained; no external calls (provider callables are local closures).
 *
 * Run: php tests/test-ves-waterfall-sourcing.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
class WP_Error { public $code; public $message; public function __construct($c='',$m='',$d=[]){$this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($t){return $t instanceof WP_Error;}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
require_once dirname(__DIR__).'/includes/class-ves-waterfall-sourcing.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}};

$ctx = ['workspace_id'=>5,'module'=>'deep_trend_finder','operation_type'=>'source_enrichment'];

// 1. Stops on first success.
$calls = [];
$r = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c)use(&$calls){$calls[]='cache';return ['items'=>['a','b']];}],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c)use(&$calls){$calls[]='apify';return ['items'=>['x']];}],
]);
$ok($r['ok']===true && $r['provider']==='cache', 'stops on first success (cache wins)');
$ok($calls===['cache'], 'paid provider is NOT called after a cheap success');
$ok(count($r['attempts'])===1 && $r['attempts'][0]['status']==='success' && $r['attempts'][0]['cost_tier']==='free', 'attempt metadata: provider/status/cost_tier recorded');
$ok(isset($r['attempts'][0]['latency_ms']), 'attempt records latency_ms');

// 2. Empty fall-through, then success.
$calls=[];
$r2 = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c)use(&$calls){$calls[]='cache';return ['items'=>[]];}],       // empty
    ['provider'=>'existing_db','cost_tier'=>'free','callable'=>function($c)use(&$calls){$calls[]='db';return null;}],            // empty/null
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c)use(&$calls){$calls[]='apify';return ['items'=>['x']];}],    // success
]);
$ok($r2['ok']===true && $r2['provider']==='apify', 'empty/null results fall through to the paid provider');
$ok($r2['attempts'][0]['status']==='empty' && $r2['attempts'][1]['status']==='empty' && $r2['attempts'][2]['status']==='success', 'attempt statuses: empty, empty, success');
$ok($calls===['cache','db','apify'], 'all providers attempted in order until success');

// 3. Failed provider (WP_Error) falls through; error_code captured.
$r3 = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c){return new WP_Error('cache_miss','no');}],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c){return ['items'=>['x']];}],
]);
$ok($r3['attempts'][0]['status']==='failed' && $r3['attempts'][0]['error_code']==='cache_miss', 'WP_Error provider recorded as failed with error_code');
$ok($r3['ok']===true && $r3['provider']==='apify', 'falls through WP_Error to next provider');

// 4. Exception in provider becomes a failed attempt (never fatal).
$r4 = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c){throw new RuntimeException('boom');}],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c){return ['items'=>['x']];}],
]);
$ok($r4['attempts'][0]['status']==='failed' && $r4['attempts'][0]['error_code']==='exception', 'provider exception becomes failed attempt (recovered)');
$ok($r4['ok']===true, 'waterfall recovers from an exception and continues');

// 5. collect_all gathers all successes.
$r5 = VES_Waterfall_Sourcing::run(array_merge($ctx, ['collect_all'=>true]), [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c){return ['items'=>['a']];}],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c){return ['items'=>['b']];}],
]);
$ok($r5['ok']===true && count($r5['results'])===2 && isset($r5['results']['cache'], $r5['results']['apify']), 'collect_all continues and gathers all successful providers');

// 6. All fail/empty → ok=false, no provider.
$r6 = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'cache','cost_tier'=>'free','callable'=>function($c){return ['items'=>[]];}],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c){return new WP_Error('down','x');}],
]);
$ok($r6['ok']===false && $r6['provider']==='' && count($r6['attempts'])===2, 'all-empty/failed yields ok=false with full attempt trail');

// 7. Non-callable provider is skipped.
$r7 = VES_Waterfall_Sourcing::run($ctx, [
    ['provider'=>'broken','cost_tier'=>'free','callable'=>'not_a_function'],
    ['provider'=>'apify','cost_tier'=>'paid','callable'=>function($c){return ['items'=>['x']];}],
]);
$ok($r7['attempts'][0]['status']==='skipped' && $r7['ok']===true, 'non-callable provider is skipped, waterfall continues');

// 8. No secrets in attempt metadata (only provider/status/latency/cost_tier/usage/error).
$keys = array_keys($r['attempts'][0]);
sort($keys);
$ok($keys === ['cost_tier','error_code','latency_ms','provider','status','usage_event_id'], 'attempt metadata contains only safe keys (no payloads/secrets)');

echo "\nVES waterfall sourcing tests: {$pass} passed, {$fail} failed\n";
if($fail>0)exit(1);
