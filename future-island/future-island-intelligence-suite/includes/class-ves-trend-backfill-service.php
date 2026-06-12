<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Backfill_Service — Phase 4B. Converts HISTORICAL Deep Trend Finder
 * reports (fi_dtf_reports ⋈ fi_dtf_runs) into trend observations so the Phase 4
 * deterministic engine has real history to work with.
 *
 * Distinct from the legacy VES_Trend_Backfill_Runner / VES_CLI_Trend_Backfill
 * (those backfill the OLD trend system). This writes ONLY to ves_trend_observations
 * via VES_Trend_Observation_Store, is dry-run-by-default, and is IDEMPOTENT: each
 * report is processed at most once (tracked in an option set), so re-applying never
 * double-counts. No external/API calls, no LLM, no raw payload storage.
 */
final class VES_Trend_Backfill_Service {

    const OPTION_DONE = 'ves_trend_backfill_done';   // array of processed report ids
    const OPTION_LAST = 'ves_trend_backfill_last';   // last apply summary
    const MAX_DONE    = 50000;                       // bound the marker set

    private static function reports_table(): string {
        return (class_exists('FIDTF_DB') && method_exists('FIDTF_DB', 'table')) ? FIDTF_DB::table('reports') : '';
    }
    private static function runs_table(): string {
        return (class_exists('FIDTF_DB') && method_exists('FIDTF_DB', 'table')) ? FIDTF_DB::table('runs') : '';
    }

    private static function done_set(): array {
        $v = function_exists('get_option') ? get_option(self::OPTION_DONE, []) : [];
        return is_array($v) ? array_map('intval', $v) : [];
    }
    private static function mark_done(int $report_id): void {
        if ($report_id <= 0 || !function_exists('update_option')) { return; }
        $set = self::done_set();
        if (!in_array($report_id, $set, true)) { $set[] = $report_id; }
        if (count($set) > self::MAX_DONE) { $set = array_slice($set, -self::MAX_DONE); }
        update_option(self::OPTION_DONE, array_values($set), false);
    }

    /**
     * Enumerate historical reports + their eligibility/reason. Read-only.
     * @param array $filters workspace_id, run_id, from, to, limit, offset, only_missing
     */
    public static function discover_backfill_candidates(array $filters = []): array {
        global $wpdb;
        $reports = self::reports_table(); $runs = self::runs_table();
        if ($reports === '' || $runs === '' || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) { return []; }

        $where = "rp.report_type = 'final_synthesis'"; $params = [];
        if (!empty($filters['workspace_id'])) { $where .= ' AND r.workspace_id = %d'; $params[] = (int) $filters['workspace_id']; }
        if (!empty($filters['run_id'])) { $where .= ' AND rp.run_id = %d'; $params[] = (int) $filters['run_id']; }
        if (!empty($filters['from'])) { $where .= ' AND rp.created_at >= %s'; $params[] = (string) $filters['from']; }
        if (!empty($filters['to'])) { $where .= ' AND rp.created_at <= %s'; $params[] = (string) $filters['to']; }
        $limit = max(1, min(2000, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = "SELECT rp.id AS report_id, rp.run_id, rp.final_synthesis_json AS payload, rp.created_at AS report_created_at,
                       r.workspace_id, r.user_id
                FROM {$reports} rp INNER JOIN {$runs} r ON r.id = rp.run_id
                WHERE {$where} ORDER BY rp.id ASC LIMIT %d OFFSET %d";
        $params[] = $limit; $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $done = self::done_set();
        $only_missing = !empty($filters['only_missing']);
        $out = [];
        foreach ($rows as $row) {
            $report_id = (int) ($row['report_id'] ?? 0);
            $workspace_id = self::resolve_workspace((int) ($row['workspace_id'] ?? 0), (int) ($row['user_id'] ?? 0));
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $has_terms = is_array($payload) && !empty($payload['top_terms']);
            $has_tags  = is_array($payload) && !empty($payload['top_hashtags']);
            $already = in_array($report_id, $done, true);

            if (!is_array($payload)) { $reason = 'missing_payload'; }
            elseif (!$has_terms && !$has_tags) { $reason = 'unsupported_format'; }
            elseif ($workspace_id <= 0) { $reason = 'missing_workspace'; }
            elseif ($already) { $reason = 'already_backfilled'; }
            else { $reason = 'eligible'; }

            if ($only_missing && $reason !== 'eligible') { continue; }
            $est = is_array($payload) ? (count((array) ($payload['top_terms'] ?? [])) + count((array) ($payload['top_hashtags'] ?? []))) : 0;
            $out[] = [
                'run_id'                 => (int) ($row['run_id'] ?? 0),
                'workspace_id'           => $workspace_id,
                'report_id'              => $report_id,
                'created_at'             => (string) ($row['report_created_at'] ?? ''),
                'has_top_terms'          => $has_terms,
                'has_hashtags'           => $has_tags,
                'estimated_observations' => $est,
                'already_backfilled'     => $already,
                'reason'                 => $reason,
            ];
        }
        return $out;
    }

    /** Dry-run aggregate. Writes nothing. */
    public static function preview_backfill(array $filters = []): array {
        $candidates = self::discover_backfill_candidates($filters);
        $agg = ['dry_run' => true, 'candidates' => count($candidates), 'eligible' => 0, 'already_backfilled' => 0,
                'missing_payload' => 0, 'unsupported_format' => 0, 'missing_workspace' => 0, 'estimated_observations' => 0, 'details' => []];
        foreach ($candidates as $c) {
            $r = $c['reason'];
            if (isset($agg[$r])) { $agg[$r]++; }
            if ($r === 'eligible') { $agg['estimated_observations'] += (int) $c['estimated_observations']; }
        }
        $agg['details'] = array_slice($candidates, 0, 50);
        return $agg;
    }

    /**
     * Pure: extract observation payloads from a decoded report. No DB.
     * @return array list of arrays ready for VES_Trend_Observation_Store::create_or_get_observation
     */
    public static function extract_observations_from_report(array $report, array $context = []): array {
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        $run_id = (string) ($context['run_id'] ?? '');
        $observed_at = (string) ($context['observed_at'] ?? (function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s')));
        $source_id = max(0, (int) ($context['source_id'] ?? 0));
        $meta_base = [
            'backfilled'          => true,
            'source'              => 'historical_dtf_report',
            'backfill_run_id'     => (string) ($context['backfill_run_id'] ?? ''),
            'original_run_id'     => $run_id,
            'original_report_id'  => (int) ($context['report_id'] ?? 0),
            'original_created_at' => $observed_at,
        ];
        $out = [];
        foreach ([['top_terms', 'keyword'], ['top_hashtags', 'hashtag']] as $pair) {
            [$key, $type] = $pair;
            foreach ((array) ($report[$key] ?? []) as $k => $v) {
                $term = ''; $count = 1.0;
                if (is_string($v) && $v !== '') { $term = $v; }
                elseif (is_array($v)) {
                    $term = (string) ($v['label'] ?? $v['term'] ?? $v['hashtag'] ?? $v['name'] ?? (is_string($k) ? $k : ''));
                    $count = (float) ($v['count'] ?? $v['value'] ?? $v['frequency'] ?? 1);
                } elseif (is_string($k) && $k !== '') { $term = $k; $count = (float) $v; }
                $term = trim((string) $term);
                if ($term === '') { continue; }
                $out[] = [
                    'workspace_id' => $workspace_id, 'run_id' => $run_id, 'observation_type' => $type,
                    'term' => $term, 'provider' => 'apify', 'observed_at' => $observed_at,
                    'value_number' => max(1.0, $count), 'raw_count' => (int) max(1, $count),
                    'source_id' => $source_id, 'metadata' => $meta_base,
                ];
            }
        }
        return array_slice($out, 0, 60);
    }

    /**
     * Backfill a single report row (as returned by discover). Idempotent: skips a
     * report already in the done set. Dry-run writes nothing.
     * @return array{report_id:int,run_id:int,reason:string,created:int}
     */
    public static function backfill_report(array $candidate, array $args = []): array {
        $dry_run = !empty($args['dry_run']);
        $report_id = (int) ($candidate['report_id'] ?? 0);
        $run_id = (int) ($candidate['run_id'] ?? 0);
        $res = ['report_id' => $report_id, 'run_id' => $run_id, 'reason' => (string) ($candidate['reason'] ?? ''), 'created' => 0];
        if (($candidate['reason'] ?? '') !== 'eligible') { return $res; }
        if (!class_exists('VES_Trend_Observation_Store')) { $res['reason'] = 'store_unavailable'; return $res; }
        if (in_array($report_id, self::done_set(), true)) { $res['reason'] = 'already_backfilled'; return $res; }

        // Re-read the payload for this report (discover only carried summary fields).
        $report = self::load_report_payload($report_id);
        if (!is_array($report)) { $res['reason'] = 'missing_payload'; return $res; }

        $observations = self::extract_observations_from_report($report, [
            'workspace_id' => (int) $candidate['workspace_id'], 'run_id' => (string) $run_id,
            'report_id' => $report_id, 'observed_at' => (string) ($candidate['created_at'] ?? ''),
            'backfill_run_id' => (string) ($args['backfill_run_id'] ?? ''),
        ]);
        if ($dry_run) { $res['reason'] = 'eligible'; $res['created'] = count($observations); return $res; }

        $created = 0;
        $failed = 0;
        foreach ($observations as $obs) {
            $id = VES_Trend_Observation_Store::create_or_get_observation($obs);
            if (!self::is_wp_error($id)) { $created++; } else { $failed++; }
        }
        // Idempotency safety: only mark the report done when there was nothing to
        // insert, or at least one observation persisted. If every insert failed
        // (e.g. a transient DB error), leave it UNMARKED so a later run can retry
        // instead of skipping the report forever.
        if (!empty($observations) && $created === 0) {
            $res['reason'] = 'insert_failed';
            $res['created'] = 0;
            $res['failed'] = $failed;
            return $res;
        }
        self::mark_done($report_id);
        $res['created'] = $created;
        $res['failed'] = $failed;
        return $res;
    }

    /**
     * Run the backfill across candidates. DRY-RUN BY DEFAULT — pass
     * ['apply' => true] (or ['dry_run' => false]) to write.
     */
    public static function run_backfill(array $args = []): array {
        $apply = !empty($args['apply']) || (isset($args['dry_run']) && $args['dry_run'] === false);
        $dry_run = !$apply;
        $backfill_run_id = 'bk_' . gmdate('Ymd_His') . '_' . substr(md5((string) microtime(true)), 0, 6);

        $filters = array_intersect_key($args, array_flip(['workspace_id', 'run_id', 'from', 'to', 'limit', 'offset']));
        $filters['only_missing'] = true; // apply only eligible
        $candidates = self::discover_backfill_candidates($filters);

        $summary = ['dry_run' => $dry_run, 'backfill_run_id' => $backfill_run_id,
                    'reports_processed' => 0, 'observations_created' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
        foreach ($candidates as $c) {
            try {
                $r = self::backfill_report($c, ['dry_run' => $dry_run, 'backfill_run_id' => $backfill_run_id]);
                if (($r['reason'] ?? '') === 'eligible') { $summary['reports_processed']++; $summary['observations_created'] += (int) $r['created']; }
                else { $summary['skipped']++; }
                if (count($summary['details']) < 50) { $summary['details'][] = $r; }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if (class_exists('VES_Log')) { VES_Log::warn('trend_backfill', 'report_failed', ['error_code' => 'backfill_report_failed']); }
            }
        }
        if ($apply && function_exists('update_option')) {
            update_option(self::OPTION_LAST, [
                'when' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
                'backfill_run_id' => $backfill_run_id, 'reports_processed' => $summary['reports_processed'],
                'observations_created' => $summary['observations_created'],
            ], false);
        }
        return $summary;
    }

    public static function last_backfill(): array {
        $v = function_exists('get_option') ? get_option(self::OPTION_LAST, []) : [];
        return is_array($v) ? $v : [];
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function load_report_payload(int $report_id): ?array {
        global $wpdb;
        $reports = self::reports_table();
        if ($reports === '' || $report_id <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return null; }
        $json = $wpdb->get_var($wpdb->prepare('SELECT final_synthesis_json FROM ' . $reports . ' WHERE id = %d', $report_id));
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : null;
    }
    private static function resolve_workspace(int $workspace_id, int $user_id): int {
        if ($workspace_id > 0) { return $workspace_id; }
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'infer_workspace_id')) {
            return max(0, (int) VES_Memory_Records::infer_workspace_id([], $user_id));
        }
        return 0;
    }
    private static function is_wp_error($thing): bool {
        return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error);
    }
}
