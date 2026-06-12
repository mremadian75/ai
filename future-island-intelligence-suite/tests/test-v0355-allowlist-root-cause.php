<?php
/**
 * v0.3.55 — allowlist root-cause regression tests.
 *
 * Live failure being locked down: runs died at dispatch with
 * "This data source actor is not allowlisted on the server" (FI-25xxxx /
 * FI-54xxxx) because (a) the allowlist enumeration missed platform keys the
 * dispatch paths actually resolve, (b) shipped-default actors were absent from
 * the registry, and (c) the resulting error was classified as a transient
 * provider_transport_error offering retry/reduced-search that can never work.
 *
 * These tests execute the REAL registry + config code (not grep) for (a)/(b)
 * and assert the classification wiring for (c).
 */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }

// Load the real VES_Config BEFORE the shims so the registry integrates with the
// real all_actor_slugs() implementation (the shims only define a stub fallback).
require_once __DIR__ . '/../includes/class-ves-config.php';
// Settings come back empty -> the shipped defaults under test are what resolve.
if (!class_exists('VES_Admin')) {
    final class VES_Admin {
        public static $diagnostics = [];
        public static function get_settings() { return []; }
        public static function record_diagnostic($source, $message, $context = []) { self::$diagnostics[] = [$source, $message, $context]; }
    }
}
require __DIR__ . '/bootstrap-wp-shims.php';
require_once __DIR__ . '/../includes/class-ves-apify-actor-registry.php';
require_once __DIR__ . '/../modules/deep-trend-finder/includes/class-fidtf-settings.php';

$checks = 0;
$failures = [];
function ok($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; fwrite(STDERR, "FAIL: {$message}\n"); }
}

// ── (a) every platform key the dispatch paths can resolve is enumerated ──────
$keys = VES_Config::all_actor_platform_keys();
foreach (['tiktok_trending_videos', 'tiktok_trending_creators', 'tiktok_trending_sounds', 'tiktok_hashtag_trends', 'reddit_trends', 'youtube_trending', 'tiktok_trends', 'twitter_trends', 'semrush_keyword_magic', 'semrush_domain'] as $key) {
    ok(in_array($key, $keys, true), "all_actor_platform_keys covers '{$key}'");
}
$slugs = VES_Config::all_actor_slugs();
ok(in_array('data_xplorer/tiktok-trends', $slugs, true), 'all_actor_slugs resolves tiktok_trending_videos default');
ok(in_array('easyapi/reddit-trends-scraper', $slugs, true), 'all_actor_slugs resolves reddit_trends default');
ok(in_array('lexis-solutions/tiktok-trending-hashtags', $slugs, true), 'all_actor_slugs resolves tiktok_hashtag_trends default');

// ── (b) shipped defaults pass the dispatch gate ──────────────────────────────
// Every actor a default install can resolve at dispatch time must be allowed;
// otherwise the product blocks its own configuration at runtime.
$shipped = [
    'core trend slot tiktok_trending_videos' => 'data_xplorer/tiktok-trends',
    'core trend slot tiktok_trending_creators' => 'lexis-solutions/tiktok-trending-creators',
    'core trend slot tiktok_trending_sounds' => 'alien_force/tiktok-trending-sounds',
    'core trend slot tiktok_hashtag_trends (legacy default)' => 'lexis-solutions/tiktok-trending-hashtags',
    'core trend slot reddit_trends' => 'easyapi/reddit-trends-scraper',
    'core tiktok_comments default' => 'scraptik/tiktok-comments-scraper-api',
    'core tiktok_backup default' => 'api-ninja/tiktok-data-scraper',
    'core semrush agencies mode' => 'saswave/semrush-agencies-partner-scraper',
    'core semrush keyword_simple mode' => 'marceli/semrush-keyworlds-scraper',
];
foreach ($shipped as $label => $slug) {
    ok(VES_Apify_Actor_Registry::is_allowed_slug($slug), "{$label} ({$slug}) is allowlisted");
}

// DTF module defaults must be dispatchable through the core gate.
$dtf = FIDTF_Settings::get()['actor_map'];
$dtf['tiktok_enrichment'] = FIDTF_Settings::get()['tiktok_enrichment_actor_id'];
foreach ($dtf as $source => $slug) {
    ok(VES_Apify_Actor_Registry::is_allowed_slug($slug), "DTF default {$source} actor ({$slug}) is allowlisted");
}

// Registry entries (not just allowlist coverage) exist for the previously
// missing shipped defaults so admins can see and manage them.
$registry = VES_Apify_Actor_Registry::registry();
foreach (['google_news_fast', 'tiktok_trending_videos', 'tiktok_trending_creators', 'tiktok_trending_sounds', 'reddit_trends'] as $key) {
    ok(isset($registry[$key]), "registry has admin-visible entry '{$key}'");
}

// An unknown actor must still be blocked — the gate is narrowed, not removed.
ok(!VES_Apify_Actor_Registry::is_allowed_slug('evil/unknown-actor'), 'unknown actor remains blocked');
ok(!VES_Apify_Actor_Registry::is_allowed_slug(''), 'empty slug remains blocked');

// ── preflight helper used by all dispatch paths ──────────────────────────────
$pf = VES_Apify_Actor_Registry::preflight_actor_slug('');
ok($pf['ok'] === false && $pf['reason'] === 'actor_not_configured', 'preflight: empty slug -> actor_not_configured');
$pf = VES_Apify_Actor_Registry::preflight_actor_slug('evil/unknown-actor');
ok($pf['ok'] === false && $pf['reason'] === 'actor_not_allowlisted', 'preflight: unknown slug -> actor_not_allowlisted');
$pf = VES_Apify_Actor_Registry::preflight_actor_slug('apify/instagram-scraper');
ok($pf['ok'] === true, 'preflight: known slug -> ok');

// ── (c) misclassification fix is wired in the AJAX surface ───────────────────
$ajax = file_get_contents(__DIR__ . '/../includes/class-ves-ajax-controller.php');
$allow_pos = strpos($ajax, "return 'provider_actor_not_allowlisted';");
$transport_fallback_pos = strpos($ajax, "? 'provider_http_error' : 'provider_transport_error';");
ok($allow_pos !== false, 'classify_error_category has a provider_actor_not_allowlisted branch');
ok($transport_fallback_pos !== false && $allow_pos < $transport_fallback_pos, 'allowlist classification runs BEFORE the transport-error fallthrough');
ok(strpos($ajax, "if (\$category === 'provider_actor_not_allowlisted') {") !== false, 'public message has a distinct allowlist branch');
ok(strpos($ajax, 'Reintentar no ayudará hasta entonces') !== false, 'allowlist message says retry will not help');
// Non-retryable taxonomy: extract the $no list and assert membership.
ok(preg_match('/static \$no\s+=\s+\[([^\]]+)\]/', $ajax, $m) === 1 && strpos($m[1], 'provider_actor_not_allowlisted') !== false, 'provider_actor_not_allowlisted is classified non-retryable');
// Reduced search eligibility must NOT include the allowlist category.
preg_match_all('/in_array\(\$category, \[([^\]]+)\], true\)\) \{ \$actions\[\] = \'reduced_scope\'/', $ajax, $mm);
foreach ((array) ($mm[1] ?? []) as $i => $list) {
    ok(strpos($list, 'provider_actor_not_allowlisted') === false, "reduced_scope eligibility list #{$i} excludes provider_actor_not_allowlisted");
}
ok(strpos($ajax, "'stage' => 'actor_allowlist_preflight'") !== false, 'AJAX start() preflights the actor before reserving credits');

// ── trend run executor classifies + preflights ───────────────────────────────
$exec = file_get_contents(__DIR__ . '/../includes/class-ves-run-execution-service.php');
ok(strpos($exec, "return 'failed_not_allowlisted';") !== false, 'trend executor classifies allowlist refusal distinctly');
ok(strpos($exec, "preflight_actor_slug") !== false, 'trend executor preflights actor before slot/dispatch');
ok(strpos($exec, "'status' => 'failed_not_allowlisted'") !== false, 'trend executor marks the source failed_not_allowlisted (non-retryable skip)');

// ── frontend wiring ──────────────────────────────────────────────────────────
$js = file_get_contents(__DIR__ . '/../assets/js/ves-frontend.js');
ok(strpos($js, "provider_actor_not_allowlisted") !== false, 'frontend knows the allowlist category');
ok(strpos($js, 'Fuente no habilitada en este servidor') !== false, 'frontend renders a non-busy label for allowlist failures');
$reduced_list = '';
if (preg_match("/const showReduced = actions\.includes\('reduced_scope'\) \|\| \[([^\]]+)\]/", $js, $jm)) { $reduced_list = $jm[1]; }
ok($reduced_list !== '' && strpos($reduced_list, 'provider_actor_not_allowlisted') === false, 'frontend reduced-search shortcut list excludes allowlist failures');

if (!empty($failures)) {
    fwrite(STDOUT, sprintf("v0.3.55 allowlist root-cause checks: %d passed, %d failed\n", $checks - count($failures), count($failures)));
    exit(1);
}
fwrite(STDOUT, "v0.3.55 allowlist root-cause checks passed: {$checks} / {$checks}\n");
exit(0);
