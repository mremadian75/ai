<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('ves_trend_defaults')) {
    function ves_trend_defaults() {
        return [
            'topic' => '',
            'country' => 'None',
            'language' => '',
            'duration' => '7d',
            'reason' => '',
            'sourceBudgetLevel' => 'cheap_probe',
            'candidatePoolSize' => 18,
            'desiredFinalTrends' => 4,
            'trendSources' => 'lite',
            'trendFuentes' => 'lite',
            'date_from' => '',
            'output_language' => 'es',
        ];
    }
}

if (!function_exists('ves_trend_sanitize_request')) {
    /**
     * Canonicalize Trend Finder request fields while preserving older aliases.
     * v0.9.24.84: frontend/backend aliases are normalized here so UI choices
     * actually affect source planning, ranking, and cost preflight.
     */
    function ves_trend_sanitize_request($src) {
        $src = is_array($src) ? $src : [];
        $defaults = ves_trend_defaults();

        $first = static function($keys, $default = '') use ($src) {
            foreach ((array) $keys as $key) {
                if (array_key_exists($key, $src)) {
                    $value = wp_unslash($src[$key]);
                    if (is_array($value)) {
                        $flat = trim(implode("\n", array_map('strval', $value)));
                        if ($flat !== '') { return $value; }
                    } elseif (trim((string) $value) !== '') {
                        return $value;
                    }
                }
            }
            return $default;
        };

        $topic = sanitize_text_field((string) $first(['trendCategory', 'category', 'topic', 'query'], ''));
        $topic = preg_replace('/\s+/', ' ', trim((string) $topic));
        if (function_exists('mb_substr')) { $topic = mb_substr($topic, 0, 140, 'UTF-8'); }
        else { $topic = substr($topic, 0, 140); }

        $country = ves_sanitize_country_code($first(['trendCountry', 'market', 'country', 'trendMarket'], $defaults['country']));

        $duration = sanitize_key((string) $first(['trendRange', 'trendTimeRange', 'duration', 'time_range', 'timeRange'], $defaults['duration']));
        $duration_aliases = [
            'last_24_hours' => '24h', 'last24h' => '24h', 'now_24_h' => '24h',
            '24h' => '24h', '1d' => '1d', 'day' => '1d', 'last_day' => '1d',
            '7d' => '7d', 'last_7_days' => '7d', 'week' => '7d',
            '30d' => '30d', 'last_30_days' => '30d', 'month' => '30d',
            '90d' => '90d', 'last_90_days' => '90d', 'quarter' => '90d',
            '12m' => '12m', '12mo' => '12m', 'last_12_months' => '12m', 'year' => '12m', '365d' => '12m',
        ];
        $duration = $duration_aliases[$duration] ?? $duration;
        if (!in_array($duration, ['24h', '1d', '7d', '30d', '90d', '12m'], true)) {
            $duration = $defaults['duration'];
        }

        $reason = sanitize_textarea_field((string) $first(['trendReason', 'reason', 'businessGoal', 'business_goal', 'businessObjective', 'business_objective'], ''));
        $reason = trim(preg_replace('/\s+/', ' ', $reason));
        if (function_exists('mb_substr')) { $reason = mb_substr($reason, 0, 900, 'UTF-8'); }
        else { $reason = substr($reason, 0, 900); }

        $language_raw = (string) $first(['trendLanguage', 'language', 'lang'], $defaults['language']);
        $language = function_exists('ves_sanitize_language_code')
            ? ves_sanitize_language_code($language_raw)
            : strtolower(preg_replace('/[^a-zA-Z\-]/', '', $language_raw));
        if (strlen($language) > 8) { $language = ''; }

        $output_language_raw = sanitize_key((string) $first(['outputLanguage', 'output_language'], 'es'));
        if (!in_array($output_language_raw, ['es', 'en', 'fa'], true)) { $output_language_raw = 'es'; }

        $date_from = '';
        $date_from_raw = (string) $first(['dateFromIso', 'date_from'], '');
        if (function_exists('ves_clean_iso_date')) { $date_from = ves_clean_iso_date($date_from_raw); }

        $budget_level = sanitize_key((string) $first(['sourceBudgetLevel', 'source_budget_level', 'budget_level'], 'cheap_probe'));
        if (in_array($budget_level, ['low_cost', 'lite'], true)) { $budget_level = 'cheap_probe'; }
        if (!in_array($budget_level, ['cheap_probe', 'balanced', 'deep_scan'], true)) { $budget_level = 'cheap_probe'; }
        $admin_override = !empty($src['ves_admin_allow_deep_scan']) || !empty($src['admin_override']) || !empty($src['allowDeepScan']);
        if (defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && !$admin_override) {
            $budget_level = 'cheap_probe';
        }

        $candidate_pool = max(5, min(200, (int) $first(['candidatePoolSize', 'candidatePool', 'candidate_pool', 'candidate_pool_size'], 18)));
        $desired_final = max(1, min(50, (int) $first(['desiredFinalTrends', 'desired_final_trends', 'desiredFinalResults', 'desired_final_results'], 4)));
        if (defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && !$admin_override) {
            $candidate_pool = max(5, min(20, $candidate_pool));
            $desired_final = max(1, min(5, $desired_final));
        }

        $trend_type = sanitize_key((string) $first(['trendType', 'trend_type'], 'general'));
        $sort_by = sanitize_key((string) $first(['trendSort', 'sortBy', 'sort_by'], 'opportunity'));
        if ($sort_by === 'business_opportunity') { $sort_by = 'opportunity'; }
        if (!in_array($sort_by, ['opportunity', 'freshness', 'growth', 'relevance', 'engagement'], true)) { $sort_by = 'opportunity'; }

        $source_mode = sanitize_key((string) $first(['trendFuentes', 'trendSources', 'sourceMode', 'source_mode'], 'lite'));
        $reduced_scope = !empty($src['reducedScope']) || !empty($src['reduced_scope']);
        if ($source_mode === 'low_cost') { $source_mode = 'lite'; }
        if (!in_array($source_mode, ['lite', 'balanced', 'search_only', 'social_only', 'news_search_social'], true)) { $source_mode = 'lite'; }
        if ($reduced_scope && (!$admin_override || $source_mode !== 'social_only')) {
            $source_mode = 'search_only';
        }
        if (defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && !$admin_override && in_array($source_mode, ['balanced', 'news_search_social'], true)) {
            $source_mode = $reduced_scope ? 'search_only' : 'lite';
        }

        return [
            'topic' => $topic,
            'country' => $country,
            'market' => function_exists('ves_trend_label_country') ? ves_trend_label_country($country) : $country,
            'language' => $language,
            'duration' => $duration,
            'time_range' => $duration,
            'reason' => $reason,
            'business_goal' => $reason,
            'trend_type' => $trend_type,
            'budget_level' => $budget_level,
            'sourceBudgetLevel' => $budget_level,
            'source_mode' => $source_mode,
            'sourceMode' => $source_mode,
            'trendSources' => $source_mode,
            'trendFuentes' => $source_mode,
            'max_sources' => $reduced_scope ? max(1, min(2, (int) $first(['maxSources', 'max_sources'], 2))) : ((defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && !$admin_override) ? min(3, max(1, (int) $first(['maxSources', 'max_sources'], 3))) : max(1, (int) $first(['maxSources', 'max_sources'], 8))),
            'maxSources' => $reduced_scope ? max(1, min(2, (int) $first(['maxSources', 'max_sources'], 2))) : ((defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP && !$admin_override) ? min(3, max(1, (int) $first(['maxSources', 'max_sources'], 3))) : max(1, (int) $first(['maxSources', 'max_sources'], 8))),
            'reduced_scope' => $reduced_scope,
            'candidate_pool' => $reduced_scope ? max(5, min(15, $candidate_pool)) : $candidate_pool,
            'candidatePoolSize' => $reduced_scope ? max(5, min(15, $candidate_pool)) : $candidate_pool,
            'desired_final_trends' => $reduced_scope ? max(1, min(3, $desired_final)) : $desired_final,
            'desiredFinalTrends' => $reduced_scope ? max(1, min(3, $desired_final)) : $desired_final,
            'sort_by' => $sort_by,
            'trendSort' => $sort_by,
            'date_from' => $date_from,
            'output_language' => $output_language_raw,
        ];
    }
}

if (!function_exists('ves_trend_label_country')) {
    function ves_trend_label_country($code) {
        $map = [
            'None' => 'Worldwide', 'ES' => 'Spain', 'US' => 'United States', 'GB' => 'United Kingdom', 'FR' => 'France', 'DE' => 'Germany',
            'IT' => 'Italy', 'PT' => 'Portugal', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'BE' => 'Belgium', 'CH' => 'Switzerland',
            'CA' => 'Canada', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
            'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'TW' => 'Taiwan', 'HK' => 'Hong Kong', 'SG' => 'Singapore', 'MY' => 'Malaysia',
            'ID' => 'Indonesia', 'TH' => 'Thailand', 'PH' => 'Philippines', 'VN' => 'Vietnam', 'IN' => 'India', 'AU' => 'Australia', 'NZ' => 'New Zealand',
            'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'TR' => 'Turkey', 'MA' => 'Morocco', 'EG' => 'Egypt', 'ZA' => 'South Africa',
            'IL' => 'Israel', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'RO' => 'Romania',
            'GR' => 'Greece', 'AT' => 'Austria', 'CZ' => 'Czech Republic', 'UY' => 'Uruguay', 'VE' => 'Venezuela', 'EC' => 'Ecuador', 'BO' => 'Bolivia',
            'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'NG' => 'Nigeria', 'KE' => 'Kenya',
        ];
        $code = (string) $code;
        return $map[$code] ?? ($code !== '' ? $code : 'Worldwide');
    }
}

if (!function_exists('ves_trend_duration_label')) {
    function ves_trend_duration_label($duration) {
        $map = [
            '24h' => 'Last 24 hours',
            '1d' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            '12m' => 'Last 12 months',
        ];
        return $map[sanitize_key((string) $duration)] ?? 'Last 7 days';
    }
}

if (!function_exists('ves_trend_google_time_range')) {
    function ves_trend_google_time_range($duration) {
        $map = [
            '24h' => 'now 1-d',
            '1d' => 'now 1-d',
            '7d' => 'now 7-d',
            '30d' => 'today 1-m',
            '90d' => 'today 3-m',
            '12m' => 'today 12-m',
        ];
        return $map[sanitize_key((string) $duration)] ?? 'now 7-d';
    }
}

if (!function_exists('ves_trend_tiktok_time_range')) {
    function ves_trend_tiktok_time_range($duration) {
        $map = [
            '24h' => '1',
            '1d' => '1',
            '7d' => '7',
            '30d' => '30',
            '90d' => '120',
            '12m' => '365',
        ];
        return $map[sanitize_key((string) $duration)] ?? '7';
    }
}

if (!function_exists('ves_trend_youtube_country_slug')) {
    function ves_trend_youtube_country_slug($country) {
        $map = [
            'None' => 'world', 'US' => 'united-states', 'GB' => 'united-kingdom', 'ES' => 'spain', 'FR' => 'france', 'DE' => 'germany',
            'IT' => 'italy', 'PT' => 'portugal', 'NL' => 'netherlands', 'CH' => 'switzerland', 'CA' => 'canada', 'MX' => 'mexico', 'BR' => 'brazil',
            'AR' => 'argentina', 'CL' => 'chile', 'JP' => 'japan', 'KR' => 'south-korea', 'TH' => 'thailand', 'PH' => 'philippines', 'VN' => 'vietnam',
            'IN' => 'india', 'AU' => 'australia', 'AE' => 'united-arab-emirates', 'SA' => 'saudi-arabia', 'TR' => 'turkey', 'RU' => 'russia', 'ZA' => 'south-africa',
        ];
        return $map[(string) $country] ?? 'world';
    }
}
if (!function_exists('ves_trend_tiktok_country_code')) {
    function ves_trend_tiktok_country_code($country) {
        $country = strtoupper(trim((string) $country));
        if ($country === '' || $country === 'NONE' || $country === 'ANY' || $country === 'WORLD') {
            return '';
        }
        $allowed = ['US','GB','ES','FR','DE','IT','NL','CH','CA','MX','BR','CL','JP','KR','TH','PH','VN','IN','ID','AU','AE','SA','TR','RU','ZA'];
        return in_array($country, $allowed, true) ? $country : '';
    }
}

if (!function_exists('ves_trend_tiktok_country_slug')) {
    function ves_trend_tiktok_country_slug($country) {
        return ves_trend_tiktok_country_code($country);
    }
}
if (!function_exists('ves_trend_youtube_category_slug')) {
    function ves_trend_youtube_category_slug($topic) {
        $t = strtolower((string) $topic);
        $map = [
            'music' => 'music', 'song' => 'music', 'artist' => 'music', 'band' => 'music',
            'game' => 'gaming', 'gaming' => 'gaming', 'esport' => 'gaming',
            'sport' => 'sports', 'football' => 'sports', 'soccer' => 'sports', 'basketball' => 'sports', 'ufc' => 'sports',
            'news' => 'news-and-politics', 'politic' => 'news-and-politics', 'election' => 'news-and-politics',
            'tech' => 'science-and-technology', 'ai' => 'science-and-technology', 'software' => 'science-and-technology', 'startup' => 'science-and-technology',
            'science' => 'science-and-technology', 'gadget' => 'science-and-technology',
            'beauty' => 'howto-and-style', 'style' => 'howto-and-style', 'fashion' => 'howto-and-style', 'tutorial' => 'howto-and-style',
            'film' => 'film-and-animation', 'movie' => 'film-and-animation', 'animation' => 'film-and-animation', 'anime' => 'film-and-animation',
            'comedy' => 'comedy', 'funny' => 'comedy', 'meme' => 'comedy',
            'pet' => 'pets-and-animals', 'animal' => 'pets-and-animals',
            'car' => 'autos-and-vehicles', 'auto' => 'autos-and-vehicles', 'vehicle' => 'autos-and-vehicles',
            'entertainment' => 'entertainment', 'celebrity' => 'entertainment',
            'blog' => 'people-and-blogs', 'lifestyle' => 'people-and-blogs', 'travel' => 'people-and-blogs', 'business' => 'people-and-blogs',
        ];
        foreach ($map as $needle => $slug) {
            if (strpos($t, $needle) !== false) {
                return $slug;
            }
        }
        return 'all';
    }
}

if (!function_exists('ves_trend_tiktok_industry')) {
    function ves_trend_tiktok_industry($topic) {
        $t = strtolower((string) $topic);
        $map = [
            'beauty' => 'Beauty & Personal Care', 'skin' => 'Beauty & Personal Care', 'makeup' => 'Beauty & Personal Care',
            'food' => 'Food & Beverage', 'drink' => 'Food & Beverage', 'restaurant' => 'Food & Beverage',
            'travel' => 'Travel', 'trip' => 'Travel', 'tourism' => 'Travel',
            'sport' => 'Sports & Outdoor', 'fitness' => 'Sports & Outdoor', 'gym' => 'Sports & Outdoor',
            'tech' => 'Tech & Electronics', 'ai' => 'Tech & Electronics', 'software' => 'Tech & Electronics', 'gadget' => 'Tech & Electronics',
            'education' => 'Education', 'learn' => 'Education', 'course' => 'Education',
            'health' => 'Health', 'wellness' => 'Health',
            'game' => 'Games', 'gaming' => 'Games',
            'pet' => 'Pets', 'animal' => 'Pets',
            'fashion' => 'Apparel & Accessories', 'clothes' => 'Apparel & Accessories', 'apparel' => 'Apparel & Accessories',
            'car' => 'Vehicle & Transportation', 'auto' => 'Vehicle & Transportation', 'mobility' => 'Vehicle & Transportation',
            'news' => 'News & Entertainment', 'entertainment' => 'News & Entertainment', 'celebrity' => 'News & Entertainment',
            'finance' => 'Financial Services', 'bank' => 'Financial Services', 'crypto' => 'Financial Services',
            'home' => 'Home Improvement', 'interior' => 'Home Improvement',
            'business' => 'Business Services', 'saas' => 'Business Services', 'marketing' => 'Business Services',
        ];
        foreach ($map as $needle => $label) {
            if (strpos($t, $needle) !== false) {
                return $label;
            }
        }
        return '';
    }
}

if (!function_exists('ves_trend_twitter_country_id')) {
    function ves_trend_twitter_country_id($country) {
        $map = [
            'None' => '1', 'US' => '2', 'CA' => '3', 'MX' => '4', 'GB' => '5', 'FR' => '6', 'DE' => '7', 'IT' => '8', 'ES' => '9', 'PT' => '10',
            'NL' => '11', 'DK' => '12', 'AT' => '13', 'BE' => '14', 'CH' => '15', 'GR' => '16', 'RU' => '17', 'TR' => '18', 'KR' => '19', 'SG' => '20',
            'ID' => '21', 'PH' => '22', 'VN' => '23', 'TH' => '24', 'AU' => '25', 'IL' => '26', 'AE' => '27', 'SA' => '28', 'AR' => '29', 'BR' => '30',
            'EG' => '31', 'NG' => '32', 'KE' => '33', 'ZA' => '34', 'JP' => '35',
        ];
        return $map[(string) $country] ?? '1';
    }
}

if (!function_exists('ves_trend_twitter_flags')) {
    function ves_trend_twitter_flags($duration) {
        $flags = [
            'live' => true,
            'hour1' => false,
            'hour3' => false,
            'hour6' => false,
            'hour12' => false,
            'hour24' => false,
            'day2' => false,
            'day3' => false,
        ];
        $duration = sanitize_key((string) $duration);
        if (in_array($duration, ['1d', '7d', '30d', '90d'], true)) {
            $flags['hour1'] = true;
            $flags['hour3'] = true;
        }
        if (in_array($duration, ['1d', '7d', '30d', '90d'], true)) {
            $flags['hour6'] = true;
            $flags['hour12'] = true;
            $flags['hour24'] = true;
        }
        if (in_array($duration, ['7d', '30d', '90d'], true)) {
            $flags['day2'] = true;
            $flags['day3'] = true;
        }
        return $flags;
    }
}

if (!function_exists('ves_build_trend_actor_payloads')) {
    function ves_build_trend_actor_payloads($request) {
        if (class_exists('VES_Trend_Query_Planner')) {
            $built = VES_Trend_Query_Planner::build($request);
            return is_array($built['payloads'] ?? null) ? $built['payloads'] : [];
        }
        $request = ves_trend_sanitize_request($request);
        $topic = $request['topic'];
        $country = $request['country'];
        $duration = $request['duration'];
        $country_for_tiktok = function_exists('ves_trend_tiktok_country_code') ? ves_trend_tiktok_country_code($country) : '';
        $tiktok_industry = ves_trend_tiktok_industry($topic);

        $google_input = [
            'searchTerms' => [$topic],
            'isMultiple' => false,
            'timeRange' => ves_trend_google_time_range($duration),
            'geo' => $country === 'None' ? '' : $country,
            'viewedFrom' => $country === 'None' ? 'us' : strtolower($country),
            'maxItems' => 25,
        ];

        $youtube_input = [
            'country' => ves_trend_youtube_country_slug($country),
            'category' => ves_trend_youtube_category_slug($topic),
        ];

        $tiktok_input = [
            'adsScrapeHashtags' => true,
            'adsScrapeVideos' => true,
            'resultsPerPage' => 25,
            'adsCountryCode' => $country_for_tiktok,
            'adsVideosCountryCode' => $country_for_tiktok,
            'adsScrapeSounds' => false,
            'adsSoundsCountryCode' => $country_for_tiktok,
            'adsRankType' => 'popular',
            'adsScrapeCreators' => false,
            'adsCreatorsCountryCode' => $country_for_tiktok,
            'adsSortCreatorsBy' => 'follower',
            'adsTimeRange' => ves_trend_tiktok_time_range($duration),
            'adsSortVideosBy' => 'vv',
        ];
        if ($tiktok_industry !== '') {
            $tiktok_input['adsHashtagIndustry'] = $tiktok_industry;
        }

        $twitter_input = array_merge(ves_trend_twitter_flags($duration), [
            'country' => ves_trend_twitter_country_id($country),
        ]);

        return [
            'google_trends' => [
                'label' => 'Google Trends',
                'actor_slug' => VES_Config::get_actor_slug('google_trends'),
                'input' => $google_input,
            ],
            'youtube_trending' => [
                'label' => 'YouTube Trending',
                'actor_slug' => VES_Config::get_actor_slug('youtube_trending'),
                'input' => $youtube_input,
            ],
            'tiktok_trends' => [
                'label' => 'TikTok Trends',
                'actor_slug' => VES_Config::get_actor_slug('tiktok_trends'),
                'input' => $tiktok_input,
            ],
            'twitter_trends' => [
                'label' => 'X / Twitter Trends',
                'actor_slug' => VES_Config::get_actor_slug('twitter_trends'),
                'input' => $twitter_input,
            ],
        ];
    }
}

if (!function_exists('ves_trend_bundle_key')) {
    function ves_trend_bundle_key($bundle_id) {
        return 'ves_trend_bundle_' . md5((string) $bundle_id);
    }
}

if (!function_exists('ves_trend_extract_highlights')) {
    function ves_trend_extract_highlights($source_key, $items) {
        $items = is_array($items) ? $items : [];
        $highlights = [];
        $push = static function ($value, $secondary = '') use (&$highlights) {
            $value = trim((string) $value);
            $secondary = trim((string) $secondary);
            if ($value === '') return;
            $label = $secondary !== '' ? ($value . ' — ' . $secondary) : $value;
            if (!in_array($label, $highlights, true)) {
                $highlights[] = $label;
            }
        };

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($source_key === 'twitter_trends') {
                $push($item['trend'] ?? '', trim((string) (($item['timePeriod'] ?? '') . ' ' . ($item['volume'] ?? ''))));
                continue;
            }
            if ($source_key === 'youtube_trending') {
                $push($item['title'] ?? '', trim((string) (($item['channelName'] ?? $item['channel'] ?? '') . ' ' . ($item['views'] ?? $item['viewCount'] ?? ''))));
                continue;
            }
            if ($source_key === 'tiktok_trends') {
                $push(
                    $item['hashtagName'] ?? $item['musicTitle'] ?? $item['title'] ?? $item['postDesc'] ?? $item['description'] ?? '',
                    $item['creatorUniqueId'] ?? $item['authorName'] ?? $item['region'] ?? ''
                );
                continue;
            }
            if ($source_key === 'google_trends') {
                $push($item['searchTerm'] ?? $item['keyword'] ?? $item['query'] ?? '', '');
                foreach (['relatedQueriesTop', 'relatedQueriesRising', 'relatedTopicsTop', 'relatedTopicsRising', 'relatedQueries', 'relatedTopics'] as $key) {
                    $entries = $item[$key] ?? null;
                    if (!is_array($entries)) continue;
                    foreach ($entries as $entry) {
                        if (is_array($entry)) {
                            $push($entry['query'] ?? $entry['topic'] ?? $entry['title'] ?? $entry['value'] ?? '', $entry['value'] ?? $entry['formattedValue'] ?? '');
                        } elseif (is_string($entry)) {
                            $push($entry, '');
                        }
                        if (count($highlights) >= 12) {
                            break 2;
                        }
                    }
                }
            }
            if (count($highlights) >= 12) {
                break;
            }
        }

        return array_slice($highlights, 0, 10);
    }
}

if (!function_exists('ves_trend_compact_items_for_ai')) {
    function ves_trend_compact_items_for_ai($source_key, $items) {
        $items = is_array($items) ? array_slice($items, 0, 20) : [];
        $compact = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($source_key === 'twitter_trends') {
                $compact[] = [
                    'trend' => $item['trend'] ?? '',
                    'timePeriod' => $item['timePeriod'] ?? '',
                    'volume' => $item['volume'] ?? '',
                ];
                continue;
            }
            if ($source_key === 'youtube_trending') {
                $compact[] = [
                    'title' => $item['title'] ?? '',
                    'channel' => $item['channelName'] ?? $item['channel'] ?? '',
                    'views' => $item['views'] ?? $item['viewCount'] ?? '',
                    'likes' => $item['likes'] ?? $item['likeCount'] ?? '',
                    'url' => $item['url'] ?? $item['videoUrl'] ?? '',
                ];
                continue;
            }
            if ($source_key === 'tiktok_trends') {
                $compact[] = [
                    'label' => $item['hashtagName'] ?? $item['musicTitle'] ?? $item['title'] ?? $item['postDesc'] ?? $item['description'] ?? '',
                    'creator' => $item['creatorUniqueId'] ?? $item['authorName'] ?? '',
                    'views' => $item['playCount'] ?? $item['views'] ?? '',
                    'likes' => $item['diggCount'] ?? $item['likes'] ?? '',
                    'url' => $item['url'] ?? $item['videoUrl'] ?? '',
                ];
                continue;
            }
            $compact[] = [
                'searchTerm' => $item['searchTerm'] ?? $item['keyword'] ?? $item['query'] ?? '',
                'relatedQueriesTop' => is_array($item['relatedQueriesTop'] ?? null) ? array_slice($item['relatedQueriesTop'], 0, 5) : [],
                'relatedQueriesRising' => is_array($item['relatedQueriesRising'] ?? null) ? array_slice($item['relatedQueriesRising'], 0, 5) : [],
                'relatedTopicsTop' => is_array($item['relatedTopicsTop'] ?? null) ? array_slice($item['relatedTopicsTop'], 0, 5) : [],
                'relatedTopicsRising' => is_array($item['relatedTopicsRising'] ?? null) ? array_slice($item['relatedTopicsRising'], 0, 5) : [],
            ];
        }
        return $compact;
    }
}
