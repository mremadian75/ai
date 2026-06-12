<?php
/**
 * v0.9.31.6 targeted regression checks.
 * Run from plugin root with: php tests/test-v09312-query-planner-report-templates.php
 * No external API calls are made.
 */
define('ABSPATH', dirname(__DIR__));
define('VES_PLUGIN_DIR', dirname(__DIR__) . '/');
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
if (!function_exists('ves_google_ads_region_from_country')) { function ves_google_ads_region_from_country($c){ return strtoupper($c ?: 'ES'); } }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
require VES_PLUGIN_DIR . 'includes/class-ves-query-planner.php';
require VES_PLUGIN_DIR . 'includes/reports/class-ves-report-template-registry.php';
require VES_PLUGIN_DIR . 'includes/reports/class-ves-report-schema-validator.php';
require VES_PLUGIN_DIR . 'includes/reports/class-ves-report-data-builder.php';
require VES_PLUGIN_DIR . 'includes/reports/class-ves-report-renderer.php';
function ves_test_assert($condition, $message) { if (!$condition) { throw new Exception($message); } }
$n = VES_Query_Planner::normalize_request_fields('ads','google_ads',['targets'=>"Mahou\nMAHOU.ES\nmahou.es", 'country'=>'Spain', 'limit'=>20]);
$p = VES_Query_Planner::build_actor_payload('ads','google_ads','dz_omar/google-ads-scraper',$n);
ves_test_assert(count($p['domains']) === 1 && $p['domains'][0] === 'mahou.es', 'Google Ads domains should normalize/dedupe.');
ves_test_assert((int)$p['maxDomainMatches'] >= 1, 'Google Ads maxDomainMatches cannot be zero.');
$n2 = VES_Query_Planner::normalize_request_fields('ads','facebook_ads',['targets'=>'Mahou España','country'=>'ES']);
$p2 = VES_Query_Planner::build_actor_payload('ads','facebook_ads','curious_coder/facebook-ads-library-scraper',$n2);
ves_test_assert(!empty($p2['urls'][0]['url']), 'Meta Ads urls must be object list for actor schema compatibility.');
$n3 = VES_Query_Planner::normalize_request_fields('seo','ahrefs',['keyword_seed'=>'cerveza sin alcohol','mode'=>'keywords_scraper']);
$p3 = VES_Query_Planner::build_actor_payload('seo','ahrefs','pro100chok/ahrefs-seo-tools',$n3);
ves_test_assert(($p3['keyword'] ?? '') === 'cerveza sin alcohol', 'Ahrefs keyword aliases should produce keyword payload.');
$html = ves_render_report_template(VES_Report_Data_Builder::from_run_payload(['platform'=>'tiktok','items'=>[['title'=>'Hook','text'=>'Observed']],'next_actions'=>['Test hook']]), 'tiktok-radar');
ves_test_assert(strpos($html, 'ves-report-tiktok') !== false, 'Report renderer should render selected template.');
echo "OK v0.9.31.6 targeted regression checks\n";
