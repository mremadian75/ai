<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$GLOBALS['__fi_admin'] = true; $GLOBALS['__fi_logged_in'] = true; $GLOBALS['__fi_transients'] = [];
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['__fi_admin']); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['__fi_logged_in']); } }
if (!function_exists('get_transient')) { function get_transient($k){ return $GLOBALS['__fi_transients'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k,$v,$ttl=0){ $GLOBALS['__fi_transients'][$k]=$v; return true; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 9300; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-settings-service.php','includes/class-ves-provider-replay-guard.php','includes/class-ves-provider-rate-limit-service.php','includes/class-ves-provider-callback-auth-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php','includes/class-ves-run-diagnostics-service.php'
] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name'=>'Ledger QA','created_by_user_id'=>42]); VES_Workspace_Membership_Store::add_member($ws,42,'owner');
$run = VES_Run_Service::create_run(['workspace_id'=>$ws,'run_type'=>'social_scan','trigger_type'=>'qa','idempotency_key'=>'ledger-run']);
$id1 = VES_Provider_Ingestion_Store::record(['workspace_id'=>$ws,'run_id'=>$run,'provider_family'=>'social_signal','provider_key'=>'approved_social_provider','provider_run_id'=>'p1','idempotency_key'=>'ledger-1','status'=>'partial','row_count'=>2,'accepted_count'=>1,'rejected_count'=>1,'result'=>['created'=>['signals'=>[1]],'secret'=>'sk-shouldhide']]);
$id2 = VES_Provider_Ingestion_Store::record(['workspace_id'=>$ws,'run_id'=>$run,'provider_family'=>'aeo_answer','provider_key'=>'approved_aeo_provider','provider_run_id'=>'p2','idempotency_key'=>'ledger-2','status'=>'failed','row_count'=>1,'accepted_count'=>0,'rejected_count'=>1,'result'=>['created'=>[]]]);
fi_spine_ok(count(VES_Provider_Ingestion_Store::list(['workspace_id'=>$ws,'provider_family'=>'social_signal'])) === 1, 'provider family filter applied');
fi_spine_ok(count(VES_Provider_Ingestion_Store::list(['workspace_id'=>$ws,'has_rejected'=>1])) === 2, 'has rejected filter applied');
fi_spine_ok(count(VES_Provider_Ingestion_Store::list(['workspace_id'=>$ws,'limit'=>1,'offset'=>1])) === 1, 'pagination offset works');
fi_spine_ok(VES_Provider_Ingestion_Store::count(['workspace_id'=>$ws]) === 2, 'total count works');
VES_Provider_Ingestion_Store::mark_reviewed($id1);
fi_spine_ok(count(VES_Provider_Ingestion_Store::list(['workspace_id'=>$ws,'reviewed'=>'reviewed'])) === 1, 'reviewed filter works');
fi_spine_ok(VES_Provider_Ingestion_Store::bulk_archive([$id2]) === 1, 'bulk archive works');
$blocked = VES_Provider_Ingestion_Store::void_usage_if_no_value($id1);
fi_spine_ok(is_wp_error($blocked), 'void usage blocked when value exists');
VES_Run_Log_Service::append($ws,$run,'warn','provider_auth','provider_auth_blocked','Blocked signature', ['signature'=>'sha256=abcd','api_key'=>'sk-shouldhide']);
$diag = VES_Run_Diagnostics_Service::diagnose($ws,$run);
fi_spine_ok($diag['diagnosis'] === 'provider_auth_failed', 'diagnostics classify auth failure');
$logs = json_encode(VES_Run_Log_Service::list_for_run($ws,$run));
fi_spine_ok(strpos($logs,'sk-shouldhide') === false && strpos($logs,'sha256=abcd') === false, 'diagnostics/log output redacted');
fi_spine_finish('v0.9.0 ingestion ledger filters and diagnostics checks');
