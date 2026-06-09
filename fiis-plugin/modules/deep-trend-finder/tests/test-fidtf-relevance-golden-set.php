<?php
/**
 * v1.1.0 — Deep Trend Finder relevance policy golden set.
 *
 * Pins the CURRENT intended anti-noise relevance policy of FIDTF_Relevance_Filter
 * across canonical cases, so the policy is an explicit, regression-proof contract
 * (not implicit comment-driven behavior). These assertions reflect the ACTUAL
 * current implementation — they were captured by executing the real filter, not
 * invented. This test changes no product code and no scoring.
 *
 * Policy summary (current, v0.3.26 / v0.3.37 / v0.3.46 hardening):
 *   - is_relevant === (recommended_use !== 'hide').
 *   - A textual "core match" (the request keyword literally present in the item
 *     text/hashtags) is what unlocks 'show'/'support'. provider_query provenance
 *     adds a small additive boost (cap +12) but does NOT set has_core_match.
 *   - With core intent but no textual core match, score < 50 => 'hide'.
 *   - Generic/category-only terms (beverage, mocktail, drinks, summer, fyp, ...)
 *     do not count as a core match.
 *   - TikTok rows below the minimum view threshold (10k) are zeroed as noise
 *     before semantic scoring.
 *
 * Run: php modules/deep-trend-finder/tests/test-fidtf-relevance-golden-set.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('FIDTF_PLUGIN_DIR')) { define('FIDTF_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('FIDTF_PLUGIN_URL')) { define('FIDTF_PLUGIN_URL', 'https://example.test/'); }
if (!defined('FIDTF_VERSION')) { define('FIDTF_VERSION', '0.3.53'); }
if (!defined('FI_DTF_ENABLE_DEEP_VIDEO')) { define('FI_DTF_ENABLE_DEEP_VIDEO', false); }

if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return trim((string) $s); } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d = null) { return $s; } }
if (!function_exists('esc_attr__')) { function esc_attr__($s, $d = null) { return $s; } }
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
$GLOBALS['__fidtf_options'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__fidtf_options'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__fidtf_options'][$k] = $v; return true; } }

require_once dirname(__DIR__) . '/includes/class-fidtf-settings.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-relevance-filter.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-provider-apify.php';
require_once dirname(__DIR__) . '/includes/providers/class-fidtf-provider-tiktok.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$req  = ['keywords' => ['beverage'], 'market' => ''];
$plan = ['queries' => ['beverage'], 'max_items' => 50];

$make = function (array $over = []) {
    return array_merge([
        'inputSource' => 'beverage', 'id' => '1', 'title' => 'x',
        'views' => 22260, 'likes' => 529, 'comments' => 4, 'shares' => 50, 'bookmarks' => 438,
        'hashtags' => [], 'channel' => ['username' => 'creator'],
        'uploadedAtFormatted' => '2026-05-14T05:42:03.000Z',
        'video' => ['url' => 'https://cdn.test/v.mp4', 'thumbnail' => 'https://cdn.test/t.jpg'],
        'postPage' => 'https://www.tiktok.com/@creator/video/1',
    ], $over);
};
$score_of = function (array $row) use ($req, $plan) {
    $item = FIDTF_Normalizer::normalize('tiktok', FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([$row])[0]);
    return FIDTF_Relevance_Filter::score($item, $req, $plan);
};

// ── A. Clear relevant — literal core match in text ──────────────────────────
$a = $score_of($make(['title' => 'best beverage refresher review', 'hashtags' => ['beverage', 'mocktail']]));
$ok(!empty($a['matched_core_keywords']), 'A: literal core term produces a core match');
$ok($a['recommended_use'] === 'show', 'A: literal core match => recommended_use=show');
$ok($a['is_relevant'] === true, 'A: literal core match => is_relevant=true');
$ok(in_array($a['evidence_tier'], ['support', 'strong'], true), 'A: literal core match => evidence_tier support/strong');

// ── B. Semantically related, NO literal core match (the v0323 case) ─────────
$b = $score_of($make(['title' => 'strawberry lime refresher every single day #mocktail', 'hashtags' => ['refresher', 'mocktail', 'summerrecipes']]));
$ok(empty($b['matched_core_keywords']), 'B: semantic-only content has NO core match');
$ok($b['score'] >= 45 && $b['score'] < 50, 'B: score lands in the 45..50 anti-noise band');
$ok($b['recommended_use'] === 'hide', 'B: no literal core + score<50 => recommended_use=hide');
$ok($b['is_relevant'] === false, 'B: semantic-only provider result is NOT relevant (anti-noise policy)');
$ok($b['evidence_tier'] === 'noise', 'B: semantic-only provider result => evidence_tier=noise');
$ok(strpos($b['reason'], 'provider discovery query') !== false, 'B: reason still credits provider discovery query provenance');

// ── C. Provider-query matched but content off-topic ─────────────────────────
$c = $score_of($make(['title' => 'car detailing tips for beginners', 'hashtags' => ['cars', 'detailing']]));
$ok(empty($c['matched_core_keywords']), 'C: off-topic content has no core match');
$ok($c['recommended_use'] === 'hide', 'C: off-topic provider result => hide');
$ok($c['is_relevant'] === false, 'C: provider-query provenance alone does not make off-topic content relevant');

// ── D. High-engagement generic/viral item (documented current behavior) ─────
// NOTE: under the current implementation, a high-engagement generic item can
// reach score >= threshold via metrics alone and is shown as WEAK evidence even
// without a core match. This is documented here as the current contract; it is a
// candidate for the future relevance review (engagement-without-topic), NOT a
// fake-green expectation.
$d = $score_of($make(['title' => 'wait for it #fyp #viral', 'views' => 5000000, 'likes' => 900000, 'comments' => 8000, 'shares' => 40000, 'bookmarks' => 12000, 'hashtags' => ['fyp', 'viral', 'foryou']]));
$ok(empty($d['matched_core_keywords']), 'D: viral generic item has no core match');
$ok($d['evidence_tier'] !== 'strong' && $d['evidence_tier'] !== 'support', 'D: viral generic item is never strong/support evidence');
$ok(!$d['client_safe'], 'D: viral generic item is not client_safe');

// ── E. Low/modest-engagement but precise (literal core, above min-view gate) ─
$e = $score_of($make(['title' => 'beverage taste test', 'views' => 12000, 'likes' => 20, 'comments' => 1, 'shares' => 0, 'bookmarks' => 2, 'hashtags' => ['beverage']]));
$ok(!empty($e['matched_core_keywords']), 'E: precise literal core match is recognized even with modest engagement');
$ok($e['is_relevant'] === true, 'E: precise literal core (above min-view) is relevant despite low engagement');
$ok(in_array($e['evidence_tier'], ['support', 'strong', 'weak'], true), 'E: precise literal core yields usable (non-noise) evidence tier');

// E0. Below the TikTok minimum view threshold => zeroed as noise (documented gate).
$e0 = $score_of($make(['title' => 'beverage taste test', 'views' => 9999, 'likes' => 5, 'hashtags' => ['beverage']]));
$ok($e0['score'] == 0.0 && $e0['is_relevant'] === false, 'E0: below TikTok min-view threshold => zeroed as noise before semantic scoring');

// ── F. Broad-category / generic-only false positive ─────────────────────────
$f = $score_of($make(['title' => 'summer drinks vibes', 'hashtags' => ['drinks', 'summer']]));
$ok(empty($f['matched_core_keywords']), 'F: generic-only content has no core match');
$ok($f['recommended_use'] === 'hide', 'F: generic-only category content => hide');
$ok($f['is_relevant'] === false, 'F: broad-category false positive is suppressed by anti-noise policy');

echo "\nFIDTF relevance golden set: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
