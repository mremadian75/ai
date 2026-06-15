<?php
require_once __DIR__ . '/test-fidtf-v0342-cross-platform-statistical-scoring-and-gpt-analysis-contract.php';
$plugin = file_get_contents(__DIR__ . '/../../../future-island-intelligence-suite.php');
$filter_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-relevance-filter.php');
$bridge_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-ai-bridge.php');
$report_src = file_get_contents(__DIR__ . '/../includes/class-fidtf-report-service.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.43
ok(strpos($filter_src, 'SEO/BirdLife') !== false, 'live SEO/BirdLife false-positive case is documented in filter');
ok(strpos($filter_src, 'acronym_context_gate') !== false, 'acronym context gate exists');
ok(strpos($filter_src, "['google_news', 'reddit']") !== false, 'news/community author metadata excluded from semantic text');
ok(strpos($filter_src, 'search/marketing context') !== false, 'SEO acronym context warning exists');
ok(strpos($bridge_src, 'triangulation_matrix') !== false, 'OpenAI schema requires triangulation matrix');
ok(strpos($bridge_src, 'decision_confidence_score') !== false, 'OpenAI schema requires decision confidence score');
ok(strpos($bridge_src, 'evidence_quality_score') !== false, 'OpenAI schema requires evidence quality score');
ok(strpos($bridge_src, 'must_not_claim') !== false, 'OpenAI schema requires must_not_claim guardrails');
ok(strpos($bridge_src, 'Triangulate claims') !== false, 'OpenAI prompt explicitly requires triangulation');
ok(strpos($report_src, 'Acronym and entity-name collisions') !== false, 'report warns about acronym/entity collisions');

$schema_method = new ReflectionMethod('FIDTF_AI_Bridge', 'openai_smoke_response_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke(null);
foreach (['triangulation_matrix', 'decision_confidence_score', 'evidence_quality_score', 'must_not_claim'] as $field) {
    ok(in_array($field, (array) ($schema['required'] ?? []), true), "OpenAI schema requires $field");
}

$item = [
    'source_key' => 'google_news',
    'title' => 'Lanzamos una campaña de firmas por los Parques Nacionales',
    'caption_or_text' => '',
    'author' => 'SEO/BirdLife',
    'provider_query' => 'seo',
    'metrics' => [],
    'url' => 'https://example.com',
    'published_at' => '2026-05-26T08:57:08Z',
];
$score = FIDTF_Relevance_Filter::score($item, ['keywords' => ['seo']], []);
ok(($score['recommended_use'] ?? '') === 'hide', 'SEO/BirdLife publisher-name match is hidden without SEO marketing context');
ok(($score['score'] ?? 100) <= 32, 'SEO acronym false-positive is capped');

fwrite(STDOUT, "FIDTF v0.3.43 full live actor GPT data-scientist hardening checks passed: {$checks} / {$checks}\n");
