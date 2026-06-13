<?php
/**
 * Phase 9D — external egress lockdown contract.
 *
 * Proves:
 *  1. the egress inventory classifies Apify, OpenAI, Stripe, DTF adapters and
 *     MarketSignal with ZERO unknown egress and the single-dispatch-gate flag
 *  2. grep-backed: NO production file performs a direct wp_remote_post Apify
 *     run-start — every run-start goes through VES_Apify_Client::request()
 *  3. MarketSignal routes through the core client, includes the ceiling, and
 *     fails closed without the client (source-level + no direct token dispatch)
 *  4. both DTF adapters' direct run-start paths are fail-closed/core-routed
 *  5. AI provider calls are classified/key-gated; no generation flag flip
 *  6. billing (Stripe) is classified billing_provider_explicit
 *
 * Run: php tests/test-ves-egress-lockdown-9d.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function get_option($k,$d=false){return $GLOBALS['__o'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__o'][$k]=$v;return true;}
$GLOBALS['__o']=[];

require_once dirname(__DIR__) . '/includes/class-ves-external-egress-inventory.php';

$pass=0;$fail=0;
$ok=function($c,$l)use(&$pass,&$fail){if($c){$pass++;}else{$fail++;fwrite(STDERR,"FAIL: $l\n");}};
$root = dirname(__DIR__);

// ── 1. Inventory completeness ─────────────────────────────────────────────────
$rows = VES_External_Egress_Inventory::inventory();
$sum  = VES_External_Egress_Inventory::summary();
$providers = array_unique(array_map(function ($r) { return $r['provider']; }, $rows));
foreach (['apify', 'openai', 'stripe', 'public_web'] as $p) {
    $ok(in_array($p, $providers, true), "inventory covers provider: {$p}");
}
$classes = wp_json_encode_compat($rows);
function wp_json_encode_compat($d) { return json_encode($d); }
foreach (['VES_Market_Signal_Commercial', 'FIDTF_Generic_Apify_Live_Adapter', 'FIDTF_TikTok_Live_Adapter', 'FIDTF_Core_Apify_Client_Adapter', 'VES_Stripe_Billing', 'VES_OpenAI_Client'] as $cls) {
    $ok(strpos($classes, $cls) !== false, "inventory lists {$cls}");
}
$ok((int) $sum['unknown_count'] === 0, 'ZERO unknown external egress');
$ok(!empty($sum['single_dispatch_gate']), 'inventory reports the single dispatch gate intact');
foreach ($rows as $r) {
    if (!$r['guarded']) { $ok(false, 'unguarded egress row: ' . $r['class']); }
}
$ok(true, 'every inventory row is guarded');

// ── 2. Grep-backed: no direct Apify run-start wp_remote_post in production ───
$offenders = [];
$scan = function ($dir) use (&$scan, &$offenders) {
    foreach ((array) glob($dir . '/*') as $p) {
        if (is_dir($p)) {
            if (strpos($p, '/tests') !== false || strpos($p, '/docs') !== false) { continue; }
            $scan($p);
            continue;
        }
        if (substr($p, -4) !== '.php') { continue; }
        $src = (string) file_get_contents($p);
        // A direct run-start = wp_remote_post whose nearby URL construction targets
        // /acts/... or actor-tasks runs/run-sync. Whitelist: the core client itself.
        if (strpos($p, 'class-ves-apify-client.php') !== false) { continue; }
        if (!preg_match('/wp_remote_post\s*\(/', $src)) { continue; }
        if (preg_match('/wp_remote_post\s*\(\s*[^)]{0,160}(\/acts\/|actor-tasks|run-sync|\/runs)/s', $src)) {
            $offenders[] = $p;
        }
        // Also: any file that builds an api.apify.com acts/run URL AND calls wp_remote_post anywhere.
        if (strpos($src, 'api.apify.com') !== false
            && preg_match('/(\/acts\/|actor-tasks).{0,120}(runs|run-sync)/s', $src)
            && preg_match('/wp_remote_post\s*\(\s*\$?(url|run_url|dispatch)/', $src)) {
            $offenders[] = $p;
        }
    }
};
$scan($root . '/includes');
$scan($root . '/modules');
$offenders = array_unique($offenders);
$ok(count($offenders) === 0, 'no production file dispatches an Apify run-start via wp_remote_post' . (count($offenders) ? ' — offenders: ' . implode(', ', $offenders) : ''));

// ── 3. MarketSignal routed through the core client ────────────────────────────
$ms = (string) file_get_contents($root . '/includes/class-ves-market-signal-commercial.php');
$ok(strpos($ms, "VES_Apify_Client::request('POST', \$url, \$input)") !== false, 'MarketSignal dispatches through VES_Apify_Client::request');
$ok(strpos($ms, "'maxTotalChargeUsd' => max(0.1, \$ceiling)") !== false, 'MarketSignal run URL carries maxTotalChargeUsd');
$ok(strpos($ms, 'ves_ms_apify_client_unavailable') !== false, 'MarketSignal fails closed when the core client is unavailable');
$rs_start = strpos($ms, 'private static function run_apify_sync');
$rs_end = strpos($ms, 'private static function', $rs_start + 10);
$run_sync_fn = substr($ms, $rs_start, $rs_end !== false ? $rs_end - $rs_start : 3200);
$ok(!preg_match('/wp_remote_post\\s*\\(/', $run_sync_fn), 'MarketSignal run path contains no direct wp_remote_post call');
$ok(strpos($run_sync_fn, 'Bearer') === false, 'MarketSignal run path no longer handles the token directly');

// ── 4. DTF adapters fail closed / core-routed ────────────────────────────────
$gen = (string) file_get_contents($root . '/modules/deep-trend-finder/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$ok(strpos($gen, 'source_dispatch_blocked_fail_closed') !== false, 'DTF generic adapter is fail-closed');
$ok(strpos($gen, 'FIDTF_Core_Apify_Client_Adapter::available()') !== false, 'DTF generic adapter routes through the core adapter');
$gen_start = substr($gen, strpos($gen, 'private function start_direct_apify_run'), 1600);
$ok(strpos($gen_start, 'wp_remote_post') === false || strpos($gen_start, 'never an unguarded wp_remote_post') !== false, 'DTF generic direct start contains no wp_remote_post call');
$ok(!preg_match('/wp_remote_post\s*\(/', $gen_start), 'DTF generic direct start performs zero direct HTTP');

$tik = (string) file_get_contents($root . '/modules/deep-trend-finder/includes/providers/class-fidtf-tiktok-live-adapter.php');
$ok(strpos($tik, 'tiktok_dispatch_blocked_fail_closed') !== false, 'DTF TikTok adapter is fail-closed');
$tik_start = substr($tik, strpos($tik, 'private function start_direct_apify_run'), 1600);
$ok(!preg_match('/wp_remote_post\s*\(/', $tik_start), 'DTF TikTok direct start performs zero direct HTTP');
$ok(strpos($tik, 'VES_Security_Event_Log::record(\'provider_dispatch_blocked\'') !== false && strpos($gen, 'VES_Security_Event_Log::record(\'provider_dispatch_blocked\'') !== false && strpos($ms, 'VES_Security_Event_Log::record(\'provider_dispatch_blocked\'') !== false, 'blocked legacy fallbacks record security events');

// ── 5. AI egress classified + key-gated; no flag flip ────────────────────────
$openai_rows = VES_External_Egress_Inventory::for_provider('openai');
$ok(count($openai_rows) >= 5, 'all five OpenAI call sites classified');
$openai_json = json_encode($openai_rows);
$ok(strpos($openai_json, 'ves_request_item_analysis') !== false, 'inventory names the REAL analysis.php function');
$ok(strpos($openai_json, 'generate_with_openai') !== false, 'inventory names the REAL MarketSignal OpenAI method');
foreach ($openai_rows as $r) {
    $ok(in_array($r['classification'], ['ai_provider_gated', 'ai_provider_legacy_requires_review'], true), 'OpenAI path classified gated/legacy-review: ' . $r['class']);
}
$main = (string) file_get_contents($root . '/future-island-intelligence-suite.php');
$ok(strpos($main, "ves_generation_execution_enabled', true") === false, 'generation execution flag is never force-enabled');
$fici = (string) file_get_contents($root . '/modules/creative-intelligence/includes/ai/class-fici-openai-provider.php');
$ok(strpos($fici, 'fici_missing_api_key') !== false, 'Creative Intelligence AI call is key-gated');

// ── 6. Billing classified ─────────────────────────────────────────────────────
$stripe_rows = VES_External_Egress_Inventory::for_provider('stripe');
$ok(count($stripe_rows) >= 1 && $stripe_rows[0]['classification'] === 'billing_provider_explicit', 'Stripe egress classified billing_provider_explicit');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
