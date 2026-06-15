<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){return true;} }
if (!function_exists('current_user_can')) { function current_user_can($cap){return true;} }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n=800; public static function record($args){return ++self::$n;} } }
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
require_once dirname(__DIR__) . '/includes/class-ves-social-signal-mapper.php';
require_once dirname(__DIR__) . '/includes/class-ves-social-signal-intelligence-service.php';
VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Intelligence_Store::create_tables(true);
$norm = VES_Social_Signal_Mapper::normalize_row(['platform'=>'TikTok','caption'=>'Strong hook #AI','metrics'=>['likes'=>12],'relevance_label'=>'direct_match'], ['language'=>'en','market'=>'ES']);
fi_spine_ok($norm['platform']==='tiktok' && $norm['metrics']['likes']===12 && in_array('#AI',$norm['hashtags'],true), 'provider row maps to canonical social payload');
$res = VES_Social_Signal_Intelligence_Service::run_scan(['mode'=>'topic','query'=>'AI marketing workflows','platforms'=>'tiktok,reddit','market'=>'Spain','language'=>'en','provider_rows'=>[['platform'=>'reddit','caption'=>'Agencies want evidence before content.','relevance_label'=>'semantic_fit']]], ['workspace_id'=>42,'idempotency_key'=>'soc-1']);
fi_spine_ok(is_array($res) && ($res['run_id']??0)>0, 'topic social scan accepted');
$run = VES_Run_Service::get_run((int)$res['run_id']);
fi_spine_ok($run['run_type']==='social_scan' && $run['status']==='completed', 'social scan links to canonical run');
fi_spine_ok(count($res['created']['signals'])===1 && count($res['created']['evidence'])===1, 'signals and evidence created');
fi_spine_ok(count($res['created']['insights'])===1 && count($res['created']['briefs'])===1, 'social insight and brief candidate created');
$bad = VES_Social_Signal_Intelligence_Service::run_scan(['mode'=>'post','post_url'=>'javascript:alert(1)'], ['workspace_id'=>42]);
fi_spine_ok(is_wp_error($bad), 'unsafe post URL blocked');
$discard = VES_Social_Signal_Mapper::normalize_row(['caption'=>'x','relevance_label'=>'nonsense']);
fi_spine_ok($discard['relevance_label']==='weak', 'unknown quality label becomes weak');
fi_spine_finish('v0.5.0 Social Signal Scan checks');
