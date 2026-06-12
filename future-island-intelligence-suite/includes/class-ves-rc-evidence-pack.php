<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_RC_Evidence_Pack — Phase 9E evidence pack v2 / live-validation anti-forgery.
 *
 * Schema 2.0. A live validation may ONLY be recorded as passed when:
 *   1. the pack verifies (schema + deterministic evidence_pack_hash),
 *   2. validation_status is the COMPUTED 'passed' (never asserted), which now
 *      requires every required CLI command captured to stdout/stderr/combined
 *      files with exit code 0, the 12 required browser screenshots, the browser
 *      console log, the network-errors log, the PHP error-log tail and the DB
 *      backup hash, and
 *   3. every referenced artifact FILE is verified on disk (evidence root or
 *      extracted archive): exists + SHA-256 matches. A pack JSON alone is
 *      classified json_only_unverified and can never be recorded as passed.
 * The recorded state carries files_verified=true; the classifier only ever
 * reports 'passed' for such states. Nothing here can produce a
 * production-ready claim. PHP 7.4 compatible.
 */
final class VES_RC_Evidence_Pack {

    const SCHEMA_VERSION = '2.0';
    const OPTION_LIVE_VALIDATION = 'ves_rc_live_validation';

    /** Every required CLI command for a PASSED pack (9E.1 full battery). */
    const REQUIRED_COMMANDS = [
        'php -v',
        'wp --info',
        'wp core version',
        'wp db query "SELECT VERSION();"',
        'wp option get siteurl',
        'wp option get home',
        'wp plugin list',
        'wp theme list',
        'wp ves verify-schema',
        'wp ves validate-staging --format=json',
        'wp ves readiness-check --format=json',
        'wp ves rc-readiness-check --format=json',
        'wp ves memory-summary --format=json',
        'wp ves memory-context-preview --workspace=1 --use-case=brief_generation --format=json',
        'wp ves generation-context-preview --workspace=1 --use-case=brief_generation --format=json',
        'wp ves generation-prompt-preview --workspace=1 --use-case=brief_generation --target-type=insight --target-id=1 --format=json',
        'wp ves operator-queue --workspace=1 --format=json',
        'wp ves memory-expire --dry-run',
    ];

    /** Canonical browser screenshot set (9E.4). */
    const REQUIRED_SCREENSHOTS = [
        '01-signal-room.png', '02-social-media-signal-report.png',
        '03-evidence-gate-blocked.png', '04-evidence-gate-ready.png',
        '05-operator-queue.png', '06-memory-brand-context.png',
        '07-generation-context-preview.png', '08-prompt-package-preview.png',
        '09-brief-workbench.png', '10-draft-workbench.png',
        '11-release-candidate-page.png', '12-security-dead-letter-diagnostics.png',
    ];

    const REQUIRED_FIELDS = [
        'schema_version', 'build_sha256', 'plugin_version', 'siteurl', 'home',
        'wp_version', 'php_version', 'db_version', 'db_backup_sha256',
        'command_outputs', 'screenshots_manifest', 'screenshot_files',
        'php_error_log_file', 'php_error_log_sha256',
        'browser_console_log_file', 'browser_console_log_sha256',
        'network_errors_file', 'network_errors_sha256',
        'evidence_archive_sha256', 'generated_at', 'operator',
        'validation_status', 'limitations',
    ];

    // ── canonical hash (unchanged mechanics from v1; proven deterministic) ─────

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

    // ── schema v2 ──────────────────────────────────────────────────────────────

    /** @return array{valid:bool,errors:array} */
    public static function schema_validate(array $pack) {
        $errors = [];
        if ((string) ($pack['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be ' . self::SCHEMA_VERSION;
        }
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $pack)) { $errors[] = "missing field: {$field}"; }
        }
        if (!is_array($pack['command_outputs'] ?? null)) {
            $errors[] = 'command_outputs must be a map of command => {exit_code, stdout_file, stdout_sha256, stderr_file, stderr_sha256, combined_file, combined_sha256}';
        } else {
            foreach (self::REQUIRED_COMMANDS as $cmd) {
                $entry = $pack['command_outputs'][$cmd] ?? null;
                if (!is_array($entry)) { $errors[] = "missing required command output: {$cmd}"; continue; }
                if ((int) ($entry['exit_code'] ?? 1) !== 0) { $errors[] = "required command exited non-zero: {$cmd}"; }
                foreach (['stdout', 'stderr', 'combined'] as $stream) {
                    if (!self::valid_relpath((string) ($entry[$stream . '_file'] ?? ''))) { $errors[] = "{$cmd}: {$stream}_file missing or unsafe path"; }
                    if (!self::is_hash((string) ($entry[$stream . '_sha256'] ?? ''))) { $errors[] = "{$cmd}: {$stream}_sha256 missing/invalid"; }
                }
            }
        }
        $manifest = is_array($pack['screenshots_manifest'] ?? null) ? array_map('strval', $pack['screenshots_manifest']) : [];
        foreach (self::REQUIRED_SCREENSHOTS as $shot) {
            if (!in_array($shot, $manifest, true)) { $errors[] = "screenshots_manifest missing required screenshot: {$shot}"; }
        }
        $shot_files = is_array($pack['screenshot_files'] ?? null) ? $pack['screenshot_files'] : [];
        foreach (self::REQUIRED_SCREENSHOTS as $shot) {
            if (!self::is_hash((string) ($shot_files[$shot] ?? ''))) { $errors[] = "screenshot_files missing/invalid sha256 for: {$shot}"; }
        }
        foreach ([
            'php_error_log' => 'PHP error log', 'browser_console_log' => 'browser console log', 'network_errors' => 'network errors log',
        ] as $key => $label) {
            if (!self::valid_relpath((string) ($pack[$key . '_file'] ?? ''))) { $errors[] = "{$label} file missing or unsafe path"; }
            if (!self::is_hash((string) ($pack[$key . '_sha256'] ?? ''))) { $errors[] = "{$label} sha256 missing/invalid (file may be a NO_*_OBSERVED marker but must exist and hash)"; }
        }
        if (!self::is_hash((string) ($pack['db_backup_sha256'] ?? ''))) { $errors[] = 'db_backup_sha256 missing/invalid'; }
        if (!self::is_hash((string) ($pack['build_sha256'] ?? ''))) { $errors[] = 'build_sha256 must be a SHA-256 hex digest'; }
        if (!self::is_hash((string) ($pack['evidence_archive_sha256'] ?? ''))) { $errors[] = 'evidence_archive_sha256 (archive manifest hash) missing/invalid'; }
        $status = self::clean_key((string) ($pack['validation_status'] ?? ''));
        if (!in_array($status, ['passed', 'failed', 'incomplete'], true)) { $errors[] = 'validation_status must be passed|failed|incomplete'; }
        if (!is_array($pack['operator'] ?? null) || trim((string) ($pack['operator']['name'] ?? '')) === '') { $errors[] = 'operator.name is required'; }
        if (isset($pack['evidence_pack_hash']) && !self::is_hash((string) $pack['evidence_pack_hash'])) { $errors[] = 'evidence_pack_hash must be a SHA-256 hex digest'; }
        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /** Schema + embedded hash integrity. */
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

    // ── build (v2 skeleton; status COMPUTED, never asserted) ──────────────────

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
            'db_backup_sha256' => self::clean_hash((string) ($inputs['db_backup_sha256'] ?? '')),
            'command_outputs' => is_array($inputs['command_outputs'] ?? null) ? $inputs['command_outputs'] : [],
            'screenshots_manifest' => is_array($inputs['screenshots_manifest'] ?? null) ? array_values(array_map('strval', $inputs['screenshots_manifest'])) : [],
            'screenshot_files' => is_array($inputs['screenshot_files'] ?? null) ? $inputs['screenshot_files'] : [],
            'php_error_log_file' => (string) ($inputs['php_error_log_file'] ?? ''),
            'php_error_log_sha256' => self::clean_hash((string) ($inputs['php_error_log_sha256'] ?? '')),
            'browser_console_log_file' => (string) ($inputs['browser_console_log_file'] ?? ''),
            'browser_console_log_sha256' => self::clean_hash((string) ($inputs['browser_console_log_sha256'] ?? '')),
            'network_errors_file' => (string) ($inputs['network_errors_file'] ?? ''),
            'network_errors_sha256' => self::clean_hash((string) ($inputs['network_errors_sha256'] ?? '')),
            'evidence_archive_sha256' => self::clean_hash((string) ($inputs['evidence_archive_sha256'] ?? '')),
            'generated_at'   => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'operator'       => [
                'name'    => self::clean_text((string) ($inputs['operator_name'] ?? ''), 120),
                'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ],
            'validation_status' => 'incomplete',
            'limitations'    => is_array($inputs['limitations'] ?? null) ? array_values(array_map('strval', $inputs['limitations'])) : ['Generated skeleton; the validation script attaches command outputs and browser artifacts before this pack can compute passed.'],
        ];
        $probe = $pack;
        $probe['validation_status'] = 'passed';
        $check = self::schema_validate($probe + ['evidence_pack_hash' => str_repeat('0', 64)]);
        $pack['validation_status'] = $check['valid'] && trim((string) $pack['operator']['name']) !== '' ? 'passed' : 'incomplete';
        $pack['evidence_pack_hash'] = self::compute_hash($pack);
        return $pack;
    }

    // ── Phase 9E.2 — file-backed verification ─────────────────────────────────

    /**
     * Verify every referenced artifact against the evidence root on disk.
     * @return array{status:string,missing:array,mismatched:array}
     *   status: ok | missing_required_artifacts | file_hash_mismatch
     */
    public static function verify_files(array $pack, $evidence_root) {
        $root = rtrim((string) $evidence_root, '/');
        $missing = []; $mismatched = [];
        $check = function ($rel, $expected_sha) use ($root, &$missing, &$mismatched) {
            if (!self::valid_relpath((string) $rel)) { $missing[] = (string) $rel . ' (unsafe path)'; return; }
            $abs = $root . '/' . $rel;
            if (!is_file($abs) || !is_readable($abs)) { $missing[] = (string) $rel; return; }
            $actual = hash_file('sha256', $abs);
            if (!self::is_hash((string) $expected_sha) || !hash_equals(strtolower((string) $expected_sha), (string) $actual)) {
                $mismatched[] = (string) $rel;
            }
        };
        foreach ((array) ($pack['command_outputs'] ?? []) as $cmd => $entry) {
            if (!is_array($entry)) { $missing[] = 'command_outputs:' . $cmd; continue; }
            foreach (['stdout', 'stderr', 'combined'] as $stream) {
                $check((string) ($entry[$stream . '_file'] ?? ''), (string) ($entry[$stream . '_sha256'] ?? ''));
            }
        }
        foreach ((array) ($pack['screenshot_files'] ?? []) as $name => $sha) {
            $check('screenshots/' . basename((string) $name), (string) $sha);
        }
        $check((string) ($pack['php_error_log_file'] ?? ''), (string) ($pack['php_error_log_sha256'] ?? ''));
        $check((string) ($pack['browser_console_log_file'] ?? ''), (string) ($pack['browser_console_log_sha256'] ?? ''));
        $check((string) ($pack['network_errors_file'] ?? ''), (string) ($pack['network_errors_sha256'] ?? ''));
        if (count($missing) > 0) { return ['status' => 'missing_required_artifacts', 'missing' => $missing, 'mismatched' => $mismatched]; }
        if (count($mismatched) > 0) { return ['status' => 'file_hash_mismatch', 'missing' => [], 'mismatched' => $mismatched]; }
        return ['status' => 'ok', 'missing' => [], 'mismatched' => []];
    }

    /** Safety cap for the decompressed evidence tar (the spec'd pack is a few MB). */
    const MAX_DECOMPRESSED_BYTES = 1073741824; // 1 GiB

    /**
     * Verify a tar(.gz) evidence archive and run verify_files against the
     * extraction. Hardened path:
     *   1. gzip archives are decompressed to a plain .tar in the temp dir first
     *      (streamed, size-capped) — PharData's direct .tar.gz extraction can
     *      silently produce zero-byte files on some PHP/zlib builds;
     *   2. every entry name is pre-scanned (no absolute paths, no '..' segments)
     *      before extraction;
     *   3. every extracted file must match its archived size — zero-byte or
     *      truncated extraction is detected explicitly and fails closed;
     *   4. the temp dir is removed on EVERY return path.
     * The pack's evidence_archive_sha256 is the ARCHIVE MANIFEST hash (sha256 of
     * manifest-files.txt — non-circular) and is re-verified against the extracted
     * manifest. Failure to extract/verify NEVER records a pass.
     * @return array{status:string,missing:array,mismatched:array,root:string}
     */
    public static function verify_archive(array $pack, $archive_path) {
        $archive_path = (string) $archive_path;
        if (!is_file($archive_path) || !is_readable($archive_path)) {
            return ['status' => 'missing_required_artifacts', 'missing' => ['evidence archive: ' . basename($archive_path)], 'mismatched' => [], 'root' => ''];
        }
        if (!class_exists('PharData')) {
            return ['status' => 'missing_required_artifacts', 'missing' => ['PharData unavailable: cannot extract archive for verification'], 'mismatched' => [], 'root' => ''];
        }
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/ves-evidence-' . substr(hash('sha256', $archive_path . microtime()), 0, 12);
        if (!@mkdir($tmp, 0700, true) || !is_dir($tmp)) {
            return ['status' => 'missing_required_artifacts', 'missing' => ['temp extraction directory could not be created'], 'mismatched' => [], 'root' => ''];
        }
        $fail = static function ($detail) use ($tmp) {
            self::cleanup_dir($tmp);
            return ['status' => 'missing_required_artifacts', 'missing' => [(string) $detail], 'mismatched' => [], 'root' => ''];
        };
        // 1. Decompress gzip → plain .tar ourselves (detected by magic bytes, not name).
        $tar_path = $archive_path;
        $magic = (string) @file_get_contents($archive_path, false, null, 0, 2);
        if ($magic === "\x1f\x8b") {
            if (!function_exists('gzopen')) {
                return $fail('zlib unavailable: cannot decompress the gzip evidence archive');
            }
            $tar_path = $tmp . '/evidence.tar';
            $gz = @gzopen($archive_path, 'rb');
            if ($gz === false) { return $fail('archive decompression failed: cannot open gzip stream'); }
            $out = @fopen($tar_path, 'wb');
            if ($out === false) { @gzclose($gz); return $fail('archive decompression failed: cannot write temp tar'); }
            $written = 0;
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 1048576);
                if ($chunk === false) { @fclose($out); @gzclose($gz); return $fail('archive decompression failed: corrupt or truncated gzip stream'); }
                if ($chunk === '') { break; }
                $written += strlen($chunk);
                if ($written > self::MAX_DECOMPRESSED_BYTES) {
                    @fclose($out); @gzclose($gz);
                    return $fail('archive decompression refused: decompressed size exceeds the 1 GiB safety cap');
                }
                fwrite($out, $chunk);
            }
            @fclose($out); @gzclose($gz);
            if ($written === 0 || (int) @filesize($tar_path) === 0) {
                return $fail('archive decompression produced an empty tar (corrupt, truncated, or empty gzip stream)');
            }
        }
        // 2. Pre-scan entry names; record per-entry sizes for the extraction audit.
        $entry_sizes = [];
        $xdir = $tmp . '/x';
        try {
            $phar = new PharData($tar_path);
            $prefix = 'phar://' . $tar_path . '/';
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                $rel = str_replace('\\', '/', (string) $entry->getPathname());
                $norm_prefix = str_replace('\\', '/', $prefix);
                if (strpos($rel, $norm_prefix) === 0) { $rel = substr($rel, strlen($norm_prefix)); }
                if (!self::safe_archive_entry($rel)) {
                    return $fail('archive refused: unsafe entry path inside archive (absolute or traversal)');
                }
                if ($entry->isFile()) { $entry_sizes[$rel] = (int) $entry->getSize(); }
            }
            if (count($entry_sizes) === 0) {
                return $fail('archive contains no files');
            }
            if (!@mkdir($xdir, 0700, true) || !is_dir($xdir)) {
                return $fail('temp extraction directory could not be created');
            }
            $phar->extractTo($xdir, null, true);
        } catch (\Throwable $e) {
            return $fail('archive extraction failed: ' . basename($archive_path));
        }
        // 3. Extraction audit: every entry present at its full archived size.
        $short = [];
        foreach ($entry_sizes as $rel => $size) {
            $abs = $xdir . '/' . $rel;
            $got = is_file($abs) ? (int) @filesize($abs) : -1;
            if ($got !== $size) {
                $short[] = $rel . ($got === 0 && $size > 0 ? ' (extracted as zero bytes)' : ' (extraction incomplete)');
                if (count($short) >= 8) { break; }
            }
        }
        if (count($short) > 0) {
            self::cleanup_dir($tmp);
            return ['status' => 'missing_required_artifacts', 'missing' => $short, 'mismatched' => [], 'root' => ''];
        }
        // The archive contains the evidence folder; locate the root that holds the manifest.
        $root = $xdir;
        $manifest = $root . '/manifest-files.txt';
        if (!is_file($manifest)) {
            $entries = glob($xdir . '/*', GLOB_ONLYDIR);
            foreach ((array) $entries as $dir) {
                if (is_file($dir . '/manifest-files.txt')) { $root = $dir; $manifest = $dir . '/manifest-files.txt'; break; }
            }
        }
        if (!is_file($manifest)) {
            return $fail('manifest-files.txt not found in archive');
        }
        if ((int) @filesize($manifest) === 0) {
            return $fail('manifest-files.txt extracted as zero bytes (extraction edge case; archive not trusted)');
        }
        $manifest_sha = (string) hash_file('sha256', $manifest);
        $declared = strtolower((string) ($pack['evidence_archive_sha256'] ?? ''));
        if (!self::is_hash($declared) || !hash_equals($declared, $manifest_sha)) {
            self::cleanup_dir($tmp);
            return ['status' => 'file_hash_mismatch', 'missing' => [], 'mismatched' => ['manifest-files.txt (archive manifest hash mismatch)'], 'root' => ''];
        }
        $result = self::verify_files($pack, $root);
        $result['root'] = '';
        self::cleanup_dir($tmp);
        return $result;
    }

    /** Archive entry names must be relative and traversal-free. */
    private static function safe_archive_entry($p) {
        $p = (string) $p;
        if ($p === '' || strpos($p, "\0") !== false) { return false; }
        if ($p[0] === '/' || preg_match('/^[A-Za-z]:/', $p)) { return false; }
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') { return false; }
        }
        return true;
    }

    /** Best-effort recursive removal of the temp extraction dir (bounded to /tmp). */
    private static function cleanup_dir($dir) {
        $dir = (string) $dir;
        if ($dir === '' || strpos($dir, rtrim(sys_get_temp_dir(), '/') . '/ves-evidence-') !== 0 || !is_dir($dir)) { return; }
        $items = scandir($dir);
        foreach ((array) $items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $path = $dir . '/' . $item;
            if (is_dir($path)) { self::cleanup_dir_inner($path); } else { @unlink($path); }
        }
        @rmdir($dir);
    }

    private static function cleanup_dir_inner($dir) {
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $path = $dir . '/' . $item;
            if (is_dir($path)) { self::cleanup_dir_inner($path); } else { @unlink($path); }
        }
        @rmdir($dir);
    }

    // ── Phase 9E.2 — the ONLY trusted write path ───────────────────────────────

    /**
     * Record a live validation. $opts MUST provide 'evidence_root' or
     * 'archive_path' — a pack JSON alone is json_only_unverified and is refused.
     * @return array|WP_Error
     */
    public static function record_live_validation(array $pack, array $opts = []) {
        if (function_exists('current_user_can') && function_exists('is_user_logged_in') && is_user_logged_in() && !current_user_can('manage_options')) {
            return new WP_Error('ves_evidence_capability', 'Recording a live validation requires manage_options.');
        }
        $verify = self::verify($pack);
        if (!$verify['valid']) {
            return new WP_Error('ves_evidence_invalid', 'Evidence pack rejected: ' . implode('; ', array_slice($verify['errors'], 0, 6)));
        }
        if (self::clean_key((string) $pack['validation_status']) !== 'passed') {
            return new WP_Error('ves_evidence_not_passed', 'Evidence pack validation_status is not "passed"; refusing to record a pass.');
        }
        $root = isset($opts['evidence_root']) ? (string) $opts['evidence_root'] : '';
        $archive = isset($opts['archive_path']) ? (string) $opts['archive_path'] : '';
        if ($root === '' && $archive === '') {
            return new WP_Error('ves_evidence_json_only', 'A pack JSON alone is json_only_unverified: provide --evidence-root or --evidence-archive so every artifact file can be verified.');
        }
        if ($root !== '') {
            if (!is_dir($root)) { return new WP_Error('ves_evidence_root_missing', 'Evidence root folder not found.'); }
            $files = self::verify_files($pack, $root);
        } else {
            $files = self::verify_archive($pack, $archive);
        }
        if (($files['status'] ?? '') !== 'ok') {
            $detail = array_slice(array_merge((array) $files['missing'], (array) $files['mismatched']), 0, 8);
            return new WP_Error('ves_evidence_' . $files['status'], 'Artifact verification failed (' . $files['status'] . '): ' . implode('; ', $detail));
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
            'schema_version'     => self::SCHEMA_VERSION,
            'files_verified'     => true,
            'verified_via'       => $root !== '' ? 'evidence_root' : 'evidence_archive',
            'evidence_pack_hash' => strtolower((string) $pack['evidence_pack_hash']),
            'evidence_archive_sha256' => strtolower((string) $pack['evidence_archive_sha256']),
            'build_sha256'       => strtolower((string) $pack['build_sha256']),
            'plugin_version'     => (string) $pack['plugin_version'],
            'screenshots_verified' => count(self::REQUIRED_SCREENSHOTS),
            'recorded_at'        => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'operator'           => self::clean_text((string) ($pack['operator']['name'] ?? ''), 120),
            'operator_user_id'   => (int) ($pack['operator']['user_id'] ?? 0),
        ];
        if (function_exists('update_option')) { update_option(self::OPTION_LIVE_VALIDATION, $state, false); }
        return $state;
    }

    /**
     * Classify the stored live-validation option (9E.2 taxonomy + Phase 1 states):
     *   passed              — evidence-pack recorded WITH file verification
     *   failed              — a validation run was explicitly recorded as FAILED
     *   json_only_unverified — evidence-pack-shaped state without files_verified
     *   unverified_manual   — any other manual option
     *   unrun               — nothing recorded
     *   unknown_error       — something IS recorded but is unreadable/garbage
     *                         (never silently reported as unrun)
     */
    public static function live_validation_state() {
        $raw = function_exists('get_option') ? get_option(self::OPTION_LIVE_VALIDATION, null) : null;
        if ($raw === null || $raw === false) {
            return ['status' => 'unrun', 'recorded_at' => '', 'evidence_pack_hash' => '', 'schema_version' => '', 'note' => 'No live staging validation has been recorded on this install.', 'missing_for_trust' => ['evidence pack', 'file-backed verification', 'browser artifacts']];
        }
        if (!is_array($raw) || empty($raw['status']) || !is_string($raw['status'])) {
            return ['status' => 'unknown_error', 'recorded_at' => '', 'evidence_pack_hash' => '', 'schema_version' => '', 'note' => 'A live-validation record exists but could not be read (corrupt or unexpected shape). Treat as NOT validated; re-record from evidence.', 'missing_for_trust' => ['a readable, verified evidence-pack record']];
        }
        if (self::clean_key((string) $raw['status']) === 'failed') {
            return [
                'status'            => 'failed',
                'recorded_at'       => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
                'evidence_pack_hash' => '',
                'schema_version'    => self::clean_text((string) ($raw['schema_version'] ?? ''), 8),
                'note'              => 'A live staging validation was recorded as FAILED. The release must not proceed until a passing, file-backed run is recorded.',
                'missing_for_trust' => ['a PASSING, file-backed evidence pack (fix the failures, re-run the validation script, re-record)'],
            ];
        }
        $hash = strtolower((string) ($raw['evidence_pack_hash'] ?? ''));
        $is_pack = (string) ($raw['source'] ?? '') === 'evidence_pack' && self::is_hash($hash);
        $files_verified = !empty($raw['files_verified']);
        if (self::clean_key((string) $raw['status']) === 'passed' && $is_pack && $files_verified) {
            return [
                'status'             => 'passed',
                'schema_version'     => self::clean_text((string) ($raw['schema_version'] ?? ''), 8),
                'files_verified'     => true,
                'verified_via'       => self::clean_key((string) ($raw['verified_via'] ?? '')),
                'recorded_at'        => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
                'evidence_pack_hash' => $hash,
                'evidence_archive_sha256' => strtolower((string) ($raw['evidence_archive_sha256'] ?? '')),
                'build_sha256'       => strtolower((string) ($raw['build_sha256'] ?? '')),
                'operator'           => self::clean_text((string) ($raw['operator'] ?? ''), 120),
                'note'               => 'Recorded through a verified, file-backed evidence pack.',
                'missing_for_trust'  => [],
            ];
        }
        if ($is_pack && !$files_verified) {
            return [
                'status' => 'json_only_unverified',
                'schema_version' => self::clean_text((string) ($raw['schema_version'] ?? ''), 8),
                'recorded_at' => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
                'evidence_pack_hash' => '',
                'note' => 'An evidence-pack-shaped record exists but its artifact files were never verified. NOT trusted as passed.',
                'missing_for_trust' => ['file-backed artifact verification (re-record with --evidence-root or --evidence-archive)'],
            ];
        }
        return [
            'status'             => 'unverified_manual',
            'recorded_at'        => self::clean_text((string) ($raw['recorded_at'] ?? ''), 40),
            'evidence_pack_hash' => '',
            'schema_version'     => '',
            'note'               => 'A live-validation option exists but has no verifiable evidence pack. NOT trusted as passed.',
            'missing_for_trust'  => ['verified evidence pack (schema 2.0)', 'file-backed artifact verification', 'browser screenshots + console/network logs'],
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function is_hash($s) { return (bool) preg_match('/^[a-f0-9]{64}$/i', (string) $s); }

    /** Relative, traversal-free path inside the evidence folder. */
    private static function valid_relpath($p) {
        $p = (string) $p;
        if ($p === '' || strpos($p, '..') !== false) { return false; }
        if ($p[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $p)) { return false; }
        return true;
    }

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
