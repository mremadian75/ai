<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){return true;} }
if (!function_exists('current_user_can')) { function current_user_can($cap){return true;} }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n=700; public static function record($args){return ++self::$n;} } }
require_once dirname(__DIR__) . '/includes/class-ves-string-compat.php';
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-usage-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brief-os-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-draft-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-playbook-input-validator.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-report-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brand-market-xray-playbook.php';
VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Intelligence_Store::create_tables(true);
$res = VES_Brand_Market_Xray_Playbook::run([
  'brand_url'=>'https://futureisland.example/?utm_source=x', 'brand_name'=>'Future Island', 'brand_description'=>'Cultural-tech signal-to-brief workspace.', 'market'=>'Spain', 'language'=>'en', 'audience'=>'agency strategists', 'competitors'=>'Brandwatch, Semrush', 'goal'=>'Find positioning angle', 'keywords'=>'signals, briefs', 'social_handles'=>'@futureisland'
], ['workspace_id'=>42, 'idempotency_key'=>'xray-test-1']);
fi_spine_ok(is_array($res) && ($res['run_id']??0)>0, 'Brand X-Ray returns run id');
$run = VES_Run_Service::get_run((int)$res['run_id']);
fi_spine_ok($run['run_type']==='brand_xray' && $run['status']==='completed', 'canonical run created and completed');
fi_spine_ok(count($res['created']['sources'])>=1, 'sources created');
fi_spine_ok(count($res['created']['signals'])>=4, 'signal items created');
fi_spine_ok(count($res['created']['evidence'])>=4, 'evidence refs created');
fi_spine_ok(count($res['created']['insights'])>=3, 'draft insights created');
fi_spine_ok(count($res['created']['briefs'])>=1, 'brief candidate created');
fi_spine_ok(count($res['created']['drafts'])>=1, 'draft candidate created');
$edges = VES_Decision_Edge_Service::list_edges_for_run(42, (int)$res['run_id']);
fi_spine_ok(count($edges)>=6, 'decision edges created');
$report = VES_Decision_Report_Service::build_for_run(42, (int)$res['run_id']);
fi_spine_ok(($report['counts']['signals']??0)>=4 && in_array('manual', $report['source_coverage'], true), 'decision report skeleton summarizes coverage');
$bad = VES_Brand_Market_Xray_Playbook::run(['brand_url'=>'file:///etc/passwd'], ['workspace_id'=>42]);
fi_spine_ok(is_wp_error($bad), 'invalid URL handled safely');
fi_spine_finish('v0.5.0 Brand & Market X-Ray playbook checks');
