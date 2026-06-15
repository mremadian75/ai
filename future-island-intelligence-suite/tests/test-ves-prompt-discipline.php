<?php
/**
 * Sprint 2 (Part C) — analysis discipline instruction is present in the central
 * claim-producing prompts, without weakening existing evidence-ref rules.
 *
 * Run: php tests/test-ves-prompt-discipline.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); } }
require_once dirname(__DIR__) . '/includes/class-ves-ai-prompt-templates.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};

$needle = 'Use claim_readiness and evidence_quality metadata to calibrate language';

$insight = VES_AI_Prompt_Templates::get('insight_generation');
$audit   = VES_AI_Prompt_Templates::get('brand_audit_final_report');

// ── 1. Discipline instruction added to the insight-generation prompt ─────────
$ok(is_array($insight) && strpos((string) $insight['system'], $needle) !== false, '1: insight_generation system carries the discipline instruction');
$ok(strpos((string) $insight['system'], 'avoid unsupported claims') !== false, '1: discipline instruction mentions avoiding unsupported claims');

// ── 2. Discipline instruction added to the brand-audit final report prompt ───
$ok(is_array($audit) && strpos((string) $audit['system'], $needle) !== false, '2: brand_audit_final_report system carries the discipline instruction');

// ── 3. Existing evidence-ref rules are preserved (not weakened) ──────────────
$ok(strpos((string) $audit['system'], 'Only use evidence_refs from the Allowed evidence refs list. Never create fake refs.') !== false, '3: strict allowed-refs rule preserved');
$ok(strpos((string) $audit['system'], 'leave evidence_refs empty and mark the item as hypothesis/limitation') !== false, '3: empty-refs-for-unsupported rule preserved');
$ok(strpos((string) $insight['system'], 'Every insight must be linked to evidence.') !== false, '3: insight evidence-linkage rule preserved');

// ── 4. Recommendation-must-cite language is present ──────────────────────────
$ok(strpos((string) $insight['system'], 'Recommendations must cite supporting evidence_refs when available.') !== false, '4: recommendation must cite evidence_refs');

echo "\nPrompt discipline instruction (Part C): {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
