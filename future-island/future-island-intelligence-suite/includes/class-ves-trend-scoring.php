<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Scoring {
    private static function evidence_to_score($evidence_strength) {
        $text = strtolower((string) $evidence_strength);
        if ($text === '') return 4.0;
        if (strpos($text, 'alta') !== false || strpos($text, 'strong') !== false) return 8.0;
        if (strpos($text, 'media') !== false || strpos($text, 'moderate') !== false) return 6.0;
        if (strpos($text, 'baja') !== false || strpos($text, 'weak') !== false) return 3.0;
        if (preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
            return max(0.0, min(10.0, (float) $m[1]));
        }
        return 5.0;
    }

    public static function resolve_run_mode($source_summary) {
        $usable = (int) ($source_summary['usable_sources'] ?? 0);
        $partial = (int) ($source_summary['partial_sources'] ?? 0);
        $metric = (float) ($source_summary['metric_average'] ?? 0);
        $direct_matches = (int) ($source_summary['direct_matches'] ?? 0);
        $failed = (int) ($source_summary['failed_sources'] ?? 0);
        $only_google_trends = !empty($source_summary['only_google_trends_usable']);
        if ($usable < 2 || $direct_matches <= 0 || $only_google_trends || $failed > $usable || $metric < 0.50) {
            return ($usable + $partial) >= 1 ? 'exploratory' : 'insufficient_evidence';
        }
        if ($usable >= 2 && $metric >= 0.55 && $direct_matches > 0) {
            return 'validated';
        }
        if (($usable + $partial) >= 2 && $metric >= 0.25) {
            return 'directional';
        }
        if (($usable + $partial) >= 1) {
            return 'exploratory';
        }
        return 'insufficient_evidence';
    }

    public static function resolve_run_status($source_summary) {
        $usable = (int) ($source_summary['usable_sources'] ?? 0);
        $usable_rows = (int) ($source_summary['usable_rows'] ?? ($source_summary['usable_records'] ?? 0));
        $partial = (int) ($source_summary['partial_sources'] ?? 0);
        $failed = (int) ($source_summary['failed_sources'] ?? 0);
        $providers_succeeded = (int) ($source_summary['providers_succeeded'] ?? 0);
        $raw_rows = (int) ($source_summary['raw_rows'] ?? 0);
        $normalized_rows = (int) ($source_summary['normalized_rows'] ?? 0);
        $adjacent = (int) ($source_summary['adjacent_evidence'] ?? ($source_summary['ambient_signals'] ?? 0));
        if ($usable > 0 && $failed === 0 && $partial === 0) {
            return 'completed';
        }
        if ($usable > 0 || $usable_rows > 0 || $partial > 0) {
            return 'partial';
        }
        if ($providers_succeeded > 0 && ($raw_rows > 0 || $normalized_rows > 0 || $adjacent > 0)) {
            return 'partial';
        }
        return 'failed';
    }

    public static function build_insights_from_analysis($analysis_structured, $source_summary, $confidence_mode) {
        $trends = is_array($analysis_structured['prioritized_trends'] ?? null) ? $analysis_structured['prioritized_trends'] : [];
        $insights = [];
        foreach ($trends as $trend) {
            if (!is_array($trend)) {
                continue;
            }
            $trend_name = sanitize_text_field((string) ($trend['trend_name'] ?? ''));
            if (class_exists('VES_Trend_Label_Guard') && !VES_Trend_Label_Guard::is_safe_label($trend_name, 'trend_name')) { continue; }
            $fit = isset($trend['fit_score']) ? (float) $trend['fit_score'] : 0.0;
            $evidence = self::evidence_to_score($trend['evidence_strength'] ?? '');
            $base_confidence = ($confidence_mode === 'validated') ? 8.0 : (($confidence_mode === 'directional') ? 6.0 : (($confidence_mode === 'exploratory') ? 4.0 : 2.0));
            $confidence = round(min(10, max(1, ($base_confidence + $evidence + $fit) / 3)), 1);
            $actionability = round(min(10, max(1, ($fit * 0.65) + ($evidence * 0.35))), 1);
            $priority = round(min(10, max(1, ($confidence * 0.45) + ($actionability * 0.55))), 1);
            $insights[] = [
                'insight_type' => 'opportunity',
                'status' => ($confidence_mode === 'validated') ? 'approved' : 'under_review',
                'trend_name' => $trend_name !== '' ? $trend_name : 'Trend',
                'fit_score' => $fit,
                'evidence_score' => $evidence,
                'confidence_score' => $confidence,
                'actionability_score' => $actionability,
                'priority_score' => $priority,
                'source_count' => (int) ($source_summary['total_sources'] ?? 0),
                'usable_source_count' => (int) ($source_summary['usable_sources'] ?? 0),
                'evidence_json' => [
                    'evidence' => array_values(array_slice((array) ($trend['evidence'] ?? []), 0, 8)),
                    'growth_drivers' => array_values(array_slice((array) ($trend['growth_drivers'] ?? []), 0, 6)),
                    'primary_channel' => sanitize_text_field((string) ($trend['primary_channel'] ?? '')),
                    'signal_type' => sanitize_text_field((string) ($trend['signal_type'] ?? '')),
                ],
                'observed_signal_json' => [
                    'fit_reason' => sanitize_text_field((string) ($trend['fit_reason'] ?? '')),
                    'audience_or_context' => sanitize_text_field((string) ($trend['audience_or_context'] ?? '')),
                    'activation_window' => sanitize_text_field((string) ($trend['activation_window'] ?? '')),
                ],
                'interpretation_json' => [
                    'recommended_action' => sanitize_text_field((string) ($trend['recommended_action'] ?? '')),
                    'content_actions' => array_values(array_slice((array) ($trend['content_actions'] ?? []), 0, 8)),
                    'counterargument' => sanitize_text_field((string) ($trend['counterargument'] ?? '')),
                ],
                'risk_json' => [
                    'risk_note' => sanitize_text_field((string) ($trend['risk_note'] ?? '')),
                ],
            ];
        }
        return $insights;
    }
}
