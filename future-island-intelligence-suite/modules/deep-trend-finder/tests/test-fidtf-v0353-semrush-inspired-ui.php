<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/../../future-island-intelligence-suite.php');
$css = file_get_contents($root . '/assets/css/fidtf-frontend.css');
$tpl = file_get_contents($root . '/templates/shortcode-deep-trend-finder.php');
function ok($condition, $message) { echo ($condition ? "OK " : "FAIL ") . $message . PHP_EOL; if (!$condition) { exit(1); } }
// [v1.1.0 archived] removed obsolete standalone-addon version snapshot assertion: addon version header bumped to v0.3.53
ok(strpos($css, 'v0.3.53 Semrush-inspired SaaS dashboard UI/UX layer') !== false, 'Semrush-inspired UI layer marker exists');
ok(strpos($css, '--fidtf-orange') !== false, 'orange primary action token exists');
ok(strpos($css, 'grid-template-columns: minmax(240px, 288px) minmax(0, 1fr)') !== false, 'left control rail layout exists');
ok(strpos($css, '.fidtf-evidence-group .fidtf-insight-stack') !== false, 'dense evidence grid override exists');
ok(strpos($tpl, 'Control panel') !== false, 'sidebar copy changed to control panel');
ok(strpos($tpl, 'Run workspace') !== false, 'sidebar workspace copy exists');
