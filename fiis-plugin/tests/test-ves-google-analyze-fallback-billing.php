<?php
/**
 * Deep-debug regression: google_analyze() must treat a local OpenAI fallback like
 * analyze_item() does — never charge for it and never write the degraded result
 * back into trusted memory. A local fallback is not a provider completion.
 *
 * This is a source-level audit (the AJAX entrypoint needs a full WP runtime), so we
 * assert the structural guard around the google_analyze() body.
 *
 * Run: php tests/test-ves-google-analyze-fallback-billing.php
 */
$root = dirname(__DIR__);
$src  = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// Isolate the google_analyze() method body.
$start = strpos($src, 'public static function google_analyze()');
$ok($start !== false, 'google_analyze() method exists');
// google_analyze() is the final method before the class close; take to end of file.
$body = $start !== false ? substr($src, $start) : '';

// 1) On fallback it must VOID, not post.
$ok(strpos($body, "void_standard_usage(\$usage_key, 'OpenAI fallback local") !== false,
    'google_analyze voids the reservation on a local fallback (no charge)');

// 2) The post (charge) must be in the else branch — gated by empty(fallback).
$fallback_guard = strpos($body, "if (!empty(\$analysis['fallback'])) {");
$post_pos = strpos($body, "post_standard_usage_or_error(\$usage_key, \$request_id, 'google_usage', 'Google Intelligence AI confirmado')");
$ok($fallback_guard !== false && $post_pos !== false && $post_pos > $fallback_guard,
    'google_analyze only posts/charges when the analysis is NOT a fallback');

// 3) Memory writeback must be gated on empty(fallback) so a degraded result never
//    enters trusted memory.
$ok(strpos($body, "if (empty(\$analysis['fallback']) && class_exists('VES_AI_Context_Builder'))") !== false,
    'google_analyze skips context-builder writeback on fallback');
$ok(strpos($body, "if (empty(\$analysis['fallback']) && class_exists('VES_Temp_Memory'))") !== false,
    'google_analyze skips temp-memory save on fallback');

// 4) Parity guard: analyze_item() still has the same fallback void (no regression there).
$ok(substr_count($src, "void_standard_usage(\$usage_key, 'OpenAI fallback local; no provider completion.')") >= 3,
    'all three AI-analysis paths void on fallback (analyze_item/analyze_items/google_analyze)');

echo "\nVES google_analyze fallback-billing tests: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
