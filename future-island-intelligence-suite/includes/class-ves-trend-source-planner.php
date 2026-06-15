<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Trend Finder Source Planner v2.
 * Builds role-based source plans from simple user controls. Normal users never
 * choose actors; admins configure source slots in settings.
 */
final class VES_Trend_Source_Planner {
    private static function val($request, $keys, $default = '') {
        foreach ((array) $keys as $key) {
            if (isset($request[$key]) && trim(is_array($request[$key]) ? implode("\n", $request[$key]) : (string) $request[$key]) !== '') {
                return $request[$key];
            }
        }
        return $default;
    }

    private static function settings() {
        return (class_exists('VES_Config') && method_exists('VES_Config', 'get_settings')) ? VES_Config::get_settings() : [];
    }

    private static function mvp_lite_enabled() {
        return defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP;
    }

    private static function clamp_mvp_sources($sources, $max_sources) {
        $max_sources = max(1, (int) $max_sources);
        if (!self::mvp_lite_enabled()) { return $sources; }
        $kept = [];
        foreach ((array) $sources as $source) {
            if (count($kept) >= $max_sources) { break; }
            $key = sanitize_key((string) ($source['source_key'] ?? ''));
            if ($key === '') { continue; }
            $kept[] = $source;
        }
        return $kept;
    }

    private static function country_code($market, $country = '') {
        $raw = trim((string) ($country ?: $market));
        $m = strtolower($raw);
        if ($m === '' || $m === 'none' || $m === 'worldwide' || $m === 'global') { return ''; }
        if (in_array($m, ['spain','españa','espana','es'], true)) { return 'ES'; }
        if (in_array($m, ['united states','usa','us'], true)) { return 'US'; }
        $letters = preg_replace('/[^A-Za-z]/', '', $raw);
        if (strlen($letters) === 2) { return strtoupper($letters); }
        return strtoupper(substr($letters, 0, 2));
    }

    private static function google_time_range($range, $source_key = '') {
        $r = sanitize_key((string) $range);
        if ($r === '24h') { return 'now 1-d'; }
        if ($r === '1d') { return 'now 1-d'; }
        if ($r === '30d') { return 'today 1-m'; }
        if ($r === '90d') { return 'today 3-m'; }
        if ($r === '12m') { return 'today 12-m'; }
        return 'now 7-d';
    }

    private static function published_after($duration) {
        $duration = sanitize_key((string) $duration);
        $days = 7;
        if ($duration === '24h' || $duration === '1d') { $days = 1; }
        elseif ($duration === '30d') { $days = 30; }
        elseif ($duration === '90d') { $days = 90; }
        elseif ($duration === '12m') { $days = 365; }
        return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * DAY_IN_SECONDS));
    }

    private static function actor($slot, $fallback = '') {
        $slug = (class_exists('VES_Config') && method_exists('VES_Config', 'get_actor_slug')) ? VES_Config::get_actor_slug($slot) : '';
        return trim((string) ($slug !== '' ? $slug : $fallback));
    }

    private static function slot_enabled($slot) {
        $settings = self::settings();
        $key = $slot . '_enabled';
        if (array_key_exists($key, $settings)) { return !empty($settings[$key]); }
        return true;
    }

    private static function cost_level($slot, $fallback) {
        $settings = self::settings();
        $key = $slot . '_cost_level';
        $value = sanitize_key((string) ($settings[$key] ?? $fallback));
        return in_array($value, ['low','medium','high'], true) ? $value : $fallback;
    }

    private static function reliability_note($slot, $fallback = '') {
        $settings = self::settings();
        return sanitize_text_field((string) ($settings[$slot . '_reliability_note'] ?? $fallback));
    }

    private static function source($key, $label, $family, $role, $platform, $input, $args = []) {
        $fallback_slug = (string) ($args['fallback_slug'] ?? '');
        $actor_slug = self::actor($key, $fallback_slug);
        $enabled = self::slot_enabled($key) && $actor_slug !== '';
        return [
            'source_key' => $key,
            'label' => $label,
            'actor_slug' => $actor_slug,
            'source_family' => $family,
            'source_role' => $role,
            'platform' => $platform,
            'enabled' => $enabled,
            'required' => !empty($args['required']),
            'optional' => empty($args['required']),
            'ambient_only' => !empty($args['ambient_only']),
            'stage' => (int) ($args['stage'] ?? 1),
            'fallback_to' => sanitize_key((string) ($args['fallback_to'] ?? '')),
            'estimated_cost_level' => self::cost_level($key, (string) ($args['cost_level'] ?? 'medium')),
            'reliability_level' => sanitize_key((string) ($args['reliability_level'] ?? 'medium')),
            'requires_rental_or_access' => !empty($args['requires_rental_or_access']),
            'reason' => sanitize_text_field((string) ($args['reason'] ?? '')),
            'admin_notes' => self::reliability_note($key, (string) ($args['admin_notes'] ?? '')),
            'input' => $input,
        ];
    }

    private static function has_source_family($sources, $family) {
        foreach ($sources as $source) {
            if (($source['source_family'] ?? '') === $family && !empty($source['enabled'])) { return true; }
        }
        return false;
    }

    private static function discussion_friendly($topic, $goal = '') {
        $blob = strtolower((string) $topic . ' ' . (string) $goal);
        return (bool) preg_match('/\b(problem|review|opinion|community|reddit|pain|objection|why|how|trust|pricing|comparison|cerveza|beer|pharmacy|wellness|saas|seo|tourism|education|cars|coches|marketing)\b/u', $blob);
    }

    private static function choose_social_sources($topic, $source_mode, $budget, $discussion_friendly) {
        if ($source_mode === 'search_only') { return []; }
        $topic_l = strtolower((string) $topic);
        $ordered = [];
        if ($discussion_friendly) { $ordered[] = 'reddit_topic_posts'; }
        $ordered[] = 'x_topic_posts';
        if (preg_match('/\b(video|tiktok|reels|creative|hook|food|beer|tourism|fashion|beauty|music|creator)\b/u', $topic_l)) { $ordered[] = 'tiktok_topic_videos'; }
        else { $ordered[] = 'youtube_topic_videos'; }
        if (!in_array('reddit_topic_posts', $ordered, true)) { $ordered[] = 'reddit_topic_posts'; }
        if (!in_array('youtube_topic_videos', $ordered, true)) { $ordered[] = 'youtube_topic_videos'; }
        if (!in_array('tiktok_topic_videos', $ordered, true)) { $ordered[] = 'tiktok_topic_videos'; }
        $limit = $budget === 'balanced' ? 2 : 4;
        if ($source_mode === 'social_only') { $limit = $budget === 'cheap_probe' ? 1 : 4; }
        return array_slice(array_values(array_unique($ordered)), 0, $limit);
    }

    public static function build($request) {
        $request = function_exists('ves_trend_sanitize_request') ? ves_trend_sanitize_request($request) : (array) $request;
        $topic = sanitize_text_field((string) self::val($request, ['topic','category','query'], ''));
        $market = sanitize_text_field((string) self::val($request, ['market','country'], ''));
        $country = self::country_code($market, (string) self::val($request, ['country'], ''));
        $language = sanitize_key((string) self::val($request, ['language','lang'], 'es'));
        $duration = sanitize_key((string) self::val($request, ['duration','time_range'], '7d'));
        $budget = sanitize_key((string) self::val($request, ['budget_level','sourceBudgetLevel','source_budget_level'], 'cheap_probe'));
        if (in_array($budget, ['low_cost', 'lite'], true)) { $budget = 'cheap_probe'; }
        if (!in_array($budget, ['cheap_probe','balanced','deep_scan'], true)) { $budget = 'cheap_probe'; }
        $admin_override = !empty($request['ves_admin_allow_deep_scan']) || !empty($request['admin_override']) || !empty($request['allowDeepScan']);
        if (self::mvp_lite_enabled() && !$admin_override) { $budget = 'cheap_probe'; }
        $source_mode = sanitize_key((string) self::val($request, ['source_mode','trendSources','sourceMode'], 'lite'));
        if ($source_mode === 'low_cost') { $source_mode = 'lite'; }
        if (!in_array($source_mode, ['lite','balanced','search_only','social_only','news_search_social'], true)) { $source_mode = 'lite'; }
        if (self::mvp_lite_enabled() && !$admin_override && in_array($source_mode, ['balanced','news_search_social'], true)) { $source_mode = 'lite'; }
        $sort_by = sanitize_key((string) self::val($request, ['sort_by','trendSort','sortBy'], 'opportunity'));
        $candidate_pool = max(5, min(200, (int) self::val($request, ['candidate_pool','candidatePoolSize','candidatePool','candidate_pool_size'], 18)));
        $desired_final = max(1, min(50, (int) self::val($request, ['desired_final_trends','desiredFinalTrends','desired_final_results'], 4)));
        $max_sources = max(1, min(8, (int) self::val($request, ['max_sources','maxSources'], 3)));
        if (self::mvp_lite_enabled() && !$admin_override) {
            $candidate_pool = max(5, min(20, $candidate_pool));
            $desired_final = max(1, min(5, $desired_final));
            $max_sources = min(3, $max_sources);
        }
        $business_goal = sanitize_textarea_field((string) self::val($request, ['business_goal','reason'], ''));
        $expanded = class_exists('VES_Trend_Query_Expander') ? VES_Trend_Query_Expander::expand($request) : ['seed_terms'=>[$topic], 'local_terms'=>[], 'question_terms'=>[], 'hashtag_candidates'=>[], 'platform_terms'=>[$topic], 'news_terms'=>[], 'negative_terms'=>[]];
        $seed = array_values(array_filter((array) ($expanded['seed_terms'] ?? [])));
        $local = array_values(array_filter((array) ($expanded['local_terms'] ?? [])));
        $questions = array_values(array_filter((array) ($expanded['question_terms'] ?? [])));
        $platform_terms = array_values(array_filter((array) ($expanded['platform_terms'] ?? [])));
        $news_terms = array_values(array_filter((array) ($expanded['news_terms'] ?? [])));
        $hashtags = array_values(array_map(static function($tag) { return ltrim((string) $tag, '#'); }, (array) ($expanded['hashtag_candidates'] ?? [])));
        $primary = $seed[0] ?? $topic;
        $search_queries = array_values(array_unique(array_filter(array_merge($local, $questions, [$primary]))));
        $discussion_terms = array_values(array_unique(array_filter(array_merge($platform_terms, $questions, [$primary]))));
        $video_terms = array_values(array_unique(array_filter(array_merge($questions, $platform_terms, [$primary]))));
        $sources = [];

        $include_search = $source_mode !== 'social_only';
        $include_social = !in_array($source_mode, ['search_only', 'lite'], true);
        $include_news = $source_mode === 'news_search_social' || $budget === 'deep_scan';
        $discussion = self::discussion_friendly($topic, $business_goal);

        if ($include_search) {
            $sources[] = self::source('google_trends_interest', 'Google Trends Interest', 'search_demand', 'interest_baseline', 'google_trends', [
                'keyword' => $primary,
                'searchTerms' => array_slice(array_values(array_unique(array_merge([$primary], $seed, $local))), 0, 5),
                'isMultiple' => count($seed) > 1,
                'timeRange' => self::google_time_range($duration, 'google_trends_interest'),
                'geo' => $country,
                'viewedFrom' => strtolower($country ?: 'us'),
                'maxItems' => min(25, $candidate_pool),
            ], ['required' => true, 'stage' => 1, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_to' => 'google_search_freshness', 'fallback_slug' => 'apify/google-trends-scraper', 'reason' => 'Low-cost search demand baseline.']);

            // Sprint 1 (Part A): inject safe Google query-string exclusions only.
            // Google Search/News accept raw-query exclusion syntax; this trims known
            // noise before fetch (credit savings) while staying Google-only.
            $neg_terms = (array) ($expanded['negative_terms'] ?? []);
            $apply_neg = static function ($q) use ($neg_terms) {
                return (class_exists('VES_Query_Planner') && method_exists('VES_Query_Planner', 'apply_google_query_exclusions'))
                    ? VES_Query_Planner::apply_google_query_exclusions($q, $neg_terms)
                    : $q;
            };
            $gs_queries = array_slice($search_queries, 0, max(1, min(4, (int) ceil($candidate_pool / 20))));
            $sources[] = self::source('google_search_freshness', 'Google Search Freshness', 'search_demand', 'fresh_serp_context', 'google_search', [
                'queries' => array_map($apply_neg, $gs_queries),
                'query' => $apply_neg($search_queries[0] ?? $primary),
                'resultsPerPage' => min(10, $candidate_pool),
                'maxPagesPerQuery' => 1,
                'countryCode' => strtolower($country ?: 'es'),
                'languageCode' => $language ?: 'es',
                'maxResults' => min(10, $candidate_pool),
            ], ['required' => $budget === 'cheap_probe', 'stage' => 1, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_to' => $include_news ? 'google_news_freshness' : '', 'fallback_slug' => 'apify/google-search-scraper', 'reason' => 'Fresh search result context and fallback when Trends is flat.']);

            if ($include_news) {
                $gn_queries = array_slice(array_values(array_unique(array_merge($news_terms, $search_queries))), 0, 3);
                $sources[] = self::source('google_news_freshness', 'Google News Freshness', 'search_demand', 'fresh_news_context', 'google_news', [
                    'query' => $apply_neg($news_terms[0] ?? $search_queries[0] ?? $primary),
                    'queries' => array_map($apply_neg, $gn_queries),
                    'gl' => strtolower($country ?: 'es'),
                    'hl' => $language ?: 'es',
                    'time_period' => $duration === '24h' || $duration === '1d' ? 'last_day' : ($duration === '7d' ? 'last_week' : 'last_month'),
                    'max_items' => min(10, $candidate_pool),
                ], ['stage' => 1, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_slug' => 'apify/google-search-scraper', 'reason' => 'News/search freshness validation.']);
            }

            if ($budget !== 'cheap_probe' && self::slot_enabled('semrush_keyword_context')) {
                $sources[] = self::source('semrush_keyword_context', 'Semrush Keyword Context', 'search_demand', 'keyword_context', 'semrush', [
                    'keyword' => $primary,
                    'country' => strtolower($country ?: 'es'),
                    'language' => $language ?: 'es',
                    'maxResults' => min(50, $candidate_pool),
                ], ['stage' => 2, 'cost_level' => 'medium', 'reliability_level' => 'medium', 'fallback_slug' => 'burbn/semrush-keyword-magic-tool', 'requires_rental_or_access' => true, 'reason' => 'Optional keyword demand context; not required for generic Trend Finder.']);
            }
        }

        if ($include_social) {
            if ($budget === 'cheap_probe') {
                if ($source_mode !== 'search_only' && $discussion) {
                    $sources[] = self::source('reddit_topic_posts', 'Reddit Topic Posts', 'social_conversation', 'discussion_posts', 'reddit', [
                        'searchTerms' => array_slice($discussion_terms, 0, 2),
                        'searches' => array_slice($discussion_terms, 0, 2),
                        'queries' => array_slice($discussion_terms, 0, 2),
                        'search' => $discussion_terms[0] ?? $primary,
                        'maxItems' => min(20, $candidate_pool),
                        'sort' => 'relevance',
                        'time' => in_array($duration, ['24h','1d'], true) ? 'day' : 'week',
                        'searchPosts' => true,
                        'searchComments' => false,
                        'includeNSFW' => false,
                    ], ['stage' => 2, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_slug' => 'trudax/reddit-scraper-lite', 'reason' => 'Cheap discussion/pain-point probe for discussion-friendly topics.']);
                }
            } else {
                $selected_social = self::choose_social_sources($topic, $source_mode, $budget, $discussion);
                foreach ($selected_social as $slot) {
                    if ($slot === 'x_topic_posts') {
                        $sources[] = self::source('x_topic_posts', 'X Topic Posts', 'social_conversation', 'topic_posts', 'twitter', [
                            'searchTerms' => array_slice($discussion_terms, 0, 4),
                            'queries' => array_slice($discussion_terms, 0, 4),
                            'maxItems' => min(30, $candidate_pool),
                            'sort' => $sort_by === 'freshness' ? 'Latest' : 'Top',
                            'tweetLanguage' => $language,
                            'startDate' => '',
                            'endDate' => '',
                        ], ['stage' => 2, 'cost_level' => 'medium', 'reliability_level' => 'medium', 'fallback_to' => 'reddit_topic_posts', 'fallback_slug' => 'apidojo/tweet-scraper', 'reason' => 'Topic-specific social conversation evidence.']);
                    }
                    if ($slot === 'reddit_topic_posts') {
                        $sources[] = self::source('reddit_topic_posts', 'Reddit Topic Posts', 'social_conversation', 'discussion_posts', 'reddit', [
                            'searchTerms' => array_slice($discussion_terms, 0, 4),
                            'searches' => array_slice($discussion_terms, 0, 4),
                            'queries' => array_slice($discussion_terms, 0, 4),
                            'search' => $discussion_terms[0] ?? $primary,
                            'maxItems' => min(30, $candidate_pool),
                            'sort' => 'relevance',
                            'time' => in_array($duration, ['24h','1d'], true) ? 'day' : ($duration === '12m' ? 'year' : 'month'),
                            'searchPosts' => true,
                            'searchComments' => false,
                            'includeNSFW' => false,
                        ], ['stage' => 2, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_to' => 'x_topic_posts', 'fallback_slug' => 'trudax/reddit-scraper-lite', 'reason' => 'Discussion and pain-point validation.']);
                    }
                    if ($slot === 'youtube_topic_videos') {
                        $sources[] = self::source('youtube_topic_videos', 'YouTube Topic Videos', 'content_format', 'topic_videos', 'youtube', [
                            'searchQueries' => array_slice($video_terms, 0, 4),
                            'maxResults' => min(20, $candidate_pool),
                            'maxItems' => min(20, $candidate_pool),
                            'sort' => $sort_by === 'freshness' ? 'date' : 'relevance',
                            'sortBy' => $sort_by === 'freshness' ? 'date' : 'relevance',
                            'publishedAfter' => self::published_after($duration),
                            'language' => $language,
                            'country' => $country,
                        ], ['stage' => 2, 'cost_level' => 'medium', 'reliability_level' => 'medium', 'fallback_to' => 'google_search_freshness', 'fallback_slug' => 'streamers/youtube-scraper', 'reason' => 'Content-format evidence and educational/video intent validation.']);
                    }
                    if ($slot === 'tiktok_topic_videos') {
                        $sources[] = self::source('tiktok_topic_videos', 'TikTok Topic Videos', 'content_format', 'topic_videos', 'tiktok', [
                            'searchQueries' => array_slice($platform_terms ?: [$primary], 0, 4),
                            'hashtags' => array_slice($hashtags, 0, 4),
                            'maxItems' => min(25, $candidate_pool),
                            'resultsPerPage' => min(25, $candidate_pool),
                            'sortType' => $sort_by === 'freshness' ? 'LATEST' : 'RELEVANCE',
                            'searchSection' => 'videos',
                            'dateRange' => $duration,
                            'proxyCountryCode' => $country,
                        ], ['stage' => 2, 'cost_level' => 'high', 'reliability_level' => 'medium', 'fallback_to' => 'tiktok_hashtag_trends', 'fallback_slug' => 'clockworks/tiktok-scraper', 'requires_rental_or_access' => true, 'reason' => 'Topic-specific short-form video examples.']);
                    }
                }
            }
        }

        if ($budget === 'deep_scan') {
            $sources[] = self::source('google_trends_trending_now', 'Google Trends Trending Now', 'market_context', 'ambient_trending_now', 'google_trends', [
                'geo' => $country ?: 'US',
                'country' => $country ?: 'US',
                'maxItems' => min(50, $candidate_pool),
            ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'low', 'reliability_level' => 'medium', 'fallback_slug' => 'data_xplorer/google-trends-trending-now', 'reason' => 'Ambient country-level trend context only.']);

            $sources[] = self::source('x_trend_list', 'X Trend List', 'social_trend_feed', 'ambient_trend_list', 'twitter', [
                'country' => $country ?: 'worldwide',
                'maxItems' => min(50, $candidate_pool),
            ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'medium', 'reliability_level' => 'medium', 'fallback_to' => 'x_topic_posts', 'fallback_slug' => 'karamelo/twitter-trends-scraper', 'reason' => 'Country-level ambient trends; not direct evidence unless semantically matched.']);

            $sources[] = self::source('tiktok_hashtag_trends', 'TikTok Hashtag Trends', 'social_trend_feed', 'hashtag_trends', 'tiktok', [
                'hashtags' => array_slice($hashtags, 0, 5),
                'maxItems' => min(25, $candidate_pool),
                'resultsPerPage' => min(25, $candidate_pool),
            ], ['stage' => 3, 'cost_level' => 'high', 'reliability_level' => 'medium', 'fallback_to' => 'tiktok_topic_videos', 'fallback_slug' => 'lexissolutions/tiktok-trending-hashtags', 'requires_rental_or_access' => true, 'reason' => 'Hashtag trend validation; contract failures fall back to topic videos.']);

            $sources[] = self::source('tiktok_trending_videos', 'TikTok Trending Videos', 'social_trend_feed', 'ambient_trending_videos', 'tiktok', [
                'maxItems' => min(25, $candidate_pool),
                'country' => $country,
            ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'high', 'reliability_level' => 'low', 'fallback_to' => 'tiktok_topic_videos', 'fallback_slug' => 'data_xplorer/tiktok-trends', 'requires_rental_or_access' => true, 'reason' => 'Ambient trending videos only.']);

            $sources[] = self::source('youtube_trending_categories', 'YouTube Trending Categories', 'content_format', 'ambient_trending_categories', 'youtube', [
                'country' => $country ?: 'ES',
                'maxResults' => min(25, $candidate_pool),
                'maxItems' => min(25, $candidate_pool),
            ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'medium', 'reliability_level' => 'medium', 'fallback_to' => 'youtube_topic_videos', 'fallback_slug' => 'eunit/youtube-trending-videos-by-categories', 'reason' => 'Generic category trend context only.']);

            $sources[] = self::source('reddit_trends', 'Reddit Trends', 'social_trend_feed', 'ambient_reddit_trends', 'reddit', [
                'maxItems' => min(25, $candidate_pool),
                'time' => in_array($duration, ['24h','1d'], true) ? 'day' : 'week',
            ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'medium', 'reliability_level' => 'low', 'fallback_to' => 'reddit_topic_posts', 'fallback_slug' => 'easyapi/reddit-trends-scraper', 'requires_rental_or_access' => true, 'reason' => 'Ambient Reddit trend context.']);

            foreach ([
                'tiktok_trending_creators' => ['TikTok Trending Creators', 'social_trend_feed', 'ambient_creators', 'tiktok', 'lexissolutions/tiktok-trending-creators'],
                'tiktok_trending_sounds' => ['TikTok Trending Sounds', 'social_trend_feed', 'ambient_sounds', 'tiktok', 'alien_force/tiktok-trending-sounds'],
            ] as $slot => $meta) {
                if (self::slot_enabled($slot)) {
                    $sources[] = self::source($slot, $meta[0], $meta[1], $meta[2], $meta[3], [
                        'country' => $country ?: 'ES',
                        'maxItems' => min(20, $candidate_pool),
                    ], ['stage' => 3, 'ambient_only' => true, 'cost_level' => 'high', 'reliability_level' => 'low', 'fallback_slug' => $meta[4], 'requires_rental_or_access' => true, 'reason' => 'Optional ambient TikTok context; never direct proof by itself.']);
                }
            }
        }

        // Explicitly avoid crypto/product/e-commerce actors for generic Trend Finder.
        $sources = array_values(array_filter($sources, static function($source) {
            $slug = strtolower((string) ($source['actor_slug'] ?? ''));
            return strpos($slug, 'gmgn') === false && strpos($slug, 'trendyol') === false;
        }));

        usort($sources, static function($a, $b) {
            $stage_cmp = ((int) ($a['stage'] ?? 1)) <=> ((int) ($b['stage'] ?? 1));
            if ($stage_cmp !== 0) { return $stage_cmp; }
            return (int) empty($a['required']) <=> (int) empty($b['required']);
        });
        $sources = self::clamp_mvp_sources($sources, $max_sources);

        $payloads = [];
        foreach ($sources as $source) {
            if (empty($source['enabled'])) { continue; }
            $payloads[$source['source_key']] = [
                'label' => $source['label'],
                'actor_slug' => $source['actor_slug'],
                'source_family' => $source['source_family'],
                'source_role' => $source['source_role'],
                'platform' => $source['platform'],
                'required' => !empty($source['required']),
                'ambient_only' => !empty($source['ambient_only']),
                'fallback_to' => $source['fallback_to'],
                'stage' => (int) ($source['stage'] ?? 1),
                'estimated_cost_level' => $source['estimated_cost_level'],
                'reliability_level' => $source['reliability_level'],
                'reason' => $source['reason'],
                'input' => $source['input'],
            ];
        }

        return [
            'mode' => $budget,
            'source_mode' => $source_mode,
            'request' => $request,
            'source_plan' => $sources,
            'payloads' => $payloads,
            'expanded_queries' => $expanded,
            'negative_terms' => $expanded['negative_terms'] ?? [],
            'expected_signal_types' => ['direct','adjacent','ambient','format_signal','pain_point_signal','competitor_signal'],
            'desired_final_trends' => $desired_final,
            'max_sources' => $max_sources,
            'sort_by' => $sort_by,
            'adaptive_rules' => [
                'If Google Trends is weak or flat, use search/news freshness.',
                'If x_trend_list is ambient only, use x_topic_posts.',
                'If TikTok hashtag trends fail contract, use tiktok_topic_videos.',
                'If YouTube trending category is generic, use youtube_topic_videos.',
                'If all social sources fail, return validation plan instead of campaign-ready report.',
                'In production MVP, default to cheap_probe/lite and cap source dispatch to three lightweight sources.',
            ],
        ];
    }
}
