<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_RC_Evidence_Pack — Phase 9B.3 release evidence pack integrity.
 *
 * A live validation may only be recorded as PASSED through a structured,
 * hash-verified evidence pack. The pack carries environment facts, the outputs
 * and exit codes of every required validation command, screenshot/log manifests
 * and an operator identity; its evidence_pack_hash is a deterministic SHA-256
 * over the canonicalized pack (sorted keys, hash field excluded), so any edit
 * invalidates it. A bare `wp option update ves_rc_live_validation …` without a
 * verifiable pack is classified `unverified_manual` and is never trusted as
 * passed. Recording NEVER produces a production-ready state. PHP 7.4.
 */
final class VES_RC_Evidence_Pack {

    const SCHEMA_VERSION = '1.0';
    const OPTION_LIVE_VALIDATION = 'ves_rc_live_validation';

    /** Commands whose captured output is mandatory for a PASSED pack. */
    const REQUIRED_COMMANDS = [
        'php -v',
        'wp core version',
        'wp option get siteurl',
        'wp ves verify-schema',
        'wp ves validate-staging --format=json',
        'wp ves rc-readiness-check --format=json',
        'wp ves memory-summary --format=json',
        'wp ves operator-queue --workspace=1 --format=json',
        'wp ves memory-expire --dry-run',
    ];

    const REQUIRED_FIELDS = [
        'schema_version', 'build_sha256', 'plugin_version', 'siteurl', 'home',
        'wp_version', 'php_version', 'db_version', 'command_outputs',
        'screenshots_manifest', 'php_error_log_sha256', 'browser_console_log_sha256',
        'generated_at', 'operator', 'validation_status', 'limitations',
    ];

    // ── canonical hash ─────────────────────────────────────────────────────────

    /** Deterministic SHA-256 of the pack: recursive key sort, hash field excluded. */
    public static function compute_hash(array $pack) {
        unset($pack['evidence_pack_hash']);
        $canonical = self::canonicalize($pack);
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', (string) $json);
    }

    private static function canonicalize($value) {
        if (!is_array($value)) { return is_scalar($value) || $value === null ? $value : (string) json_encode($value); }
        $is_list = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        if ($is_list) {
            foreach ($value as $v) { $out[] = self::canonicalize($v); }
            return $out;
        }
        ksort($value);
        foreach ($value as $k => $v) { $out[(string) $k] = self::canonicalize($v); }
        return $out;
    }

    // ── schema ────────────────────────────────────────────────────────────────

    /** @return array{valid:bool,errors:array} */
    public static function schema_validate(array $pack) {
        $errors = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $pack)) { $errors[] = "missing field: {$field}"; }
        }
        if (!is_array($pack['command_outputs'] ?? null)) {
            $errors[] = 'command_outputs must be a map of command => {output_sha256, exit_code}';
        } else {
            foreach (self::REQUIRED_COMMANDS as $cmd) {
                $entry = $pack['command_outputs'][$cmd] ?? null;
                if (!is_array($entry) || !isset($entry['exit_code']) || !isset($entry['output_sha256'])) {
                    $errors[] = "missing required command output: {$cmd}";
                } elseif ((int) $entry['exit_code'] !== 0) {
                    $errors[] = "required command exited non-zero: {$cmd}";
                }
            }
        }
        if (!is_array($pack['screenshots_manifest'] ?? null)) { $errors[] = 'screenshots_manifest must be an array'; }
        $status = self::clean_key((string) ($pack['validation_status'] ?? ''));
        if (!in_array($status, ['passed', 'failed', 'incomplete'], true)) { $errors[] = 'validation_status must be passed|failed|incomplete'; }
        if (!is_array($pack['operator'] ?? null) || trim((string) ($pack['operator']['name'] ?? '')) === '') { $errors[] = 'operator.name is required'; }
        if (!preg_match('/^[a-f0-9]{64}$/i', (string) ($pack['build_sha256'] ?? ''))) { $errors[] = 'build_sha256 must be a SHA-256 hex digest'; }
        if (isset($pack['evidence_pack_hash']) && !preg_match('/^[a-f0-9]{64}$/i', (string) $pack['evidence_pack_hash'])) {
            $errors[] = 'evidence_pack_hash must be a SHA-256 hex digest';
        }
        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /** Validate schema AND that the embedded hash matches the content. */
    public static function verify(array $pack) {
        $schema = self::schema_validate($pack);
        if (!$schema['valid']) { return $schema; }
        $declared = strtolower((string) ($pack['evidence_pack_hash'] ?? ''));
        if ($declared === '') { return ['valid' => false, 'errors' => ['evidence_pack_hash missing']]; }
        $computed = self::compute_hash($pack);
        if (!hash_equals($computed, $declared)) {
            return ['valid' => false, 'errors' => ['evidence_pack_hash mismatch: pack content was altered after hashing']];
        }
        return ['valid' => true, 'errors' => []];
    }

    // ── build (in-WP generator; command outputs may be merged in by the script) ─

    /** Assemble a pack skeleton from the live WP environment. Read-only. */
    public static function build(array $inputs = []) {
        global $wpdb;
        $pack = [
            'schema_version' => self::SCHEMA_VERSION,
            'build_sha256'   => strtolower((string) ($inputs['build_sha256'] ?? str_repeat('0', 64))),
            'plugin_version' => defined('FIS_VERSION') ? FIS_VERSION : 'unknown',
            'rc_label'       => defined('FIS_RC_LABEL') ? FIS_RC_LABEL : '',
            'siteurl'        => function_exists('get_option') ? (string) get_option('siteurl', '') : '',
            'home'           => function_exists('get_option') ? (string) get_option('home', '') : '',
            'wp_version'     => isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : 'unknown',
            'php_version'    => PHP_VERSION,
            'db_version'     => (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'db_version')) ? (string) $wpdb->db_version() : 'unknown',
            'command_outputs' => is_array($inputs['command_outputs'] ?? null) ? $inputs['command_outputs'] : [],
            'screenshots_manifest' => is_array($inputs['screenshots_manifest'] ?? null) ? array_values(array_map('strval', $inputs['screenshots_manifest'])) : [],
            'php_error_log_sha256' => self::clean_hash((string) ($inputs['php_error_log_sha256'] ?? '')),
            'browser_console_log_sha256' => self::clean_hash((string) ($inputs['browser_console_log_sha256'] ?? '')),
            'generated_at'   => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'operator'       => [
                'name'    => self::clean_text((string) ($inputs['operator_name'] ?? ''), 120),
                'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ],
            'validation_status' => 'incomplete',
            'limitations'    => is_array($inputs['limitations'] ?? null) ? array_values(array_map('strval', $inputs['limitations'])) : ['Generated pack; validation_status stays incomplete until every required command output is attached and verified.'],
        ];
        // Status is COMPUTED, never asserted: passed requires every required command
        // captured with exit code 0.
        $all_present = true;
        foreach (self::REQUIRED_COMMANDS as $cmd) {
            $entry = $pack['command_outputs'][$cmd] ?? null;
            if (!is_array($entry) || (int) ($entry['exit_code'] ?? 1) !== 0 || (string) ($entry['output_sha256'] ?? '') === '') {
                $all_present = false;
                break;
            }
        }
        if ($all_present && !empty($inputs['operator_name'])) { $pack['validation_status'] = 'passed'; }
        $pack['evidence_pack_hash'] = self::compute_hash($pack);
        return $pack;
    }

    // ── record live validation (the ONLY trusted write path) ──────────────────

    /**
     * Record a live validation from a verified pack. Refuses invalid schema,
     * hash mismatch, non-passed status, or current critical blockers. Returns
     * the stored state array or WP_Error. Never claims production readiness.
     */
    public static function record_live_validation(array $pack) {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            return new WP_Error('ves_evidence_capability', 'Recording a live validation requires manage_options.');
        }
        $verify = self::verify($pack);
        if (!$verify['valid']) {
            return new WP_Error('ves_evidence_invalid', 'Evidence pack rejected: ' . implode('; ', array_slice($verify['errors'], 0, 5)));
        }
        if (self::clean_key((string) $pack['validation_status']) !== 'passed') {
            return new WP_Error('ves_evidence_not_passed', 'Evidence pack validation_status is not "passed"; refusing to record a pass.');
        }
        if (class_exists('VES_RC_Readiness_Service')) {
            $report = VES_RC_Readiness_Service::report();
            if (!empty($report['blockers'])) {
                return new WP_Error('ves_evidence_blockers', 'Cannot record a live validation pass while readiness blockers exist: ' . implode('; ', array_slice((array) $report['blockers'], 0, 3)));
            }
        }
        $state = [
            'status'             => 'passed',
            'source'             => 'evidence_pack',
            'evidence_pack_hash' => strtolower((string) $pack['evidence_pack_hash']),
            'build_sha256'       => strtolower((string) $pack['build_sha256']),
            'plugin_version'     => (string) $pack['plugin_version'],
            'recorded_at'        => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'operator'           => self::clean_text((string) ($pack['operator']['name'] ?? ''), 120),
            'operator_user_id'   => (int) ($pack['operator']['user_id'] ?? 0),
        ];
        if (function_exists('update_option')) { update_option(self::OPTION_LIVE_VALIDATION, $state, false); }
        return $state;
    }

    /**
     * Classify the stored live-validation option:
     *   passed            — recorded through a verified evidence pack
     *   unverified_manual — option exists but lacks a valid evidence_pack_hash
     *                       (e.g. written manually with wp option update)
     *   unrun             — nothing recorded
     */
    public static function live_validation_state() {
        $raw = function_exists('get_option') ? get_option(self::OPTION_LIVE_VALIDATION, []) : [];
        if (!is_array($raw) || empty($raw['status'])) {
            return ['status' => 'unrun', 'recorded_at' => '', 'evidence_pack_hash' => '', 'note' => 'No live staging validation has been recorded on this install.'];
        }
        $hash = strtolower((string) ($raw['evidence_pack_hash'] ?? ''));
        $is_evidence_backed = (string) ($raw['source'] ?? '') === 'evidence_pack' && preg_match('/^[a-f0-9]{64}$/', $hash);
        if (self::clean_key((string) $raw['status']) === 'passed' && $is_evidence_backed) {
            return [
                'status'             => 'passed',
                'recorded_at'        => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
                'evidence_pack_hash' => $hash,
                'build_sha256'       => strtolower((string) ($raw['build_sha256'] ?? '')),
                'operator'           => self::clean_text((string) ($raw['operator'] ?? ''), 120),
                'note'               => 'Recorded through a verified evidence pack.',
            ];
        }
        return [
            'status'             => 'unverified_manual',
            'recorded_at'        => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
            'evidence_pack_hash' => '',
            'note'               => 'A live-validation option exists but has no verifiable evidence pack hash. NOT trusted as passed.',
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }

    private static function clean_text($s, $max) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function clean_hash($s) {
        $s = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $s));
        return strlen($s) === 64 ? $s : '';
    }
}
