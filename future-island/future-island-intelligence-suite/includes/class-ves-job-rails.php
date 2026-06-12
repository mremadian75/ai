<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Job_Rails — Phase 9A.5 queue/retry/dead-letter rails for background jobs.
 *
 * Storage is additive and table-free: retry counters live in a bounded option
 * map keyed by the job idempotency key; final failures append to a bounded,
 * append-only dead-letter option ring (and mirror to the per-run log when a
 * run_id exists). Failure reasons are scrubbed via VES_Security_Event_Log.
 * A dead-lettered key is refused further execution until an operator clears it
 * explicitly — retries can never run forever, and a replayed job can never
 * resurrect silently. PHP 7.4 compatible.
 */
final class VES_Job_Rails {

    const OPTION_RETRIES     = 'ves_job_retry_counts';
    const OPTION_DEAD_LETTER = 'ves_job_dead_letter';
    const MAX_RETRIES        = 3;
    const MAX_TRACKED_KEYS   = 300;
    const MAX_DEAD_LETTERS   = 100;

    /** Deterministic idempotency key for a job. */
    public static function job_key($job_type, $payload = []) {
        $payload = is_array($payload) ? $payload : [];
        $basis = self::clean_key($job_type)
            . '|run:' . (int) ($payload['run_id'] ?? 0)
            . '|src:' . self::clean_key((string) ($payload['source_key'] ?? ''))
            . '|bundle:' . self::clean_text((string) ($payload['bundle_id'] ?? ''), 80);
        return 'job_' . substr(hash('sha256', $basis), 0, 32);
    }

    /** True when this key has been dead-lettered (job must not run again). */
    public static function is_dead($job_key) {
        foreach (self::dead_letters(self::MAX_DEAD_LETTERS) as $row) {
            if ((string) ($row['job_key'] ?? '') === (string) $job_key) { return true; }
        }
        return false;
    }

    /**
     * Record one failure for a job key. Increments the retry count; when the
     * count reaches MAX_RETRIES the job is dead-lettered (scrubbed reason).
     * @return array{attempts:int,dead:bool}
     */
    public static function record_failure($job_key, $job_type, $reason, array $context = []) {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return ['attempts' => 1, 'dead' => false];
        }
        $job_key = self::clean_text((string) $job_key, 64);
        $counts = get_option(self::OPTION_RETRIES, []);
        if (!is_array($counts)) { $counts = []; }
        $attempts = (int) ($counts[$job_key] ?? 0) + 1;
        $counts[$job_key] = $attempts;
        if (count($counts) > self::MAX_TRACKED_KEYS) {
            $counts = array_slice($counts, -self::MAX_TRACKED_KEYS, null, true);
        }
        update_option(self::OPTION_RETRIES, $counts, false);

        $dead = $attempts >= self::MAX_RETRIES;
        if ($dead) {
            self::append_dead_letter($job_key, $job_type, $reason, $attempts, $context);
        }
        return ['attempts' => $attempts, 'dead' => $dead];
    }

    /** Clear the retry counter after a success so transient errors don't accumulate. */
    public static function record_success($job_key) {
        if (!function_exists('get_option') || !function_exists('update_option')) { return; }
        $counts = get_option(self::OPTION_RETRIES, []);
        if (!is_array($counts) || !isset($counts[$job_key])) { return; }
        unset($counts[$job_key]);
        update_option(self::OPTION_RETRIES, $counts, false);
    }

    /** Most recent dead letters, newest first. Read-only. */
    public static function dead_letters($limit = 20) {
        $rows = function_exists('get_option') ? get_option(self::OPTION_DEAD_LETTER, []) : [];
        if (!is_array($rows)) { return []; }
        $limit = max(1, min(self::MAX_DEAD_LETTERS, (int) $limit));
        return array_reverse(array_slice($rows, -$limit));
    }

    /** Diagnostics for readiness/RC page. Read-only. */
    public static function status() {
        $counts = function_exists('get_option') ? get_option(self::OPTION_RETRIES, []) : [];
        $dead = self::dead_letters(self::MAX_DEAD_LETTERS);
        return [
            'available'      => true,
            'tracked_keys'   => is_array($counts) ? count($counts) : 0,
            'dead_letters'   => count($dead),
            'max_retries'    => self::MAX_RETRIES,
            'healthy'        => count($dead) === 0,
        ];
    }

    /** Operator-only explicit clear of one dead letter (so the job may run again). */
    public static function clear_dead_letter($job_key) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return false; }
        $rows = function_exists('get_option') ? get_option(self::OPTION_DEAD_LETTER, []) : [];
        if (!is_array($rows)) { return false; }
        $kept = [];
        $found = false;
        foreach ($rows as $row) {
            if ((string) ($row['job_key'] ?? '') === (string) $job_key) { $found = true; continue; }
            $kept[] = $row;
        }
        if ($found) { update_option(self::OPTION_DEAD_LETTER, $kept, false); }
        return $found;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private static function append_dead_letter($job_key, $job_type, $reason, $attempts, array $context) {
        $reason = class_exists('VES_Security_Event_Log')
            ? VES_Security_Event_Log::scrub(self::clean_text((string) $reason, 300))
            : self::clean_text((string) $reason, 300);
        $entry = [
            'job_key'    => $job_key,
            'job_type'   => self::clean_key($job_type),
            'reason'     => $reason,
            'attempts'   => (int) $attempts,
            'run_id'     => (int) ($context['run_id'] ?? 0),
            'created_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        $rows = get_option(self::OPTION_DEAD_LETTER, []);
        if (!is_array($rows)) { $rows = []; }
        $rows[] = $entry;
        if (count($rows) > self::MAX_DEAD_LETTERS) { $rows = array_slice($rows, -self::MAX_DEAD_LETTERS); }
        update_option(self::OPTION_DEAD_LETTER, $rows, false);
        if ($entry['run_id'] > 0 && class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::error($entry['run_id'], 'job_rails', 'Job dead-lettered after max retries.', [
                'job_type' => $entry['job_type'], 'job_key' => $job_key, 'attempts' => (int) $attempts,
            ]);
        }
    }

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }

    private static function clean_text($s, $max = 300) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
