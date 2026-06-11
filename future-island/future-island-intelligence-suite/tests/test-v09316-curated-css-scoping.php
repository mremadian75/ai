<?php
/**
 * v0.9.31.6 — Curated templates: CSS must be scoped under the unique wrapper.
 * Asserts that the <style> block of each curated template does NOT contain
 * unscoped global selectors that would leak into the host theme.
 * Run: php tests/test-v09316-curated-css-scoping.php
 */
$root = dirname(__DIR__);
$main = file_get_contents($root . '/future-island-intelligence-suite.php');

$pass = 0; $fail = 0;
$assert = function($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
};

$assert(strpos($main, 'Version: 1.2.7') !== false, 'Version is 1.1.0');

$templates = [
    'verano-silencioso'   => 'verano-silencioso.php',
    'tiktok-trends-2026'  => 'tiktok-trends-2026.php',
    'tastemakers-2026'    => 'tastemakers-2026.php',
];

foreach ($templates as $slug => $file) {
    $path = $root . '/templates/reports/' . $file;
    $assert(file_exists($path), "$file exists");
    if (!file_exists($path)) { continue; }

    $raw = file_get_contents($path);
    if (!preg_match('/<style[^>]*>(.*?)<\/style>/s', $raw, $m)) {
        $assert(false, "$file has a <style> block");
        continue;
    }
    $css = $m[1];
    $wrapper = ".ves-report-curated.ves-report-$slug";

    // Strip @keyframes blocks entirely — their step selectors (0%/100%) are not class selectors.
    $css_stripped = preg_replace('/@keyframes\s+[a-zA-Z0-9_-]+\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css);

    // Find every rule-list selector and check it is scoped.
    // A "rule" is anything that looks like SELECTOR { ... }.
    // Skip @-rules entirely.
    $lines = preg_split('/\r?\n/', $css_stripped);
    $bad = [];
    $brace_depth = 0;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '//') === 0 || strpos($trim, '/*') === 0) { continue; }
        // Skip lines inside @-rules
        if (strpos($trim, '@') === 0) { continue; }
        // Selector lines end with '{'
        if (!preg_match('/\{$/', $trim)) { continue; }
        $selector = rtrim(rtrim($trim, '{'));
        $selector = trim($selector);
        if ($selector === '') { continue; }
        // Each comma-separated piece must start with the wrapper.
        foreach (explode(',', $selector) as $piece) {
            $p = trim($piece);
            if ($p === '') { continue; }
            // Allowed pseudo-roots
            if (strpos($p, $wrapper) === 0) { continue; }
            // Anything else is a global selector leak.
            $bad[] = $p;
        }
    }
    $assert(empty($bad), "$file CSS has no unscoped selectors (found: " . implode(' | ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' ...' : '') . ')');

    // Specific dangerous patterns that should never appear unscoped.
    $assert(!preg_match('/(^|\n)\s*\*\s*\{/', $css_stripped), "$file does NOT have global * { reset");
    $assert(!preg_match('/(^|\n)\s*body\s*\{/', $css_stripped), "$file does NOT have global body { rule");
    $assert(!preg_match('/(^|\n)\s*:root\s*\{/', $css_stripped), "$file does NOT have global :root { (variables must scope to wrapper)");

    // Sanity: at least one scoped rule exists.
    $assert(strpos($css_stripped, $wrapper) !== false, "$file CSS contains the unique wrapper $wrapper");



    // v1.1.0: @keyframes names are global even when class selectors are scoped.
    $generic_animation_names = 'blink|pulse|spin|fade|float|slide|marquee';
    $assert(!preg_match('/@keyframes\s+(' . $generic_animation_names . ')\b/i', $css), "$file does NOT define generic global @keyframes names");
    $assert(!preg_match('/animation(?:-name)?\s*:[^;{}]*(?<![-a-zA-Z0-9_])(' . $generic_animation_names . ')(?![-a-zA-Z0-9_])/i', $css), "$file animation declarations do NOT reference generic keyframe names");

    // v1.1.0: each curated template gets scoped tablet + mobile fallbacks.
    $assert(strpos($css, '@media (max-width: 900px)') !== false, "$file has a tablet scoped @media block");
    $assert(strpos($css, '@media (max-width: 560px)') !== false, "$file has a mobile scoped @media block");

    if (preg_match_all('/@media[^{}]*\{(.*?)\n\}/s', $css, $media_matches)) {
        foreach ($media_matches[1] as $idx => $media_body) {
            $bad_media = [];
            foreach (preg_split('/\r?\n/', $media_body) as $mline) {
                $mt = trim($mline);
                if ($mt === '' || $mt === '}' || strpos($mt, '/*') === 0 || strpos($mt, '@') === 0) { continue; }
                if (strpos($mt, '{') === false) { continue; }
                $selector = trim(substr($mt, 0, strpos($mt, '{')));
                foreach (explode(',', $selector) as $piece) {
                    $piece = trim($piece);
                    if ($piece !== '' && strpos($piece, $wrapper) !== 0) { $bad_media[] = $piece; }
                }
            }
            $assert(empty($bad_media), "$file @media block #" . ($idx + 1) . " has only scoped selectors" . (empty($bad_media) ? '' : ': ' . implode(' | ', $bad_media)));
        }
    } else {
        $assert(false, "$file has parseable @media blocks");
    }

    $fallbacks = [
        'verano-silencioso' => ['.stats-row{grid-template-columns:repeat(2', '.stats-row{grid-template-columns:1fr', '.anatomy-grid{grid-template-columns:1fr', '.tension-grid{grid-template-columns:1fr', '.brands-grid{grid-template-columns:1fr', '.msg-grid{grid-template-columns:1fr', '.risk-grid{grid-template-columns:1fr', '.tl-row{grid-template-columns:1fr'],
        'tiktok-trends-2026' => ['.topnav{align-items:flex-start;flex-direction:column', '.pillars{grid-template-columns:1fr', '.trends-grid{grid-template-columns:repeat(2', '.trends-grid{grid-template-columns:1fr', '.format-grid{grid-template-columns:1fr', '.shift-grid{grid-template-columns:1fr', '.algo-grid{grid-template-columns:1fr', '.brands-row{grid-template-columns:1fr'],
        'tastemakers-2026' => ['.compare-wrap{grid-template-columns:1fr', '.tier-row{grid-template-columns:1fr', '.trust-grid{grid-template-columns:1fr', '.find-grid{grid-template-columns:1fr', '.tipos-grid{grid-template-columns:1fr', '.brief-grid{grid-template-columns:1fr', '.metrics-grid{grid-template-columns:1fr', '.brands-grid{grid-template-columns:1fr'],
    ];
    foreach ($fallbacks[$slug] as $needle) {
        $assert(strpos($css, $wrapper . ' ' . $needle) !== false, "$file has responsive fallback for $needle");
    }

    // @import is the only remaining global thing we accept (font loading);
    // make sure it is not corrupted by previous scoping passes.
    if (strpos($css, '@import') !== false) {
        $assert(preg_match('/@import\s+url\([\'"]https?:\/\/[^)]+\)\s*;/', $css) === 1, "$file @import line is well-formed");
    }
}

if ($fail === 0) {
    echo "OK v1.1.0 curated CSS scoping — $pass assertions passed\n";
} else {
    echo "FAIL v1.1.0 curated CSS scoping — $fail failures, $pass passed\n";
    exit(1);
}
