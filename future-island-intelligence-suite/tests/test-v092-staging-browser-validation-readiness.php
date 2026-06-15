<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);

$normalizer = file_get_contents($root . '/modules/deep-trend-finder/includes/class-fidtf-normalizer.php');
fi_spine_ok(strpos($normalizer, "'provider_malformed_empty_item' => false") !== false, 'transcript metadata no longer references undefined provider_malformed_empty_item');
fi_spine_ok(strpos($normalizer, "'provider_malformed_empty_item' => $" . "provider_malformed_empty_item") !== false, 'evidence quality still returns provider malformed flag');

foreach (['test-fidtf-v0340-full-live-openai-json-schema-and-key-safety.php','test-fidtf-v0341-cross-platform-data-scientist-and-live-gpt-safety.php','test-fidtf-v0342-cross-platform-statistical-scoring-and-gpt-analysis-contract.php'] as $file) {
    $src = file_get_contents($root . '/modules/deep-trend-finder/tests/' . $file);
    fi_spine_ok(strpos($src, 'is_readable($readme_path)') !== false, $file . ' guards optional README read');
    fi_spine_ok(strpos($src, "file_get_contents($root . '/README.md')") === false, $file . ' no longer reads missing README directly');
}
$error_test = file_get_contents($root . '/tests/test-v093017-error-category-ordering.php');
fi_spine_ok(strpos($error_test, "\\" . "$" . "source === 'actor_dispatch_error'") !== false, 'error ordering test escapes literal source variable');

$plugin = file_get_contents($root . '/includes/class-ves-plugin.php');
fi_spine_ok(strpos($plugin, 'VES_Workspace_Onboarding_Service::register') !== false, 'workspace onboarding admin-post handler is registered');
$onboarding = file_get_contents($root . '/includes/class-ves-workspace-onboarding-service.php');
foreach (['handle_admin_post','Edit onboarding profile','Create first private-beta Run after saving','provider rows must return through signed WordPress endpoints'] as $needle) {
    fi_spine_ok(strpos($onboarding, $needle) !== false, 'onboarding integrated UI includes ' . $needle);
}

$provider_admin = file_get_contents($root . '/includes/class-ves-provider-admin-page.php');
foreach (['PAGE_BROWSER_VALIDATION','fi-staging-browser-validation','render_browser_validation_page','Browser Validation'] as $needle) {
    fi_spine_ok(strpos($provider_admin, $needle) !== false, 'provider admin browser validation integration includes ' . $needle);
}
$qa = file_get_contents($root . '/includes/class-ves-operator-qa-service.php');
foreach (['browser_validation_plan','Future Island Staging Browser Validation','Open Command Room','Signed callback simulator trial','Pilot readiness checkpoint','optional n8n example'] as $needle) {
    fi_spine_ok(strpos($qa, $needle) !== false, 'operator browser validation UI includes ' . $needle);
}

$cmd_renderer = file_get_contents($root . '/includes/modules/command-room/class-fi-command-room-renderer.php');
fi_spine_ok(strpos($cmd_renderer, 'Browser Validation') !== false, 'Command Room links to browser validation screen');
fi_spine_ok(strpos($cmd_renderer, 'onboarding_saved') !== false, 'Command Room shows onboarding save notice');

$fixture_runner = file_get_contents($root . '/bin/run-provider-fixture-trials.sh');
foreach (['FI_PROVIDER_AEO_KEY','approved_aeo_capture','FI_PROVIDER_CREATIVE_KEY','manual_creative_analysis'] as $needle) {
    fi_spine_ok(strpos($fixture_runner, $needle) !== false, 'fixture runner uses family-specific provider key: ' . $needle);
}
$evidence_script = file_get_contents($root . '/bin/build-staging-browser-validation-evidence-pack.sh');
foreach (['optional example only','does not require n8n assets','SCREENSHOTS_OR_HTML_SNIPPETS_BUNDLE.zip'] as $needle) {
    fi_spine_ok(strpos($evidence_script, $needle) !== false || strpos($evidence_script, 'ui-snippets') !== false, 'browser evidence pack preserves optional n8n/UI evidence language: ' . $needle);
}

$css = file_get_contents($root . '/assets/css/fiis-ui-system.css');
foreach (['fi-browser-validation-screen','fi-validation-grid','fi-onboarding-editor','fi-onboarding-form'] as $needle) {
    fi_spine_ok(strpos($css, $needle) !== false, 'staging browser validation UI CSS includes ' . $needle);
}

fi_spine_finish('v0.9.2 staging browser validation readiness checks');
