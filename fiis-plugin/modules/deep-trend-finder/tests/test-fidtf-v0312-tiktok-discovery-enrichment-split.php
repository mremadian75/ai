<?php
require_once __DIR__ . '/test-fidtf-v0311-admin-dispatch-diagnostics-and-view-gate.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$settings_code = file_get_contents($root . '/includes/class-fidtf-settings.php');
$provider_code = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
$core_code = file_get_contents($root . '/includes/providers/class-fidtf-core-apify-client-adapter.php');
$rest_code = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.12
ok(strpos($settings_code, 'tiktok_discovery_actor_id') !== false, 'settings define explicit TikTok discovery actor setting');
ok(strpos($settings_code, 'tiktok_enrichment_actor_id') !== false, 'settings define explicit TikTok enrichment actor setting');
ok(strpos($provider_code, 'build_enrichment_actor_input') !== false, 'TikTok provider exposes enrichment input builder');
ok(strpos($core_code, "'provider_mode_used' => 'tiktok_discovery'") !== false, 'provider mode label tiktok_discovery is used');
ok(strpos($core_code, "'enrichment_provider_mode' => 'tiktok_enrichment'") !== false, 'provider mode label tiktok_enrichment is used');

$old = FIDTF_Settings::defaults();
unset($old['tiktok_discovery_actor_id'], $old['tiktok_enrichment_actor_id']);
$old['tiktok_actor_id'] = 'scraptik/tiktok-api';
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $old;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $old;
$migrated = FIDTF_Settings::get();
ok(($migrated['tiktok_discovery_actor_id'] ?? '') === 'clockworks/tiktok-scraper', 'old tiktok_actor_id=scraptik/tiktok-api migrates away from discovery to Clockworks default');
ok(($migrated['tiktok_enrichment_actor_id'] ?? '') === 'scraptik/tiktok-api', 'old tiktok_actor_id=scraptik/tiktok-api is preserved as enrichment actor');
ok(FIDTF_Settings::tiktok_actor_role('scraptik/tiktok-api') === 'tiktok_enrichment', 'scraptik/tiktok-api is classified as enrichment actor');
ok(FIDTF_Settings::tiktok_actor_role('clockworks/tiktok-scraper') === 'tiktok_discovery', 'clockworks/tiktok-scraper is classified as discovery actor');

$settings = FIDTF_Settings::get();
$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$settings['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
$settings['tiktok_default_limit'] = 25;
$settings['tiktok_max_limit'] = 50;
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;

$payload = [
    'source_plan' => [
        'queries' => ['mahou', 'https://www.tiktok.com/@brand/video/7643492866437811478'],
        'hashtags' => ['#beer'],
        'creator_or_format_hints' => ['@mahou'],
        'max_items' => 300,
        'sort_strategy' => 'relevance_then_engagement',
    ],
    'request_context' => [
        'market' => 'Spain',
        'language' => 'es',
        'brand' => 'mahou',
        'keywords' => ['beverage'],
        'date_range' => 'last_30_days',
    ],
    'limits' => ['max_items' => 300],
];
$discovery_input = FIDTF_Provider_TikTok::build_discovery_actor_input_from_payload($payload);
ok(isset($discovery_input['searchQueries']) && in_array('mahou', (array) $discovery_input['searchQueries'], true), 'discovery actor input contains searchQueries');
ok(($discovery_input['resultsPerPage'] ?? 0) === 50, 'discovery actor input clamps resultsPerPage to admin max');
ok(($discovery_input['proxyCountryCode'] ?? '') === 'ES', 'discovery actor input contains proxyCountryCode');
ok(!empty($discovery_input['hashtags']) && in_array('beer', (array) $discovery_input['hashtags'], true), 'discovery actor input contains hashtags');
ok(!empty($discovery_input['profiles']) && in_array('mahou', (array) $discovery_input['profiles'], true), 'discovery actor input contains profiles');
ok(!empty($discovery_input['postURLs']), 'discovery actor input contains postURLs for TikTok URLs');
ok(!array_key_exists('post_awemeId', $discovery_input), 'discovery actor input does not contain post_awemeId');
ok(!array_key_exists('listComments_awemeId', $discovery_input), 'discovery actor input does not contain listComments_awemeId');
ok(!array_key_exists('searchPosts_keyword', $discovery_input), 'discovery actor input does not contain Scraptik searchPosts_keyword');

$enrichment_input = FIDTF_Provider_TikTok::build_enrichment_actor_input('7643492866437811478', 30, 0);
ok(($enrichment_input['post_awemeId'] ?? '') === '7643492866437811478', 'enrichment actor input contains post_awemeId');
ok(($enrichment_input['listComments_awemeId'] ?? '') === '7643492866437811478', 'enrichment actor input contains listComments_awemeId');
ok(($enrichment_input['listComments_count'] ?? 0) === 30, 'enrichment actor input contains listComments_count');
ok(array_key_exists('listComments_cursor', $enrichment_input), 'enrichment actor input contains listComments_cursor');

$completed = FIDTF_Source_Job_Service::normalize_dispatch_result([
    'ok' => true,
    'status' => 'completed',
    'provider_mode_used' => 'tiktok_discovery',
    'provider_run_id' => 'discovery-run-1',
    'discovery_actor_id' => 'clockworks/tiktok-scraper',
    'enrichment_actor_id' => 'scraptik/tiktok-api',
    'discovery_actor_input' => $discovery_input,
    'items' => [['desc' => 'post without aweme id']],
]);
ok(($completed['status'] ?? '') === 'completed', 'discovery can complete without enrichment');
ok(empty($completed['enrichment_attempted']), 'enrichment remains optional when no selected aweme_id exists');

$merged = FIDTF_Provider_TikTok::merge_enrichment_payload(
    ['aweme_id' => '7643492866437811478', 'desc' => 'candidate post'],
    [['aweme_detail' => ['aweme_id' => '7643492866437811478'], 'comments' => [['text' => 'first'], ['text' => 'second']]]]
);
ok(!empty($merged['comments']) && count($merged['comments']) === 2, 'enrichment can attach comments to a selected discovery post');
ok(($merged['aweme_detail']['aweme_id'] ?? '') === '7643492866437811478', 'enrichment can attach aweme_detail to selected discovery post');

foreach (['discovery_actor_id','enrichment_actor_id','discovery_actor_input','discovery_run_id','discovery_dataset_rows','discovery_flattened_items','normalized_items','relevant_items','enrichment_attempted','enrichment_run_ids','enrichment_comments_count'] as $field) {
    ok(strpos($rest_code, "'{$field}'") !== false || strpos($rest_code, $field) !== false, 'REST/admin diagnostics expose ' . $field);
}
ok(strpos($js, 'TikTok discovery completed') !== false, 'frontend copy includes TikTok discovery completed');
ok(strpos($js, 'TikTok enrichment completed') !== false, 'frontend copy includes TikTok enrichment completed');
ok(strpos($js, 'Discovery returned posts but relevance filter rejected them') !== false, 'frontend copy includes relevance rejection message');
ok(strpos($js, 'Discovery returned zero posts') !== false, 'frontend copy includes zero discovery message');
ok(strpos($js, 'Enrichment skipped: no selected post aweme_id') !== false, 'frontend copy includes enrichment skipped message');

fwrite(STDOUT, "FIDTF v0.3.12 TikTok discovery/enrichment split checks passed: {$checks} / {$checks}\n");
