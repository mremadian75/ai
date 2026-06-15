<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Run_Lock_Manager {
    private static function key($run_id, $job_type) {
        return 'ves_run_lock:' . (int) $run_id . ':' . sanitize_key((string) $job_type);
    }

    public static function acquire($run_id, $job_type, $ttl = 900) {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return false;
        }
        $ttl = max(60, (int) $ttl);
        $key = self::key($run_id, $job_type);

        // v0.9.8.0 bug fix: the previous "get then set" pattern was a TOCTOU race.
        // We now use `add_option` which compiles to an atomic INSERT IGNORE in MySQL,
        // so concurrent jobs cannot both win the race. We then promote it to a
        // transient so existing TTL semantics still apply.
        $option_key = '_' . $key; // option name keeps the leading underscore convention
        $added = add_option($option_key, time(), '', false);
        if (!$added) {
            // Either an existing lock OR a stale one. If stale, take it over.
            $existing = (int) get_option($option_key, 0);
            if ($existing > 0 && (time() - $existing) <= $ttl) {
                return false;
            }
            // Stale → reset and proceed.
            update_option($option_key, time(), false);
        }
        // Mirror as a transient so other code reading the lock via get_transient
        // still sees it. Both expire within $ttl.
        set_transient($key, time(), $ttl);
        return true;
    }

    public static function refresh($run_id, $job_type, $ttl = 900) {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return false;
        }
        $ttl = max(60, (int) $ttl);
        $key = self::key($run_id, $job_type);
        update_option('_' . $key, time(), false);
        return set_transient($key, time(), $ttl);
    }

    public static function release($run_id, $job_type) {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return;
        }
        $key = self::key($run_id, $job_type);
        delete_option('_' . $key);
        delete_transient($key);
    }

    public static function is_locked($run_id, $job_type) {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return false;
        }
        return false !== get_transient(self::key($run_id, $job_type));
    }

    public static function release_stale_locks() {
        // v0.9.8.0: actually scan for stale locks and release them.
        // Both transient timeouts AND option timestamps older than 1h are released.
        global $wpdb;
        $cutoff = time() - HOUR_IN_SECONDS;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_ves_run_lock:%'
            )
        );
        $released = 0;
        foreach ((array) $rows as $row) {
            $ts = (int) $row->option_value;
            if ($ts > 0 && $ts < $cutoff) {
                delete_option($row->option_name);
                $transient_key = ltrim($row->option_name, '_');
                delete_transient($transient_key);
                $released++;
            }
        }
        return $released;
    }
}
