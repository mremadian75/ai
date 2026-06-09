<?php
require_once __DIR__ . '/test-fidtf-v031-core-apify-client-bridge-hardening.php';
require_once dirname(__DIR__) . '/includes/class-fidtf-module-info.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$plugin_class = file_get_contents($root . '/includes/class-fidtf-plugin.php');
$admin = file_get_contents($root . '/includes/class-fidtf-admin.php');
$memory = file_get_contents($root . '/includes/class-fidtf-memory-bridge.php');
$template = file_get_contents($root . '/templates/shortcode-deep-trend-finder.php');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$js = file_get_contents($root . '/assets/js/fidtf-frontend.js');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin version is v0.3.2/v0.3.3/v0.3.4 integration UI line
ok(strpos($plugin, 'class-fidtf-module-info.php') !== false, 'module info file is bootstrapped');
ok(function_exists('fidtf_module_info'), 'fidtf_module_info helper exists');
$info = fidtf_module_info();
ok(($info['key'] ?? '') === 'deep_trend_finder', 'module info key is deep_trend_finder');
ok(($info['shortcode'] ?? '') === 'future_island_deep_trend_finder', 'module info advertises shortcode');
ok(array_key_exists('url', $info) && array_key_exists('page_id', $info), 'module info exposes URL and page id fields');
// [v1.1.0 retired] The standalone add-on → Core handshake hook
// add_filter('future_island_register_external_modules', ...) is obsolete: in unified FIIS v1.1.0
// the Deep Trend Finder module is loaded in-process by the suite bootstrap (FIDTF_Plugin::boot()),
// not advertised to a separately-installed Core. Replaced with the current unified-loading contract.
ok(strpos($plugin, 'FIDTF_Plugin::boot()') !== false, 'unified bootstrap boots FIDTF_Plugin directly');
ok(strpos($plugin, "includes/class-fidtf-plugin.php") !== false, 'unified bootstrap loads the DTF plugin class');
ok(strpos($plugin_class, 'class FIDTF_Plugin') !== false, 'class-fidtf-plugin.php defines FIDTF_Plugin');
ok(strpos($plugin_class, 'public static function boot') !== false, 'FIDTF_Plugin exposes boot()');
ok(strpos($plugin_class, "add_action('init', [__CLASS__, 'register_shortcode'])") !== false, 'FIDTF_Plugin::boot registers its shortcode on init');
ok(strpos($plugin_class, "add_action('rest_api_init', ['FIDTF_REST_Controller', 'register_routes'])") !== false, 'FIDTF_Plugin::boot registers REST routes on rest_api_init');
ok(strpos($plugin_class, 'fidtf_page_slug') !== false, 'auto-created page stores page slug option');
ok(strpos($plugin_class, 'fidtf_page_shortcode_missing') !== false, 'user-edited page missing shortcode is flagged, not silently overwritten');
ok(strpos($plugin_class, 'recreate_frontend_page') !== false && strpos($admin, 'fidtf_recreate_page') !== false, 'admin recreate page action exists');
ok(strpos($admin, 'check_admin_referer') !== false && strpos($admin, 'current_user_can') !== false, 'recreate action has nonce and capability guard');
ok(strpos($memory, "'source_type' => 'deep_trend_finder'") !== false, 'memory bridge writes Deep Trend Finder source_type');
ok(strpos($memory, 'VES_Memory_Records::save_record') !== false, 'memory bridge writes through Core memory records when available');
ok(strpos($template, 'fidtf-hero') !== false, 'UI has intelligence hero section');
ok(strpos($template, 'fidtf-card-brief') !== false, 'UI has research brief card');
ok(strpos($template, 'fidtf-source-plan') !== false && strpos($template, 'fidtf-source-readiness') !== false, 'UI has source readiness / signal map');
ok(strpos($template, 'fidtf-progressive-rows') !== false, 'UI has progressive source rows shell');
ok(strpos($template, 'fidtf-evidence-grid') !== false, 'UI has evidence grid shell');
ok(strpos($template, 'fidtf-report-shell') !== false, 'UI has report shell');
ok(strpos($template . $js, 'sendPrompt(') === false, 'no undefined sendPrompt in frontend');
ok(strpos($template . $js . $admin, 'onclick=') === false, 'no inline onclick in add-on UI/admin code');
ok(strpos($template . $js, 'cdnjs.cloudflare.com') === false && strpos($template . $js, 'Chart.js') === false, 'no raw Chart.js CDN');
ok(strpos($css, '.fidtf-shell') !== false && (strpos($css, 'repeat(auto-fit, minmax(260px, 1fr))') !== false || strpos($css, 'repeat(auto-fit, minmax(240px, 1fr))') !== false), 'CSS is scoped and responsive');
ok(strpos($js, 'selectedSourcePlanHtml') !== false && strpos($js, 'data-fidtf-progressive-rows') !== false, 'JS updates source plan and progressive rows');
ok(strpos($template, 'Ready for live') !== false && strpos($template, 'Planned only') !== false, 'provider status chips distinguish live TikTok from planned-only sources');
ok(strpos($template, 'Charts and recommendations will appear only after real evidence exists') !== false, 'report shell avoids fake charts and fake recommendations');

fwrite(STDOUT, "FIDTF v0.3.2 core shell integration UI checks passed: {$checks} / {$checks}\n");
