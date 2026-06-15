<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return true; } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return false; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!function_exists('rest_ensure_response')) { function rest_ensure_response($d){ return $d; } }
$GLOBALS['routes'] = [];
if (!function_exists('register_rest_route')) { function register_rest_route($ns, $route, $args){ $GLOBALS['routes'][$ns . $route] = $args; return true; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 8000; public static function record($args){ return ++self::$n; } } }
class FI_Test_Request_V70 { private $p; public function __construct($p){ $this->p = $p; } public function get_param($k){ return $this->p[$k] ?? null; } public function get_params(){ return $this->p; } public function get_json_params(){ return $this->p; } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php','includes/class-ves-decision-report-service.php','includes/class-ves-private-beta-readiness-service.php','includes/class-ves-canonical-rest-controller.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'REST Provider', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
VES_Canonical_REST_Controller::register_routes();
foreach (['fi/v1/provider/contracts','fi/v1/provider/social/ingest','fi/v1/provider/aeo/ingest','fi/v1/provider/creative/ingest','fi/v1/creative/taxonomy','fi/v1/runs/(?P<id>\\d+)/report','fi/v1/runs/(?P<id>\\d+)/report.md'] as $route) {
    fi_spine_ok(isset($GLOBALS['routes'][$route]), 'provider/report REST route registered: ' . $route);
}
$contracts = VES_Canonical_REST_Controller::provider_contracts(new FI_Test_Request_V70([]));
fi_spine_ok(is_array($contracts) && count($contracts['contracts']) >= 5 && $contracts['providers_write_directly_to_db'] === false, 'provider contracts REST response is safe');
$tax = VES_Canonical_REST_Controller::creative_taxonomy(new FI_Test_Request_V70([]));
fi_spine_ok(in_array('hook_type', $tax['dimensions'], true), 'creative taxonomy REST exposes dimensions');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'social_scan', 'trigger_type' => 'api', 'idempotency_key' => 'rest-provider-run']);
$res = VES_Canonical_REST_Controller::provider_social_ingest(new FI_Test_Request_V70(['run_id' => $run, 'provider_key' => 'approved_social_provider', 'provider_run_id' => 'rest-ext', 'idempotency_key' => 'rest-ingest-social', 'rows' => [[
    'platform' => 'linkedin', 'source_url' => 'https://www.linkedin.com/posts/rest', 'caption' => 'Evidence-first workflows are replacing generic AI drafts.', 'relevance_label' => 'direct_match', 'authorization' => 'Bearer secretsecretsecret'
]]]));
fi_spine_ok(is_array($res) && $res['accepted_count'] === 1 && empty($res['raw_payload_exposed']), 'REST social ingestion accepted and does not expose raw payload');
fi_spine_ok(strpos(json_encode($res), 'secretsecret') === false, 'REST provider response redacts token');
$timeline = VES_Canonical_REST_Controller::run_timeline(new FI_Test_Request_V70(['id' => $run]));
fi_spine_ok(isset($timeline['run_logs']) && count($timeline['run_logs']) >= 1 && isset($timeline['timeline']), 'run timeline REST includes run logs and grouped timeline');
$report = VES_Canonical_REST_Controller::get_run_report(new FI_Test_Request_V70(['id' => $run]));
fi_spine_ok(is_array($report) && $report['shareable'] === false && isset($report['report']['counts']['run_logs']), 'run report REST is private and includes run logs');
$md = VES_Canonical_REST_Controller::get_run_report_markdown(new FI_Test_Request_V70(['id' => $run]));
fi_spine_ok(is_array($md) && $md['format'] === 'markdown' && strpos($md['content'], 'Decision Report') !== false, 'markdown report endpoint works');
$export = VES_Canonical_REST_Controller::export_report(new FI_Test_Request_V70(['run_id' => $run, 'format' => 'html']));
fi_spine_ok(is_array($export) && $export['format'] === 'html' && $export['shareable'] === false, 'private report export supports html without public share link');
$ready = VES_Private_Beta_Readiness_Service::snapshot();
fi_spine_ok(!empty($ready['secret_redaction']) && !empty($ready['provider_ingestion_store']) && $ready['provider_contracts'] >= 5, 'private beta readiness snapshot covers provider hardening');
fi_spine_finish('v0.7.0 REST report beta readiness checks');
