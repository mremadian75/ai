<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit_Source_Evaluator {
    public static function summarize($sources) {
        $summary = [
            'usable_sources' => 0,
            'partial_sources' => 0,
            'failed_sources' => 0,
            'pending_sources' => 0,
            'total_sources' => 0,
            'usable_ratio' => 0,
            'partial_ratio' => 0,
            'failed_ratio' => 0,
            'source_family_coverage' => [],
            'family_summary' => [],
            'competitor_total' => 0,
            'competitor_usable' => 0,
            'competitor_partial' => 0,
            'competitor_failed' => 0,
            'competitor_missing_ratio' => 0,
            'competitor_coverage_status' => 'none',
            'owned_social_usable' => 0,
            'earned_social_usable' => 0,
            'social_usable_sources' => 0,
            'social_item_count' => 0,
            'normalized_evidence_diversity' => 0,
            'only_internal_or_search_evidence' => false,
        ];
        foreach ((array) $sources as $key => $source) {
            if (!is_array($source)) { continue; }
            $summary['total_sources']++;
            $family = class_exists('VES_Brand_Audit_Scoring') ? VES_Brand_Audit_Scoring::source_family($key, $source) : sanitize_key((string) ($source['source_type'] ?? 'other'));
            if (!isset($summary['family_summary'][$family])) {
                $summary['family_summary'][$family] = ['total' => 0, 'usable' => 0, 'partial' => 0, 'failed' => 0, 'pending' => 0, 'items' => 0];
            }
            $status = strtoupper((string) ($source['status'] ?? ''));
            $usability = sanitize_key((string) ($source['analytical_usability_status'] ?? ''));
            $item_count = max(0, (int) ($source['item_count'] ?? 0));
            $bucket = self::bucket($status, $usability);
            $summary['family_summary'][$family]['total']++;
            $summary['family_summary'][$family]['items'] += $item_count;
            $summary['family_summary'][$family][$bucket] = (int) ($summary['family_summary'][$family][$bucket] ?? 0) + 1;
            $summary[$bucket . '_sources'] = (int) ($summary[$bucket . '_sources'] ?? 0) + 1;
            if ($bucket === 'usable') { $summary['source_family_coverage'][$family] = true; }
            $role = sanitize_key((string) ($source['entity_role'] ?? ''));
            if ($role === 'competitor' || strpos((string) $key, 'competitor') !== false) {
                $summary['competitor_total']++;
                if ($bucket === 'usable') { $summary['competitor_usable']++; }
                elseif ($bucket === 'failed') { $summary['competitor_failed']++; }
                else { $summary['competitor_partial']++; }
            }
            if ($family === 'social') {
                $summary['social_item_count'] += $item_count;
                if ($bucket === 'usable') { $summary['social_usable_sources']++; }
                if ($bucket === 'usable' && $role === 'brand') { $summary['owned_social_usable']++; }
                if ($bucket === 'usable' && $role !== 'brand' && $role !== 'competitor') { $summary['earned_social_usable']++; }
            }
        }
        $total = max(1, (int) $summary['total_sources']);
        $summary['usable_ratio'] = round($summary['usable_sources'] / $total, 4);
        $summary['partial_ratio'] = round($summary['partial_sources'] / $total, 4);
        $summary['failed_ratio'] = round($summary['failed_sources'] / $total, 4);
        $summary['source_family_coverage'] = array_keys(array_filter($summary['source_family_coverage']));
        $summary['normalized_evidence_diversity'] = count($summary['source_family_coverage']);
        if ($summary['competitor_total'] > 0) {
            $missing = $summary['competitor_partial'] + $summary['competitor_failed'];
            $summary['competitor_missing_ratio'] = round($missing / max(1, $summary['competitor_total']), 4);
            $summary['competitor_coverage_status'] = $summary['competitor_usable'] > 0 && $summary['competitor_missing_ratio'] < 0.5 ? 'usable' : 'blind_spot';
        }
        $non_internal_search = array_diff($summary['source_family_coverage'], ['workspace', 'owned_web', 'search', 'public_web']);
        $summary['only_internal_or_search_evidence'] = empty($non_internal_search);
        return $summary;
    }

    private static function bucket($status, $usability) {
        $status = strtoupper((string) $status);
        $usability = sanitize_key((string) $usability);
        if ($usability === 'usable' || $status === 'SUCCEEDED') { return 'usable'; }
        if ($usability === 'failed' || in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'DISPATCH_ERROR'], true)) { return 'failed'; }
        if (in_array($status, ['QUEUED', 'RUNNING', 'WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'], true) || $usability === 'pending') { return 'partial'; }
        return 'partial';
    }
}
