<?php
/**
 * Phase 26B — Deep Trend Finder relevance golden set (EXTENDED).
 *
 * Safety-net coverage captured by EXECUTING the real FIDTF_Normalizer +
 * FIDTF_Relevance_Filter path (no product code is changed). These assertions
 * pin the CURRENT behavior so later intelligence phases (relevance review,
 * engagement-vs-topic rebalancing) can change scoring deliberately and see it
 * here, instead of silently. Several cases are explicitly labelled:
 *
 *   "Current behavior snapshot — not necessarily desired behavior."
 *
 * They are passing snapshots of today's logic, NOT fake-green expressions of a
 * future desired state. The suite stays green.
 *
 * Scope guard: this file adds tests only. It does not touch scoring, weights,
 * thresholds, normalization, or any production class.
 *
 * Run: php modules/deep-trend-finder/tests/test-fidtf-relevance-golden-set-extended.php
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
$norm = function (array $row) {
    return FIDTF_Normalizer::normalize('tiktok', FIDTF_Provider_TikTok::flatten_scraptik_dataset_items([$row])[0]);
};
$score_of = function (array $row) use ($req, $plan, $norm) {
    return FIDTF_Relevance_Filter::score($norm($row), $req, $plan);
};

// Fixtures.
$relevant = $norm($make(['id' => 'rel', 'title' => 'beverage taste test', 'views' => 12000, 'likes' => 20, 'comments' => 1, 'shares' => 0, 'bookmarks' => 2, 'hashtags' => ['beverage']]));
$viral    = $norm($make(['id' => 'vir', 'title' => 'wait for it #fyp #viral', 'views' => 5000000, 'likes' => 900000, 'comments' => 8000, 'shares' => 40000, 'bookmarks' => 12000, 'hashtags' => ['fyp', 'viral', 'foryou']]));
$mid      = $norm($make(['id' => 'mid', 'title' => 'beverage review honest', 'views' => 40000, 'likes' => 300, 'comments' => 10, 'hashtags' => ['beverage']]));

$rs = FIDTF_Relevance_Filter::score($relevant, $req, $plan);
$vs = FIDTF_Relevance_Filter::score($viral, $req, $plan);

// ── 1. Clearly relevant low-engagement passes STRONGER than irrelevant viral ──
$ok(!empty($rs['matched_core_keywords']), '1: relevant low-engagement item has a literal core match');
$ok(empty($vs['matched_core_keywords']), '1: irrelevant viral item has NO core match');
$ok($rs['client_safe'] === true, '1: relevant item is client_safe');
$ok($vs['client_safe'] === false, '1: viral irrelevant item is NOT client_safe');
$ok(in_array($rs['evidence_tier'], ['support', 'strong'], true), '1: relevant item reaches support/strong evidence tier');
$ok(!in_array($vs['evidence_tier'], ['support', 'strong'], true), '1: viral irrelevant item never reaches support/strong tier');
$ok($rs['score'] > $vs['score'], '1: relevant low-engagement item scores HIGHER than irrelevant viral item');

// ── 2. Generic high-engagement is not strong evidence (documented weakness) ───
// Current behavior snapshot — not necessarily desired behavior.
// The viral generic item still receives recommended_use='show' / evidence_tier='weak'
// purely on engagement, despite zero topical match. This is the known
// "engagement-without-topic" weakness flagged in the Phase 26A audit. It is
// pinned here as the CURRENT contract so a future relevance phase that hides it
// will produce a visible, intentional diff in this test — not a silent change.
$ok($vs['recommended_use'] === 'show', '2: [current] viral generic item is still surfaced (show) on engagement alone');
$ok($vs['evidence_tier'] === 'weak', '2: [current] viral generic item is graded weak, not hidden');
$ok($vs['client_safe'] === false, '2: viral generic item is at least gated out of client-safe use');

// ── 3. Market/language match helps but does NOT override topical mismatch ─────
$offtopic_es = $norm($make(['id' => 'off', 'title' => 'car detailing tips for beginners', 'market' => 'ES', 'language' => 'es', 'hashtags' => ['cars', 'detailing']]));
$os = FIDTF_Relevance_Filter::score($offtopic_es, ['keywords' => ['beverage'], 'market' => 'ES', 'language' => 'es'], $plan);
$ok(empty($os['matched_core_keywords']), '3: off-topic-but-market-matched item has no core match');
$ok($os['recommended_use'] === 'hide', '3: market/language match does NOT rescue a complete topical mismatch (hide)');
$ok($os['is_relevant'] === false, '3: off-topic item stays not relevant despite matching market/language');

// ── 4. Source/platform metadata is preserved through scoring output ───────────
$scored_items = FIDTF_Relevance_Filter::filter_items([$viral, $relevant, $mid], $req, $plan);
$ok(count($scored_items) === 3, '4: filter_items returns every input row');
$all_have_source = true; $all_have_url = true; $all_have_score = true;
foreach ($scored_items as $it) {
    if (($it['source_key'] ?? '') !== 'tiktok') { $all_have_source = false; }
    if (empty($it['url'])) { $all_have_url = false; }
    if (!array_key_exists('relevance_score', $it)) { $all_have_score = false; }
}
$ok($all_have_source, '4: source_key survives onto every scored item');
$ok($all_have_url, '4: source url survives onto every scored item');
$ok($all_have_score, '4: relevance_score is attached to every scored item');

// ── 5. Ordering is deterministic for the tested fixture ──────────────────────
$order1 = array_map(static function ($i) { return $i['external_id']; }, $scored_items);
$scored_again = FIDTF_Relevance_Filter::filter_items([$mid, $relevant, $viral], $req, $plan); // different input order
$order2 = array_map(static function ($i) { return $i['external_id']; }, $scored_again);
$ok($order1 === $order2, '5: ranking order is stable regardless of input order (deterministic sort)');
$scores_desc = array_map(static function ($i) { return $i['relevance_score']; }, $scored_items);
$sorted = $scores_desc; rsort($sorted);
$ok($scores_desc === $sorted, '5: scored items are returned in descending relevance_score order');
$ok($order1[count($order1) - 1] === 'vir', '5: the irrelevant viral item ranks last among the fixture');

echo "\nFIDTF relevance golden set (extended): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
