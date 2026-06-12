<?php
/**
 * Audit regression — the plugin header declares "Requires PHP: 7.4", so no shipped
 * PHP file may use PHP 8.0-only syntax that would FATAL on a 7.4 host. This guards
 * against match() expressions, str_contains/str_starts_with/str_ends_with (8.0
 * functions, not polyfilled here), the nullsafe operator (?->), enums, and
 * constructor property promotion. php -l on PHP 8.x would NOT catch these.
 *
 * Run: php tests/test-audit-php74-compat.php
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($c, $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } };

// Confirm the declared PHP requirement is still 7.4 (the premise of this guard).
$boot = (string) file_get_contents($root . '/future-island-intelligence-suite.php');
$ok(preg_match('/Requires PHP:\s*7\.4/', $boot) === 1, 'plugin header still declares Requires PHP: 7.4');

// Enumerate all shipped PHP files (exclude tests + vendor + .git).
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') { continue; }
    if (strpos($p, '/.git/') !== false || strpos($p, '/vendor/') !== false || strpos($p, '/tests/') !== false) { continue; }
    $files[] = $p;
}
$ok(count($files) > 100, 'discovered the shipped PHP files (' . count($files) . ')');

// Patterns that fatal on PHP 7.4. Each is matched against code with line comments
// and block-comment lines stripped to avoid false positives from prose.
$checks = [
    'match() expression'        => '/(?<![>\w])(=|=>|\(|\breturn|,)\s*match\s*\(/',
    'str_contains()'            => '/(?<!function )\bstr_contains\s*\(/',
    'str_starts_with()'         => '/(?<!function )\bstr_starts_with\s*\(/',
    'str_ends_with()'           => '/(?<!function )\bstr_ends_with\s*\(/',
    'nullsafe operator ?->'     => '/\?->/',
    'enum declaration'          => '/^\s*enum\s+[A-Z]\w*/m',
];

$violations = [];
foreach ($files as $p) {
    $src = (string) file_get_contents($p);
    // Strip // line comments and # comments and /* */ blocks to reduce false hits.
    $stripped = preg_replace('!/\*.*?\*/!s', '', $src);
    $stripped = preg_replace('!//.*$!m', '', (string) $stripped);
    foreach ($checks as $label => $re) {
        if (preg_match($re, (string) $stripped)) {
            $violations[] = basename($p) . ' :: ' . $label;
        }
    }
}

$ok(empty($violations), 'no PHP 8.0-only syntax in shipped files' . (empty($violations) ? '' : ' — ' . implode('; ', $violations)));

// Spot-check the previously-offending DTF report template specifically.
$tpl = (string) file_get_contents($root . '/modules/deep-trend-finder/templates/report-deep-trend-finder.php');
$ok(strpos($tpl, 'str_contains') === false && !preg_match('/=\s*match\s*\(/', $tpl), 'DTF report template is PHP 7.4-clean (no match()/str_contains)');

echo "\nPHP 7.4 compatibility audit: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
