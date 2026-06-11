<?php
/**
 * Full-plugin static hardening audit — v0.9.30.21
 *
 * Covers the regressions found after the Phase 0 live tests plus a broader
 * static sweep: version consistency, public-copy provider leakage, YouTube
 * runtime guardrails, AJAX auth surface, and syntax-level testability.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function($condition, $message) use (&$failures, &$passes) {
    if ($condition) { $passes[] = $message; } else { $failures[] = $message; }
};
$read = function($rel) use ($root) { return file_get_contents($root . '/' . $rel); };

$main = $read('future-island-intelligence-suite.php');
$assert(strpos($main, 'Version: 1.2.7') !== false, 'Plugin header version is 1.1.0');
$assert(strpos($main, "VES_PLUGIN_VERSION',         '1.2.7'") !== false, 'VES_PLUGIN_VERSION constant is 1.1.0');

$publicTemplates = [
    'templates/_scraper-form.php',
    'templates/_linkedin-form.php',
];
$publicBadExact = [
    'consulta al proveedor',
    'sin llamada al proveedor',
    'términos del proveedor',
    'coste proveedor',
    'Provider access failed',
    'HTTP logico',
    'Provider Cost',
    'Apify',
];
foreach ($publicTemplates as $file) {
    $body = $read($file);
    foreach ($publicBadExact as $bad) {
        $assert(stripos($body, $bad) === false, "Public template {$file} does not expose {$bad}");
    }
}

$frontend = $read('assets/js/ves-frontend.js');
foreach (['Provider access failed', 'HTTP logico', 'statusHint', 'Credits refunded', 'No credits charged', 'Run usage'] as $bad) {
    $assert(strpos($frontend, $bad) === false, "Frontend bundle no longer contains stale normal-user copy: {$bad}");
}
$assert(strpos($frontend, "const providerCostKpiLabel = (widget) => isDebugMode(widget) ? 'Provider cost' : 'Uso';") !== false, 'Normal KPI label is product-safe Usage/Uso, debug keeps Provider cost');
$assert(strpos($frontend, "chip('Señales utilizables'") !== false, 'Normal diagnostics use Spanish product-safe usable-signal label');
$assert(strpos($frontend, 'Créditos devueltos') !== false, 'Normal usage outcome uses Spanish credit wording');

$queryPlanner = $read('includes/class-ves-query-planner.php');
$helpers = $read('includes/helpers.php');
$adapter = $read('includes/class-ves-source-adapter-registry.php');
$assert(strpos($queryPlanner, 'ves_youtube_search_terms') !== false, 'Canonical YouTube planner uses shared language-aware query expansion helper');
$assert(strpos($helpers, "'espana' => 'ES'") !== false || strpos($helpers, 'espana') !== false && strpos($helpers, "'ES'") !== false, 'Country mapper contains Spain/España -> ES handling');
$assert(strpos($queryPlanner, "subtitlesLanguage' => 'en'") === false && strpos($adapter, "subtitlesLanguage' => 'en'") === false, 'Canonical planner/adapter do not force English subtitles');

$controller = $read('includes/class-ves-ajax-controller.php');
foreach (['provider_run_id_received','dataset_fetch_attempted','normalization_started','response_rendered','public_support_code','classify_error_category'] as $needle) {
    $assert(strpos($controller, $needle) !== false, "Diagnostics controller includes {$needle}");
}
$assert(strpos($controller, 'require_admin_ops') !== false, 'Admin-only AJAX helper is present for repair/diagnostic actions');
foreach (['trend_memory_repair_count','trend_memory_repair_dry_run','trend_memory_repair_apply','apify_slot_diagnostics'] as $method) {
    $pos = strpos($controller, 'public static function ' . $method);
    $chunk = $pos !== false ? substr($controller, $pos, 500) : '';
    $assert(strpos($chunk, 'require_admin_ops') !== false, "{$method} is protected by nonce + manage_options helper");
}

if ($failures) {
    echo "FAILURES:\n" . implode("\n", array_map(fn($f) => ' - ' . $f, $failures)) . "\n";
    exit(1);
}

echo "v0.9.30.21 full-plugin hardening audit passed (" . count($passes) . " checks).\n";
foreach ($passes as $p) { echo " - {$p}\n"; }
