<?php
/**
 * v0.9.30.22 — "Something went wrong" root-cause trace.
 * safeUserMessage must route JS TypeError messages to an actionable page-refresh
 * message rather than the generic "Something went wrong" fallback.
 * postAjax must catch fetch() TypeErrors and produce a safe non-technical message.
 * ajaxStart must guard against a missing form dataset.ajax attribute.
 */

$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');
$php  = file_get_contents($root . '/includes/class-ves-ajax-controller.php');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// 1. safeUserMessage: "cannot read" triggers page-refresh branch, NOT generic fallback
$add('safeUserMessage maps "cannot read" to jsTypeError branch', strpos($js, 'cannot read') !== false && strpos($js, 'jsTypeError') !== false);

// 2. safeUserMessage: "properties of" is in the jsTypeError regex
$add('safeUserMessage jsTypeError regex includes "properties of"', strpos($js, 'properties of') !== false);

// 3. v0.9.31.6: safeUserMessage jsTypeError → render-safe message (not false F5 instruction)
$false_f5_msg = 'La página necesita refrescarse — presiona F5 e inténtalo de nuevo.';
$render_safe_msg = 'No pudimos mostrar el resultado en pantalla';
$add('safeUserMessage page-refresh message is safe (no false F5 for TypeError)', strpos($js, $false_f5_msg) === false && strpos($js, $render_safe_msg) !== false);

// 4. ajaxStart throws safe Spanish message on missing dataset.ajax
$ajax_start_pos = strpos($js, 'const ajaxStart = async');
$ajax_start_window = ($ajax_start_pos !== false) ? substr($js, $ajax_start_pos, 400) : '';
$add('ajaxStart throws safe message when form?.dataset?.ajax is missing', strpos($ajax_start_window, 'Configuración de formulario inválida') !== false);

// 5. postAjax wraps fetch() in try/catch for TypeErrors
$post_ajax_pos = strpos($js, 'const postAjax = async');
$post_ajax_window = ($post_ajax_pos !== false) ? substr($js, $post_ajax_pos, 500) : '';
$add('postAjax wraps fetch() in try/catch', strpos($post_ajax_window, 'catch (fetchErr)') !== false);
$add('postAjax TypeError branch returns safe message', strpos($post_ajax_window, 'fetchErr instanceof TypeError') !== false);

// 6. fatal() includes safe_diagnostic in payload (confirming PHP path is leak-free)
$fatal_pos = strpos($php, 'function fatal(');
if ($fatal_pos === false) $fatal_pos = strpos($php, 'private static function fatal');
$fatal_window = ($fatal_pos !== false) ? substr($php, $fatal_pos, 2500) : '';
$add('fatal() includes safe_diagnostic in payload', strpos($fatal_window, 'safe_diagnostic') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.30.22 something-went-wrong-trace check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.30.22 something-went-wrong-trace checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
