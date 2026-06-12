<?php
require_once __DIR__ . '/test-fidtf-v0337-full-live-actors-provider-query-context-and-gpt-preflight.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic_src = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$normalizer_src = file_get_contents($root . '/includes/class-fidtf-normalizer.php');
$relevance_src = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.38
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.38
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.38
ok(strpos($generic_src, 'poll_after_seconds') !== false, 'generic Apify adapter exposes non-terminal polling guidance');
ok(strpos($normalizer_src, 'apply_google_trends_summary') !== false, 'Google Trends summary hardening is present');
ok(strpos($relevance_src, 'phrase_gate') !== false, 'multi-word phrase gate is present');

$gt = FIDTF_Normalizer::normalize('google_trends', [
    'keyword' => 'seo',
    'timeframe' => 'today 1-m',
    'geo' => 'ES',
    'language' => 'es-ES',
    'trends_url' => 'https://trends.google.com/trends/explore?date=today+1-m&geo=ES&q=seo&hl=es-ES',
    'timeline_data' => [
        'seo' => [
            '2026-04-26' => 71,
            '2026-04-27' => 98,
            '2026-04-28' => 100,
            '2026-05-26' => 66,
        ],
        'isPartial' => [
            '2026-04-26' => false,
            '2026-04-27' => false,
            '2026-04-28' => false,
            '2026-05-26' => true,
        ],
    ],
]);
ok(($gt['metrics']['trend_peak'] ?? 0) === 100.0, 'Google Trends nested timeline extracts peak interest');
ok(($gt['metrics']['trend_latest'] ?? 0) === 66.0, 'Google Trends nested timeline extracts latest interest');
ok(($gt['metrics']['trend_points'] ?? 0) === 4, 'Google Trends nested timeline counts datapoints');
ok(($gt['metrics']['trend_direction'] ?? '') === 'falling', 'Google Trends nested timeline computes direction');
ok(!empty($gt['trend_timeseries']) && count($gt['trend_timeseries']) === 4, 'Google Trends timeline is flattened into row series');

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Find evidence about brand strategy in Spain',
    'objective' => 'evidence only',
    'market' => 'Spain',
    'language' => 'en',
    'keywords' => ['brand strategy'],
    'channels' => ['reddit'],
]);
$source_plan = ['queries' => ['brand strategy'], 'max_items' => 2, 'sort_strategy' => 'relevance'];
$weak_phrase_match = FIDTF_Normalizer::normalize('reddit', [
    'id' => 't3_weak_phrase',
    'url' => 'https://www.reddit.com/r/example/comments/weak/',
    'username' => 'example_user',
    'title' => 'A generic strategy thread',
    'communityName' => 'r/example',
    'body' => 'This thread has lots of votes but does not discuss branding, positioning, audience, or marketing work.',
    'numberOfComments' => 300,
    'upVotes' => 5000,
    'createdAt' => date('c'),
    'dataType' => 'post',
    'provider_query' => 'brand strategy',
]);
$weak_score = FIDTF_Relevance_Filter::score($weak_phrase_match, $request, $source_plan);
ok(($weak_score['recommended_use'] ?? '') === 'hide', 'weak multi-word query match stays hidden despite provider query and engagement');
ok(strpos((string) ($weak_score['reason'] ?? ''), 'Multi-word request only weakly matched') !== false, 'weak phrase match reports capped context reason');

fwrite(STDOUT, "FIDTF v0.3.38 full live GPT preflight, trends summary, and relevance hardening checks passed: {$checks} / {$checks}\n");
