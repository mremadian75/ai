<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit {
    public static function defaults() {
        return [
            'brand_name' => '',
            'website_url' => '',
            'country' => 'ES',
            'language' => 'es',
            'industry' => '',
            'audit_goal' => '',
            'depth' => 'standard',
            'output_type' => 'report_deck',
            'instagram_url' => '',
            'tiktok_url' => '',
            'youtube_url' => '',
            'linkedin_url' => '',
            'twitter_url' => '',
            'facebook_url' => '',
            'google_maps_url' => '',
            'hashtags' => [],
            'brand_keywords' => [],
            'competitors' => [],
            'output_language' => 'es',
        ];
    }

    public static function allowed_depths() {
        return ['light', 'standard', 'deep'];
    }

    public static function allowed_output_types() {
        return ['report', 'deck', 'report_deck'];
    }

    public static function label_depth($depth) {
        $depth = sanitize_key((string) $depth);
        $labels = [
            'light' => 'Light',
            'standard' => 'Standard',
            'deep' => 'Deep',
        ];
        return $labels[$depth] ?? 'Standard';
    }

    public static function parse_list($value, $max = 10) {
        $max = max(1, min(50, (int) $max));
        if (is_array($value)) {
            $parts = $value;
        } else {
            $value = (string) $value;
            $parts = preg_split('/[\r\n,;]+/', $value);
        }
        $out = [];
        foreach ((array) $parts as $part) {
            $part = trim(wp_strip_all_tags((string) $part));
            if ($part === '') {
                continue;
            }
            $part = mb_substr($part, 0, 120);
            if (!in_array($part, $out, true)) {
                $out[] = $part;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    public static function sanitize_url_or_empty($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = esc_url_raw($url);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private static function normalize_country($country) {
        $country = trim(sanitize_text_field((string) $country));
        if ($country === "") { return "ES"; }
        $upper = strtoupper($country);
        if (preg_match("/^[A-Z]{2}$/", $upper)) { return $upper; }
        $norm = function_exists("remove_accents") ? remove_accents($country) : $country;
        $norm = strtolower(trim($norm));
        $map = [
            "spain" => "ES", "espana" => "ES", "españa" => "ES",
            "united states" => "US", "usa" => "US", "estados unidos" => "US",
            "united kingdom" => "GB", "uk" => "GB", "great britain" => "GB", "reino unido" => "GB",
            "france" => "FR", "francia" => "FR", "germany" => "DE", "alemania" => "DE",
            "italy" => "IT", "italia" => "IT", "portugal" => "PT",
            "mexico" => "MX", "méxico" => "MX", "brazil" => "BR", "brasil" => "BR",
            "argentina" => "AR", "colombia" => "CO", "chile" => "CL", "peru" => "PE", "perú" => "PE",
            "worldwide" => "NONE", "global" => "NONE", "any" => "NONE", "cualquier pais" => "NONE", "cualquier país" => "NONE"
        ];
        return $map[$norm] ?? mb_substr($upper, 0, 16);
    }

    private static function sanitize_platform_url_or_empty($platform, $url) {
        $url = self::sanitize_url_or_empty($url);
        if ($url === "") { return ""; }
        if (function_exists("ves_platform_url_kind")) {
            $kind = ves_platform_url_kind($platform, $url);
            if ($kind === "unknown") { return ""; }
        }
        return $url;
    }

    private static function pick($src, $keys, $default = '') {
        foreach ((array) $keys as $key) {
            if (isset($src[$key])) {
                return is_array($src[$key]) ? $src[$key] : wp_unslash($src[$key]);
            }
        }
        return $default;
    }

    public static function sanitize_request($src) {
        $src = is_array($src) ? $src : [];
        $defaults = self::defaults();

        $brand_name = sanitize_text_field((string) self::pick($src, ['brandName', 'brand_name'], ''));
        $brand_name = mb_substr(trim($brand_name), 0, 160);

        $country = sanitize_text_field((string) self::pick($src, ['brandCountry', 'country'], $defaults['country']));
        $country = self::normalize_country($country !== '' ? $country : $defaults['country']);

        $language = sanitize_key((string) self::pick($src, ['outputLanguage', 'brandLanguage', 'language'], $defaults['language']));
        if ($language === '') {
            $language = $defaults['language'];
        }

        $depth = sanitize_key((string) self::pick($src, ['brandAuditDepth', 'depth'], $defaults['depth']));
        if (!in_array($depth, self::allowed_depths(), true)) {
            $depth = $defaults['depth'];
        }

        $output_type = sanitize_key((string) self::pick($src, ['brandAuditOutput', 'output_type'], $defaults['output_type']));
        if (!in_array($output_type, self::allowed_output_types(), true)) {
            $output_type = $defaults['output_type'];
        }

        $request = [
            'brand_name' => $brand_name,
            'website_url' => self::sanitize_url_or_empty(self::pick($src, ['websiteUrl', 'website_url'], '')),
            'country' => $country,
            'language' => $language,
            'industry' => mb_substr(sanitize_text_field((string) self::pick($src, ['brandIndustry', 'industry'], '')), 0, 160),
            'audit_goal' => mb_substr(sanitize_textarea_field((string) self::pick($src, ['brandAuditGoal', 'audit_goal'], '')), 0, 800),
            'depth' => $depth,
            'output_type' => $output_type,
            'instagram_url' => self::sanitize_platform_url_or_empty('instagram', self::pick($src, ['instagramUrl', 'instagram_url'], '')),
            'tiktok_url' => self::sanitize_platform_url_or_empty('tiktok', self::pick($src, ['tiktokUrl', 'tiktok_url'], '')),
            'youtube_url' => self::sanitize_platform_url_or_empty('youtube', self::pick($src, ['youtubeUrl', 'youtube_url'], '')),
            'linkedin_url' => self::sanitize_url_or_empty(self::pick($src, ['linkedinUrl', 'linkedin_url'], '')),
            'twitter_url' => self::sanitize_platform_url_or_empty('twitter', self::pick($src, ['twitterUrl', 'twitter_url'], '')),
            'facebook_url' => self::sanitize_platform_url_or_empty('facebook', self::pick($src, ['facebookUrl', 'facebook_url'], '')),
            'google_maps_url' => self::sanitize_url_or_empty(self::pick($src, ['googleMapsUrl', 'google_maps_url'], '')),
            'hashtags' => self::parse_list(self::pick($src, ['brandHashtags', 'hashtags'], []), 10),
            'brand_keywords' => self::parse_list(self::pick($src, ['brandKeywords', 'brand_keywords'], []), 15),
            'competitors' => self::parse_list(self::pick($src, ['brandCompetitors', 'competitors'], []), 5),
            'output_language' => $language,
        ];

        return wp_parse_args($request, $defaults);
    }
}
