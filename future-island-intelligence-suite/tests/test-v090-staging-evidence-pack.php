<?php
require_once __DIR__ . '/fixtures/fake-wpdb-spine.php';
$root = dirname(__DIR__);
$script = $root . '/bin/build-staging-trial-evidence-pack.sh';
fi_spine_ok(is_file($script) && is_executable($script), 'evidence pack script exists and executable');
$out = sys_get_temp_dir() . '/fi-staging-trial-evidence-pack-test.zip';
@unlink($out);
$cmd = 'FI_STAGING_EVIDENCE_PACK=' . escapeshellarg($out) . ' bash ' . escapeshellarg($script) . ' 2>&1';
$result = shell_exec($cmd);
fi_spine_ok(is_string($result) && strpos($result, 'Staging trial evidence pack:') !== false, 'evidence pack dry-run command completes');
fi_spine_ok(is_file($out) && filesize($out) > 0, 'evidence pack zip created');
fi_spine_finish('v0.9.0 staging evidence pack script checks');
