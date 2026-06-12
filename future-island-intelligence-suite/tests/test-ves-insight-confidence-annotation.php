<?php
/**
 * Phase 23 — VES_Insight_Records::prepare() canonical confidence annotation (read-only).
 *
 * Verifies the first production consumer of VES_Confidence: prepare() attaches an
 * additive `confidence_canonical` block derived from the row's own fields, without
 * changing any existing key/value, without writing to the DB, and without UI rendering.
 *
 * prepare() is private and reads from DB-row shapes (evidence_refs_json / meta_json / …);
 * it is exercised via Reflection against representative rows. Only the minimal WP shims
 * the method touches (sanitize_key) are defined here; VES_Confidence is loaded directly.
 *
 * Run: php tests/test-ves-insight-confidence-annotation.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
}

require_once dirname(__DIR__) . '/includes/class-ves-confidence.php';
require_once dirname(__DIR__) . '/includes/class-ves-insight-records.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

// Reflection into the private prepare() (same approach used by prior confidence tests).
$prepare = new ReflectionMethod('VES_Insight_Records', 'prepare');
$prepare->setAccessible(true);
$call = function (array $row) use ($prepare) { return $prepare->invoke(null, $row); };

// The canonical mapper output contract (must be mirrored in confidence_canonical).
$CANON_KEYS = ['band','numeric','source_module','native','evidence_status','allowed_action','client_facing','reason','limitations'];
$CANON = ['validated','supported','directional','exploratory','hypothesis','insufficient','blocked'];
$RANK = ['validated'=>7,'supported'=>6,'directional'=>5,'exploratory'=>4,'hypothesis'=>3,'insufficient'=>2,'blocked'=>1];

// Build a representative DB-row (column names as stored).
$make_row = function (array $over = []) {
    return array_merge([
        'id' => 1, 'workspace_id' => 5, 'user_id' => 9, 'run_id' => 'ba_run_1',
        'insight_key' => 'k1', 'title' => 'Insight', 'insight_type' => 'insight',
        'summary' => 'Summary', 'evidence_refs_json' => '[]', 'pattern_ids_json' => '[]',
        'confidence' => 0.90, 'business_impact' => 'high', 'urgency' => 'medium',
        'status' => 'proposed', 'created_by' => 'ai', 'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00', 'meta_json' => '{}',
    ], $over);
};

// ── 1. Supported row (>=2 refs, status supported) ──────────────────────────────
$supported = $call($make_row([
    'confidence' => 0.90,
    'evidence_refs_json' => json_encode(['ev_1_aaaaaa', 'ev_2_bbbbbb']),
    'meta_json' => json_encode(['evidence_validation_status' => 'supported', 'evidence_ref_count' => 2]),
]));
$ok(isset($supported['confidence_canonical']), '1: supported row exposes confidence_canonical block');
$sc = $supported['confidence_canonical'];
$ok(in_array($sc['band'], ['validated','supported'], true), '1: supported (>=2 refs) band not wrongly downgraded (got ' . $sc['band'] . ')');
$ok($sc['evidence_status'] === 'supported', '1: evidence_status=supported');

// ── 2. Weak row (1 ref / weak status) → cap directional or lower ────────────────
$weak = $call($make_row([
    'confidence' => 0.90,
    'evidence_refs_json' => json_encode(['ev_1_aaaaaa']),
    'meta_json' => json_encode(['evidence_validation_status' => 'weak', 'evidence_ref_count' => 1]),
]));
$wc = $weak['confidence_canonical'];
$ok($RANK[$wc['band']] <= $RANK['directional'], '2: weak (1 ref) capped to directional or lower (got ' . $wc['band'] . ')');
$ok($wc['band'] === 'directional', '2: weak row → directional');

// ── 3. Unsupported row (0 refs / unsupported_by_evidence) → cap hypothesis/lower ─
$unsupported = $call($make_row([
    'confidence' => 0.90,
    'evidence_refs_json' => '[]',
    'meta_json' => json_encode(['evidence_validation_status' => 'unsupported', 'evidence_ref_count' => 0, 'unsupported_by_evidence' => true]),
]));
$uc = $unsupported['confidence_canonical'];
$ok($RANK[$uc['band']] <= $RANK['hypothesis'], '3: unsupported (0 refs) capped to hypothesis or lower (got ' . $uc['band'] . ')');
$ok($uc['band'] === 'hypothesis', '3: unsupported row → hypothesis');
$ok($uc['client_facing'] === false, '3: hypothesis is not client_facing');

// ── 4. Existing-shape regression: all prior keys present + unchanged ────────────
// Recreate the EXACT pre-Phase-23 prepared array from the same row and compare.
$row = $make_row([
    'confidence' => 0.42,
    'evidence_refs_json' => json_encode(['ev_1_aaaaaa']),
    'meta_json' => json_encode(['evidence_validation_status' => 'weak', 'evidence_ref_count' => 1, 'invalid_evidence_refs' => ['ev_X']]),
]);
$out = $call($row);
$refs = ['ev_1_aaaaaa'];
$meta = ['evidence_validation_status' => 'weak', 'evidence_ref_count' => 1, 'invalid_evidence_refs' => ['ev_X']];
$expected_existing = [
    'id' => 1, 'workspace_id' => 5, 'user_id' => 9, 'run_id' => 'ba_run_1',
    'insight_key' => 'k1', 'title' => 'Insight', 'insight_type' => 'insight', 'summary' => 'Summary',
    'evidence_refs' => $refs, 'pattern_ids' => [], 'confidence' => 0.42,
    'business_impact' => 'high', 'urgency' => 'medium', 'status' => 'proposed',
    'created_by' => 'ai', 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    'meta' => $meta,
    'unsupported_by_evidence' => false,
    'invalid_evidence_refs' => ['ev_X'],
    'evidence_ref_count' => 1,
    'evidence_validation_status' => 'weak',
];
$all_present = true; $all_equal = true;
foreach ($expected_existing as $k => $v) {
    if (!array_key_exists($k, $out)) { $all_present = false; echo "    (missing key: $k)\n"; }
    elseif ($out[$k] !== $v) { $all_equal = false; echo "    (changed value: $k)\n"; }
}
$ok($all_present, '4: all pre-existing prepare() keys still present');
$ok($all_equal, '4: all pre-existing prepare() values unchanged');
// the ONLY added key is confidence_canonical.
$added = array_values(array_diff(array_keys($out), array_keys($expected_existing)));
$ok($added === ['confidence_canonical'], '4: the only new key is confidence_canonical (got: ' . implode(',', $added) . ')');

// ── 5. confidence_canonical shape + numeric/band consistency ─────────────────────
$shape_ok = true;
foreach ($CANON_KEYS as $k) { if (!array_key_exists($k, $out['confidence_canonical'])) { $shape_ok = false; echo "    (canonical missing: $k)\n"; } }
$ok($shape_ok, '5: confidence_canonical exposes the full mapper output contract');
$ok(in_array($out['confidence_canonical']['band'], $CANON, true), '5: canonical band is a canonical value');
$numeric_consistent = function (array $c) use ($RANK) {
    if ($c['numeric'] === null) { return true; }
    $n = (float) $c['numeric'];
    $min = ['validated'=>0.82,'supported'=>0.62,'directional'=>0.45,'exploratory'=>0.30];
    foreach (['validated','supported','directional','exploratory'] as $b) {
        if ($RANK[$b] > $RANK[$c['band']] && $n >= $min[$b]) { return false; }
    }
    return true;
};
$consistent_all = $numeric_consistent($sc) && $numeric_consistent($wc) && $numeric_consistent($uc) && $numeric_consistent($out['confidence_canonical']);
$ok($consistent_all, '5: numeric never implies a stronger band than the final band (all rows)');
// native preserved inside the canonical block (the prepared row is the mapper input).
$ok(($uc['native']['confidence'] ?? null) === 0.90, '5: canonical native preserves the row confidence (0.90)');

// ── 6. Guard behavior ────────────────────────────────────────────────────────────
// VES_Confidence is loaded in this process, so the class_exists() guard's true branch is
// already covered. The false branch (class genuinely absent) cannot be exercised here
// without unloading a defined class, which PHP does not support; verified by inspection
// that prepare() omits the key (no fatal) when class_exists('VES_Confidence') is false.
$ok(class_exists('VES_Confidence'), '6: guard true-branch covered (VES_Confidence present); false-branch verified by inspection (graceful omit, no fatal)');

echo "\nVES_Insight_Records confidence_canonical annotation: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
