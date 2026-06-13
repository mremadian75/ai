<?php
/**
 * v0.9.31.8-p4.1-deep-trend-addon-live-qa-hotfix — admin diagnostic display must stay structured and safe.
 *
 * v0.9.31.6 moved renderAdminDetail(err) into vesErrorStatusHtml(). Check the
 * shared helper plus both error surfaces instead of requiring duplicated calls.
 */

$root = dirname(__DIR__);
$js   = file_get_contents($root . '/assets/js/ves-frontend.js');

$checks = [];
$add = function($name, $ok) use (&$checks) { $checks[] = [$name, (bool) $ok]; };

$extractBlock = static function(string $src, string $needle, int $window = 5000): string {
    $pos = strpos($src, $needle);
    if ($pos === false) { return ''; }
    $open = strpos($src, '{', $pos);
    if ($open === false) { return substr($src, $pos, $window); }
    $depth = 0;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') { $depth++; }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $pos, $i - $pos + 1); }
        }
    }
    return substr($src, $pos, $window);
};

// 1. renderAdminDetail helper exists.
$add('renderAdminDetail function defined', strpos($js, 'const renderAdminDetail') !== false || strpos($js, 'function renderAdminDetail') !== false);

// 2. renderAdminDetail builds a <details> element (collapsible, not overflow).
$render_window = $extractBlock($js, 'const renderAdminDetail', 2000);
$add('renderAdminDetail uses <details> element', strpos($render_window, '<details') !== false);
$add('renderAdminDetail uses <summary> label', strpos($render_window, '<summary') !== false);

// 3. adminDetail is no longer set to a raw JSON.stringify blob.
$raw_json_pattern = strpos($js, "adminDetail = JSON.stringify") !== false;
$add('adminDetail no longer set to JSON.stringify blob', !$raw_json_pattern);

// 4. adminFields (structured) replaces the old blob.
$add('adminFields structured object used instead', strpos($js, 'adminFields') !== false);

// 5. Shared helper owns admin detail rendering, and both error surfaces use it.
$error_helper = $extractBlock($js, 'const vesErrorStatusHtml', 1600);
$add('vesErrorStatusHtml helper defined', $error_helper !== '');
$add('renderAdminDetail called inside vesErrorStatusHtml', strpos($error_helper, 'renderAdminDetail(err)') !== false);
$add('vesErrorStatusHtml includes safe diagnostic line', strpos($error_helper, 'vesSafeDiagLine(err)') !== false);
$add('vesErrorStatusHtml includes retry action', strpos($error_helper, 'vesRetryActionHtml(err)') !== false);
$finish = $extractBlock($js, 'const finishPollingAfterError', 5000);
$add('polling failure path uses vesErrorStatusHtml', strpos($finish, 'vesErrorStatusHtml(') !== false);
$add('start/dispatch failure path uses vesErrorStatusHtml', strpos($js, "setStatus(widget, vesErrorStatusHtml('Ejecución no iniciada', err, widget)") !== false);

// 6. No raw JSON.stringify of the full response in the rendered error HTML.
$error_display_regions = [];
$set_status_pos = 0;
$json_in_status = false;
while (($pos = strpos($js, 'setStatus(', $set_status_pos)) !== false) {
    $region = substr($js, $pos, 300);
    if (strpos($region, 'JSON.stringify') !== false) {
        $json_in_status = true;
        break;
    }
    $set_status_pos = $pos + 10;
}
$add('no JSON.stringify embedded in setStatus error output', !$json_in_status);
$add('safeDiagnostic is not rendered as raw JSON', strpos($js, 'JSON.stringify(err.safeDiagnostic') === false && strpos($js, 'JSON.stringify(sd') === false);

// 7. escapeHtml used inside renderAdminDetail (prevents XSS in diagnostic values).
$add('escapeHtml used inside renderAdminDetail', strpos($render_window, 'escapeHtml') !== false);

$bad = array_column(array_filter($checks, fn($c) => !$c[1]), 0);
if (!empty($bad)) {
    fwrite(STDERR, "\nv0.9.31.8-p4.1-deep-trend-addon-live-qa-hotfix admin-detail-no-raw-json check FAILED:\n - " . implode("\n - ", $bad) . "\n");
    exit(1);
}
echo "v0.9.31.8-p4.1-deep-trend-addon-live-qa-hotfix admin-detail-no-raw-json checks passed: " . count($checks) . ' / ' . count($checks) . PHP_EOL;
