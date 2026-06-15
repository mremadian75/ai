<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
foreach (['includes/class-ves-secret-redactor.php','includes/class-ves-provider-contract-service.php','includes/class-ves-run-log-service.php'] as $f) { require_once dirname(__DIR__) . '/' . $f; }
VES_Run_Log_Service::create_table(true);
$redacted = VES_Secret_Redactor::redact(['authorization' => 'Bearer sk-supersecret123456789', 'url' => 'https://example.com/path?api_key=abc123456789&ok=1', 'nested' => ['token' => 'apify_api_token_xyz987654321', 'public_url' => 'https://example.com/open']]);
fi_spine_ok($redacted['authorization'] === '[redacted]', 'authorization key redacted');
fi_spine_ok(strpos($redacted['url'], 'api_key=[redacted]') !== false && strpos($redacted['url'], 'ok=1') !== false, 'secret URL param redacted without destroying public URL');
fi_spine_ok($redacted['nested']['token'] === '[redacted]', 'nested token redacted');
fi_spine_ok($redacted['nested']['public_url'] === 'https://example.com/open', 'public URL preserved');
fi_spine_ok(strpos(VES_Secret_Redactor::redact_string('Authorization: Bearer abcdefghijklmnop'), '[redacted]') !== false, 'bearer string redacted');
$contract = VES_Provider_Contract_Service::assert_contract('social_signal', 'approved_social_provider');
fi_spine_ok(is_array($contract) && $contract['schema_version'] === 'v1', 'known social provider contract registered');
fi_spine_ok(is_wp_error(VES_Provider_Contract_Service::assert_contract('social_signal', 'unknown_provider')), 'unknown provider blocked');
fi_spine_ok(is_wp_error(VES_Provider_Contract_Service::assert_contract('social_signal', 'disabled_social_provider')), 'disabled provider blocked');
$id = VES_Run_Log_Service::append(11, 22, 'info', 'provider', 'provider_result_received', 'Token sk-secretsecretsecret received', ['workspace_id' => 11, 'api_key' => 'sk-testkeytestkey', 'normal' => 'visible']);
fi_spine_ok($id > 0, 'run log appended');
$rows = VES_Run_Log_Service::list_for_run(11, 22);
fi_spine_ok(count($rows) === 1 && $rows[0]['event_type'] === 'provider_result_received', 'run log list scoped by workspace/run');
fi_spine_ok(strpos(json_encode($rows), 'sk-test') === false && strpos(json_encode($rows), '[redacted]') !== false, 'run log redacts secrets in message/context');
$timeline = VES_Run_Log_Service::timeline_for_run(11, 22);
fi_spine_ok(count($timeline) === 1 && $timeline[0]['count'] === 1, 'timeline grouped by event type');
fi_spine_finish('v0.7.0 redactor/provider contract/run-log checks');
