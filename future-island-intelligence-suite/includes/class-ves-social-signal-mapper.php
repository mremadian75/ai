<?php
if (!defined('ABSPATH')) { exit; }

/** Normalize existing/provider-like social rows into canonical Signal payloads. */
final class VES_Social_Signal_Mapper {
    const QUALITY_LABELS = ['direct_match','semantic_fit','creative_mechanic','weak','discarded'];

    public static function normalize_row(array $row, array $defaults = []): array {
        $platform = self::key((string) ($row['platform'] ?? ($defaults['platform'] ?? 'other')), 40);
        $source_url = class_exists('VES_Playbook_Input_Validator') ? VES_Playbook_Input_Validator::safe_url((string) ($row['source_url'] ?? ($row['url'] ?? ''))) : (string) ($row['source_url'] ?? '');
        $caption = self::textarea((string) ($row['caption'] ?? ($row['text'] ?? ($row['title'] ?? ''))), 5000);
        $title = self::text((string) ($row['title'] ?? ($caption !== '' ? substr($caption, 0, 90) : 'Social signal')), 255);
        $label = self::key((string) ($row['relevance_label'] ?? 'semantic_fit'), 40);
        if (!in_array($label, self::QUALITY_LABELS, true)) { $label = 'weak'; }
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        foreach (['views','likes','comments','shares','saves'] as $k) { $metrics[$k] = max(0, (int) ($metrics[$k] ?? ($row[$k] ?? 0))); }
        $hashtags = is_array($row['hashtags'] ?? null) ? array_values($row['hashtags']) : self::extract_hashtags($caption);
        $normalized_text = strtolower(trim(preg_replace('/\s+/', ' ', $caption . ' ' . $title)));
        return [
            'signal_type' => 'social_post',
            'platform' => $platform,
            'source_url' => $source_url,
            'origin_ref' => self::text((string) ($row['origin_ref'] ?? ($row['id'] ?? '')), 190),
            'author_name' => self::text((string) ($row['author_name'] ?? ''), 190),
            'author_handle' => self::text((string) ($row['author_handle'] ?? ($row['handle'] ?? '')), 120),
            'title' => $title,
            'text' => self::textarea((string) ($row['text'] ?? $caption), 5000),
            'caption' => $caption,
            'hashtags' => array_slice(array_map([__CLASS__, 'text_public'], $hashtags), 0, 20),
            'media_refs' => is_array($row['media_refs'] ?? null) ? array_slice($row['media_refs'], 0, 10) : [],
            'published_at' => self::datetime((string) ($row['published_at'] ?? '')),
            'captured_at' => self::datetime((string) ($row['captured_at'] ?? '')) ?: self::now(),
            'metrics' => $metrics,
            'language' => self::locale((string) ($row['language'] ?? ($defaults['language'] ?? ''))),
            'country_or_market' => self::text((string) ($row['country_or_market'] ?? ($defaults['market'] ?? '')), 120),
            'normalized_text' => $normalized_text,
            'relevance_label' => $label,
            'quality_flags' => is_array($row['quality_flags'] ?? null) ? array_slice($row['quality_flags'], 0, 20) : [],
            'provider_ref' => self::text((string) ($row['provider_ref'] ?? ''), 190),
        ];
    }

    public static function to_signal_args(array $normalized, int $workspace_id, int $run_id, int $source_id = 0): array {
        $type = $normalized['relevance_label'] === 'creative_mechanic' ? 'content_pattern' : ($normalized['relevance_label'] === 'discarded' ? 'other' : 'audience_signal');
        return [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'source_id' => $source_id,
            'signal_type' => $type,
            'title' => (string) ($normalized['title'] ?? 'Social signal'),
            'summary' => (string) ($normalized['caption'] ?? ($normalized['text'] ?? '')),
            'value_text' => (string) ($normalized['normalized_text'] ?? ''),
            'confidence_score' => self::confidence_for_label((string) ($normalized['relevance_label'] ?? 'weak')),
            'occurred_at' => (string) ($normalized['published_at'] ?: $normalized['captured_at']),
            'metadata' => [
                'social_signal' => $normalized,
                'platform' => (string) ($normalized['platform'] ?? ''),
                'source_family' => 'social',
                'relevance_label' => (string) ($normalized['relevance_label'] ?? ''),
                'metrics' => is_array($normalized['metrics'] ?? null) ? $normalized['metrics'] : [],
            ],
        ];
    }

    public static function confidence_for_label(string $label): int {
        return ['direct_match' => 72, 'semantic_fit' => 60, 'creative_mechanic' => 58, 'weak' => 35, 'discarded' => 15][$label] ?? 35;
    }

    private static function extract_hashtags(string $text): array { preg_match_all('/#[\p{L}\p{N}_]+/u', $text, $m); return $m[0] ?? []; }
    public static function text_public($s): string { return self::text((string) $s, 80); }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function textarea(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return strlen($s) > $max ? substr($s, 0, $max) : $s; }
    private static function key(string $s, int $max): string { $s = strtolower($s); $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function locale(string $s): string { $s = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, 16); }
    private static function datetime(string $s): string { $ts = $s !== '' ? strtotime($s) : false; return $ts ? gmdate('Y-m-d H:i:s', $ts) : ''; }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
}
