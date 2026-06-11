<?php
require_once __DIR__ . '/test-fidtf-v022-source-job-contract-hardening.php';

global $fidtf_options, $fidtf_filter_handlers, $fidtf_filter_calls, $fidtf_last_http_request;
$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = false;
$settings['enable_tiktok_live_bridge'] = false;
$settings['tiktok_provider_mode'] = 'external_filter';
$settings['tiktok_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$settings['source_limits']['tiktok'] = 25;
$settings['source_max_caps']['tiktok'] = 50;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers = [];
$fidtf_filter_calls = [];

$provider = new FIDTF_Provider_TikTok();
$source_plan = [
    'queries' => ['verano silencioso'],
    'hashtags' => ['#madrid', 'tiktoktravel'],
    'creator_or_format_hints' => ['POV travel diary'],
    'max_items' => 300,
];
$request = [
    'market' => 'Spain',
    'language' => 'es',
    'date_range' => 'last_30_days',
    'keywords' => ['quiet travel'],
    'exclude_terms' => ['cheap flights'],
    'planner_mode' => 'external_model_bridge',
];

$payload_disabled = $provider->build_payload($source_plan, $request);
ok(($payload_disabled['provider_intent'] ?? '') === 'planned_only', 'TikTok payload stays planned_only when bridge disabled');
ok(($payload_disabled['live_dispatch_enabled'] ?? true) === false, 'TikTok payload live flag false when bridge disabled');
$disabled = $provider->dispatch($source_plan, $request);
ok(is_wp_error($disabled) && $disabled->get_error_code() === 'live_dispatch_disabled', 'global live disabled blocks TikTok dispatch');

$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = false;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$bridge_disabled = $provider->dispatch($source_plan, $request);
ok(is_wp_error($bridge_disabled) && $bridge_disabled->get_error_code() === 'tiktok_live_bridge_disabled', 'TikTok bridge has its own explicit disabled error');

$settings['enable_tiktok_live_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$payload_live = $provider->build_payload($source_plan, $request);
ok(($payload_live['provider_intent'] ?? '') === 'live_collect', 'TikTok payload uses live_collect only when both live gates are enabled');
ok(($payload_live['live_dispatch_enabled'] ?? false) === true, 'TikTok live flag true only when both live gates are enabled');
$missing = $provider->dispatch($source_plan, $request);
ok(is_wp_error($missing) && $missing->get_error_code() === 'tiktok_provider_unavailable', 'missing TikTok provider reports honest provider unavailable diagnostic');

$captured = [];
$fidtf_filter_calls = [];
$fidtf_filter_handlers['fidtf_tiktok_live_dispatch_result'] = function($tag, $value, $context, $payload_arg, $actor_input_arg) use (&$captured) {
    $captured = ['context' => $context, 'payload' => $payload_arg, 'actor_input' => $actor_input_arg];
    return ['ok' => true, 'status' => 'queued', 'provider_run_id' => 'tiktok-run-1', 'items' => []];
};
$queued = $provider->dispatch($source_plan, $request);
ok(!is_wp_error($queued) && ($queued['provider_run_id'] ?? '') === 'tiktok-run-1', 'TikTok external filter bridge can return queued provider run');
ok(($fidtf_filter_calls['fidtf_tiktok_live_dispatch_result'] ?? 0) === 1, 'TikTok bridge calls TikTok-specific provider filter');
ok(($fidtf_filter_calls['fidtf_dispatch_source_job'] ?? 0) === 0, 'TikTok-specific filter is preferred over legacy generic source filter');
ok(($captured['context']['actor_id'] ?? '') === 'clockworks/tiktok-scraper', 'TikTok bridge context includes discovery actor id for admin/server use');
ok(($captured['actor_input']['resultsPerPage'] ?? 0) === 50, 'TikTok discovery input clamps requested max_items to admin max');
ok(!empty($captured['actor_input']['searchQueries']), 'TikTok discovery input uses searchQueries');
ok(($captured['actor_input']['proxyCountryCode'] ?? '') === 'ES', 'TikTok discovery input normalizes Spain to ES');
ok(!array_key_exists('post_awemeId', $captured['actor_input']), 'TikTok discovery input does not send Scraptik enrichment post_awemeId');
ok(array_key_exists('hashtags', $captured['actor_input']), 'TikTok discovery input can send hashtag field');
ok(!array_key_exists('searchPosts_keyword', $captured['actor_input']), 'TikTok discovery input does not send Scraptik searchPosts_keyword');

$generic = new FIDTF_Provider_Instagram();
$generic_result = $generic->dispatch(['queries' => ['summer']], $request);
ok(is_wp_error($generic_result) && $generic_result->get_error_code() === 'source_live_bridge_disabled', 'non-TikTok Apify provider remains planned-only in v0.3.0');

$google_payload = FIDTF_Source_Dispatcher::payload_for('google_trends', ['seed_keywords' => ['verano'], 'max_items' => 10], $request);
ok(($google_payload['provider_intent'] ?? '') === 'planned_only', 'Google Trends canonical payload remains planned_only');
ok(($google_payload['live_dispatch_enabled'] ?? true) === false, 'non-TikTok canonical payload cannot enable live dispatch');

$nested = FIDTF_Normalizer::normalize('tiktok', [
    'id' => 'nested-1',
    'authorMeta' => ['name' => 'Creator Name', 'nickName' => 'Creator Nick'],
    'video' => ['playAddr' => 'https://cdn.example/video.mp4', 'cover' => 'https://cdn.example/cover.jpg'],
    'stats' => ['playCount' => '1.2M', 'diggCount' => '45K', 'commentCount' => 300, 'shareCount' => 80, 'collectCount' => 70],
    'desc' => 'Quiet Madrid travel diary',
    'createTime' => 1770000000,
]);
ok($nested['author'] === 'Creator Name', 'TikTok normalizer reads nested authorMeta');
ok(($nested['metrics']['views'] ?? 0) === 1200000.0, 'TikTok normalizer reads nested play count');
ok(($nested['metrics']['likes'] ?? 0) === 45000.0, 'TikTok normalizer reads nested like count');
ok(($nested['media']['media_url'] ?? '') === 'https://cdn.example/video.mp4', 'TikTok normalizer reads nested video URL');
ok(($nested['evidence_quality']['video_file'] ?? true) === false, 'video URL does not pretend downloaded video file exists');
ok(($nested['evidence_quality']['deep_video_analyzed'] ?? true) === false, 'normalizer never claims deep video analysis');

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        global $fidtf_last_http_request;
        $fidtf_last_http_request = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 201], 'body' => wp_json_encode(['data' => ['id' => 'apify-run-1', 'status' => 'RUNNING']])];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}

$settings = FIDTF_Settings::get();
$settings['tiktok_provider_mode'] = 'apify_bridge';
$settings['tiktok_apify_token'] = 'apify-secret-test-token';
$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = true;
$fidtf_options[FIDTF_Settings::OPTION] = $settings;
$fidtf_filter_handlers = [];
$fidtf_last_http_request = [];
$direct = $provider->dispatch($source_plan, $request);
ok(!is_wp_error($direct) && ($direct['provider_run_id'] ?? '') === 'apify-run-1', 'direct Apify bridge starts async actor run');
ok(strpos($fidtf_last_http_request['url'] ?? '', '/acts/clockworks~tiktok-scraper/runs') !== false, 'direct Apify bridge uses async actor runs endpoint');
ok(($fidtf_last_http_request['args']['headers']['Authorization'] ?? '') === 'Bearer apify-secret-test-token', 'direct Apify bridge sends token only in server-side Authorization header');
ok(strpos(wp_json_encode($fidtf_last_http_request['args']['body'] ?? ''), 'apify-secret-test-token') === false, 'direct Apify bridge never includes token in request body');

$frontend = file_get_contents(dirname(__DIR__) . '/assets/js/fidtf-frontend.js');
ok(strpos($frontend, 'apify-secret-test-token') === false && strpos($frontend, 'Authorization') === false, 'frontend bundle contains no provider token or Authorization header');
ok(strpos($frontend, 'TikTok collection started') !== false, 'frontend has TikTok running status copy');
ok(strpos($frontend, 'Discovery returned posts but relevance filter rejected them') !== false, 'frontend has TikTok no-relevant-evidence copy');

fwrite(STDOUT, "FIDTF v0.3.0 TikTok provider bridge checks passed: {$checks} / {$checks}\n");
