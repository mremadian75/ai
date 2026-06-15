<?php
/**
 * v0.3.55 — Google Ads asset copy must be CAMPAIGN-FACING (executed, not grep).
 *
 * The previous generator shipped internal-process language as ad copy
 * ("Test the signal route", "Validate before campaign", "Turn signal into
 * brief"). That text can never run as a real ad. The new generator derives
 * consumer-facing copy from topic/market/audience/format; workflow framing
 * (status/caveat/proof-needed) travels ONLY in metadata fields.
 */
require_once __DIR__ . '/test-fidtf-v010.php';

$block_method = new ReflectionMethod('FIDTF_Report_Service', 'google_ads_asset_block');
$block_method->setAccessible(true);
$fields_method = new ReflectionMethod('FIDTF_Report_Service', 'asset_fields');
$fields_method->setAccessible(true);

$terms = [
    ['label' => 'world cup', 'term' => 'world cup', 'count' => 18],
    ['label' => 'football fans', 'term' => 'football fans', 'count' => 9],
];
$hashtags = [['label' => 'worldcup', 'count' => 12]];
$formats = [['label' => 'short-form video', 'count' => 7]];
$quality_strong = [
    'tier_counts' => ['strong' => 30, 'support' => 16, 'creative_only' => 0, 'weak' => 2, 'noise' => 0],
    'decision_ready_rows' => 46,
];
$intel = ['creative_only_count' => 0];
$focus = ['brand' => '', 'market' => 'Spain', 'audience' => 'Spanish Gen Z football fans', 'keywords' => ['world cup'], 'core_terms' => ['world cup']];
$source_summary = [
    'tiktok' => ['relevant_count' => 30, 'status' => 'completed'],
    'google_trends' => ['relevant_count' => 16, 'status' => 'completed'],
];

$block = $block_method->invoke(null, $terms, $hashtags, $formats, $quality_strong, $intel, $focus, $source_summary);

ok(is_array($block) && $block['platform'] === 'Google Ads', 'asset block exists for Google Ads');
ok(count($block['headlines']) === 5 && count($block['long_headlines']) === 5 && count($block['descriptions']) === 5, '5 headlines, 5 long headlines, 5 descriptions');

// Character limits respected and reported.
foreach ($block['headlines'] as $f) { ok($f['limit'] === 40 && $f['count'] <= 40 && $f['valid'], 'headline within 40 chars: "' . $f['text'] . '"'); }
foreach ($block['long_headlines'] as $f) { ok($f['limit'] === 90 && $f['count'] <= 90 && $f['valid'], 'long headline within 90 chars: "' . $f['text'] . '"'); }
foreach ($block['descriptions'] as $f) { ok($f['limit'] === 90 && $f['count'] <= 90 && $f['valid'], 'description within 90 chars: "' . $f['text'] . '"'); }

// Campaign-facing: copy derives from the evidence topic, not workflow language.
$all_copy = '';
foreach (['headlines', 'long_headlines', 'descriptions'] as $group) {
    foreach ($block[$group] as $f) { $all_copy .= ' ' . strtolower($f['text']); }
}
ok(strpos($all_copy, 'world cup') !== false, 'copy is derived from the evidence topic (world cup)');
foreach ([
    'future island', 'signal', 'validate', 'validation', 'hypothesis', 'evidence',
    'brief', 'draft', 'route', 'internal test', 'market test', 'caveat', 'proof',
    'guaranteed', '#1', 'proven',
] as $banned) {
    ok(strpos($all_copy, $banned) === false, "no meta/unsupported term '{$banned}' inside ad copy");
}

// Workflow framing stays in metadata.
ok(in_array($block['status'], ['hypothesis', 'ready_for_internal_test', 'not_client_ready', 'blocked_insufficient_evidence'], true), 'status is a workflow value in metadata');
ok($block['status'] === 'ready_for_internal_test', 'strong evidence yields ready_for_internal_test');
ok(trim((string) $block['evidence_caveat']) !== '', 'evidence caveat attached');
ok(trim((string) $block['proof_needed_note']) !== '', 'proof-needed note attached');
ok(($block['derived_from']['topic'] ?? '') === 'world cup', 'derived_from metadata traces the topic');
ok(($block['derived_from']['market'] ?? '') === 'Spain', 'derived_from metadata traces the market');

// Weak evidence downgrades status; zero usable evidence blocks the block.
$quality_weak = ['tier_counts' => ['strong' => 1, 'support' => 1, 'creative_only' => 3, 'weak' => 4, 'noise' => 0], 'decision_ready_rows' => 2];
$weak_block = $block_method->invoke(null, $terms, $hashtags, $formats, $quality_weak, $intel, $focus, ['tiktok' => ['relevant_count' => 9, 'status' => 'completed']]);
ok($weak_block['status'] === 'hypothesis', 'weak evidence downgrades status to hypothesis');
$quality_zero = ['tier_counts' => ['strong' => 0, 'support' => 0, 'creative_only' => 0, 'weak' => 0, 'noise' => 0], 'decision_ready_rows' => 0];
$zero_block = $block_method->invoke(null, [], [], [], $quality_zero, $intel, $focus, []);
ok($zero_block['status'] === 'blocked_insufficient_evidence', 'zero usable evidence blocks the asset block');

// Over-limit and meta-copy flagging in the field validator itself.
$flagged = $fields_method->invoke(null, [str_repeat('a', 60), 'Validate the signal route with evidence'], 40, 'hypothesis');
ok($flagged[0]['valid'] === false && in_array('over_limit', $flagged[0]['flags'], true), 'over-limit text is flagged invalid');
ok($flagged[1]['valid'] === false && in_array('meta_copy_blocked', $flagged[1]['flags'], true), 'meta-copy text is flagged invalid');

// Intake bridge body (deterministic draft) is also campaign-facing.
$intake_src = file_get_contents(dirname(__DIR__, 3) . '/includes/class-ves-source-intake.php');
if ($intake_src === false) { $intake_src = file_get_contents(dirname(__DIR__) . '/../../includes/class-ves-source-intake.php'); }
ok(strpos($intake_src, "asset_line('Test the route'") === false, 'intake bridge no longer ships "Test the route" as a headline');
ok(strpos($intake_src, "asset_line('Validate before campaign'") === false, 'intake bridge no longer ships "Validate before campaign" as a headline');
ok(strpos($intake_src, 'campaign_topic_from_brief') !== false, 'intake bridge derives copy from the brief topic');

fwrite(STDOUT, "v0.3.55 campaign asset copy checks passed.\n");
exit(0);
