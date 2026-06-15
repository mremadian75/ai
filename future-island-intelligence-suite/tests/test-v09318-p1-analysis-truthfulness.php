<?php
require __DIR__ . '/bootstrap-wp-shims.php';

if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return trim((string) $s); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($n, $decimals = 0) { return number_format((float) $n, (int) $decimals); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null, $enc = null) { return $length === null ? substr((string) $s, (int) $start) : substr((string) $s, (int) $start, (int) $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen($s, $enc = null) { return strlen((string) $s); } }

$root = dirname(__DIR__);
require_once $root . '/includes/analysis.php';
require_once $root . '/includes/class-ves-ajax-controller.php';
require_once $root . '/includes/class-ves-openai-client.php';

$checks = 0;
$failures = [];
$assert = function($condition, $label) use (&$checks, &$failures) {
    $checks++;
    if (!$condition) { $failures[] = $label; }
};

$fixture_one = ves_normalize_social_metrics([
    'views_or_plays' => 80700,
    'likes' => 2786,
    'comments' => 97,
    'shares' => 721,
]);
$assert($fixture_one['views'] === 80700, 'views_or_plays maps to canonical views');
$assert($fixture_one['likes'] === 2786, 'likes stays canonical');
$assert($fixture_one['comments'] === 97, 'comments stays canonical');
$assert($fixture_one['shares'] === 721, 'shares stays canonical');
$assert($fixture_one['saves'] === null, 'missing saves remains null');
$assert($fixture_one['engagement_visible'] === true, 'engagement_visible true when any metric exists');

$fixture_two = ves_normalize_social_metrics([
    'playCount' => 104467,
    'diggCount' => 367,
    'commentCount' => 176,
    'shareCount' => 110,
    'collectCount' => 55,
]);
$assert($fixture_two['views'] === 104467, 'playCount maps to canonical views');
$assert($fixture_two['likes'] === 367, 'diggCount maps to canonical likes');
$assert($fixture_two['comments'] === 176, 'commentCount maps to canonical comments');
$assert($fixture_two['shares'] === 110, 'shareCount maps to canonical shares');
$assert($fixture_two['saves'] === 55, 'collectCount maps to canonical saves');
$assert($fixture_two['engagement_visible'] === true, 'engagement_visible true for TikTok alias fixture');

$formatted = ves_normalize_social_metrics(['playCount' => '80.7K', 'diggCount' => '2,786', 'commentCount' => '-', 'shareCount' => 'bad']);
$assert($formatted['views'] === 80700, '80.7K parses safely');
$assert($formatted['likes'] === 2786, '2,786 parses safely');
$assert($formatted['comments'] === null, 'dash metric becomes null');
$assert($formatted['shares'] === null, 'invalid metric becomes null');

$ajax = new ReflectionClass('VES_Ajax_Controller');
$normalize = $ajax->getMethod('normalize_analysis_item_payload');
$normalize->setAccessible(true);
$payload = [
    'platform' => 'tiktok',
    'title' => 'Selected TikTok card',
    'url' => 'https://www.tiktok.com/@demo/video/1234567890123456789',
    'caption_or_text' => 'Visible selected caption',
    'metrics' => ['views_or_plays' => 80700, 'likes' => 2786, 'comments' => 97, 'shares' => 721],
    'raw' => ['playCount' => 1, 'diggCount' => 2, 'raw_payload' => 'should not leak'],
];
$normalized = $normalize->invoke(null, $payload);
$assert(($normalized['metrics']['views'] ?? null) === 80700, 'selected item backend payload preserves preview views');
$assert(($normalized['metrics']['likes'] ?? null) === 2786, 'selected item backend payload preserves preview likes');
$assert(($normalized['metrics']['comments'] ?? null) === 97, 'selected item backend payload preserves preview comments');
$assert(($normalized['metrics']['shares'] ?? null) === 721, 'selected item backend payload preserves preview shares');
$assert(isset($normalized['raw_preview_payload']) && is_array($normalized['raw_preview_payload']), 'raw_preview_payload survives as safe preview payload');
$assert(!array_key_exists('raw_payload', $normalized['raw_preview_payload']), 'raw diagnostics stripped from preview payload');

$enrich = $ajax->getMethod('maybe_enrich_selected_item_for_analysis');
$enrich->setAccessible(true);
$enriched = $enrich->invoke(null, 'tiktok', $normalized, 'REQ-P1');
$assert(($enriched['metrics']['views'] ?? null) === 80700, 'preview views survive enrichment skip/failure');
$assert(($enriched['ves_views'] ?? null) === 80700, 'preview views become canonical ves_views floor');
$assert(($enriched['ves_enrichment_status'] ?? '') !== '', 'enrichment failure/skip is explicit');

$openai = new ReflectionClass('VES_OpenAI_Client');
$packMethod = $openai->getMethod('build_social_evidence_pack');
$packMethod->setAccessible(true);
$pack = $packMethod->invoke(null, 'tiktok', [
    'url' => 'https://www.tiktok.com/@demo/video/123',
    'caption_or_text' => 'Caption visible sobre marketing',
    'authorMeta' => ['name' => 'demo'],
    'views_or_plays' => 80700,
    'likes' => 2786,
    'comments' => 97,
    'shares' => 721,
    'commentsList' => [['text' => 'Me gusta', 'author' => 'u1']],
]);
$assert(($pack['metrics']['views'] ?? null) === 80700, 'evidence pack keeps normalized views');
$assert(($pack['metrics']['likes'] ?? null) === 2786, 'evidence pack keeps normalized likes');
$assert(($pack['evidence_quality']['caption'] ?? false) === true, 'evidence_quality caption true');
$assert(($pack['evidence_quality']['metrics'] ?? false) === true, 'evidence_quality metrics true');
$assert(($pack['evidence_quality']['comments'] ?? false) === true, 'evidence_quality comments true with sample');
$assert(($pack['evidence_quality']['video_frames'] ?? true) === false, 'evidence_quality video_frames false');
$assert(($pack['evidence_quality']['audio'] ?? true) === false, 'evidence_quality audio false');
$assert(($pack['evidence_quality']['transcript'] ?? true) === false, 'evidence_quality transcript false without transcript');
$assert(($pack['video_frames'] ?? null) === [], 'evidence pack exposes empty video_frames array');
$assert(array_key_exists('audio_transcript', $pack) && $pack['audio_transcript'] === null, 'evidence pack exposes null audio_transcript');

$fallbackMethod = $openai->getMethod('build_local_item_analysis_structured_fallback');
$fallbackMethod->setAccessible(true);
$structured = $fallbackMethod->invoke(null, $pack, '', new WP_Error('upstream_unavailable', 'provider unavailable'));
$assert(($structured['analysis_mode'] ?? '') === 'caption_metrics_only', 'local fallback uses caption_metrics_only for caption+metrics');
$assert(($structured['confidence']['level'] ?? '') !== 'high', 'local fallback never shows high confidence with limited evidence');
$assert(($structured['performance_metrics']['views'] ?? null) === 80700, 'local fallback displays normalized views');
$assert(strpos(implode(' ', $structured['limitations'] ?? []), 'No se realizó análisis visual frame-by-frame') !== false, 'local fallback includes honest limitation');

$formatMethod = $openai->getMethod('format_item_analysis_text_from_structured');
$formatMethod->setAccessible(true);
$text = $formatMethod->invoke(null, $structured);
$assert(strpos($text, 'Views: 80,700') !== false, 'formatted local fallback shows views, not dash');
$assert(strpos($text, '✕ Video frame-by-frame') !== false, 'formatted analysis explicitly marks no frame-by-frame evidence');
$assert(strpos($text, '✕ Audio') !== false, 'formatted analysis explicitly marks no audio evidence');
$assert(strpos($text, 'GPT-5.1') === false, 'local fallback text does not fake GPT-5.1 model label');

$normalizeStructured = $openai->getMethod('normalize_item_analysis_structured');
$normalizeStructured->setAccessible(true);
$ai = [
    'analysis_mode' => 'full_creative',
    'confidence' => ['level' => 'high', 'reason' => 'claimed full media'],
    'evidence_available' => ['caption' => true, 'metrics' => true, 'video_preview' => true, 'audio' => true, 'transcript' => true, 'comments' => true, 'profile_context' => true, 'video_frames' => true],
    'evidence_quality' => ['caption' => true, 'metrics' => true, 'comments' => true, 'transcript' => true, 'video_frames' => true, 'audio' => true],
    'performance_metrics' => ['views' => 999999, 'likes' => 1, 'comments' => 1, 'shares' => 1, 'saves' => 1, 'derived_ratios' => []],
];
$clean = $normalizeStructured->invoke(null, $ai, $pack, 'es');
$assert(($clean['performance_metrics']['views'] ?? null) === 80700, 'AI invented metric overwritten by evidence pack metric');
$assert(($clean['evidence_quality']['video_frames'] ?? true) === false, 'AI visual/frame claim removed when unavailable');
$assert(($clean['evidence_quality']['audio'] ?? true) === false, 'AI audio claim removed when unavailable');
$assert(($clean['evidence_quality']['transcript'] ?? true) === false, 'AI transcript claim removed when unavailable');
$assert(($clean['confidence']['level'] ?? '') !== 'high', 'confidence downgraded when evidence is limited');
$assert(strpos(implode(' ', $clean['limitations'] ?? []), 'No se realizó análisis visual frame-by-frame') !== false, 'normalized AI includes honest limitation');

$php = file_get_contents($root . '/includes/class-ves-openai-client.php');
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$assert(strpos($php, "'model_label' => 'Análisis local de respaldo'") !== false, 'local fallback label is explicit');
$assert(strpos($php, "'model_label' => 'Texto AI + métricas locales'") !== false, 'invalid AI JSON/text-only label is explicit');
$assert(strpos($php, "'analysis_source' => 'ai_text_with_local_metrics'") !== false, 'invalid AI JSON becomes ai_text_with_local_metrics');
$assert(strpos($js, 'raw_preview_payload: previewRaw') !== false, 'frontend sends raw_preview_payload');
$assert(strpos($js, 'Video frame-by-frame') !== false, 'frontend evidence UI names frame-by-frame honestly');

if ($failures) {
    fwrite(STDERR, "v0.9.31.8-p4.1-deep-trend-addon-live-qa-hotfix checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'v0.9.31.8-p4.1-deep-trend-addon-live-qa-hotfix checks passed: ' . $checks . ' / ' . $checks . PHP_EOL;
