<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Migrations — central, version-driven schema guard + health reporter.
 *
 * The plugin's tables are created lazily by each store's create_table()/create_tables(),
 * but that relied on each store remembering to bump its own DB_VERSION when its schema
 * changed. If a column was added without a version bump, existing installs never re-ran
 * dbDelta and inserts to the new column failed silently (this is the class of bug behind
 * the "usage_reservation_failed" / record_event failures).
 *
 * This runner makes that impossible: when the global SCHEMA_VERSION changes, it forces a
 * dbDelta refresh across EVERY table exactly once (idempotent; dbDelta only adds columns/
 * indexes, never drops data). Bump SCHEMA_VERSION whenever any table schema changes.
 *
 * It also exposes a read-only health() snapshot for the admin System Health panel.
 */
final class VES_Migrations {

    /** Bump this whenever ANY table schema changes to force a one-time re-migration. */
    const SCHEMA_VERSION = '2026.06.14-1';
    const OPTION = 'ves_schema_version';
    const LOCK   = 'ves_schema_migrating';
    const REPAIR = 'ves_repair_tables';

    public static function register() {
        // Runs on the first request after an update; the version-gate keeps it a single
        // cheap get_option comparison on every subsequent request.
        add_action('init', [__CLASS__, 'maybe_run'], 1);
        add_action('admin_post_' . self::REPAIR, [__CLASS__, 'handle_repair']);
    }

    public static function handle_repair() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) { wp_die('Insufficient capability'); }
            return;
        }
        if (function_exists('check_admin_referer')) { check_admin_referer(self::REPAIR); }
        $res = self::force_remigrate();
        $back = (function_exists('wp_get_referer') ? wp_get_referer() : '') ?: admin_url('admin.php?page=ves-fiis-console&tab=advanced');
        wp_safe_redirect(add_query_arg('ves_repaired', count($res['ran']), $back));
        exit;
    }

    /** [class, method] pairs. Force is passed as a 2nd arg; no-arg methods ignore it. */
    public static function stores(): array {
        return [
            ['VES_Usage_Billing', 'ensure_setup'],
            ['VES_Usage_Billing', 'create_tables'],
            ['VES_Stripe_Billing', 'create_tables'],
            ['VES_Memory_Records', 'create_table'],
            ['VES_Review_Decision_Ledger', 'create_table'],
            ['VES_Insight_Records', 'create_table'],
            ['VES_Opportunity_Records', 'create_table'],
            ['VES_Brief_Records', 'create_table'],
            ['VES_Evidence_Store', 'create_table'],
            ['VES_Knowledge_Graph', 'create_tables'],
            ['VES_Knowledge_Consolidator', 'create_table'],
            ['VES_Semantic_Index', 'create_table'],
            ['VES_Pattern_Candidates', 'create_table'],
            ['VES_Metric_Snapshots', 'create_table'],
            ['VES_Workflow_Events', 'create_table'],
            ['VES_Trend_Run_Store', 'create_table'],
            ['VES_Trend_Insight_Store', 'create_table'],
            ['VES_Market_Signal_Store', 'create_tables'],
            ['VES_Market_Signal_Commercial', 'create_tables'],
            ['VES_Monitor_Store', 'create_table'],
            ['VES_Projects', 'create_table'],
            ['VES_Workspace_Profile', 'create_table'],
            ['VES_Assistant_Threads', 'create_tables'],
            ['VES_Feedback_Learning', 'create_table'],
            ['VES_Brand_Audit_Run_Store', 'create_table'],
            ['VES_Run_Log_Service', 'create_table'],
            ['VES_Temp_Memory', 'create_table'],
            ['VES_AI_Usage_Tracker', 'create_table'],
            ['VES_Intelligence_Store', 'create_tables'],
            ['VES_Trend_Observation_Store', 'create_table'],
            ['VES_Trend_Record_Store', 'create_table'],
        ];
    }

    public static function maybe_run() {
        try {
            if (get_option(self::OPTION) === self::SCHEMA_VERSION) { return; }
            if (get_transient(self::LOCK)) { return; }
            set_transient(self::LOCK, 1, 180);
            self::run_all(true);
            update_option(self::OPTION, self::SCHEMA_VERSION, false);
            delete_transient(self::LOCK);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_Migrations] maybe_run failed: ' . $e->getMessage());
            }
        }
    }

    /** Force-(re)create every table. Returns which ran/failed. Each call is isolated. */
    public static function run_all($force = false): array {
        $out = ['ran' => [], 'failed' => []];
        foreach (self::stores() as $pair) {
            [$class, $method] = $pair;
            if (!class_exists($class) || !method_exists($class, $method)) { continue; }
            try {
                call_user_func([$class, $method], $force);
                $out['ran'][] = $class . '::' . $method;
            } catch (\Throwable $e) {
                $out['failed'][] = $class . '::' . $method;
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                    error_log('[VES_Migrations] ' . $class . '::' . $method . ' failed: ' . $e->getMessage());
                }
            }
        }
        return $out;
    }

    /** Manually force a full re-migration (used by the admin "Repair tables" button). */
    public static function force_remigrate(): array {
        $res = self::run_all(true);
        if (function_exists('update_option')) { update_option(self::OPTION, self::SCHEMA_VERSION, false); }
        return $res;
    }

    // ── Read-only health snapshot (for the System Health panel) ────────────────

    public static function health(): array {
        global $wpdb;
        $health = [
            'plugin_version' => defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '',
            'schema_version_code' => self::SCHEMA_VERSION,
            'schema_version_db' => function_exists('get_option') ? (string) get_option(self::OPTION, '(never run)') : '',
            'object_cache' => (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) ? 'external (Redis/Memcached)' : 'database transients',
            'tables' => [],
            'reservation_failures_7d' => null,
            'pending_reservations' => null,
        ];
        $health['schema_in_sync'] = ($health['schema_version_db'] === self::SCHEMA_VERSION);

        if (!isset($wpdb) || !is_object($wpdb)) { return $health; }
        try {
            $prefix = $wpdb->prefix;
            $patterns = [$prefix . 'ves\_%', $prefix . 'fici\_%', $prefix . 'fidtf\_%'];
            $names = [];
            foreach ($patterns as $p) {
                $found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $p));
                if (is_array($found)) { $names = array_merge($names, $found); }
            }
            $names = array_values(array_unique($names));
            sort($names);
            foreach ($names as $name) {
                $safe = '`' . str_replace('`', '``', (string) $name) . '`';
                $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . $safe);
                $health['tables'][] = ['name' => (string) $name, 'rows' => $count === null ? '—' : (int) $count];
            }

            // Recent reservation failures (denied/insufficient) — surfaces billing issues.
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'table_name')) {
                $ut = VES_Usage_Billing::table_name();
                $ut_safe = '`' . str_replace('`', '``', (string) $ut) . '`';
                $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
                $health['reservation_failures_7d'] = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $ut_safe . " WHERE status = 'denied' AND created_at >= %s", $since
                ));
                $health['pending_reservations'] = (int) $wpdb->get_var(
                    'SELECT COUNT(*) FROM ' . $ut_safe . " WHERE status = 'pending'"
                );
            }
        } catch (\Throwable $e) {
            $health['error'] = $e->getMessage();
        }
        return $health;
    }
}
