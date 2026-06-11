<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_CLI_Trends — Phase 4 (Part H). Read-only trend-engine summary for dev/admin.
 *
 *   wp ves trend-summary
 *   wp ves trend-summary --workspace_id=12
 *
 * Does not mutate data and makes no external API calls.
 */
final class VES_CLI_Trends {

    public static function register(): void {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('ves trend-summary', [__CLASS__, 'summary']);
            // Note: 'ves trend-backfill' is owned by the legacy VES_CLI_Trend_Backfill
            // (old trend system). This Phase 4B observation backfill uses a distinct name.
            \WP_CLI::add_command('ves trend-obs-backfill', [__CLASS__, 'backfill']); // dry-run default; --apply to write
            \WP_CLI::add_command('ves trend-rebuild', [__CLASS__, 'rebuild']);
            \WP_CLI::add_command('ves trend-evaluate', [__CLASS__, 'evaluate']);   // golden set
            \WP_CLI::add_command('ves trend-verify-staging', [__CLASS__, 'verify_staging']);
            // Phase 5 — evidence-first insight engine.
            \WP_CLI::add_command('ves insight-assess', [__CLASS__, 'insight_assess']);   // read-only
            \WP_CLI::add_command('ves trend-insights', [__CLASS__, 'trend_insights']);   // dry-run default; --apply
            \WP_CLI::add_command('ves insight-briefs', [__CLASS__, 'insight_briefs']);   // dry-run default; --apply
            // Phase 5B — staging validation + batch insight reassessment.
            \WP_CLI::add_command('ves validate-staging', [__CLASS__, 'validate_staging']);   // read-only readiness check
            \WP_CLI::add_command('ves readiness-check', [__CLASS__, 'validate_staging']);    // alias of validate-staging
            \WP_CLI::add_command('ves insight-reassess', [__CLASS__, 'insight_reassess']);   // dry-run default; metadata-only apply
        }
    }

    /**
     * wp ves validate-staging [--workspace_id=N] [--format=json|table] [--fail-on=warning|error]
     * Read-only production-readiness validation. Exits non-zero when --fail-on is met.
     */
    public static function validate_staging($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Staging_Validation_Service')) { \WP_CLI::error('Staging validation service unavailable.'); return; }
        $ws = isset($assoc['workspace_id']) ? max(0, (int) $assoc['workspace_id']) : 0;
        $format = isset($assoc['format']) ? strtolower((string) $assoc['format']) : 'table';
        $fail_on = isset($assoc['fail-on']) ? strtolower((string) $assoc['fail-on']) : '';

        $r = VES_Staging_Validation_Service::run_validation(['workspace_id' => $ws]);
        $status = (string) $r['production_readiness_status'];

        if ($format === 'json') {
            \WP_CLI::log((string) wp_json_encode(VES_Staging_Validation_Service::export_summary(['workspace_id' => $ws])));
        } else {
            \WP_CLI::log('Production readiness: ' . strtoupper($status));
            \WP_CLI::log(sprintf('Schema: %s', (string) ($r['schema_health']['status'] ?? 'n/a')));
            \WP_CLI::log(sprintf('Trend records: %d · golden_set %d/%d passed · records_missing_evidence=%d',
                (int) ($r['trend_health']['trend_records'] ?? 0), (int) ($r['trend_health']['golden_set']['passed'] ?? 0),
                (int) ($r['trend_health']['golden_set']['total'] ?? 0), (int) ($r['trend_health']['records_missing_evidence'] ?? 0)));
            \WP_CLI::log(sprintf('Insights: total=%d missing_evidence=%d eligible_review=%d eligible_approval=%d',
                (int) ($r['insight_health']['total'] ?? 0), (int) ($r['insight_health']['missing_evidence'] ?? 0),
                (int) ($r['insight_health']['eligible_for_review'] ?? 0), (int) ($r['insight_health']['eligible_for_approval'] ?? 0)));
            \WP_CLI::log(sprintf('Telemetry: ai_usage_24h=%d failed_24h=%d sources_missing_usage_event=%d',
                (int) ($r['telemetry_health']['ai_usage_24h'] ?? 0), (int) ($r['telemetry_health']['failed_24h'] ?? 0),
                (int) ($r['telemetry_health']['sources_missing_usage_event'] ?? 0)));
            foreach ((array) ($r['blocking_issues'] ?? []) as $b) { \WP_CLI::warning('BLOCKING: ' . $b); }
            foreach ((array) ($r['warnings'] ?? []) as $w) { \WP_CLI::log('  warning: ' . $w); }
            \WP_CLI::log('Next commands:');
            foreach ((array) ($r['next_commands'] ?? []) as $n) { \WP_CLI::log('  ' . $n); }
        }

        $has_block = !empty($r['blocking_issues']);
        $has_warn = !empty($r['warnings']);
        if ($fail_on === 'error' && $has_block) { \WP_CLI::error('Readiness check failed: blocking issues present.'); return; }
        if ($fail_on === 'warning' && ($has_block || $has_warn)) { \WP_CLI::error('Readiness check failed: warnings present.'); return; }
        \WP_CLI::success('Staging validation complete (read-only).');
    }

    /**
     * wp ves insight-reassess [--workspace_id=N] [--limit=N] [--status=draft] [--missing_evidence]
     *   [--created_before=DATE] [--apply-metadata] [--promote-reviewed-safe] [--reject-unsupported]
     * DRY-RUN by default. No approve flag exists. Never auto-approves, never deletes.
     */
    public static function insight_reassess($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Insight_Reassessment_Service')) { \WP_CLI::error('Reassessment service unavailable.'); return; }
        $params = [];
        foreach (['workspace_id', 'limit', 'offset', 'status', 'created_before', 'created_after'] as $k) { if (isset($assoc[$k])) { $params[$k] = $assoc[$k]; } }
        if (!empty($assoc['missing_evidence'])) { $params['missing_evidence'] = true; }
        if (!empty($assoc['apply-metadata'])) { $params['apply_metadata'] = true; }
        if (!empty($assoc['promote-reviewed-safe'])) { $params['promote_reviewed_safe'] = true; }
        if (!empty($assoc['reject-unsupported'])) { $params['reject_unsupported'] = true; }

        $s = VES_Insight_Reassessment_Service::reassess_batch($params);
        \WP_CLI::log(($s['dry_run'] ? 'DRY-RUN' : 'APPLIED') . sprintf(': candidates=%d reassessed=%d errors=%d', $s['candidates'], $s['reassessed'], $s['errors']));
        \WP_CLI::log('By recommendation:');
        foreach ((array) $s['by_recommendation'] as $rec => $c) { \WP_CLI::log(sprintf('  %-34s %d', $rec, $c)); }
        \WP_CLI::log(sprintf('metadata_written=%d promoted_reviewed=%d rejected=%d', $s['metadata_written'], $s['promoted_reviewed'], $s['rejected']));
        if ($s['dry_run']) { \WP_CLI::success('Dry-run complete (no writes). Use --apply-metadata / --promote-reviewed-safe / --reject-unsupported to act.'); }
        else { \WP_CLI::success('Reassessment applied (metadata-only unless safe promotion/rejection flags were set; never auto-approved).'); }
    }

    /** wp ves insight-assess [--workspace_id=N] — read-only insight quality overview. */
    public static function insight_assess($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Insight_Lifecycle_Service')) { \WP_CLI::error('Insight engine unavailable.'); return; }
        $ws = isset($assoc['workspace_id']) ? max(0, (int) $assoc['workspace_id']) : 0;
        $o = VES_Insight_Lifecycle_Service::quality_overview($ws, ['sample' => 25]);
        \WP_CLI::log('Insight status totals:');
        foreach ((array) ($o['totals'] ?? []) as $st => $c) { \WP_CLI::log(sprintf('  %-10s %d', $st, $c)); }
        \WP_CLI::log(sprintf('missing_evidence=%d sample=%d eligible_review=%d eligible_approval=%d below_quality=%d avg_quality=%.1f trends_without_insight=%d',
            (int) $o['missing_evidence'], (int) $o['sample_size'], (int) $o['eligible_review'], (int) $o['eligible_approval'], (int) $o['below_quality'], (float) $o['avg_quality'], (int) $o['trends_without_insight']));
        \WP_CLI::success('Insight assessment complete (read-only).');
    }

    /** wp ves trend-insights [--apply] [--workspace_id=N] [--min_score=N] — build draft insights from trend records. */
    public static function trend_insights($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Trend_Insight_Builder') || !class_exists('VES_Trend_Record_Store')) { \WP_CLI::error('Trend insight builder unavailable.'); return; }
        $apply = !empty($assoc['apply']);
        $ws = isset($assoc['workspace_id']) ? max(0, (int) $assoc['workspace_id']) : 0;
        $min = isset($assoc['min_score']) ? (float) $assoc['min_score'] : 60.0;
        $filters = ['min_score' => $min, 'limit' => 200]; if ($ws > 0) { $filters['workspace_id'] = $ws; }
        $trends = VES_Trend_Record_Store::list_trend_records($filters);
        $created = 0; $skipped = 0;
        foreach ($trends as $tr) {
            $id = (int) ($tr['id'] ?? 0); if ($id <= 0) { continue; }
            if (!$apply) {
                $built = VES_Trend_Insight_Builder::build_from_trend_record($tr);
                if (is_wp_error($built) || empty($tr['evidence_ids'])) { $skipped++; } else { $created++; }
                continue;
            }
            $r = VES_Trend_Insight_Builder::create_draft_insight_from_trend($id, ['min_score' => $min, 'min_confidence' => $min]);
            if (is_wp_error($r)) { $skipped++; } else { $created++; }
        }
        \WP_CLI::log(sprintf('%s: would_create/created=%d skipped=%d (of %d trend records)', $apply ? 'APPLIED' : 'DRY-RUN', $created, $skipped, count($trends)));
        if (!$apply) { \WP_CLI::success('Dry-run complete (no writes). Re-run with --apply.'); }
        else { \WP_CLI::success('Trend insights built (drafts only).'); }
    }

    /** wp ves insight-briefs [--apply] [--workspace_id=N] — build draft briefs from evidence-backed insights. */
    public static function insight_briefs($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Insight_Brief_Builder') || !class_exists('VES_Intelligence_Store')) { \WP_CLI::error('Brief builder unavailable.'); return; }
        $apply = !empty($assoc['apply']);
        $ws = isset($assoc['workspace_id']) ? max(0, (int) $assoc['workspace_id']) : 0;
        $filters = ['limit' => 200]; if ($ws > 0) { $filters['workspace_id'] = $ws; }
        $insights = VES_Intelligence_Store::list_insights($filters);
        $created = 0; $skipped = 0;
        foreach ($insights as $ins) {
            $id = (int) ($ins['id'] ?? 0);
            if ($id <= 0 || empty($ins['evidence_ids'])) { $skipped++; continue; }
            if (!$apply) { $created++; continue; }
            $r = VES_Insight_Brief_Builder::create_brief_from_insight($id);
            if (is_wp_error($r)) { $skipped++; } else { $created++; }
        }
        \WP_CLI::log(sprintf('%s: would_create/created=%d skipped=%d (of %d insights)', $apply ? 'APPLIED' : 'DRY-RUN', $created, $skipped, count($insights)));
        if (!$apply) { \WP_CLI::success('Dry-run complete (no writes). Re-run with --apply.'); }
        else { \WP_CLI::success('Briefs built (drafts only).'); }
    }

    private static function require_cap(): bool {
        if (function_exists('is_user_logged_in') && function_exists('current_user_can') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Insufficient capability.');
            return false;
        }
        return true;
    }

    /** wp ves trend-obs-backfill [--apply] [--workspace_id=N] [--limit=N] — DRY-RUN by default. */
    public static function backfill($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Trend_Backfill_Service')) { \WP_CLI::error('Backfill service unavailable.'); return; }
        $apply = !empty($assoc['apply']);
        $params = ['apply' => $apply];
        foreach (['workspace_id', 'run_id', 'limit', 'offset', 'from', 'to'] as $k) { if (isset($assoc[$k])) { $params[$k] = $assoc[$k]; } }
        if (!$apply) {
            $p = VES_Trend_Backfill_Service::preview_backfill($params);
            \WP_CLI::log(sprintf('DRY-RUN: %d candidates · eligible=%d already=%d missing_payload=%d unsupported=%d missing_ws=%d · est_observations=%d',
                $p['candidates'], $p['eligible'], $p['already_backfilled'], $p['missing_payload'], $p['unsupported_format'], $p['missing_workspace'], $p['estimated_observations']));
            \WP_CLI::success('Dry-run complete (no rows written). Re-run with --apply to backfill.');
            return;
        }
        $r = VES_Trend_Backfill_Service::run_backfill($params);
        \WP_CLI::log(sprintf('APPLIED: reports_processed=%d observations_created=%d skipped=%d errors=%d (run %s)',
            $r['reports_processed'], $r['observations_created'], $r['skipped'], $r['errors'], $r['backfill_run_id']));
        \WP_CLI::success('Backfill applied (idempotent).');
    }

    /** wp ves trend-rebuild [--workspace_id=N] [--min_points=N]. */
    public static function rebuild($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Trend_Rebuild_Service')) { \WP_CLI::error('Rebuild service unavailable.'); return; }
        $filters = [];
        foreach (['workspace_id', 'observation_type', 'platform', 'provider', 'normalized_term', 'min_points', 'limit'] as $k) { if (isset($assoc[$k])) { $filters[$k] = $assoc[$k]; } }
        $r = VES_Trend_Rebuild_Service::rebuild_trends($filters);
        \WP_CLI::log(sprintf('terms_seen=%d created=%d updated=%d insufficient=%d errors=%d',
            $r['terms_seen'], $r['records_created'], $r['records_updated'], $r['insufficient_data'], $r['errors']));
        \WP_CLI::success('Trend rebuild complete.');
    }

    /** wp ves trend-evaluate — deterministic golden-set check (no LLM). */
    public static function evaluate($args, $assoc): void {
        if (!self::require_cap() || !class_exists('VES_Trend_Golden_Set')) { \WP_CLI::error('Golden set unavailable.'); return; }
        $r = VES_Trend_Golden_Set::evaluate();
        foreach ($r['failures'] as $f) { \WP_CLI::warning(sprintf('FAIL %s: expected=%s actual=%s', $f['name'], $f['expected'], $f['actual'])); }
        \WP_CLI::log(sprintf('Golden set: %d/%d passed (%.1f%%)', $r['passed'], $r['total'], $r['pass_rate']));
        if ($r['failed'] > 0) { \WP_CLI::error('Golden set has failures — review classifier thresholds.'); }
        else { \WP_CLI::success('Golden set passed.'); }
    }

    /** wp ves trend-verify-staging — prints the staging verification checklist (read-only). */
    public static function verify_staging($args, $assoc): void {
        if (!self::require_cap()) { return; }
        \WP_CLI::log('Trend engine staging verification checklist (run in order):');
        foreach ([
            '1. wp ves verify-schema            # confirm trend tables + columns exist',
            '2. wp ves trend-obs-backfill --dry-run # preview historical reports (no writes)',
            '3. wp ves trend-obs-backfill --apply   # idempotent backfill of observations',
            '4. wp ves trend-rebuild            # recompute trend records from observations',
            '5. wp ves trend-evaluate           # deterministic golden-set classifier check',
            '6. Run one live DTF scan           # confirms write_trend_intelligence wiring',
            '7. Open admin → Intelligence Suite → Advanced → Trend Engine panel',
        ] as $line) { \WP_CLI::log('  ' . $line); }
        \WP_CLI::success('Checklist printed (no changes made).');
    }

    public static function summary($args, $assoc): void {
        if (function_exists('is_user_logged_in') && function_exists('current_user_can') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Insufficient capability.');
            return;
        }
        $ws = isset($assoc['workspace_id']) ? max(0, (int) $assoc['workspace_id']) : 0;

        if (class_exists('VES_Trend_Observation_Store')) {
            \WP_CLI::log('Observations: ' . VES_Trend_Observation_Store::total($ws));
            foreach (VES_Trend_Observation_Store::count_by('observation_type', $ws) as $t => $c) {
                \WP_CLI::log(sprintf('  %-14s %d', $t, $c));
            }
        }
        if (class_exists('VES_Trend_Record_Store')) {
            \WP_CLI::log('Trend records: ' . VES_Trend_Record_Store::total($ws));
            foreach (VES_Trend_Record_Store::count_by_classification($ws) as $cl => $c) {
                \WP_CLI::log(sprintf('  %-18s %d', $cl, $c));
            }
            \WP_CLI::log('Records missing evidence: ' . VES_Trend_Record_Store::count_missing_evidence($ws));
        }
        \WP_CLI::success('Trend summary complete (read-only).');
    }
}
