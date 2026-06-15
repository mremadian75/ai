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
            \WP_CLI::add_command('ves rc-evidence-pack', [__CLASS__, 'evidence_pack']); // read-only generator (writes only the output file)
            \WP_CLI::add_command('ves rc-record-live-validation', [__CLASS__, 'record_live_validation']); // verified write
        }
    }

    /**
     * wp ves rc-readiness-check [--workspace=<id>] [--strict] [--format=json|table]
     * --strict (9C.4): blocked unless live validation passed with a verified
     * evidence pack and every hard rail is active; success is
     * ready_for_pilot_review — never production-ready.
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
            'strict' => !empty($assoc['strict']),
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

    /**
     * wp ves rc-evidence-pack --output=<folder> [--build-sha=<sha256>]
     *   [--operator=<name>] [--screenshots-dir=<dir>] [--db-backup=<file>]
     *   [--php-error-log=<file>] [--browser-log=<file>] [--format=json]
     * Phase 9B.3 — generates the evidence pack JSON from the live environment.
     * Read-only apart from writing the pack file itself. validation_status is
     * COMPUTED (passed only when every required command output is attached by
     * the validation script); a freshly generated pack is 'incomplete'.
     */
    public static function evidence_pack($args, $assoc) {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Requires manage_options capability.');
            return;
        }
        if (!class_exists('VES_RC_Evidence_Pack')) { \WP_CLI::error('VES_RC_Evidence_Pack unavailable.'); return; }
        $output = isset($assoc['output']) ? rtrim((string) $assoc['output'], '/') : '';
        if ($output === '' || !is_dir($output) || !is_writable($output)) {
            if (class_exists('VES_Security_Event_Log')) { VES_Security_Event_Log::record('invalid_cli_args', 'rc-evidence-pack called without a writable --output folder.'); }
            \WP_CLI::error('--output must be an existing writable folder.');
            return;
        }
        $inputs = [
            'build_sha256' => isset($assoc['build-sha']) ? (string) $assoc['build-sha'] : str_repeat('0', 64),
            'operator_name' => isset($assoc['operator']) ? (string) $assoc['operator'] : '',
        ];
        if (!empty($assoc['screenshots-dir']) && is_dir((string) $assoc['screenshots-dir'])) {
            $shots = glob(rtrim((string) $assoc['screenshots-dir'], '/') . '/*.{png,jpg,jpeg}', GLOB_BRACE);
            $inputs['screenshots_manifest'] = array_map('basename', is_array($shots) ? $shots : []);
            // Schema 2.0: every screenshot is hashed so the pack is file-verifiable.
            $inputs['screenshot_files'] = [];
            foreach ((array) $shots as $shot_path) {
                if (is_readable($shot_path)) { $inputs['screenshot_files'][basename($shot_path)] = hash_file('sha256', $shot_path); }
            }
        }
        if (!empty($assoc['db-backup']) && is_readable((string) $assoc['db-backup'])) {
            $inputs['db_backup_sha256'] = hash_file('sha256', (string) $assoc['db-backup']);
        }
        foreach (['php-error-log' => 'php_error_log_sha256', 'browser-log' => 'browser_console_log_sha256'] as $arg => $field) {
            if (!empty($assoc[$arg]) && is_readable((string) $assoc[$arg])) {
                $inputs[$field] = hash_file('sha256', (string) $assoc[$arg]);
            }
        }
        $pack = VES_RC_Evidence_Pack::build($inputs);
        $path = $output . '/evidence-pack-' . gmdate('Ymd-His') . '.json';
        $json = function_exists('wp_json_encode') ? wp_json_encode($pack, JSON_PRETTY_PRINT) : json_encode($pack, JSON_PRETTY_PRINT);
        if (false === file_put_contents($path, (string) $json)) {
            \WP_CLI::error('Could not write evidence pack to ' . $path);
            return;
        }
        \WP_CLI::log('Evidence pack written: ' . $path);
        \WP_CLI::log('evidence_pack_hash: ' . (string) $pack['evidence_pack_hash']);
        \WP_CLI::log('validation_status: ' . (string) $pack['validation_status'] . ' (the validation script attaches command outputs to complete it)');
        \WP_CLI::success('Pack generated. It does NOT record a validation by itself.');
    }

    /**
     * wp ves rc-record-live-validation --evidence-pack=<file.json>
     *   ( --evidence-root=<folder> | --evidence-archive=<file.tar.gz> )
     * Phase 9E.2 — the ONLY trusted way to record a live validation pass. The
     * pack must verify (schema 2.0 + deterministic hash + full required command
     * battery + browser artifacts) AND every referenced artifact file must exist
     * with a matching SHA-256 in the evidence root or extracted archive. A pack
     * JSON alone is json_only_unverified and is refused. Never produces a
     * production-ready state.
     */
    public static function record_live_validation($args, $assoc) {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            \WP_CLI::error('Requires manage_options capability.');
            return;
        }
        if (!class_exists('VES_RC_Evidence_Pack')) { \WP_CLI::error('VES_RC_Evidence_Pack unavailable.'); return; }
        $file = isset($assoc['evidence-pack']) ? (string) $assoc['evidence-pack'] : '';
        if ($file === '' || !is_readable($file)) {
            if (class_exists('VES_Security_Event_Log')) { VES_Security_Event_Log::record('invalid_cli_args', 'rc-record-live-validation called without a readable --evidence-pack file.'); }
            \WP_CLI::error('--evidence-pack must point to a readable JSON file.');
            return;
        }
        $root = isset($assoc['evidence-root']) ? (string) $assoc['evidence-root'] : '';
        $archive = isset($assoc['evidence-archive']) ? (string) $assoc['evidence-archive'] : '';
        if ($root === '' && $archive === '') {
            if (class_exists('VES_Security_Event_Log')) { VES_Security_Event_Log::record('invalid_cli_args', 'rc-record-live-validation called without --evidence-root/--evidence-archive (json-only refused).'); }
            \WP_CLI::error('Refused: provide --evidence-root=<folder> or --evidence-archive=<file.tar.gz>. A pack JSON alone is json_only_unverified and cannot be recorded as passed.');
            return;
        }
        $pack = json_decode((string) file_get_contents($file), true);
        if (!is_array($pack)) { \WP_CLI::error('Evidence pack file is not valid JSON.'); return; }
        $opts = [];
        if ($root !== '') { $opts['evidence_root'] = $root; }
        if ($archive !== '') { $opts['archive_path'] = $archive; }
        $result = VES_RC_Evidence_Pack::record_live_validation($pack, $opts);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            \WP_CLI::error('Refused: ' . $result->get_error_message());
            return;
        }
        \WP_CLI::log('Recorded live validation (evidence-backed, file-verified via ' . (string) $result['verified_via'] . ').');
        \WP_CLI::log('schema_version:      ' . (string) $result['schema_version']);
        \WP_CLI::log('evidence_pack_hash:  ' . (string) $result['evidence_pack_hash']);
        \WP_CLI::log('archive_manifest:    ' . (string) $result['evidence_archive_sha256']);
        \WP_CLI::log('build_sha256:        ' . (string) $result['build_sha256']);
        \WP_CLI::success('Live validation recorded. This does NOT make the build production-ready.');
    }
}
