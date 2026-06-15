<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Provider_Result_Validator {
    public static function validate_rows(string $family, array $contract, array $rows): array {
        $accepted = [];
        $rejected = [];
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            $clean = VES_Secret_Redactor::redact(is_array($row) ? $row : []);
            $err = self::validate_row($family, $contract, $clean);
            if ($err !== '') { $rejected[] = ['index' => $i - 1, 'reason' => $err, 'row' => self::public_row($clean)]; continue; }
            $accepted[] = self::normalize_row($family, $clean);
        }
        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    private static function validate_row(string $family, array $contract, array $row): string {
        if (!empty($row['is_private']) || !empty($row['private']) || !empty($row['requires_login']) || !empty($row['logged_in']) || !empty($row['private_content'])) {
            return 'private_or_logged_in_content_blocked';
        }
        foreach ((array) ($contract['required_fields'] ?? []) as $field) {
            if (!array_key_exists($field, $row) || self::is_empty($row[$field])) { return 'missing_required_' . $field; }
        }
        if ($family === 'social_signal') {
            $has_text = !self::is_empty($row['text'] ?? null) || !self::is_empty($row['caption'] ?? null) || !self::is_empty($row['title'] ?? null) || !self::is_empty($row['source_url'] ?? null) || !self::is_empty($row['origin_ref'] ?? null);
            if (!$has_text) { return 'missing_social_content_reference'; }
            if (!empty($row['source_url']) && !self::looks_public_url((string) $row['source_url'])) { return 'invalid_source_url'; }
        }
        if ($family === 'aeo_answer') {
            if (strlen((string) ($row['answer'] ?? '')) < 3) { return 'answer_too_short'; }
        }
        if ($family === 'creative_asset_analysis') {
            if (strlen((string) ($row['summary'] ?? '')) < 3) { return 'summary_too_short'; }
        }
        return '';
    }

    private static function normalize_row(string $family, array $row): array {
        $out = [];
        foreach ($row as $k => $v) {
            $key = self::clean_key((string) $k, 80);
            if ($key === '') { continue; }
            if (is_string($v)) { $out[$key] = self::clean_text($v, in_array($key, ['text','caption','answer','summary'], true) ? 5000 : 500); }
            elseif (is_array($v)) { $out[$key] = VES_Secret_Redactor::redact($v); }
            elseif (is_bool($v) || is_numeric($v) || $v === null) { $out[$key] = $v; }
        }
        $out['provider_family'] = $family;
        return $out;
    }

    private static function public_row(array $row): array {
        return array_intersect_key($row, array_flip(['platform','source_url','origin_ref','title','prompt','provider_ref','reason']));
    }

    private static function looks_public_url(string $url): bool {
        if ($url === '') { return true; }
        return (bool) preg_match('#^https?://#i', $url);
    }
    private static function is_empty($v): bool { return $v === null || $v === '' || (is_array($v) && empty($v)); }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
}
