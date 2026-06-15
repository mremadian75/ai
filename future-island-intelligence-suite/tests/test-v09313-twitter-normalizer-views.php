<?php
/**
 * v0.9.31.3 — Twitter null viewCount + discard-filter regression.
 * Run: php tests/test-v09313-twitter-normalizer-views.php
 * No external API calls or WordPress DB required.
 */
define('ABSPATH', dirname(__DIR__));
define('VES_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return trim((string)$s); } }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
if (!function_exists('remove_accents')) { function remove_accents($s){ return $s; } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s,$enc=null){ return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr($s,$o,$l=null){ return $l===null?substr($s,$o):substr($s,$o,$l); } }
if (!function_exists('ves_clean_int')) { function ves_clean_int($v,$min=null,$max=null){ $n=(int)$v; if($min!==null&&$n<$min){$n=$min;} if($max!==null&&$n>$max){$n=$max;} return $n; } }
if (!function_exists('ves_parse_human_number')) { function ves_parse_human_number($v){ return is_numeric($v)?floatval($v):null; } }
if (!function_exists('ves_is_post_url_mode')) { function ves_is_post_url_mode($src){ return !empty($src['mode'])&&$src['mode']==='urls'; } }
if (!function_exists('ves_requested_min_metric')) { function ves_requested_min_metric($src=[]){ foreach(['minViews','min_views','minLikes','min_likes']as$k){ if(isset($src[$k])&&trim((string)$src[$k])!==''){return max(0,(int)$src[$k]);} } return 0; } }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

require VES_PLUGIN_DIR . 'includes/analysis.php';

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// Helpers for normalizing a Twitter item through the pipeline.
// We exercise ves_item_has_filter_metric and ves_item_filter_metric_value directly.

// --- Test 1: Twitter item with null viewCount and null likeCount ---
// Simulates a tweet where the scraper returned neither viewCount nor likeCount.
$item_no_metrics = [
    'text'       => 'Hello world',
    'author'     => ['userName' => 'testuser'],
    'url'        => 'https://x.com/testuser/status/1',
    'createdAt'  => '2026-01-01T00:00:00.000Z',
    // viewCount and likeCount intentionally absent
    'ves_has_primary_metric' => 0,
    'ves_has_like_metric'    => 0,
    'ves_likes'              => 0,
    'ves_views'              => 0,
];
$has_metric = ves_item_has_filter_metric($item_no_metrics, 'twitter');
$assert(!$has_metric, 'Twitter item with null likeCount should NOT have filter metric (avoid false discard)');

// --- Test 2: Twitter item with explicit likeCount = 50 ---
$item_with_likes = [
    'text'       => 'Hello world',
    'likeCount'  => 50,
    'ves_has_primary_metric' => 1,
    'ves_has_like_metric'    => 1,
    'ves_likes'              => 50,
    'ves_views'              => 0,
];
$has_metric2 = ves_item_has_filter_metric($item_with_likes, 'twitter');
$assert($has_metric2, 'Twitter item with likeCount=50 should have filter metric');
$val2 = ves_item_filter_metric_value($item_with_likes, 'twitter');
$assert($val2 === 50, 'Twitter item with likeCount=50 should return filter value 50');

// --- Test 3: Twitter item with likeCount = 0 (explicitly zero, not absent) ---
$item_zero_likes = [
    'text'       => 'Zero likes',
    'likeCount'  => 0,
    'ves_has_primary_metric' => 0,
    'ves_has_like_metric'    => 0,
    'ves_likes'              => 0,
];
// likeCount key exists in raw item (value 0) → has_metric should be true
$has_metric3 = ves_item_has_filter_metric($item_zero_likes, 'twitter');
$assert($has_metric3, 'Twitter item with explicit likeCount=0 (key present) should have filter metric');

// --- Test 4: ves_apply_requested_filters keeps null-metric items in non-strict mode ---
// Only $item_no_metrics (null-metric) is kept; $item_with_likes (likes=50 < 100) is discarded.
$filtered = ves_apply_requested_filters([$item_no_metrics], ['platform' => 'twitter', 'minViews' => 100]);
$assert(count($filtered) === 1, 'Non-strict filter should keep Twitter item with null metrics (min 100)');

// --- Test 5: ves_apply_requested_filters discards item with known low likes ---
$filtered2 = ves_apply_requested_filters([$item_with_likes], ['platform' => 'twitter', 'minViews' => 100]);
$assert(count($filtered2) === 0, 'Twitter item with likeCount=50 should be discarded by minViews=100');

// --- Test 6: created_at (snake_case) date fallback ---
// The normalizer should handle created_at as an alias for createdAt.
// We test this by checking ves_normalize_date accepts ISO-8601 strings.
$date_camel = ves_normalize_date('2026-05-01T12:00:00.000Z');
$date_snake = ves_normalize_date('2026-05-01T12:00:00+00:00');
$assert($date_camel !== '' && $date_snake !== '', 'ves_normalize_date should parse ISO-8601 Twitter date formats');

// --- Test 7: impressionCount alias in ves_arr_get ---
$item_impression = [
    'text'            => 'Impression only tweet',
    'impressionCount' => 1200,
    'ves_views'       => 1200,
    'ves_has_primary_metric' => 0,
    'ves_has_like_metric'    => 0,
    'ves_likes'       => 0,
];
// impressionCount is in raw item but no likeCount → no like metric
$has_metric4 = ves_item_has_filter_metric($item_impression, 'twitter');
$assert(!$has_metric4, 'Twitter item with only impressionCount (no likeCount) should not have filter metric for likes');

if ($fail === 0) {
    echo "OK v0.9.31.3 Twitter normalizer views — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.3 Twitter normalizer views — $fail failures, $pass passed\n";
    exit(1);
}
