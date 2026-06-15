<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$GLOBALS['__fi_admin'] = true;
$GLOBALS['__fi_logged_in'] = true;
$GLOBALS['__fi_transients'] = [];
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['__fi_admin']); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['__fi_logged_in']); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!function_exists('get_transient')) { function get_transient($k){ return $GLOBALS['__fi_transients'][$k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $ttl = 0){ $GLOBALS['__fi_transients'][$k] = $v; return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k){ unset($GLOBALS['__fi_transients'][$k]); return true; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 9000; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-settings-service.php','includes/class-ves-provider-replay-guard.php','includes/class-ves-provider-rate-limit-service.php','includes/class-ves-provider-callback-auth-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
class FI_Test_Request_V80 { private $p,$h,$b,$r; public function __construct($p,$h=[],$b='',$r='/fi/v1/provider/social/ingest'){ $this->p=$p; $this->h=[]; foreach($h as $k=>$v){ $this->h[strtolower($k)]=$v; } $this->b=$b; $this->r=$r; } public function get_param($k){ return $this->p[$k] ?? null; } public function get_params(){ return $this->p; } public function get_json_params(){ return $this->p; } public function get_body(){ return $this->b; } public function get_header($k){ return $this->h[strtolower($k)] ?? ''; } public function get_route(){ return $this->r; } }
function fi_v80_body(array $payload){ return wp_json_encode($payload, JSON_UNESCAPED_UNICODE); }
function fi_v80_signed_request(array $payload, string $secret, string $idempotency, int $ts = null, string $family_route = '/fi/v1/provider/social/ingest', string $provider = 'approved_social_provider') {
    $body = fi_v80_body($payload);
    $signed = VES_Provider_Callback_Auth_Service::sign_body($body, $secret, $ts);
    $headers = array_merge($signed, ['X-FI-Provider-Key' => $provider, 'X-FI-Idempotency-Key' => $idempotency]);
    return new FI_Test_Request_V80($payload, $headers, $body, $family_route);
}
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Callback Security', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'social_scan', 'trigger_type' => 'provider_callback', 'idempotency_key' => 'callback-run']);
VES_Provider_Settings_Service::set_environment('staging');
$secret = 'fi_callback_secret_123456789';
VES_Provider_Settings_Service::set('social_signal', 'approved_social_provider', ['enabled' => true, 'auth_mode' => 'hmac_signature', 'secret' => $secret, 'max_callbacks_per_hour' => 50, 'max_rows_per_callback' => 2]);
$payload = ['run_id' => $run, 'provider_key' => 'approved_social_provider', 'rows' => [['platform' => 'linkedin', 'caption' => 'Evidence before generation', 'api_key' => 'sk-hiddenhiddenhidden']]];
$missing = new FI_Test_Request_V80($payload, ['X-FI-Provider-Key'=>'approved_social_provider','X-FI-Idempotency-Key'=>'miss-1'], fi_v80_body($payload));
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($missing, 'social_signal')), 'missing signature blocked');
$bad = fi_v80_signed_request($payload, 'wrong_secret', 'bad-1');
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($bad, 'social_signal')), 'bad signature blocked');
$stale = fi_v80_signed_request($payload, $secret, 'stale-1', time() - 9999);
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($stale, 'social_signal')), 'stale timestamp blocked');
$future = fi_v80_signed_request($payload, $secret, 'future-1', time() + 9999);
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($future, 'social_signal')), 'future timestamp blocked');
$wrong = fi_v80_signed_request($payload, $secret, 'wrong-family-1');
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($wrong, 'aeo_answer')), 'wrong provider family blocked');
VES_Provider_Settings_Service::set('social_signal', 'approved_social_provider', ['enabled' => false]);
$disabled = fi_v80_signed_request($payload, $secret, 'disabled-1');
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($disabled, 'social_signal')), 'disabled provider blocked');
VES_Provider_Settings_Service::set('social_signal', 'approved_social_provider', ['enabled' => true]);
$valid = fi_v80_signed_request($payload, $secret, 'valid-1');
$accepted = VES_Provider_Callback_Auth_Service::authenticate($valid, 'social_signal');
fi_spine_ok(is_array($accepted) && $accepted['workspace_id'] === $ws && $accepted['run_id'] === $run, 'valid HMAC accepted and scoped to run workspace');
$replay = fi_v80_signed_request($payload, $secret, 'valid-1');
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate($replay, 'social_signal')), 'replayed idempotency key blocked');
VES_Provider_Settings_Service::set('social_signal', 'approved_social_provider', ['max_callbacks_per_hour' => 1]);
$GLOBALS['__fi_transients'] = [];
$first = VES_Provider_Callback_Auth_Service::authenticate(fi_v80_signed_request($payload, $secret, 'rate-1'), 'social_signal');
$second = VES_Provider_Callback_Auth_Service::authenticate(fi_v80_signed_request($payload, $secret, 'rate-2'), 'social_signal');
fi_spine_ok(is_array($first) && is_wp_error($second), 'rate limit blocks too many requests');
$too_many = $payload; $too_many['rows'] = [['platform'=>'linkedin'], ['platform'=>'linkedin'], ['platform'=>'linkedin']];
$GLOBALS['__fi_transients'] = [];
fi_spine_ok(is_wp_error(VES_Provider_Callback_Auth_Service::authenticate(fi_v80_signed_request($too_many, $secret, 'rows-1'), 'social_signal')), 'too many rows blocked');
$logs = VES_Run_Log_Service::list_for_run($ws, $run);
$json = json_encode($logs);
fi_spine_ok(strpos($json, 'sk-hidden') === false && strpos($json, $secret) === false && strpos($json, 'sha256=') === false, 'raw body, signature and secret never logged');
fi_spine_finish('v0.8.0 provider callback auth checks');
