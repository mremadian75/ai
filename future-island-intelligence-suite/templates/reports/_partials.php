<?php
if (!defined('ABSPATH')) { exit; }
if (!function_exists('ves_report_badge')) {
    function ves_report_badge($text, $class = '') {
        return '<span class="ves-report-badge ' . esc_attr($class) . '">' . esc_html((string)$text) . '</span>';
    }
}
if (!function_exists('ves_report_list')) {
    function ves_report_list($items, $empty = '') {
        $items = is_array($items) ? $items : [];
        if (empty($items)) { return $empty !== '' ? '<p class="ves-report-muted">' . esc_html($empty) . '</p>' : ''; }
        $html = '<ul class="ves-report-list">';
        foreach ($items as $item) { $html .= '<li>' . esc_html((string)$item) . '</li>'; }
        return $html . '</ul>';
    }
}
if (!function_exists('ves_report_excerpt')) {
    function ves_report_excerpt($value, $limit = 180) {
        $raw = (string) $value;
        $text = function_exists('wp_strip_all_tags') ? trim(wp_strip_all_tags($raw)) : trim(strip_tags($raw));
        $limit = max(20, (int) $limit);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit - 1) . '…' : $text;
    }
}
if (!function_exists('ves_report_confidence_meta')) {
    function ves_report_confidence_meta($confidence) {
        $key = strtolower(trim((string) $confidence));
        $map = [
            'high' => ['label' => 'High confidence', 'class' => 'is-hot', 'pct' => 88],
            'medium' => ['label' => 'Medium confidence', 'class' => 'is-rise', 'pct' => 68],
            'medium-high' => ['label' => 'Medium-high confidence', 'class' => 'is-rise', 'pct' => 74],
            'low-medium' => ['label' => 'Low-medium confidence', 'class' => 'is-watch', 'pct' => 48],
            'directional' => ['label' => 'Directional', 'class' => 'is-watch', 'pct' => 55],
            'limited' => ['label' => 'Limited evidence', 'class' => 'is-weak', 'pct' => 28],
            'low' => ['label' => 'Low confidence', 'class' => 'is-weak', 'pct' => 22],
        ];
        if (isset($map[$key])) { return $map[$key]; }
        return ['label' => $confidence !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Limited evidence', 'class' => 'is-weak', 'pct' => 35];
    }
}
