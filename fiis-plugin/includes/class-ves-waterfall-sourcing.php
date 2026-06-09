<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Waterfall_Sourcing — Phase 3 cheap→expensive sourcing orchestrator.
 *
 * Tries a list of providers in order (cheapest/free first), stops on the first
 * successful result (unless collect_all=true), and records a provenance trail of
 * every attempt (provider, status, latency, cost tier, error). The framework makes
 * NO external calls itself — each provider supplies a callable that does the work.
 *
 * It is defensive: a provider that returns WP_Error, throws, or yields an empty
 * result is recorded as a failed/empty attempt and the waterfall falls through.
 * Raw payloads are never logged; only counts/status metadata.
 *
 * Provider shape:
 *   ['provider' => 'cache'|'existing_db'|'apify'|..., 'cost_tier' => 'free'|'cheap'|'paid',
 *    'callable' => function(array $context): array|WP_Error,  // returns ['items'=>[...]] or data
 *    'usage_event_id' => 0 ]  // optional, if the callable emitted a usage row
 */
final class VES_Waterfall_Sourcing {

    const STATUS_SKIPPED = 'skipped';
    const STATUS_SUCCESS = 'success';
    const STATUS_EMPTY   = 'empty';
    const STATUS_FAILED  = 'failed';

    /**
     * @param array $context  ['workspace_id','module','operation_type','entity','collect_all'=>bool]
     * @param array $providers list of provider specs (see class doc)
     * @return array {
     *   'ok' => bool, 'provider' => string (winning provider, '' if none),
     *   'result' => array (winning result, or merged results when collect_all),
     *   'results' => array (per-provider successful results when collect_all),
     *   'attempts' => array (provenance trail), 'workspace_id' => int
     * }  — never a WP_Error; failures are reflected in ok=false + attempts.
     */
    public static function run(array $context, array $providers): array {
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        $collect_all = !empty($context['collect_all']);
        $module = self::clean((string) ($context['module'] ?? ''), 80);
        $operation = self::clean((string) ($context['operation_type'] ?? 'source_enrichment'), 80);

        $out = [
            'ok' => false, 'provider' => '', 'result' => [], 'results' => [],
            'attempts' => [], 'workspace_id' => $workspace_id,
        ];

        foreach ($providers as $spec) {
            if (!is_array($spec)) { continue; }
            $provider = self::clean((string) ($spec['provider'] ?? 'unknown'), 40);
            $cost_tier = self::clean((string) ($spec['cost_tier'] ?? 'unknown'), 16);
            $callable = $spec['callable'] ?? null;

            if (!is_callable($callable)) {
                $out['attempts'][] = self::attempt($provider, self::STATUS_SKIPPED, 0, $cost_tier, 0, 'not_callable');
                continue;
            }

            $started = microtime(true);
            $status = self::STATUS_FAILED;
            $error_code = '';
            $result = null;
            try {
                $result = call_user_func($callable, $context);
                if (self::is_wp_error($result)) {
                    $status = self::STATUS_FAILED;
                    $error_code = self::clean((string) $result->get_error_code(), 80);
                    $result = null;
                } elseif (self::is_empty_result($result)) {
                    $status = self::STATUS_EMPTY;
                    $result = null;
                } else {
                    $status = self::STATUS_SUCCESS;
                }
            } catch (\Throwable $e) {
                // Recover from any provider exception as a failed attempt — never fatal.
                $status = self::STATUS_FAILED;
                $error_code = 'exception';
                $result = null;
            }
            $latency = (int) max(0, round((microtime(true) - $started) * 1000));

            $usage_event_id = max(0, (int) ($spec['usage_event_id'] ?? 0));
            $attempt = self::attempt($provider, $status, $latency, $cost_tier, $usage_event_id, $error_code);
            $out['attempts'][] = $attempt;

            if (class_exists('VES_Log')) {
                VES_Log::debug('waterfall_sourcing', 'attempt', [
                    'module' => $module, 'operation_type' => $operation,
                    'provider' => $provider, 'workspace_id' => $workspace_id,
                    'latency_ms' => $latency, 'error_code' => $error_code,
                ]);
            }

            if ($status === self::STATUS_SUCCESS) {
                if ($out['provider'] === '') { $out['provider'] = $provider; $out['result'] = is_array($result) ? $result : ['result' => $result]; }
                $out['results'][$provider] = is_array($result) ? $result : ['result' => $result];
                $out['ok'] = true;
                if (!$collect_all) { break; }
            }
        }

        return $out;
    }

    private static function attempt(string $provider, string $status, int $latency_ms, string $cost_tier, int $usage_event_id, string $error_code): array {
        return [
            'provider' => $provider,
            'status' => $status,
            'latency_ms' => $latency_ms,
            'cost_tier' => $cost_tier,
            'usage_event_id' => $usage_event_id,
            'error_code' => $error_code,
        ];
    }

    /** Empty = null, [], or an array with no items/data/result payload. */
    private static function is_empty_result($result): bool {
        if ($result === null) { return true; }
        if (is_array($result)) {
            if (empty($result)) { return true; }
            if (array_key_exists('items', $result)) { return empty($result['items']); }
            if (array_key_exists('data', $result)) { return empty($result['data']); }
            if (array_key_exists('result', $result)) { return empty($result['result']); }
            return false;
        }
        return empty($result);
    }

    private static function is_wp_error($thing): bool {
        if (function_exists('is_wp_error')) { return is_wp_error($thing); }
        return $thing instanceof WP_Error;
    }

    private static function clean(string $v, int $max): string {
        $v = function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v));
        return substr($v, 0, $max);
    }
}
