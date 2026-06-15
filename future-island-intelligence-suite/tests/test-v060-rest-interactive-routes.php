<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return true; } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return false; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!function_exists('rest_ensure_response')) { function rest_ensure_response($d){ return $d; } }
$GLOBALS['routes'] = [];
if (!function_exists('register_rest_route')) { function register_rest_route($ns, $route, $args){ $GLOBALS['routes'][$ns . $route] = $args; return true; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 3000; public static function record($args){ return ++self::$n; } } }
class FI_Test_Request { private $p; public function __construct($p){ $this->p = $p; } public function get_param($k){ return $this->p[$k] ?? null; } public function get_params(){ return $this->p; } public function get_json_params(){ return $this->p; } }

foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-brief-os-service.php','includes/class-ves-draft-service.php','includes/class-ves-review-service.php','includes/class-ves-outcome-store.php','includes/class-ves-outcome-service.php','includes/class-ves-pattern-observation-store.php','includes/class-ves-pattern-observation-service.php','includes/class-ves-command-room-action-service.php','includes/class-ves-decision-report-service.php','includes/class-ves-aeo-action-service.php','includes/class-ves-canonical-rest-controller.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }

VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Outcome_Store::create_table(true); VES_Pattern_Observation_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'REST Workspace', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
VES_Canonical_REST_Controller::register_routes();
foreach (['fi/v1/briefs/(?P<id>\\d+)/review','fi/v1/drafts/(?P<id>\\d+)/review','fi/v1/drafts/(?P<id>\\d+)/memory','fi/v1/memory','fi/v1/memory/(?P<id>\\d+)/review','fi/v1/patterns','fi/v1/reports/export','fi/v1/aeo/prompt-set'] as $route) {
    fi_spine_ok(isset($GLOBALS['routes'][$route]), 'interactive REST route registered: ' . $route);
    fi_spine_ok(is_callable($GLOBALS['routes'][$route][0]['permission_callback'] ?? null), 'route permission callback registered: ' . $route);
}
fi_spine_ok(VES_Canonical_REST_Controller::can_access() === true, 'membership-based REST access allowed');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'research', 'trigger_type' => 'manual', 'idempotency_key' => 'rest-run']); VES_Run_Service::mark_running($run);
$brief = VES_Brief_OS_Service::create_manual_brief(['workspace_id' => $ws, 'run_id' => $run, 'title' => 'REST brief', 'objective' => 'Create an evidence-first draft.', 'channel' => 'linkedin', 'format' => 'social_post', 'source_evidence' => [['type' => 'manual', 'id' => 'rest-source']]]);
$ready = VES_Canonical_REST_Controller::review_brief(new FI_Test_Request(['id' => $brief, 'decision' => 'ready', 'reason_code' => 'good_angle']));
fi_spine_ok(is_array($ready) && (($ready['result']['status'] ?? '') === 'ready'), 'REST brief mark-ready action works');
$draftId = VES_Draft_Service::create_draft_from_brief($brief, ['draft_mode' => 'deterministic_preview']);
$draftReview = VES_Canonical_REST_Controller::review_draft(new FI_Test_Request(['id' => $draftId, 'decision' => 'primary']));
fi_spine_ok(is_array($draftReview) && !empty($draftReview['result']['metadata']['is_primary']), 'REST draft mark-primary action works');
$memory = VES_Canonical_REST_Controller::create_memory_from_draft(new FI_Test_Request(['id' => $draftId, 'memory_text' => 'REST-created proof-first memory candidate.']));
$memoryId = is_array($memory) ? (int) ($memory['result'] ?? 0) : 0;
fi_spine_ok($memoryId > 0, 'REST draft memory candidate creation works');
$memoryList = VES_Canonical_REST_Controller::list_memory(new FI_Test_Request([]));
fi_spine_ok(is_array($memoryList) && !empty($memoryList['memory_is_context_not_evidence']) && count($memoryList['memory']) >= 1, 'REST memory list labels context not evidence');
$memoryReview = VES_Canonical_REST_Controller::review_memory(new FI_Test_Request(['id' => $memoryId, 'decision' => 'activate', 'reason_code' => 'useful_pattern']));
fi_spine_ok(is_array($memoryReview) && (($memoryReview['result']['status'] ?? '') === 'active'), 'REST memory activation works');
$patterns = VES_Canonical_REST_Controller::list_patterns(new FI_Test_Request([]));
fi_spine_ok(is_array($patterns) && isset($patterns['patterns']), 'REST patterns list works');
$export = VES_Canonical_REST_Controller::export_report(new FI_Test_Request(['run_id' => $run]));
fi_spine_ok(is_array($export) && $export['shareable'] === false && isset($export['report']), 'REST report export is private structured payload');
$aeo = VES_Canonical_REST_Controller::run_aeo_prompt_set(new FI_Test_Request(['prompts' => ['Who is visible for evidence-backed marketing briefs?'], 'answers' => [['prompt' => 'Who is visible?', 'answer' => 'Manual answer mentions Future Island with no private scraping.', 'mentioned_brand' => true]], 'market' => 'Spain', 'language' => 'en', 'idempotency_key' => 'rest-aeo']));
fi_spine_ok(is_array($aeo) && !empty($aeo['run_id']) && $aeo['provider_called'] === false, 'REST AEO prompt-set creates provider-ready run without provider call');

fi_spine_finish('v0.6.0 interactive REST route checks');
