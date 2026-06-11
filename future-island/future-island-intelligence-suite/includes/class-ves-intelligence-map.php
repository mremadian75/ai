<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Intelligence_Map — deterministic, read-only relationship-graph builder.
 *
 * Optional visual layer for the operator/admin. It DERIVES a small, bounded
 * relationship graph from records that ALREADY exist (trend runs, insights,
 * opportunities, briefs, memory, evidence refs). It is a read-only projection:
 *
 *   - It never mutates records, options, or transients (besides an optional
 *     short-lived cache write keyed by the sanitized request).
 *   - It makes NO AI / network call and consumes NO credits.
 *   - It creates NO database tables — every edge comes from existing foreign
 *     keys / JSON ref arrays (run_id, insight_ids, opportunity_id, evidence
 *     refs, memory source_id).
 *   - All queries are workspace-scoped and bounded (per-type + total node caps,
 *     date window, optional single-run focus).
 *
 * The data-gathering (DB) and the graph-assembly (pure) are split so the graph
 * logic is unit-testable without a WordPress runtime.
 */
final class VES_Intelligence_Map {

    /** Canonical node types this map renders. */
    const NODE_TYPES = ['run', 'evidence', 'insight', 'opportunity', 'brief', 'memory'];

    /** Canonical relationship (edge) vocabulary. */
    const EDGE_TYPES = ['generated_by', 'derived_from', 'created_from', 'converted_to', 'supports', 'stored_as_memory'];

    const DEFAULT_DAYS = 30;
    const DEFAULT_MAX_NODES = 160;
    const HARD_MAX_NODES = 400;
    const MAX_RUNS = 12;            // distinct runs pulled for a workspace map
    const PER_RUN_LIMIT = 40;       // per-type records pulled per run
    const MEMORY_LIMIT = 40;
    const CACHE_TTL = 120;          // seconds; short-lived read cache

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Normalize raw request args into a safe, bounded option set.
     * Pure (no DB) so it is directly unit-testable.
     */
    public static function sanitize_args($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $types = $raw['types'] ?? [];
        if (is_string($types)) { $types = preg_split('/[,\s]+/', $types); }
        $types = array_values(array_intersect(
            array_map('strval', array_map(static function ($t) {
                return strtolower(preg_replace('/[^a-z_]/i', '', (string) $t));
            }, (array) $types)),
            self::NODE_TYPES
        ));

        $statuses = $raw['statuses'] ?? ($raw['status'] ?? []);
        if (is_string($statuses)) { $statuses = preg_split('/[,\s]+/', $statuses); }
        $statuses = array_values(array_filter(array_map(static function ($s) {
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
        }, (array) $statuses), static function ($s) { return $s !== ''; }));

        return [
            'workspace_id' => max(0, (int) ($raw['workspace_id'] ?? 0)),
            'user_id'      => max(0, (int) ($raw['user_id'] ?? 0)),
            'run_id'       => self::clean_run_id($raw['run_id'] ?? ''),
            'days'         => max(1, min(365, (int) ($raw['days'] ?? self::DEFAULT_DAYS))),
            'min_confidence' => max(0, min(100, (int) ($raw['min_confidence'] ?? 0))),
            'types'        => $types,            // empty => all types
            'statuses'     => array_slice($statuses, 0, 12), // empty => all statuses
            'max_nodes'    => max(10, min(self::HARD_MAX_NODES, (int) ($raw['max_nodes'] ?? self::DEFAULT_MAX_NODES))),
        ];
    }

    /**
     * Build a graph for the given (already-sanitized or raw) args.
     * Gathers existing records (workspace-scoped, bounded) then assembles.
     *
     * @return array{nodes:array,edges:array,stats:array,filters:array,empty:bool,empty_message:string,generated_at:string}
     */
    public static function build_graph($raw_args): array {
        $args = self::sanitize_args($raw_args);
        if ($args['workspace_id'] <= 0) {
            return self::empty_result($args, 'Select a workspace to view its intelligence map.');
        }

        $cache_key = 'ves_imap_' . md5(wp_json_encode($args));
        $cached = function_exists('get_transient') ? get_transient($cache_key) : false;
        if (is_array($cached)) { return $cached; }

        $sets = self::gather($args);
        $result = self::assemble_graph($sets, $args);

        if (function_exists('set_transient')) { set_transient($cache_key, $result, self::CACHE_TTL); }
        return $result;
    }

    /**
     * Pure graph assembly from already-fetched record sets. No DB, no mutation
     * of the input arrays. This is the unit-tested core.
     *
     * @param array $sets ['runs'=>[],'insights'=>[],'opportunities'=>[],'briefs'=>[],'memory'=>[]]
     * @param array $args  sanitized args (for filters/caps/empty message)
     */
    public static function assemble_graph(array $sets, array $args = []): array {
        $args = self::sanitize_args($args + ['workspace_id' => 1]); // tolerate raw args in tests
        $type_filter = $args['types'];
        $status_filter = $args['statuses'];
        $min_conf = (int) $args['min_confidence'];
        $allow = static function ($type) use ($type_filter) {
            return empty($type_filter) || in_array($type, $type_filter, true);
        };

        $nodes = [];   // id => node
        $edges = [];   // list of [from,to,type,weight]
        $add_node = static function (array $node) use (&$nodes) {
            if (!isset($node['id']) || $node['id'] === '') { return; }
            if (!isset($nodes[$node['id']])) { $nodes[$node['id']] = $node; }
        };

        $runs   = self::as_list($sets['runs'] ?? []);
        $ins    = self::as_list($sets['insights'] ?? []);
        $opps   = self::as_list($sets['opportunities'] ?? []);
        $briefs = self::as_list($sets['briefs'] ?? []);
        $mems   = self::as_list($sets['memory'] ?? []);

        $status_ok = static function ($record) use ($status_filter) {
            if (empty($status_filter)) { return true; }
            $s = strtolower((string) ($record['status'] ?? ''));
            return $s === '' || in_array($s, $status_filter, true);
        };
        $conf_of = static function ($record) {
            $c = $record['confidence'] ?? null;
            if ($c === null) { return null; }
            $c = (float) $c;
            return $c <= 1.0 ? (int) round($c * 100) : (int) round($c); // accept 0..1 or 0..100
        };

        // ── Run nodes (and an aggregated evidence node per run) ──────────────
        $run_evidence = []; // run_id => total evidence refs seen
        foreach ($runs as $run) {
            $rid = self::clean_run_id($run['run_id'] ?? ($run['bundle_id'] ?? ($run['id'] ?? '')));
            if ($rid === '' || !$allow('run')) { continue; }
            $add_node([
                'id' => 'run:' . $rid,
                'type' => 'run',
                'label' => self::label($run, ['report_title', 'title', 'label'], 'Run ' . $rid),
                'status' => (string) ($run['run_status'] ?? ($run['status'] ?? '')),
                'created_at' => (string) ($run['created_at'] ?? ''),
                'confidence' => $conf_of($run),
                'source' => (string) ($run['platform'] ?? ($run['source_type'] ?? '')),
                'summary' => self::summary($run),
                'object_ref' => ['type' => 'run', 'id' => $rid],
            ]);
        }

        // helper to ensure a run node exists (runs may only be referenced by children)
        $ensure_run = static function ($rid) use (&$nodes, $allow, $add_node) {
            $rid = self::clean_run_id($rid);
            if ($rid === '' || !$allow('run')) { return ''; }
            $id = 'run:' . $rid;
            if (!isset($nodes[$id])) {
                $add_node(['id' => $id, 'type' => 'run', 'label' => 'Run ' . $rid, 'status' => '', 'created_at' => '', 'confidence' => null, 'source' => '', 'summary' => '', 'object_ref' => ['type' => 'run', 'id' => $rid]]);
            }
            return $id;
        };

        // ── Insight nodes + edges to run / evidence ──────────────────────────
        foreach ($ins as $r) {
            if (!$allow('insight') || !$status_ok($r)) { continue; }
            $conf = $conf_of($r);
            if ($min_conf > 0 && $conf !== null && $conf < $min_conf) { continue; }
            $id = 'insight:' . (int) ($r['id'] ?? 0);
            if ($id === 'insight:0') { continue; }
            $ref_count = is_array($r['evidence_refs'] ?? null) ? count($r['evidence_refs']) : (int) ($r['evidence_ref_count'] ?? 0);
            $add_node([
                'id' => $id, 'type' => 'insight',
                'label' => self::label($r, ['title'], 'Insight'),
                'status' => (string) ($r['status'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'confidence' => $conf,
                'source' => (string) ($r['insight_type'] ?? ''),
                'summary' => self::summary($r),
                'evidence_ref_count' => $ref_count,
                'evidence_validation_status' => (string) ($r['evidence_validation_status'] ?? ''),
                'object_ref' => ['type' => 'insight', 'id' => (int) $r['id'], 'run_id' => self::clean_run_id($r['run_id'] ?? '')],
            ]);
            $rid = self::clean_run_id($r['run_id'] ?? '');
            if ($rid !== '') {
                $run_id = $ensure_run($rid);
                if ($run_id !== '') { $edges[] = ['from' => $id, 'to' => $run_id, 'type' => 'generated_by', 'weight' => 1.0]; }
                if ($ref_count > 0) { $run_evidence[$rid] = ($run_evidence[$rid] ?? 0) + $ref_count; }
            }
        }

        // ── Opportunity nodes + edges to run and insights ────────────────────
        foreach ($opps as $r) {
            if (!$allow('opportunity') || !$status_ok($r)) { continue; }
            $conf = $conf_of($r);
            if ($min_conf > 0 && $conf !== null && $conf < $min_conf) { continue; }
            $id = 'opportunity:' . (int) ($r['id'] ?? 0);
            if ($id === 'opportunity:0') { continue; }
            $add_node([
                'id' => $id, 'type' => 'opportunity',
                'label' => self::label($r, ['title'], 'Opportunity'),
                'status' => (string) ($r['status'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'confidence' => $conf,
                'source' => (string) ($r['opportunity_type'] ?? ''),
                'summary' => self::summary($r, ['description', 'summary']),
                'priority_score' => isset($r['priority_score']) ? (float) $r['priority_score'] : null,
                'object_ref' => ['type' => 'opportunity', 'id' => (int) $r['id'], 'run_id' => self::clean_run_id($r['run_id'] ?? '')],
            ]);
            $rid = self::clean_run_id($r['run_id'] ?? '');
            if ($rid !== '') { $run_id = $ensure_run($rid); if ($run_id !== '') { $edges[] = ['from' => $id, 'to' => $run_id, 'type' => 'generated_by', 'weight' => 0.8]; } }
            foreach (self::ref_ids($r['insight_ids'] ?? []) as $iid) {
                $edges[] = ['from' => $id, 'to' => 'insight:' . $iid, 'type' => 'derived_from', 'weight' => 1.0];
            }
        }

        // ── Brief nodes + edges to opportunity / insights / run ──────────────
        foreach ($briefs as $r) {
            if (!$allow('brief') || !$status_ok($r)) { continue; }
            $id = 'brief:' . (int) ($r['id'] ?? 0);
            if ($id === 'brief:0') { continue; }
            $add_node([
                'id' => $id, 'type' => 'brief',
                'label' => self::label($r, ['title', 'key_insight', 'brief_type'], 'Brief'),
                'status' => (string) ($r['status'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'confidence' => $conf_of($r),
                'source' => (string) ($r['brief_type'] ?? ''),
                'summary' => self::summary($r, ['key_insight', 'summary']),
                'object_ref' => ['type' => 'brief', 'id' => (int) $r['id'], 'run_id' => self::clean_run_id($r['run_id'] ?? '')],
            ]);
            $oid = (int) ($r['opportunity_id'] ?? 0);
            if ($oid > 0) { $edges[] = ['from' => 'opportunity:' . $oid, 'to' => $id, 'type' => 'converted_to', 'weight' => 1.0]; }
            foreach (self::ref_ids($r['insight_ids'] ?? []) as $iid) {
                $edges[] = ['from' => $id, 'to' => 'insight:' . $iid, 'type' => 'derived_from', 'weight' => 0.8];
            }
            $rid = self::clean_run_id($r['run_id'] ?? '');
            if ($rid !== '' && $oid <= 0) { $run_id = $ensure_run($rid); if ($run_id !== '') { $edges[] = ['from' => $id, 'to' => $run_id, 'type' => 'generated_by', 'weight' => 0.6]; } }
        }

        // ── Memory nodes + edges to run (when sourced from a run) ─────────────
        foreach ($mems as $r) {
            if (!$allow('memory') || !$status_ok($r)) { continue; }
            $id = 'memory:' . (int) ($r['id'] ?? 0);
            if ($id === 'memory:0') { continue; }
            $add_node([
                'id' => $id, 'type' => 'memory',
                'label' => self::label($r, ['title'], 'Memory'),
                'status' => !empty($r['is_pinned']) ? 'pinned' : 'recent',
                'created_at' => (string) ($r['created_at'] ?? ''),
                'confidence' => null,
                'source' => (string) ($r['memory_type'] ?? ($r['source_type'] ?? '')),
                'summary' => self::summary($r),
                'object_ref' => ['type' => 'memory', 'id' => (int) $r['id']],
            ]);
            $rid = self::clean_run_id($r['source_id'] ?? '');
            if ($rid !== '' && isset($nodes['run:' . $rid])) {
                $edges[] = ['from' => $id, 'to' => 'run:' . $rid, 'type' => 'stored_as_memory', 'weight' => 0.7];
            }
        }

        // ── Aggregated evidence node per run (represents captured signals) ────
        if ($allow('evidence')) {
            foreach ($run_evidence as $rid => $count) {
                $run_id = 'run:' . $rid;
                if (!isset($nodes[$run_id]) || $count <= 0) { continue; }
                $eid = 'evidence:' . $rid;
                $add_node([
                    'id' => $eid, 'type' => 'evidence',
                    'label' => 'Evidence · ' . (int) $count . ' refs',
                    'status' => '', 'created_at' => '', 'confidence' => null, 'source' => '',
                    'summary' => 'Aggregated supporting evidence references captured by this run.',
                    'evidence_ref_count' => (int) $count,
                    'object_ref' => ['type' => 'evidence', 'run_id' => $rid],
                ]);
                $edges[] = ['from' => $eid, 'to' => $run_id, 'type' => 'created_from', 'weight' => 0.5];
            }
        }

        // ── Drop edges whose endpoints are not present; cap nodes ────────────
        $edges = self::valid_edges($edges, $nodes);
        [$nodes, $edges] = self::cap_nodes($nodes, $edges, (int) $args['max_nodes']);
        $nodes = self::attach_degree($nodes, $edges);

        $node_list = array_values($nodes);
        $stats = self::stats($node_list, $edges, $args);
        $empty = count($node_list) < 2 || count($edges) < 1;

        return [
            'nodes' => $node_list,
            'edges' => array_values($edges),
            'stats' => $stats,
            'filters' => [
                'workspace_id' => (int) $args['workspace_id'],
                'run_id' => $args['run_id'],
                'days' => (int) $args['days'],
                'types' => $args['types'],
                'statuses' => $args['statuses'],
                'min_confidence' => (int) $args['min_confidence'],
                'max_nodes' => (int) $args['max_nodes'],
            ],
            'empty' => $empty,
            'empty_message' => $empty ? 'This map will become useful once runs, signals, insights, and briefs are connected.' : '',
            'generated_at' => function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];
    }

    // ── Data gathering (DB; defensive, bounded, workspace-scoped) ─────────────

    private static function gather(array $args): array {
        $ws = (int) $args['workspace_id'];
        $sets = ['runs' => [], 'insights' => [], 'opportunities' => [], 'briefs' => [], 'memory' => []];

        if ($args['run_id'] !== '') {
            $rid = $args['run_id'];
            $sets['insights']      = self::call(['VES_Insight_Records', 'list_by_run'], [$rid, self::PER_RUN_LIMIT]);
            $sets['opportunities'] = self::call(['VES_Opportunity_Records', 'list_by_run'], [$rid, self::PER_RUN_LIMIT]);
            $sets['briefs']        = self::call(['VES_Brief_Records', 'list_by_run'], [$rid, self::PER_RUN_LIMIT]);
            $run = self::call_single(['VES_Trend_Run_Store', 'get_run_by_bundle_id'], [$rid]);
            if (is_array($run)) { $run['run_id'] = $run['run_id'] ?? $rid; $sets['runs'][] = $run; }
        } else {
            $sets['insights']      = self::call(['VES_Insight_Records', 'list_by_workspace'], [$ws, 120]);
            $sets['opportunities'] = self::call(['VES_Opportunity_Records', 'list_by_workspace'], [$ws, 120]);

            // Distinct, recent run ids referenced by the workspace records.
            $run_ids = [];
            foreach (array_merge($sets['insights'], $sets['opportunities']) as $r) {
                $rid = self::clean_run_id($r['run_id'] ?? '');
                if ($rid !== '') { $run_ids[$rid] = true; }
            }
            $run_ids = array_slice(array_keys($run_ids), 0, self::MAX_RUNS);
            foreach ($run_ids as $rid) {
                $briefs = self::call(['VES_Brief_Records', 'list_by_run'], [$rid, self::PER_RUN_LIMIT]);
                foreach ($briefs as $b) { $sets['briefs'][] = $b; }
                $run = self::call_single(['VES_Trend_Run_Store', 'get_run_by_bundle_id'], [$rid]);
                if (is_array($run)) { $run['run_id'] = $run['run_id'] ?? $rid; $sets['runs'][] = $run; }
            }
        }

        $sets['memory'] = self::call(['VES_Memory_Records', 'list_for_admin'], [['workspace_id' => $ws, 'limit' => self::MEMORY_LIMIT]]);
        return $sets;
    }

    private static function call($callable, array $params): array {
        if (is_array($callable) && class_exists($callable[0]) && method_exists($callable[0], $callable[1])) {
            $out = call_user_func_array($callable, $params);
            return is_array($out) ? $out : [];
        }
        return [];
    }

    private static function call_single($callable, array $params) {
        if (is_array($callable) && class_exists($callable[0]) && method_exists($callable[0], $callable[1])) {
            return call_user_func_array($callable, $params);
        }
        return null;
    }

    // ── Pure helpers ──────────────────────────────────────────────────────────

    private static function as_list($value): array {
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $item) { if (is_array($item)) { $out[] = $item; } }
        return $out;
    }

    private static function clean_run_id($value): string {
        if (!is_scalar($value)) { return ''; }
        $v = trim((string) $value);
        $v = preg_replace('/[^A-Za-z0-9_\-:.]/', '', $v);
        return (string) substr($v, 0, 120);
    }

    private static function ref_ids($value): array {
        $out = [];
        foreach ((array) $value as $ref) {
            if (is_array($ref)) { $ref = $ref['id'] ?? ($ref['insight_id'] ?? ''); }
            if (!is_scalar($ref)) { continue; }
            // Refs may be raw ints or prefixed tokens like "ins_42"; extract digits.
            if (preg_match('/(\d+)/', (string) $ref, $m)) { $out[] = (int) $m[1]; }
        }
        return array_values(array_unique(array_filter($out, static function ($n) { return $n > 0; })));
    }

    private static function label(array $r, array $keys, string $fallback): string {
        foreach ($keys as $k) {
            if (!empty($r[$k]) && is_scalar($r[$k])) {
                return self::truncate(self::clean_text((string) $r[$k]), 90);
            }
        }
        return $fallback;
    }

    private static function summary(array $r, array $keys = ['summary', 'description']): string {
        foreach ($keys as $k) {
            if (!empty($r[$k]) && is_scalar($r[$k])) { return self::truncate(self::clean_text((string) $r[$k]), 320); }
        }
        return '';
    }

    private static function clean_text(string $v): string {
        $v = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($v) : strip_tags($v);
        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    private static function truncate(string $v, int $max): string {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($v) > $max ? rtrim(mb_substr($v, 0, $max)) . '…' : $v;
        }
        return strlen($v) > $max ? rtrim(substr($v, 0, $max)) . '…' : $v;
    }

    private static function valid_edges(array $edges, array $nodes): array {
        $seen = [];
        $out = [];
        foreach ($edges as $e) {
            $from = $e['from'] ?? ''; $to = $e['to'] ?? '';
            if ($from === '' || $to === '' || $from === $to) { continue; }
            if (!isset($nodes[$from]) || !isset($nodes[$to])) { continue; }
            $key = $from . '|' . $to . '|' . ($e['type'] ?? '');
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Cap the node set to max_nodes. Runs/evidence are kept as structural hubs;
     * remaining nodes are ranked by (confidence desc, created_at desc). Edges are
     * then re-filtered to the surviving node set.
     */
    private static function cap_nodes(array $nodes, array $edges, int $max): array {
        if (count($nodes) <= $max) { return [$nodes, $edges]; }
        $priority = ['run' => 0, 'evidence' => 1];
        $keep = [];
        $rest = [];
        foreach ($nodes as $id => $n) {
            if (isset($priority[$n['type']])) { $keep[$id] = $n; } else { $rest[$id] = $n; }
        }
        uasort($rest, static function ($a, $b) {
            $ca = $a['confidence'] ?? -1; $cb = $b['confidence'] ?? -1;
            if ($ca !== $cb) { return $cb <=> $ca; }
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });
        foreach ($rest as $id => $n) {
            if (count($keep) >= $max) { break; }
            $keep[$id] = $n;
        }
        // If hubs alone already exceed max, trim them too (rare).
        if (count($keep) > $max) { $keep = array_slice($keep, 0, $max, true); }
        return [$keep, self::valid_edges($edges, $keep)];
    }

    private static function attach_degree(array $nodes, array $edges): array {
        $deg = [];
        foreach ($edges as $e) {
            $deg[$e['from']] = ($deg[$e['from']] ?? 0) + 1;
            $deg[$e['to']] = ($deg[$e['to']] ?? 0) + 1;
        }
        foreach ($nodes as $id => &$n) { $n['relationship_count'] = (int) ($deg[$id] ?? 0); }
        unset($n);
        return $nodes;
    }

    private static function stats(array $nodes, array $edges, array $args): array {
        $by_type = array_fill_keys(self::NODE_TYPES, 0);
        foreach ($nodes as $n) { $t = $n['type'] ?? ''; if (isset($by_type[$t])) { $by_type[$t]++; } }
        $by_rel = [];
        foreach ($edges as $e) { $t = $e['type'] ?? ''; $by_rel[$t] = ($by_rel[$t] ?? 0) + 1; }
        return [
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'nodes_by_type' => $by_type,
            'edges_by_type' => $by_rel,
            'capped' => count($nodes) >= (int) $args['max_nodes'],
        ];
    }

    private static function empty_result(array $args, string $message): array {
        return [
            'nodes' => [], 'edges' => [],
            'stats' => ['node_count' => 0, 'edge_count' => 0, 'nodes_by_type' => array_fill_keys(self::NODE_TYPES, 0), 'edges_by_type' => [], 'capped' => false],
            'filters' => [
                'workspace_id' => (int) $args['workspace_id'], 'run_id' => $args['run_id'], 'days' => (int) $args['days'],
                'types' => $args['types'], 'statuses' => $args['statuses'], 'min_confidence' => (int) $args['min_confidence'], 'max_nodes' => (int) $args['max_nodes'],
            ],
            'empty' => true,
            'empty_message' => $message,
            'generated_at' => function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];
    }
}
