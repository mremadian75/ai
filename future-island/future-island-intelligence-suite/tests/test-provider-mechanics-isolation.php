<?php
/**
 * Static / functional check — v0.9.30.21
 *
 * Proves that normal (non-admin, non-debug) frontend AJAX responses do not
 * carry provider mechanics/cost labels and no-result marker rows stay non-evidence. Run from the plugin root:
 *
 *     php tests/test-provider-mechanics-isolation.php
 *
 * Exit code 0 = all checks passed, 1 = a leak/regression was detected.
 *
 * This test does NOT need a WordPress runtime. It:
 *   1. Loads the real VES_Ajax_Controller class with minimal WP stubs.
 *   2. Invokes the real private strip_admin_diagnostics() via reflection and
 *      asserts every provider-mechanic key is removed for normal users.
 *   3. Statically inspects maybe_return_duplicate_source_execution() to confirm
 *      provider keys only appear inside the admin/debug guard.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$plugin_root = dirname(__DIR__);
$controller  = $plugin_root . '/includes/class-ves-ajax-controller.php';
$analysis    = $plugin_root . '/includes/analysis.php';
$query_plan  = $plugin_root . '/includes/class-ves-query-planner.php';

if (!is_file($controller)) {
    fwrite(STDERR, "FAIL: controller file not found\n");
    exit(1);
}

/* ---- minimal WP stubs needed only to load the class + run the pure method ---- */
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('current_user_can')) {
    // Normal user: never an admin in this test harness.
    function current_user_can($cap) { return false; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; }
}
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('remove_accents')) { function remove_accents($v) { return $v; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($v) { return (string) $v; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url($url, $component); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
}
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 123; } }
if (!function_exists('wp_rand')) { function wp_rand($min = 0, $max = 0) { return 424242; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0, $depth = 512) { return json_encode($v, $flags); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { $GLOBALS['ves_test_transients'][$key] = $value; return true; }
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return $GLOBALS['ves_test_transients'][$key] ?? false; }
}

require_once $query_plan;
require_once $analysis;
require_once $controller;

$failures = [];
$passes   = [];

/* ---- the provider-mechanic keys that must never reach a normal user ---- */
$forbidden_keys = [
    'actor_slug',
    'provider_run_id',
    'source_execution_key',
    'provider_actor_slug',
    'provider_dataset_id',
    'dataset_id',
    'run_body',
    'total_charge_usd',
    'provider_cost_usd',
    'provider_cost',
    'actual_provider_cost_usd',
];

/* ============================================================= *
 * Check 1 — functional: strip_admin_diagnostics() removes keys   *
 * ============================================================= */
try {
    $ref    = new ReflectionMethod('VES_Ajax_Controller', 'strip_admin_diagnostics');
    $ref->setAccessible(true);

    // A payload shaped like a real run response, salted with every provider key
    // both at the top level and nested inside a child array.
    $payload = [
        'status'             => 'SUCCEEDED',
        'status_message'     => 'Listo',
        'item_count_preview' => 12,
        'run_id'             => 'abc123',            // allowed: poller needs it
        'actor_slug'         => 'apidojo/tiktok',
        'provider_run_id'    => 'RUN_SECRET',
        'source_execution_key' => 'ves_src_deadbeef',
        'provider_actor_slug'  => 'apidojo/tiktok',
        'provider_dataset_id'  => 'DS_SECRET',
        'dataset_id'           => 'DS_SECRET_2',
        'run_body'             => ['data' => ['id' => 'RUN_SECRET']],
        'total_charge_usd'     => 0.0421,
        'provider_cost_usd'    => 0.0421,
        'provider_cost'        => ['estimated_usd' => 0.0421, 'warning' => true, 'source_count' => 3],
        'actual_provider_cost_usd' => 0.0421,
        'nested'             => [
            'actor_slug'      => 'apidojo/tiktok',
            'provider_run_id' => 'RUN_SECRET',
            'provider_cost'   => ['estimated_usd' => 0.99],
            'safe_field'      => 'ok',
        ],
    ];

    $clean = $ref->invoke(null, $payload);

    foreach ($forbidden_keys as $k) {
        if (array_key_exists($k, $clean)) {
            $failures[] = "strip_admin_diagnostics left top-level provider key '$k'";
        }
    }
    if (isset($clean['nested']) && is_array($clean['nested'])) {
        foreach (['actor_slug', 'provider_run_id', 'provider_cost'] as $k) {
            if (array_key_exists($k, $clean['nested'])) {
                $failures[] = "strip_admin_diagnostics left nested provider key '$k'";
            }
        }
    }
    // Product-safe fields and the poller's run_id must survive.
    foreach (['status', 'status_message', 'item_count_preview', 'run_id'] as $k) {
        if (!array_key_exists($k, $clean)) {
            $failures[] = "strip_admin_diagnostics wrongly removed product-safe key '$k'";
        }
    }
    if (empty($failures)) {
        $passes[] = 'strip_admin_diagnostics() removes provider mechanics and provider USD cost (top-level + nested) while keeping product-safe fields incl. run_id';
    }
} catch (Throwable $e) {
    $failures[] = 'reflection check failed: ' . $e->getMessage();
}


/* ============================================================= *
 * Check 1b — functional: public_payload() tokenizes run_id       *
 * ============================================================= */
try {
    $pub = new ReflectionMethod('VES_Ajax_Controller', 'public_payload');
    $pub->setAccessible(true);
    $raw_run = 'RAWPROVIDERRUN123';
    $public = $pub->invoke(null, [
        'platform' => 'tiktok',
        'status' => 'RUNNING',
        'run_id' => $raw_run,
        'provider_run_id' => $raw_run,
        'dataset_id' => 'DATASET_SECRET',
        'source_execution_key' => 'ves_src_secret',
        'provider_cost' => ['estimated_usd' => 1.23, 'warning' => true, 'source_count' => 4],
        'provider_cost_usd' => 1.23,
    ]);
    if (($public['run_id'] ?? '') === $raw_run) {
        $failures[] = 'public_payload left raw provider run_id in normal response';
    }
    if (!preg_match('/^vesrun[a-f0-9]{32}$/i', (string) ($public['run_id'] ?? ''))) {
        $failures[] = 'public_payload did not replace run_id with public run token';
    }
    foreach (['provider_run_id', 'dataset_id', 'source_execution_key', 'provider_cost', 'provider_cost_usd'] as $k) {
        if (array_key_exists($k, $public)) {
            $failures[] = "public_payload leaked provider key '$k'";
        }
    }
    if (empty($public['resource_notice']['warning'])) {
        $failures[] = 'public_payload did not keep a product-safe resource_notice for normal users';
    }
    if (empty(array_filter($failures, function ($f) { return strpos($f, 'public_payload') !== false; }))) {
        $passes[] = 'public_payload() replaces raw provider run ids with public tokens and strips provider cost mechanics while keeping resource_notice';
    }
} catch (Throwable $e) {
    $failures[] = 'public_payload reflection check failed: ' . $e->getMessage();
}

/* ============================================================= *
 * Check 2 — static: duplicate-prevention response is gated       *
 * ============================================================= */
$src = file_get_contents($controller);

if (preg_match('/function maybe_return_duplicate_source_execution.*?\n    \}/s', $src, $m)) {
    $method = $m[0];

    // Isolate the admin/debug branch so we can tell guarded vs. unguarded code.
    $debug_branch = '';
    if (preg_match('/if \(self::request_debug_enabled\(\)\) \{(.*?)\n        \}/s', $method, $dm)) {
        $debug_branch = $dm[1];
    }
    $unguarded = str_replace($debug_branch, '', $method);

    foreach (['source_execution_key', 'provider_run_id', 'actor_slug'] as $k) {
        // The key may legitimately appear as a diagnostic argument or inside the
        // debug branch; it must NOT appear as an array key in the unguarded
        // response array. We check for the response-array assignment form.
        if (preg_match("/'" . preg_quote($k, '/') . "'\s*=>/", $unguarded)
            && strpos($unguarded, "VES_Admin::record_diagnostic") !== false) {
            // Allowed inside record_diagnostic(...) context; verify it is not in
            // the $public_response array literal.
        }
    }

    if (strpos($method, '$public_response') === false) {
        $failures[] = 'maybe_return_duplicate_source_execution no longer builds a $public_response array';
    } else {
        // Extract the $public_response = [ ... ]; literal.
        if (preg_match('/\$public_response = \[(.*?)\n        \];/s', $method, $pm)) {
            $literal = $pm[1];
            foreach (['source_execution_key', 'provider_run_id', 'actor_slug', "'run'"] as $k) {
                $needle = (strpos($k, "'") === 0) ? ($k . ' =>') : ("'" . $k . "' =>");
                if (strpos($literal, $needle) !== false) {
                    $failures[] = "maybe_return_duplicate_source_execution \$public_response still contains provider key $k";
                }
            }
            if (strpos($literal, "'run_id' =>") === false) {
                $failures[] = 'maybe_return_duplicate_source_execution dropped run_id (poller needs it)';
            }
        } else {
            $failures[] = 'could not parse $public_response literal';
        }
        if (strpos($method, 'request_debug_enabled()') === false) {
            $failures[] = 'maybe_return_duplicate_source_execution has no admin/debug guard';
        }
        if (empty(array_filter($failures, function ($f) {
            return strpos($f, 'maybe_return_duplicate_source_execution') !== false;
        }))) {
            $passes[] = 'maybe_return_duplicate_source_execution() sends only product-safe fields; provider detail is behind the request_debug_enabled() admin guard';
        }
    }
} else {
    $failures[] = 'could not locate maybe_return_duplicate_source_execution method';
}

/* ============================================================= *
 * Check 3 — static: blocked list completeness                    *
 * ============================================================= */
if (preg_match('/\$blocked = \[(.*?)\n        \];/s', $src, $bm)) {
    $blocked_literal = $bm[1];
    foreach ($forbidden_keys as $k) {
        if (strpos($blocked_literal, "'" . $k . "'") === false) {
            $failures[] = "strip_admin_diagnostics \$blocked list is missing '$k'";
        }
    }
    if (empty(array_filter($failures, function ($f) {
        return strpos($f, '$blocked') !== false;
    }))) {
        $passes[] = 'strip_admin_diagnostics() $blocked list explicitly contains all provider-mechanic keys';
    }
} else {
    $failures[] = 'could not locate $blocked list';
}

/* ============================================================= *
 * Check 4 — static: success() routes through public_payload()    *
 * ============================================================= */
if (preg_match('/function success\([^)]*\)\s*\{(.*?)\n    \}/s', $src, $sm)) {
    if (strpos($sm[1], 'public_payload(') !== false) {
        $passes[] = 'success() routes every payload through public_payload() -> strip_admin_diagnostics()';
    } else {
        $failures[] = 'success() no longer routes through public_payload()';
    }
}



/* ============================================================= *
 * Check 5 — static: frontend bundle does not expose actor slugs  *
 * ============================================================= */
$frontend = $plugin_root . '/assets/js/ves-frontend.js';
if (!is_file($frontend)) {
    $failures[] = 'frontend bundle not found';
} else {
    $js = file_get_contents($frontend);
    $known_actor_slugs = [
        'apidojo/tiktok-scraper',
        'streamers/youtube-scraper',
        'apify/facebook-posts-scraper',
        'apify/instagram-scraper',
        'apidojo/tweet-scraper',
        'trudax/reddit-scraper-lite',
        'epctex/pinterest-scraper',
        'harvestapi/linkedin-profile-search',
        'curious_coder/facebook-ads-library-scraper',
        'dz_omar/google-ads-scraper',
        'radeance/moz-scraper',
        'pro100chok/ahrefs-seo-tools',
        'marceli/semrush-keyworlds-scraper',
        'burbn/semrush-keyword-magic-tool',
        'radeance/semrush-scraper',
    ];
    foreach ($known_actor_slugs as $slug) {
        if (strpos($js, $slug) !== false) {
            $failures[] = "frontend bundle exposes actor slug '$slug'";
        }
    }
    foreach (['coste Apify', 'Aviso de coste proveedor externo', 'Apify'] as $label) {
        if (stripos($js, $label) !== false) {
            $failures[] = "frontend bundle still contains provider-specific user label '$label'";
        }
    }
    if (preg_match("/\['Actor'\s*,/", $js) || preg_match('/<strong>Actor:/', $js)) {
        $failures[] = 'frontend preflight/UI still renders an Actor label for normal users';
    }
    foreach (['Social video collector', 'Ads transparency collector', 'SEO signal collector', 'Trend signal collector', 'Review signal collector', 'Source route', 'Reliability / access note'] as $safe_label) {
        if (strpos($js, $safe_label) === false) {
            $failures[] = "frontend missing product-safe label '$safe_label'";
        }
    }
    if (strpos($js, 'Admin provider cost notice') === false || strpos($js, 'This run uses multiple external sources and may consume more credits') === false) {
        $failures[] = 'frontend does not contain both admin-only provider-cost copy and normal resource/credit copy';
    }
    if (preg_match('/estimated provider cost[^\n]+setStatus/i', $js) && strpos($js, 'isDebugMode(widget) || isAdminUI()') === false) {
        $failures[] = 'provider USD cost message is not clearly guarded by admin/debug logic';
    }
    if (empty(array_filter($failures, function ($f) {
        return strpos($f, 'frontend') !== false || strpos($f, 'provider-specific') !== false || strpos($f, 'provider USD') !== false;
    }))) {
        $passes[] = 'frontend bundle exposes product-safe preflight labels only; known actor slugs and Apify/USD normal-user labels are absent';
    }
}


/* ============================================================= *
 * Check 6 — functional: TikTok noResults markers are non-evidence*
 * ============================================================= */
try {
    $dataset = [
        ['noResults' => true, 'title' => 'No Results', 'query' => 'marketing con IA'],
        ['no_results' => 'true', 'label' => 'No Results'],
    ];
    if (!function_exists('ves_tiktok_item_is_no_results_marker')) {
        $failures[] = 'ves_tiktok_item_is_no_results_marker() is missing';
    } else {
        foreach ($dataset as $row) {
            if (!ves_tiktok_item_is_no_results_marker($row)) {
                $failures[] = 'TikTok noResults row was not classified as no_results_marker';
            }
        }
        $split = ves_split_no_result_markers($dataset);
        if (count($split['items']) !== 0 || (int) $split['no_result_marker_count'] !== 2) {
            $failures[] = 'noResults-only dataset did not produce 0 usable signals and 2 marker rows';
        }
        foreach ($split['markers'] as $marker_row) {
            if (($marker_row['discard_reason'] ?? '') !== 'no_results_marker' || ($marker_row['evidence_quality'] ?? '') !== 'none' || ($marker_row['usable'] ?? true) !== false) {
                $failures[] = 'noResults marker row was not stamped as unusable/no evidence';
                break;
            }
        }
        $real_post = ['id' => '123', 'text' => 'marketing con IA', 'webVideoUrl' => 'https://www.tiktok.com/@u/video/123', 'playCount' => 10];
        if (ves_tiktok_item_is_no_results_marker($real_post)) {
            $failures[] = 'real TikTok post was incorrectly classified as no_results_marker';
        }
        if (empty(array_filter($failures, function ($f) { return strpos($f, 'noResults') !== false || strpos($f, 'no_results_marker') !== false || strpos($f, 'TikTok noResults') !== false; }))) {
            $passes[] = 'TikTok noResults marker rows are classified as unusable, evidence_quality=none, and split out before normalization';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'noResults marker classification check failed: ' . $e->getMessage();
}

/* ============================================================= *
 * Check 7 — functional/static: TikTok keyword expansion is narrow*
 * ============================================================= */
try {
    $normalized = VES_Query_Planner::normalize_request_fields('social', 'tiktok', [
        'queryTerms' => "marketing con IA\nmarketing inteligencia artificial\nmarketing ia\nmarketing\ntiktokmarketing\ndigitalmarketing\nmarketingtips\ncontentmarketing\nsocialmediamarketing\nmarketingstrategy",
        'hashtags' => "#marketing #digitalmarketing #marketingtips",
        'limit' => 50,
        'language' => 'es',
        'country' => 'ES',
    ]);
    $payload = VES_Query_Planner::build_actor_payload('social', 'tiktok', 'clockworks/tiktok-scraper', $normalized);
    $search_queries = $payload['searchQueries'] ?? [];
    $hashtags = $payload['hashtags'] ?? [];
    if (count($search_queries) > 5) {
        $failures[] = 'TikTok searchQueries exceeds 5 conservative terms';
    }
    if (in_array('#marketing', $search_queries, true) || in_array('digitalmarketing', $search_queries, true)) {
        $failures[] = 'TikTok searchQueries still mixes generic hashtag terms into keyword search';
    }
    if (empty($hashtags)) {
        $failures[] = 'TikTok hashtags were not kept in the separate hashtags field';
    }
    $platform_input = file_get_contents($plugin_root . '/includes/platform-input.php');
    foreach (["array_slice(\$keywords, 0, 20)", "ves_contextual_search_phrases(\$clean_query, \$src, 8)"] as $bad) {
        if (strpos($platform_input, $bad) !== false) {
            $failures[] = "TikTok input builder still contains excessive expansion pattern: $bad";
        }
    }
    if (empty(array_filter($failures, function ($f) { return strpos($f, 'TikTok searchQueries') !== false || strpos($f, 'TikTok input builder') !== false || strpos($f, 'hashtags') !== false; }))) {
        $passes[] = 'TikTok input planning keeps keyword search to a small conservative set and keeps hashtags separate';
    }
} catch (Throwable $e) {
    $failures[] = 'TikTok conservative keyword check failed: ' . $e->getMessage();
}

/* ============================================================= *
 * Check 8 — static: marker-only runs block confident generation  *
 * ============================================================= */
if (strpos($src, "analysis_blocked_reason'] = 'no_results_marker'") === false
    || strpos($src, "generation_blocked'] = true") === false
    || strpos($src, "can_generate_brief'] = false") === false) {
    $failures[] = 'noResults-only backend response does not clearly block AI insight/brief generation';
} else {
    $passes[] = 'noResults-only backend response marks generation_blocked and can_generate_brief=false';
}

/* ============================================================= *
 * Check 9 — static: normal UI has no Provider Cost label         *
 * ============================================================= */
if (is_file($frontend)) {
    $js = file_get_contents($frontend);
    if (strpos($js, 'Provider Cost') !== false || strpos($js, 'Provider cost unavailable') !== false) {
        $failures[] = 'normal frontend still contains old Provider Cost / Provider cost unavailable wording';
    }
    if (strpos($js, "isDebugMode(widget) ? 'Provider cost' : 'Uso'") === false) {
        $failures[] = 'provider cost KPI label is not debug-only';
    }
    if (strpos($js, 'No hay señales utilizables.') === false || strpos($js, 'La fuente no devolvió resultados utilizables para esta consulta.') === false) {
        $failures[] = 'frontend missing clear Spanish no usable social signals / empty marker copy';
    }
    if (empty(array_filter($failures, function ($f) { return strpos($f, 'frontend') !== false || strpos($f, 'Provider Cost') !== false || strpos($f, 'provider cost KPI') !== false; }))) {
        $passes[] = 'normal frontend uses product-safe usage/credit wording and clear Spanish no-usable-signal empty state; provider cost label remains debug-only';
    }
}


/* ============================================================= *
 * Check 10 — functional: public errors are product-safe          *
 * ============================================================= */
try {
    $pem = new ReflectionMethod('VES_Ajax_Controller', 'public_error_message');
    $pem->setAccessible(true);
    $msg = $pem->invoke(null, 'Provider access failed. HTTP logico 500 request_id: 1407d916-edf2-47a0-9ec1-e8aa12dad7c1 actor_slug apidojo/tiktok-scraper dataset_id DS', 500, [
        'request_id' => '1407d916-edf2-47a0-9ec1-e8aa12dad7c1',
        'actor_slug' => 'apidojo/tiktok-scraper',
        'dataset_id' => 'DS',
    ]);
    if (stripos($msg, 'Provider') !== false || stripos($msg, 'Apify') !== false || stripos($msg, 'actor') !== false || stripos($msg, 'HTTP') !== false || stripos($msg, 'request_id') !== false || stripos($msg, '1407d916') !== false) {
        $failures[] = 'public_error_message() exposes provider/internal mechanics: ' . $msg;
    } elseif (stripos($msg, 'source') === false || stripos($msg, 'Diagnostics') === false) {
        $failures[] = 'public_error_message() did not return the expected source/Diagnostics safe wording';
    } else {
        $passes[] = 'public_error_message() maps provider/internal failures to product-safe source access wording';
    }
} catch (Throwable $e) {
    $failures[] = 'public_error_message reflection check failed: ' . $e->getMessage();
}

/* ============================================================= *
 * Check 11 — static: postAjax message stays clean                *
 * ============================================================= */
if (is_file($frontend)) {
    $js = file_get_contents($frontend);
    if (preg_match('/if \(json\.success === false\) \{(.*?)\n        \}\n        return json\.data;/s', $js, $pm)) {
        $block = $pm[1];
        foreach (['statusHint', 'const ref', '` [Ref:', '` (HTTP logico', '+ statusHint', '+ ref'] as $bad) {
            if (strpos($block, $bad) !== false) {
                $failures[] = "postAjax still appends technical detail to Error.message via '$bad'";
            }
        }
        if (strpos($block, 'new Error(json.data?.message') === false) {
            $failures[] = 'postAjax does not build Error.message directly from json.data.message only';
        }
        if (strpos($block, 'err.adminDetail') === false || strpos($block, 'request_id') === false || strpos($block, 'status') === false) {
            $failures[] = 'postAjax no longer preserves request_id/status in adminDetail';
        }
        if (empty(array_filter($failures, function ($f) { return strpos($f, 'postAjax') !== false; }))) {
            $passes[] = 'postAjax keeps public Error.message clean and stores request_id/status only in adminDetail';
        }
    } else {
        $failures[] = 'could not locate postAjax json.success === false block';
    }
}

/* ============================================================= *
 * Check 12 — static: catch handlers sanitize normal error banners*
 * ============================================================= */
if (is_file($frontend)) {
    $js = file_get_contents($frontend);
    $unsafe_patterns = [
        "setStatus(widget, '❌ ' + escapeHtml(err.message",
        "setStatus(widget, '❌ Error al pintar resultados: ' + escapeHtml(renderErr.message",
        "renderAnalysisPanel(err.message ||",
        "escapeHtml(retryErr.message ||",
    ];
    foreach ($unsafe_patterns as $bad) {
        if (strpos($js, $bad) !== false) {
            $failures[] = "normal catch handler still bypasses safeUserMessage(): $bad";
        }
    }
    foreach (['HTTP logico 500', 'Provider access failed', '[Ref: 1407d916-edf2-47a0-9ec1-e8aa12dad7c1]', 'sourceAccessMessage'] as $needle) {
        if ($needle === 'sourceAccessMessage') {
            if (strpos($js, $needle) === false) $failures[] = 'safeUserMessage() missing sourceAccessMessage mapping';
        } elseif (strpos($js, $needle) !== false) {
            $failures[] = "frontend contains forbidden normal-user error text: $needle";
        }
    }
    // v0.9.30.21: raw JSON blob replaced by renderAdminDetail() structured helper;
    // technical_message still stored in adminFields; renderAdminDetail called for admins.
    if (strpos($js, 'renderAdminDetail') === false || strpos($js, 'technical_message') === false) {
        $failures[] = 'admin/debug diagnostics no longer expose technical_message via renderAdminDetail';
    }
    if (empty(array_filter($failures, function ($f) { return strpos($f, 'safeUserMessage') !== false || strpos($f, 'catch handler') !== false || strpos($f, 'frontend contains forbidden') !== false || strpos($f, 'admin/debug diagnostics') !== false; }))) {
        $passes[] = 'normal run/Trend/Brand/Google catch handlers pass errors through safeUserMessage(); renderAdminDetail exposes structured diagnostics for admins';
    }
}

/* ---------------------------- report ---------------------------- */
echo "=== Provider Mechanics Isolation — v0.9.30.21 static check ===\n\n";
foreach ($passes as $p)   { echo "  PASS  $p\n"; }
foreach ($failures as $f) { echo "  FAIL  $f\n"; }
echo "\n";

if ($failures) {
    echo count($failures) . " check(s) FAILED.\n";
    exit(1);
}
echo count($passes) . " check(s) passed. Normal-user responses are free of provider mechanics and noResults markers stay non-evidence.\n";
exit(0);
