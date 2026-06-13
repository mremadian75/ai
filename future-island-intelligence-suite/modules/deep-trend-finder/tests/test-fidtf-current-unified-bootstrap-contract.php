<?php
/**
 * v1.1.0 — Deep Trend Finder current unified-bootstrap contract.
 *
 * After unification, the DTF module is loaded through the suite bootstrap
 * `future-island-intelligence-suite.php`. The old standalone addon bootstrap
 * `future-island-deep-trend-finder-addon.php` no longer exists, and DTF tests
 * must not depend on a standalone DTF plugin header (`Version: 0.3.xx`).
 *
 * This test pins that current contract so the unified-architecture assumptions
 * are validated for real (and so reintroducing a standalone-bootstrap
 * dependency would be caught). It deliberately validates *current* behavior
 * only — it is not a historical release snapshot.
 *
 * Run: php modules/deep-trend-finder/tests/test-fidtf-current-unified-bootstrap-contract.php
 */

$plugin_root          = dirname(__DIR__, 3); // .../future-island-intelligence-suite
$unified_bootstrap    = $plugin_root . '/future-island-intelligence-suite.php';
$standalone_bootstrap = dirname(__DIR__) . '/future-island-deep-trend-finder-addon.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// 1. The unified bootstrap exists and is the real entry point.
$ok(is_file($unified_bootstrap), 'unified bootstrap future-island-intelligence-suite.php exists');

// 2. The standalone addon bootstrap is no longer required/present.
$ok(!is_file($standalone_bootstrap), 'standalone addon bootstrap future-island-deep-trend-finder-addon.php is NOT required/present');

$src = is_file($unified_bootstrap) ? (string) file_get_contents($unified_bootstrap) : '';

// 3. FIDTF_VERSION is declared in the unified bootstrap.
$ok(strpos($src, 'FIDTF_VERSION') !== false, 'FIDTF_VERSION is declared in the unified bootstrap');

// 4. FIDTF_VERSION equals the current module version 0.3.55 (whitespace-tolerant).
$ok((bool) preg_match("/define\\(\\s*'FIDTF_VERSION'\\s*,\\s*'0\\.3\\.55'\\s*\\)/", $src),
    'FIDTF_VERSION equals 0.3.55 in the unified bootstrap');

// 5. There is no standalone DTF plugin header: the suite header is the SUITE
//    version, so DTF tests must not depend on a `Version: 0.3.xx` header.
$ok(strpos($src, 'Version: 0.3.54') === false,
    'no DTF test should depend on a standalone DTF plugin header (suite header is the suite version, not 0.3.54)');

// 6. The suite header Version must be self-consistent with FIS_VERSION rather than
//    a hardcoded snapshot (this is what previously went stale at 1.1.0 vs 1.2.4).
//    We assert: a header Version exists, it is a semver, it equals the FIS_VERSION
//    constant defined in the same file, and it is NOT a DTF 0.3.x version.
preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $src, $header_m);
$header_version = $header_m[1] ?? '';
preg_match("/define\\(\\s*'FIS_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $src, $const_m);
$fis_version = $const_m[1] ?? '';
$ok($header_version !== '', 'unified bootstrap declares a semver suite header Version');
$ok($fis_version !== '' && $header_version === $fis_version,
    "suite header Version ({$header_version}) matches FIS_VERSION ({$fis_version})");
$ok(strpos($header_version, '0.3.') !== 0,
    'suite header Version is the suite version, not a DTF 0.3.x version');

echo "\nFIDTF current unified-bootstrap contract: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
