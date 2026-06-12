<?php
/**
 * v0.9.31.6 — Curated report templates: 3 new PHP files + registry wiring +
 * auto-routing inside choose(). Static checks on file presence, ABSPATH
 * guard, scoped section wrapper, no inline onclick=sendPrompt, registry
 * entries, and topic-based routing.
 * Run: php tests/test-v09315-curated-templates.php
 */
$root = dirname(__DIR__);
$main = file_get_contents($root . '/future-island-intelligence-suite.php');
$registry = file_get_contents($root . '/includes/reports/class-ves-report-template-registry.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.9') !== false, 'Version is 1.1.0');

$templates = [
    'verano-silencioso'   => 'verano-silencioso.php',
    'tiktok-trends-2026'  => 'tiktok-trends-2026.php',
    'tastemakers-2026'    => 'tastemakers-2026.php',
];

foreach ($templates as $key => $file) {
    $path = $root . '/templates/reports/' . $file;
    $assert(file_exists($path), "$file exists in templates/reports/");
    $body = file_exists($path) ? file_get_contents($path) : '';
    $assert(strpos($body, "if (!defined('ABSPATH'))") !== false, "$file has ABSPATH guard");
    $assert(strpos($body, '<section class="ves-report ves-report-curated ves-report-' . $key . '"') !== false, "$file wraps in scoped ves-report-curated section");
    $assert(strpos($body, 'onclick="sendPrompt(') === false && strpos($body, "onclick='sendPrompt(") === false, "$file removed all inline onclick=sendPrompt(");
    $assert(strpos($body, 'data-ves-assistant-question=') !== false, "$file uses data-ves-assistant-question for CTAs");
    $assert(strpos($body, 'esc_html') !== false, "$file uses esc_html (title override slot)");
}

// Registry has the 3 keys
foreach (array_keys($templates) as $key) {
    $assert(strpos($registry, "'$key' => [") !== false, "registry templates() includes '$key'");
}

// choose() routes by topic blob
$choose_pos = strpos($registry, 'public static function choose(');
$choose_body = $choose_pos !== false ? substr($registry, $choose_pos, 4000) : '';
$assert(strpos($choose_body, 'tiktok-trends-2026') !== false, "choose() can return 'tiktok-trends-2026'");
$assert(strpos($choose_body, 'verano-silencioso') !== false,  "choose() can return 'verano-silencioso'");
$assert(strpos($choose_body, 'tastemakers-2026') !== false,   "choose() can return 'tastemakers-2026'");
$assert(strpos($choose_body, 'verano silencioso|slow living') !== false, 'choose() inspects slow-living topic blob');
$assert(strpos($choose_body, 'tastemaker') !== false, 'choose() inspects tastemaker topic');

if ($fail === 0) {
    echo "OK v0.9.31.6 curated templates — $pass assertions passed\n";
} else {
    echo "FAIL v0.9.31.6 curated templates — $fail failures, $pass passed\n";
    exit(1);
}
