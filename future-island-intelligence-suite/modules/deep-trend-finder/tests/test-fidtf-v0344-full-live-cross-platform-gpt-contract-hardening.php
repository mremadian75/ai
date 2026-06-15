<?php
require_once __DIR__ . '/test-fidtf-v0343-full-live-actor-gpt-data-scientist-hardening.php';
$plugin = file_get_contents(__DIR__ . '/../../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-ai-bridge.php');
$filter_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-relevance-filter.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.44
ok(strpos($bridge_src, 'prepare_openai_smoke_sample') !== false, 'OpenAI smoke test prepares cross-platform evidence package');
ok(strpos($bridge_src, "array_slice((array) (\$sample['evidence'] ?? []), 0, 25)") !== false, 'OpenAI smoke test accepts a larger multi-platform evidence sample');
ok(strpos($bridge_src, 'sample_profile') !== false, 'OpenAI smoke test exposes sample profile diagnostics');
ok(strpos($bridge_src, 'platform_interaction_map') !== false, 'OpenAI schema requires platform interaction map');
ok(strpos($bridge_src, 'claim_audit') !== false, 'OpenAI schema requires claim audit');
ok(strpos($bridge_src, 'follow_up_test_design') !== false, 'OpenAI schema requires follow-up test design');
ok(strpos($bridge_src, 'data_scientist_verdict') !== false, 'OpenAI schema requires data scientist verdict');
ok(strpos($filter_src, 'marketing_intent_context_gate') !== false, 'marketing-intent context gate exists');
ok(strpos($filter_src, 'celebrity/news/community drift') !== false, 'Reddit/news drift warning exists');

$schema_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_response_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke(null);
foreach (['platform_interaction_map', 'claim_audit', 'follow_up_test_design', 'sample_limits', 'data_scientist_verdict'] as $field) {
    ok(in_array($field, (array) ($schema['required'] ?? []), true), "OpenAI data scientist schema requires $field");
}

$sample_method = new ReflectionMethod('FIDTF_AI_Bridge', 'prepare_openai_smoke_sample');
$sample_method->setAccessible(true);
$prepared = $sample_method->invoke(null, [
    'request_context' => ['keywords' => ['seo'], 'market' => 'ES'],
    'evidence' => [
        ['source_key' => 'tiktok', 'caption_or_text' => 'SEO tips', 'metrics' => ['views' => 779], 'relevance_tier' => 'direct'],
        ['source_key' => 'instagram', 'caption_or_text' => 'SEO agency post', 'metrics' => ['likes' => 0], 'relevance_tier' => 'direct'],
        ['source_key' => 'google_news', 'title' => 'SEO/BirdLife unrelated publisher collision', 'metrics' => [], 'relevance_tier' => 'noise'],
    ],
]);
ok(($prepared['sample_profile']['row_count'] ?? 0) === 3, 'prepared sample counts row volume');
ok(($prepared['sample_profile']['source_counts']['tiktok'] ?? 0) === 1, 'prepared sample counts TikTok source');
ok(in_array('never_use_publisher_or_author_name_as_semantic_proof', (array) ($prepared['sample_profile']['required_analysis_behavior'] ?? []), true), 'prepared sample carries semantic-proof guardrail');

$item = [
    'source_key' => 'reddit',
    'title' => 'Daily Express - Netflix strategy branded annoying',
    'caption_or_text' => 'Celebrity discussion about a personal brand and public narrative, with entertainment gossip rather than commercial evidence.',
    'author' => 'Feisty_Energy_107',
    'provider_query' => 'brand strategy',
    'metrics' => ['score' => 375, 'comments' => 209],
    'url' => 'https://example.com/reddit',
    'published_at' => '2026-05-12T08:38:45Z',
];
$score = FIDTF_Relevance_Filter::score($item, ['keywords' => ['brand strategy']], []);
ok(($score['score'] ?? 100) <= 46, 'celebrity/community brand-strategy drift is capped');
ok(strpos((string) ($score['reason'] ?? ''), 'business or marketing context') !== false, 'brand-strategy drift reason is exposed');

fwrite(STDOUT, "FIDTF v0.3.44 full live cross-platform GPT contract hardening checks passed: {$checks} / {$checks}\n");
