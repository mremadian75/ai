<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Query_Planner {
    private static function split_topic($topic) {
        $topic_raw = trim((string) $topic);
        $parts = preg_split('/[,;\|\/]+/u', $topic_raw);
        $clusters = [];
        $seen = [];
        foreach ((array) $parts as $part) {
            $part = trim(preg_replace('/\s+/', ' ', (string) $part));
            if ($part === '') { continue; }
            $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $clusters[] = $part;
        }
        if (empty($clusters) && $topic_raw !== '') {
            $clusters[] = $topic_raw;
        }
        return array_values(array_slice($clusters, 0, 8));
    }

    public static function build($request) {
        $request = function_exists('ves_trend_sanitize_request') ? ves_trend_sanitize_request($request) : (array) $request;

        if (class_exists('VES_Trend_Source_Planner')) {
            $planned = VES_Trend_Source_Planner::build($request);
            $payloads = [];
            foreach ((array) ($planned['payloads'] ?? []) as $source_key => $payload) {
                $source_key = sanitize_key((string) $source_key);
                $payload = is_array($payload) ? $payload : [];
                $payload['input'] = is_array($payload['input'] ?? null) ? $payload['input'] : [];
                $payload['source_family'] = sanitize_key((string) ($payload['source_family'] ?? 'market_context'));
                $payload['source_role'] = sanitize_key((string) ($payload['source_role'] ?? 'source'));
                $payload['platform'] = sanitize_key((string) ($payload['platform'] ?? $source_key));
                $payloads[$source_key] = class_exists('VES_Source_Adapter_Registry')
                    ? VES_Source_Adapter_Registry::normalize_trend_payload($source_key, $payload, array_merge($request, [
                        'expanded_queries' => $planned['expanded_queries'] ?? [],
                        'trend_type' => $request['trend_type'] ?? '',
                    ]))
                    : $payload;
                if (function_exists('ves_validate_actor_payload_contract')) {
                    $valid = ves_validate_actor_payload_contract(
                        $payloads[$source_key]['platform'] ?? $payload['platform'],
                        $payloads[$source_key]['actor_slug'] ?? $payload['actor_slug'] ?? '',
                        $payloads[$source_key]['input'] ?? []
                    );
                    if (is_wp_error($valid)) {
                        $payloads[$source_key]['adapter_validation_status'] = 'failed_contract';
                        $payloads[$source_key]['adapter_error'] = $valid->get_error_message();
                    }
                }
            }
            return [
                'request' => $request,
                'query_plan' => [
                    'mode' => $planned['mode'] ?? 'balanced',
                    'source_plan' => $planned['source_plan'] ?? [],
                    'expanded_queries' => $planned['expanded_queries'] ?? [],
                    'negative_terms' => $planned['negative_terms'] ?? [],
                    'expected_signal_types' => $planned['expected_signal_types'] ?? [],
                    'desired_final_trends' => $planned['desired_final_trends'] ?? 10,
                ],
                'payloads' => $payloads,
                'request_hash' => md5(wp_json_encode([$request, $payloads])),
            ];
        }

        // Legacy fallback preserved for older installs that load this class without the source planner.
        $clusters = self::split_topic($request['topic'] ?? '');
        $google_terms = !empty($clusters) ? $clusters : [sanitize_text_field((string) ($request['topic'] ?? ''))];
        $payloads = [
            'google_trends_interest' => [
                'label' => 'Google Trends Interest',
                'actor_slug' => class_exists('VES_Config') ? VES_Config::get_actor_slug('google_trends_interest') : 'apify/google-trends-scraper',
                'source_family' => 'search_demand',
                'source_role' => 'interest_baseline',
                'platform' => 'google_trends',
                'input' => [
                    'searchTerms' => array_values(array_filter($google_terms)),
                    'isMultiple' => count($google_terms) > 1,
                    'timeRange' => 'now 7-d',
                    'geo' => sanitize_text_field((string) ($request['country'] ?? 'ES')),
                    'maxItems' => 25,
                ],
            ],
        ];
        foreach ($payloads as $source_key => $payload) {
            if (class_exists('VES_Source_Adapter_Registry')) {
                $payloads[$source_key] = VES_Source_Adapter_Registry::normalize_trend_payload($source_key, $payload, $request);
            }
        }
        return [
            'request' => $request,
            'query_plan' => ['mode' => 'legacy_fallback', 'clusters' => $clusters],
            'payloads' => $payloads,
            'request_hash' => md5(wp_json_encode([$request, $payloads])),
        ];
    }
}
