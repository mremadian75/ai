<?php
/**
 * v0.9.30.21 — Creative Intelligence humanizeError must map every WP_Error code
 * that FICI_Error_Mapper can produce to a user-safe Spanish/English message.
 * Also checks: renderError accepts error param, isAdmin exposed in FICI_FRONTEND,
 * and output language directive wired into FICI_OpenAI_Provider.
 */

$root    = dirname(__DIR__);
$fici_js = file_get_contents($root . '/modules/creative-intelligence/assets/js/fici-frontend.js');
$fici_php_provider = file_get_contents($root . '/modules/creative-intelligence/includes/ai/class-fici-openai-provider.php');
$fici_php_plugin   = file_get_contents($root . '/modules/creative-intelligence/includes/class-fici-plugin.php');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// --- humanizeError coverage ---
$required_codes = [
    'fici_missing_api_key',
    'fici_invalid_api_key',
    'fici_auth_error',
    'fici_rate_limited',
    'fici_rate_limited_upstream',
    'fici_file_too_large',
    'fici_invalid_file_type',
    'fici_video_frames_missing',
    'fici_schema_mismatch',
    'fici_empty_output',
    'fici_request_timeout',
    'fici_invalid_response',
    'fici_upstream_unavailable',
    'fici_upstream_error',
    'fici_missing_input',
    'fici_run_not_completed',
    'fici_permission_denied',
    'rest_cookie_invalid_nonce',
];

foreach ($required_codes as $code) {
    $add("humanizeError maps '$code'", strpos($fici_js, $code) !== false);
}

// --- renderError accepts error parameter ---
$render_error_pos = strpos($fici_js, 'function renderError(');
if ($render_error_pos === false) {
    $render_error_pos = strpos($fici_js, 'renderError =');
}
$render_error_sig = ($render_error_pos !== false) ? substr($fici_js, $render_error_pos, 80) : '';
$add('renderError accepts error parameter', substr_count($render_error_sig, ',') >= 2 || strpos($render_error_sig, 'error') !== false);

// --- renderError builds admin details block for isAdmin ---
$render_error_body = ($render_error_pos !== false) ? substr($fici_js, $render_error_pos, 1200) : '';
$add('renderError shows admin diagnostics when isAdmin', strpos($render_error_body, 'isAdmin') !== false || strpos($render_error_body, 'FICI_FRONTEND.isAdmin') !== false);
$add('renderError uses <details> for admin info', strpos($render_error_body, '<details') !== false);

// --- main submit error call passes error object to renderError ---
// Pattern: renderError(resultBox, humanizeError(error), error)
$add('renderError called with error object at main submit site', strpos($fici_js, 'renderError(resultBox, humanizeError(error), error)') !== false || (strpos($fici_js, 'renderError(') !== false && strpos($fici_js, 'humanizeError(error)') !== false));

// --- isAdmin exposed in FICI_FRONTEND PHP localization ---
$add("isAdmin in FICI_FRONTEND localization", strpos($fici_php_plugin, "'isAdmin'") !== false && strpos($fici_php_plugin, 'current_user_can') !== false);

// --- outputLanguage exposed in FICI_FRONTEND PHP localization ---
$add("outputLanguage in FICI_FRONTEND localization", strpos($fici_php_plugin, "'outputLanguage'") !== false);

// --- FICI_OpenAI_Provider threads output language into instructions ---
$add('fici_output_language filter applied in FICI_OpenAI_Provider', strpos($fici_php_provider, 'fici_output_language') !== false);
$add('language_directive method exists', strpos($fici_php_provider, 'language_directive') !== false);
$add('instructions() uses language_directive', strpos($fici_php_provider, 'lang_directive') !== false || strpos($fici_php_provider, 'language_directive') !== false);
$add('build_user_text includes Output language field', strpos($fici_php_provider, 'Output language') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.30.21 fici-humanize-error check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.21 fici-humanize-error checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
