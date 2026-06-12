<?php
/**
 * Intelligence Map (relationship graph) — deterministic builder tests.
 *
 * Exercises the PURE graph assembly (VES_Intelligence_Map::assemble_graph) and
 * input sanitisation (::sanitize_args) without a database. Verifies node/edge
 * construction from existing record refs, bounding/caps, filtering, empty state,
 * non-mutation, and safe handling of malformed input.
 *
 * Run: php tests/test-ves-intelligence-map.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('Y-m-d H:i:s'); } }

require_once dirname(__DIR__) . '/includes/class-ves-intelligence-map.php';

$pass = 0; $fail = 0;
$ok = function ($cond, $label) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
};
$node_by_id = function (array $g, $id) { foreach ($g['nodes'] as $n) { if ($n['id'] === $id) { return $n; } } return null; };
$has_edge = function (array $g, $from, $to, $type) {
    foreach ($g['edges'] as $e) { if ($e['from'] === $from && $e['to'] === $to && $e['type'] === $type) { return true; } }
    return false;
};

// ── Representative, linked record sets (the real chain) ──────────────────────
$sets = [
    'runs' => [
        ['run_id' => 'bundle_A', 'report_title' => 'Spring beverage scan', 'run_status' => 'completed', 'created_at' => '2026-05-10 10:00:00'],
    ],
    'insights' => [
        ['id' => 11, 'run_id' => 'bundle_A', 'title' => 'Category growth', 'status' => 'approved', 'confidence' => 0.82, 'evidence_refs' => ['ev_1_aa', 'ev_2_bb'], 'evidence_validation_status' => 'supported', 'created_at' => '2026-05-10 11:00:00'],
        ['id' => 12, 'run_id' => 'bundle_A', 'title' => 'Weak signal', 'status' => 'proposed', 'confidence' => 0.30, 'evidence_refs' => [], 'created_at' => '2026-05-10 11:05:00'],
    ],
    'opportunities' => [
        ['id' => 21, 'run_id' => 'bundle_A', 'title' => 'Launch campaign', 'status' => 'approved', 'confidence' => 0.7, 'insight_ids' => ['ins_11'], 'priority_score' => 88, 'created_at' => '2026-05-10 12:00:00'],
    ],
    'briefs' => [
        ['id' => 31, 'run_id' => 'bundle_A', 'title' => 'Campaign brief', 'status' => 'draft', 'opportunity_id' => 21, 'insight_ids' => ['ins_11'], 'created_at' => '2026-05-10 13:00:00'],
    ],
    'memory' => [
        ['id' => 41, 'title' => 'Analysis output', 'source_type' => 'analysis_output', 'source_id' => 'bundle_A', 'is_pinned' => 1, 'created_at' => '2026-05-10 14:00:00'],
    ],
];

$g = VES_Intelligence_Map::assemble_graph($sets, ['workspace_id' => 5]);

// ── 1. Nodes for each object type exist ──────────────────────────────────────
$ok($node_by_id($g, 'run:bundle_A') !== null, '1: run node built');
$ok($node_by_id($g, 'insight:11') !== null, '1: insight node built');
$ok($node_by_id($g, 'opportunity:21') !== null, '1: opportunity node built');
$ok($node_by_id($g, 'brief:31') !== null, '1: brief node built');
$ok($node_by_id($g, 'memory:41') !== null, '1: memory node built');
$ok($node_by_id($g, 'evidence:bundle_A') !== null, '1: aggregated evidence node built for run with refs');

// ── 2. Edges follow the real chain ───────────────────────────────────────────
$ok($has_edge($g, 'insight:11', 'run:bundle_A', 'generated_by'), '2: insight --generated_by--> run');
$ok($has_edge($g, 'opportunity:21', 'insight:11', 'derived_from'), '2: opportunity --derived_from--> insight');
$ok($has_edge($g, 'opportunity:21', 'brief:31', 'converted_to'), '2: opportunity --converted_to--> brief');
$ok($has_edge($g, 'brief:31', 'insight:11', 'derived_from'), '2: brief --derived_from--> insight');
$ok($has_edge($g, 'memory:41', 'run:bundle_A', 'stored_as_memory'), '2: memory --stored_as_memory--> run');
$ok($has_edge($g, 'evidence:bundle_A', 'run:bundle_A', 'created_from'), '2: evidence --created_from--> run');

// ── 3. No dangling edges; canonical vocab ────────────────────────────────────
$ids = array_map(function ($n) { return $n['id']; }, $g['nodes']);
$dangling = false;
foreach ($g['edges'] as $e) { if (!in_array($e['from'], $ids, true) || !in_array($e['to'], $ids, true)) { $dangling = true; } }
$ok(!$dangling, '3: no edge references a missing node');
$bad_type = false;
foreach ($g['edges'] as $e) { if (!in_array($e['type'], VES_Intelligence_Map::EDGE_TYPES, true)) { $bad_type = true; } }
$ok(!$bad_type, '3: all edge types are canonical');

// ── 4. Node degree (relationship_count) is attached ──────────────────────────
$run_node = $node_by_id($g, 'run:bundle_A');
$ok(isset($run_node['relationship_count']) && $run_node['relationship_count'] >= 3, '4: run node aggregates multiple relationships');

// ── 5. Confidence normalised; metadata preserved ─────────────────────────────
$ins = $node_by_id($g, 'insight:11');
$ok($ins['confidence'] === 82, '5: 0..1 confidence normalised to 0..100');
$ok($ins['evidence_ref_count'] === 2, '5: evidence_ref_count preserved on insight node');
$ok($ins['evidence_validation_status'] === 'supported', '5: evidence_validation_status carried through');

// ── 6. Stats + non-empty ─────────────────────────────────────────────────────
$ok($g['empty'] === false, '6: connected graph is not empty');
$ok($g['stats']['node_count'] === count($g['nodes']) && $g['stats']['edge_count'] === count($g['edges']), '6: stats counts match');
$ok(($g['stats']['nodes_by_type']['insight'] ?? 0) === 2, '6: nodes_by_type counts insights');

// ── 7. Type filter limits node set ───────────────────────────────────────────
$only = VES_Intelligence_Map::assemble_graph($sets, ['workspace_id' => 5, 'types' => ['run', 'insight']]);
$types_present = array_unique(array_map(function ($n) { return $n['type']; }, $only['nodes']));
sort($types_present);
$ok($types_present === ['insight', 'run'], '7: type filter restricts node types');

// ── 8. min_confidence filter drops weak insights ─────────────────────────────
$strong = VES_Intelligence_Map::assemble_graph($sets, ['workspace_id' => 5, 'min_confidence' => 60]);
$ok($node_by_id($strong, 'insight:12') === null, '8: low-confidence insight excluded by min_confidence');
$ok($node_by_id($strong, 'insight:11') !== null, '8: high-confidence insight retained');

// ── 9. max_nodes cap keeps structural hubs (runs) ────────────────────────────
$big_sets = ['runs' => [['run_id' => 'R', 'created_at' => '2026-05-01 00:00:00']], 'insights' => [], 'opportunities' => [], 'briefs' => [], 'memory' => []];
for ($i = 1; $i <= 50; $i++) { $big_sets['insights'][] = ['id' => $i, 'run_id' => 'R', 'title' => 'I' . $i, 'status' => 'proposed', 'confidence' => $i, 'created_at' => '2026-05-01 00:00:0' . ($i % 10)]; }
$capped = VES_Intelligence_Map::assemble_graph($big_sets, ['workspace_id' => 5, 'max_nodes' => 12]);
$ok(count($capped['nodes']) <= 12, '9: node count respects max_nodes cap');
$ok($node_by_id($capped, 'run:R') !== null, '9: run hub survives capping');
$ok($capped['stats']['capped'] === true, '9: stats flag capped=true');

// ── 10. Empty state when nothing connects ────────────────────────────────────
$empty = VES_Intelligence_Map::assemble_graph(['runs' => [], 'insights' => [], 'opportunities' => [], 'briefs' => [], 'memory' => []], ['workspace_id' => 5]);
$ok($empty['empty'] === true && $empty['empty_message'] !== '', '10: empty input yields empty state with message');

// ── 11. Input is not mutated ─────────────────────────────────────────────────
$before = $sets;
VES_Intelligence_Map::assemble_graph($sets, ['workspace_id' => 5]);
$ok($sets === $before, '11: assemble_graph does not mutate the input sets');

// ── 12. Malformed input handled safely ───────────────────────────────────────
$bad = VES_Intelligence_Map::assemble_graph(['runs' => 'x', 'insights' => [null, 'y', ['id' => 0]], 'opportunities' => null], ['workspace_id' => 5]);
$ok(is_array($bad['nodes']) && is_array($bad['edges']), '12: malformed sets handled without error');

// ── 13. sanitize_args bounds and canonicalises ───────────────────────────────
$sa = VES_Intelligence_Map::sanitize_args(['workspace_id' => '7', 'days' => 9999, 'max_nodes' => 999999, 'types' => 'run, bogus,insight', 'status' => 'Approved, DRAFT!!', 'run_id' => 'a b/<x>c']);
$ok($sa['workspace_id'] === 7, '13: workspace_id cast to int');
$ok($sa['days'] === 365, '13: days clamped to max 365');
$ok($sa['max_nodes'] === VES_Intelligence_Map::HARD_MAX_NODES, '13: max_nodes clamped to hard cap');
$ok($sa['types'] === ['run', 'insight'], '13: unknown types dropped, known kept');
$ok($sa['statuses'] === ['approved', 'draft'], '13: statuses lowercased/sanitised');
$ok(strpos($sa['run_id'], ' ') === false && strpos($sa['run_id'], '<') === false, '13: run_id stripped of unsafe chars');

echo "\nIntelligence Map relationship-graph builder: {$pass} passed, {$fail} failed\n";
if ($fail > 0) { exit(1); }
