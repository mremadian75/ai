<?php
/**
 * v0.3.55 — report template renders as an intelligence artifact, not a
 * dashboard card stack. Locks the reading order (evidence before
 * recommendation, proof-needed before limitations, trace last), the editorial
 * summary strip, the source truth rail, collapsible diagnostics, and copy
 * buttons restricted to valid asset fields.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; } else { $fail++; fwrite(STDERR, "FAIL: {$label}\n"); }
};

$tpl = (string) file_get_contents($root . '/templates/report-deep-trend-finder.php');
// Order checks scan the MARKUP only — the doc comment also names the sections.
$markup_start = strpos($tpl, '<div class="fidtf-report');
$markup = $markup_start !== false ? substr($tpl, $markup_start) : $tpl;
$css = (string) file_get_contents($root . '/assets/css/fidtf-frontend.css');
$js  = (string) file_get_contents($root . '/assets/js/fidtf-frontend.js');

// ── Section order: title → decision → claim gate → evidence map → source truth
//    → clusters → interpretation → briefing → outputs → proof → limitations → trace
$order = [
    '<!-- 1 · Intelligence title + status -->',
    '<!-- 2 · Decision summary -->',
    '<!-- 3 · Claim-readiness gate -->',
    '<!-- 4 · Evidence map -->',
    '<!-- 5 · Source execution truth -->',
    '<!-- 6 · Signal clusters -->',
    '<!-- 7 · Interpretation / key insights -->',
    '<!-- 8 · Briefing route -->',
    '<!-- 9 · Platform-ready outputs -->',
    '<!-- 10 · Proof still needed -->',
    '<!-- 11 · Limitations -->',
    '<!-- 12 · Trace + diagnostics -->',
];
$last = -1;
foreach ($order as $marker) {
    $pos = strpos($markup, $marker);
    $ok($pos !== false, "template contains section marker '{$marker}'");
    $ok($pos !== false && $pos > $last, "'{$marker}' appears after the previous section (artifact order)");
    if ($pos !== false) { $last = $pos; }
}

// ── Editorial summary strip replaces the equal metric tile grid in the head.
$ok(strpos($tpl, 'fidtf-summary-strip') !== false, 'head uses the editorial summary strip');
$ok(strpos($tpl, 'fidtf-metric-grid') === false, 'old equal metric tile grid is gone from the report');

// ── Source execution is a truth rail with the full count ladder.
$ok(strpos($tpl, 'fidtf-source-rail') !== false && strpos($tpl, 'fidtf-source-row') !== false, 'sources render as a truth rail, not scattered cards');
foreach (['Provider returned:', 'Actor dataset items:', 'Parsed:', 'Normalized:', 'Usable evidence:', 'Decision-ready:', 'Discarded:'] as $count_label) {
    $ok(strpos($tpl, $count_label) !== false, "source rail keeps the count ladder ('{$count_label}')");
}

// ── Diagnostics are collapsible and never primary.
$ok(substr_count($tpl, '<details class="fidtf-diagnostics"') >= 2, 'technical diagnostics render inside collapsed details elements');
$ok(strpos($tpl, 'Technical diagnostics') !== false, 'per-source diagnostics keep their collapsed summary');

// ── Copy buttons exist and only on VALID fields (invalid/meta-flagged get none).
$ok(strpos($tpl, 'data-fidtf-copy') !== false, 'asset fields expose copy buttons');
$ok((bool) preg_match('/if \(!empty\(\$asset_field\[.valid.\]\)\): \?>\s*<button type="button" class="fidtf-copy-btn"/', $tpl), 'copy button renders only when the field is valid');
$ok(strpos($js, 'data-fidtf-copy') !== false && strpos($js, 'navigator.clipboard') !== false, 'frontend implements the clipboard handler');
$ok(strpos($tpl, 'onclick=') === false, 'no inline event handlers in the template');

// ── Required legacy strings stay intact for the report contract.
foreach (['Google Ads asset block', 'Strategic readout', 'Content angle briefs', 'Recommended validation plan', 'Hook mechanics detected', 'Creative territories', 'Strategic risk notes', 'Cross-platform intelligence', 'Hypotheses to test', 'Data scientist scorecard', 'Evidence fit diagnosis', 'Briefing recommendations'] as $required) {
    $ok(strpos($tpl, $required) !== false, "template keeps required section '{$required}'");
}

// ── Status states the template must label honestly.
foreach (['provider_timeout_stale', 'blocked_insufficient_evidence', 'source_actor_not_allowlisted'] as $state) {
    $ok(strpos($tpl, $state) !== false, "template maps the '{$state}' state to a human label");
}

// ── Responsive + overflow safety in the new CSS layer.
$ok(strpos($css, '.fidtf-artifact-section') !== false && strpos($css, '.fidtf-summary-strip') !== false, 'artifact CSS layer exists');
$ok(strpos($css, '@media (max-width: 640px)') !== false, 'artifact layout has a small-screen branch');
$ok(strpos($css, 'overflow-wrap: anywhere') !== false, 'long values cannot overflow the layout');

echo "\nv0.3.55 artifact report render checks: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
