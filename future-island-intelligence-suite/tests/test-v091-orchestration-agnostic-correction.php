<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$guide = $root . '/ORCHESTRATOR_AGNOSTIC_CALLBACK_GUIDE.md';
fi_spine_ok(is_file($guide), 'orchestrator agnostic callback guide exists');
$g = file_get_contents($guide);
foreach (['internal signed callback simulator','custom worker','cron job','Apify actor wrapper','Make/Zapier-style workflow','optional n8n example','future managed Future Island worker'] as $needle) {
    fi_spine_ok(strpos($g, $needle) !== false, 'guide lists approved callback source: ' . $needle);
}
fi_spine_ok(strpos($g, 'WordPress remains the canonical product shell, permission layer, review UI, and record layer.') !== false, 'guide states WordPress canonical role');
fi_spine_ok(strpos($g, 'External tools never write directly to canonical tables.') !== false, 'guide forbids direct canonical table writes');
fi_spine_ok(strpos($g, 'External tools return results only through authenticated WordPress endpoints.') !== false, 'guide requires authenticated WordPress endpoints');

$old_json = $root . '/integrations/n8n/future-island-provider-callback-template.json';
$optional_json = $root . '/integrations/examples/n8n/OPTIONAL_future-island-provider-callback-template.json';
$optional_readme = $root . '/integrations/examples/n8n/README_OPTIONAL_N8N_PROVIDER_CALLBACK.md';
fi_spine_ok(!file_exists($old_json), 'old mandatory n8n template path is not present');
fi_spine_ok(is_file($optional_json), 'optional n8n template exists under examples path');
fi_spine_ok(is_file($optional_readme), 'optional n8n readme exists under examples path');
$optional_raw = file_get_contents($optional_json) . file_get_contents($optional_readme);
fi_spine_ok(strpos($optional_raw, 'Optional n8n example. Future Island does not require n8n.') !== false, 'optional n8n docs explicitly say n8n is not required');
fi_spine_ok(strpos($optional_raw, 'write directly to WordPress database tables') !== false || strpos($optional_raw, 'write directly to canonical Future Island tables') !== false, 'integration docs prohibit direct DB writes');

$onboarding_src = file_get_contents($root . '/includes/class-ves-workspace-onboarding-service.php');
foreach (['manual_only','signed_callback_simulator','approved_external_orchestrator','optional_n8n_example','provider_disabled'] as $mode) {
    fi_spine_ok(strpos($onboarding_src, $mode) !== false, 'onboarding exposes provider mode ' . $mode);
}
fi_spine_ok(strpos($onboarding_src, "'provider_modes' => ['manual_only','signed_callback_simulator','approved_external_orchestrator','optional_n8n_example','provider_disabled']") !== false, 'n8n_callback is not exposed in the provider mode list');
fi_spine_ok(strpos($onboarding_src, 'Future Island does not require n8n') !== false, 'onboarding copy says n8n is optional');

$qa_src = file_get_contents($root . '/includes/class-ves-operator-qa-service.php');
foreach (['callback_simulator_available','fixture_dry_run_available','bad_signature_blocked','replay_blocked','invalid_private_fixture_rejected','optional_n8n_template_present'] as $qa_key) {
    fi_spine_ok(strpos($qa_src, $qa_key) !== false, 'QA checklist includes ' . $qa_key);
}
fi_spine_ok(strpos($qa_src, "'status' => true, 'evidence' => file_exists(dirname(__DIR__) . '/integrations/examples/n8n/OPTIONAL_future-island-provider-callback-template.json')") !== false, 'optional n8n check does not fail QA when absent');

$pack = file_get_contents($root . '/bin/build-staging-trial-evidence-pack.sh');
fi_spine_ok(strpos($pack, 'integrations/examples') !== false, 'evidence pack collects examples path');
fi_spine_ok(strpos($pack, 'copy_if_exists') !== false, 'evidence pack uses optional copy helper');
fi_spine_ok(strpos($pack, 'optional n8n example is collected only when present') !== false, 'evidence pack manifest explains optional n8n collection');

$all_docs = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = $file->getPathname();
    if (!preg_match('/\.(md|php|sh|json|txt)$/', $path)) { continue; }
    if (strpos($path, '/tests/') !== false) { continue; }
    $all_docs .= "\n--- {$path} ---\n" . file_get_contents($path);
}
foreach (['connect real n8n','configure real n8n credentials','configure n8n credentials','run n8n callbacks','required n8n','requires n8n'] as $bad) {
    fi_spine_ok(stripos($all_docs, $bad) === false, 'docs/code do not contain mandatory n8n phrase: ' . $bad);
}
fi_spine_finish('v0.9.1 orchestration-agnostic correction checks');
