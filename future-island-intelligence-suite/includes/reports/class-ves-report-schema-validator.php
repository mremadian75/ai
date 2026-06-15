<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Report_Schema_Validator {
    public static function normalize(array $report): array {
        $report['title'] = sanitize_text_field((string)($report['title'] ?? __('Intelligence report', 'ves')));
        $report['subtitle'] = sanitize_text_field((string)($report['subtitle'] ?? ''));
        $report['platform'] = sanitize_key((string)($report['platform'] ?? $report['source_family'] ?? 'unknown'));
        $report['source_family'] = sanitize_key((string)($report['source_family'] ?? $report['platform']));
        $report['language'] = sanitize_key((string)($report['language'] ?? 'es'));
        $report['sample_mode'] = !empty($report['sample_mode']);
        foreach (['summary','diagnostics'] as $key) {
            if (!isset($report[$key]) || !is_array($report[$key])) { $report[$key] = []; }
        }
        foreach (['evidence','insights','opportunities','next_actions','warnings','discard_reasons'] as $key) {
            if (!isset($report[$key]) || !is_array($report[$key])) { $report[$key] = []; }
        }
        $report['summary']['evidence_count'] = max(0, (int)($report['summary']['evidence_count'] ?? count($report['evidence'])));
        $report['summary']['usable_count'] = max(0, (int)($report['summary']['usable_count'] ?? $report['summary']['evidence_count']));
        $report['summary']['confidence'] = sanitize_key((string)($report['summary']['confidence'] ?? ($report['summary']['evidence_count'] > 0 ? 'limited' : 'none')));
        if (!in_array($report['summary']['confidence'], ['none','low','limited','medium','high'], true)) {
            $report['summary']['confidence'] = 'limited';
        }
        $report['diagnostics']['rendered_at'] = gmdate('c');
        return $report;
    }

    public static function validate(array $report): array {
        $report = self::normalize($report);
        $errors = [];
        if (($report['title'] ?? '') === '') { $errors[] = 'missing_title'; }
        if (!empty($report['sample_mode'])) { $errors[] = 'sample_mode_not_live_evidence'; }
        if ((int)($report['summary']['evidence_count'] ?? 0) <= 0) { $errors[] = 'no_usable_evidence'; }
        return ['ok' => empty($errors) || $errors === ['no_usable_evidence'], 'errors' => $errors, 'report' => $report];
    }
}
