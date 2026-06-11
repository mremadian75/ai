<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_CLI_RC_Readiness — `wp ves rc-readiness-check` (v0.1 Release Candidate).
 *
 * Read-only whole-product readiness gate. Never mutates data, never calls a
 * provider, never prints secrets. Exit code 0 for ready_for_staging /
 * ready_with_warnings, 1 for blocked (CI-friendly). PHP 7.4 compatible.
 */
final class VES_CLI_RC_Readiness {

    public static function register() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('ves rc-readiness-check', [__CLASS__, 'run']); // read-only
        }
    }

    /**
     * wp ves rc-readiness-check [--workspace=<id>] [--format=json|table]
     */
    public static function run($args, $assoc) {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Requires manage_options capability.');
            return;
        }
        if (!class_exists('VES_RC_Readiness_Service')) {
            \WP_CLI::error('VES_RC_Readiness_Service unavailable.');
            return;
        }
        $report = VES_RC_Readiness_Service::report([
            'workspace_id' => isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0,
        ]);
        $format = isset($assoc['format']) ? strtolower((string) $assoc['format']) : 'table';

        if ($format === 'json') {
            \WP_CLI::log(function_exists('wp_json_encode') ? wp_json_encode($report, JSON_PRETTY_PRINT) : json_encode($report, JSON_PRETTY_PRINT));
        } else {
            \WP_CLI::log('Future Island v0.1 RC readiness — ' . (string) $report['status']);
            \WP_CLI::log('Plugin: ' . (string) $report['plugin_version'] . ((string) $report['rc_label'] !== '' ? ' (' . (string) $report['rc_label'] . ')' : ''));
            \WP_CLI::log('Live validation: ' . (string) ($report['live_validation']['status'] ?? 'unrun'));
            \WP_CLI::log('Production ready: NO (cannot be granted by this command)');
            foreach ((array) $report['checks'] as $c) {
                \WP_CLI::log(sprintf('  [%-5s] %-28s %s', strtoupper((string) $c['status']), (string) $c['label'], (string) $c['detail']));
            }
            foreach ((array) $report['blockers'] as $b) { \WP_CLI::log('  BLOCKER: ' . (string) $b); }
            foreach ((array) $report['warnings'] as $w) { \WP_CLI::log('  warning: ' . (string) $w); }
        }

        if ((string) $report['status'] === VES_RC_Readiness_Service::STATUS_BLOCKED) {
            \WP_CLI::error('RC readiness: BLOCKED.');
            return;
        }
        \WP_CLI::success('RC readiness: ' . (string) $report['status']);
    }
}
