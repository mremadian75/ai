<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){return true;} }
if (!function_exists('current_user_can')) { function current_user_can($cap){return $cap==='manage_options';} }
if (!function_exists('get_current_user_id')) { function get_current_user_id(){return 42;} }
if (!function_exists('esc_html')) { function esc_html($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('esc_attr')) { function esc_attr($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('esc_url')) { function esc_url($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('admin_url')) { function admin_url($path=''){return 'admin.php' . ($path ? '/' . ltrim($path,'/') : '');} }
if (!function_exists('add_query_arg')) { function add_query_arg($args,$url){return $url . '?' . http_build_query($args);} }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a,$n='_wpnonce',$r=true,$echo=true){$h='<input type="hidden" name="'.$n.'" value="nonce">'; if($echo){echo $h;} return $h;} }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n=900; public static function record($args){return ++self::$n;} } }
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-usage-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brief-os-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-playbook-input-validator.php';
require_once dirname(__DIR__) . '/includes/class-ves-draft-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-report-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brand-market-xray-playbook.php';
require_once dirname(__DIR__) . '/includes/modules/command-room/class-fi-command-room-service.php';
require_once dirname(__DIR__) . '/includes/modules/command-room/class-fi-command-room-renderer.php';
VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Intelligence_Store::create_tables(true);
$res = VES_Brand_Market_Xray_Playbook::run(['brand_name'=>'Future Island','brand_description'=>'Evidence-backed marketing intelligence','market'=>'Spain','language'=>'en','audience'=>'agencies','competitors'=>'Generic AI Writer','goal'=>'choose a campaign angle'], ['workspace_id'=>42,'idempotency_key'=>'ui-1']);
$html = FI_Command_Room_Renderer::render_html(['run_id'=>(int)$res['run_id']]);
fi_spine_ok(strpos($html, 'Command Room') !== false, 'Command Room renders');
fi_spine_ok(strpos($html, 'Brand &amp; Market X-Ray') !== false || strpos($html, 'Brand & Market X-Ray') !== false, 'playbook card renders');
fi_spine_ok(strpos($html, 'Evidence / object drawer') !== false, 'evidence drawer renders');
fi_spine_ok(strpos($html, 'Run timeline') !== false, 'run timeline renders');
fi_spine_ok(strpos($html, 'Memory after review') !== false && strpos($html, 'not evidence') !== false, 'memory policy visible');
fi_spine_ok(strpos($html, '<script') === false && strpos($html, 'sk-') === false, 'no script/secrets exposed');
$html2 = FI_Command_Room_Renderer::render_html(['run_id'=>999999]);
fi_spine_ok(strpos($html2, 'Command Room') !== false, 'missing run does not fatal');
fi_spine_finish('v0.5.0 Command Room render checks');
