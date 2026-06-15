<?php
require_once __DIR__ . '/test-fidtf-v0339-full-live-openai-network-diagnostics.php';

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$bridge_src = file_get_contents($root . '/includes/class-fidtf-ai-bridge.php');
$readme_path = $root . '/README.md';
$readme = is_readable($readme_path) ? file_get_contents($readme_path) : '';

// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: plugin header bumped to v0.3.40
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: FIDTF_VERSION bumped to v0.3.40
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: lineage marker records v0.3.40
ok(strpos($bridge_src, 'openai_smoke_response_schema') !== false, 'OpenAI smoke test declares JSON schema response format');
ok(strpos($bridge_src, "'type' => 'json_schema'") !== false, 'OpenAI smoke test requests Responses API json_schema output');
ok(strpos($bridge_src, "'store' => false") !== false, 'OpenAI smoke test disables response storage');
ok(strpos($bridge_src, 'request_key_ignored') !== false, 'OpenAI smoke test ignores request-body API keys');
ok(strpos($bridge_src, 'openai_api_key_meta') !== false, 'OpenAI runtime reports safe key source metadata');
// [v1.1.0 archived] removed obsolete standalone-addon README version-snapshot assertion: 'README documents v0.3.40'.
// The unified FIIS v1.1.0 package ships no per-module deep-trend-finder/README.md; the DTF version
// contract is FIDTF_VERSION (0.3.53) in the suite bootstrap, already asserted by the current-contract tests.

$status = FIDTF_AI_Bridge::openai_runtime_status();
ok(isset($status['key_source']) && !isset($status['api_key']) && !isset($status['key']), 'runtime status exposes safe key source without raw key');
ok(($status['response_format'] ?? '') === 'json_schema', 'runtime status reports json_schema response format');
$smoke = FIDTF_AI_Bridge::run_openai_smoke_test(['openai_api_key' => 'sk-proj-leaked-demo-key-should-be-ignored', 'evidence' => [['title' => 'SEO demand rising']]]);
ok(($smoke['ok'] ?? true) === false, 'smoke test still fails safely without server-side key even if request body includes a key');
ok(strpos(json_encode($smoke), 'sk-proj-leaked') === false, 'request-body key is never echoed in smoke output');

fwrite(STDOUT, "FIDTF v0.3.40 live OpenAI JSON-schema smoke-test hardening checks passed: {$checks} / {$checks}\n");
