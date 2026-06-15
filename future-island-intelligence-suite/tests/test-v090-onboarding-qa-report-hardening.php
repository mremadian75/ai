<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$GLOBALS['__fi_admin'] = true; $GLOBALS['__fi_logged_in'] = true;
if (!function_exists('current_user_can')) { function current_user_can($cap){ return !empty($GLOBALS['__fi_admin']); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return !empty($GLOBALS['__fi_logged_in']); } }
if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 9400; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-settings-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-run-diagnostics-service.php','includes/class-ves-operator-qa-service.php','includes/class-ves-workspace-onboarding-service.php','includes/class-ves-decision-report-service.php'
] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name'=>'Onboarding QA','created_by_user_id'=>42]); VES_Workspace_Membership_Store::add_member($ws,42,'owner');
$state1 = VES_Workspace_Onboarding_Service::state($ws);
fi_spine_ok($state1['onboarding_state'] === 'in_progress' || $state1['onboarding_state'] === 'ready_for_first_run', 'initial onboarding state returned');
$state2 = VES_Workspace_Onboarding_Service::save_profile(['brand_name'=>'Future Brand','brand_url'=>'https://example.com','market'=>'Spain','language'=>'es','competitors'=>['A','B'],'provider_mode'=>'signed_callback_simulator','usage_mode'=>'zero_cost_beta','default_reason_code'=>'weak_evidence','create_first_run'=>1], $ws);
fi_spine_ok(is_array($state2) && in_array($state2['onboarding_state'], ['ready_for_first_run','first_run_created'], true), 'onboarding v2 profile saved with state progression');
fi_spine_ok(($state2['brand_profile']['provider_mode'] ?? '') === 'signed_callback_simulator', 'provider mode saved');
fi_spine_ok(($state2['brand_profile']['usage_mode'] ?? '') === 'zero_cost_beta', 'usage mode saved');
fi_spine_ok((int)($state2['first_run_id'] ?? 0) > 0, 'first run created without external provider auto-call');
$qa = VES_Operator_QA_Service::checklist($ws, (int)$state2['first_run_id']);
fi_spine_ok(is_array($qa['items']) && count($qa['items']) >= 5, 'operator QA checklist generated');
VES_Operator_QA_Service::save_note('no_secrets_visible', '<script>x</script> checked', true);
$qa2 = VES_Operator_QA_Service::checklist($ws, (int)$state2['first_run_id']);
$json = json_encode($qa2);
fi_spine_ok(strpos($json, '<script>') === false, 'operator note sanitized');
VES_Run_Log_Service::append($ws,(int)$state2['first_run_id'],'info','provider_ingestion','provider_result_received','Received safe rows', ['bearer'=>'Bearer secret-token-to-redact']);
$report = VES_Decision_Report_Service::build_for_run($ws, (int)$state2['first_run_id']);
$md = VES_Decision_Report_Service::render_markdown($report);
$html = VES_Decision_Report_Service::render_private_html($report);
fi_spine_ok(strpos($md, '## Evidence appendix') !== false, 'markdown includes evidence appendix');
fi_spine_ok(strpos($html, 'Private beta') !== false, 'html preview includes private beta label');
fi_spine_ok(strpos(json_encode($report), 'secret-token-to-redact') === false, 'report export redacted');
fi_spine_ok(strpos($md, 'Memory is context, not evidence') !== false, 'memory not counted as evidence');
fi_spine_finish('v0.9.0 onboarding QA and report hardening checks');
