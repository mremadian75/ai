<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Memory_Bridge {
    public static function remember_report(int $run_id, array $report): void {
        do_action('fidtf_report_ready_for_memory', $run_id, $report);

        $summary = self::summary_text($report);
        if ($summary === '') { return; }

        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'save_record')) {
            try {
                VES_Memory_Records::save_record([
                    'memory_type' => 'research_summary',
                    'title' => 'Deep Trend Finder report #' . $run_id,
                    'summary' => $summary,
                    'content' => $report,
                    'source_type' => 'deep_trend_finder',
                    'source_id' => (string) $run_id,
                    'tags' => ['Deep Trend Finder', 'trend intelligence'],
                    'importance_score' => 60,
                    'dedupe' => true,
                ]);
                return;
            } catch (Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[fidtf] VES_Memory_Records bridge skipped: ' . $e->getMessage()); }
            }
        }

        $payload = [
            'source' => 'deep_trend_finder',
            'source_type' => 'deep_trend_finder',
            'label' => 'Deep Trend Finder',
            'run_id' => $run_id,
            'summary' => $summary,
            'created_at' => current_time('mysql'),
        ];

        try {
            if (class_exists('VES_Temp_Memory_Store') && method_exists('VES_Temp_Memory_Store', 'insert')) {
                VES_Temp_Memory_Store::insert($payload);
            } elseif (class_exists('VES_Temp_Memory_Store') && method_exists('VES_Temp_Memory_Store', 'remember')) {
                VES_Temp_Memory_Store::remember($payload);
            } elseif (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'remember')) {
                VES_Temp_Memory::remember($payload);
            }
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[fidtf] memory bridge skipped: ' . $e->getMessage()); }
        }
    }

    private static function summary_text(array $report): string {
        $parts = [];
        foreach ((array) ($report['insights'] ?? []) as $insight) {
            if (is_array($insight) && !empty($insight['title'])) { $parts[] = sanitize_text_field((string) $insight['title']); }
        }
        if (empty($parts) && !empty($report['status_label'])) { $parts[] = sanitize_text_field((string) $report['status_label']); }
        if (empty($parts) && !empty($report['summary'])) { $parts[] = sanitize_text_field((string) $report['summary']); }
        return trim(implode(' | ', array_slice($parts, 0, 5)));
    }
}
