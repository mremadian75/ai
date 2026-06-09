<?php
/**
 * v0.9.31.3 — Report system wiring, CSS tokens, and template safety.
 * Run: php tests/test-v09313-report-wiring.php
 * No external calls; loads report classes directly.
 */
$root = dirname(__DIR__);
define('ABSPATH', $root);
define('VES_PLUGIN_DIR', $root . '/');
define('VES_PLUGIN_URL', 'https://example.test/wp-content/plugins/vietnam-estudio-social-scraper/');
if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s){ return trim(strip_tags((string)$s)); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return trim((string)$s); } }
if (!function_exists('esc_url')) { function esc_url($s){ $s = trim((string)$s); return preg_match('#^https?://#i', $s) ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : ''; } }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html__')) { function esc_html__($s,$d=null){ return esc_html($s); } }
if (!function_exists('__')) { function __($s,$d=null){ return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

require $root . '/includes/reports/class-ves-report-template-registry.php';
require $root . '/includes/reports/class-ves-report-schema-validator.php';
require $root . '/includes/reports/class-ves-report-data-builder.php';
require $root . '/includes/reports/class-ves-report-renderer.php';

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

// 1. ves_render_report_template function must exist after loading renderer.
$assert(function_exists('ves_render_report_template'), 'ves_render_report_template() helper must be declared');

// 2. VES_Report_Renderer::render() must return non-empty HTML for a valid report.
$report = VES_Report_Data_Builder::from_run_payload([
    'platform'     => 'tiktok',
    'items'        => [['ves_title' => 'Test hook', 'ves_text' => 'Observed signal', 'ves_views' => 1200]],
    'insights'     => ['Strong hook pattern observed'],
    'next_actions' => ['Test format A/B'],
    'warnings'     => [],
]);
$template_key = VES_Report_Template_Registry::choose($report);
$assert($template_key !== '', 'VES_Report_Template_Registry::choose() must return a template key');
$assert($template_key === 'tiktok-radar', "choose() must select tiktok-radar for tiktok platform, got: $template_key");

$html = ves_render_report_template($report, $template_key);
$assert(is_string($html) && strlen($html) > 100, 'ves_render_report_template() must return non-empty HTML');
$assert(strpos($html, 'onclick') === false, 'Report templates must NOT use inline onclick handlers');
$assert(strpos($html, 'esc_html') === false, "Rendered HTML must not contain literal 'esc_html' — should be called, not echoed");

// 3. CSS must use FI brand tokens (not old generic colors).
$css = file_get_contents($root . '/assets/css/ves-report-templates.css');
$assert(strpos($css, '#3A61E1') !== false, 'ves-report-templates.css must use FI azul #3A61E1 (not #2563eb)');
$assert(strpos($css, '#F15D31') !== false, 'ves-report-templates.css must use FI orange #F15D31 (not #dc2626)');
$assert(strpos($css, '#2563eb') === false, 'ves-report-templates.css must NOT contain old blue #2563eb');
$assert(strpos($css, '#dc2626') === false, 'ves-report-templates.css must NOT contain old red #dc2626');
$assert(strpos($css, '#E4DDC9') !== false, 'ves-report-templates.css must use FI beige #E4DDC9 for empty state');
$assert(strpos($css, '#FAF9F6') !== false, 'ves-report-templates.css must use FI off-white #FAF9F6 for empty state');

// 4. All template PHP files must use esc_html() and have no inline onclick.
$templates_dir = $root . '/templates/reports/';
foreach (glob($templates_dir . '*.php') as $tpl_path) {
    $tpl = file_get_contents($tpl_path);
    $tpl_name = basename($tpl_path);
    if ($tpl_name === '_partials.php') { continue; }
    $assert(strpos($tpl, 'esc_html') !== false, "$tpl_name must call esc_html()");
    $assert(strpos($tpl, 'onclick') === false, "$tpl_name must NOT use inline onclick");
}

// 5. AJAX controller must wire report_html (grep check).
$ctrl = file_get_contents($root . '/includes/class-ves-ajax-controller.php');
$assert(strpos($ctrl, 'report_html') !== false, 'ajax controller must build report_html in success response');
$assert(strpos($ctrl, 'ves_render_report_template') !== false, 'ajax controller must call ves_render_report_template');
$assert(strpos($ctrl, 'VES_Report_Data_Builder') !== false, 'ajax controller must reference VES_Report_Data_Builder');

// 6. Frontend JS must display report_html.
$js = file_get_contents($root . '/assets/js/ves-frontend.js');
$assert(strpos($js, 'report_html') !== false, 'ves-frontend.js must handle report_html from server response');
$assert(strpos($js, 'ves-report-container') !== false, 'ves-frontend.js must target .ves-report-container element');

// 7. Shortcode template must include .ves-report-container.
$shortcode = file_get_contents($root . '/templates/shortcode.php');
$assert(strpos($shortcode, 'ves-report-container') !== false, 'shortcode.php must include .ves-report-container div');

if ($fail === 0) {
    echo "OK v0.9.31.3 report wiring — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.3 report wiring — $fail failures, $pass passed\n";
    exit(1);
}
