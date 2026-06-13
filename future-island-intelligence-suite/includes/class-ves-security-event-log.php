<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Security_Event_Log — Phase 9B.4 append-only security/audit event log.
 *
 * Records guardrail trips: blocked provider dispatch, blocked lifecycle
 * transitions, workspace mismatches, nonce/capability failures, redaction
 * counts, unsafe preview attempts, invalid CLI args. Storage is a bounded,
 * append-only option ring (oldest dropped past MAX_EVENTS) — no schema change.
 * Every payload is scrubbed BEFORE storage; secret VALUES are never stored,
 * only the fact that something was redacted. Read-only surfaces (RC page,
 * readiness) may list recent events. There is no edit/delete API by design.
 * PHP 7.4 compatible.
 */
final class VES_Security_Event_Log {

    const OPTION_KEY = 'ves_security_event_log';
    const MAX_EVENTS = 200;
    const TYPES = [
        'provider_dispatch_blocked', 'lifecycle_transition_blocked', 'workspace_mismatch',
        'nonce_failed', 'capability_failed', 'secret_redacted', 'unsafe_preview_attempt',
        'invalid_cli_args', 'allowlist_unavailable', 'charge_ceiling_blocked',
        'unsafe_bypass_attempt', 'ledger_anomaly', 'other',
    ];

    /** Append one scrubbed event. Returns true when stored. */
    public static function record($type, $detail, array $context = []) {
        if (!function_exists('get_option') || !function_exists('update_option')) { return false; }
        $type = self::clean_key($type);
        if (!in_array($type, self::TYPES, true)) { $type = 'other'; }
        $event = [
            'type'       => $type,
            'detail'     => self::scrub(self::clean_text($detail, 300)),
            'context'    => self::scrub_context($context),
            'user_id'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'created_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ];
        $log = get_option(self::OPTION_KEY, []);
        if (!is_array($log)) { $log = []; }
        $log[] = $event;
        if (count($log) > self::MAX_EVENTS) { $log = array_slice($log, -self::MAX_EVENTS); }
        update_option(self::OPTION_KEY, $log, false);
        return true;
    }

    /** Most recent events, newest first, bounded. Read-only. */
    public static function recent($limit = 20) {
        $log = function_exists('get_option') ? get_option(self::OPTION_KEY, []) : [];
        if (!is_array($log)) { return []; }
        $limit = max(1, min(self::MAX_EVENTS, (int) $limit));
        return array_reverse(array_slice($log, -$limit));
    }

    /** Counts by type for diagnostics. Read-only. */
    public static function summary() {
        $log = function_exists('get_option') ? get_option(self::OPTION_KEY, []) : [];
        if (!is_array($log)) { return ['total' => 0, 'by_type' => [], 'available' => true]; }
        $by = [];
        foreach ($log as $e) { $t = self::clean_key($e['type'] ?? 'other'); $by[$t] = ($by[$t] ?? 0) + 1; }
        return ['total' => count($log), 'by_type' => $by, 'available' => true];
    }

    // ── scrubbing ──────────────────────────────────────────────────────────────

    /** Replace anything secret-shaped; never store the secret itself. */
    public static function scrub($text) {
        $text = (string) $text;
        $patterns = [
            '/apify_api_[A-Za-z0-9]+/i', '/sk-[A-Za-z0-9_\-]{8,}/i', '/sk_live_[A-Za-z0-9]{8,}/i',
            '/whsec_[A-Za-z0-9]{8,}/i', '/AIza[A-Za-z0-9_\-]{20,}/', '/Bearer\s+[A-Za-z0-9_\-.]+/i',
            '/[A-Za-z0-9_\-]{40,}/',
        ];
        $count = 0;
        foreach ($patterns as $p) {
            $text = preg_replace($p, '[redacted]', $text, -1, $c);
            $count += (int) $c;
        }
        if ($count > 0) { $text .= ' (redactions: ' . $count . ')'; }
        return $text;
    }

    private static function scrub_context(array $context) {
        $forbidden = ['token', 'api_key', 'apikey', 'authorization', 'bearer', 'secret', 'password', 'raw_prompt', 'prompt_text', 'raw_response', 'provider_response', 'cookie', 'nonce_value'];
        $out = []; $i = 0;
        foreach ($context as $k => $v) {
            if ($i++ >= 12) { break; } // bounded
            $key = self::clean_key($k);
            if (in_array($key, $forbidden, true)) { $out[$key] = '[redacted]'; continue; }
            if (is_scalar($v) || $v === null) { $out[$key] = self::scrub(self::clean_text((string) $v, 160)); }
            elseif (is_array($v)) { $out[$key] = '[array:' . count($v) . ']'; }
            else { $out[$key] = '[object]'; }
        }
        return $out;
    }

    private static function clean_key($s) {
        return function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
    }

    private static function clean_text($s, $max = 300) {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags((string) $s));
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
