<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical usage facade for run-linked, idempotent usage events.
 *
 * This does not replace VES_Usage_Billing or VES_AI_Usage_Tracker. It routes
 * zero-cost/manual telemetry through the billing ledger when available and falls
 * back to AI telemetry in test/lightweight contexts. Paid reservation/settlement
 * still stays in the existing billing layer.
 */
final class VES_Usage_Service {

    /** @var array<string,int> Process-local idempotency cache for fallback/test contexts. */
    private static $recorded_keys = [];

    /** @return int|WP_Error */
    public static function record_zero_cost_event(array $args) {
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        $run_id       = max(0, (int) ($args['run_id'] ?? 0));
        $target_type  = self::clean_key((string) ($args['target_type'] ?? ''), 40);
        $target_id    = (string) ($args['target_id'] ?? '');
        $event_type   = self::clean_key((string) ($args['event_type'] ?? 'manual_zero_cost'), 40);
        $user_id      = max(0, (int) ($args['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0)));
        if ($workspace_id <= 0) { return self::err('ves_usage_missing_workspace', 'workspace_id is required.'); }
        if ($run_id <= 0) { return self::err('ves_usage_missing_run', 'run_id is required.'); }
        if ($target_type === '' || $target_id === '') { return self::err('ves_usage_missing_target', 'target_type and target_id are required.'); }
        if ($user_id <= 0) { $user_id = 0; }

        $usage_key = self::clean_text((string) ($args['idempotency_key'] ?? ''), 100);
        if ($usage_key === '') {
            $usage_key = 'fi:zero:' . $workspace_id . ':' . $run_id . ':' . $target_type . ':' . md5((string) $target_id . '|' . $event_type);
        }
        if (isset(self::$recorded_keys[$usage_key])) {
            return (int) self::$recorded_keys[$usage_key];
        }
        $idempotency_transient = 'ves_usage_zero_' . md5($usage_key);
        if (function_exists('get_transient')) {
            $existing_id = (int) get_transient($idempotency_transient);
            if ($existing_id > 0) { return $existing_id; }
        }

        $context = is_array($args['context'] ?? null) ? $args['context'] : [];
        $context = array_merge($context, [
            'workspace_id' => $workspace_id,
            'run_id' => (string) $run_id,
            'target_type' => $target_type,
            'target_id' => (string) $target_id,
            'module' => self::clean_key((string) ($args['module'] ?? 'future_island'), 80),
            'provider' => 'manual',
            'model' => 'operator_review',
            'charged_credits' => 0,
            'reserved_credits' => 0,
            'run_status' => self::clean_key((string) ($args['status'] ?? 'completed'), 40),
            'metered_zero_cost' => 1,
        ]);

        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event') && $user_id > 0) {
            $id = (int) VES_Usage_Billing::record_meter_event(
                $user_id,
                $event_type,
                $target_type,
                (string) $target_id,
                self::clean_text((string) ($args['message'] ?? 'Future Island zero-cost usage event'), 190),
                $context,
                $usage_key
            );
            if ($id > 0) { self::$recorded_keys[$usage_key] = $id; self::remember_usage_id($idempotency_transient, $id); self::add_usage_edge($workspace_id, $run_id, $id, $target_type, $target_id, $event_type); return $id; }
        }

        if (class_exists('VES_AI_Usage_Tracker') && method_exists('VES_AI_Usage_Tracker', 'record')) {
            $id = (int) VES_AI_Usage_Tracker::record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'module' => (string) ($context['module'] ?? 'future_island'),
                'operation_type' => $event_type,
                'run_id' => (string) $run_id,
                'provider' => 'manual',
                'model' => 'operator_review',
                'status' => 'completed',
                'estimated_provider_cost' => 0,
                'actual_provider_cost' => 0,
                'estimated_credits' => 0,
                'credits_charged' => 0,
                'pricing_source' => 'unavailable',
                'metadata' => $context,
            ]);
            if ($id > 0) { self::$recorded_keys[$usage_key] = $id; self::remember_usage_id($idempotency_transient, $id); self::add_usage_edge($workspace_id, $run_id, $id, $target_type, $target_id, $event_type); return $id; }
        }

        return self::err('ves_usage_record_failed', 'Usage event could not be recorded.');
    }

    public static function reserve_provider_ingestion(array $args) {
        $args['event_type'] = $args['event_type'] ?? 'provider_ingest_reserved';
        $args['status'] = 'reserved';
        return self::record_zero_cost_event($args);
    }

    public static function settle_provider_ingestion(array $args) {
        $args['event_type'] = $args['event_type'] ?? 'provider_ingest_zero_cost';
        $args['status'] = 'settled';
        return self::record_zero_cost_event($args);
    }

    public static function void_provider_ingestion(array $args) {
        $args['event_type'] = $args['event_type'] ?? 'provider_ingest_voided';
        $args['status'] = 'voided';
        return self::record_zero_cost_event($args);
    }

    private static function remember_usage_id(string $key, int $id): void {
        if ($id <= 0 || !function_exists('set_transient')) { return; }
        $ttl = 7 * (defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400);
        set_transient($key, $id, $ttl);
    }

    private static function add_usage_edge(int $workspace_id, int $run_id, int $usage_event_id, string $target_type, string $target_id, string $event_type): void {
        if ($workspace_id <= 0 || $run_id <= 0 || $usage_event_id <= 0) { return; }
        if (!class_exists('VES_Decision_Edge_Service')) { return; }
        VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'run', $run_id, 'usage_event', $usage_event_id, 'spent_usage', [
            'metadata' => [
                'event_type' => $event_type,
                'target_type' => $target_type,
                'target_id' => (string) $target_id,
                'zero_cost' => true,
            ],
        ]);
    }

    private static function clean_key(string $s, int $max): string {
        $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s));
        return substr($s, 0, $max);
    }
    private static function clean_text(string $s, int $max): string {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s));
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
