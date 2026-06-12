<?php
require_once __DIR__ . '/test-fidtf-v030-tiktok-provider-bridge.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-core-apify-client-adapter.php';

class VES_Apify_Client {
    public static $calls = [];
    public static $mode = 'ok';
    public static function request($method, $url, $body = null, $attempt = 1) {
        self::$calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'attempt' => $attempt];
        if (self::$mode === 'no_run_id') { return ['code' => 201, 'body' => ['data' => ['status' => 'RUNNING']]]; }
        if (self::$mode === 'rate_limited') { return new WP_Error('apify_rate_limited', 'rate limit', ['status' => 429, 'retryable' => true]); }
        return ['code' => 201, 'body' => ['data' => ['id' => 'core-run-1', 'status' => 'RUNNING']]];
    }
    public static function fetch_run($run_id, $wait_for_finish = 20) {
        self::$calls[] = ['method' => 'fetch_run', 'run_id' => $run_id, 'wait' => $wait_for_finish];
        return ['code' => 200, 'body' => ['data' => ['id' => $run_id, 'status' => 'SUCCEEDED']]];
    }
    public static function fetch_items($run_id, $limit = 150) {
        self::$calls[] = ['method' => 'fetch_items', 'run_id' => $run_id, 'limit' => $limit];
        return [['id' => 'item-1', 'desc' => 'verano silencioso Madrid']];
    }
    public static function acquire_dispatch_slot($actor_slug, $user_id = 0, $context = []) { self::$calls[] = ['method' => 'acquire_dispatch_slot', 'actor' => $actor_slug]; return 'slot-1'; }
    public static function register_run_id_for_slot($slot_id, $run_id) { self::$calls[] = ['method' => 'register_run_id_for_slot', 'slot' => $slot_id, 'run_id' => $run_id]; }
    public static function release_dispatch_slot($slot_id) { self::$calls[] = ['method' => 'release_dispatch_slot', 'slot' => $slot_id]; }
}

$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = true;
$settings['tiktok_provider_mode'] = 'core_apify_client';
$settings['tiktok_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers = [];

$status = FIDTF_Settings::tiktok_provider_config_status();
ok(($status['recommended_provider_mode'] ?? '') === 'core_apify_client', 'recommended mode is core when VES_Apify_Client static API exists');
ok(!empty($status['core_methods']['request']) && !empty($status['core_methods']['fetch_run']) && !empty($status['core_methods']['fetch_items']), 'core diagnostics detect request/fetch_run/fetch_items');

$provider = new FIDTF_Provider_TikTok();
$source_plan = ['queries' => ['verano silencioso'], 'max_items' => 10];
$request = ['market' => 'Spain', 'language' => 'es', 'keywords' => ['Madrid'], 'channels' => ['tiktok']];
VES_Apify_Client::$calls = [];
$started = $provider->dispatch($source_plan, $request);
ok(!is_wp_error($started) && ($started['provider_run_id'] ?? '') === 'core-run-1', 'core_apify_client mode starts run through static request');
ok(count(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'POST'; })) === 1, 'core_apify_client mode calls VES_Apify_Client::request');
ok(strpos(VES_Apify_Client::$calls[1]['url'] ?? '', '/acts/clockworks~tiktok-scraper/runs') !== false, 'core request uses Apify actor runs endpoint');
ok(strpos(wp_json_encode(VES_Apify_Client::$calls), 'apify-secret-test-token') === false, 'core mode exposes no direct token');
ok(count(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'register_run_id_for_slot'; })) === 1, 'core slot run id registration is used when available');

$adapter = new FIDTF_TikTok_Live_Adapter();
VES_Apify_Client::$calls = [];
$payload = $provider->build_payload($source_plan, $request);
$refreshed = $adapter->refresh(['id' => 9, 'provider_run_id' => 'core-run-1'], $payload);
ok(!is_wp_error($refreshed) && ($refreshed['status'] ?? '') === 'completed', 'core refresh completes succeeded run');
ok(count(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'fetch_run'; })) === 1, 'core refresh calls VES_Apify_Client::fetch_run');
ok(count(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'fetch_items'; })) === 1, 'core refresh calls VES_Apify_Client::fetch_items with run id');

VES_Apify_Client::$mode = 'no_run_id';
$bad = $provider->dispatch($source_plan, $request);
ok(is_wp_error($bad) && $bad->get_error_code() === 'tiktok_provider_invalid_run_response', '2xx start without run id returns invalid run response');
VES_Apify_Client::$mode = 'ok';

$js = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
$tpl = file_get_contents(dirname(__DIR__) . '/templates/shortcode-deep-trend-finder.php') . file_get_contents(dirname(__DIR__) . '/templates/report-deep-trend-finder.php');
$css = file_get_contents(dirname(__DIR__) . '/assets/css/fidtf-frontend.css');
ok(strpos($js . $tpl, 'sendPrompt(') === false, 'no undefined sendPrompt remains in add-on UI');
ok(strpos($tpl, 'onclick=') === false, 'no inline onclick remains in add-on templates');
ok(strpos($css, 'repeat(auto-fit, minmax(260px, 1fr))') !== false || strpos($css, 'repeat(auto-fit, minmax(240px, 1fr))') !== false, 'responsive minmax grid exists');
ok(strpos($tpl . $js, 'cdnjs.cloudflare.com') === false && strpos($tpl . $js, 'Chart.js') === false, 'no raw Chart.js CDN in add-on template/bundle');

$plugin = file_get_contents(dirname(__DIR__) . '/includes/class-fidtf-plugin.php');
ok(strpos($plugin, 'maybe_create_frontend_page') !== false, 'activation can create/reuse frontend page');
ok(strpos($plugin, '[future_island_deep_trend_finder]') !== false, 'auto page contains shortcode');

fwrite(STDOUT, "FIDTF v0.3.1 core Apify bridge hardening checks passed: {$checks} / {$checks}\n");
