<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_CLI_Schema — Phase 3B Part F. WP-CLI schema verification for real
 * WordPress/MySQL installs. Read-only by default; `--repair` re-runs the existing
 * idempotent migrations (additive dbDelta only — never destructive).
 *
 *   wp ves verify-schema
 *   wp ves verify-schema --repair
 */
final class VES_CLI_Schema {

    public static function register(): void {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('ves verify-schema', [__CLASS__, 'verify']);
        }
    }

    /**
     * @param array $args
     * @param array $assoc ['repair' => bool]
     */
    public static function verify($args, $assoc): void {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Insufficient capability.');
            return;
        }
        if (!class_exists('VES_Health_Check') || !method_exists('VES_Health_Check', 'verify_schema')) {
            \WP_CLI::error('VES_Health_Check::verify_schema unavailable.');
            return;
        }

        if (!empty($assoc['repair'])) {
            if (class_exists('VES_Migrations') && method_exists('VES_Migrations', 'force_remigrate')) {
                $res = VES_Migrations::force_remigrate();
                \WP_CLI::log('Repair ran: ' . count((array) ($res['ran'] ?? [])) . ' store(s); failed: ' . count((array) ($res['failed'] ?? [])) . '.');
            } else {
                \WP_CLI::warning('Migration runner unavailable; skipped --repair.');
            }
        }

        $report = VES_Health_Check::verify_schema();
        foreach ((array) ($report['tables'] ?? []) as $t) {
            $status = strtoupper((string) ($t['status'] ?? 'ok'));
            $line = sprintf('[%s] %s%s', $status, (string) ($t['table'] ?? ''),
                !empty($t['missing_columns']) ? ' — missing: ' . implode(', ', (array) $t['missing_columns']) : (empty($t['exists']) ? ' — table missing' : ''));
            if ($status === 'ERROR') { \WP_CLI::warning($line); }
            else { \WP_CLI::log($line); }
        }
        $overall = strtoupper((string) ($report['status'] ?? 'ok'));
        if ($overall === 'ERROR') { \WP_CLI::error('Schema verification: ERROR (run with --repair or activate the plugin).'); }
        elseif ($overall === 'WARNING') { \WP_CLI::warning('Schema verification: WARNING (some columns missing; --repair to apply).'); }
        else { \WP_CLI::success('Schema verification: OK.'); }
    }
}
