<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Legacy_Trend_Report_Normalizer {
    public static function legacy_key_for_memory_id($memory_id) {
        return 'legacy-report-' . (int) $memory_id;
    }

    public static function legacy_bundle_id($memory_id) {
        return 'legacy-trend-report-' . (int) $memory_id;
    }

    private static function sanitize_trend_item($trend, $source_summary, $confidence_mode) {
        if (!is_array($trend)) {
            return null;
        }
        $name = sanitize_text_field((string) ($trend['trend_name'] ?? $trend['title'] ?? ''));
        if ($name === '') {
            return null;
        }
        $fit = isset($trend['fit_score']) ? (float) $trend['fit_score'] : 0.0;
        $evidence = isset($trend['evidence_score']) ? (float) $trend['evidence_score'] : 0.0;
        if ($evidence <= 0 && !empty($trend['evidence_strength'])) {
            $tmp = VES_Trend_Scoring::build_insights_from_analysis([
                'prioritized_trends' => [[
                    'trend_name' => $name,
                    'fit_score' => $fit,
                    'evidence_strength' => $trend['evidence_strength'],
                    'evidence' => (array) ($trend['evidence'] ?? []),
                ]],
            ], $source_summary, $confidence_mode);
            if (!empty($tmp[0]['evidence_score'])) {
                $evidence = (float) $tmp[0]['evidence_score'];
            }
        }
        $confidence = isset($trend['confidence_score']) ? (float) $trend['confidence_score'] : 0.0;
        $actionability = isset($trend['actionability_score']) ? (float) $trend['actionability_score'] : 0.0;
        $priority = isset($trend['priority_score']) ? (float) $trend['priority_score'] : 0.0;
        if ($confidence <= 0 || $actionability <= 0 || $priority <= 0) {
            $generated = VES_Trend_Scoring::build_insights_from_analysis([
                'prioritized_trends' => [[
                    'trend_name' => $name,
                    'fit_score' => $fit,
                    'evidence_strength' => $trend['evidence_strength'] ?? $evidence,
                    'evidence' => (array) ($trend['evidence'] ?? []),
                    'growth_drivers' => (array) ($trend['growth_drivers'] ?? []),
                    'primary_channel' => (string) ($trend['primary_channel'] ?? ''),
                    'signal_type' => (string) ($trend['signal_type'] ?? ''),
                    'fit_reason' => (string) ($trend['fit_reason'] ?? ''),
                    'audience_or_context' => (string) ($trend['audience_or_context'] ?? ''),
                    'activation_window' => (string) ($trend['activation_window'] ?? ''),
                    'recommended_action' => (string) ($trend['recommended_action'] ?? ''),
                    'content_actions' => (array) ($trend['content_actions'] ?? []),
                    'counterargument' => (string) ($trend['counterargument'] ?? ''),
                    'risk_note' => (string) ($trend['risk_note'] ?? ''),
                ]],
            ], $source_summary, $confidence_mode);
            if (!empty($generated[0])) {
                $confidence = $confidence > 0 ? $confidence : (float) ($generated[0]['confidence_score'] ?? 0);
                $actionability = $actionability > 0 ? $actionability : (float) ($generated[0]['actionability_score'] ?? 0);
                $priority = $priority > 0 ? $priority : (float) ($generated[0]['priority_score'] ?? 0);
                if ($evidence <= 0) {
                    $evidence = (float) ($generated[0]['evidence_score'] ?? 0);
                }
            }
        }
        return [
            'insight_type' => sanitize_key((string) ($trend['insight_type'] ?? 'opportunity')),
            'status' => 'archived',
            'trend_name' => $name,
            'fit_score' => $fit,
            'evidence_score' => $evidence,
            'confidence_score' => $confidence,
            'actionability_score' => $actionability,
            'priority_score' => $priority,
            'source_count' => (int) ($source_summary['total_sources'] ?? 0),
            'usable_source_count' => (int) ($source_summary['usable_sources'] ?? 0),
            'evidence_json' => is_array($trend['evidence_json'] ?? null) ? $trend['evidence_json'] : [
                'evidence' => array_values(array_slice((array) ($trend['evidence'] ?? []), 0, 8)),
                'growth_drivers' => array_values(array_slice((array) ($trend['growth_drivers'] ?? []), 0, 6)),
                'primary_channel' => sanitize_text_field((string) ($trend['primary_channel'] ?? '')),
                'signal_type' => sanitize_text_field((string) ($trend['signal_type'] ?? '')),
            ],
            'observed_signal_json' => is_array($trend['observed_signal_json'] ?? null) ? $trend['observed_signal_json'] : [
                'fit_reason' => sanitize_text_field((string) ($trend['fit_reason'] ?? '')),
                'audience_or_context' => sanitize_text_field((string) ($trend['audience_or_context'] ?? '')),
                'activation_window' => sanitize_text_field((string) ($trend['activation_window'] ?? '')),
            ],
            'interpretation_json' => is_array($trend['interpretation_json'] ?? null) ? $trend['interpretation_json'] : [
                'recommended_action' => sanitize_text_field((string) ($trend['recommended_action'] ?? '')),
                'content_actions' => array_values(array_slice((array) ($trend['content_actions'] ?? []), 0, 8)),
                'counterargument' => sanitize_text_field((string) ($trend['counterargument'] ?? '')),
            ],
            'risk_json' => is_array($trend['risk_json'] ?? null) ? $trend['risk_json'] : [
                'risk_note' => sanitize_text_field((string) ($trend['risk_note'] ?? '')),
            ],
        ];
    }

    public static function normalize_report_row($row, $args = []) {
        $memory_id = (int) ($row['id'] ?? 0);
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
        $created_at = sanitize_text_field((string) ($row['created_at'] ?? current_time('mysql', true)));
        $user_id = !empty($row['user_id']) ? (int) $row['user_id'] : 0;
        $session_key = sanitize_key((string) ($row['session_key'] ?? ($user_id > 0 ? 'user:' . $user_id : 'legacy')));
        $structured = is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : [];
        $prioritized = is_array($structured['prioritized_trends'] ?? null) ? $structured['prioritized_trends'] : [];

        $errors = [];
        if ($memory_id <= 0) {
            $errors[] = 'missing_memory_id';
        }
        if ($user_id <= 0 && $session_key === '') {
            $errors[] = 'ownerless';
        }
        if (empty($payload)) {
            $errors[] = 'empty_payload';
        }
        if (empty($prioritized)) {
            $errors[] = 'missing_prioritized_trends';
        }

        $source_summary = [
            'total_sources' => (int) ($payload['total_sources'] ?? count((array) ($payload['sources'] ?? []))),
            'usable_sources' => (int) ($payload['usable_sources'] ?? $payload['successful_sources'] ?? 0),
            'partial_sources' => (int) ($payload['partial_sources'] ?? 0),
            'failed_sources' => (int) ($payload['failed_sources'] ?? 0),
            'metric_average' => 0.0,
        ];
        if ($source_summary['total_sources'] <= 0) {
            $source_summary['total_sources'] = count((array) ($payload['sources'] ?? []));
        }
        $confidence_mode = sanitize_key((string) ($payload['confidence_mode'] ?? ''));
        if ($confidence_mode === '' && class_exists('VES_Trend_Scoring')) {
            $confidence_mode = VES_Trend_Scoring::resolve_run_mode($source_summary);
        }
        if ($confidence_mode === '') {
            $confidence_mode = 'exploratory';
        }
        $run_status = sanitize_key((string) ($payload['run_status'] ?? ''));
        if ($run_status === '' && class_exists('VES_Trend_Scoring')) {
            $run_status = VES_Trend_Scoring::resolve_run_status($source_summary);
        }
        if ($run_status === '') {
            $run_status = !empty($prioritized) ? 'partial' : 'failed';
        }

        $request = is_array($payload['requested'] ?? null) ? $payload['requested'] : [];
        $request = [
            'topic' => sanitize_text_field((string) ($request['topic'] ?? ($summary['title'] ?? $row['report_title'] ?? ''))),
            'country' => sanitize_text_field((string) ($request['country'] ?? ($row['country_code'] ?? 'None'))),
            'duration' => sanitize_key((string) ($request['duration'] ?? '7d')),
            'reason' => sanitize_textarea_field((string) ($request['reason'] ?? ($row['targets'] ?? ''))),
        ];
        $request_hash = hash('sha256', wp_json_encode([
            'topic' => mb_strtolower((string) $request['topic']),
            'country' => (string) $request['country'],
            'duration' => (string) $request['duration'],
            'legacy' => $memory_id,
        ]));
        $insights = [];
        foreach ($prioritized as $trend) {
            $mapped = self::sanitize_trend_item($trend, $source_summary, $confidence_mode);
            if ($mapped) {
                $insights[] = $mapped;
            }
        }
        if (empty($insights)) {
            $errors[] = 'no_mappable_insights';
        }
        $eligible = empty($errors) || ($args['strict'] ?? false ? false : !in_array('ownerless', $errors, true) && !in_array('missing_memory_id', $errors, true) && !in_array('empty_payload', $errors, true));
        $risk = empty($errors) ? 'safe' : ((count($errors) >= 2 || in_array('ownerless', $errors, true)) ? 'review-needed' : 'safe');
        if (in_array('empty_payload', $errors, true) || in_array('missing_memory_id', $errors, true)) {
            $risk = 'malformed';
        }
        return [
            'memory_id' => $memory_id,
            'legacy_key' => self::legacy_key_for_memory_id($memory_id),
            'bundle_id' => self::legacy_bundle_id($memory_id),
            'user_id' => $user_id,
            'session_key' => $session_key,
            'created_at' => $created_at,
            'request' => $request,
            'query_plan' => ['legacy' => true, 'source' => 'temp_memory', 'sample_signals' => array_values(array_slice((array) ($summary['signals'] ?? []), 0, 8))],
            'sources' => is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
            'source_summary' => $source_summary,
            'final_payload' => $payload,
            'run_status' => $run_status,
            'confidence_mode' => $confidence_mode,
            'requested_topic' => sanitize_text_field((string) $request['topic']),
            'country_code' => sanitize_text_field((string) $request['country']),
            'duration_code' => sanitize_key((string) $request['duration']),
            'request_hash' => $request_hash,
            'insights' => $insights,
            'estimated_insight_count' => count($insights),
            'eligible' => !empty($insights) && !in_array('ownerless', $errors, true) && !in_array('empty_payload', $errors, true),
            'errors' => $errors,
            'risk_bucket' => $risk,
            'summary' => $summary,
        ];
    }
}
