<?php
/**
 * Lightweight, dependency-free test runner for the Fluent Boards AI Automation add-on.
 *
 * Usage:  php tests/run.php
 *
 * Exits 0 when all checks pass, 1 otherwise (CI-friendly). No PHPUnit/Composer required.
 */

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/test-settings.php';
echo "\n";
require __DIR__ . '/test-autoload.php';
echo "\n";
require __DIR__ . '/test-ai-and-deps.php';
echo "\n";
require __DIR__ . '/test-safemode.php';
echo "\n";
require __DIR__ . '/test-render-smoke.php';

exit(fbaia_test_summary());
