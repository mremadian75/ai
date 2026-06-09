<?php
require_once __DIR__ . '/test-fidtf-v036-scraptik-output-flattening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version is v0.3.7 or newer

ok(FIDTF_Provider_TikTok::normalize_scraptik_region('Spain', 'es') === 'ES', 'Spain market maps to ES region');
ok(FIDTF_Provider_TikTok::normalize_scraptik_region('SP', 'es') === 'ES', 'legacy SP market code is corrected to ES');
ok(FIDTF_Provider_TikTok::normalize_scraptik_region('España', 'es') === 'ES', 'Spanish market name maps to ES region');
ok(FIDTF_Provider_TikTok::normalize_scraptik_region('', 'es') === 'ES', 'Spanish language fallback maps to ES region');
ok(FIDTF_Provider_TikTok::normalize_scraptik_region('United Kingdom', 'en') === 'GB', 'United Kingdom maps to GB region');

$actor_input = FIDTF_Provider_TikTok::build_actor_input_from_payload([
    'source_key' => 'tiktok',
    'source_plan' => [
        'queries' => ['beverage', 'mahou', 'cerveza', 'trends around beverage'],
        'hashtags' => ['beverage', 'mahou', 'cerveza'],
        'max_items' => 25,
        'sort_strategy' => 'relevance_then_engagement',
    ],
    'request_context' => [
        'market' => 'Spain',
        'language' => 'es',
        'keywords' => ['beverage', 'mahou', 'cerveza'],
        'brand' => 'mahou',
        'company' => 'mahou',
    ],
    'limits' => ['max_items' => 25],
]);
ok(($actor_input['proxyCountryCode'] ?? '') === 'ES', 'production Spain context sends proxyCountryCode ES, not SP');
ok(!empty($actor_input['searchQueries']) && in_array('mahou', (array) $actor_input['searchQueries'], true), 'brand term is preferred inside TikTok discovery searchQueries');
ok(($actor_input['resultsPerPage'] ?? 0) === 25, 'discovery result count remains preserved');
ok(($actor_input['videoSearchSorting'] ?? '') === 'MOST_RELEVANT', 'discovery videoSearchSorting enum remains preserved');

$actor_input_no_brand = FIDTF_Provider_TikTok::build_actor_input_from_payload([
    'source_key' => 'tiktok',
    'source_plan' => [
        'queries' => ['beverage', 'mahou', 'cerveza', 'trends around beverage'],
        'max_items' => 25,
        'sort_strategy' => 'relevance_then_engagement',
    ],
    'request_context' => [
        'market' => 'SP',
        'language' => 'es',
        'keywords' => ['beverage', 'mahou', 'cerveza'],
    ],
    'limits' => ['max_items' => 25],
]);
ok(($actor_input_no_brand['proxyCountryCode'] ?? '') === 'ES', 'legacy SP request context is corrected before discovery dispatch');
ok(!empty(array_intersect((array) ($actor_input_no_brand['searchQueries'] ?? []), ['mahou', 'cerveza'])), 'without brand, specific short search terms are preserved in discovery searchQueries');

fwrite(STDOUT, "FIDTF v0.3.7 Scraptik input region hotfix checks passed: {$checks} / {$checks}\n");
