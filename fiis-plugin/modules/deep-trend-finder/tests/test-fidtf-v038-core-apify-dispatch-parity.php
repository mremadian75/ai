<?php
require_once __DIR__ . '/test-fidtf-v037-scraptik-input-region-hotfix.php';
if (!class_exists('FIDTF_AI_Planner')) { require_once dirname(__DIR__) . '/includes/class-fidtf-ai-planner.php'; }
if (!class_exists('FIDTF_Core_Apify_Client_Adapter')) { require_once dirname(__DIR__) . '/includes/providers/class-fidtf-core-apify-client-adapter.php'; }
if (!class_exists('FIDTF_TikTok_Live_Adapter')) { require_once dirname(__DIR__) . '/includes/providers/class-fidtf-tiktok-live-adapter.php'; }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.9

if (!class_exists('VES_Apify_Client')) {
    class VES_Apify_Client {
        public static $calls = [];
        public static function request($method, $url, $body = null, $attempt = 1) { self::$calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'attempt' => $attempt]; return ['code' => 201, 'body' => ['data' => ['id' => 'core-run-1', 'status' => 'RUNNING']]]; }
        public static function fetch_run($run_id, $wait_for_finish = 20) { self::$calls[] = ['method' => 'fetch_run', 'run_id' => $run_id, 'wait' => $wait_for_finish]; return ['code' => 200, 'body' => ['data' => ['id' => $run_id, 'status' => 'SUCCEEDED']]]; }
        public static function fetch_items($run_id, $limit = 150) { self::$calls[] = ['method' => 'fetch_items', 'run_id' => $run_id, 'limit' => $limit]; return [['id' => 'item-1', 'desc' => 'mahou beverage trend']]; }
    }
}

$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = true;
$settings['tiktok_provider_mode'] = 'external_filter'; // legacy/stale stored setting from old installs.
$settings['tiktok_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers = [];

$status = FIDTF_Settings::tiktok_provider_config_status();
ok(($status['configured_mode'] ?? '') === 'external_filter', 'legacy stored provider mode remains visible for diagnostics');
ok(($status['effective_mode'] ?? '') === 'core_apify_client', 'effective provider mode falls back to Core Apify when no external filter exists');
ok(!empty($status['provider_ready']), 'preflight treats Core fallback as provider-ready');
$preflight = FIDTF_Settings::live_preflight_status(['tiktok']);
ok(!empty($preflight['tiktok_live_ready']), 'TikTok preflight is live-ready through Core fallback');

VES_Apify_Client::$calls = [];
$provider = new FIDTF_Provider_TikTok();
$source_plan = ['queries' => ['beverage', 'mahou', 'cerveza'], 'max_items' => 25, 'sort_strategy' => 'relevance_then_engagement'];
$request = ['market' => 'Spain', 'language' => 'es', 'keywords' => ['beverage', 'mahou', 'cerveza'], 'brand' => 'mahou', 'channels' => ['tiktok']];
$result = $provider->dispatch($source_plan, $request);
ok(!is_wp_error($result) && ($result['provider_run_id'] ?? '') === 'core-run-1', 'legacy external_filter setting still dispatches through Core when Core is available');
$post_calls = array_values(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'POST'; }));
ok(count($post_calls) === 1, 'Core fallback performs exactly one VES_Apify_Client::request POST');
ok(strpos($post_calls[0]['url'] ?? '', '/acts/clockworks~tiktok-scraper/runs') !== false, 'Core fallback uses Apify actor runs endpoint');
$sent_body = json_decode((string) ($post_calls[0]['body'] ?? ''), true);
ok(is_array($sent_body), 'Core fallback sends JSON-encoded body through VES_Apify_Client::request');
ok(strpos($post_calls[0]['url'] ?? '', 'waitForFinish=20') !== false || strpos($post_calls[0]['url'] ?? '', 'waitForFinish=') !== false, 'Core fallback run URL carries mother-plugin style run options');
ok(!empty($sent_body['searchQueries']) && in_array('mahou', (array) $sent_body['searchQueries'], true), 'Core fallback sends discovery searchQueries');
ok(($sent_body['proxyCountryCode'] ?? '') === 'ES', 'Core fallback sends normalized ES proxyCountryCode');
ok(!array_key_exists('post_awemeId', (array) $sent_body), 'Core fallback does not send Scraptik enrichment field in discovery phase');
ok(array_key_exists('resultsPerPage', (array) $sent_body), 'Core fallback includes discovery resultsPerPage field');

$settings['tiktok_provider_mode'] = 'apify_bridge';
$settings['tiktok_apify_token'] = 'direct-secret';
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;
ok(FIDTF_Settings::tiktok_effective_provider_mode() === 'apify_bridge', 'explicit direct bridge mode is still respected when token exists');

fwrite(STDOUT, "FIDTF v0.3.9 Core Apify dispatch parity checks passed: {$checks} / {$checks}\n");
