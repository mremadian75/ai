<?php
/**
 * Sprint 2 (Part B) — AI context packaging carries claim-readiness metadata.
 *
 * Executes the REAL VES_AI_Context_Builder::compact_packet_for_context() (via
 * reflection, same pattern as the Sprint 1 evidence-quality test) and verifies
 * that an evidence-bundle packet now carries internal claim_readiness metadata,
 * WITHOUT changing existing fields (notably evidence_refs) and WITHOUT requiring
 * the readiness to be computable for empty packets.
 *
 * This does NOT touch evidence-ref validation (covered by
 * tests/test-evidence-ref-validation.php, left unchanged).
 *
 * Run: php tests/test-ves-ai-context-claim-readiness.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }

require_once dirname(__DIR__) . '/includes/class-ves-evidence-quality.php';
require_once dirname(__DIR__) . '/includes/class-ves-claim-readiness.php';
require_once dirname(__DIR__) . '/includes/class-ves-ai-context-builder.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$compact = new ReflectionMethod('VES_AI_Context_Builder', 'compact_packet_for_context');
$compact->setAccessible(true);
$run = function ($packet) use ($compact) { return $compact->invoke(null, $packet); };

// A packet whose records form a strong, multi-source evidence bundle.
$packet = [
    'entry_type' => 'persistent_workspace_memory',
    'platform' => 'workspace',
    'title' => 'Recent workspace memory',
    'summary' => 'Recent operational memory.',
    'records' => [
        [
            'title' => 'Category growth', 'summary' => 'Strong category growth observed in Q2 across retailers',
            'source_type' => 'news', 'url' => 'https://a.test/1', 'published_at' => '2026-05-01', 'author' => 'bob',
            'text' => 'Quarterly beverage category sales increased across major retailers in spring nationwide',
            'metrics' => ['views' => 9000], 'evidence_refs' => ['ev_12_a1b2c3'],
        ],
        [
            'title' => 'Retailer demand', 'summary' => 'Independent retailer reports confirm rising demand',
            'source_type' => 'blog', 'url' => 'https://b.test/2', 'published_at' => '2026-05-02', 'author' => 'ann',
            'text' => 'Independent retailers separately reported rising beverage demand across the spring quarter',
            'metrics' => ['views' => 4000], 'evidence_refs' => ['ev_13_deadbe'],
        ],
    ],
];
$out = $run($packet);

// ── 1. Packet now carries claim_readiness metadata when evidence exists ──────
$ok(isset($out['claim_readiness']), '1: packet includes claim_readiness');
$ok(in_array($out['claim_readiness'], VES_Claim_Readiness::tiers(), true), '1: claim_readiness uses canonical vocabulary');
$ok(isset($out['claim_strength_score']) && is_int($out['claim_strength_score']), '1: packet includes integer claim_strength_score');
$ok(array_key_exists('claim_limitations', $out) && is_array($out['claim_limitations']), '1: packet includes claim_limitations array');
$ok(in_array($out['recommended_language'] ?? '', VES_Claim_Readiness::languages(), true), '1: packet includes recommended_language');
$ok(in_array($out['minimum_required_action'] ?? '', VES_Claim_Readiness::actions(), true), '1: packet includes minimum_required_action');
$ok($out['claim_readiness'] === 'ready', '1: strong multi-source bundle => ready');

// ── 2. Sprint 1 evidence-quality metadata remains present on records ─────────
$ok(isset($out['records'][0]['evidence_quality_tier']), '2: per-record evidence_quality_tier still present');
$ok(isset($out['records'][0]['evidence_kind']), '2: per-record evidence_kind still present');

// ── 3. evidence_refs are unchanged by the enrichment ─────────────────────────
$ok(($out['records'][0]['evidence_refs'] ?? []) === ['ev_12_a1b2c3'], '3: record 0 evidence_refs passed through unchanged');
$ok(($out['records'][1]['evidence_refs'] ?? []) === ['ev_13_deadbe'], '3: record 1 evidence_refs passed through unchanged');

// ── 4. Context still works when claim readiness cannot be computed ───────────
$empty_packet = ['entry_type' => 'excluded_assumptions', 'platform' => 'workspace', 'title' => 'X', 'summary' => 'Y', 'records' => []];
$out_empty = $run($empty_packet);
$ok(is_array($out_empty), '4: packet with no records compacts without error');
$ok(!isset($out_empty['claim_readiness']), '4: no claim_readiness attached when there is no evidence bundle');

// ── 5. A single-source bundle is capped at cautious in context ───────────────
$single_packet = [
    'entry_type' => 'persistent_workspace_memory', 'platform' => 'workspace', 'title' => 'Single', 'summary' => 'one',
    'records' => [[
        'title' => 'Solo', 'summary' => 'one strong source only',
        'source_type' => 'news', 'url' => 'https://a.test/1', 'published_at' => '2026-05-01', 'author' => 'bob',
        'text' => 'Quarterly beverage category sales increased across major retailers in spring nationwide',
        'metrics' => ['views' => 9000], 'evidence_refs' => ['ev_12_a1b2c3'],
    ]],
];
$out_single = $run($single_packet);
$ok($out_single['claim_readiness'] === 'cautious', '5: single-source bundle => cautious in context');

echo "\nAI context claim-readiness packaging (Part B): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
