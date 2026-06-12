<?php
/**
 * Regression coverage for the evidence-reference (anti-hallucination) contract.
 *
 * Run: php tests/test-evidence-ref-validation.php
 *
 * This is a FUNCTIONAL test (it executes real plugin code), not a string-grep
 * check. It pins the behaviour that protects reports from citing evidence that
 * does not exist, and from presenting unsupported claims at full confidence.
 *
 * Two layers are covered, both discovered from the current implementation:
 *
 *   A. Generic evidence-ref hygiene — VES_Evidence_Store::normalize_evidence_refs()
 *      (public, pure). Only canonical `ev_<digits>_<hex>` refs survive; everything
 *      else is dropped; duplicates collapse; the limit is honoured.
 *
 *   B. Brand-audit AI-output validation —
 *      VES_OpenAI_Client::validate_brand_audit_refs_against_allowed() (private,
 *      pure; exercised via Reflection because there is no public wrapper that
 *      avoids a live OpenAI/database round-trip). Refs not present in the
 *      allowed set are removed; items with zero valid refs are downgraded to a
 *      hypothesis with confidence capped at 0.35; items with a single valid ref
 *      are capped at 0.65 ("weak"); items with >= 2 valid refs are "supported"
 *      and keep their confidence.
 *
 * No production code is modified or required to change for this test: the
 * brand-audit validator is reached through Reflection rather than by widening
 * its visibility.
 */

require __DIR__ . '/bootstrap-wp-shims.php';

$root = dirname(__DIR__);
require_once $root . '/includes/class-ves-evidence-store.php';
require_once $root . '/includes/class-ves-openai-client.php';

$state = ['total' => 0, 'pass' => 0, 'fail' => []];

// ---------------------------------------------------------------------------
// A. Generic evidence-ref hygiene: VES_Evidence_Store::normalize_evidence_refs()
//    Canonical format (from the implementation): ^ev_\d+_[a-f0-9]{6,20}$
// ---------------------------------------------------------------------------
$normalized = VES_Evidence_Store::normalize_evidence_refs([
    'ev_12_a1b2c3',      // valid (6 hex)
    'ev_7_deadbeef12',   // valid (10 hex)
    'ev_12_a1b2c3',      // duplicate of the first -> collapses
    'EV_9_ABCDEF',       // invalid: uppercase (regex is lowercase + no /i)
    'ev_9_xyz',          // invalid: non-hex + too short
    'not_a_ref',         // invalid: wrong prefix
    'ev__abcdef',        // invalid: missing numeric id
    '123',               // invalid
]);

ves_test_ok('keeps canonical ev_ refs', in_array('ev_12_a1b2c3', $normalized, true) && in_array('ev_7_deadbeef12', $normalized, true), $state);
ves_test_ok('drops malformed/uppercase/wrong-prefix refs',
    !in_array('EV_9_ABCDEF', $normalized, true)
    && !in_array('ev_9_xyz', $normalized, true)
    && !in_array('not_a_ref', $normalized, true)
    && !in_array('ev__abcdef', $normalized, true)
    && !in_array('123', $normalized, true), $state);
ves_test_ok('collapses duplicate refs', count($normalized) === 2, $state);

// String input is split on whitespace/commas.
$from_string = VES_Evidence_Store::normalize_evidence_refs('ev_1_aaaaaa, ev_2_bbbbbb nonsense');
ves_test_ok('parses refs from a delimited string', $from_string === ['ev_1_aaaaaa', 'ev_2_bbbbbb'], $state);

// Array-of-arrays: the ref is pulled from evidence_ref / ref / id.
$from_objects = VES_Evidence_Store::normalize_evidence_refs([
    ['evidence_ref' => 'ev_3_cccccc'],
    ['ref' => 'ev_4_dddddd'],
    ['id' => 'ev_5_eeeeee'],
    ['unrelated' => 'x'],
]);
ves_test_ok('extracts refs from object rows',
    in_array('ev_3_cccccc', $from_objects, true)
    && in_array('ev_4_dddddd', $from_objects, true)
    && in_array('ev_5_eeeeee', $from_objects, true), $state);

// The limit argument is honoured.
$limited = VES_Evidence_Store::normalize_evidence_refs(['ev_1_aaaaaa', 'ev_2_bbbbbb', 'ev_3_cccccc'], 2);
ves_test_ok('honours the ref count limit', count($limited) === 2, $state);

// ---------------------------------------------------------------------------
// B. Brand-audit AI-output validation:
//    VES_OpenAI_Client::validate_brand_audit_refs_against_allowed($structured, $allowed_refs)
//    Reached via Reflection — visibility is NOT changed.
// ---------------------------------------------------------------------------
$method = (new ReflectionMethod('VES_OpenAI_Client', 'validate_brand_audit_refs_against_allowed'));
$method->setAccessible(true);

$allowed = ['ev_1', 'ev_2', 'ev_3'];
$structured = [
    'insights' => [
        // Two valid refs -> supported, confidence preserved.
        ['title' => 'Supported', 'confidence' => 0.9, 'evidence_refs' => ['ev_1', 'ev_2']],
        // One valid ref -> weak, confidence capped at 0.65.
        ['title' => 'Weak', 'confidence' => 0.9, 'evidence_refs' => ['ev_1']],
        // Zero valid refs (fabricated) -> unsupported hypothesis, confidence capped at 0.35.
        ['title' => 'Fabricated', 'confidence' => 0.9, 'evidence_refs' => ['ev_FAKE', 'ev_999']],
    ],
    'opportunities' => [
        ['title' => 'Op weak', 'confidence' => 0.8, 'evidence_refs' => ['ev_3', 'ev_FAKE']],
    ],
    'evidence_used' => ['ev_1', 'ev_FAKE', 'ev_2'],
];

$result = $method->invoke(null, $structured, $allowed);

$supported = $result['insights'][0];
$weak      = $result['insights'][1];
$fab       = $result['insights'][2];
$op        = $result['opportunities'][0];

// Supported item: keeps both refs, status 'supported', confidence untouched.
ves_test_ok('two valid refs -> supported', ($supported['evidence_validation_status'] ?? '') === 'supported', $state);
ves_test_ok('supported item keeps its confidence', (float) $supported['confidence'] === 0.9, $state);
ves_test_ok('supported item keeps both refs', $supported['evidence_refs'] === ['ev_1', 'ev_2'], $state);

// Weak item: single valid ref, status 'weak', confidence capped at 0.65.
ves_test_ok('one valid ref -> weak', ($weak['evidence_validation_status'] ?? '') === 'weak', $state);
ves_test_ok('weak item confidence capped at 0.65', (float) $weak['confidence'] === 0.65, $state);

// Fabricated item: no valid ref -> unsupported, confidence capped at 0.35,
// hypothesis limitation injected, refs stripped, invalid refs recorded in meta.
ves_test_ok('zero valid refs -> unsupported', ($fab['evidence_validation_status'] ?? '') === 'unsupported', $state);
ves_test_ok('unsupported item confidence capped at 0.35', (float) $fab['confidence'] === 0.35, $state);
ves_test_ok('unsupported item has its fabricated refs removed', $fab['evidence_refs'] === [], $state);
ves_test_ok('unsupported item is flagged in meta', !empty($fab['meta']['unsupported_by_evidence']), $state);
ves_test_ok('unsupported item records the invalid refs',
    in_array('ev_FAKE', (array) ($fab['meta']['invalid_evidence_refs'] ?? []), true)
    && in_array('ev_999', (array) ($fab['meta']['invalid_evidence_refs'] ?? []), true), $state);
ves_test_ok('unsupported item gains a hypothesis limitation',
    !empty(array_filter((array) ($fab['limitations'] ?? []), static function ($l) {
        return stripos((string) $l, 'hypothesis') !== false;
    })), $state);

// Mixed opportunity row: one valid + one fabricated -> only the valid ref kept.
ves_test_ok('opportunity keeps only the valid ref', $op['evidence_refs'] === ['ev_3'], $state);
ves_test_ok('opportunity with one valid ref is weak', ($op['evidence_validation_status'] ?? '') === 'weak', $state);

// evidence_used is filtered down to the allowed set.
ves_test_ok('evidence_used keeps only allowed refs',
    in_array('ev_1', $result['evidence_used'], true)
    && in_array('ev_2', $result['evidence_used'], true)
    && !in_array('ev_FAKE', $result['evidence_used'], true), $state);

// Run-level validation summary reflects that invalid refs were removed.
ves_test_ok('validation summary reports invalid refs were removed',
    ($result['_evidence_ref_validation']['status'] ?? '') === 'invalid_refs_removed'
    && in_array('ev_FAKE', (array) ($result['_evidence_ref_validation']['invalid_evidence_refs'] ?? []), true), $state);

// A fully-clean payload reports a clean status (no invalid refs at all).
$clean = $method->invoke(null, [
    'insights' => [['title' => 'Clean', 'confidence' => 0.7, 'evidence_refs' => ['ev_1', 'ev_2']]],
], $allowed);
ves_test_ok('clean payload reports clean validation status',
    ($clean['_evidence_ref_validation']['status'] ?? '') === 'clean', $state);

ves_test_finish('Evidence-ref validation (anti-hallucination contract)', $state);
