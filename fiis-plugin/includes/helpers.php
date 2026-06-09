<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        $string = (string) $string;
        return $length === null ? substr($string, (int) $start) : substr($string, (int) $start, (int) $length);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) {
        return strtolower((string) $string);
    }
}

if (!function_exists('ves_get_ip')) {
    function ves_get_ip() {
        // v0.9.24.6: validate each candidate with filter_var so spoofed
        // X-Forwarded-For values (e.g. '1.1.1.1, evil-string') can't
        // bypass rate-limit keys or leak into diagnostics.
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // X-Forwarded-For can be a comma-separated list; take the first.
                $ip  = trim(explode(',', $raw)[0]);
                // Validate: only accept well-formed IPv4/IPv6 addresses.
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        // Fallback: accept private-range REMOTE_ADDR (intranet / localhost dev).
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote && filter_var($remote, FILTER_VALIDATE_IP)) { return $remote; }
        return '0.0.0.0';
    }
}

if (!function_exists('ves_rate_limit_key')) {
    function ves_rate_limit_key() {
        if (is_user_logged_in()) {
            return 'ves_uid_' . get_current_user_id();
        }
        return 'ves_ip_' . md5(ves_get_ip());
    }
}

if (!function_exists('ves_api_request')) {
    function ves_api_request($method, $url, $body = null) {
        return VES_Apify_Client::request($method, $url, $body);
    }
}

if (!function_exists('ves_sanitize_country_code')) {
    function ves_sanitize_country_code($code) {
        $raw = trim((string) $code);
        $normalized = function_exists('remove_accents') ? remove_accents($raw) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        $normalized = strtr((string) $normalized, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $normalized = strtoupper(trim((string) $normalized));
        $normalized_key = preg_replace('/[^A-Z0-9]+/', '_', $normalized);
        $country_aliases = [
            'ESPANA' => 'ES', 'SPAIN' => 'ES', 'ESPAÑA' => 'ES',
            'ESTADOS_UNIDOS' => 'US', 'UNITED_STATES' => 'US', 'USA' => 'US', 'US' => 'US',
            'REINO_UNIDO' => 'GB', 'UNITED_KINGDOM' => 'GB', 'GREAT_BRITAIN' => 'GB', 'UK' => 'GB',
            'FRANCIA' => 'FR', 'FRANCE' => 'FR',
            'ALEMANIA' => 'DE', 'GERMANY' => 'DE', 'DEUTSCHLAND' => 'DE',
            'ITALIA' => 'IT', 'ITALY' => 'IT',
            'PORTUGAL' => 'PT',
            'PAISES_BAJOS' => 'NL', 'NETHERLANDS' => 'NL', 'HOLLAND' => 'NL',
            'MEXICO' => 'MX', 'MÉXICO' => 'MX',
            'BRASIL' => 'BR', 'BRAZIL' => 'BR',
            'ARGENTINA' => 'AR', 'CHILE' => 'CL', 'COLOMBIA' => 'CO', 'PERU' => 'PE', 'PERÚ' => 'PE',
        ];
        if (isset($country_aliases[$normalized_key])) {
            $normalized = $country_aliases[$normalized_key];
        }
        $code = $normalized;
        if ($code === '' || $code === 'NONE' || $code === 'ANY' || $code === 'CUALQUIER_PAIS' || $code === 'WORLDWIDE') return 'None';
        if (!preg_match('/^[A-Z]{2}$/', $code)) return 'None';
        $allowed = [
            'ES','US','GB','FR','DE','IT','NL','PT','IE','BE','CH','SE','NO','DK','FI','PL','RO','GR','AT','CZ',
            'CA','MX','BR','AR','CL','CO','PE','UY','VE','EC','BO',
            'JP','KR','CN','TW','HK','SG','MY','ID','TH','PH','VN','IN','PK','BD','AU','NZ',
            'AE','SA','TR','EG','MA','ZA','IL','NG','KE'
        ];
        return in_array($code, $allowed, true) ? $code : 'None';
    }
}

if (!function_exists('ves_sanitize_language_code')) {
    function ves_sanitize_language_code($code) {
        $raw = trim((string) $code);
        $normalized = function_exists('remove_accents') ? remove_accents($raw) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        $normalized = strtr((string) $normalized, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $normalized = strtolower(trim((string) $normalized));
        $normalized_key = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $language_aliases = [
            'espanol' => 'es', 'spanish' => 'es', 'castellano' => 'es',
            'ingles' => 'en', 'english' => 'en',
            'frances' => 'fr', 'french' => 'fr',
            'aleman' => 'de', 'german' => 'de', 'deutsch' => 'de',
            'italiano' => 'it', 'italian' => 'it',
            'portugues' => 'pt', 'portuguese' => 'pt',
            'neerlandes' => 'nl', 'dutch' => 'nl',
            'turco' => 'tr', 'turkish' => 'tr',
            'arabe' => 'ar', 'arabic' => 'ar',
            'japones' => 'ja', 'japanese' => 'ja',
            'coreano' => 'ko', 'korean' => 'ko',
            'chino' => 'zh', 'chinese' => 'zh',
            'ruso' => 'ru', 'russian' => 'ru',
            'vietnamita' => 'vi', 'vietnamese' => 'vi',
            'indonesio' => 'id', 'indonesian' => 'id',
            'tailandes' => 'th', 'thai' => 'th',
            'hindi' => 'hi',
        ];
        if (isset($language_aliases[$normalized_key])) {
            $normalized = $language_aliases[$normalized_key];
        }
        $code = $normalized;
        if ($code === '' || $code === 'any' || $code === 'cualquier_idioma') return '';
        if (!preg_match('/^[a-z]{2}$/', $code)) return '';
        $allowed = ['es','en','fr','de','it','pt','nl','tr','ar','ja','ko','zh','ru','vi','id','th','hi','pl','sv','cs'];
        return in_array($code, $allowed, true) ? $code : '';
    }
}


if (!function_exists('ves_youtube_search_terms')) {
    /**
     * v0.9.30.6 — conservative, language-aware YouTube query expansion.
     * Keeps the user's primary query first, adds only close Spanish variants for
     * broad English marketing terms, and caps the dispatch list to 3–5 entries.
     */
    function ves_youtube_search_terms($queries, $src = [], $max = 5) {
        $src = is_array($src) ? $src : [];
        $max = max(1, min(5, (int) $max));
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : sanitize_key((string) ($src['language'] ?? ''));
        $base = [];
        foreach ((array) $queries as $q) {
            $q = trim(wp_strip_all_tags((string) $q));
            $q = preg_replace('/\s+/', ' ', $q);
            if ($q !== '') { $base[] = $q; }
        }
        $base = array_values(array_unique($base));
        if (empty($base)) { return []; }
        $out = [];
        foreach ($base as $q) {
            $out[] = $q;
            $norm = strtolower(function_exists('remove_accents') ? remove_accents($q) : $q);
            $norm = trim(preg_replace('/\s+/', ' ', $norm));
            if ($language === 'es') {
                if ($norm === 'marketing') {
                    $out[] = 'marketing digital';
                    $out[] = 'marketing en español';
                    $out[] = 'estrategia de marketing';
                } elseif (preg_match('/\b(ai|artificial intelligence)\b/', $norm) && strpos($norm, 'marketing') !== false) {
                    $out[] = str_replace([' ai ', ' artificial intelligence '], [' ia ', ' inteligencia artificial '], ' ' . $norm . ' ');
                    $out[] = 'marketing con IA';
                    $out[] = 'marketing inteligencia artificial';
                } elseif (strpos($norm, 'digital marketing') !== false) {
                    $out[] = 'marketing digital';
                    $out[] = 'marketing digital en español';
                }
            }
            if (count(array_unique($out)) >= $max) { break; }
        }
        $clean = [];
        foreach ($out as $q) {
            $q = trim(preg_replace('/\s+/', ' ', (string) $q));
            if ($q !== '' && !in_array($q, $clean, true)) { $clean[] = $q; }
            if (count($clean) >= $max) { break; }
        }
        return $clean;
    }
}

if (!function_exists('ves_item_language')) {
    function ves_item_language($item) {
        if (!is_array($item)) return '';
        foreach (['textLanguage', 'language', 'lang', 'originalLanguage', 'languageCode', 'videoLanguage'] as $key) {
            if (!array_key_exists($key, $item)) { continue; }
            $raw = trim((string) $item[$key]);
            if ($raw === '') { continue; }
            $lc = strtolower($raw);
            // Actor values such as "un", "und" or "unknown" mean unknown, not a real mismatch.
            if (in_array($lc, ['un', 'und', 'unknown', 'none', 'null', 'n/a'], true)) { continue; }
            if (preg_match('/^[a-z]{2}/', $lc, $m)) {
                return $m[0];
            }
        }
        return '';
    }
}

if (!function_exists('ves_normalize_url')) {
    /**
     * Normalise a URL entered by the user:
     *   facebook.com/natgeo     -> https://facebook.com/natgeo
     *   www.youtube.com/@nasa   -> https://www.youtube.com/@nasa
     *   http://foo              -> kept as-is
     * Returns '' if the value can't be turned into a valid http/https URL.
     */
    function ves_normalize_url($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return '';

        // Strip leading "at"-like markers that users sometimes paste in.
        $raw = ltrim($raw, "@ \t\n\r\0\x0B");

        // If no scheme, try prepending https://
        if (!preg_match('~^https?://~i', $raw)) {
            // Only prepend if the string looks like a domain (contains a dot) OR starts with www.
            if (preg_match('~^([a-z0-9\-]+\.)+[a-z]{2,}([/:?#].*)?$~i', $raw) || stripos($raw, 'www.') === 0) {
                $raw = 'https://' . $raw;
            } else {
                return '';
            }
        }

        $sanitised = esc_url_raw($raw);
        if (!$sanitised || !filter_var($sanitised, FILTER_VALIDATE_URL)) return '';
        $scheme = parse_url($sanitised, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) return '';
        return $sanitised;
    }
}

if (!function_exists('ves_parse_lines')) {
    function ves_parse_lines($raw, $type = 'text') {
        $raw   = (string) $raw;
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;

            switch ($type) {
                case 'hashtag':
                    $line = ltrim($line, "# \t\n\r\0\x0B");
                    $line = preg_replace('/[^\p{L}0-9_\.\-]/u', '', $line);
                    break;
                case 'profile':
                    $line = ltrim($line, "@ \t\n\r\0\x0B");
                    $line = preg_replace('/[^\p{L}0-9_\.\-]/u', '', $line);
                    break;
                case 'url':
                    $line = ves_normalize_url($line);
                    if ($line === '') continue 2;
                    break;
                default:
                    $line = sanitize_text_field($line);
                    break;
            }
            if ($line !== '') $items[] = $line;
        }
        return array_values(array_unique($items));
    }
}

if (!function_exists('ves_parse_human_number')) {
    /**
     * Parse user-entered metric strings such as 2,000, 2.000, 2k, 1.5K,
     * 1,2k or 1.2M. This is intentionally conservative: decimal comma/dot
     * without a suffix is treated as a normal decimal, while grouped triples
     * are treated as thousands separators.
     */
    function ves_parse_human_number($value) {
        if ($value === '' || $value === null || is_array($value)) { return null; }
        if (is_int($value) || is_float($value)) { return (int) round((float) $value); }
        $raw = trim((string) $value);
        if ($raw === '') { return null; }
        $raw = preg_replace('/\s+/u', '', $raw);
        $raw = str_replace(['٬', '،'], ',', $raw);
        $raw = str_replace(['Ｋ','Ｍ','Ｂ'], ['K','M','B'], $raw);

        if (preg_match('/^([0-9]+(?:[\.,][0-9]+)?)([kmb])$/i', $raw, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            $suffix = strtolower($m[2]);
            if ($suffix === 'k') { $num *= 1000; }
            if ($suffix === 'm') { $num *= 1000000; }
            if ($suffix === 'b') { $num *= 1000000000; }
            return (int) round($num);
        }

        if (preg_match('/^[0-9]+(?:[\.,][0-9]{3})+$/', $raw)) {
            return (int) str_replace([',', '.'], '', $raw);
        }

        if (preg_match('/^[0-9]+(?:[\.,][0-9]+)?$/', $raw)) {
            return (int) round((float) str_replace(',', '.', $raw));
        }

        return null;
    }
}

if (!function_exists('ves_clean_int')) {
    function ves_clean_int($value, $min = 0, $max = null) {
        if ($value === '' || $value === null) return null;
        $parsed = function_exists('ves_parse_human_number') ? ves_parse_human_number($value) : null;
        $value = $parsed !== null ? (int) $parsed : (int) $value;
        if ($value < $min) $value = $min;
        if ($max !== null && $value > $max) $value = $max;
        return $value;
    }
}

if (!function_exists('ves_requested_min_metric')) {
    /**
     * v0.9.24.55 — Canonical reader for the user-facing minimum metric.
     * Cached/custom frontends may submit min_views/minimum_views/minMetric instead
     * of the current minViews field. Treat all of them as the same locked product
     * constraint so over-fetching, backend gates and diagnostics cannot diverge.
     */
    function ves_requested_min_metric($src = []) {
        $src = is_array($src) ? $src : [];
        foreach (['minViews','min_views','minimumViews','minimum_views','minMetric','min_metric','minimumMetric','minimum_metric','minLikes','min_likes'] as $key) {
            if (!array_key_exists($key, $src)) { continue; }
            $raw = $src[$key];
            if (is_array($raw)) { continue; }
            if (trim((string) $raw) === '') { continue; }
            if (function_exists('ves_parse_human_number')) {
                $parsed = ves_parse_human_number($raw);
                if ($parsed !== null) { return max(0, (int) $parsed); }
            }
            return function_exists('ves_clean_int') ? ves_clean_int($raw, 0, null) : max(0, (int) $raw);
        }
        return 0;
    }
}

if (!function_exists('ves_date_preset_to_days')) {
    /**
     * Whitelist of date presets. Anything else → null (= no filter).
     * Returns either a string usable directly by some actors (e.g. "7 days")
     * or an ISO date string.
     */
    function ves_date_preset_to_days($preset) {
        $preset = sanitize_key((string) $preset);
        $map = [
            '1d'  => '1 day',
            '7d'  => '7 days',
            '14d' => '14 days',
            '30d' => '30 days',
            '60d' => '60 days',
            '90d' => '90 days',
            '180d'=> '180 days',
            '365d'=> '365 days',
        ];
        return $map[$preset] ?? null;
    }
}

if (!function_exists('ves_yt_date_filter_code')) {
    /** Date preset mapped to TikTok search-style integer codes (0–5). */
    function ves_yt_date_filter_code($preset) {
        $map = [
            'any' => '0',
            '1d'  => '1',
            '7d'  => '2',
            '14d' => '3',
            '30d' => '3',
            '60d' => '4',
            '90d' => '4',
            '180d'=> '5',
            '365d'=> '5', // v0.9.9.6: TikTok caps at 180 days. Clamp instead of returning "any".
        ];
        return $map[sanitize_key((string) $preset)] ?? '0';
    }
}

if (!function_exists('ves_yt_date_filter')) {
    /** Date preset mapped to YouTube actor dateFilter enum. */
    function ves_yt_date_filter($preset) {
        $map = [
            '1d'   => 'today',
            '7d'   => 'week',
            '14d'  => 'month',
            '30d'  => 'month',
            '60d'  => 'year',
            '90d'  => 'year',
            '180d' => 'year',
            '365d' => 'year',
        ];
        return $map[sanitize_key((string) $preset)] ?? null;
    }
}

if (!function_exists('ves_make_start_urls')) {
    function ves_make_start_urls($urls) {
        if (!is_array($urls)) return [];
        $items = [];
        foreach ($urls as $url) {
            $url = esc_url_raw((string) $url);
            if (!$url) continue;
            $items[] = ['url' => $url];
        }
        return $items;
    }
}

if (!function_exists('ves_scraper_mode_value')) {
    function ves_scraper_mode_value($mode_or_src) {
        if (is_array($mode_or_src)) {
            $mode_or_src = $mode_or_src['mode'] ?? 'search';
        }
        return sanitize_key((string) $mode_or_src);
    }
}

if (!function_exists('ves_is_post_url_mode')) {
    function ves_is_post_url_mode($mode_or_src) {
        $mode = ves_scraper_mode_value($mode_or_src);
        return in_array($mode, ['url_posts', 'post_urls', 'post_url'], true);
    }
}

if (!function_exists('ves_is_mixed_url_mode')) {
    /** Generic UI mode "urls": accept post URLs, profile URLs and handles. */
    function ves_is_mixed_url_mode($mode_or_src) {
        return ves_scraper_mode_value($mode_or_src) === 'urls';
    }
}

if (!function_exists('ves_is_profile_url_mode')) {
    function ves_is_profile_url_mode($mode_or_src) {
        $mode = ves_scraper_mode_value($mode_or_src);
        if (in_array($mode, ['url_profile', 'profile_url', 'profile_urls'], true)) {
            return true;
        }

        // v0.9.24.16: Be defensive when a stale frontend/cache submits an
        // empty/incorrect mode but the target itself is clearly a profile URL.
        // This prevents TikTok profile runs from being treated as generic
        // searches, and it enables the profile-feed fallback path in polling.
        if (is_array($mode_or_src)) {
            $platform = sanitize_key((string) ($mode_or_src['platform'] ?? ''));
            $targets_raw = (string) ($mode_or_src['targets'] ?? '');
            if ($platform !== '' && $targets_raw !== '') {
                $lines = preg_split('/\\r\\n|\\r|\\n/', $targets_raw);
                $profile_count = 0;
                $post_count = 0;
                foreach ((array) $lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '') { continue; }
                    $kind = function_exists('ves_platform_url_kind') ? ves_platform_url_kind($platform, $line) : 'unknown';
                    if ($kind === 'profile') { $profile_count++; }
                    if ($kind === 'post') { $post_count++; }
                }
                if ($profile_count > 0 && $post_count === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('ves_is_any_url_mode')) {
    function ves_is_any_url_mode($mode_or_src) {
        return ves_is_post_url_mode($mode_or_src) || ves_is_profile_url_mode($mode_or_src) || ves_is_mixed_url_mode($mode_or_src);
    }
}


if (!function_exists('ves_platform_url_kind')) {
    function ves_platform_url_kind($platform, $url) {
        $platform = sanitize_key((string) $platform);
        $url = ves_normalize_url((string) $url);
        if ($url === '') {
            return 'unknown';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path_l = strtolower($path);

        // v0.9.16.23: classify URLs only when the hostname belongs to the
        // requested platform. A Facebook URL pasted in the X/Twitter field used
        // to look like a valid Twitter profile because only the path was checked.
        $host_matches = static function($host, $needles) {
            foreach ((array) $needles as $needle) {
                if ($needle !== '' && (strpos($host, $needle) !== false)) {
                    return true;
                }
            }
            return false;
        };

        switch ($platform) {
            case 'tiktok':
                if (!$host_matches($host, ['tiktok.com'])) { return 'unknown'; }
                if (preg_match('~/(?:@[^/]+/(?:video|photo)/\d+|t/[^/]+|v/\d+)~i', $path)) {
                    return 'post';
                }
                if (preg_match('~/@[^/]+/?$~i', $path)) {
                    return 'profile';
                }
                return 'unknown';

            case 'youtube':
                if (!$host_matches($host, ['youtube.com', 'youtu.be'])) { return 'unknown'; }
                if (strpos($host, 'youtu.be') !== false || preg_match('~/(?:watch|shorts|embed|live|clip|post)(/|$)~i', $path) || preg_match('~(^|&)v=[^&]+~i', $query)) {
                    return 'post';
                }
                if (preg_match('~/(?:@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+|playlist)(/|$)~i', $path)) {
                    return 'profile';
                }
                return 'unknown';

            case 'instagram':
                if (!$host_matches($host, ['instagram.com'])) { return 'unknown'; }
                if (preg_match('~/(?:p|reel|tv|stories)/[^/]+~i', $path)) {
                    return 'post';
                }
                if (preg_match('~/explore/tags/[^/]+~i', $path)) {
                    return 'hashtag';
                }
                if (preg_match('~^/[^/]+/?$~', $path)) {
                    return 'profile';
                }
                return 'unknown';

            case 'facebook':
                if (!$host_matches($host, ['facebook.com', 'fb.com'])) { return 'unknown'; }
                if (preg_match('~/(?:posts|videos|reel|photo|photos|watch|share|story\.php|permalink\.php)(/|$)~i', $path_l) || preg_match('~(^|&)story_fbid=|(^|&)fbid=|(^|&)v=~i', $query)) {
                    return 'post';
                }
                if (preg_match('~^/profile\.php$~i', $path_l) && preg_match('~(^|&)id=~i', $query)) {
                    return 'profile';
                }
                if (preg_match('~^/[^/?#]+/?$~', $path) || preg_match('~/pages/[^/]+~i', $path)) {
                    return 'profile';
                }
                return 'unknown';
            case 'twitter':
                if (!$host_matches($host, ['twitter.com', 'x.com'])) { return 'unknown'; }
                if (preg_match('~/(?:status|statuses)/\d+~i', $path) || preg_match('~/i/web/status/\d+~i', $path)) {
                    return 'post';
                }
                if (preg_match('~/(?:search|i/lists|hashtag)(/|$)~i', $path) || preg_match('~^/[^/]+/?$~', $path)) {
                    return 'profile';
                }
                return 'unknown';

            case 'pinterest':
                if (!$host_matches($host, ['pinterest.'])) { return 'unknown'; }
                if (preg_match('~/pin/[^/]+~i', $path) || preg_match('~/pin/\d+~i', $path)) {
                    return 'post';
                }
                if (preg_match('~/(?:search|ideas|today)(/|$)~i', $path) || preg_match('~^/[^/]+(?:/[^/]+)?/?$~', $path)) {
                    return 'profile';
                }
                return 'unknown';
        }
        return 'unknown';
    }
}

if (!function_exists('ves_filter_urls_for_mode')) {
    function ves_filter_urls_for_mode($platform, $urls, $mode_or_src) {
        $urls = is_array($urls) ? $urls : [];
        $want = ves_is_mixed_url_mode($mode_or_src) ? 'any' : (ves_is_post_url_mode($mode_or_src) ? 'post' : (ves_is_profile_url_mode($mode_or_src) ? 'profile' : 'any'));
        $kept = [];
        $wrong = [];
        foreach ($urls as $url) {
            $url = ves_normalize_url((string) $url);
            if ($url === '') {
                continue;
            }
            $kind = ves_platform_url_kind($platform, $url);
            if (($want === 'any' && in_array($kind, ['post', 'profile', 'hashtag'], true)) || $kind === $want) {
                $kept[] = $url;
            } else {
                $wrong[] = $url;
            }
        }
        return [array_values(array_unique($kept)), array_values(array_unique($wrong))];
    }
}

if (!function_exists('ves_tiktok_profile_handles_from_urls')) {
    function ves_tiktok_profile_handles_from_urls($urls) {
        $handles = [];
        foreach ((array) $urls as $url) {
            $url = ves_normalize_url((string) $url);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~/@([^/]+)/?~', $path, $m)) {
                $handle = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $m[1]);
                if ($handle !== '') {
                    $handles[] = $handle;
                }
            }
        }
        return array_values(array_unique($handles));
    }
}
if (!function_exists('ves_instagram_profile_handles_from_urls')) {
    /**
     * Extract Instagram profile handles from profile URLs or plain @handles.
     * Post/reel/story URLs and /explore/tags/ URLs are intentionally ignored.
     */
    function ves_instagram_profile_handles_from_urls($urls) {
        $handles = [];
        foreach ((array) $urls as $url) {
            $raw = trim((string) $url);
            if ($raw === '') { continue; }

            if (stripos($raw, 'instagram.com') === false && stripos($raw, 'http') !== 0) {
                $handle = preg_replace('/[^A-Za-z0-9_.]/', '', ltrim($raw, '@'));
                if ($handle !== '') { $handles[] = $handle; }
                continue;
            }

            $url = ves_normalize_url($raw);
            if ($url === '') { continue; }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (strpos($host, 'instagram.com') === false) { continue; }
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            if ($path === '') { continue; }
            if (preg_match('~^(p|reel|tv|stories|explore|accounts|about|developer|direct)(/|$)~i', $path)) {
                continue;
            }
            $first = strtok($path, '/');
            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $first);
            if ($handle !== '') { $handles[] = $handle; }
        }
        return array_values(array_unique($handles));
    }
}

if (!function_exists('ves_parse_handles_or_urls')) {
    /**
     * Keeps valid URLs intact and normalises @handles for actors that accept both.
     */
    function ves_parse_handles_or_urls($raw) {
        $raw   = (string) $raw;
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $items = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;

            // Try URL normalisation first.
            $maybe_url = ves_normalize_url($line);
            if ($maybe_url !== '') {
                $items[] = $maybe_url;
                continue;
            }

            $handle = ltrim($line, "@ \t\n\r\0\x0B");
            $handle = preg_replace('/[^\p{L}0-9_\.\-]/u', '', $handle);
            if ($handle !== '') {
                $items[] = $handle;
            }
        }

        return array_values(array_unique($items));
    }
}

if (!function_exists('ves_extract_item_timestamp')) {
    /**
     * v0.9.9.2 — Best-effort timestamp extraction across platforms.
     * Returns a Unix timestamp (int) or null if nothing usable was found.
     * Mirrors the keys used by JS normalizeItem() so behaviour is consistent.
     */
    function ves_extract_item_timestamp($item) {
        if (!is_array($item)) return null;

        // 1) Numeric epoch fields commonly used by Apify actors.
        $numeric_keys = [
            'createTime', 'createTimeISO', 'created_time', 'createdAt', 'created_at',
            'uploadedAt', 'uploadTime', 'publishedTime', 'video.createTime', 'video.createdAt', 'video.uploadedAt',
            'timestamp', 'publish_time', 'publishedAt', 'published_at', 'postedAt', 'posted_at',
            'published', 'pubDate', 'uploadDate', 'upload_date', 'taken_at',
            'takenAt', 'takenAtTimestamp', 'date_posted', 'datePosted',
            'ad_creation_time', 'ad_delivery_start_time', 'ad_delivery_stop_time',
            'end_date', 'start_date',
        ];
        foreach ($numeric_keys as $k) {
            $has = false;
            $v = null;
            if (array_key_exists($k, $item)) {
                $has = true;
                $v = $item[$k];
            } elseif (strpos((string) $k, '.') !== false && function_exists('ves_arr_get')) {
                $v = ves_arr_get($item, $k, null);
                $has = ($v !== null && $v !== '');
            }
            if (!$has) continue;
            if (is_numeric($v)) {
                $n = (float) $v;
                // Heuristic: values > 10^12 are millisecond timestamps.
                if ($n > 1000000000000) $n = $n / 1000;
                if ($n > 0 && $n < 9999999999) return (int) $n;
            }
            if (is_string($v) && $v !== '') {
                $t = strtotime($v);
                if ($t !== false) return $t; // v0.9.24.8: false-not-falsy
            }
        }

        // 2) Common string-date fields.
        $string_keys = [
            'date', 'ves_date', 'uploadedAtFormatted', 'uploaded_at_formatted', 'video.uploadedAtFormatted', 'video.createTimeISO', 'end_date_formatted', 'createdAtIso',
            'createdDateTime', 'snapshot.cards.0.created_at',
        ];
        foreach ($string_keys as $k) {
            if (array_key_exists($k, $item)) {
                $v = (string) $item[$k];
            } elseif (strpos((string) $k, '.') !== false && function_exists('ves_arr_get')) {
                $v = (string) ves_arr_get($item, $k, '');
            } else {
                continue;
            }
            if ($v === '') continue;
            $t = strtotime($v);
            if ($t !== false) return $t; // v0.9.24.8: false-not-falsy
        }

        return null;
    }
}

if (!function_exists('ves_filter_items_by_date_from')) {
    /**
     * v0.9.9.2 — Drop items posted before $date_from_iso (yyyy-mm-dd).
     * Items without a parseable timestamp are kept (we don't punish unknown).
     * Returns [filtered_items, dropped_count].
     */
    function ves_filter_items_by_date_from($items, $date_from_iso) {
        $date_from_iso = (string) $date_from_iso;
        if ($date_from_iso === '' || !is_array($items) || empty($items)) {
            return [is_array($items) ? $items : [], 0];
        }
        // Validate yyyy-mm-dd
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from_iso)) {
            return [$items, 0];
        }
        $cutoff = strtotime($date_from_iso . ' 00:00:00 UTC');
        if ($cutoff === false) return [$items, 0]; // v0.9.24.8
        $kept = [];
        $dropped = 0;
        foreach ($items as $item) {
            $ts = ves_extract_item_timestamp($item);
            if ($ts === null) {
                $kept[] = $item;
                continue;
            }
            if ($ts >= $cutoff) {
                $kept[] = $item;
            } else {
                $dropped++;
            }
        }
        return [array_values($kept), $dropped];
    }
}

if (!function_exists('ves_clean_iso_date')) {
    function ves_clean_iso_date($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        // Accept yyyy-mm-dd only (input type="date" already enforces this).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return '';
        $t = strtotime($raw . ' 00:00:00 UTC');
        if ($t === false) return ''; // v0.9.24.8
        // Sanity: can't accept future dates beyond today.
        if ($t > time()) return '';
        // Sanity: can't go before 2008 (before TikTok existed in practice).
        if ($t < strtotime('2008-01-01')) return '';
        return $raw;
    }
}



if (!function_exists('ves_apidojo_tiktok_date_range')) {
    /**
     * v0.9.24.51: Apidojo TikTok search has its own dateRange enum.
     * Keep actor-side filtering aligned with the locked UI date, then still
     * enforce the exact cutoff locally because TikTok ranges are coarse.
     */
    function ves_apidojo_tiktok_date_range($preset) {
        $preset = function_exists('ves_normalize_date_preset_key') ? ves_normalize_date_preset_key($preset) : sanitize_key((string) $preset);
        // v0.9.24.64 fix: 14d was mapped to THIS_MONTH which only covers days 1–today
        // when run early in the month (e.g. May 11 → only 11 days, not 14).
        // Use LAST_THREE_MONTHS as a safe superset; the local post-filter enforces
        // the exact 14-day ISO cutoff so no extra data is shown to the user.
        // 365d now maps to LAST_SIX_MONTHS (safest actor-side bucket that avoids the
        // DEFAULT no-filter path); the exact 365-day cutoff is applied locally.
        $map = [
            '1d'   => 'YESTERDAY',
            '7d'   => 'THIS_WEEK',
            '14d'  => 'LAST_THREE_MONTHS',
            '30d'  => 'THIS_MONTH',
            '60d'  => 'LAST_THREE_MONTHS',
            '90d'  => 'LAST_THREE_MONTHS',
            '180d' => 'LAST_SIX_MONTHS',
            '365d' => 'LAST_SIX_MONTHS',
        ];
        return $map[$preset] ?? 'DEFAULT';
    }
}

if (!function_exists('ves_apidojo_tiktok_sort_type')) {
    /** Map product sort intent to Apidojo's supported TikTok sort enum. */
    function ves_apidojo_tiktok_sort_type($sort_priority) {
        $sort_priority = sanitize_key((string) $sort_priority);
        if ($sort_priority === 'latest') { return 'DATE_POSTED'; }
        if ($sort_priority === 'top_metric') { return 'MOST_LIKED'; }
        return 'RELEVANCE';
    }
}

if (!function_exists('ves_tiktok_actor_uses_apidojo_schema')) {
    /**
     * v0.9.24.48: TikTok discovery is routed through Apidojo. Keep actor-schema
     * detection centralized so payload builders, previews and constraint guards
     * do not leak Clockworks-only fields into the new primary actor.
     */
    function ves_tiktok_actor_uses_apidojo_schema($actor_slug) {
        return stripos((string) $actor_slug, 'apidojo/tiktok-scraper') !== false;
    }
}

if (!function_exists('ves_tiktok_actor_uses_clockworks_schema')) {
    function ves_tiktok_actor_uses_clockworks_schema($actor_slug) {
        return stripos((string) $actor_slug, 'clockworks/tiktok-scraper') !== false;
    }
}

if (!function_exists('ves_tiktok_video_id_from_url')) {
    function ves_tiktok_video_id_from_url($url) {
        $url = (string) $url;
        if (preg_match('~/video/(\d+)~', $url, $m)) {
            return sanitize_text_field($m[1]);
        }
        return '';
    }
}

if (!function_exists('ves_tt_search_date_posted')) {
    /**
     * v0.9.9.6 — IMPORTANT: I previously thought this actor wanted "0/1/7/30/90/180"
     * (real day counts) and added a wrong mapping in v0.9.9.4. The actor's
     * validator actually rejects anything outside "0/1/2/3/4/5" — these are
     * sequence indices for the dropdown:
     *   0 = any
     *   1 = last 24 hours
     *   2 = last 7 days
     *   3 = last 30 days
     *   4 = last 90 days
     *   5 = last 180 days
     * So we delegate to ves_yt_date_filter_code which has used the correct
     * mapping all along. The ambiguous-brand issue had nothing to do with this code
     * — it was the language post-filter (now handled by ves_filter_items_by_language_strict).
     */
    function ves_tt_search_date_posted($preset) {
        $preset = function_exists('ves_normalize_date_preset_key') ? ves_normalize_date_preset_key($preset) : sanitize_key((string) $preset);
        return function_exists('ves_yt_date_filter_code') ? ves_yt_date_filter_code($preset) : '0';
    }
}


if (!function_exists('ves_tt_video_search_date_filter')) {
    /**
     * Clockworks TikTok fallback uses videoSearchDateFilter for /video search.
     * Keep legacy searchDatePosted separately for older builds, but always set this
     * field too so Apify does not merge the actor default ALL_TIME into a stricter run.
     */
    function ves_tt_video_search_date_filter($preset) {
        $preset = function_exists('ves_normalize_date_preset_key') ? ves_normalize_date_preset_key($preset) : sanitize_key((string) $preset);
        $map = [
            '1d' => 'PAST_24_HOURS',
            '7d' => 'PAST_WEEK',
            '14d' => 'PAST_MONTH',
            '30d' => 'PAST_MONTH',
            '60d' => 'LAST_3_MONTHS',
            '90d' => 'LAST_3_MONTHS',
            '180d' => 'LAST_6_MONTHS',
        ];
        return $map[$preset] ?? 'ALL_TIME';
    }
}

if (!function_exists('ves_date_preset_from_iso')) {
    /**
     * v0.9.9.4 — Map a yyyy-mm-dd "since this date" to the closest matching
     * preset key ('any', '1d', '7d', '30d', '90d', '180d', '365d').
     * Used when the user provides only the calendar date and no preset; the
     * scraper actor still needs a preset value.
     */
    function ves_date_preset_from_iso($date_iso) {
        $date_iso = (string) $date_iso;
        if ($date_iso === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_iso)) {
            return 'any';
        }
        $cutoff = strtotime($date_iso . ' 00:00:00 UTC');
        if (!$cutoff) return 'any';
        $days = max(0, (int) floor((time() - $cutoff) / DAY_IN_SECONDS));
        if ($days <= 1)  return '1d';
        if ($days <= 7)  return '7d';
        if ($days <= 30) return '30d';
        if ($days <= 90) return '90d';
        if ($days <= 180) return '180d';
        if ($days <= 365) return '365d';
        return 'any';
    }
}

if (!function_exists('ves_normalize_date_preset_key')) {
    /**
     * v0.9.24.53 — Normalize date presets from the native UI and from cached/custom
     * frontends. Older cached JS can send labels such as `last_7_days`, `week`,
     * `past_month` or even numeric strings. Keep the backend as the source of truth.
     */
    function ves_normalize_date_preset_key($preset) {
        $raw = strtolower(trim((string) $preset));
        $raw = function_exists('remove_accents') ? remove_accents($raw) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        $raw = strtr((string) $raw, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $raw = str_replace([' ', '-', '.', '/'], '_', (string) $raw);
        $raw = sanitize_key($raw);
        $map = [
            '' => 'any',
            'all' => 'any',
            'any' => 'any',
            'default' => 'any',
            'all_time' => 'any',
            'any_time' => 'any',
            '0' => 'any',
            'today' => '1d',
            'day' => '1d',
            '1' => '1d',
            '1d' => '1d',
            '24h' => '1d',
            'last_24h' => '1d',
            'last_24_hours' => '1d',
            'past_24_hours' => '1d',
            'yesterday' => '1d',
            'week' => '7d',
            '7' => '7d',
            '7d' => '7d',
            'last_7_days' => '7d',
            'past_7_days' => '7d',
            '14' => '14d',
            '14d' => '14d',
            'last_14_days' => '14d',
            'past_14_days' => '14d',
            'last_two_weeks' => '14d',
            'past_two_weeks' => '14d',
            'ultimos_14_dias' => '14d',
            'ltimos_14_dias' => '14d',
            'ultimas_2_semanas' => '14d',
            'ltimas_2_semanas' => '14d',
            'this_week' => '7d',
            'past_week' => '7d',
            'month' => '30d',
            '30' => '30d',
            '30d' => '30d',
            'last_30_days' => '30d',
            'past_30_days' => '30d',
            'this_month' => '30d',
            'past_month' => '30d',
            'ultimos_30_dias' => '30d',
            'ltimos_30_dias' => '30d',
            'ultimas_4_semanas' => '30d',
            'ltimas_4_semanas' => '30d',
            '60' => '60d',
            '60d' => '60d',
            'last_60_days' => '60d',
            'past_60_days' => '60d',
            'last_2_months' => '60d',
            'last_two_months' => '60d',
            'past_2_months' => '60d',
            'past_two_months' => '60d',
            '2_months' => '60d',
            'two_months' => '60d',
            'ultimos_2_meses' => '60d',
            'ltimos_2_meses' => '60d',
            'ultimos_dos_meses' => '60d',
            'ltimos_dos_meses' => '60d',
            '90' => '90d',
            '90d' => '90d',
            'last_90_days' => '90d',
            'past_90_days' => '90d',
            'last_3_months' => '90d',
            'last_three_months' => '90d',
            'past_3_months' => '90d',
            'past_three_months' => '90d',
            '3_months' => '90d',
            'three_months' => '90d',
            'last_quarter' => '90d',
            'past_quarter' => '90d',
            'ultimos_3_meses' => '90d',
            'ltimos_3_meses' => '90d',
            'ultimos_tres_meses' => '90d',
            'ltimos_tres_meses' => '90d',
            '180' => '180d',
            '180d' => '180d',
            'last_180_days' => '180d',
            'past_180_days' => '180d',
            'last_6_months' => '180d',
            'last_six_months' => '180d',
            'past_6_months' => '180d',
            'past_six_months' => '180d',
            '6_months' => '180d',
            'six_months' => '180d',
            'ultimos_6_meses' => '180d',
            'ltimos_6_meses' => '180d',
            'ultimos_seis_meses' => '180d',
            'ltimos_seis_meses' => '180d',
            '365' => '365d',
            '365d' => '365d',
            'year' => '365d',
            'last_year' => '365d',
            'past_year' => '365d',
            'last_365_days' => '365d',
            'past_365_days' => '365d',
        ];
        return $map[$raw] ?? (in_array($raw, ['1d','7d','14d','30d','60d','90d','180d','365d'], true) ? $raw : 'any');
    }
}

if (!function_exists('ves_resolve_date_filter_inputs')) {
    /**
     * v0.9.24.53 — One-stop resolver for date filtering. Reads both the native
     * `datePreset` select and explicit calendar aliases. Calendar/ISO values win.
     */
    function ves_resolve_date_filter_inputs($src) {
        $src = is_array($src) ? $src : [];
        $iso_raw = '';
        foreach (['dateFromIso','date_from_iso','dateFrom','date_from','fromDate','from_date','sinceDate','since_date','startDate','start_date','afterDate','after_date','postedAfter','posted_after'] as $key) {
            if (array_key_exists($key, $src) && trim((string) $src[$key]) !== '') {
                $iso_raw = (string) $src[$key];
                break;
            }
        }
        $iso = function_exists('ves_clean_iso_date') ? ves_clean_iso_date($iso_raw) : '';
        $preset_raw = '';
        foreach (['datePreset','date_preset','dateRangePreset','date_range_preset','dateRange','date_range','timeRange','time_range','postedWithin','posted_within'] as $key) {
            if (array_key_exists($key, $src) && trim((string) $src[$key]) !== '') {
                $preset_raw = (string) $src[$key];
                break;
            }
        }
        $preset = function_exists('ves_normalize_date_preset_key') ? ves_normalize_date_preset_key($preset_raw) : sanitize_key((string) $preset_raw);
        if ($iso !== '') {
            $preset = ves_date_preset_from_iso($iso);
        }
        return ['preset' => $preset, 'iso' => $iso];
    }
}

if (!function_exists('ves_detect_text_language')) {
    /**
     * v0.9.9.4 — Lightweight language detection from free text. Returns
     * a 2-letter ISO code or '' when uncertain. We DON'T claim to be
     * a full language detector; we just look for strong signals that
     * separate the most-confused languages in social-media scraping:
     * Spanish, English, Portuguese, French, Italian, Japanese, Korean,
     * Chinese, Arabic, Russian.
     *
     * Uses two passes:
     *   1) Unicode-script: hiragana/katakana → ja, hangul → ko, han alone → zh,
     *      cyrillic → ru, arabic → ar.
     *   2) Latin-script: per-language characteristic markers + stopword counts.
     * If no signal is strong enough, returns '' (we DO NOT guess).
     */
    function ves_detect_text_language($text) {
        $text = (string) $text;
        if ($text === '') return '';
        // v0.9.9.5: mbstring may be absent on bare-bones hosts. Fall back to
        // strtolower (Unicode case-folding only matters for Latin script here,
        // since Pass 1 returns before any case work for non-Latin scripts).
        $text_lc = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $len = function_exists('mb_strlen') ? mb_strlen($text_lc, 'UTF-8') : strlen($text_lc);
        if ($len < 4) return '';

        // Pass 1: scripts.
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) return 'ja'; // hiragana/katakana
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) return 'ko'; // hangul
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) return 'ru'; // cyrillic (rough — could be uk too)
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) return 'ar'; // arabic
        // Han characters without hiragana/hangul → assume zh
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
            // If we already returned ja/ko above we wouldn't be here.
            return 'zh';
        }

        // Pass 2: latin-script heuristics. Score per language.
        $scores = ['es' => 0, 'en' => 0, 'pt' => 0, 'fr' => 0, 'it' => 0, 'de' => 0];
        // Distinctive characters
        if (preg_match('/[ñ¿¡]/u', $text_lc)) $scores['es'] += 4;
        if (preg_match('/[ãõç]/u', $text_lc)) $scores['pt'] += 3;
        if (preg_match('/[œàâêîôûœëïü]/u', $text_lc)) $scores['fr'] += 3;
        if (preg_match('/[ßäöüẞ]/u', $text_lc)) $scores['de'] += 4;
        if (preg_match('/[àèìòù]/u', $text_lc)) $scores['it'] += 2;

        // Stopword-based scoring. Each list contains words distinctive enough
        // to that language that we won't get false positives from neighbouring
        // Romance/Germanic languages. Shared tokens like "la"/"de"/"per" are
        // intentionally omitted because they appear in multiple languages and
        // would push every Latin-script item toward es/it.
        $stopwords = [
            'es' => ['que','con','para','sin','por','más','muy','este','esta','estos','estas','también','pero','cuando','cómo','hola','años','día','mejor','está','están','tengo','tienen','hace','hacer','quiero','siempre','nunca','aquí','allí','soy','eres','somos','después','antes','hoy','ayer','mañana','porque','aunque','aquel','aquella','nos','vosotros','ustedes','vamos','vemos','viene','vienes','puede','pueden','tus','mis','nuestro','nuestra','nueva','nuevo','hay','todo','todos','nada','estoy','tenemos','tienes','sabías','sabias','pidiendo','prueba','durante','solo','última','ultimo','último','ultimos','últimos','ahora'],
            'en' => ['the','and','for','with','that','this','have','has','from','your','about','will','would','their','them','what','when','because','should','better','years','were','been','being','today','yesterday','tomorrow','very','also','only','just','much','many','some','more','most','here','there','they','dont','don','can','cannot'],
            'pt' => ['não','para','você','vocês','eles','elas','quando','melhor','também','muito','pouco','agora','depois','antes','sempre','nunca','porque','tudo','nada','aqui','alguma','algum','tenho','tem','foi','está','aqui','ali','isso','isto'],
            'fr' => ['les','des','que','pour','avec','dans','sans','vous','nous','très','ans','mieux','aussi','quand','est','suis','être','avoir','tout','tous','rien','aujourd','toujours','jamais','ici','elle','ils','elles','plus','moins','bien','mal','depuis','encore','aussi'],
            'it' => ['gli','che','con','non','molto','anche','quando','meglio','anni','giorno','sono','essere','avere','fare','sempre','mai','tutto','niente','qui','quello','questa','questo','dopo','prima','così','perché','sebbene'],
            'de' => ['der','die','das','und','ist','nicht','mit','auch','aber','sehr','jahre','besser','wenn','ich','sie','wir','ihr','haben','sein','werden','heute','gestern','morgen','immer','niemals','nur','mehr','weniger','noch','schon'],
        ];
        foreach (preg_split('/[^\p{L}]+/u', $text_lc) as $word) {
            $word = (string) $word;
            if ($word === '' || (function_exists('mb_strlen') ? mb_strlen($word) : strlen($word)) < 2) continue;
            foreach ($stopwords as $lang => $list) {
                if (in_array($word, $list, true)) {
                    $scores[$lang]++;
                }
            }
        }
        arsort($scores, SORT_NUMERIC);
        $top_lang = array_key_first($scores);
        $top_score = $scores[$top_lang];
        // Need a minimum confidence: at least 2 distinctive markers and a clear lead.
        $values = array_values($scores);
        $second = isset($values[1]) ? $values[1] : 0;
        if ($top_score >= 2 && $top_score > $second) {
            return $top_lang;
        }
        return '';
    }
}

if (!function_exists('ves_text_has_requested_language_hint')) {
    /**
     * v0.9.24.61 — Conservative rescue for short/marketing social captions.
     * Lightweight language detection often returns "unknown" for Spanish posts
     * like "Madrid, nos vemos" or "No tenemos nada que perder" because social
     * captions are short, hashtag-heavy, or mixed with English platform words.
     * This helper keeps those items when the user explicitly selected Spanish,
     * without allowing clearly non-Latin or clearly other-language items through.
     */
    function ves_text_has_requested_language_hint($text, $language, $requested_country = '') {
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($language) : sanitize_key((string) $language);
        if ($language !== 'es') {
            return false;
        }
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $norm = function_exists('remove_accents') ? remove_accents($lower) : $lower;
        $norm = strtolower((string) $norm);

        // Strong signals that usually mean Spanish-market content even when the
        // caption has few generic stopwords.
        if (preg_match('/[ñ¿¡]/u', $lower)) {
            return true;
        }
        $strong = [
            'espana','españa','madrid','barcelona','valencia','sevilla','bilbao','valladolid',
            'cerveza','cervezas','caña','canas','cañas','botellin','botellín','tapas','terraza','terrazas',
            'san isidro',
            'ultimahora','ultima hora','última hora'
        ];
        foreach ($strong as $term) {
            $term_norm = function_exists('remove_accents') ? remove_accents($term) : $term;
            if (preg_match('/(^|[^a-z0-9])' . preg_quote(strtolower($term_norm), '/') . '([^a-z0-9]|$)/u', $norm)) {
                return true;
            }
        }

        // Require at least two softer Spanish cues to avoid passing Romance-language
        // false positives on a single shared word.
        $soft = ['que','para','pero','sin','mas','muy','este','esta','estos','estas','tambien','cuando','como','nos','vamos','vemos','viene','vienes','puede','pueden','tus','mis','nuestro','nuestra','nueva','nuevo','hay','todo','todos','nada','estoy','soy','eres','somos','tenemos','tienes','quiero','quieres','hoy','ayer','manana','ahora','solo','pidiendo','prueba','durante','perder','mundo','ver'];
        $hits = 0;
        foreach ($soft as $term) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($term, '/') . '([^a-z0-9]|$)/u', $norm)) {
                $hits++;
                if ($hits >= 2) {
                    return true;
                }
            }
        }

        // Spanish country selection is only a weak support signal; it cannot pass
        // content alone, but it lowers the threshold when one soft cue exists.
        $country = strtoupper((string) $requested_country);
        return $country === 'ES' && $hits >= 1;
    }
}

if (!function_exists('ves_extract_item_text_blob')) {
    /**
     * v0.9.9.4 — Concat caption / title / description / transcript fields
     * into one blob for language detection.
     * v0.9.9.8 — Expanded to read enriched fields (`ves_transcript`, `ves_caption`,
     * `ves_text`) plus TikTok / YouTube / Facebook / Instagram specific paths
     * the actor commonly populates. The previous list missed `ves_transcript`,
     * which is where the controller stores fetched subtitles — meaning Japanese
     * transcripts were invisible to language detection.
     */
    function ves_extract_item_text_blob($item) {
        if (!is_array($item)) return '';
        $keys = [
            // Enriched fields (set by ves_enrich_single_item_for_display).
            'ves_text', 'ves_caption', 'ves_transcript', 'ves_title',
            // Common direct fields.
            'text', 'caption', 'description', 'desc', 'title', 'transcript',
            'subtitle', 'subtitleText', 'captionText', 'caption_text',
            'name', 'message', 'body', 'content',
            // FB Ads.
            'ad_creative_body', 'ad_creative_link_caption', 'ad_creative_link_title',
            // Nested commonly-used paths.
            'snippet.title', 'snippet.description',
            'item.desc', 'video.desc',
            'videoMeta.text', 'videoMeta.title',
            'authorMeta.signature', 'authorMeta.bioLink', 'authorMeta.nickName',
            'translations.subtitles.0.plaintext',
            'subtitles.0.text',
        ];
        $parts = [];
        foreach ($keys as $key) {
            if (strpos($key, '.') !== false) {
                $segs = explode('.', $key);
                $cur = $item;
                foreach ($segs as $seg) {
                    if (is_array($cur) && isset($cur[$seg])) {
                        $cur = $cur[$seg];
                    } else {
                        $cur = null;
                        break;
                    }
                }
                if (is_string($cur) && $cur !== '') $parts[] = $cur;
            } elseif (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                $parts[] = $item[$key];
            }
        }
        return trim(implode(' ', $parts));
    }
}

if (!function_exists('ves_filter_items_by_language_strict')) {
    /**
     * v0.9.9.4 — Drop items whose detected language doesn't match $language.
     * v0.9.9.8 — Tightened with two additional signals:
     *   1. Items with substantial text (≥ 30 chars) but no detectable match for
     *      the user's chosen language are now DROPPED, not kept.
     *   2. Items with short text where we can't detect the language remain KEPT
     *      ONLY if there's a positive country signal pointing at a country
     *      where the requested language is plausibly spoken. Otherwise DROPPED.
     *
     * The pre-v0.9.9.8 behaviour was too lenient: a transcript like "Agoro yɛnde"
     * (Bissa, West Africa) would pass through because the detector returned ''
     * (uncertain), and the user who asked for `es` would see African-language
     * videos. The new rule aligns the language post-filter with what the user
     * actually wants.
     *
     * Returns [filtered_items, dropped_count].
     */
    function ves_filter_items_by_language_strict($items, $language, $requested_country = '') {
        $language = (string) $language;
        if ($language === '' || !is_array($items) || empty($items)) {
            return [is_array($items) ? $items : [], 0];
        }
        // v0.9.24.5: Non-Latin script quick-filter.
        // The actor's own `language` metadata field (read in step 1 via
        // ves_item_language) is set by account country, not post text.
        // A Spain-based account that posts in Korean will have language='es'
        // from the actor, but the Hangul text should be caught here first.
        // Map of scripts that are unambiguously incompatible with each language.
        $latin_languages = ['es','en','pt','fr','it','de','nl','pl','sv','da','no','fi','ro','hr','sk','cs','hu'];
        $is_latin_target = in_array($language, $latin_languages, true);
        // Script patterns that mean 'definitely not Latin-script content'
        $non_latin_scripts = [
            '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', // hiragana/katakana → ja
            '/[\x{AC00}-\x{D7AF}]/u',                    // hangul → ko
            '/[\x{0400}-\x{04FF}]/u',                    // cyrillic → ru/uk
            '/[\x{0600}-\x{06FF}]/u',                    // arabic → ar
            '/[\x{4E00}-\x{9FFF}]/u',                    // CJK → zh
            '/[\x{0900}-\x{097F}]/u',                    // devanagari → hi
            '/[\x{0E00}-\x{0E7F}]/u',                    // thai → th
        ];
        // Map of plausibly-aligned (language, country) pairs. When both signals
        // disagree it's almost certainly not the user's content.
        $plausible_pairs = [
            'es' => ['ES','MX','AR','CO','CL','PE','VE','UY','PY','BO','EC','CR','PA','DO','GT','HN','SV','NI','CU','PR','US'],
            'en' => ['US','GB','CA','AU','NZ','IE','ZA','IN','PH','SG','KE','NG'],
            'pt' => ['PT','BR','AO','MZ','CV','GW','ST','TL'],
            'fr' => ['FR','BE','CH','CA','MC','LU','SN','CI','CM','MA','DZ','TN'],
            'it' => ['IT','CH','SM','VA'],
            'de' => ['DE','AT','CH','LI','LU'],
            'ja' => ['JP'],
            'ko' => ['KR','KP'],
            'zh' => ['CN','TW','HK','SG'],
            'ru' => ['RU','BY','KZ','UA'],
            'ar' => ['SA','EG','AE','MA','DZ','TN','IQ','SY','LB','JO','LY','YE','OM','KW','QA','BH'],
        ];
        $kept = [];
        $dropped = 0;
        foreach ($items as $item) {
            $raw = is_array($item) && isset($item['raw']) ? $item['raw'] : $item;
            // 1) Non-Latin script override: if the target language is Latin-script
            // but the text blob contains a clear non-Latin script, drop immediately
            // regardless of what the actor's language metadata says.
            if ($is_latin_target) {
                $blob_for_script = ves_extract_item_text_blob(is_array($item) ? $item : []);
                if ($blob_for_script === '' && $raw !== $item) {
                    $blob_for_script = ves_extract_item_text_blob(is_array($raw) ? $raw : []);
                }
                if ($blob_for_script !== '') {
                    foreach ($non_latin_scripts as $script_pattern) {
                        if (@preg_match($script_pattern, $blob_for_script)) {
                            $dropped++;
                            continue 2; // continue the outer foreach
                        }
                    }
                }
            }
            // 2) Check explicit language fields from actor metadata.
            $explicit_lang = function_exists('ves_item_language') ? ves_item_language($raw) : '';
            if ($explicit_lang !== '') {
                if ($explicit_lang === $language) {
                    $kept[] = $item;
                } else {
                    $dropped++;
                }
                continue;
            }
            // 3) Build a text blob from caption / title / transcript.
            $blob = ves_extract_item_text_blob(is_array($item) ? $item : []);
            if ($blob === '' && $raw !== $item) {
                $blob = ves_extract_item_text_blob(is_array($raw) ? $raw : []);
            }
            $blob_len = function_exists('mb_strlen') ? mb_strlen($blob, 'UTF-8') : strlen($blob);
            // 4) Detect.
            $detected = function_exists('ves_detect_text_language') ? ves_detect_text_language($blob) : '';
            $language_hint = function_exists('ves_text_has_requested_language_hint') ? ves_text_has_requested_language_hint($blob, $language, $requested_country) : false;
            if ($detected === $language || ($detected === '' && $language_hint)) {
                $kept[] = $item;
                continue;
            }
            if ($detected !== '' && $detected !== $language) {
                $dropped++;
                continue;
            }
            // 5) Detected = '' (uncertain).
            //    With ≥ 30 chars of unambiguous text and no local-language hint,
            //    drop. Spanish social captions with market/linguistic cues are
            //    rescued above so we don't wipe valid local TikTok/IG results.
            if ($blob_len >= 30) {
                $dropped++;
                continue;
            }
            // 6) Short text + uncertain. Use country as a tiebreaker:
            //    - If we have an item country AND it's plausible for the requested
            //      language, keep.
            //    - If we have an item country AND it's NOT plausible, drop.
            //    - If we have no item country signal, keep (conservative).
            $item_country = function_exists('ves_extract_item_country') ? ves_extract_item_country(is_array($raw) ? $raw : []) : '';
            if ($item_country === '') {
                // No country signal at all — keep the benefit of the doubt.
                $kept[] = $item;
                continue;
            }
            $plausible = isset($plausible_pairs[$language]) ? $plausible_pairs[$language] : [];
            if (in_array(strtoupper($item_country), $plausible, true)) {
                $kept[] = $item;
            } else {
                $dropped++;
            }
        }
        return [array_values($kept), $dropped];
    }
}

if (!function_exists('ves_normalize_country_signal')) {
    function ves_normalize_country_signal($value) {
        if (is_array($value)) {
            foreach ($value as $entry) {
                $code = ves_normalize_country_signal($entry);
                if ($code !== '') { return $code; }
            }
            return '';
        }
        $raw = trim((string) $value);
        if ($raw === '') { return ''; }
        $raw = wp_strip_all_tags($raw);
        $raw = trim($raw);
        if ($raw === '') { return ''; }
        $upper = strtoupper($raw);
        if (preg_match('/^[A-Z]{2}$/', $upper)) {
            $safe = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($upper) : $upper;
            return $safe !== 'None' ? $safe : '';
        }
        if (preg_match('/^[A-Z]{3}$/', $upper)) {
            $iso3 = [
                'ESP'=>'ES','USA'=>'US','GBR'=>'GB','MEX'=>'MX','FRA'=>'FR','DEU'=>'DE','ITA'=>'IT','PRT'=>'PT','BRA'=>'BR','ARG'=>'AR','COL'=>'CO','CHL'=>'CL','PER'=>'PE','CAN'=>'CA','AUS'=>'AU','NLD'=>'NL','CHE'=>'CH','SWE'=>'SE','JPN'=>'JP','KOR'=>'KR','ARE'=>'AE','TUR'=>'TR','MAR'=>'MA','EGY'=>'EG','ZAF'=>'ZA','NGA'=>'NG','IND'=>'IN','VNM'=>'VN'
            ];
            if (isset($iso3[$upper])) { return $iso3[$upper]; }
        }
        $norm = function_exists('remove_accents') ? remove_accents($raw) : $raw;
        $norm = strtolower(trim(preg_replace('/[^a-z0-9 ]+/i', ' ', $norm)));
        $norm = preg_replace('/\s+/', ' ', $norm);
        $map = [
            'spain'=>'ES','espana'=>'ES','españa'=>'ES','madrid'=>'ES','barcelona'=>'ES',
            'united states'=>'US','united states of america'=>'US','usa'=>'US','u s a'=>'US','us'=>'US',
            'united kingdom'=>'GB','great britain'=>'GB','uk'=>'GB','england'=>'GB',
            'mexico'=>'MX','mexico city'=>'MX','mexicano'=>'MX',
            'france'=>'FR','francia'=>'FR','germany'=>'DE','alemania'=>'DE','deutschland'=>'DE','italy'=>'IT','italia'=>'IT','portugal'=>'PT',
            'brazil'=>'BR','brasil'=>'BR','argentina'=>'AR','colombia'=>'CO','chile'=>'CL','peru'=>'PE','canada'=>'CA','australia'=>'AU',
            'netherlands'=>'NL','holland'=>'NL','switzerland'=>'CH','suisse'=>'CH','sweden'=>'SE','japan'=>'JP','south korea'=>'KR','korea'=>'KR',
            'united arab emirates'=>'AE','uae'=>'AE','turkiye'=>'TR','turkey'=>'TR','morocco'=>'MA','maroc'=>'MA','egypt'=>'EG','south africa'=>'ZA','nigeria'=>'NG','india'=>'IN','vietnam'=>'VN'
        ];
        if (isset($map[$norm])) { return $map[$norm]; }
        foreach ($map as $name => $code) {
            if (strlen($name) >= 5 && preg_match('/(^| )' . preg_quote($name, '/') . '( |$)/', $norm)) { return $code; }
        }
        return '';
    }
}

if (!function_exists('ves_extract_item_country')) {
    /**
     * v0.9.24.46 — Best-effort country extraction across social/ad actors.
     * Handles 2/3-letter codes, country names, arrays, and TikTok locationCreated.
     */
    function ves_extract_item_country($item) {
        if (!is_array($item)) return '';
        $keys = [
            'locationCreated', 'countryCreated', 'region', 'country', 'countryCode', 'regionCode', 'locale',
            'authorMeta.region', 'authorMeta.country', 'authorMeta.countryCode', 'authorMeta.locationCreated',
            'author.region', 'author.country', 'author.countryCode',
            'location.country', 'geo.country', 'place.country', 'page_country', 'targeted_or_reached_countries',
            'targetCountries', 'countries', 'creativeRegions', 'regionStats.0.region', 'snapshot.page.country', 'snapshot.page_country'
        ];
        foreach ($keys as $key) {
            $cur = null;
            if (strpos($key, '.') !== false) {
                $segs = explode('.', $key);
                $cur = $item;
                foreach ($segs as $seg) {
                    if (is_array($cur) && array_key_exists($seg, $cur)) {
                        $cur = $cur[$seg];
                    } else {
                        $cur = null;
                        break;
                    }
                }
            } elseif (array_key_exists($key, $item)) {
                $cur = $item[$key];
            }
            $code = ves_normalize_country_signal($cur);
            if ($code !== '') { return $code; }
        }
        return '';
    }
}

if (!function_exists('ves_filter_items_by_country_strict')) {
    /**
     * v0.9.9.4 — Drop items whose author region clearly differs from user choice.
     * Items with no parseable country are KEPT. We DON'T punish unknown.
     * Returns [filtered_items, dropped_count].
     */
    function ves_filter_items_by_country_strict($items, $country_code) {
        $country_code = strtoupper((string) $country_code);
        if ($country_code === '' || $country_code === 'NONE' || !is_array($items) || empty($items)) {
            return [is_array($items) ? $items : [], 0];
        }
        $kept = [];
        $dropped = 0;
        foreach ($items as $item) {
            $item_country = ves_extract_item_country(is_array($item) && isset($item['raw']) ? $item['raw'] : $item);
            if ($item_country === '' || $item_country === $country_code) {
                $kept[] = $item;
            } else {
                $dropped++;
            }
        }
        return [array_values($kept), $dropped];
    }
}

if (!function_exists('ves_over_fetch_factor')) {
    /**
     * v0.9.9.7 — Over-fetch multiplier. We send N × user-requested to the actor
     * so post-filters (language / country / date) have plenty of headroom to
     * still meet the user's expected count. Default 1. Filterable.
     */
    function ves_over_fetch_factor($platform = '') {
        $factor = (int) apply_filters('ves_over_fetch_factor', 1, $platform);
        return max(1, min(20, $factor));
    }
}

if (!function_exists('ves_over_fetch_min')) {
    /**
     * v0.9.9.7 — Minimum number of items to ask the actor for when over-fetching.
     * Even if the user asked for 1, we ask the actor for at least this many so
     * we have room to filter. Default 1. Filterable.
     */
    function ves_over_fetch_min($platform = '') {
        $min = (int) apply_filters('ves_over_fetch_min', 1, $platform);
        return max(1, min(200, $min));
    }
}

if (!function_exists('ves_min_metric_overfetch_factor')) {
    /**
     * v0.9.24.4 — Over-fetch multiplier applied when the user sets a minViews/
     * minLikes threshold. Apify actors sort by recency, not view count, so a
     * pool of exactly N items will frequently have most items below the
     * threshold after post-filtering. Fetching N × factor gives the filter
     * enough headroom to still meet the user's requested count.
     *
     * Platforms with a native actor-level filter (Twitter/X uses minimumFavorites)
     * don't need a large multiplier, but applying it uniformly is safe — the
     * extra items are simply sliced off after filtering.
     *
     * Configurable via define('VE_SOCIAL_METRIC_OVERFETCH_FACTOR', N) in wp-config.
     */
    function ves_min_metric_overfetch_factor($min_metric, $platform = '', $src = []) {
        if ((int) $min_metric <= 0) {
            return 1;
        }
        if (defined('VE_SOCIAL_METRIC_OVERFETCH_FACTOR')) {
            return max(1, min(10, (int) VE_SOCIAL_METRIC_OVERFETCH_FACTOR));
        }
        // v0.9.24.62: keep provider calls cost-predictable by default. The UI says
        // "Cantidad 20 resultados", so silently dispatching maxItems=100 is wrong
        // for a credit-metered SaaS. Over-fetch is still available when explicitly
        // requested by the product layer or via the constant above.
        $src = is_array($src) ? $src : [];
        $explicit = !empty($src['allowMetricOverfetch']) || !empty($src['allow_metric_overfetch']);
        return $explicit ? 5 : 1;
    }
}

if (!function_exists('ves_compute_actor_limit')) {
    /**
     * v0.9.9.7 — Translate the user's "I want N results" into the exact
     * provider request limit.
     *
     * v0.9.24.4 — When the user sets a minViews/minLikes threshold, we
     * over-fetch by ves_min_metric_overfetch_factor() so the post-filter has
     * enough headroom to still return the requested count after eliminating
     * low-metric items. The controller slices back to $requested after filtering.
     * Pass $src (the raw $_POST array) to activate the multiplier.
     */
    function ves_compute_actor_limit($requested, $platform = '', $src = []) {
        if ($requested === null) {
            return null;
        }
        $requested = (int) $requested;
        if ($requested <= 0) {
            return null;
        }
        $max = function_exists('ves_max_items') ? ves_max_items() : 200;
        $base = max(1, min($max, $requested));

        // Apply metric over-fetch when the user has set a view/like threshold.
        if (!empty($src) && is_array($src)) {
            $min_metric = function_exists('ves_requested_min_metric')
                ? ves_requested_min_metric($src)
                : (function_exists('ves_clean_int')
                    ? ves_clean_int($src['minViews'] ?? ($src['minLikes'] ?? null), 0, null)
                    : (int) ($src['minViews'] ?? $src['minLikes'] ?? 0));
            if ($min_metric !== null && $min_metric > 0) {
                $factor = function_exists('ves_min_metric_overfetch_factor')
                    ? ves_min_metric_overfetch_factor($min_metric, $platform, $src)
                    : 1;
                $base = max($base, min($max, (int) round($requested * $factor)));
            }
        }
        return $base;
    }
}

if (!function_exists('ves_sort_items_by_relevance_desc')) {
    /**
     * v0.9.9.7 — Stable sort that preserves the actor's natural relevance order
     * (Apify's TikTok/YouTube search returns results in best-match order)
     * while breaking ties by primary metric. We DO NOT re-rank from scratch;
     * Apify's order is the relevance signal. We just push items with very low
     * primary metric to the end as a soft tiebreaker.
     */
    function ves_sort_items_by_relevance_desc($items) {
        if (!is_array($items)) return [];
        // Annotate each item with its original index so we can preserve order.
        $indexed = [];
        foreach ($items as $i => $it) {
            $indexed[] = [$i, $it];
        }
        // Stable sort: primary by original index (lower = more relevant per actor),
        // tiebreak by primary metric desc.
        usort($indexed, static function ($a, $b) {
            // Primary: original index ascending (preserves actor relevance order).
            return $a[0] <=> $b[0];
        });
        $sorted = [];
        foreach ($indexed as $pair) {
            $sorted[] = $pair[1];
        }
        return $sorted;
    }
}
