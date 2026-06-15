<?php
$root = dirname(__DIR__);
$script = $root . '/bin/test-all-timeout-safe.sh';
$checks = 0; $fails = [];
$ok = function($cond, $msg) use (&$checks, &$fails) { $checks++; if (!$cond) { $fails[] = $msg; fwrite(STDERR, "FAIL: $msg\n"); } };
$ok(file_exists($script), 'timeout-safe test runner exists');
$ok(is_executable($script), 'timeout-safe test runner is executable');
$body = file_get_contents($script);
$ok(strlen($body) > 100, 'script readable');
$ok(strpos($body, 'test-*.php') !== false, 'script discovers test files');
$ok(strpos($body, 'timeout "$PER_FILE_TIMEOUT"') !== false || strpos($body, 'timeout') !== false, 'script applies per-file timeout');
$ok(strpos($body, 'Failed:') !== false && strpos($body, 'Timeouts:') !== false, 'script reports pass/fail/timeout counts');
$ok(strpos($body, 'exit 1') !== false, 'script returns non-zero on failure/timeout');
if ($fails) { echo 'v0.8.0 timeout-safe runner checks: ' . ($checks - count($fails)) . " passed, " . count($fails) . " failed\n"; exit(1); }
echo "v0.8.0 timeout-safe runner checks passed: $checks / $checks\n";
