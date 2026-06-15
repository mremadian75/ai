<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(){ return true; } }
if (!function_exists('current_user_can')) { function current_user_can($cap){ return false; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value){ return $value; } }
if (!class_exists('VES_AI_Usage_Tracker')) { final class VES_AI_Usage_Tracker { public static $n = 7000; public static function record($args){ return ++self::$n; } } }
foreach ([
'includes/class-ves-workspace-membership-store.php','includes/class-ves-workspace-service.php','includes/class-ves-permission-service.php','includes/class-ves-run-store.php','includes/class-ves-run-service.php','includes/class-ves-decision-edge-store.php','includes/class-ves-decision-edge-service.php','includes/class-ves-review-decision-ledger.php','includes/class-ves-intelligence-store.php','includes/class-ves-usage-service.php','includes/class-ves-secret-redactor.php','includes/class-ves-run-log-service.php','includes/class-ves-provider-contract-service.php','includes/class-ves-provider-result-validator.php','includes/class-ves-creative-taxonomy-service.php','includes/class-ves-provider-ingestion-store.php','includes/class-ves-provider-result-mapper.php','includes/class-ves-provider-run-service.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Workspace_Membership_Store::create_tables(true); VES_Run_Store::create_table(true); VES_Decision_Edge_Store::create_table(true); VES_Review_Decision_Ledger::create_table(true); VES_Intelligence_Store::create_tables(true); VES_Run_Log_Service::create_table(true); VES_Provider_Ingestion_Store::create_table(true);
$ws = VES_Workspace_Membership_Store::create_workspace(['name' => 'Provider Sprint', 'created_by_user_id' => 42]); VES_Workspace_Membership_Store::add_member($ws, 42, 'owner');
$run = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'social_scan', 'trigger_type' => 'api', 'idempotency_key' => 'provider-run-social']);
$social = VES_Provider_Run_Service::ingest('social_signal', ['workspace_id' => $ws, 'run_id' => $run, 'provider_key' => 'approved_social_provider', 'provider_run_id' => 'ext-social-1', 'idempotency_key' => 'ingest-social-1', 'rows' => [[
    'platform' => 'linkedin', 'source_url' => 'https://www.linkedin.com/posts/example', 'caption' => 'Teams want evidence before AI content.', 'relevance_label' => 'direct_match', 'metrics' => ['likes' => 12], 'provider_ref' => 'row-1', 'api_key' => 'sk-secretsecretsecret'
], ['platform' => 'linkedin', 'caption' => 'Private row', 'logged_in' => true]]]);
fi_spine_ok(is_array($social) && $social['accepted_count'] === 1 && $social['rejected_count'] === 1, 'social provider ingestion accepts valid row and rejects private/logged-in row');
fi_spine_ok(count($social['created']['signals']) === 1 && count($social['created']['evidence']) === 1 && count($social['created']['insights']) === 1, 'social provider row creates signal/evidence/insight');
fi_spine_ok(strpos(json_encode($social), 'sk-secret') === false, 'social provider response redacts token');
$dup = VES_Provider_Run_Service::ingest('social_signal', ['workspace_id' => $ws, 'run_id' => $run, 'provider_key' => 'approved_social_provider', 'idempotency_key' => 'ingest-social-1', 'rows' => []]);
fi_spine_ok(!empty($dup['already_processed']), 'provider ingestion idempotency prevents duplicate processing');
$logs = VES_Run_Log_Service::list_for_run($ws, $run);
fi_spine_ok(count($logs) >= 3 && strpos(json_encode($logs), 'sk-secret') === false, 'provider ingestion writes redacted run logs');
$edges = VES_Decision_Edge_Service::list_edges_for_run($ws, $run);
fi_spine_ok(count($edges) >= 3, 'social ingestion writes decision edges');
$run2 = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'aeo_gap', 'trigger_type' => 'api', 'idempotency_key' => 'provider-run-aeo']);
$aeo = VES_Provider_Run_Service::ingest('aeo_answer', ['workspace_id' => $ws, 'run_id' => $run2, 'provider_key' => 'approved_aeo_capture', 'idempotency_key' => 'ingest-aeo-1', 'rows' => [[
    'prompt' => 'Which tool connects signals to briefs?', 'answer' => 'Future Island connects evidence to briefs.', 'brand_mentioned' => true, 'brand_position' => 2, 'sentiment' => 'positive', 'competitor_mentions' => ['Generic AI'], 'citations' => ['https://example.com']
]]]);
fi_spine_ok($aeo['accepted_count'] === 1 && count($aeo['created']['briefs']) === 1, 'AEO provider row creates corrective brief');
$brief = VES_Intelligence_Store::get_brief((int) $aeo['created']['briefs'][0]);
fi_spine_ok(is_array($brief) && ($brief['brief_type'] ?? '') === 'aeo_fix', 'AEO brief uses Brief OS aeo_fix type');
$run3 = VES_Run_Service::create_run(['workspace_id' => $ws, 'run_type' => 'creative_analysis', 'trigger_type' => 'api', 'idempotency_key' => 'provider-run-creative']);
$creative = VES_Provider_Run_Service::ingest('creative_asset_analysis', ['workspace_id' => $ws, 'run_id' => $run3, 'provider_key' => 'manual_creative_analysis', 'idempotency_key' => 'ingest-creative-1', 'rows' => [[
    'summary' => 'Strong risk-framed hook with editorial visual style.', 'platform' => 'linkedin', 'asset_type' => 'image', 'hook_type' => 'risk_framed', 'message_angle' => 'evidence_before_generation', 'cta_type' => 'book_demo'
]]]);
fi_spine_ok($creative['accepted_count'] === 1 && count($creative['created']['signals']) === 1, 'creative provider row creates creative signal');
$sig = VES_Intelligence_Store::get_signal((int) $creative['created']['signals'][0]);
fi_spine_ok(isset($sig['metadata']['creative_taxonomy']['hook_type']), 'creative taxonomy attached to signal metadata');
fi_spine_finish('v0.7.0 provider ingestion Social/AEO/Creative checks');
