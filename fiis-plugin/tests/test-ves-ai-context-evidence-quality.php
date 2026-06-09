<?php
/**
 * Sprint 1 (Part C) — AI context packaging carries evidence-quality metadata.
 *
 * Executes the REAL VES_AI_Context_Builder::compact_record_for_context() (via
 * reflection, the same pattern used by other functional tests) and verifies that
 * the structured context now carries internal evidence_quality_tier /
 * evidence_kind / evidence_limitations, WITHOUT changing any existing field
 * (notably evidence_refs) and without requiring quality metadata to be present.
 *
 * This does NOT touch evidence-ref validation; the strict validation contract is
 * covered separately by tests/test-evidence-ref-validation.php (left unchanged).
 *
 * Run: php tests/test-ves-ai-context-evidence-quality.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }

require_once dirname(__DIR__) . '/includes/class-ves-evidence-quality.php';
require_once dirname(__DIR__) . '/includes/class-ves-ai-context-builder.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$compact = new ReflectionMethod('VES_AI_Context_Builder', 'compact_record_for_context');
$compact->setAccessible(true);
$run = function ($record) use ($compact) { return $compact->invoke(null, $record); };

// A well-formed evidence-like context record (mixed valid/invalid refs to prove
// compaction does not perform — nor change — evidence-ref validation here).
$record = [
    'title' => 'Beverage launch', 'summary' => 'Strong category growth observed in Q2 across retailers',
    'platform' => 'tiktok', 'source_type' => 'social',
    'url' => 'https://x.test/a', 'published_at' => '2026-05-01', 'author' => 'bob',
    'text' => 'Strong category growth observed in Q2 across major retailers per reports',
    'metrics' => ['views' => 9000], 'confidence_score' => 0.8,
    'evidence_refs' => ['ev_12_a1b2c3', 'ev_FAKE', 'ev_13_deadbe'],
];
$out = $run($record);

// ── 1. Compact context now carries the evidence-quality metadata ─────────────
$ok(isset($out['evidence_quality_tier']), '1: compact context includes evidence_quality_tier');
$ok(isset($out['evidence_kind']), '1: compact context includes evidence_kind');
$ok(array_key_exists('evidence_limitations', $out) && is_array($out['evidence_limitations']), '1: compact context includes evidence_limitations array');
$ok(in_array($out['evidence_quality_tier'], VES_Evidence_Quality::tiers(), true), '1: tier uses the canonical vocabulary');

// ── 2. evidence_refs handling is unchanged by the enrichment ─────────────────
// compact_record_for_context only sanitizes/caps the list (it never validated
// refs); enrichment must not alter that behavior.
$expected_refs = $record['evidence_refs']; // all three survive sanitize (no validation here)
$ok(($out['evidence_refs'] ?? []) === $expected_refs, '2: evidence_refs list is passed through unchanged (no new filtering added)');

// ── 3. Existing scalar fields are untouched ──────────────────────────────────
$ok(($out['title'] ?? '') === 'Beverage launch', '3: existing title field is unchanged');
$ok(isset($out['confidence_score']) && (float) $out['confidence_score'] === 0.8, '3: existing confidence_score is unchanged');

// ── 4. Packaging still works when quality metadata is effectively missing ────
$minimal = ['title' => 'X', 'summary' => 'short'];
$out_min = $run($minimal);
$ok(isset($out_min['evidence_quality_tier']) && $out_min['evidence_quality_tier'] === 'insufficient', '4: minimal record still packages, classified insufficient');
$ok(is_array($out_min['evidence_limitations'] ?? null), '4: minimal record still yields a limitations array');

// ── 5. Empty/invalid record does not break compaction ────────────────────────
$out_empty = $run([]);
$ok(is_array($out_empty), '5: empty record compacts to an array without error');
$ok(isset($out_empty['evidence_quality_tier']), '5: empty record still carries an evidence_quality_tier');

echo "\nAI context evidence-quality packaging (Part C): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
