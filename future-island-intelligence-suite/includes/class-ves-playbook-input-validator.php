<?php
if (!defined('ABSPATH')) { exit; }

/** Shared deterministic validation/sanitization for guided playbooks. */
final class VES_Playbook_Input_Validator {

    public static function brand_market_xray(array $input) {
        $out = [
            'brand_url' => self::safe_url((string) ($input['brand_url'] ?? '')),
            'brand_name' => self::text((string) ($input['brand_name'] ?? ''), 160),
            'brand_description' => self::textarea((string) ($input['brand_description'] ?? ''), 5000),
            'market' => self::text((string) ($input['market'] ?? ''), 120),
            'language' => self::locale((string) ($input['language'] ?? 'en')),
            'audience' => self::text((string) ($input['audience'] ?? ''), 255),
            'goal' => self::text((string) ($input['goal'] ?? 'Identify the strongest evidence-backed market direction.'), 255),
            'manual_notes' => self::textarea((string) ($input['manual_notes'] ?? ''), 5000),
            'competitors' => self::split_list($input['competitors'] ?? '', 8, 180),
            'keywords' => self::split_list($input['keywords'] ?? ($input['topics'] ?? ''), 10, 80),
            'social_handles' => self::split_list($input['social_handles'] ?? '', 8, 80),
        ];

        if ((string) ($input['brand_url'] ?? '') !== '' && $out['brand_url'] === '') {
            return self::err('ves_playbook_bad_url', 'Brand URL must be http(s) and is stored as a reference only.');
        }
        if ($out['brand_name'] === '' && $out['brand_description'] === '' && $out['brand_url'] === '') {
            return self::err('ves_playbook_missing_brand', 'Add a brand URL, brand name, or brand description.');
        }
        return $out;
    }

    public static function social_signal_scan(array $input) {
        $mode = self::key((string) ($input['mode'] ?? 'topic'), 40);
        if (!in_array($mode, ['topic','post','profile','competitor'], true)) { $mode = 'topic'; }
        $platforms = self::split_list($input['platforms'] ?? 'instagram,tiktok,reddit,linkedin', 8, 40);
        $out = [
            'mode' => $mode,
            'query' => self::text((string) ($input['query'] ?? ($input['topic'] ?? '')), 200),
            'post_url' => self::safe_url((string) ($input['post_url'] ?? '')),
            'profile' => self::text((string) ($input['profile'] ?? ($input['handle'] ?? '')), 120),
            'competitor_handles' => self::split_list($input['competitor_handles'] ?? '', 10, 120),
            'platforms' => $platforms,
            'market' => self::text((string) ($input['market'] ?? ''), 120),
            'language' => self::locale((string) ($input['language'] ?? 'en')),
            'time_window' => self::text((string) ($input['time_window'] ?? 'last_30_days'), 80),
            'result_limit' => max(1, min(50, (int) ($input['result_limit'] ?? 10))),
            'goal' => self::text((string) ($input['goal'] ?? 'Turn social signals into insight and a brief candidate.'), 255),
        ];
        if ($mode === 'topic' && $out['query'] === '') { return self::err('ves_social_missing_query', 'Topic scan requires a query.'); }
        if ($mode === 'post' && $out['post_url'] === '') { return self::err('ves_social_bad_post_url', 'Post analysis requires a public http(s) URL.'); }
        if ($mode === 'profile' && $out['profile'] === '') { return self::err('ves_social_missing_profile', 'Profile analysis requires a handle or profile URL.'); }
        if ($mode === 'competitor' && empty($out['competitor_handles'])) { return self::err('ves_social_missing_competitors', 'Competitor scan requires at least one handle or URL.'); }
        return $out;
    }

    public static function safe_url(string $url): string {
        $url = trim($url);
        if ($url === '') { return ''; }
        $parts = @parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http','https'], true) || empty($parts['host'])) { return ''; }
        return function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
    }

    public static function split_list($value, int $limit, int $max_len): array {
        if (is_array($value)) { $items = $value; }
        else { $items = preg_split('/[\r\n,]+/', (string) $value); }
        $out = [];
        foreach ((array) $items as $item) {
            $item = self::text((string) $item, $max_len);
            if ($item === '') { continue; }
            if (!in_array($item, $out, true)) { $out[] = $item; }
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    public static function text(string $s, int $max): string {
        $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s));
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }
    public static function textarea(string $s, int $max): string {
        $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s));
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }
    public static function locale(string $s): string {
        $s = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s));
        return substr($s !== '' ? $s : 'en', 0, 16);
    }
    public static function key(string $s, int $max): string {
        $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s));
        return substr($s, 0, $max);
    }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message]; }
}
