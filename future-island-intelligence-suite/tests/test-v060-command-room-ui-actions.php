<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){return true;} }
if (!function_exists('current_user_can')) { function current_user_can($cap){return $cap==='manage_options';} }
if (!function_exists('apply_filters')) { function apply_filters($tag,$value){return $value;} }
if (!function_exists('esc_html')) { function esc_html($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('esc_attr')) { function esc_attr($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('esc_url')) { function esc_url($s){return htmlspecialchars((string)$s, ENT_QUOTES);} }
if (!function_exists('admin_url')) { function admin_url($path=''){return 'admin.php' . ($path ? '/' . ltrim($path,'/') : '');} }
if (!function_exists('add_query_arg')) { function add_query_arg($args,$url){return $url . '?' . http_build_query($args);} }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a,$n='_wpnonce',$r=true,$echo=true){$h='<input type="hidden" name="'.$n.'" value="nonce">'; if($echo){echo $h;} return $h;} }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n=5000; public static function record($args){return ++self::$n;} } }
foreach (['includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-brief-os-service.php','includes/class-ves-playbook-input-validator.php','includes/class-ves-draft-service.php','includes/class-ves-review-service.php','includes/class-ves-command-room-action-service.php','includes/class-ves-decision-report-service.php','includes/class-ves-brand-market-xray-playbook.php','includes/modules/command-room/class-fi-command-room-service.php','includes/modules/command-room/class-fi-command-room-renderer.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name'=>'UI Workspace','created_by_user_id'=>42]); VES_Workspace_Membership_Store::add_member($ws,42,'owner');
$res = VES_Brand_Market_Xray_Playbook::run(['brand_name'=>'Future Island','brand_description'=>'Evidence-backed marketing intelligence','market'=>'Spain','language'=>'en','audience'=>'agencies','competitors'=>'Generic AI Writer','goal'=>'choose a campaign angle'], ['workspace_id'=>$ws,'idempotency_key'=>'ui-actions']);
$html = FI_Command_Room_Renderer::render_html(['run_id'=>(int)$res['run_id']]);
fi_spine_ok(strpos($html, 'Approve') !== false && strpos($html, 'Reject') !== false && strpos($html, 'Request revision') !== false, 'interactive review action buttons render');
fi_spine_ok(strpos($html, 'name=&quot;reason_code&quot;') !== false || strpos($html, 'name="reason_code"') !== false, 'reason code selector renders');
fi_spine_ok(strpos($html, 'Create brief') !== false && strpos($html, 'Generate draft') !== false, 'brief and draft action buttons render');
fi_spine_ok(strpos($html, 'Record outcome') !== false && strpos($html, 'Save outcome') !== false, 'outcome form renders');
fi_spine_ok(strpos($html, 'Memory is context, not evidence') !== false || strpos($html, 'not evidence') !== false, 'memory context warning renders');
fi_spine_ok(strpos($html, 'Decision Report export is private-beta JSON') !== false, 'private report export note renders');
fi_spine_ok(strpos($html, '<script') === false && strpos($html, 'sk-') === false, 'no script or provider secret exposed');
fi_spine_finish('v0.6.0 Command Room UI action render checks');
