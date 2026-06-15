<?php
/**
 * Phase 26B — Deduplication identity contract.
 *
 * Executes the REAL ves_dedupe_items_by_identity() from includes/analysis.php and
 * pins its CURRENT behavior. The goal is a truthful safety net before any future
 * dedup improvement (URL canonicalization, cross-source dedup): when that work
 * lands, these snapshots will change visibly instead of silently.
 *
 * IMPORTANT: several cases document weak/undesired-but-current behavior and are
 * written as PASSING snapshots, clearly labelled. They are NOT fake-green
 * expectations of a future desired state, and the suite stays green.
 *
 * This file adds tests only. No dedup logic, scoring, or schema is changed.
 *
 * Run: php tests/test-dedup-identity-contract.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

// Minimal WordPress shims required only to load includes/analysis.php and run
// the pure dedup helper. We intentionally do NOT define ves_dedupe_items_by_identity,
// ves_first_text_path, or ves_search_normalize_text — those are guarded by
// function_exists() in analysis.php and must be the REAL implementations.
if (!function_exists('current_user_can')) { function current_user_can($c) { return false; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('remove_accents')) { function remove_accents($v) { return $v; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 123; } }
if (!function_exists('wp_rand')) { function wp_rand($min = 0, $max = 0) { return 424242; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0, $depth = 512) { return json_encode($v, $flags); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['ves_test_transients'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['ves_test_transients'][$k] ?? false; } }

require_once dirname(__DIR__) . '/includes/analysis.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

if (!function_exists('ves_dedupe_items_by_identity')) {
    echo "  FAIL  ves_dedupe_items_by_identity not loaded\n";
    echo "\nDedup identity contract: 0 passed, 1 failed\n";
    exit(1);
}

// ── 1. Exact same URL collapses ──────────────────────────────────────────────
$same = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a'],
    ['url' => 'https://x.test/a'],
]);
$ok(count($same) === 1, '1: two items with identical url collapse to one');

// ── 2. Same id collapses ─────────────────────────────────────────────────────
$same_id = ves_dedupe_items_by_identity([
    ['id' => 'abc', 'title' => 'one'],
    ['id' => 'abc', 'title' => 'two'],
]);
$ok(count($same_id) === 1, '2: two items with identical id collapse to one (first kept)');
$ok(($same_id[0]['title'] ?? '') === 'one', '2: dedup keeps the first occurrence');

// ── 3. URL variants now collapse via Phase 27B canonicalization ──────────────
// Phase 27B intentionally added minimal URL canonicalization for dedup identity:
// http/https, trailing slash, fragment, and common tracking params (utm_*,
// fbclid, gclid) all resolve to the same identity. (Previously these were
// treated as distinct — that snapshot is updated here on purpose.)
$variants = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a'],
    ['url' => 'https://x.test/a?utm_source=ig'],
    ['url' => 'http://x.test/a'],
    ['url' => 'https://x.test/a/'],
    ['url' => 'https://x.test/a#section'],
    ['url' => 'https://x.test/a?fbclid=123&gclid=456'],
]);
$ok(count($variants) === 1, '3: http/https, trailing slash, fragment and tracking-param variants now collapse to one identity');

// 3a. Non-tracking query params are preserved (NOT over-merged).
$kept_params = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a?id=1'],
    ['url' => 'https://x.test/a?id=2'],
]);
$ok(count($kept_params) === 2, '3a: distinct non-tracking query params remain distinct (conservative canonicalization)');

// 3b. A tracking param mixed with a real param keeps the real param identity.
$mixed_params = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a?id=1&utm_source=ig'],
    ['url' => 'https://x.test/a?id=1'],
]);
$ok(count($mixed_params) === 1, '3b: stripping a tracking param leaves a real param intact and collapses the pair');

// 3c. Different host or path still stays distinct.
$distinct = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a'],
    ['url' => 'https://x.test/b'],
    ['url' => 'https://y.test/a'],
]);
$ok(count($distinct) === 3, '3c: different host/path are still distinct identities');

// ── 3d. Port handling (Phase 27B correction) ────────────────────────────────
// Non-default ports identify a genuinely distinct endpoint and must be preserved;
// only the HTTP/HTTPS default ports (80/443) are dropped.
$port_distinct = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test:8080/a'],
    ['url' => 'https://x.test/a'],
]);
$ok(count($port_distinct) === 2, '3d.A: non-default port (:8080) stays distinct from the portless host');

$https_default = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test:443/a'],
    ['url' => 'https://x.test/a'],
]);
$ok(count($https_default) === 1, '3d.B: default https port (:443) collapses with the portless host');

$http_default = ves_dedupe_items_by_identity([
    ['url' => 'http://x.test:80/a'],
    ['url' => 'http://x.test/a'],
]);
$ok(count($http_default) === 1, '3d.C: default http port (:80) collapses with the portless host');

$port_with_tracking = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test:8080/a?utm_source=ig'],
    ['url' => 'https://x.test:8080/a'],
]);
$ok(count($port_with_tracking) === 1, '3d.D: non-default port is preserved while a tracking param is stripped (pair collapses)');

$different_ports = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test:8080/a'],
    ['url' => 'https://x.test:3000/a'],
]);
$ok(count($different_ports) === 2, '3d.E: two different non-default ports stay distinct');

// ── 4. Same story across different sources stays separate (no cross-source) ──
// Current behavior snapshot — not necessarily desired behavior.
// Dedup is identity-by-url/id only; the same story on two platforms with
// different urls is NOT merged. Pinned for the future cross-source dedup phase.
$cross = ves_dedupe_items_by_identity([
    ['source_key' => 'tiktok', 'url' => 'https://tiktok.com/v/1', 'title' => 'Vegan protein launch'],
    ['source_key' => 'reddit', 'url' => 'https://reddit.com/r/x/2', 'title' => 'Vegan protein launch'],
]);
$ok(count($cross) === 2, '4: [current] identical story across two sources is NOT cross-source deduped');

// ── 5. Items with no identity fall back to a full-item hash ──────────────────
$no_identity = ves_dedupe_items_by_identity([
    ['title' => 'alpha', 'note' => 'x'],
    ['title' => 'beta', 'note' => 'y'],
    ['title' => 'alpha', 'note' => 'x'], // exact duplicate body => same md5 => collapses
]);
$ok(count($no_identity) === 2, '5: identity-less items dedupe by full-item hash (exact duplicate body collapses)');

// ── 6. Mixed batch end-to-end count (regression anchor) ──────────────────────
$mixed = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a'],
    ['url' => 'https://x.test/a'],
    ['url' => 'https://x.test/a?utm=1'],
    ['id' => 'abc'],
    ['title' => 'no identity'],
]);
$ok(count($mixed) === 4, '6: representative mixed batch (5 in) dedupes to 4 out under current rules');

// ── 7. Non-array entries are skipped safely ──────────────────────────────────
$dirty = ves_dedupe_items_by_identity([
    ['url' => 'https://x.test/a'],
    'not-an-array',
    null,
    ['url' => 'https://x.test/b'],
]);
$ok(count($dirty) === 2, '7: non-array entries are skipped without error');

echo "\nDedup identity contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
