<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return true; } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return false; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 4000; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-brief-os-service.php','includes/class-ves-playbook-input-validator.php','includes/class-ves-social-signal-mapper.php','includes/class-ves-social-signal-intelligence-service.php','includes/class-ves-aeo-action-service.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Provider Ready Workspace', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'operator');
$social = VES_Social_Signal_Intelligence_Service::run_scan([
    'mode' => 'topic',
    'query' => 'evidence backed marketing intelligence',
    'platforms' => ['linkedin'],
    'market' => 'Spain',
    'language' => 'en',
    'provider_rows' => [[
        'platform' => 'linkedin',
        'caption' => 'Teams want evidence before AI content #strategy',
        'relevance_label' => 'direct_match',
        'metrics' => ['likes' => 12],
        'provider_ref' => 'manual-row-1'
    ]],
    'idempotency_key' => 'social-provider-ready'
], ['workspace_id' => $ws, 'idempotency_key' => 'social-provider-ready']);
fi_spine_ok(is_array($social) && !empty($social['run_id']), 'provider-ready social scan creates canonical run');
fi_spine_ok(($social['provider_ready'] ?? false) === true && ($social['provider_called'] ?? true) === false, 'social foundation is provider-ready but no provider called');
fi_spine_ok(count($social['created']['signals'] ?? []) >= 1 && count($social['created']['evidence'] ?? []) >= 1, 'social provider row normalized into signal and evidence');
$signal = VES_Intelligence_Store::get_signal((int) $social['created']['signals'][0]);
fi_spine_ok(($signal['metadata']['social_signal']['provider_ref'] ?? '') === 'manual-row-1', 'social provider ref stored without token exposure');
fi_spine_ok(strpos(json_encode($signal), 'sk-') === false, 'social signal does not expose provider tokens');
$aeo = VES_AEO_Action_Service::run_prompt_set([
    'prompts' => ["Which tools connect market evidence to execution briefs?"],
    'answers' => [[
        'prompt' => 'Which tools connect market evidence to execution briefs?',
        'answer' => 'Manual answer mentions Future Island as an evidence-to-brief workspace.',
        'mentioned_brand' => true,
        'cited_sources' => ['manual-reference']
    ]],
    'market' => 'Spain',
    'language' => 'en'
], ['workspace_id' => $ws, 'idempotency_key' => 'aeo-provider-ready']);
fi_spine_ok(is_array($aeo) && !empty($aeo['run_id']), 'AEO prompt set creates canonical run');
fi_spine_ok(($aeo['provider_called'] ?? true) === false, 'AEO prompt set is provider-ready without provider call');
fi_spine_ok(count($aeo['created']['signals'] ?? []) >= 1 && count($aeo['created']['evidence'] ?? []) >= 1 && count($aeo['created']['briefs'] ?? []) >= 1, 'AEO creates signal evidence insight and corrective brief');
$run = VES_Run_Service::get_run((int) $aeo['run_id']);
fi_spine_ok(($run['run_type'] ?? '') === 'aeo_gap' && (($run['metadata']['provider_ready'] ?? false) || ($run['metadata']['provider_ready'] ?? '') === '1'), 'AEO run tagged as provider-ready gap action');
$edges = VES_Decision_Edge_Service::list_edges_for_run($ws, (int) $aeo['run_id']);
fi_spine_ok(count($edges) >= 3, 'AEO decision edges created for lineage');
fi_spine_finish('v0.6.0 provider-ready Social/AEO foundation checks');
