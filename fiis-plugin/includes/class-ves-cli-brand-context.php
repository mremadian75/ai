<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_CLI_Brand_Context — Phase 6A WP-CLI diagnostics for Brand Context (Memory V2).
 * Read-only summaries + context preview; expiry is DRY-RUN by default (--apply to write).
 *
 *   wp ves memory-summary [--workspace=<id>] [--format=json]
 *   wp ves memory-context-preview --workspace=<id> --use-case=brief_generation [--format=json]
 *   wp ves memory-expire [--workspace=<id>] [--apply]      (dry-run by default)
 */
final class VES_CLI_Brand_Context {

    public static function register() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('ves memory-summary', [__CLASS__, 'summary']);
            \WP_CLI::add_command('ves memory-context-preview', [__CLASS__, 'context_preview']);
            \WP_CLI::add_command('ves memory-expire', [__CLASS__, 'expire']); // dry-run default
        }
    }

    private static function guard() {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('current_user_can') && !current_user_can('manage_options')) {
            \WP_CLI::error('Insufficient capability.');
            return false;
        }
        if (!class_exists('VES_Brand_Context_Service')) { \WP_CLI::error('Brand Context service unavailable.'); return false; }
        return true;
    }

    public static function summary($args, $assoc) {
        if (!self::guard()) { return; }
        $ws = isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0;
        $s = VES_Brand_Context_Service::summary($ws);
        if (($assoc['format'] ?? '') === 'json') { \WP_CLI::log((string) wp_json_encode($s)); return; }
        \WP_CLI::log(sprintf('Brand context: total=%d trusted=%d candidates=%d', $s['total'], $s['trusted'], $s['candidates']));
        foreach ((array) $s['by_status'] as $st => $n) { \WP_CLI::log(sprintf('  %-12s %d', $st, $n)); }
        \WP_CLI::success('Read-only summary complete.');
    }

    public static function context_preview($args, $assoc) {
        if (!self::guard()) { return; }
        $ws = isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0;
        $uc = isset($assoc['use-case']) ? (string) $assoc['use-case'] : 'brief_generation';
        $pkg = VES_Brand_Context_Service::build_context_package(['workspace_id' => $ws, 'use_case' => $uc]);
        if (($assoc['format'] ?? '') === 'json') { \WP_CLI::log((string) wp_json_encode($pkg)); return; }
        \WP_CLI::log(sprintf('Context package: use_case=%s items=%d/%d chars=%d/%d', $pkg['use_case'], $pkg['item_count'], $pkg['limits']['max_items'], $pkg['char_count'], $pkg['limits']['max_chars']));
        foreach ((array) $pkg['items'] as $it) { \WP_CLI::log(sprintf('  [%s] %s', $it['record_type'], $it['summary'])); }
        if (empty($pkg['items'])) { \WP_CLI::log('  ' . $pkg['note']); }
        \WP_CLI::success('Read-only preview (trusted memory only; candidates excluded).');
    }

    public static function expire($args, $assoc) {
        if (!self::guard()) { return; }
        $ws = isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0;
        $apply = !empty($assoc['apply']);
        $r = VES_Brand_Context_Service::expire_stale($ws, $apply);
        \WP_CLI::log(sprintf('%s: %d eligible past-expiry record(s)%s', $apply ? 'APPLIED' : 'DRY-RUN', $r['eligible'], $apply ? sprintf(', %d marked expired', $r['applied']) : ''));
        if (!$apply) { \WP_CLI::success('Dry-run complete (no writes). Re-run with --apply to mark expired.'); }
        else { \WP_CLI::success('Expiry applied (non-destructive: status only; pinned rows untouched).'); }
    }
}
