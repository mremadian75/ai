<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$GLOBALS['__fi_admin'] = true; $GLOBALS['__fi_logged_in'] = true; $GLOBALS['__fi_transients'] = [];
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['__fi_admin']); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['__fi_logged_in']); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!function_exists('get_transient')) { function get_transient($k){ return $GLOBALS['__fi_transients'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k,$v,$ttl=0){ $GLOBALS['__fi_transients'][$k]=$v; return true; } }
if (!function_exists('rest_ensure_response')) { function rest_ensure_response($d){ return $d; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 9200; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-settings-service.php','includes/class-ves-provider-replay-guard.php','includes/class-ves-provider-rate-limit-service.php','includes/class-ves-provider-callback-auth-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php','includes/class-ves-decision-report-service.php','includes/class-ves-canonical-rest-controller.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
class FI_Test_Request_V80_Fixture { private $p,$h,$b,$r; public function __construct($p,$h=[],$b='',$r='/fi/v1/provider/social/ingest'){ $this->p=$p; $this->h=[]; foreach($h as $k=>$v){ $this->h[strtolower($k)]=$v; } $this->b=$b; $this->r=$r; } public function get_param($k){ return $this->p[$k] ?? null; } public function get_params(){ return $this->p; } public function get_json_params(){ return $this->p; } public function get_body(){ return $this->b; } public function get_header($k){ return $this->h[strtolower($k)] ?? ''; } public function get_route(){ return $this->r; } }
function fi_fixture_payload($name){ $p = __DIR__ . '/fixtures/provider/' . $name; $d = json_decode(file_get_contents($p), true); return is_array($d) ? $d : []; }
function fi_fixture_request(array $payload, string $secret, string $idempotency, string $provider, string $route){ $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE); $headers = VES_Provider_Callback_Auth_Service::sign_body($body, $secret); $headers['X-FI-Provider-Key'] = $provider; $headers['X-FI-Idempotency-Key'] = $idempotency; return new FI_Test_Request_V80_Fixture($payload, $headers, $body, $route); }
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Fixture Callbacks', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
VES_Provider_Settings_Service::set_environment('staging');
VES_Provider_Settings_Service::set('social_signal','approved_social_provider',['enabled'=>true,'auth_mode'=>'hmac_signature','secret'=>'fi_fixture_social_secret','max_callbacks_per_hour'=>500,'max_rows_per_callback'=>10]);
VES_Provider_Settings_Service::set('aeo_answer','approved_aeo_capture',['enabled'=>true,'auth_mode'=>'hmac_signature','secret'=>'fi_fixture_aeo_secret','max_callbacks_per_hour'=>500,'max_rows_per_callback'=>10]);
VES_Provider_Settings_Service::set('creative_asset_analysis','manual_creative_analysis',['enabled'=>true,'auth_mode'=>'hmac_signature','secret'=>'fi_fixture_creative_secret','max_callbacks_per_hour'=>500,'max_rows_per_callback'=>10]);
$run_social = VES_Run_Service::create_run(['workspace_id'=>$ws,'run_type'=>'social_scan','trigger_type'=>'provider_callback','idempotency_key'=>'fixture-social-run']);
$social = fi_fixture_payload('social-mixed-batch.json'); $social['run_id'] = $run_social;
$res = VES_Canonical_REST_Controller::provider_social_ingest(fi_fixture_request($social, 'fi_fixture_social_secret', 'fixture-social-mixed-1', 'approved_social_provider', '/fi/v1/provider/social/ingest'));
fi_spine_ok(is_array($res) && $res['accepted_count'] === 1 && $res['rejected_count'] === 1, 'signed social mixed fixture accepted/rejected expected rows');
$dup = VES_Canonical_REST_Controller::provider_social_ingest(fi_fixture_request($social, 'fi_fixture_social_secret', 'fixture-social-mixed-1', 'approved_social_provider', '/fi/v1/provider/social/ingest'));
fi_spine_ok(is_wp_error($dup), 'fixture callback idempotency replay blocked at endpoint');
$logs = VES_Run_Log_Service::list_for_run($ws, $run_social);
fi_spine_ok(count($logs) >= 3, 'fixture ingestion writes run timeline logs');
$report = VES_Canonical_REST_Controller::get_run_report(new FI_Test_Request_V80_Fixture(['id'=>$run_social]));
fi_spine_ok(is_array($report) && isset($report['report']['counts']['signals']) && $report['shareable'] === false, 'fixture decision report output is private and includes counts');
$run_aeo = VES_Run_Service::create_run(['workspace_id'=>$ws,'run_type'=>'aeo_gap','trigger_type'=>'provider_callback','idempotency_key'=>'fixture-aeo-run']);
$aeo = fi_fixture_payload('aeo-valid-gap.json'); $aeo['run_id'] = $run_aeo;
$aeo_res = VES_Canonical_REST_Controller::provider_aeo_ingest(fi_fixture_request($aeo, 'fi_fixture_aeo_secret', 'fixture-aeo-valid-1', 'approved_aeo_capture', '/fi/v1/provider/aeo/ingest'));
fi_spine_ok(is_array($aeo_res) && $aeo_res['accepted_count'] === 1 && count($aeo_res['created']['briefs']) === 1, 'signed AEO fixture creates corrective brief');
$run_creative = VES_Run_Service::create_run(['workspace_id'=>$ws,'run_type'=>'creative_analysis','trigger_type'=>'provider_callback','idempotency_key'=>'fixture-creative-run']);
$creative = fi_fixture_payload('creative-invalid-token-leak.json'); $creative['run_id'] = $run_creative;
$creative_res = VES_Canonical_REST_Controller::provider_creative_ingest(fi_fixture_request($creative, 'fi_fixture_creative_secret', 'fixture-creative-token-1', 'manual_creative_analysis', '/fi/v1/provider/creative/ingest'));
fi_spine_ok(is_array($creative_res) && $creative_res['accepted_count'] === 1, 'signed creative fixture accepted');
fi_spine_ok(strpos(json_encode($creative_res), 'fixturesupersecret') === false && strpos(json_encode(VES_Run_Log_Service::list_for_run($ws, $run_creative)), 'fixturetoken') === false, 'creative fixture token leak redacted from response and run logs');
fi_spine_finish('v0.8.0 provider callback fixture suite checks');
