<?php
require_once __DIR__ . '/test-fidtf-v010.php';

$root = dirname(__DIR__);
$report_src = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$report_tpl = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$report_css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$ves_js = file_get_contents($root . '/../../assets/js/ves-frontend.js');
$intake_src = file_get_contents($root . '/../../includes/class-ves-source-intake.php');

ok(strpos($report_src, 'provider_returned_items') !== false, 'data truth field provider_returned_items exists');
ok(strpos($report_src, 'decision_ready_items') !== false, 'data truth field decision_ready_items exists');
ok(strpos($report_src, 'creative_only_items') !== false, 'data truth field creative_only_items exists');
ok(strpos($report_src, 'provider_results_zero_usable_evidence') !== false, 'provider rows with zero usable evidence state exists');
ok(strpos($report_src, 'source_actor_not_allowlisted') !== false, 'allowlisted actor failure state exists');
ok(strpos($report_src, 'direct_keyword_match') !== false && strpos($report_src, 'direct_semantic_fit') !== false, 'lexical and strategic fit are separated');
ok(strpos($report_src, 'market_demand_fit') !== false && strpos($report_src, 'claim_readiness') !== false, 'demand fit and claim readiness are explicit');
ok(strpos($report_src, 'World Cup cultural moment') !== false, 'world cup phrase is represented as a phrase label');
ok(strpos($report_src, "'world','cup','team','today','people'") !== false, 'generic cluster tokens are suppressed');
ok(strpos($report_src, 'Not yet a recommended media channel') !== false, 'low-demand social evidence is not promoted to media-channel recommendation');
ok(strpos($report_src, 'google_ads_asset_block') !== false, 'Google Ads asset block generator exists');
ok(strpos($report_tpl, 'Provider returned:') !== false && strpos($report_tpl, 'Usable evidence:') !== false, 'report template renders provider vs usable rows separately');
ok(strpos($report_tpl, 'Technical diagnostics') !== false, 'report template keeps technical trace collapsed');
ok(strpos($report_tpl, 'Google Ads asset block') !== false, 'report template renders Google Ads asset block');
ok(strpos($report_tpl, 'Proof still needed') !== false, 'report template renders proof-needed gate');
ok(strpos($report_css, 'grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))') !== false, 'responsive safe evidence grids use minmax 260px');
ok(strpos($report_css, 'overflow-wrap: anywhere') !== false, 'report CSS protects long slugs from overflow');
ok(strpos($ves_js, 'vesGoogleResultState') !== false, 'Google result state helper exists');
ok(strpos($ves_js, 'provider_results_zero_usable_evidence') !== false, 'frontend distinguishes provider rows without usable evidence');
ok(strpos($ves_js, 'Operator action:') !== false && strpos($ves_js, 'Technical detail') !== false, 'three-layer error UI exists');
ok(strpos($intake_src, 'ACTION_DRAFT') !== false && strpos($intake_src, 'ACTION_MEMORY') !== false && strpos($intake_src, 'ACTION_USAGE') !== false, 'operator workflow has draft, memory and usage actions');
ok(strpos($intake_src, 'check_admin_referer') !== false && strpos($intake_src, 'current_user_can') !== false, 'operator actions keep nonce and capability checks');

$source_summary_method = new ReflectionMethod('FIDTF_Report_Service', 'source_summary');
$source_summary_method->setAccessible(true);
$local_method = new ReflectionMethod('FIDTF_Report_Service', 'local_synthesis');
$local_method->setAccessible(true);

$items = [
    [
        'source_key' => 'tiktok',
        'source' => 'tiktok',
        'signal_type' => 'social_post',
        'title' => 'FIFA World Cup 2026 meme hook',
        'caption_or_text' => 'Spain fans remix a FIFA World Cup 2026 football meme in TikTok short-form football hook format.',
        'hashtags' => ['worldcup', 'football', 'spain'],
        'matched_keywords' => ['world cup'],
        'relevance_tier' => 'direct',
        'evidence_tier' => 'support',
        'recommended_use' => 'use',
        'relevance_score' => 84,
        'views' => 120000,
        'likes' => 5000,
    ],
    [
        'source_key' => 'tiktok',
        'source' => 'tiktok',
        'signal_type' => 'social_post',
        'title' => 'World Cup cultural moment short-form format',
        'caption_or_text' => 'A World Cup cultural moment works as football meme mechanics, not proof of market demand.',
        'hashtags' => ['worldcup', 'meme'],
        'matched_keywords' => ['world cup'],
        'relevance_tier' => 'direct',
        'evidence_tier' => 'support',
        'recommended_use' => 'use',
        'relevance_score' => 79,
        'views' => 52000,
        'likes' => 1800,
    ],
];

$jobs = [
    [
        'source_key' => 'tiktok',
        'status' => 'completed',
        'provider_returned_items' => 3,
        'actor_dataset_items' => 3,
        'parsed_items' => 3,
        'normalized_items' => 3,
        'relevant_items' => 2,
    ],
    [
        'source_key' => 'google_search',
        'status' => 'completed',
        'provider_returned_items' => 1,
        'actor_dataset_items' => 1,
        'parsed_items' => 1,
        'normalized_items' => 0,
        'relevant_items' => 0,
    ],
];

$source_summary = $source_summary_method->invoke(null, $jobs, $items);
ok(($source_summary['tiktok']['provider_returned_items'] ?? 0) === 3, 'provider returned count is preserved');
ok(($source_summary['tiktok']['decision_ready_items'] ?? 0) === 2, 'decision-ready rows are counted from usable evidence only');
ok(($source_summary['google_search']['relevant_items'] ?? 99) === 0, 'provider wrapper row is not counted as usable evidence');
ok(($source_summary['google_search']['discarded_items'] ?? 0) === 1, 'provider row without usable evidence is counted as discarded');
ok(($source_summary['google_search']['source_state'] ?? '') === 'provider_results_zero_usable_evidence', 'Google result state is not contradictory');
ok(in_array('parsed_but_not_normalized', $source_summary['google_search']['discard_reasons'] ?? [], true), 'discard reason is visible');

$run = [
    'user_brief' => 'World Cup 2026 cultural moment for Spain',
    'objective' => 'Validate football creative mechanics',
    'market' => 'Spain',
    'language' => 'en',
    'request_json' => json_encode([
        'brand' => 'Future Island',
        'market' => 'Spain',
        'keywords' => ['world cup', 'football meme'],
        'research_mode' => 'creative_validation',
    ]),
];
$report = $local_method->invoke(null, 354, $run, $source_summary, $items);
$clusters = $report['signal_clusters'] ?? [];
$labels = array_map(static function ($row) { return (string) ($row['label'] ?? ''); }, $clusters);
ok(in_array('World Cup cultural moment', $labels, true) || in_array('FIFA World Cup 2026 conversation', $labels, true), 'cluster uses human phrase label instead of generic token');
ok(!in_array('world', array_map('strtolower', $labels), true) && !in_array('cup', array_map('strtolower', $labels), true), 'generic world/cup tokens are not primary cluster labels');
ok(strpos((string) ($report['marketing_decision_summary']['recommended_channel_to_test'] ?? ''), 'Not yet a recommended media channel') !== false, 'recommendation remains a hypothesis when demand is not proven');
ok(($report['confidence_breakdown']['market_demand_confidence'] ?? '') === 'not_proven', 'TikTok creative traction does not become market demand');
ok(strpos(strtolower((string) ($report['evidence_fit_diagnosis']['market_demand_fit'] ?? '')), 'not proven') !== false, 'market demand fit stays not proven without demand rows');

$asset = $report['platform_asset_blocks']['google_ads'] ?? [];
ok(($asset['status'] ?? '') === 'hypothesis', 'Google Ads block is marked as hypothesis');
ok(!empty($asset['evidence_caveat']) && !empty($asset['proof_needed_note']), 'asset block carries evidence caveat and proof-needed note');
foreach (['headlines' => 40, 'long_headlines' => 90, 'descriptions' => 90] as $key => $limit) {
    ok(count($asset[$key] ?? []) === 5, $key . ' has five fields');
    foreach ($asset[$key] as $field) {
        ok(($field['limit'] ?? 0) === $limit, $key . ' field carries expected limit');
        ok(($field['count'] ?? 999) <= $limit, $key . ' field respects character limit');
        ok(($field['valid'] ?? false) === true, $key . ' field is valid');
    }
}

fwrite(STDOUT, "FIDTF v0.3.54 SaaS evidence, safety and asset bridge checks passed.\n");
