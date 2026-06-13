<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Usage_Settlement — Phase 9B.2 explicit settlement-state semantics.
 *
 * Compatibility layer over the existing usage ledger (VES_Usage_Billing): the
 * stored statuses stay pending/posted/void/reversed (no destructive migration);
 * this class maps every event onto the canonical taxonomy
 *   reserved / completed / failed / voided / settlement_required /
 *   not_chargeable / diagnostic_only
 * using status + settlement_classification + context, exposes an unsettled
 * report for readiness, and provides an append-only settlement_required marker
 * (bounded option) for runs the ledger could not finalize. It never fabricates
 * costs or refunds and never mutates ledger rows itself. PHP 7.4 compatible.
 */
final class VES_Usage_Settlement {

    const STATES = ['reserved', 'completed', 'failed', 'voided', 'settlement_required', 'not_chargeable', 'diagnostic_only'];
    const OPTION_SETTLEMENT_REQUIRED = 'ves_usage_settlement_required';
    const MAX_MARKERS = 100;
    const STALE_RESERVED_HOURS = 24;

    /** Map one ledger event row onto the canonical settlement state. */
    public static function canonical_state(array $event) {
        $status = self::clean_key((string) ($event['status'] ?? ''));
        $context = self::decode_context($event);
        if (!empty($context['unlimited']) || $status === 'guest') { return 'diagnostic_only'; }
        if ($status === 'pending') { return 'reserved'; }
        if ($status === 'void') { return 'voided'; }
        if ($status === 'reversed') { return 'voided'; }
        if ($status === 'posted') {
            $classification = self::clean_key((string) ($context['settlement_classification'] ?? ''));
            if ($classification === 'not_chargeable_zero_delivery') { return 'not_chargeable'; }
            if (!empty($context['settlement_required'])) { return 'settlement_required'; }
            if (!empty($context['failed']) || $classification === 'failed') { return 'failed'; }
            return 'completed';
        }
        if ($status === 'failed') { return 'failed'; }
        return 'settlement_required'; // unknown stored status: surface, never hide
    }

    /**
     * Append-only marker: a provider run finished in a state the ledger cannot
     * settle automatically (e.g. provider charged but delivery is ambiguous).
     * Never deducts credits, never fabricates a refund — it makes the row loud
     * until an operator resolves it.
     */
    public static function mark_settlement_required($usage_key, $reason = '') {
        if (!function_exists('get_option') || !function_exists('update_option')) { return false; }
        $usage_key = self::clean_text((string) $usage_key, 80);
        if ($usage_key === '') { return false; }
        $markers = get_option(self::OPTION_SETTLEMENT_REQUIRED, []);
        if (!is_array($markers)) { $markers = []; }
        foreach ($markers as $m) {
            if ((string) ($m['usage_key'] ?? '') === $usage_key && empty($m['resolved_at'])) {
                return true; // idempotent: already marked and unresolved
            }
        }
        $markers[] = [
            'usage_key'  => $usage_key,
            'reason'     => class_exists('VES_Security_Event_Log') ? VES_Security_Event_Log::scrub(self::clean_text($reason, 240)) : self::clean_text($reason, 240),
            'created_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'resolved_at' => '',
        ];
        if (count($markers) > self::MAX_MARKERS) { $markers = array_slice($markers, -self::MAX_MARKERS); }
        update_option(self::OPTION_SETTLEMENT_REQUIRED, $markers, false);
        return true;
    }

    /** Operator resolution appends a resolved_at timestamp (no deletion). */
    public static function resolve_settlement_marker($usage_key, $note = '') {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return false; }
        $markers = function_exists('get_option') ? get_option(self::OPTION_SETTLEMENT_REQUIRED, []) : [];
        if (!is_array($markers)) { return false; }
        $found = false;
        foreach ($markers as $i => $m) {
            if ((string) ($m['usage_key'] ?? '') === (string) $usage_key && empty($m['resolved_at'])) {
                $markers[$i]['resolved_at'] = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
                $markers[$i]['resolution_note'] = self::clean_text($note, 200);
                $found = true;
            }
        }
        if ($found) { update_option(self::OPTION_SETTLEMENT_REQUIRED, $markers, false); }
        return $found;
    }

    /** Unresolved settlement_required markers. Read-only. */
    public static function open_settlement_markers() {
        $markers = function_exists('get_option') ? get_option(self::OPTION_SETTLEMENT_REQUIRED, []) : [];
        if (!is_array($markers)) { return []; }
        $open = [];
        foreach ($markers as $m) {
            if (is_array($m) && empty($m['resolved_at'])) { $open[] = $m; }
        }
        return $open;
    }

    /**
     * Readiness report: stale reserved rows + open settlement markers. Read-only.
     * Stale reserved = pending rows older than STALE_RESERVED_HOURS that the
     * reconciliation sweep has not yet voided/settled.
     */
    public static function settlement_health() {
        global $wpdb;
        $out = [
            'available' => true,
            'reserved_stale' => 0,
            'settlement_required' => count(self::open_settlement_markers()),
            'stale_hours' => self::STALE_RESERVED_HOURS,
            'healthy' => true,
        ];
        if (isset($wpdb) && is_object($wpdb) && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'table_name') && method_exists($wpdb, 'get_var')) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALE_RESERVED_HOURS * 3600);
            $stale = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . VES_Usage_Billing::table_name() . " WHERE status = 'pending' AND created_at < %s",
                $cutoff
            ));
            $out['reserved_stale'] = max(0, (int) $stale);
        }
        $out['healthy'] = $out['reserved_stale'] === 0 && $out['settlement_required'] === 0;
        return $out;
    }

    /** Readiness probe: taxonomy + mapping compiled in. */
    public static function semantics_active() {
        return self::canonical_state(['status' => 'pending']) === 'reserved'
            && self::canonical_state(['status' => 'posted']) === 'completed'
            && self::canonical_state(['status' => 'void']) === 'voided'
            && self::canonical_state(['status' => 'posted', 'context' => ['settlement_classification' => 'not_chargeable_zero_delivery']]) === 'not_chargeable'
            && self::canonical_state(['status' => 'something_unknown']) === 'settlement_required';
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function decode_context(array $event) {
        $context = $event['context'] ?? [];
        if (is_string($context) && $context !== '') {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }
        return is_array($context) ? $context : [];
    }

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }

    private static function clean_text($s, $max) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
