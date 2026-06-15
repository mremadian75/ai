<?php
require_once __DIR__ . '/test-fidtf-v0312-tiktok-discovery-enrichment-split.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$provider_code = file_get_contents($root . '/includes/providers/class-fidtf-provider-tiktok.php');
$adapter_code = file_get_contents($root . '/includes/providers/class-fidtf-tiktok-live-adapter.php');
$admin_code = file_get_contents($root . '/includes/class-fidtf-admin.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version bumped to v0.3.13
foreach (['build_scraptik_input', 'build_clockworks_input', 'build_apidojo_input'] as $builder) {
    ok(strpos($provider_code, $builder) !== false, 'TikTok provider exposes actor-specific input builder ' . $builder);
}
ok(strpos($provider_code, 'return strpos($actor, \'apidojo/tiktok-scraper\') !== false') === false, 'Apidojo is no longer treated as Clockworks-schema actor');
ok(strpos($provider_code, 'actor_uses_apidojo_schema') !== false, 'TikTok provider detects Apidojo schema actors explicitly');
ok(strpos($adapter_code, 'validate_discovery_actor_input') !== false, 'TikTok adapter validates discovery input before dispatch');

$settings = FIDTF_Settings::get();
$settings['tiktok_discovery_actor_id'] = 'apidojo/tiktok-scraper';
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
$apidojo_input = FIDTF_Provider_TikTok::build_discovery_actor_input_from_payload($payload);
ok(!empty($apidojo_input['keywords']) && in_array('mahou', (array) $apidojo_input['keywords'], true), 'Apidojo input contains keywords including brand');
ok(!empty($apidojo_input['startUrls']), 'Apidojo input contains startUrls for TikTok URLs');
ok(($apidojo_input['maxItems'] ?? 0) === 50, 'Apidojo input clamps maxItems to admin max');
ok(($apidojo_input['dateRange'] ?? '') === 'THIS_MONTH', 'Apidojo input maps dateRange to actor field');
ok(($apidojo_input['location'] ?? '') === 'ES', 'Apidojo input maps location to actor field');
ok(($apidojo_input['sortType'] ?? '') === 'RELEVANCE', 'Apidojo input maps sortType to actor field');
ok(array_key_exists('includeSearchKeywords', $apidojo_input), 'Apidojo input contains includeSearchKeywords');
ok(array_key_exists('customMapFunction', $apidojo_input), 'Apidojo input contains customMapFunction');
foreach (['searchQueries','resultsPerPage','searchSection','searchSorting','videoSearchSorting','searchDatePosted','proxyCountryCode','shouldDownloadVideos','shouldDownloadCovers','downloadSubtitlesOptions','post_awemeId','listComments_awemeId'] as $forbidden) {
    ok(!array_key_exists($forbidden, $apidojo_input), 'Apidojo input omits Clockworks/Scraptik field ' . $forbidden);
}
ok(FIDTF_Provider_TikTok::validate_discovery_actor_input('apidojo/tiktok-scraper', $apidojo_input) === true, 'Valid Apidojo input passes pre-dispatch validation');

$broken_input = ['searchQueries' => ['mahou'], 'resultsPerPage' => 25];
$validation = FIDTF_Provider_TikTok::validate_discovery_actor_input('apidojo/tiktok-scraper', $broken_input);
ok(is_wp_error($validation), 'Malformed Apidojo input is blocked before dispatch');
ok($validation->get_error_message() === 'Selected TikTok actor is apidojo/tiktok-scraper, but generated input does not contain a "keywords" or "startUrls" field.', 'Malformed Apidojo input exposes the exact admin diagnostic message');
$error_data = is_wp_error($validation) ? (array) $validation->get_error_data() : [];
ok(empty($error_data['dispatch_attempted']) && empty($error_data['request_attempted']), 'Malformed Apidojo input reports no dispatch/request attempted');
ok(($error_data['provider_mode_used'] ?? '') === 'tiktok_discovery', 'Malformed Apidojo input reports tiktok_discovery mode');

$settings['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
$GLOBALS['fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$GLOBALS['__fidtf_options'][FIDTF_Settings::OPTION] = $settings;
$clockworks_input = FIDTF_Provider_TikTok::build_discovery_actor_input_from_payload($payload);
ok(!empty($clockworks_input['searchQueries']) && in_array('mahou', (array) $clockworks_input['searchQueries'], true), 'Clockworks actor still receives searchQueries');
ok(!array_key_exists('keywords', $clockworks_input), 'Clockworks actor does not receive Apidojo keywords');

ok(strpos($admin_code, 'preview only / blocked by global_live_disabled') !== false, 'Admin UI labels blocked input preview when global live dispatch is disabled');
ok(strpos($admin_code, 'Apidojo/tiktok-scraper receives keywords/startUrls') !== false, 'Admin UI explains Apidojo actor input fields');

fwrite(STDOUT, "FIDTF v0.3.13 Apidojo TikTok input schema hotfix checks passed: {$checks} / {$checks}\n");
