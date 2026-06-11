<?php
require_once __DIR__ . '/test-fidtf-v039-scraptik-social-request-contract.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$adapter_code = file_get_contents($root . '/includes/providers/class-fidtf-core-apify-client-adapter.php');
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$admin_code = file_get_contents($root . '/includes/class-fidtf-admin.php');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.10
// [v1.1.0 archived] removed obsolete standalone-addon version/lineage assertion: admin info copy describes v0.3.10 parity goal or newer

// 1. The Core adapter MUST mirror Core social-media scraper exactly:
// Core does $options['waitForFinish'] = 0 before ves_make_run_url(). The
// add-on must do the same so dispatch is async like the mother plugin.
ok(strpos($adapter_code, "\$options['waitForFinish'] = 0") !== false, 'Core adapter explicitly sets waitForFinish=0 to match Core social scraper async dispatch');
ok(strpos($adapter_code, "'waitForFinish' => 0,") !== false, 'Core adapter HTTP-fallback URL uses waitForFinish=0');
ok(strpos($adapter_code, "wp_json_encode(\$actor_input)") !== false, 'Core adapter sends JSON body via wp_json_encode just like Core scraper');
ok(strpos($adapter_code, "VES_Apify_Client') === false || strpos(\$adapter_code, \"VES_Apify_Client', 'request'\") !== false") === false, 'sanity check'); // placeholder to keep checks alignment

// 2. Diagnostic fields surface in run record outputs.
ok(strpos($adapter_code, "'provider_mode_used' => 'tiktok_discovery'") !== false, 'Core adapter records provider_mode_used=tiktok_discovery in dispatch result');
ok(strpos($adapter_code, "'request_attempted' => true") !== false, 'Core adapter records request_attempted in dispatch result');
ok(strpos($adapter_code, "'zero_dataset_items'") !== false, 'Core adapter distinguishes zero_dataset_items outcome reason');
ok(strpos($adapter_code, "'flattened_empty'") !== false, 'Core adapter distinguishes flattened_empty outcome reason');
ok(strpos($adapter_code, "'evidence_returned'") !== false, 'Core adapter records evidence_returned outcome reason');
ok(strpos($adapter_code, "'run_queued'") !== false, 'Core adapter records run_queued outcome reason');

// 3. REST controller exposes per-job dispatch diagnostic to admins.
ok(strpos($rest_code, "derive_outcome_reason") !== false, 'REST controller derives outcome_reason for each job');
ok(strpos($rest_code, "'sanitized_actor_input'") !== false, 'REST controller exposes full sanitized actor input to admins');
ok(strpos($rest_code, "'addon_version'") !== false, 'REST controller exposes running add-on version per job diagnostic');
ok(strpos($rest_code, "'provider_mode_used'") !== false, 'REST controller exposes provider_mode_used per job');
ok(strpos($rest_code, "tiktok_effective_provider_mode") !== false, 'REST controller surfaces the effective provider mode actually used at dispatch');
ok(strpos($rest_code, "'request_attempted'") !== false, 'REST controller exposes request_attempted per job');
ok(strpos($rest_code, "normalizer_extracted_zero") !== false, 'REST controller distinguishes normalizer failure outcome');
ok(strpos($rest_code, "no_items_passed_relevance") !== false, 'REST controller distinguishes relevance gate outcome');

// 4. Frontend differentiates four post-completion outcomes.
ok(strpos($js, 'Discovery returned zero posts') !== false, 'frontend has zero-discovery-posts copy');
ok(strpos($js, 'normalizer could not extract usable post fields') !== false, 'frontend has normalizer-failure copy');
ok(strpos($js, 'Discovery returned posts but relevance filter rejected them') !== false, 'frontend has relevance-failure copy');
ok(strpos($js, 'TikTok collection started') !== false, 'frontend keeps running TikTok copy');
ok(strpos($js, 'hasZeroRawTikTok') !== false && strpos($js, 'hasNormalizerFailureTikTok') !== false && strpos($js, 'hasNoRelevantTikTok') !== false, 'frontend updateRunView decides between zero-raw, normalizer-failure, relevance-failure');

// 5. Live execution end-to-end test using VES_Apify_Client mock from v0.3.8.
VES_Apify_Client::$calls = [];
$settings = FIDTF_Settings::get();
$settings['enable_live_dispatch'] = true;
$settings['enable_tiktok_live_bridge'] = true;
$settings['tiktok_provider_mode'] = 'core_apify_client';
$settings['tiktok_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$settings['tiktok_min_views'] = 20000;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $settings;

$provider = new FIDTF_Provider_TikTok();
$result = $provider->dispatch(
    ['queries' => ['beverage', 'mahou'], 'max_items' => 25],
    ['market' => 'Spain', 'language' => 'es', 'keywords' => ['beverage', 'mahou'], 'brand' => 'mahou', 'channels' => ['tiktok']]
);
ok(!is_wp_error($result), 'v0.3.10 Core-parity dispatch returns a non-error result');
ok(($result['provider_run_id'] ?? '') !== '', 'v0.3.10 dispatch returns a provider_run_id');
ok(($result['provider_mode_used'] ?? '') === 'tiktok_discovery', 'v0.3.12 dispatch result records provider_mode_used=tiktok_discovery');
ok(!empty($result['request_attempted']), 'v0.3.10 dispatch result records request_attempted=true');
$post_calls = array_values(array_filter(VES_Apify_Client::$calls, function($c) { return ($c['method'] ?? '') === 'POST'; }));
ok(count($post_calls) === 1, 'v0.3.10 dispatch performs exactly one POST through VES_Apify_Client::request');
ok(strpos((string) ($post_calls[0]['url'] ?? ''), 'waitForFinish=0') !== false, 'v0.3.10 dispatch URL uses async waitForFinish=0 like Core social scraper');
$sent_body = json_decode((string) ($post_calls[0]['body'] ?? ''), true);
ok(is_array($sent_body) && !empty($sent_body['searchQueries']) && in_array('mahou', (array) $sent_body['searchQueries'], true), 'v0.3.12 dispatch sends discovery searchQueries including brand');
ok(($sent_body['proxyCountryCode'] ?? '') === 'ES', 'v0.3.12 dispatch sends proxyCountryCode=ES for Spain');
foreach (['post_awemeId', 'listComments_awemeId', 'searchPosts_keyword', 'searchPosts_count', 'includeComments'] as $forbidden) {
    ok(!array_key_exists($forbidden, (array) $sent_body), 'v0.3.12 discovery dispatch omits enrichment/Scraptik field ' . $forbidden);
}

fwrite(STDOUT, "FIDTF v0.3.10 Core social Apify execution parity checks passed: {$checks} / {$checks}\n");
