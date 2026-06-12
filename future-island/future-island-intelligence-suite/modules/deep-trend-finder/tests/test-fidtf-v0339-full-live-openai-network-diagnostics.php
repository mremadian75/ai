<?php
require_once __DIR__ . '/test-fidtf-v0338-full-live-gpt-preflight-trends-summary-and-relevance-hardening.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$settings_src = file_get_contents($root . '/includes/class-fidtf-settings.php');
$rest_src = file_get_contents($root . '/includes/class-fidtf-rest-controller.php');

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.40
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.40
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.40
ok(strpos($bridge_src, 'openai_runtime_status') !== false, 'AI bridge exposes OpenAI runtime status');
ok(strpos($bridge_src, 'run_openai_smoke_test') !== false, 'AI bridge exposes admin smoke test method');
ok(strpos($bridge_src, 'https://api.openai.com/v1/responses') !== false, 'OpenAI smoke test targets Responses API endpoint');
ok(strpos($settings_src, 'openai_runtime') !== false, 'preflight reports OpenAI runtime readiness without exposing a key');
ok(strpos($rest_src, '/admin/openai-smoke-test') !== false, 'admin-only REST smoke test route registered');

$status = FIDTF_AI_Bridge::openai_runtime_status();
ok(isset($status['configured']) && isset($status['transport_ready']) && isset($status['can_live_test']), 'OpenAI runtime status has expected safe fields');
ok(!isset($status['api_key']) && !isset($status['key']), 'OpenAI runtime status never returns raw key fields');
$smoke = FIDTF_AI_Bridge::run_openai_smoke_test(['evidence' => [['title' => 'test signal']]]);
ok(($smoke['ok'] ?? true) === false, 'OpenAI smoke test fails safely without server-side key in local tests');
ok(in_array(($smoke['status'] ?? ''), ['not_configured', 'transport_unavailable', 'transport_error'], true), 'OpenAI smoke test reports a safe failure status without leaking secrets');

fwrite(STDOUT, "FIDTF v0.3.40 full live actor and OpenAI network diagnostic checks passed: {$checks} / {$checks}\n");
