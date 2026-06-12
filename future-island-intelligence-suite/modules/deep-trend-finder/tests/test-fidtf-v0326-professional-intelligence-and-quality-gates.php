<?php
require_once __DIR__ . '/test-fidtf-v0325-multi-source-live-and-professional-report.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$settings = file_get_contents($root . '/includes/class-fidtf-settings.php');
$relevance = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');
$report = file_get_contents($root . '/includes/class-fidtf-report-service.php');
$report_tpl = file_get_contents($root . '/templates/report-deep-trend-finder.php');
$frontend = file_get_contents($root . '/assets/js/fidtf-frontend.js');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$generic = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$source_job = file_get_contents($root . '/includes/class-fidtf-source-job-service.php');
$rest = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.26
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.26
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.26 quality gate

ok(strpos($settings, 'dedupe_source_list') !== false, 'settings have central source list deduplication');
ok(strpos($settings, 'ordered_source_keys') !== false, 'settings keep source display order stable');
ok(strpos($relevance, 'core_keywords_from_request') !== false, 'relevance filter separates core intent from planner expansion');
ok(strpos($relevance, 'matches_only_generic_expansion') !== false, 'relevance filter rejects generic-only expansion matches');
ok(strpos($relevance, 'matched_core_keywords') !== false, 'relevance filter stores matched core keywords for auditability');
ok(strpos($relevance, 'matched_expansion_keywords') !== false, 'relevance filter stores matched expansion keywords for auditability');

$weak = FIDTF_Relevance_Filter::filter_items([
    [
        'source_key' => 'tiktok',
        'title' => 'Apple juice is a beverage made from apples',
        'caption_or_text' => 'Apple juice beverage recipe and wellness drink',
        'metrics' => ['views' => 25000, 'likes' => 200, 'comments' => 4, 'shares' => 20],
        'evidence_quality' => ['has_engagement_signal' => true, 'has_freshness_signal' => true],
    ],
], ['keywords' => ['mahou'], 'brand' => 'mahou'], ['queries' => ['beverage'], 'hashtags' => ['beer']]);
ok(empty($weak[0]['is_relevant']), 'generic beverage-only row is not relevant when core intent is Mahou');

$strong = FIDTF_Relevance_Filter::filter_items([
    [
        'source_key' => 'tiktok',
        'title' => 'Mahou beer ritual in Madrid nightlife',
        'caption_or_text' => 'Mahou beer with friends in Madrid #cerveza',
        'metrics' => ['views' => 25000, 'likes' => 200, 'comments' => 4, 'shares' => 20],
        'evidence_quality' => ['has_engagement_signal' => true, 'has_freshness_signal' => true],
    ],
], ['keywords' => ['mahou'], 'brand' => 'mahou'], ['queries' => ['beverage'], 'hashtags' => ['beer']]);
ok(!empty($strong[0]['is_relevant']), 'core Mahou row remains relevant with the stricter scorer');
ok(strpos((string) ($strong[0]['relevance_reason'] ?? ''), 'Score ') === false, 'relevance reason no longer duplicates score label');

ok(strpos($report, 'evidence_intelligence') !== false, 'report builds evidence intelligence layer');
ok(strpos($report, 'creative_territories') !== false, 'report creates creative territories');
ok(strpos($report, 'risk_notes') !== false, 'report outputs risk notes');
ok(strpos($report, 'source_confidence') !== false, 'report computes source confidence');
ok(strpos($report_tpl, 'Evidence map') !== false, 'report template renders evidence map');
ok(strpos($report_tpl, 'Hook mechanics detected') !== false, 'report template renders hook mechanics');
ok(strpos($report_tpl, 'Creative territories') !== false, 'report template renders creative territories');
ok(strpos($report_tpl, 'Strategic risk notes') !== false, 'report template renders strategic risks');
ok(strpos($frontend, 'fidtf-has-full-report') !== false, 'frontend moves full report to full-width output area');
ok(strpos($css, '.fidtf-has-full-report .fidtf-output') !== false, 'CSS makes full report use full width');
ok(strpos($css, '.fidtf-mini-bar') !== false, 'CSS styles evidence map mini bars');

ok(strpos($generic, 'actor_input_profile') !== false, 'generic adapter records actor input profile');
ok(strpos($generic, 'fidtf_generic_apify_actor_input') !== false, 'generic adapter exposes actor input filter for strict schemas');
ok(strpos($generic, 'instagram_apify_official') !== false, 'generic adapter has Instagram profile');
ok(strpos($generic, 'reddit_trudax_lite') !== false, 'generic adapter has Reddit profile');
ok(strpos($generic, 'google_trends_data_xplorer') !== false, 'generic adapter has Google Trends profile');
ok(strpos($generic, 'google_news_data_xplorer') !== false, 'generic adapter has Google News profile');
ok(strpos($source_job, 'delete_items_for_job') !== false, 'source job replaces stale evidence on successful reprocessing');
ok(strpos($rest, 'actor_input_profile') !== false, 'REST diagnostics expose actor input profile to admins');

fwrite(STDOUT, "FIDTF v0.3.26 professional intelligence and quality gate checks passed: {$checks} / {$checks}\n");
