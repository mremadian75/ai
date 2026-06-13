<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Log — central, debug-gated structured logger (Phase 0.6).
 *
 * Normalizes the cost-spine metadata fields so every subsystem logs the same
 * shape, and enforces three hard rules:
 *   1. Logging only happens when debug is enabled (WP_DEBUG, the
 *      `ves_debug_logging` option, or the `ves_log_enabled` filter).
 *   2. API keys / secrets are always scrubbed.
 *   3. Raw prompts and raw model responses are NEVER logged (the known
 *      metadata whitelist simply has no field for them, and any stray
 *      prompt/response keys are dropped).
 *
 * Sinks: a compact single-line JSON to error_log (WP-native), and — when a
 * NUMERIC run_id is present — the existing plugin log table via
 * VES_Run_Log_Service (used carefully, never inventing a run id).
 */
final class VES_Log {

    /** The only metadata keys that are persisted, in this order. */
    const META_KEYS = [
        'workspace_id', 'module', 'run_id', 'operation_type', 'provider', 'model',
        'latency_ms', 'input_tokens', 'output_tokens',
        'credits_estimated', 'credits_charged', 'error_code',
    ];

    public static function debug_enabled(): bool {
        $enabled = (defined('WP_DEBUG') && WP_DEBUG);
        if (!$enabled && function_exists('get_option')) {
            $enabled = (bool) get_option('ves_debug_logging', false);
        }
        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('ves_log_enabled', $enabled);
        }
        return $enabled;
    }

    public static function debug($component, $message, array $meta = []): void { self::event('debug', $component, $message, $meta); }
    public static function info($component, $message, array $meta = []): void  { self::event('info', $component, $message, $meta); }
    public static function warn($component, $message, array $meta = []): void  { self::event('warn', $component, $message, $meta); }
    public static function error($component, $message, array $meta = []): void { self::event('error', $component, $message, $meta); }

    /**
     * Emit a normalized log event. No-op unless debug is enabled. Never throws.
     */
    public static function event(string $level, string $component, string $message, array $meta = []): void {
        try {
            if (!self::debug_enabled()) { return; }

            $level = preg_replace('/[^a-z]/', '', strtolower($level)) ?: 'info';
            $component = self::scrub(self::flatten_short($component, 60));
            $message = self::scrub(self::flatten_short($message, 300));
            $normalized = self::normalize_meta($meta);

            // Sink 1: WP-native error_log, compact JSON, secrets scrubbed.
            if (function_exists('error_log')) {
                $line = '[VES][' . $level . '][' . $component . '] ' . $message . ' ' . self::encode($normalized);
                error_log(self::scrub($line));
            }

            // Sink 2: plugin run-log table — only when a real numeric run id exists.
            $numeric_run = isset($meta['run_id']) && is_numeric($meta['run_id']) ? (int) $meta['run_id'] : 0;
            if ($numeric_run > 0 && class_exists('VES_Run_Log_Service')
                && method_exists('VES_Run_Log_Service', $level === 'debug' ? 'debug' : ($level === 'warn' ? 'warn' : ($level === 'error' ? 'error' : 'info')))) {
                $method = in_array($level, ['debug', 'warn', 'error'], true) ? $level : 'info';
                VES_Run_Log_Service::$method($numeric_run, $component, $message, $normalized);
            }
        } catch (\Throwable $e) {
            // Logging must never break a request.
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Whitelist + sanitize metadata to the known cost-spine fields only. */
    private static function normalize_meta(array $meta): array {
        $out = [];
        foreach (self::META_KEYS as $key) {
            if (!array_key_exists($key, $meta)) { continue; }
            $value = $meta[$key];
            switch ($key) {
                case 'workspace_id':
                case 'latency_ms':
                case 'input_tokens':
                case 'output_tokens':
                    $out[$key] = max(0, (int) $value);
                    break;
                case 'credits_estimated':
                case 'credits_charged':
                    $out[$key] = round((float) $value, 4);
                    break;
                default:
                    if (is_scalar($value) || $value === null) {
                        $out[$key] = self::scrub(self::flatten_short((string) $value, 120));
                    }
            }
        }
        return $out;
    }

    private static function flatten_short($value, int $max): string {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/\s+/', ' ', trim($value));
        return substr((string) $value, 0, $max);
    }

    private static function encode(array $data): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        return is_string($json) ? $json : '{}';
    }

    /** Reuse the tracker's secret scrubber when available; otherwise inline the same patterns. */
    private static function scrub(string $value): string {
        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'scrub_secrets')) {
            return VES_AI_Usage_Tracker::scrub_secrets($value);
        }
        $patterns = [
            '/sk-[A-Za-z0-9_\-]{8,}/',
            '/sk_live_[A-Za-z0-9]{8,}/',
            '/whsec_[A-Za-z0-9]{8,}/',
            '/apify_api_[A-Za-z0-9]{8,}/',
            '/AIza[A-Za-z0-9_\-]{20,}/',
            '/Bearer\s+[A-Za-z0-9_\-.]+/i',
        ];
        return (string) preg_replace($patterns, '[redacted]', $value);
    }
}
