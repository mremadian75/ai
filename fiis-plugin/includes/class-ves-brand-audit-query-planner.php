<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Brand_Audit_Query_Planner {
    public static function build($request) {
        $request = is_array($request) ? $request : [];
        $queries = self::build_query_plan($request);
        $payloads = self::build_payloads($request, $queries);
        $sources = self::build_sources($request, $payloads);
        $source_preview = self::source_preview($sources, $request);
        $query_plan = [
            'brand_core_queries' => $queries['brand_core_queries'],
            'search_intent_queries' => $queries['search_intent_queries'],
            'review_queries' => $queries['review_queries'],
            'social_queries' => $queries['social_queries'],
            'competitor_queries' => $queries['competitor_queries'],
            'source_queries' => $queries['source_queries'],
            'source_preview' => $source_preview,
        ];
        return [
            'request' => $request,
            'query_plan' => $query_plan,
            'payloads' => $payloads,
            'sources' => $sources,
            'source_preview' => $source_preview,
            'request_hash' => self::request_hash($request, $query_plan),
        ];
    }

    private static function unique($items, $limit = 40) {
        $out = [];
        foreach ((array) $items as $item) {
            $item = trim((string) $item);
            if ($item === '') { continue; }
            $key = function_exists('mb_strtolower') ? mb_strtolower($item) : strtolower($item);
            if (!isset($out[$key])) { $out[$key] = $item; }
            if (count($out) >= $limit) { break; }
        }
        return array_values($out);
    }

    // Uses VES_Config::brand_audit_max_competitors_standard() / deep caps via brand_audit_max_competitors_for_depth().
    private static function request_competitors($request) {
        $request = is_array($request) ? $request : [];
        $depth = sanitize_key((string) ($request['depth'] ?? 'standard'));
        $max = class_exists('VES_Config') && method_exists('VES_Config', 'brand_audit_max_competitors_for_depth') ? VES_Config::brand_audit_max_competitors_for_depth($depth) : ($depth === 'deep' ? 5 : 3);
        $raw = $request['competitors'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n|,/', $raw);
        }
        $out = [];
        foreach ((array) $raw as $competitor) {
            $name = self::normalize_entity_name($competitor);
            if ($name === '') { continue; }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            if (isset($out[$key])) { continue; }
            $out[$key] = $name;
            if (count($out) >= $max) { break; }
        }
        return array_values($out);
    }

    private static function estimated_provider_cost($source_count, $heavy_count) {
        $source_count = max(0, (int) $source_count);
        $heavy_count = max(0, (int) $heavy_count);
        if (class_exists('VES_Config') && method_exists('VES_Config', 'apify_estimate_provider_cost')) {
            return (float) VES_Config::apify_estimate_provider_cost($source_count, $heavy_count);
        }
        if (class_exists('VES_Config') && method_exists('VES_Config', 'estimated_cost_usd_per_run')) {
            return (float) round($heavy_count * VES_Config::estimated_cost_usd_per_run('apify') + max(0, $source_count - $heavy_count) * VES_Config::estimated_cost_usd_per_run('serpapi'), 4);
        }
        return 0.0;
    }

    private static function source_preview($sources, $request) {
        $sources = is_array($sources) ? $sources : [];
        $source_count = count($sources);
        $heavy = 0;
        $optional = 0;
        $phase_counts = [];
        $competitor_expansion = 0;
        $source_rows = [];
        foreach ($sources as $key => $source) {
            if (!is_array($source)) { continue; }
            $phase = max(1, (int) ($source['phase'] ?? 1));
            $phase_counts[$phase] = ($phase_counts[$phase] ?? 0) + 1;
            $is_heavy = !empty($source['is_heavy_source']) || in_array(($source['source_type'] ?? ''), ['social_apify'], true);
            if ($is_heavy) { $heavy++; }
            if (!empty($source['optional'])) { $optional++; }
            if (($source['entity_role'] ?? '') === 'competitor') { $competitor_expansion++; }
            $source_rows[] = [
                'source_key' => sanitize_key((string) $key),
                'label' => sanitize_text_field((string) ($source['label'] ?? $key)),
                'phase' => $phase,
                'heavy' => (bool) $is_heavy,
                'optional' => !empty($source['optional']),
                'entity_role' => sanitize_key((string) ($source['entity_role'] ?? '')),
            ];
        }
        ksort($phase_counts);
        $estimated_external_provider_cost = self::estimated_provider_cost($source_count, $heavy);
        $max_heavy_per_phase = class_exists('VES_Config') ? VES_Config::brand_audit_max_heavy_sources_per_phase() : 2;
        $warning = '';
        if ($source_count > 30 || $heavy > 10) {
            $warning = 'High source count. Reduce competitors or disable competitor social expansion before running.';
        }
        return [
            'source_count' => $source_count,
            'heavy_source_count' => $heavy,
            'estimated_external_provider_cost' => $estimated_external_provider_cost,
            'internal_credit_estimate_hint' => 'credits are metered separately from provider cost',
            'competitor_expansion_count' => $competitor_expansion,
            'optional_source_count' => $optional,
            'max_heavy_sources_per_phase' => $max_heavy_per_phase,
            'phase_counts' => $phase_counts,
            'warning' => $warning,
            'source_count_limit_exceeded' => ($source_count > 30 || $heavy > 10),
            'sources' => $source_rows,
        ];
    }

    private static function normalize_entity_name($name) {
        $name = trim(wp_strip_all_tags((string) $name));
        $name = preg_replace('/\s+/', ' ', $name);
        return function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
    }

    private static function entity_key($role, $name, $idx = 0) {
        $base = sanitize_title(self::normalize_entity_name($name));
        if ($base === '') { $base = 'entity_' . max(1, (int) $idx); }
        $role = sanitize_key((string) $role);
        return substr($role . '_' . $base, 0, 80);
    }

    private static function build_query_plan($request) {
        $brand = trim((string) ($request['brand_name'] ?? ''));
        $country = trim((string) ($request['country'] ?? 'ES'));
        $industry = trim((string) ($request['industry'] ?? ''));
        $competitors = self::request_competitors($request);
        $hashtags = array_values((array) ($request['hashtags'] ?? []));
        $brand_keywords = array_values((array) ($request['brand_keywords'] ?? []));

        $brand_core = self::unique(array_merge([$brand, $brand . ' ' . $country], $brand_keywords), 20);
        $search_intent = self::unique([
            $brand . ' opiniones',
            $brand . ' reseñas',
            $brand . ' precio',
            $brand . ' donde comprar',
            $brand . ' cerca de mi',
            $brand . ' campaña',
            $brand . ' anuncios',
        ], 20);
        $review_queries = self::unique([$brand . ' reviews', $brand . ' opiniones ' . $country], 12);
        $social_queries = self::unique(array_merge([
            $brand . ' Instagram',
            $brand . ' TikTok',
            $brand . ' YouTube',
            $brand . ' X Twitter',
            $brand . ' Facebook',
            $brand . ' LinkedIn',
        ], $hashtags), 30);
        $competitor_queries = [];
        foreach ($competitors as $competitor) {
            $competitor = self::normalize_entity_name($competitor);
            if ($competitor === '') { continue; }
            $competitor_queries[] = $brand . ' vs ' . $competitor;
            $competitor_queries[] = $competitor . ' opiniones';
            $competitor_queries[] = $competitor . ' campaña';
            $competitor_queries[] = $competitor . ' Instagram';
            $competitor_queries[] = $competitor . ' TikTok';
            $competitor_queries[] = $competitor . ' YouTube';
            $competitor_queries[] = $competitor . ' web oficial ' . $country;
            $competitor_queries[] = $competitor . ' productos ' . $country;
        }
        if ($industry !== '') {
            $competitor_queries[] = 'best ' . $industry . ' brands ' . $country;
            $competitor_queries[] = $industry . ' competitors ' . $country;
            $competitor_queries[] = $industry . ' Instagram TikTok YouTube ' . $country;
        }

        return [
            'brand_core_queries' => $brand_core,
            'search_intent_queries' => $search_intent,
            'review_queries' => $review_queries,
            'social_queries' => $social_queries,
            'competitor_queries' => self::unique($competitor_queries, 40),
            'source_queries' => self::unique(array_merge($brand_core, $search_intent, $review_queries, $social_queries), 50),
        ];
    }

    private static function build_payloads($request, $queries) {
        $depth = sanitize_key((string) ($request['depth'] ?? 'standard'));
        $country = strtoupper(sanitize_text_field((string) ($request['country'] ?? 'ES')));
        $country_lc = strtolower($country === 'NONE' ? '' : $country);
        $language = sanitize_key((string) ($request['language'] ?? 'es'));
        $brand = trim((string) ($request['brand_name'] ?? ''));
        $competitors = self::request_competitors($request);
        $trend_terms = self::unique(array_merge([$brand], array_slice($competitors, 0, $depth === 'deep' ? 4 : 2)), 5);
        $search_limit = $depth === 'light' ? 4 : ($depth === 'deep' ? 10 : 7);
        $news_query = trim($brand . ' ' . ($country !== '' && $country !== 'NONE' ? $country : ''));

        return [
            'website_crawler' => !empty($request['website_url']) ? [
                'start_urls' => $request['website_url'],
                'max_crawl_pages' => $depth === 'deep' ? 12 : ($depth === 'light' ? 4 : 7),
                'max_crawl_depth' => $depth === 'deep' ? 2 : 1,
                'use_sitemaps' => true,
                'crawler_type' => 'playwright:adaptive',
            ] : [],
            'google_search' => [
                'query' => implode("\n", array_slice($queries['source_queries'], 0, $search_limit)),
                'country_code' => $country_lc,
                'search_language' => $language,
                'max_pages' => $depth === 'deep' ? 2 : 1,
                'quick_date_range' => '',
            ],
            'google_news' => [
                'query' => $news_query !== '' ? $news_query : $brand,
                'gl' => $country_lc,
                'hl' => $language,
                'lr' => $language !== '' ? 'lang_' . $language : '',
                'time_period' => $depth === 'light' ? 'last_month' : 'last_year',
                'max_items' => $depth === 'deep' ? 50 : 30,
            ],
            'google_trends' => [
                'search_terms' => implode("\n", $trend_terms),
                'geo' => $country !== 'NONE' ? $country : '',
                'time_range' => $depth === 'light' ? 'today 1-m' : 'today 3-m',
                'category' => '',
                'max_items' => $depth === 'deep' ? 50 : 25,
            ],
            'social_platforms' => self::build_social_payloads($request, $queries),
            'social_public_search' => self::build_social_public_search_payloads($request),
            'competitor_search' => !empty($queries['competitor_queries']) ? [
                'query' => implode("\n", array_slice($queries['competitor_queries'], 0, $depth === 'deep' ? 16 : 10)),
                'country_code' => $country_lc,
                'search_language' => $language,
                'max_pages' => $depth === 'deep' ? 2 : 1,
                'quick_date_range' => '',
            ] : [],
            'maps_reviews' => !empty($request['google_maps_url']) ? [
                'google_maps_url' => $request['google_maps_url'],
                'search_terms' => $brand,
                'location_query' => self::country_location_label($country),
                'country_code' => $country_lc,
                'language' => $language,
                'max_places' => $depth === 'deep' ? 5 : ($depth === 'light' ? 2 : 3),
                'scrape_place_detail_page' => true,
                'max_reviews' => $depth === 'deep' ? 80 : ($depth === 'light' ? 20 : 40),
                'max_images' => 0,
            ] : [],
        ];
    }

    private static function platform_limit($depth, $platform) {
        $depth = sanitize_key((string) $depth);
        // Brand Audit is comparative: every usable social source should try to collect at least 20 recent items.
        $base = $depth === 'deep' ? 35 : 20;
        if ($platform === 'twitter') { $base = $depth === 'deep' ? 40 : 24; }
        return max(20, (int) $base);
    }

    private static function platform_supports_keyword_search($platform) {
        $platform = sanitize_key((string) $platform);
        return in_array($platform, ['youtube', 'tiktok', 'instagram', 'twitter'], true);
    }

    private static function social_entities($request) {
        $entities = [];
        $brand = self::normalize_entity_name($request['brand_name'] ?? '');
        if ($brand !== '') {
            $entities[] = [
                'role' => 'brand',
                'name' => $brand,
                'key' => self::entity_key('brand', $brand, 0),
                'urls' => [
                    'instagram' => trim((string) ($request['instagram_url'] ?? '')),
                    'tiktok' => trim((string) ($request['tiktok_url'] ?? '')),
                    'youtube' => trim((string) ($request['youtube_url'] ?? '')),
                    'twitter' => trim((string) ($request['twitter_url'] ?? '')),
                    'facebook' => trim((string) ($request['facebook_url'] ?? '')),
                ],
            ];
        }
        $idx = 1;
        foreach (self::request_competitors($request) as $competitor) {
            $name = self::normalize_entity_name($competitor);
            if ($name === '') { continue; }
            $entities[] = [
                'role' => 'competitor',
                'name' => $name,
                'key' => self::entity_key('competitor', $name, $idx),
                'urls' => self::competitor_urls_from_value($name),
            ];
            $idx++;
        }
        return $entities;
    }

    private static function competitor_urls_from_value($value) {
        $value = trim((string) $value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) { return []; }
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $urls = [];
        if (strpos($host, 'instagram.com') !== false) { $urls['instagram'] = $value; }
        elseif (strpos($host, 'tiktok.com') !== false) { $urls['tiktok'] = $value; }
        elseif (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) { $urls['youtube'] = $value; }
        elseif (strpos($host, 'x.com') !== false || strpos($host, 'twitter.com') !== false) { $urls['twitter'] = $value; }
        elseif (strpos($host, 'facebook.com') !== false || strpos($host, 'fb.com') !== false) { $urls['facebook'] = $value; }
        return $urls;
    }

    private static function social_target_lines($request, $platform, $entity = null) {
        $entity = is_array($entity) ? $entity : [];
        $name = self::normalize_entity_name($entity['name'] ?? ($request['brand_name'] ?? ''));
        $role = sanitize_key((string) ($entity['role'] ?? 'brand'));
        $country = trim((string) ($request['country'] ?? ''));
        $industry = trim((string) ($request['industry'] ?? ''));
        $hashtags = $role === 'brand' ? array_values((array) ($request['hashtags'] ?? [])) : [];
        $lines = [];
        if ($name !== '') { $lines[] = $name; }
        if ($name !== '' && $country !== '' && strtoupper($country) !== 'NONE') { $lines[] = $name . ' ' . $country; }
        if ($name !== '' && $industry !== '') { $lines[] = $name . ' ' . $industry; }
        foreach ($hashtags as $tag) { $lines[] = (string) $tag; }
        return implode("\n", self::unique($lines, $platform === 'twitter' ? 8 : 6));
    }

    private static function build_social_payload($platform, $request, $entity = null, $variant = 'primary') {
        $platform = sanitize_key((string) $platform);
        $entity = is_array($entity) ? $entity : [];
        $depth = sanitize_key((string) ($request['depth'] ?? 'standard'));
        $role = sanitize_key((string) ($entity['role'] ?? 'brand'));
        $name = self::normalize_entity_name($entity['name'] ?? ($request['brand_name'] ?? ''));
        if ($name === '') { return []; }
        $entity_key = sanitize_key((string) ($entity['key'] ?? self::entity_key($role, $name, 0)));
        $urls = is_array($entity['urls'] ?? null) ? $entity['urls'] : [];
        $url = trim((string) ($urls[$platform] ?? ''));
        $variant = sanitize_key((string) $variant);
        $mode = ($variant === 'profile' && $url !== '') ? 'profile_url' : 'search';
        $targets = $mode === 'profile_url' ? $url : self::social_target_lines($request, $platform, $entity);
        if ($targets === '') { return []; }
        if ($mode === 'search' && !self::platform_supports_keyword_search($platform)) { return []; }
        $src = [
            'mode' => $mode,
            'targets' => $targets,
            'limit' => self::platform_limit($depth, $platform),
            'country' => sanitize_text_field((string) ($request['country'] ?? 'ES')),
            'language' => sanitize_key((string) ($request['language'] ?? 'es')),
            'datePreset' => $depth === 'deep' ? '90d' : ($depth === 'light' ? '60d' : '90d'),
            'searchContext' => trim((string) ($request['industry'] ?? '') . ' ' . (string) ($request['audit_goal'] ?? '')),
            'sortPriority' => $mode === 'profile_url' ? 'latest' : 'relevance',
            'brandAuditEntityRole' => $role,
            'brandAuditEntityName' => $name,
            'brandAuditSourceVariant' => $variant,
            'includeTranscript' => false,
            'brandAuditCompactSearch' => true,
        ];
        if (function_exists('ves_build_platform_input')) {
            $input = ves_build_platform_input($platform, $src);
        } else {
            $input = self::fallback_social_input($platform, $src);
        }
        $input = self::compact_social_input($platform, is_array($input) ? $input : [], $mode, $role);
        if (function_exists('ves_validate_platform_input') && !ves_validate_platform_input($platform, $input)) {
            return [];
        }
        return [
            'platform' => $platform,
            'mode' => $mode,
            'targets' => $targets,
            'actor_slug' => class_exists('VES_Config') ? VES_Config::get_actor_slug($platform) : '',
            'input' => is_array($input) ? $input : [],
            'source_request' => $src,
            'entity_role' => $role,
            'entity_name' => $name,
            'entity_key' => $entity_key,
            'variant' => $variant,
            'phase' => $role === 'competitor' ? 4 : ($variant === 'profile' ? 2 : 3),
            'phase_label' => $role === 'competitor' ? 'competitor_public_social_search' : ($variant === 'profile' ? 'owned_social_urls' : 'public_brand_mentions'),
            'optional' => $role === 'competitor',
        ];
    }

    private static function compact_social_input($platform, $input, $mode, $role) {
        $platform = sanitize_key((string) $platform);
        $mode = sanitize_key((string) $mode);
        $role = sanitize_key((string) $role);
        $input = is_array($input) ? $input : [];
        if ($mode !== 'search') { return $input; }
        $is_competitor = $role === 'competitor';
        $limits = [
            'youtube' => $is_competitor ? 3 : 5,
            'tiktok' => $is_competitor ? 4 : 6,
            'twitter' => $is_competitor ? 5 : 8,
            'instagram' => $is_competitor ? 4 : 7,
        ];
        $limit = (int) ($limits[$platform] ?? 6);
        foreach (['searchQueries', 'searchTerms', 'directUrls', 'hashtags'] as $key) {
            if (!empty($input[$key]) && is_array($input[$key])) {
                $input[$key] = array_slice(array_values(array_unique($input[$key])), 0, $limit);
            }
        }
        return $input;
    }

    private static function fallback_social_input($platform, $src) {
        $targets = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($src['targets'] ?? '')))));
        $limit = max(20, (int) ($src['limit'] ?? 20));
        switch ($platform) {
            case 'youtube': return ['searchQueries' => $targets, 'maxResults' => $limit];
            case 'tiktok':
                return [
                    'keywords' => $targets,
                    'maxItems' => $limit,
                    'sortType' => 'RELEVANCE',
                    'dateRange' => 'LAST_THREE_MONTHS',
                    'includeSearchKeywords' => false,
                    'customMapFunction' => '(object) => { return {...object} }',
                ];
            case 'instagram':
                $profiles = function_exists('ves_instagram_profile_handles_from_urls') ? ves_instagram_profile_handles_from_urls($targets) : [];
                if (!empty($profiles)) {
                    $direct = [];
                    foreach ($profiles as $profile) {
                        $profile = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $profile);
                        if ($profile !== '') { $direct[] = 'https://www.instagram.com/' . $profile . '/'; }
                    }
                    return ['directUrls' => array_values(array_unique($direct)), 'resultsType' => 'posts', 'resultsLimit' => $limit];
                }
                return ['search' => implode(', ', $targets), 'searchType' => 'hashtag', 'resultsType' => 'posts', 'resultsLimit' => $limit];
            case 'twitter': return ['searchTerms' => $targets, 'maxItems' => $limit, 'sort' => 'Top'];
            case 'facebook': return ['startUrls' => array_map(static fn($u) => ['url' => $u], array_filter($targets, static fn($t) => filter_var($t, FILTER_VALIDATE_URL))), 'resultsLimit' => $limit];
        }
        return [];
    }

    private static function build_social_payloads($request, $queries) {
        $platforms = ['youtube', 'tiktok', 'instagram', 'twitter', 'facebook'];
        $out = [];
        $competitor_social_expansion = class_exists('VES_Config') ? VES_Config::brand_audit_enable_competitor_social_expansion() : false;
        foreach (self::social_entities($request) as $entity) {
            $role = sanitize_key((string) ($entity['role'] ?? 'brand'));
            if ($role === 'competitor' && !$competitor_social_expansion) { continue; }
            $entity_key = sanitize_key((string) ($entity['key'] ?? 'entity'));
            foreach ($platforms as $platform) {
                $url = trim((string) (($entity['urls'][$platform] ?? '')));
                if ($url !== '') {
                    $payload = self::build_social_payload($platform, $request, $entity, 'profile');
                    if (!empty($payload['input'])) {
                        $out[$platform . '_' . $entity_key . '_profile'] = $payload;
                    }
                }
                // Brand Audit must not stop at submitted profile URLs. It also runs topic/search evidence for the brand and competitors where the platform actor supports search.
                if (self::platform_supports_keyword_search($platform)) {
                    $payload = self::build_social_payload($platform, $request, $entity, $role === 'brand' ? 'topic_search' : 'competitor_search');
                    if (!empty($payload['input'])) {
                        $out[$platform . '_' . $entity_key . '_search'] = $payload;
                    }
                }
            }
        }
        return $out;
    }

    private static function build_social_public_search_payloads($request) {
        $country = strtolower(sanitize_text_field((string) ($request['country'] ?? 'ES')));
        if ($country === 'none') { $country = ''; }
        $language = sanitize_key((string) ($request['language'] ?? 'es'));
        $out = [];
        foreach (self::social_entities($request) as $entity) {
            $name = self::normalize_entity_name($entity['name'] ?? '');
            if ($name === '') { continue; }
            $role = sanitize_key((string) ($entity['role'] ?? 'brand'));
            $entity_key = sanitize_key((string) ($entity['key'] ?? self::entity_key($role, $name, 0)));
            $urls = is_array($entity['urls'] ?? null) ? $entity['urls'] : [];
            $linkedin_url = $role === 'brand' ? trim((string) ($request['linkedin_url'] ?? '')) : '';
            $facebook_url = trim((string) ($urls['facebook'] ?? ($role === 'brand' ? ($request['facebook_url'] ?? '') : '')));
            $out['linkedin_public_' . $entity_key] = [
                'query' => $linkedin_url !== '' ? $linkedin_url : ($name . ' site:linkedin.com/company OR site:linkedin.com/showcase'),
                'country_code' => $country,
                'search_language' => $language,
                'max_pages' => 1,
                'quick_date_range' => '',
                'platform' => 'linkedin',
                'entity_role' => $role,
                'entity_name' => $name,
                'phase' => $role === 'competitor' ? 4 : 3,
                'phase_label' => $role === 'competitor' ? 'competitor_public_visibility' : 'brand_public_visibility',
                'optional' => $role === 'competitor',
            ];
            if ($facebook_url === '') {
                $out['facebook_public_' . $entity_key] = [
                    'query' => $name . ' site:facebook.com',
                    'country_code' => $country,
                    'search_language' => $language,
                    'max_pages' => 1,
                    'quick_date_range' => '',
                    'platform' => 'facebook',
                    'entity_role' => $role,
                    'entity_name' => $name,
                    'phase' => $role === 'competitor' ? 4 : 3,
                    'phase_label' => $role === 'competitor' ? 'competitor_public_visibility' : 'brand_public_visibility',
                    'optional' => $role === 'competitor',
                ];
            }
        }
        return $out;
    }

    private static function country_location_label($country) {
        $country = strtoupper(sanitize_text_field((string) $country));
        $labels = [
            'ES' => 'Spain', 'US' => 'United States', 'GB' => 'United Kingdom', 'FR' => 'France', 'DE' => 'Germany', 'IT' => 'Italy', 'PT' => 'Portugal', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'NL' => 'Netherlands', 'CA' => 'Canada', 'AU' => 'Australia',
        ];
        if ($country === '' || $country === 'NONE') { return 'Worldwide'; }
        return $labels[$country] ?? $country;
    }

    private static function build_google_source($source_key, $label, $module_key, $input, $notes = '', $phase = 1, $optional = false) {
        return [
            'source_key' => sanitize_key((string) $source_key),
            'label' => sanitize_text_field((string) $label),
            'source_type' => 'google_intel',
            'module_key' => sanitize_key((string) $module_key),
            'status' => 'QUEUED',
            'dispatch_status' => 'pending',
            'analytical_usability_status' => 'pending',
            'notes' => $notes,
            'phase' => max(1, (int) $phase),
            'phase_label' => max(1, (int) $phase) === 1 ? 'foundation_search_web' : 'expanded_source',
            'is_heavy_source' => in_array(sanitize_key((string) $module_key), ['crawler', 'maps'], true),
            'optional' => (bool) $optional,
            'input' => is_array($input) ? $input : [],
            'entity_role' => sanitize_key((string) ($input['entity_role'] ?? '')),
            'entity_name' => sanitize_text_field((string) ($input['entity_name'] ?? '')),
        ];
    }

    private static function build_social_source($source_key, $label, $platform, $payload, $notes = '') {
        return [
            'source_key' => sanitize_key((string) $source_key),
            'label' => sanitize_text_field((string) $label),
            'source_type' => 'social_apify',
            'platform' => sanitize_key((string) $platform),
            'actor_slug' => sanitize_text_field((string) ($payload['actor_slug'] ?? '')),
            'mode' => sanitize_key((string) ($payload['mode'] ?? 'search')),
            'entity_role' => sanitize_key((string) ($payload['entity_role'] ?? 'brand')),
            'entity_name' => sanitize_text_field((string) ($payload['entity_name'] ?? '')),
            'entity_key' => sanitize_key((string) ($payload['entity_key'] ?? '')),
            'source_variant' => sanitize_key((string) ($payload['variant'] ?? '')),
            'status' => 'QUEUED',
            'dispatch_status' => 'pending',
            'analytical_usability_status' => 'pending',
            'notes' => $notes,
            'phase' => max(1, (int) ($payload['phase'] ?? 3)),
            'phase_label' => sanitize_key((string) ($payload['phase_label'] ?? 'social_collection')),
            'is_heavy_source' => true,
            'optional' => !empty($payload['optional']),
            'input' => is_array($payload['input'] ?? null) ? $payload['input'] : [],
            'source_request' => is_array($payload['source_request'] ?? null) ? $payload['source_request'] : [],
        ];
    }

    private static function build_sources($request, $payloads) {
        $sources = [];
        $sources['brand_profile'] = [
            'source_key' => 'brand_profile',
            'label' => 'Brand profile',
            'source_type' => 'internal',
            'status' => 'QUEUED',
            'dispatch_status' => 'pending',
            'analytical_usability_status' => 'pending',
            'notes' => 'Initial brand profile and request normalization.',
            'phase' => 1,
            'phase_label' => 'workspace_foundation',
            'is_heavy_source' => false,
            'optional' => false,
            'input' => $request,
        ];
        if (!empty($payloads['website_crawler'])) {
            $sources['website_crawler'] = self::build_google_source('website_crawler', 'Website snapshot', 'crawler', $payloads['website_crawler'], 'Website content crawler via Google Intelligence/Apify with WordPress HTTP fallback.');
        }
        $sources['google_search'] = self::build_google_source('google_search', 'Google Search signals', 'search', $payloads['google_search'], 'Google SERP/search-intent evidence.');
        $sources['google_news'] = self::build_google_source('google_news', 'Google News signals', 'news', $payloads['google_news'], 'Recent public/news coverage with Google Search fallback.');
        if (!empty($payloads['google_trends']['search_terms'])) {
            $sources['google_trends'] = self::build_google_source('google_trends', 'Google Trends signals', 'trends', $payloads['google_trends'], 'Interest-over-time and related query signals.');
        }
        $platform_labels = [
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'instagram' => 'Instagram',
            'twitter' => 'X / Twitter',
            'facebook' => 'Facebook',
        ];
        foreach ((array) ($payloads['social_platforms'] ?? []) as $payload_key => $payload) {
            if (!is_array($payload) || empty($payload['input'])) { continue; }
            $platform = sanitize_key((string) ($payload['platform'] ?? 'social'));
            $entity_name = sanitize_text_field((string) ($payload['entity_name'] ?? 'Brand'));
            $role = sanitize_key((string) ($payload['entity_role'] ?? 'brand'));
            $variant = sanitize_key((string) ($payload['variant'] ?? ''));
            $label_prefix = $role === 'competitor' ? 'Competitor' : 'Brand';
            $label = ($platform_labels[$platform] ?? ucfirst($platform)) . ' ' . $label_prefix . ' evidence: ' . $entity_name;
            if ($variant === 'topic_search') { $label .= ' + public mentions'; }
            if ($variant === 'competitor_search') { $label .= ' + public/social search'; }
            $sources[sanitize_key((string) $payload_key)] = self::build_social_source((string) $payload_key, $label, $platform, $payload, 'Collect at least 20 recent/readable social items when the provider returns enough data. Used for brand-vs-competitor comparison.');
        }
        foreach ((array) ($payloads['social_public_search'] ?? []) as $source_key => $input) {
            $platform = sanitize_key((string) ($input['platform'] ?? 'social'));
            $entity_name = sanitize_text_field((string) ($input['entity_name'] ?? ''));
            $role = sanitize_key((string) ($input['entity_role'] ?? 'brand'));
            $label = ($platform === 'linkedin' ? 'LinkedIn public visibility' : 'Facebook public visibility') . ($entity_name !== '' ? ': ' . $entity_name : '');
            $sources[$source_key] = self::build_google_source($source_key, $label, 'search', $input, 'Public social visibility collected through Google Search where no direct platform actor is configured or no exact page URL was provided.', (int)($input['phase'] ?? 3), !empty($input['optional']));
        }
        if (!empty($payloads['competitor_search'])) {
            $sources['competitor_search'] = self::build_google_source('competitor_search', 'Competitor web/search visibility signals', 'search', $payloads['competitor_search'], 'Competitor input expansion, official-site discovery, review, campaign and search visibility evidence.', 4, true);
        }
        if (!empty($payloads['maps_reviews'])) {
            $sources['maps_reviews'] = self::build_google_source('maps_reviews', 'Google Maps / Reviews', 'maps', $payloads['maps_reviews'], 'Google Maps places, ratings and review snippets when available.', 2, false);
        }
        return $sources;
    }

    private static function request_hash($request, $query_plan) {
        return hash('sha256', wp_json_encode([
            'brand_name' => function_exists('mb_strtolower') ? mb_strtolower((string) ($request['brand_name'] ?? '')) : strtolower((string) ($request['brand_name'] ?? '')),
            'website_url' => (string) ($request['website_url'] ?? ''),
            'country' => (string) ($request['country'] ?? ''),
            'depth' => (string) ($request['depth'] ?? ''),
            'competitors' => array_values((array) ($request['competitors'] ?? [])),
            'query_plan' => $query_plan,
        ]));
    }
}
