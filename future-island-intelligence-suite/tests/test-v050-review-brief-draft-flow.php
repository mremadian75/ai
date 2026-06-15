<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){return true;} }
if (!function_exists('current_user_can')) { function current_user_can($cap){return $cap==='manage_options';} }
if (!function_exists('get_current_user_id')) { function get_current_user_id(){return 42;} }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n=950; public static function record($args){return ++self::$n;} } }
require_once dirname(__DIR__) . '/includes/class-ves-workspace-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-permission-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-run-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-decision-edge-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-decision-ledger.php';
require_once dirname(__DIR__) . '/includes/class-ves-intelligence-store.php';
require_once dirname(__DIR__) . '/includes/class-ves-usage-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-brief-os-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-draft-service.php';
require_once dirname(__DIR__) . '/includes/class-ves-review-service.php';
VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true);
$run = VES_Run_Service::create_run(['workspace_id'=>42,'run_type'=>'research','trigger_type'=>'manual','status'=>'running','idempotency_key'=>'review-1']);
$src = VES_Intelligence_Store::create_source(['workspace_id'=>42,'run_id'=>$run,'source_type'=>'manual','provider'=>'manual','title'=>'Manual context']);
$sig = VES_Intelligence_Store::create_signal(['workspace_id'=>42,'run_id'=>$run,'source_id'=>$src,'signal_type'=>'market_signal','title'=>'Audience wants proof','summary'=>'Audience requests evidence before content.','confidence_score'=>72]);
$ev = VES_Intelligence_Store::create_evidence(['workspace_id'=>42,'run_id'=>$run,'source_id'=>$src,'signal_id'=>$sig,'evidence_type'=>'observation','text'=>'Manual note: evidence before content.','confidence_score'=>70]);
$ins = VES_Intelligence_Store::create_insight(['workspace_id'=>42,'run_id'=>$run,'insight_type'=>'opportunity','title'=>'Lead with proof','summary'=>'Proof-first narrative is more useful than generic AI copy.','evidence_ids'=>[$ev],'signal_ids'=>[$sig],'status'=>'draft']);
$approved = VES_Review_Service::review('insight', $ins, 'approve', ['workspace_id'=>42,'reason_code'=>'good_angle','notes'=>'Useful and specific.']);
fi_spine_ok(is_array($approved) && ($approved['status']??'')==='approved', 'approve insight');
$brief_id = VES_Review_Service::create_brief_from_insight($ins, ['workspace_id'=>42,'channel'=>'linkedin','format'=>'social_post','objective'=>'turn proof into a campaign angle','target_audience'=>'agency operators']);
fi_spine_ok(is_int($brief_id) && $brief_id>0, 'create brief from approved insight');
$draft = VES_Draft_Service::create_draft_from_brief((int)$brief_id, ['draft_mode'=>'deterministic_preview']);
$draft_id = is_int($draft) ? $draft : 0;
$draft_row = VES_Intelligence_Store::get_draft($draft_id);
fi_spine_ok($draft_id>0 && is_array($draft_row) && ($draft_row['metadata']['draft_mode']??'')==='deterministic_preview', 'generate draft candidate with mode');
$primary = VES_Draft_Service::mark_primary($draft_id);
fi_spine_ok(is_array($primary) && !empty($primary['metadata']['is_primary']), 'mark draft primary');
$reject = VES_Review_Service::review('draft', $draft_id, 'reject', ['workspace_id'=>42,'reason_code'=>'too_generic','notes'=>'Needs sharper proof.']);
fi_spine_ok(is_array($reject) && ($reject['reason_code']??'')==='too_generic', 'reject draft with reason code');
$invalid = VES_Review_Service::review('insight', $ins, 'approve', ['workspace_id'=>42,'reason_code'=>'not_a_reason']);
fi_spine_ok(is_wp_error($invalid), 'invalid reason code blocked');
$edges = VES_Decision_Edge_Service::list_edges_for_target(42, 'draft', $draft_id);
fi_spine_ok(count($edges) >= 1, 'decision edges visible for draft');
fi_spine_finish('v0.5.0 review/brief/draft flow checks');
