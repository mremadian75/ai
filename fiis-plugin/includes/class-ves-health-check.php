<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Health_Check — Phase 0 internal diagnostics (read-only).
 *
 * Returns a structured snapshot of plugin health for the admin diagnostics
 * surface. It never exposes secret values — API keys are reported only as a
 * boolean "configured" plus a masked length. Safe to call anywhere; never throws.
 *
 *   [
 *     'status' => 'ok'|'warning'|'error',
 *     'generated_at' => '...',
 *     'checks' => [ ['key','status','message','details'], ... ],
 *   ]
 */
final class VES_Health_Check {

    const OK = 'ok';
    const WARN = 'warning';
    const ERROR = 'error';

    public static function run(): array {
        $checks = [];
        foreach ([
            'check_plugin_version', 'check_schema', 'check_tables',
            'check_openai_key', 'check_apify_token', 'check_billing',
            'check_usage_tracker', 'check_scheduler', 'check_stripe',
            'check_last_api_error', 'check_recent_ai_failures',
        ] as $method) {
            try {
                $result = self::$method();
                if (is_array($result) && !empty($result)) { $checks[] = $result; }
            } catch (\Throwable $e) {
                $checks[] = self::row($method, self::WARN, 'Check could not run.', ['exception' => get_class($e)]);
            }
        }

        return [
            'status'       => self::rollup($checks),
            'generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'checks'       => $checks,
        ];
    }

    // ── Phase 3B Part F: live schema verification (read-only) ───────────────────

    /** Required canonical tables → columns that must exist after dbDelta. */
    public static function required_schema(): array {
        global $wpdb;
        $p = (isset($wpdb) && is_object($wpdb)) ? $wpdb->prefix : 'wp_';
        return [
            $p . 'ves_ai_usage_events' => ['actual_provider_cost', 'estimated_provider_cost', 'pricing_source', 'provider', 'module', 'status'],
            $p . 'ves_intel_sources'   => ['canonical_hash', 'raw_hash', 'last_seen_at', 'provider'],
            $p . 'ves_intel_signals'   => ['canonical_signal_hash', 'recurrence_count', 'last_seen_at', 'source_id'],
            $p . 'ves_intel_evidences' => ['source_id', 'signal_id', 'evidence_type'],
            $p . 'ves_intel_insights'  => ['evidence_ids_json', 'status', 'insight_type'],
            $p . 'ves_intel_briefs'    => ['insight_id', 'status', 'brief_type'],
            $p . 'ves_intel_drafts'    => ['brief_id', 'status', 'draft_type'],
        ];
    }

    /**
     * Verify required tables + columns exist in a real WordPress/MySQL install.
     * READ-ONLY (SHOW TABLES / SHOW COLUMNS) — never mutates. Returns structured
     * statuses; safe to call from admin diagnostics or a WP-CLI command.
     *
     * @return array{status:string,tables:array,generated_at:string}
     */
    public static function verify_schema(): array {
        global $wpdb;
        $out = ['status' => self::OK, 'tables' => [], 'generated_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s')];
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            $out['status'] = self::WARN; return $out;
        }
        $overall = self::OK;
        foreach (self::required_schema() as $table => $columns) {
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
            $missing = [];
            if ($exists && method_exists($wpdb, 'get_col')) {
                $present = $wpdb->get_col('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                $present = is_array($present) ? array_map('strval', $present) : [];
                foreach ($columns as $c) { if (!in_array($c, $present, true)) { $missing[] = $c; } }
            } elseif (!$exists) {
                $missing = $columns;
            }
            $status = !$exists ? self::ERROR : (empty($missing) ? self::OK : self::WARN);
            if ($status === self::ERROR) { $overall = self::ERROR; }
            elseif ($status === self::WARN && $overall !== self::ERROR) { $overall = self::WARN; }
            $out['tables'][] = [
                'table' => $table, 'exists' => $exists, 'status' => $status,
                'missing_columns' => $missing,
            ];
        }
        $out['status'] = $overall;
        return $out;
    }

    // ── individual checks ───────────────────────────────────────────────────────

    private static function check_plugin_version(): array {
        $fis = defined('FIS_VERSION') ? FIS_VERSION : (defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '');
        $ves = defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '';
        $fidtf = defined('FIDTF_VERSION') ? FIDTF_VERSION : '';
        $status = ($fis !== '') ? self::OK : self::WARN;
        $msg = ($fis !== '') ? ('Suite ' . $fis . (($fidtf !== '') ? ', DTF ' . $fidtf : '')) : 'Version constants not defined.';
        // Suite + VES constants are intentionally the same value; flag drift only.
        if ($fis !== '' && $ves !== '' && $fis !== $ves) {
            $status = self::WARN;
            $msg .= ' (FIS/VES version mismatch: ' . $fis . ' vs ' . $ves . ')';
        }
        return self::row('plugin_version', $status, $msg, [
            'fis_version' => $fis, 'ves_version' => $ves, 'fidtf_version' => $fidtf,
        ]);
    }

    private static function check_schema(): array {
        if (!class_exists('VES_Migrations') || !method_exists('VES_Migrations', 'health')) {
            return self::row('schema_version', self::WARN, 'Migration runner unavailable.', []);
        }
        $h = VES_Migrations::health();
        $in_sync = !empty($h['schema_in_sync']);
        return self::row('schema_version', $in_sync ? self::OK : self::WARN,
            $in_sync ? 'Schema in sync.' : 'Schema out of sync — run Repair tables.',
            [
                'schema_version_db'   => (string) ($h['schema_version_db'] ?? ''),
                'schema_version_code' => (string) ($h['schema_version_code'] ?? ''),
            ]);
    }

    private static function check_tables(): array {
        if (!class_exists('VES_Migrations') || !method_exists('VES_Migrations', 'health')) {
            return self::row('database_tables', self::WARN, 'Cannot read table health.', []);
        }
        $h = VES_Migrations::health();
        $tables = is_array($h['tables'] ?? null) ? $h['tables'] : [];
        $count = count($tables);
        $status = $count > 0 ? self::OK : self::WARN;
        return self::row('database_tables', $status,
            $count > 0 ? ($count . ' plugin tables present.') : 'No plugin tables detected.',
            ['table_count' => $count]);
    }

    private static function check_openai_key(): array {
        $configured = false; $masked = '';
        if (class_exists('VES_Config') && method_exists('VES_Config', 'get_openai_api_key')) {
            $key = (string) VES_Config::get_openai_api_key();
            $configured = ($key !== '');
            $masked = self::mask($key);
        }
        return self::row('openai_api_key', $configured ? self::OK : self::WARN,
            $configured ? 'Configured.' : 'Not configured — AI analysis cannot run.',
            ['configured' => $configured, 'masked' => $masked]); // never the raw key
    }

    private static function check_apify_token(): array {
        $configured = false; $masked = '';
        $token = '';
        if (function_exists('ves_get_token')) { $token = (string) ves_get_token(); }
        elseif (function_exists('get_option')) { $token = (string) get_option('ves_apify_token', ''); }
        $configured = ($token !== '');
        $masked = self::mask($token);
        return self::row('apify_token', $configured ? self::OK : self::WARN,
            $configured ? 'Configured.' : 'Not configured — provider runs cannot dispatch.',
            ['configured' => $configured, 'masked' => $masked]);
    }

    private static function check_billing(): array {
        $class_ok = class_exists('VES_Billing');
        $table_ok = false;
        if ($class_ok && method_exists('VES_Billing', 'table_name')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $t = VES_Billing::table_name();
                $table_ok = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);
            }
        }
        $status = ($class_ok && $table_ok) ? self::OK : ($class_ok ? self::WARN : self::ERROR);
        $msg = $class_ok ? ($table_ok ? 'Billing system ready.' : 'Billing class present, ledger table missing.')
                         : 'Billing system unavailable.';
        return self::row('billing_system', $status, $msg, ['class' => $class_ok, 'ledger_table' => $table_ok]);
    }

    private static function check_usage_tracker(): array {
        $class_ok = class_exists('VES_AI_Usage_Tracker');
        $table_ok = $class_ok && VES_AI_Usage_Tracker::table_exists();
        $status = ($class_ok && $table_ok) ? self::OK : self::WARN;
        $msg = $class_ok ? ($table_ok ? 'AI usage tracking ready.' : 'Tracker present; table not yet migrated (run Repair tables).')
                         : 'AI usage tracker not loaded.';
        return self::row('ai_usage_tracker', $status, $msg, ['class' => $class_ok, 'table' => $table_ok]);
    }

    private static function check_scheduler(): array {
        $action_scheduler = class_exists('ActionScheduler') || function_exists('as_schedule_recurring_action');
        $wp_cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        if ($action_scheduler) {
            return self::row('scheduler', self::OK, 'Action Scheduler available.', ['action_scheduler' => true, 'wp_cron_disabled' => $wp_cron_off]);
        }
        return self::row('scheduler', $wp_cron_off ? self::WARN : self::OK,
            $wp_cron_off ? 'Action Scheduler missing and WP-Cron disabled — background jobs may not run.' : 'Falling back to WP-Cron.',
            ['action_scheduler' => false, 'wp_cron_disabled' => $wp_cron_off]);
    }

    private static function check_stripe(): array {
        if (!class_exists('VES_Stripe_Billing')) {
            return self::row('stripe_billing', self::WARN, 'Stripe billing not loaded.', ['class' => false]);
        }
        $configured = false;
        if (method_exists('VES_Stripe_Billing', 'is_configured')) {
            try { $configured = (bool) VES_Stripe_Billing::is_configured(); } catch (\Throwable $e) { $configured = false; }
        } else {
            $opts = function_exists('get_option') ? get_option('ves_stripe_settings', []) : [];
            $configured = is_array($opts) && !empty($opts['secret_key']);
        }
        // Stripe being unconfigured is informational, not an error (self-host without billing).
        return self::row('stripe_billing', $configured ? self::OK : self::WARN,
            $configured ? 'Configured.' : 'Not configured (optional).',
            ['configured' => $configured]); // never the secret
    }

    private static function check_last_api_error(): array {
        if (!class_exists('VES_Admin') || !method_exists('VES_Admin', 'get_diagnostic_log')) {
            return self::row('last_api_error', self::OK, 'No diagnostic log available.', []);
        }
        $log = VES_Admin::get_diagnostic_log();
        if (!is_array($log) || empty($log)) {
            return self::row('last_api_error', self::OK, 'No recent provider errors recorded.', []);
        }
        foreach ($log as $entry) {
            $source = (string) ($entry['source'] ?? '');
            if (strpos($source, 'openai') === 0 || strpos($source, 'apify') === 0) {
                $scrubbed = class_exists('VES_AI_Usage_Tracker')
                    ? VES_AI_Usage_Tracker::scrub_secrets((string) ($entry['message'] ?? ''))
                    : (string) ($entry['message'] ?? '');
                return self::row('last_api_error', self::WARN, 'Last provider diagnostic: ' . $source, [
                    'source' => $source,
                    'when'   => (string) ($entry['timestamp'] ?? ''),
                    'message' => substr($scrubbed, 0, 160),
                ]);
            }
        }
        return self::row('last_api_error', self::OK, 'No recent provider errors recorded.', []);
    }

    private static function check_recent_ai_failures(): array {
        if (!class_exists('VES_AI_Usage_Tracker')) {
            return self::row('recent_ai_failures', self::OK, 'Usage tracker not loaded.', []);
        }
        $summary = VES_AI_Usage_Tracker::summary(24);
        if (empty($summary['table_exists'])) {
            return self::row('recent_ai_failures', self::OK, 'No telemetry yet.', []);
        }
        $failed = (int) ($summary['failed'] ?? 0);
        $total = (int) ($summary['total'] ?? 0);
        $status = $failed > 0 ? self::WARN : self::OK;
        return self::row('recent_ai_failures', $status,
            $failed > 0 ? ($failed . ' of ' . $total . ' AI calls failed in 24h.') : ($total . ' AI calls in 24h, no failures.'),
            ['failed_24h' => $failed, 'total_24h' => $total, 'last_error_code' => (string) ($summary['last_error_code'] ?? '')]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private static function row(string $key, string $status, string $message, array $details): array {
        return [
            'key'     => $key,
            'status'  => in_array($status, [self::OK, self::WARN, self::ERROR], true) ? $status : self::WARN,
            'message' => $message,
            'details' => $details,
        ];
    }

    private static function rollup(array $checks): string {
        $status = self::OK;
        foreach ($checks as $c) {
            $s = $c['status'] ?? self::OK;
            if ($s === self::ERROR) { return self::ERROR; }
            if ($s === self::WARN) { $status = self::WARN; }
        }
        return $status;
    }

    /** Mask a secret to a non-reversible hint (e.g. "sk-…q4" + length). Never returns the value. */
    private static function mask(string $secret): string {
        $len = strlen($secret);
        if ($len === 0) { return ''; }
        $tail = $len > 2 ? substr($secret, -2) : '';
        return '••••' . $tail . ' (' . $len . ' chars)';
    }
}
