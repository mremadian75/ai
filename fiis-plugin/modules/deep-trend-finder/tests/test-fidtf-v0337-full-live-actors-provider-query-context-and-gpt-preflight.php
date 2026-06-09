<?php
require_once __DIR__ . '/test-fidtf-v0336-full-live-actor-and-gpt-qa-hardening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$generic_src = file_get_contents($root . '/includes/providers/class-fidtf-generic-apify-live-adapter.php');
$relevance_src = file_get_contents($root . '/includes/class-fidtf-relevance-filter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.37
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.37
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.37

ok(strpos($generic_src, 'attach_actor_context_to_item') !== false, 'generic Apify adapter attaches actor context to dataset rows');
ok(strpos($generic_src, 'primary_query_from_actor_input') !== false, 'generic Apify adapter can derive provider_query from actor input');
ok(strpos($generic_src, 'instagram.com/explore/tags') !== false, 'generic Apify adapter derives Instagram hashtag provider_query from direct URL');
ok(strpos($relevance_src, 'provider query provenance means') !== false, 'relevance filter prevents provider-query-only false positives');

$request = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Find brand strategy discussion signals',
    'objective' => 'discover evidence only',
    'market' => 'Spain',
    'language' => 'en',
    'keywords' => ['brand strategy'],
    'channels' => ['reddit'],
]);
$source_plan = ['queries' => ['brand strategy'], 'max_items' => 2, 'sort_strategy' => 'relevance'];
$off_topic_reddit = FIDTF_Normalizer::normalize('reddit', [
    'id' => 't3_unrelated',
    'url' => 'https://www.reddit.com/r/wallstreetbets/comments/example/',
    'username' => 'example_user',
    'title' => 'Regards, may I present: an unrelated index',
    'communityName' => 'r/wallstreetbets',
    'body' => 'This is a high engagement finance thread with no semantic overlap to the requested strategic evidence.',
    'numberOfComments' => 212,
    'upVotes' => 3980,
    'createdAt' => date('c'),
    'dataType' => 'post',
    'provider_query' => 'brand strategy',
]);
$score = FIDTF_Relevance_Filter::score($off_topic_reddit, $request, $source_plan);
ok(($score['recommended_use'] ?? '') === 'hide', 'Reddit provider-query-only off-topic result is hidden despite high engagement');
ok(($score['score'] ?? 100) <= 44.0, 'provider-query-only evidence is capped below show threshold');

$relevant_reddit = FIDTF_Normalizer::normalize('reddit', [
    'id' => 't3_relevant',
    'url' => 'https://www.reddit.com/r/marketing/comments/example/',
    'username' => 'example_user',
    'title' => 'How do you build a brand strategy for a new market?',
    'communityName' => 'r/marketing',
    'body' => 'I am comparing positioning, audience segmentation and brand strategy for a launch.',
    'numberOfComments' => 42,
    'upVotes' => 180,
    'createdAt' => date('c'),
    'dataType' => 'post',
    'provider_query' => 'brand strategy',
]);
$relevant_score = FIDTF_Relevance_Filter::score($relevant_reddit, $request, $source_plan);
ok(($relevant_score['recommended_use'] ?? '') !== 'hide', 'Reddit row with direct semantic overlap remains usable');
ok(in_array('brand strategy', (array) ($relevant_score['matched_core_keywords'] ?? []), true), 'direct Reddit row records matched core keyword');

$instagram_from_direct = FIDTF_Normalizer::normalize('instagram', [
    'inputUrl' => 'https://www.instagram.com/explore/tags/marketing/',
    'id' => '3905443992859566989',
    'caption' => 'Marketing strategy training post.',
    'url' => 'https://www.instagram.com/p/example/',
    'likesCount' => 10,
    'commentsCount' => 1,
    'provider_query' => 'marketing',
]);
ok(($instagram_from_direct['provider_query'] ?? '') === 'marketing', 'Instagram direct hashtag context is preserved as provider_query');

// Live GPT cannot be executed by this test suite without an external key, but the
// parser must accept the exact Responses API shape used by live requests.
$request_gpt = FIDTF_Run_Service::sanitize_request([
    'user_brief' => 'Plan a multi-source marketing trend run in Spain.',
    'objective' => 'source planning only',
    'market' => 'Spain',
    'language' => 'es',
    'keywords' => ['marketing'],
    'channels' => ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news'],
]);
$plan = [
    'schema_version' => 'fidtf.plan.v1',
    'brief_summary' => 'Live-style Responses API plan.',
    'sources' => [
        'tiktok' => ['source_key' => 'tiktok', 'queries' => ['marketing'], 'hashtags' => ['marketing'], 'creator_or_format_hints' => [], 'language_hints' => ['es'], 'market_hints' => ['Spain'], 'max_items' => 2, 'sort_strategy' => 'relevance', 'notes' => 'live qa'],
        'instagram' => ['source_key' => 'instagram', 'queries' => ['marketing'], 'hashtags' => ['marketing'], 'profile_or_topic_hints' => [], 'language_hints' => ['es'], 'market_hints' => ['Spain'], 'max_items' => 2, 'sort_strategy' => 'recent_posts', 'notes' => 'live qa'],
        'reddit' => ['source_key' => 'reddit', 'queries' => ['brand strategy'], 'subreddits' => [], 'time_range' => 'month', 'language_hints' => ['en'], 'market_hints' => ['Spain'], 'max_items' => 2, 'sort_strategy' => 'relevance', 'notes' => 'live qa'],
        'google_trends' => ['source_key' => 'google_trends', 'seed_keywords' => ['marketing'], 'related_query_hints' => [], 'geo' => 'ES', 'time_range' => 'last_30_days', 'language_hints' => ['es'], 'market_hints' => ['Spain'], 'max_items' => 1, 'sort_strategy' => 'keyword_interest', 'notes' => 'live qa'],
        'google_news' => ['source_key' => 'google_news', 'queries' => ['marketing'], 'publisher_or_topic_hints' => [], 'geo' => 'ES', 'language' => 'es', 'time_range' => 'last_24_hours', 'max_items' => 2, 'sort_strategy' => 'freshness', 'notes' => 'live qa'],
    ],
    'limitations' => ['Live API key required for production GPT call.'],
    'request_context' => ['market' => 'Spain', 'channels' => ['tiktok','instagram','reddit','google_trends','google_news']],
];
VES_AI_Planner_Bridge::$result = [
    'id' => 'resp_live_style_test',
    'status' => 'completed',
    'model' => 'gpt-live-style',
    'output' => [[
        'type' => 'message',
        'content' => [[
            'type' => 'output_text',
            'text' => json_encode($plan),
        ]],
    ]],
];
$gpt_plan = FIDTF_AI_Bridge::plan($request_gpt, []);
ok(is_array($gpt_plan) && ($gpt_plan['model_label'] ?? '') === 'gpt-live-style', 'AI bridge parses live-style Responses API output_text payload');
ok(!is_wp_error(FIDTF_AI_Planner::validate_plan($gpt_plan, $request_gpt)), 'live-style GPT source plan validates across all selected sources');

fwrite(STDOUT, "FIDTF v0.3.37 full live actors provider-query context and GPT preflight checks passed: {$checks} / {$checks}\n");
