<?php
/**
 * Test: v0.9.31.0 — Future Island™ brand tokens applied to both surfaces
 */
$root = dirname(__DIR__);
$css  = file_get_contents($root . '/assets/css/ves-frontend.css');
$ms   = file_get_contents($root . '/assets/js/ves-market-signal.js');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

// Version
$add('version is 1.2.6', strpos(file_get_contents($root . '/future-island-intelligence-suite.php'), '1.2.6') !== false);

// FI Azul primary color in ves-frontend.css
$add('FI Azul #3A61E1 in ves-frontend.css', strpos($css, '#3A61E1') !== false);

// FI Orange error color in ves-frontend.css
$add('FI Orange #F15D31 in ves-frontend.css', strpos($css, '#F15D31') !== false);

// FI Lime live-signal color in ves-frontend.css
$add('FI Lime #D2F050 in ves-frontend.css', strpos($css, '#D2F050') !== false);

// FI token variable names in ves-frontend.css
$add('--fi-azul token defined in ves-frontend.css', strpos($css, '--fi-azul:') !== false);
$add('--fi-lime token defined in ves-frontend.css', strpos($css, '--fi-lime:') !== false);
$add('--fi-orange token defined in ves-frontend.css', strpos($css, '--fi-orange:') !== false);

// Archivo Black font applied in ves-frontend.css
$add('Archivo font-family in ves-frontend.css page title', strpos($css, "Archivo") !== false);

// Old #2563EB is no longer the primary in the FI section
$add('FI token layer remaps --em to azul not old #2563EB', strpos($css, '--em:   #3A61E1') !== false || strpos($css, '--em: #3A61E1') !== false);

// MarketSignal CSS: FI Azul primary
$add('FI Azul --ac in ves-market-signal.js', strpos($ms, '--ac:#3A61E1') !== false);

// MarketSignal CSS: FI Orange error
$add('FI Orange --re in ves-market-signal.js', strpos($ms, '--re:#F15D31') !== false);

// MarketSignal CSS: FI Lime
$add('FI Lime --lime in ves-market-signal.js', strpos($ms, '--lime:#D2F050') !== false);

// MarketSignal: Archivo font import
$add('Archivo font imported in ves-market-signal.js', strpos($ms, 'Archivo') !== false);

// MarketSignal: FI text token
$add('FI Negro --t1 in ves-market-signal.js', strpos($ms, '--t1:#0F0F0F') !== false);

// MarketSignal: viz components defined
$add('Sparkline component defined in ves-market-signal.js', strpos($ms, 'const Sparkline') !== false);
$add('DonutStat component defined in ves-market-signal.js', strpos($ms, 'const DonutStat') !== false);
$add('TrendDelta component defined in ves-market-signal.js', strpos($ms, 'const TrendDelta') !== false);
$add('MiniBar component defined in ves-market-signal.js', strpos($ms, 'const MiniBar') !== false);

// Print results
$pass = $fail = 0;
foreach ($checks as [$name, $ok]) {
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name\n";
    $ok ? $pass++ : $fail++;
}
echo "\n$pass passed, $fail failed.\n";
if ($fail > 0) exit(1);
