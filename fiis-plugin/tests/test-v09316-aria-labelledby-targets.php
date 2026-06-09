<?php
/**
 * v0.9.31.6 — aria-labelledby targets must point to existing element IDs.
 * Scans every templates/reports/*.php file. For each aria-labelledby="X"
 * attribute, an element with id="X" must exist somewhere in the same file.
 * Run: php tests/test-v09316-aria-labelledby-targets.php
 */
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$files = glob($root . '/templates/reports/*.php');
$assert(count($files) >= 5, 'templates/reports/ contains at least 5 templates');

$checked = 0;
foreach ($files as $path) {
    $bn = basename($path);
    if ($bn === '_partials.php') { continue; }
    $raw = file_get_contents($path);

    preg_match_all('/aria-labelledby\s*=\s*[\'"]([^\'"]+)[\'"]/i', $raw, $aria_matches);
    foreach ($aria_matches[1] ?? [] as $target_id) {
        $checked++;
        // Look for either a static id="X" or a PHP-echoed id with the same constant.
        $has_static = preg_match('/\bid\s*=\s*[\'"]' . preg_quote($target_id, '/') . '[\'"]/', $raw);
        $assert((bool) $has_static, "$bn: aria-labelledby=\"$target_id\" has a matching id");
    }
}

$assert($checked >= 5, 'at least 5 aria-labelledby attributes were checked across templates (got ' . $checked . ')');

if ($fail === 0) {
    echo "OK v0.9.31.6 aria-labelledby targets — $pass assertions passed ($checked aria refs checked)\n";
} else {
    echo "FAIL v0.9.31.6 aria-labelledby targets — $fail failures, $pass passed\n";
    exit(1);
}
