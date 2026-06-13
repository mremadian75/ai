<?php
/**
 * Phase 26B — Normalization field-preservation contract.
 *
 * Executes the REAL normalizers and asserts that the key evidence fields survive
 * normalization, so future normalization/dedup phases cannot silently drop a
 * field that downstream evidence/traceability depends on.
 *
 * Covered:
 *   - FIDTF_Normalizer (Deep Trend Finder) — Instagram + Reddit shapes.
 *   - VES_Trend_Signal_Normalizer (VES Core trend signal layer) — Reddit shape.
 *
 * This file adds tests only. No product code, schema, or scoring is changed.
 * Assertions reflect the ACTUAL current output captured by execution, not an
 * idealized contract. Where the current normalizer drops/derives a field, it is
 * documented as a current-behavior snapshot rather than asserted as desired.
 *
 * Run: php tests/test-normalization-field-preservation.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.3.53'); }

if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return trim((string) $s); } }
if (!function_exists('remove_accents')) { function remove_accents($s) { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0) { return json_encode($v, $f); } }

require_once dirname(__DIR__) . '/includes/class-ves-trend-signal-normalizer.php';
require_once dirname(__DIR__) . '/modules/deep-trend-finder/includes/class-fidtf-normalizer.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$nonempty_str = function ($v) { return is_string($v) && $v !== ''; };

// ───────────────────────────────────────────────────────────────────────────
// A. FIDTF_Normalizer — Instagram raw row
// ───────────────────────────────────────────────────────────────────────────
$ig = FIDTF_Normalizer::normalize('instagram', [
    'shortCode'      => 'ABC123',
    'url'            => 'https://instagram.com/p/ABC123',
    'ownerUsername'  => 'brand',
    'caption'        => 'Vegan protein launch',
    'timestamp'      => '2026-05-01T10:00:00Z',
    'likesCount'     => 500,
    'commentsCount'  => 30,
    'videoViewCount' => 9000,
    'hashtags'       => ['veganprotein'],
    'inputSource'    => 'vegan protein',
    'language'       => 'en',
    'country'        => 'ES',
]);
$ok(($ig['source'] ?? '') === 'instagram' && ($ig['source_key'] ?? '') === 'instagram', 'A: instagram source/source_key preserved');
$ok(($ig['external_id'] ?? '') === 'ABC123', 'A: instagram external_id taken from shortCode');
$ok(($ig['url'] ?? '') === 'https://instagram.com/p/ABC123', 'A: instagram url preserved');
$ok(($ig['author'] ?? '') === 'brand', 'A: instagram author taken from ownerUsername');
$ok(($ig['text'] ?? '') === 'Vegan protein launch' && ($ig['caption'] ?? '') === 'Vegan protein launch', 'A: instagram caption/text preserved');
$ok(($ig['published_at'] ?? '') === '2026-05-01 10:00:00', 'A: instagram published_at normalized from timestamp');
$ok(($ig['language'] ?? '') === 'en', 'A: instagram language preserved');
$ok(($ig['market'] ?? '') === 'ES', 'A: instagram market derived from country');
$ok(($ig['provider_query'] ?? '') === 'vegan protein', 'A: instagram provider_query preserved from inputSource (traceability)');
// Metrics are normalized to numeric (float) values; compare by numeric value.
$ok(($ig['metrics']['views'] ?? null) == 9000, 'A: instagram metrics.views preserved');
$ok(($ig['metrics']['likes'] ?? null) == 500, 'A: instagram metrics.likes preserved');
$ok(($ig['metrics']['comments'] ?? null) == 30, 'A: instagram metrics.comments preserved');

// ───────────────────────────────────────────────────────────────────────────
// B. FIDTF_Normalizer — Reddit raw row
// ───────────────────────────────────────────────────────────────────────────
$rd = FIDTF_Normalizer::normalize('reddit', [
    'id'               => 'r1',
    'permalink'        => 'https://reddit.com/r/x/r1',
    'author'           => 'bob',
    'title'            => 'Best vegan protein 2026',
    'selftext'         => 'discussion body',
    'createdAt'        => '2026-04-01T08:00:00Z',
    'score'            => 120,
    'numberOfComments' => 9,
    'keyword'          => 'vegan protein',
]);
$ok(($rd['source'] ?? '') === 'reddit', 'B: reddit source preserved');
$ok(($rd['external_id'] ?? '') === 'r1', 'B: reddit external_id preserved');
$ok(($rd['url'] ?? '') === 'https://reddit.com/r/x/r1', 'B: reddit url taken from permalink');
$ok(($rd['author'] ?? '') === 'bob', 'B: reddit author preserved');
$ok(($rd['title'] ?? '') === 'Best vegan protein 2026', 'B: reddit title preserved');
$ok($nonempty_str($rd['published_at'] ?? null), 'B: reddit published_at populated from a recognized date key');
$ok(($rd['provider_query'] ?? '') === 'vegan protein', 'B: reddit provider_query preserved (traceability)');
$ok(($rd['metrics']['comments'] ?? null) == 9, 'B: reddit metrics.comments preserved');
$ok(($rd['metrics']['score'] ?? null) == 120, 'B: reddit metrics.score preserved');
// Current behavior snapshot — not necessarily desired behavior:
// the FIDTF normalizer reads camelCase date keys (createdAt) and does NOT read
// snake_case 'created_utc'. Documented so a later normalization phase that
// broadens key support produces a visible diff here.
$rd_snake = FIDTF_Normalizer::normalize('reddit', ['id' => 'r2', 'created_utc' => '2026-04-01', 'title' => 't']);
$ok(($rd_snake['published_at'] ?? '') === '', 'B: [current] snake_case created_utc is NOT mapped to published_at');

// ───────────────────────────────────────────────────────────────────────────
// C. VES_Trend_Signal_Normalizer — Reddit shape (Core trend signal layer)
// ───────────────────────────────────────────────────────────────────────────
$signals = VES_Trend_Signal_Normalizer::normalize_many(
    'reddit',
    ['platform' => 'reddit', 'source_family' => 'discussion', 'source_role' => 'discussion'],
    [[
        'title'    => 'Best vegan protein',
        'selftext' => 'I really like it',
        'url'      => 'https://reddit.com/r/x/1',
        'author'   => 'bob',
        'score'    => 120,
        'num_comments' => 9,
        'date'     => '2026-05-01',
    ]],
    ['topic' => 'vegan protein', 'market' => 'ES', 'language' => 'es', 'run_id' => 'R1']
);
$ok(count($signals) === 1, 'C: VES trend normalizer returns one signal for one row');
$sig = $signals[0];
$ok($nonempty_str($sig['signal_id'] ?? null), 'C: signal_id is generated (identity/traceability)');
$ok(($sig['source_key'] ?? '') === 'reddit', 'C: source_key preserved');
$ok(($sig['platform'] ?? '') === 'reddit', 'C: platform preserved');
$ok(($sig['url'] ?? '') === 'https://reddit.com/r/x/1', 'C: url preserved');
$ok(($sig['author_or_channel'] ?? '') === 'bob', 'C: author_or_channel preserved');
$ok($nonempty_str($sig['published_at'] ?? null), 'C: published_at preserved from recognized date key');
$ok(($sig['market'] ?? '') === 'ES', 'C: market carried from request');
$ok(($sig['language'] ?? '') === 'es', 'C: language carried from request');
$ok(($sig['metrics']['upvotes'] ?? null) == 120, 'C: metrics.upvotes preserved from score');
$ok(array_key_exists('confidence', $sig) && is_numeric($sig['confidence']), 'C: per-signal confidence is computed');

echo "\nNormalization field-preservation contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
