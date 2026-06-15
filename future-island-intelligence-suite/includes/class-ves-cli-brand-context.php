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
            \WP_CLI::add_command('ves generation-context-preview', [__CLASS__, 'generation_context_preview']); // 6B-A, read-only
            \WP_CLI::add_command('ves generation-prompt-preview', [__CLASS__, 'generation_prompt_preview']); // 6B-B/6C, read-only
            \WP_CLI::add_command('ves operator-queue', [__CLASS__, 'operator_queue']); // 7B, read-only
        }
    }

    /** wp ves generation-context-preview --workspace --use-case [--format=json] (read-only) */
    public static function generation_context_preview($args, $assoc) {
        if (!self::guard()) { return; }
        if (!class_exists('VES_Generation_Context_Resolver')) { \WP_CLI::error('Generation context resolver unavailable.'); return; }
        $params = ['workspace_id' => isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0, 'use_case' => isset($assoc['use-case']) ? (string) $assoc['use-case'] : 'brief_generation'];
        if (isset($assoc['max-items'])) { $params['max_items'] = (int) $assoc['max-items']; }
        if (isset($assoc['max-chars'])) { $params['max_chars'] = (int) $assoc['max-chars']; }
        $p = VES_Generation_Context_Resolver::preview($params);
        if (($assoc['format'] ?? '') === 'json') { \WP_CLI::log((string) wp_json_encode($p)); return; }
        \WP_CLI::log(sprintf('enabled=%s would_apply=%s reason=%s items=%d', $p['enabled'] ? 'yes' : 'no', $p['would_apply'] ? 'yes' : 'no', $p['reason'], $p['items_included']));
        \WP_CLI::success('Read-only preview (trusted memory only; no AI call, no charge).');
    }

    /** wp ves generation-prompt-preview --workspace --use-case --target-type --target-id [--max-context-items=N --max-context-chars=N --include-debug --save-to-file=PATH --force --format=json] (read-only) */
    public static function generation_prompt_preview($args, $assoc) {
        if (!self::guard()) { return; }
        if (!class_exists('VES_Generation_Prompt_Package_Builder')) { \WP_CLI::error('Prompt package builder unavailable.'); return; }
        $build = [
            'workspace_id' => isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0,
            'use_case' => isset($assoc['use-case']) ? (string) $assoc['use-case'] : 'brief_generation',
            'target_type' => isset($assoc['target-type']) ? (string) $assoc['target-type'] : 'insight',
            'target_id' => isset($assoc['target-id']) ? (int) $assoc['target-id'] : 0,
            'include_debug' => !empty($assoc['include-debug']),
        ];
        if (isset($assoc['max-context-items'])) { $build['max_context_items'] = (int) $assoc['max-context-items']; }
        if (isset($assoc['max-context-chars'])) { $build['max_context_chars'] = (int) $assoc['max-context-chars']; }
        $pkg = VES_Generation_Prompt_Package_Builder::build($build);
        if (!empty($assoc['save-to-file'])) {
            $res = VES_Generation_Prompt_Package_Builder::export_to_file($pkg, (string) $assoc['save-to-file'], !empty($assoc['force']));
            if (empty($res['ok'])) { \WP_CLI::warning('Export skipped: ' . $res['reason'] . ' (use --force to overwrite).'); }
            else { \WP_CLI::log(sprintf('Saved sanitized package to %s (%d bytes).', $res['path'], (int) $res['bytes'])); }
        }
        if (($assoc['format'] ?? '') === 'json') { \WP_CLI::log((string) wp_json_encode($pkg)); return; }
        \WP_CLI::log(sprintf('use_case=%s target=%s#%d build_status=%s blocking_reason=%s', $pkg['use_case'], $pkg['target']['type'], $pkg['target']['id'], $pkg['build_status'], $pkg['blocking_reason']));
        \WP_CLI::log(sprintf('evidence count=%d · brand_context applied=%s · contract=%s · provider_execution_allowed=%s', (int) $pkg['evidence']['count'], $pkg['brand_context']['applied'] ? 'yes' : 'no', (string) ($pkg['output_contract']['schema_key'] ?? ''), $pkg['safety']['provider_execution_allowed'] ? 'yes' : 'no'));
        foreach ((array) $pkg['diagnostics']['warnings'] as $w) { \WP_CLI::warning($w); }
        \WP_CLI::success('Read-only prompt-package preview (no AI call, no mutation, no charge).');
    }

    /** wp ves operator-queue --workspace [--queue-type --limit --format=json] (Phase 7B, read-only) */
    public static function operator_queue($args, $assoc) {
        if (!self::guard()) { return; }
        if (!class_exists('VES_Operator_Queue_Service')) { \WP_CLI::error('Operator queue service unavailable.'); return; }
        $q = VES_Operator_Queue_Service::build([
            'workspace_id' => isset($assoc['workspace']) ? max(0, (int) $assoc['workspace']) : 0,
            'queue_type' => isset($assoc['queue-type']) ? (string) $assoc['queue-type'] : '',
            'limit' => isset($assoc['limit']) ? (int) $assoc['limit'] : 10,
        ]);
        if (($assoc['format'] ?? '') === 'json') { \WP_CLI::log((string) wp_json_encode($q)); return; }
        foreach ((array) $q['queues'] as $name => $qd) {
            \WP_CLI::log(sprintf('  %-28s available=%s count=%d', $name, !empty($qd['available']) ? 'yes' : 'no', (int) ($qd['count'] ?? 0)));
        }
        foreach ((array) $q['warnings'] as $w) { \WP_CLI::warning($w); }
        \WP_CLI::success('Read-only operator queue (no mutation, no AI call, no charge).');
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
