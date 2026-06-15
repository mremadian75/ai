<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$GLOBALS['__fi_admin'] = true; $GLOBALS['__fi_logged_in'] = true; $GLOBALS['__fi_transients'] = [];
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['__fi_admin']); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['__fi_logged_in']); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!function_exists('get_transient')) { function get_transient($k){ return $GLOBALS['__fi_transients'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k,$v,$ttl=0){ $GLOBALS['__fi_transients'][$k]=$v; return true; } }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('admin_url')) { function admin_url($path=''){ return 'admin.php' . ($path && $path !== 'admin.php' ? '/' . $path : ''); } }
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url){ return $url . (strpos($url,'?')===false?'?':'&') . http_build_query($args); } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($action,$name='_wpnonce',$referer=true,$echo=true){ $h='<input type="hidden" name="'.$name.'" value="nonce">'; if($echo){echo $h;} return $h; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return trim((string)$s); } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 9100; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-settings-service.php','includes/class-ves-provider-replay-guard.php','includes/class-ves-provider-rate-limit-service.php','includes/class-ves-provider-callback-auth-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php','includes/class-ves-provider-admin-page.php','includes/class-ves-workspace-onboarding-service.php','includes/class-ves-private-beta-readiness-service.php','includes/modules/command-room/class-fi-command-room-service.php','includes/modules/command-room/class-fi-command-room-renderer.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Provider Admin', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'social_scan', 'trigger_type' => 'api', 'idempotency_key' => 'admin-ledger-run']);
$res = VES_Provider_Run_Service::ingest('social_signal', ['workspace_id'=>$ws,'run_id'=>$run,'provider_key'=>'approved_social_provider','provider_run_id'=>'admin-ledger-provider','idempotency_key'=>'admin-ledger-ingest','rows'=>[['platform'=>'linkedin','caption'=>'Provider admin rows stay redacted','authorization'=>'Bearer ledgersecret123456789']]]);
fi_spine_ok(is_array($res) && $res['ingestion_id'] > 0, 'provider ingestion recorded in ledger');
$list = VES_Provider_Ingestion_Store::list(['workspace_id' => $ws]);
fi_spine_ok(count($list) === 1 && $list[0]['status'] === 'completed', 'ingestion ledger list renders data source');
fi_spine_ok(strpos(json_encode($list), 'ledgersecret') === false, 'raw payload/token not shown in ledger rows');
fi_spine_ok(VES_Provider_Ingestion_Store::mark_reviewed((int)$res['ingestion_id']) === true, 'mark reviewed action updates status');
$reviewed = VES_Provider_Ingestion_Store::get((int)$res['ingestion_id']);
fi_spine_ok($reviewed['status'] === 'reviewed', 'reviewed status persisted');
fi_spine_ok(VES_Provider_Ingestion_Store::void_usage((int)$res['ingestion_id']) === true, 'void usage action is idempotent-safe');
$voided = VES_Provider_Ingestion_Store::get((int)$res['ingestion_id']);
fi_spine_ok($voided['status'] === 'voided', 'void status persisted');
VES_Provider_Settings_Service::set('social_signal','approved_social_provider',['enabled'=>true,'auth_mode'=>'hmac_signature','secret'=>'fi_admin_secret_123','max_rows_per_callback'=>10]);
$status = VES_Provider_Settings_Service::public_status('social_signal','approved_social_provider');
fi_spine_ok(!isset($status['secret']) && !empty($status['secret_configured']), 'provider status hides secret and shows configured boolean');
ob_start(); VES_Provider_Admin_Page::render_contracts_page(); $contracts_html = ob_get_clean();
fi_spine_ok(strpos($contracts_html, 'Provider Contracts') !== false && strpos($contracts_html, 'fi_admin_secret') === false, 'provider contracts admin renders without secret leak');
ob_start(); VES_Provider_Admin_Page::render_ingestions_page(); $ledger_html = ob_get_clean();
fi_spine_ok(strpos($ledger_html, 'Provider Ingestions') !== false && strpos($ledger_html, 'ledgersecret') === false, 'provider ingestions admin renders without raw payload');
$state = VES_Workspace_Onboarding_Service::save_profile(['brand_url'=>'https://example.com','brand_name'=>'Future Island','brand_description'=>'Cultural-tech workspace','market'=>'Spain','language'=>'en','audience'=>'agencies','competitors'=>'Generic AI, Dashboard Tool'], $ws);
fi_spine_ok(is_array($state) && !empty($state['steps']['brand_context_added']) && !empty($state['steps']['market_language_added']), 'workspace onboarding stores brand context and market/language');
$html = VES_Workspace_Onboarding_Service::render_panel($state);
fi_spine_ok(strpos($html, 'Private beta onboarding') !== false && strpos($html, 'Brand &amp; Market X-Ray') !== false, 'workspace onboarding panel renders recommended route');
$timeline = VES_Run_Log_Service::timeline_for_run($ws, $run);
fi_spine_ok(count($timeline) >= 1 && isset($timeline[0]['status_chip']) && isset($timeline[0]['events']), 'run timeline v2 includes status chips and event previews');
$cmd = FI_Command_Room_Renderer::render_html(['run_id' => $run]);
fi_spine_ok(strpos($cmd, 'Provider controls') !== false && strpos($cmd, 'Private beta onboarding') !== false && strpos($cmd, 'Raw provider payloads') !== false, 'Command Room private beta UI includes provider controls, onboarding, redaction note');
$GLOBALS['__fi_admin'] = false;
ob_start(); VES_Provider_Admin_Page::render_contracts_page(); $blocked = ob_get_clean();
fi_spine_ok($blocked === '', 'non-admin provider admin render is blocked without exposing content');
fi_spine_finish('v0.8.0 provider admin ledger onboarding checks');
