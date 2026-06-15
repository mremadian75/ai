<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Report_Data_Builder {
    public static function from_run_payload(array $payload): array {
        $platform = sanitize_key((string)($payload['platform'] ?? $payload['source'] ?? 'unknown'));
        $items = [];
        foreach ((array)($payload['items'] ?? $payload['results'] ?? $payload['evidence'] ?? []) as $idx => $item) {
            if (!is_array($item)) { continue; }
            $items[] = [
                'id' => sanitize_text_field((string)($item['item_hash'] ?? $item['id'] ?? ('ev_' . ($idx + 1)))),
                'title' => sanitize_text_field((string)($item['ves_title'] ?? $item['title'] ?? $item['headline'] ?? __('Evidence item', 'ves'))),
                'text' => sanitize_textarea_field((string)($item['ves_text'] ?? $item['text'] ?? $item['body'] ?? '')),
                'url' => esc_url_raw((string)($item['ves_url'] ?? $item['url'] ?? $item['link'] ?? '')),
                'metric' => sanitize_text_field((string)($item['ves_primary_metric_label'] ?? $item['metric_label'] ?? '')),
                'metric_value' => is_numeric($item['ves_primary_metric_value'] ?? null) ? (float)$item['ves_primary_metric_value'] : null,
                'confidence' => sanitize_key((string)($item['confidence'] ?? 'observed')),
            ];
        }
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $summary['evidence_count'] = count($items);
        $summary['usable_count'] = (int)($payload['usable_count'] ?? count($items));
        return [
            'title' => sanitize_text_field((string)($payload['title'] ?? __('Signal report', 'ves'))),
            'subtitle' => sanitize_text_field((string)($payload['subtitle'] ?? __('Evidence-backed interpretation from this run.', 'ves'))),
            'platform' => $platform,
            'source_family' => $platform,
            'language' => sanitize_key((string)($payload['language'] ?? 'es')),
            'summary' => $summary,
            'evidence' => $items,
            'insights' => self::string_list($payload['insights'] ?? []),
            'opportunities' => self::string_list($payload['opportunities'] ?? []),
            'next_actions' => self::string_list($payload['next_actions'] ?? $payload['actions'] ?? []),
            'warnings' => self::string_list($payload['warnings'] ?? []),
            'discard_reasons' => self::string_list($payload['discard_reasons'] ?? []),
            'diagnostics' => is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [],
            'sample_mode' => false,
        ];
    }

    private static function string_list($value): array {
        $out = [];
        foreach ((array)$value as $row) {
            if (is_array($row)) {
                $row = $row['text'] ?? $row['title'] ?? $row['label'] ?? '';
            }
            $row = trim(sanitize_text_field((string)$row));
            if ($row !== '') { $out[] = $row; }
        }
        return array_values(array_unique($out));
    }
}
