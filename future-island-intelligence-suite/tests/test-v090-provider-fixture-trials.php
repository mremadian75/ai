<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$script = $root . '/bin/run-provider-fixture-trials.sh';
fi_spine_ok(is_file($script) && is_executable($script), 'fixture trial runner exists and is executable');
$log = sys_get_temp_dir() . '/fi-provider-fixture-trial-test.log';
@unlink($log);
$cmd = 'FI_PROVIDER_SECRET=fi_fixture_secret_hidden FI_PROVIDER_TRIAL_LOG=' . escapeshellarg($log) . ' bash ' . escapeshellarg($script) . ' 2>&1';
$out = shell_exec($cmd);
fi_spine_ok(is_string($out) && strpos($out, 'Provider fixture trials complete') !== false, 'fixture dry-run succeeds');
fi_spine_ok(is_file($log), 'fixture runner writes log');
$contents = file_get_contents($log);
foreach (['social-valid-topic.json','social-invalid-private.json','social-mixed-batch.json','aeo-valid-gap.json','aeo-missing-answer.json','creative-valid-analysis.json','creative-invalid-token-leak.json'] as $fixture) {
    fi_spine_ok(strpos($contents, $fixture) !== false, 'fixture discovered ' . $fixture);
}
fi_spine_ok(strpos($contents, 'fi_fixture_secret_hidden') === false, 'fixture log redacts secret');
fi_spine_ok(strpos($contents, 'sha256=[redacted]') !== false, 'fixture log redacts signature');
fi_spine_finish('v0.9.0 provider fixture trial runner checks');
