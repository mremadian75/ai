<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Central trust gate for trend names and legacy memory cleanup.
 * A trend label must be semantic; pure metrics like "748K", "177K", "1.1M", "382K" or "3.3M" are evidence only. It also rejects generic URLs such as ads.tiktok.com/business/creativecenter.
 */
final class VES_Trend_Label_Guard {
    private static function strip($value) {
        $value = is_scalar($value) ? (string) $value : '';
        $value = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value) : strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    public static function is_safe_label($value, $field = '') {
        $value = self::strip($value);
        $field = strtolower((string) $field);
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $len > 140) { return false; }
        if (preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL)) { return false; }
        if (preg_match('#ads\.tiktok\.com/business/creativecenter#i', $value)) { return false; }
        if (preg_match('/^\d+(?:[\.,]\d+)?\s*[kmb]?$/i', $value)) { return false; }
        if (preg_match('/^\$?\d+(?:[\.,]\d+)?\s*[kmb]?$/i', $value)) { return false; }
        if (preg_match('/^\d{1,3}(?:[\.,]\d{3})+$/', $value)) { return false; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $value)) { return false; }
        if (preg_match('/^\d+\s*(s|sec|secs|seg|seconds?|m|min|mins|h|hr|hrs)$/i', $value)) { return false; }
        if (!preg_match('/[\p{L}#@]/u', $value)) { return false; }
        if (preg_match('/(view|views|visitas|likes?|comments?|shares?|orders?|revenue|visits?|duration|watch|ratio|rank|score|count|total|followers?|plays?|vv)/i', $field)) { return false; }
        if (preg_match('/^(views?|likes?|comments?|shares?|orders?|revenue|visits?|duration|rank|score|count|total|followers?|plays?|vv)\s*[:\-]/i', $value)) { return false; }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $bad_exact = ['live', 'none', 'null', 'undefined', 'unknown', 'n/a', 'na', 'all', 'today', 'yesterday'];
        if (in_array($lower, $bad_exact, true)) { return false; }
        return true;
    }

    public static function clean_label($value, $field = '') {
        $value = self::strip($value);
        if (!self::is_safe_label($value, $field)) { return ''; }
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : $value;
    }

    public static function trend_key($value) {
        $value = self::clean_label($value, 'trend_name');
        if ($value === '') { return ''; }
        $key = function_exists('remove_accents') ? remove_accents($value) : $value;
        $key = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
        return trim(preg_replace('/\s+/u', ' ', $key));
    }

    public static function sanitize_label_list($items, $field = 'trend_name', $limit = 20) {
        $out = [];
        $seen = [];
        foreach ((array) $items as $item) {
            $candidate = '';
            if (is_array($item)) {
                foreach (['trend_name', 'name', 'title', 'label', 'query', 'keyword', 'hashtag'] as $key) {
                    if (!empty($item[$key]) && is_scalar($item[$key])) {
                        $candidate = self::clean_label($item[$key], $key);
                        if ($candidate !== '') { break; }
                    }
                }
            } elseif (is_scalar($item)) {
                $candidate = self::clean_label($item, $field);
            }
            if ($candidate === '') { continue; }
            $key = self::trend_key($candidate);
            if ($key === '' || isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $candidate;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    public static function sanitize_prioritized_trends($trends, &$removed = []) {
        $out = [];
        $seen = [];
        $removed = [];
        foreach ((array) $trends as $trend) {
            if (!is_array($trend)) { continue; }
            $name = self::clean_label($trend['trend_name'] ?? '', 'trend_name');
            if ($name === '') {
                $raw = is_scalar($trend['trend_name'] ?? null) ? (string) $trend['trend_name'] : '';
                if ($raw !== '') { $removed[] = self::strip($raw); }
                continue;
            }
            $key = self::trend_key($name);
            if ($key === '' || isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $trend['trend_name'] = $name;
            foreach (['based_on_trend', 'opportunity_name'] as $maybe_label) {
                if (isset($trend[$maybe_label]) && !self::is_safe_label($trend[$maybe_label], $maybe_label)) {
                    $trend[$maybe_label] = '';
                }
            }
            $out[] = $trend;
        }
        return $out;
    }

    public static function payload_corruption($payload) {
        $payload = is_array($payload) ? $payload : [];
        $structured = is_array($payload['analysis_structured'] ?? null) ? $payload['analysis_structured'] : [];
        $trends = is_array($structured['prioritized_trends'] ?? null) ? $structured['prioritized_trends'] : [];
        $bad = [];
        foreach ($trends as $trend) {
            if (!is_array($trend)) { continue; }
            if (array_key_exists('trend_name', $trend) && !self::is_safe_label($trend['trend_name'], 'trend_name')) {
                $bad[] = self::strip($trend['trend_name']);
            }
        }
        foreach (['top_trends', 'new_trends', 'dropped_trends', 'overlap_trends'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $value) {
                if (is_scalar($value) && !self::is_safe_label($value, $key)) {
                    $bad[] = self::strip($value);
                }
            }
        }
        $bad = array_values(array_unique(array_filter($bad, static function($v) { return $v !== ''; })));
        return [
            'is_corrupted' => !empty($bad),
            'bad_labels' => $bad,
            'count' => count($bad),
        ];
    }

    public static function memory_status_for_payload($payload) {
        $payload = is_array($payload) ? $payload : [];
        $corruption = self::payload_corruption($payload);
        if (!empty($corruption['is_corrupted'])) { return 'corrupted_legacy'; }
        if (!empty($payload['fallback_used']) || (($payload['memory_type'] ?? '') === 'fallback_diagnostic') || (($payload['confidence_mode'] ?? '') === 'diagnostic_fallback')) { return 'fallback_diagnostic'; }
        $mode = sanitize_key((string) ($payload['confidence_mode'] ?? ''));
        if (in_array($mode, ['insufficient_evidence', 'exploratory'], true)) { return 'partial'; }
        if (($payload['run_status'] ?? '') === 'partial' || (($payload['report_status'] ?? '') === 'partial')) { return 'partial'; }
        return 'validated';
    }

    public static function repair_payload($payload) {
        $payload = is_array($payload) ? $payload : [];
        $removed = [];
        if (isset($payload['analysis_structured']) && is_array($payload['analysis_structured'])) {
            $trends = is_array($payload['analysis_structured']['prioritized_trends'] ?? null) ? $payload['analysis_structured']['prioritized_trends'] : [];
            $payload['analysis_structured']['prioritized_trends'] = self::sanitize_prioritized_trends($trends, $removed);
        }
        foreach (['top_trends', 'new_trends', 'dropped_trends', 'overlap_trends'] as $list_key) {
            if (array_key_exists($list_key, $payload)) {
                $before = (array) $payload[$list_key];
                $payload[$list_key] = self::sanitize_label_list($before, $list_key, 20);
                foreach ($before as $candidate) {
                    if (is_scalar($candidate) && !self::is_safe_label($candidate, $list_key)) { $removed[] = self::strip($candidate); }
                }
            }
        }
        $removed = array_values(array_unique(array_filter($removed, static function($v) { return $v !== ''; })));
        if (!empty($removed)) {
            $payload['memory_status'] = 'corrupted_legacy';
            $payload['needs_repair'] = false;
            $payload['legacy_repair'] = [
                'repaired_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
                'removed_labels' => array_slice($removed, 0, 30),
                'reason' => 'numeric_or_metric_only_trend_labels_removed',
            ];
            $payload['report_status'] = !empty($payload['fallback_used']) ? 'fallback_diagnostic' : 'partial';
            if (empty($payload['analysis_structured']['prioritized_trends'])) {
                $payload['can_generate_brief'] = false;
                $payload['validation_required'] = true;
            }
        }
        return [$payload, $removed];
    }
}
