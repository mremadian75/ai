<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Staging_Validation_Service — Phase 5B aggregate readiness/validation.
 *
 * READ-ONLY: runs schema, trend, insight, and telemetry health checks plus a
 * threshold audit, and derives a production_readiness_status with blocking issues,
 * warnings, and suggested next commands. No external calls, no writes, no LLM, and
 * the export summary contains only counts/statuses — never raw payloads or secrets.
 */
final class VES_Staging_Validation_Service {

    const READY = 'ready';
    const READY_WARN = 'ready_with_warnings';
    const NOT_READY = 'not_ready';

    /** Schema existence/columns (read-only). */
    public static function schema_health(): array {
        if (!class_exists('VES_Health_Check') || !method_exists('VES_Health_Check', 'verify_schema')) {
            return ['status' => 'warning', 'missing' => [], 'available' => false];
        }
        $s = VES_Health_Check::verify_schema();
        $missing = [];
        foreach ((array) ($s['tables'] ?? []) as $t) {
            if (($t['status'] ?? 'ok') !== 'ok') {
                $missing[] = ['table' => (string) ($t['table'] ?? ''), 'exists' => (bool) ($t['exists'] ?? false), 'missing_columns' => (array) ($t['missing_columns'] ?? [])];
            }
        }
        return ['status' => (string) ($s['status'] ?? 'ok'), 'missing' => $missing, 'available' => true];
    }

    /** Trend engine health (read-only). */
    public static function trend_health(array $args = []): array {
        $ws = (int) ($args['workspace_id'] ?? 0);
        $out = [
            'observations' => class_exists('VES_Trend_Observation_Store') ? VES_Trend_Observation_Store::total($ws) : 0,
            'trend_records' => class_exists('VES_Trend_Record_Store') ? VES_Trend_Record_Store::total($ws) : 0,
            'classification_distribution' => class_exists('VES_Trend_Record_Store') ? VES_Trend_Record_Store::count_by_classification($ws) : [],
            'records_missing_evidence' => class_exists('VES_Trend_Record_Store') ? VES_Trend_Record_Store::count_missing_evidence($ws) : 0,
            'golden_set' => ['passed' => 0, 'total' => 0, 'failed' => 0],
            'backfill' => ['eligible' => 0, 'already_backfilled' => 0],
        ];
        if (class_exists('VES_Trend_Golden_Set')) {
            $g = VES_Trend_Golden_Set::evaluate();
            $out['golden_set'] = ['passed' => (int) ($g['passed'] ?? 0), 'total' => (int) ($g['total'] ?? 0), 'failed' => (int) ($g['failed'] ?? 0)];
        }
        $out['insufficient_data'] = (int) ($out['classification_distribution']['insufficient_data'] ?? 0);
        if (class_exists('VES_Trend_Backfill_Service')) {
            $p = VES_Trend_Backfill_Service::preview_backfill($ws > 0 ? ['workspace_id' => $ws, 'limit' => 500] : ['limit' => 500]);
            $out['backfill'] = ['eligible' => (int) ($p['eligible'] ?? 0), 'already_backfilled' => (int) ($p['already_backfilled'] ?? 0), 'unsupported_format' => (int) ($p['unsupported_format'] ?? 0)];
        }
        return $out;
    }

    /** Insight evidence/quality health (read-only). */
    public static function insight_health(array $args = []): array {
        $ws = (int) ($args['workspace_id'] ?? 0);
        $ov = class_exists('VES_Insight_Lifecycle_Service') ? VES_Insight_Lifecycle_Service::quality_overview($ws, ['sample' => (int) ($args['sample'] ?? 25)]) : [];
        $by_status = class_exists('VES_Intelligence_Store') ? VES_Intelligence_Store::count_insights_by_status($ws) : [];
        $total = 0; foreach ($by_status as $c) { $total += (int) $c; }
        return [
            'total' => $total,
            'by_status' => $by_status,
            'missing_evidence' => (int) ($ov['missing_evidence'] ?? 0),
            'sample_assessed' => (int) ($ov['sample_size'] ?? 0),
            'eligible_for_review' => (int) ($ov['eligible_review'] ?? 0),
            'eligible_for_approval' => (int) ($ov['eligible_approval'] ?? 0),
            'below_quality' => (int) ($ov['below_quality'] ?? 0),
            'avg_quality' => (float) ($ov['avg_quality'] ?? 0),
            'trends_without_insight' => (int) ($ov['trends_without_insight'] ?? 0),
            'rejected' => (int) ($by_status['rejected'] ?? 0),
            'archived' => (int) ($by_status['archived'] ?? 0),
        ];
    }

    /** Cost/scrape telemetry health (read-only). */
    public static function telemetry_health(array $args = []): array {
        $out = ['ai_usage_24h' => 0, 'failed_24h' => 0, 'apify_total' => 0, 'pricing_unavailable' => 0,
                'sources_with_usage_event' => 0, 'sources_missing_usage_event' => 0];
        if (class_exists('VES_AI_Usage_Tracker')) {
            if (method_exists('VES_AI_Usage_Tracker', 'summary')) { $s = VES_AI_Usage_Tracker::summary(24); $out['ai_usage_24h'] = (int) ($s['total'] ?? 0); $out['failed_24h'] = (int) ($s['failed'] ?? 0); }
            if (method_exists('VES_AI_Usage_Tracker', 'scrape_coverage')) { $c = VES_AI_Usage_Tracker::scrape_coverage(168); $out['apify_total'] = (int) ($c['apify_total'] ?? 0); $out['pricing_unavailable'] = (int) ($c['pricing_unavailable'] ?? 0); }
        }
        if (class_exists('VES_Intelligence_Store') && method_exists('VES_Intelligence_Store', 'backlink_health')) {
            $b = VES_Intelligence_Store::backlink_health((int) ($args['workspace_id'] ?? 0));
            $out['sources_with_usage_event'] = (int) ($b['sources_with_usage_event'] ?? 0);
            $out['sources_missing_usage_event'] = (int) ($b['sources_missing_usage_event'] ?? 0);
        }
        return $out;
    }

    /** Derive readiness status + blocking/warnings + next commands from section results. */
    public static function recommendation_summary(array $results): array {
        $schema = is_array($results['schema_health'] ?? null) ? $results['schema_health'] : [];
        $trend  = is_array($results['trend_health'] ?? null) ? $results['trend_health'] : [];
        $insight= is_array($results['insight_health'] ?? null) ? $results['insight_health'] : [];
        $tel    = is_array($results['telemetry_health'] ?? null) ? $results['telemetry_health'] : [];
        $audit_warnings = is_array($results['threshold_audit']['warnings'] ?? null) ? $results['threshold_audit']['warnings'] : [];

        $blocking = []; $warnings = []; $next = [];

        // Blocking.
        if (($schema['status'] ?? 'ok') === 'error') {
            $blocking[] = 'Schema is missing required tables. Run migrations before validating.';
            $next[] = 'wp ves verify-schema --repair';
        }
        if ((int) ($trend['golden_set']['failed'] ?? 0) > 0) {
            $blocking[] = 'Trend classifier golden set FAILED — do not promote trend insights.';
            $next[] = 'wp ves trend-evaluate';
        }

        // Warnings.
        if (($schema['status'] ?? 'ok') === 'warning') { $warnings[] = 'Schema has missing columns; run wp ves verify-schema --repair.'; $next[] = 'wp ves verify-schema --repair'; }
        if ((int) ($insight['missing_evidence'] ?? 0) > 0) { $warnings[] = ((int) $insight['missing_evidence']) . ' insight(s) missing evidence.'; $next[] = 'wp ves insight-reassess --dry-run'; }
        if ((int) ($insight['trends_without_insight'] ?? 0) > 0) { $warnings[] = ((int) $insight['trends_without_insight']) . ' high-score trend(s) without an insight.'; $next[] = 'wp ves trend-insights --dry-run'; }
        if ((int) ($trend['backfill']['eligible'] ?? 0) > 0) { $warnings[] = ((int) $trend['backfill']['eligible']) . ' historical report(s) eligible for backfill.'; $next[] = 'wp ves trend-obs-backfill --dry-run'; }
        if ((int) ($tel['failed_24h'] ?? 0) > 0) { $warnings[] = ((int) $tel['failed_24h']) . ' failed AI usage event(s) in 24h.'; }
        foreach ($audit_warnings as $w) { $warnings[] = $w; }

        $next[] = 'wp ves insight-assess';
        $next = array_values(array_unique($next));

        $status = !empty($blocking) ? self::NOT_READY : (!empty($warnings) ? self::READY_WARN : self::READY);
        return [
            'production_readiness_status' => $status,
            'blocking_issues' => array_values($blocking),
            'warnings' => array_values($warnings),
            'next_commands' => $next,
        ];
    }

    /**
     * Explicit runtime availability (Part: distinguish not_run / unavailable from
     * pass). Reports whether the live database / WP-CLI context are present so the
     * operator never sees a blank panel that looks like a silent pass.
     * @return array{database:string,wp_cli:string,live_validation:string}
     */
    public static function environment_health(): array {
        global $wpdb;
        $db_ok = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var');
        $cli   = defined('WP_CLI') && WP_CLI;
        return [
            'database' => $db_ok ? 'available' : 'unavailable',
            'wp_cli' => $cli ? 'available' : 'not_run',
            'live_validation' => $db_ok ? 'available' : 'unavailable',
        ];
    }

    /**
     * Legacy evidence-gate counts for the readiness panel (Part: legacy gate
     * status). Counts only — never raw evidence text/payloads. Defensive: returns
     * available=false rather than throwing when the ledger is unreachable.
     * @return array{available:bool,gate:string,legacy_total:int,legacy_approved:int,legacy_approved_without_evidence:int}
     */
    public static function legacy_gate_health(array $args = []): array {
        $out = ['available' => false, 'gate' => 'unknown', 'legacy_total' => 0, 'legacy_approved' => 0, 'legacy_approved_without_evidence' => 0];
        if (!class_exists('VES_Insight_Records') || !method_exists('VES_Insight_Records', 'legacy_approval_health')) {
            return $out;
        }
        try {
            $h = VES_Insight_Records::legacy_approval_health((int) ($args['workspace_id'] ?? 0));
            return [
                'available' => true,
                'gate' => (string) ($h['gate'] ?? 'active'),
                'legacy_total' => (int) ($h['legacy_total'] ?? 0),
                'legacy_approved' => (int) ($h['legacy_approved'] ?? 0),
                'legacy_approved_without_evidence' => (int) ($h['legacy_approved_without_evidence'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return $out;
        }
    }

    /** Telemetry/security posture for the readiness panel — counts/flags only. */
    public static function security_health(): array {
        return [
            'scrubber' => 'active',
            'export_redaction' => 'active',
            'forbidden_keys' => count(self::FORBIDDEN_EXPORT_KEYS),
            // Honest: the service proves export-redaction CONFIGURATION, it does not
            // run a live secret scan. Do not claim "clean".
            'secret_leak_scan' => 'not_run',
            'auto_approval' => 'disabled',
        ];
    }

    /** Run all checks. Read-only. @return array structured, JSON-safe. */
    public static function run_validation(array $args = []): array {
        $results = [
            'generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'plugin_version' => defined('FIS_VERSION') ? FIS_VERSION : (defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : ''),
            'workspace_id' => (int) ($args['workspace_id'] ?? 0),
            'environment_health' => self::environment_health(),
            'schema_health' => self::schema_health(),
            'trend_health' => self::trend_health($args),
            'insight_health' => self::insight_health($args),
            'telemetry_health' => self::telemetry_health($args),
            'legacy_gate' => self::legacy_gate_health($args),
            'security_health' => self::security_health(),
        ];
        $results['threshold_audit'] = self::threshold_audit($args);
        $rec = self::recommendation_summary($results);
        $results['recommendations'] = $rec;
        $results['production_readiness_status'] = $rec['production_readiness_status'];
        $results['blocking_issues'] = $rec['blocking_issues'];
        $results['warnings'] = $rec['warnings'];
        $results['next_commands'] = $rec['next_commands'];
        return $results;
    }

    /** Threshold audit section (insight + trend + warnings). */
    public static function threshold_audit(array $args = []): array {
        if (!class_exists('VES_Threshold_Audit_Service')) { return ['insight' => [], 'trend' => [], 'warnings' => []]; }
        $audit = [
            'insight' => VES_Threshold_Audit_Service::audit_insight_thresholds($args),
            'trend' => VES_Threshold_Audit_Service::audit_trend_thresholds($args),
        ];
        $audit['warnings'] = VES_Threshold_Audit_Service::suggest_threshold_warnings($audit);
        return $audit;
    }

    /** Map a status/availability token to a FIIS badge class. */
    private static function badge_class(string $state): string {
        $s = strtolower($state);
        if (in_array($s, ['ready', 'ok', 'available', 'passed', 'pass', 'active', 'clean', 'disabled'], true)) { return 'fiis-badge-ok'; }
        if (in_array($s, ['ready_with_warnings', 'warning', 'warn'], true)) { return 'fiis-badge-warn'; }
        return 'fiis-badge-muted'; // not_run / unavailable / unknown / not_ready / error / failed
    }

    /**
     * Render the Production Readiness panel as escaped HTML from a run_validation()
     * result. Pure + testable (no WP required: falls back to htmlspecialchars). ALWAYS
     * returns non-empty output and shows explicit not_run/unavailable states — never a
     * silent blank. Counts/statuses only; no raw evidence/payloads/secrets.
     */
    public static function render_admin_html(array $r): string {
        $h = static function ($s): string {
            return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES);
        };
        $chip = static function ($state) use ($h): string {
            return '<span class="fiis-badge ' . self::badge_class((string) $state) . '">' . $h(strtoupper((string) $state)) . '</span>';
        };
        $env = is_array($r['environment_health'] ?? null) ? $r['environment_health'] : [];
        $sh  = is_array($r['schema_health'] ?? null) ? $r['schema_health'] : [];
        $th  = is_array($r['trend_health'] ?? null) ? $r['trend_health'] : [];
        $ih  = is_array($r['insight_health'] ?? null) ? $r['insight_health'] : [];
        $tel = is_array($r['telemetry_health'] ?? null) ? $r['telemetry_health'] : [];
        $lg  = is_array($r['legacy_gate'] ?? null) ? $r['legacy_gate'] : [];
        $status = (string) ($r['production_readiness_status'] ?? 'not_ready');

        $schema_cell = !empty($sh['available']) ? $chip((string) ($sh['status'] ?? 'ok')) : $chip('unavailable');
        $legacy_cell = !empty($lg['available'])
            ? sprintf('%s · total=%d · approved=%d · approved_without_evidence=%d',
                $chip((string) ($lg['gate'] ?? 'active')), (int) ($lg['legacy_total'] ?? 0),
                (int) ($lg['legacy_approved'] ?? 0), (int) ($lg['legacy_approved_without_evidence'] ?? 0))
            : $chip('unavailable');

        $out  = '<div class="fiis-card fiis-promo"><h3>' . $h('Production Readiness (Phase 5B — staging validation)') . '</h3>';
        $out .= '<p>' . $h('Overall status') . ': ' . $chip($status) . '</p>';
        $out .= '<p class="fiis-muted">' . $h('Legend:') . ' ' . $chip('passed') . ' ' . $chip('warning') . ' ' . $chip('failed') . ' ' . $chip('not_run') . ' ' . $chip('unavailable') . '</p>';
        $out .= '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>' . $h('Section') . '</th><th>' . $h('Signal') . '</th></tr></thead><tbody>';
        $rows = [
            'Environment' => sprintf('%s db · %s live-validation · %s wp-cli',
                $chip((string) ($env['database'] ?? 'unavailable')),
                $chip((string) ($env['live_validation'] ?? 'unavailable')),
                $chip((string) ($env['wp_cli'] ?? 'not_run'))),
            'Schema' => $schema_cell,
            'Trend records' => $h(sprintf('%d · golden %d/%d · missing_evidence=%d',
                (int) ($th['trend_records'] ?? 0), (int) ($th['golden_set']['passed'] ?? 0),
                (int) ($th['golden_set']['total'] ?? 0), (int) ($th['records_missing_evidence'] ?? 0))),
            'Insights' => $h(sprintf('total=%d · missing_evidence=%d · eligible_review=%d · eligible_approval=%d',
                (int) ($ih['total'] ?? 0), (int) ($ih['missing_evidence'] ?? 0),
                (int) ($ih['eligible_for_review'] ?? 0), (int) ($ih['eligible_for_approval'] ?? 0))),
            'Telemetry' => $h(sprintf('ai_usage_24h=%d · failed_24h=%d · sources_missing_usage_event=%d',
                (int) ($tel['ai_usage_24h'] ?? 0), (int) ($tel['failed_24h'] ?? 0),
                (int) ($tel['sources_missing_usage_event'] ?? 0))),
            'Legacy gate' => $legacy_cell,
            'Security' => $chip('active') . ' ' . $h('scrubber') . ' · ' . $h('export redaction active') . ' · ' . $h('secret-scan') . ' ' . $chip('not_run') . ' · ' . $h('no-auto-approval'),
        ];
        foreach ($rows as $label => $val) {
            $out .= '<tr><th scope="row" style="text-align:left">' . $h($label) . '</th><td>' . $val . '</td></tr>';
        }
        $out .= '</tbody></table></div>';

        foreach (['blocking_issues' => 'Blocking issues', 'warnings' => 'Warnings', 'next_commands' => 'Next commands'] as $key => $title) {
            $items = (array) ($r[$key] ?? []);
            if (empty($items)) { continue; }
            $out .= '<h4>' . $h($title) . '</h4><ul class="fiis-list">';
            foreach ($items as $it) {
                $out .= $key === 'blocking_issues'
                    ? '<li><span class="fiis-badge fiis-badge-warn">' . $h('blocking') . '</span> ' . $h((string) $it) . '</li>'
                    : ($key === 'next_commands' ? '<li><code>' . $h((string) $it) . '</code></li>' : '<li class="fiis-muted">' . $h((string) $it) . '</li>');
            }
            $out .= '</ul>';
        }
        $out .= '<p class="fiis-muted">' . $h('Read-only. To act, use WP-CLI: wp ves validate-staging, wp ves insight-reassess (dry-run default), wp ves trend-insights. No batch auto-approval.') . '</p>';
        $out .= '</div>';
        return $out;
    }

    /** Explicit, non-blank fallback card when readiness cannot be computed. */
    public static function unavailable_html(string $state, string $reason): string {
        $h = function_exists('esc_html') ? 'esc_html' : null;
        $e = static function ($s) use ($h): string { return $h ? $h((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); };
        $badge = self::badge_class($state);
        return '<div class="fiis-card fiis-promo"><h3>' . $e('Production Readiness (Phase 5B — staging validation)') . '</h3>'
            . '<p><span class="fiis-badge ' . $badge . '">' . $e(strtoupper($state)) . '</span> ' . $e($reason) . '</p>'
            . '<p class="fiis-muted">' . $e('This explicit status is shown instead of a blank panel.') . '</p></div>';
    }

    /**
     * JSON-safe export summary (Part F). Strips any forbidden keys defensively and
     * bounds size. Contains only counts/statuses — no raw payloads or secrets.
     */
    public static function export_summary(array $args = []): array {
        $v = self::run_validation($args);
        return self::scrub_export($v);
    }

    const FORBIDDEN_EXPORT_KEYS = ['api_key', 'apikey', 'token', 'bearer', 'authorization', 'auth', 'password', 'secret', 'webhook_secret', 'prompt', 'messages', 'response', 'raw_response', 'source_url', 'url'];

    private static function scrub_export($data) {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (in_array(strtolower((string) $k), self::FORBIDDEN_EXPORT_KEYS, true)) { continue; }
                $out[$k] = self::scrub_export($v);
            }
            return $out;
        }
        return $data;
    }
}
