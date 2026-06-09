<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Source adapter registry. Every Apify actor can have a different input shape.
 * This prevents generic payloads from being sent to strict actors and provides
 * stable validation/status helpers for retryable source failures.
 */
final class VES_Source_Adapter_Registry {
    private static function normalize_actor_slug($actor_slug) {
        $slug = strtolower(trim((string) $actor_slug));
        $slug = str_replace('~', '/', $slug);
        return preg_replace('/[^a-z0-9_\-\/\.]/', '', $slug);
    }

    public static function adapter_for($source_key, $actor_slug) {
        $source_key = sanitize_key((string) $source_key);
        $slug = self::normalize_actor_slug($actor_slug);
        if ($source_key === 'google_trends' || $source_key === 'google_trends_interest' || $source_key === 'google_trends_trending_now' || strpos($slug, 'google-trends') !== false) {
            if ($slug === 'data_xplorer/google-trends-trending-now' || $source_key === 'google_trends_trending_now') {
                return [
                    'adapter_key' => 'google_trends_trending_now_data_xplorer',
                    'actor_slug' => $slug,
                    'required_fields' => ['country'],
                    'supports_fields' => ['country', 'timeframe', 'categories', 'maxItems', 'proxyConfiguration'],
                    'forbidden_fields' => ['keyword', 'searchTerms', 'isMultiple', 'geo', 'viewedFrom'],
                    'heavy' => false,
                    'description' => 'data_xplorer/google-trends-trending-now requires country and returns ambient market trends.',
                ];
            }
            if ($slug === 'data_xplorer/google-trends-fast-scraper') {
                return [
                    'adapter_key' => 'google_trends_data_xplorer_fast',
                    'actor_slug' => $slug,
                    'required_fields' => ['keyword'],
                    'supports_fields' => ['keyword', 'geo', 'timeRange', 'category', 'maxItems'],
                    'forbidden_fields' => ['searchTerms', 'isMultiple'],
                    'heavy' => false,
                    'description' => 'data_xplorer/google-trends-fast-scraper requires keyword.',
                ];
            }
            if ($slug === 'apify/google-trends-scraper' || $slug === 'danek/google-trends-scraper') {
                return [
                    'adapter_key' => 'google_trends_apify_search_terms',
                    'actor_slug' => $slug,
                    'required_fields' => ['searchTerms'],
                    'supports_fields' => ['searchTerms', 'isMultiple', 'timeRange', 'geo', 'viewedFrom', 'maxItems'],
                    'heavy' => false,
                    'description' => 'Apify Google Trends actor supports searchTerms.',
                ];
            }
            return [
                'adapter_key' => 'google_trends_keyword_safe_default',
                'actor_slug' => $slug,
                'required_fields' => ['keyword'],
                'supports_fields' => ['keyword', 'geo', 'timeRange', 'maxItems'],
                'forbidden_fields' => ['searchTerms', 'isMultiple'],
                'heavy' => false,
                'description' => 'Unknown Google Trends actor: conservative keyword payload.',
            ];
        }
        if ($source_key === 'google_news' || $source_key === 'google_news_freshness' || strpos($slug, 'google-news') !== false || strpos($slug, 'news') !== false) {
            if (strpos($slug, 'data_xplorer') !== false) {
                return [
                    'adapter_key' => 'google_news_keyword_safe',
                    'actor_slug' => $slug,
                    'required_fields' => ['keyword'],
                    'supports_fields' => ['keyword', 'gl', 'hl', 'lr', 'time_period', 'max_items'],
                    'heavy' => false,
                    'description' => 'Google News actor requires keyword-style input.',
                ];
            }
            return [
                'adapter_key' => 'google_news_query_safe',
                'actor_slug' => $slug,
                'required_fields' => ['query'],
                'supports_fields' => ['query', 'keyword', 'gl', 'hl', 'lr', 'time_period', 'max_items'],
                'heavy' => false,
                'description' => 'Google News actor receives normalized query input.',
            ];
        }

        if ($source_key === 'instagram' || strpos($slug, 'instagram') !== false) {
            return [
                'adapter_key' => 'instagram_adapter',
                'actor_slug' => $slug,
                'required_fields' => [],
                'required_any' => ['directUrls', 'startUrls', 'hashtags', 'profiles', 'search'],
                'supports_fields' => ['directUrls', 'startUrls', 'resultsType', 'resultsLimit', 'onlyPostsNewerThan', 'search', 'searchType', 'searchLimit', 'profileScrapeSections', 'resultsPerPage', 'addParentData'],
                'primary_url_fields' => ['url', 'inputUrl'],
                'title_fields' => ['caption', 'text', 'shortCode'],
                'author_fields' => ['ownerUsername', 'username', 'ownerFullName'],
                'date_fields' => ['timestamp', 'takenAt', 'createdAt'],
                'metric_fields' => ['likesCount', 'commentsCount', 'videoViewCount', 'playsCount'],
                'media_fields' => ['displayUrl', 'thumbnailUrl', 'videoUrl'],
                'renderer_type' => 'social_card',
                'heavy' => true,
                'description' => 'Instagram adapter expects explicit hashtags, profile URLs, post URLs, or actor-supported search input. It must not receive business objectives as hashtags.',
                'empty_result_message' => 'Source returned Instagram rows, but they could not be prepared for this view.',
            ];
        }
        if ($source_key === 'tiktok' || strpos($source_key, 'tiktok_') === 0 || strpos($slug, 'tiktok') !== false) {
            if (strpos($slug, 'clockworks/tiktok-hashtag-scraper') !== false || $source_key === 'tiktok_hashtag_trends') {
                return [
                    'adapter_key' => 'tiktok_clockworks_hashtag_adapter',
                    'actor_slug' => $slug,
                    'required_fields' => ['hashtags'],
                    'required_any' => ['hashtags'],
                    'supports_fields' => ['hashtags', 'resultsPerPage'],
                    'forbidden_fields' => ['keywords', 'searchTerms', 'searchQueries', 'startUrls', 'maxItems', 'sortType', 'country', 'language', 'profiles', 'postURLs'],
                    'primary_url_fields' => ['webVideoUrl', 'url', 'videoUrl'],
                    'title_fields' => ['text', 'description', 'caption'],
                    'author_fields' => ['authorMeta.name', 'author', 'authorName', 'username'],
                    'date_fields' => ['createTimeISO', 'createTime', 'postedAt'],
                    'metric_fields' => ['playCount', 'diggCount', 'commentCount', 'shareCount', 'collectCount'],
                    'media_fields' => ['coverUrl','cover_url','thumbnail','thumbnailUrl','thumbnail_url','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.coverUrl','video.thumbnail','video.thumbnailUrl','video.dynamicCover','video.originCover','covers.0','media.0.thumbnail','media.0.thumbnailUrl','channel.avatar','author.avatar'],
                    'renderer_type' => 'social_card',
                    'heavy' => true,
                    'description' => 'TikTok hashtag source uses hashtags only.',
                    'empty_result_message' => 'Source returned TikTok hashtag rows, but they could not be prepared for this view.',
                ];
            }
            if (strpos($slug, 'clockworks/tiktok-scraper') !== false || $source_key === 'tiktok_topic_videos') {
                return [
                    'adapter_key' => 'tiktok_clockworks_search_adapter',
                    'actor_slug' => $slug,
                    'required_fields' => [],
                    'required_any' => ['searchQueries', 'hashtags', 'profiles', 'postURLs'],
                    'supports_fields' => ['searchQueries', 'hashtags', 'profiles', 'postURLs', 'resultsPerPage', 'searchSection', 'videoSearchSorting', 'proxyCountryCode', 'oldestPostDateUnified', 'newestPostDate'],
                    'forbidden_fields' => ['keywords', 'searchTerms', 'startUrls', 'maxItems', 'sortType', 'country', 'language'],
                    'primary_url_fields' => ['webVideoUrl', 'url', 'videoUrl'],
                    'title_fields' => ['text', 'description', 'caption'],
                    'author_fields' => ['authorMeta.name', 'author', 'authorName', 'username'],
                    'date_fields' => ['createTimeISO', 'createTime', 'postedAt'],
                    'metric_fields' => ['playCount', 'diggCount', 'commentCount', 'shareCount', 'collectCount'],
                    'media_fields' => ['coverUrl','cover_url','thumbnail','thumbnailUrl','thumbnail_url','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.coverUrl','video.thumbnail','video.thumbnailUrl','video.dynamicCover','video.originCover','covers.0','media.0.thumbnail','media.0.thumbnailUrl','channel.avatar','author.avatar'],
                    'renderer_type' => 'social_card',
                    'heavy' => true,
                    'description' => 'TikTok topic video source uses Clockworks searchQueries/hashtags; no duplicate generic fields.',
                    'empty_result_message' => 'Source returned TikTok rows, but they could not be prepared for this view.',
                ];
            }
            return [
                'adapter_key' => 'tiktok_adapter',
                'actor_slug' => $slug,
                'required_fields' => [],
                'required_any' => ['keywords', 'hashtags', 'startUrls', 'profiles', 'searchTerms'],
                'supports_fields' => ['keywords', 'hashtags', 'startUrls', 'profiles', 'resultsPerPage', 'maxItems', 'sortType', 'publishTime'],
                'primary_url_fields' => ['webVideoUrl', 'url', 'videoUrl'],
                'title_fields' => ['text', 'description', 'caption'],
                'author_fields' => ['authorMeta.name', 'author', 'authorName', 'username'],
                'date_fields' => ['createTimeISO', 'createTime', 'postedAt'],
                'metric_fields' => ['playCount', 'diggCount', 'commentCount', 'shareCount', 'collectCount'],
                'media_fields' => ['coverUrl','cover_url','thumbnail','thumbnailUrl','thumbnail_url','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.coverUrl','video.thumbnail','video.thumbnailUrl','video.dynamicCover','video.originCover','covers.0','media.0.thumbnail','media.0.thumbnailUrl','channel.avatar','author.avatar'],
                'renderer_type' => 'social_card',
                'heavy' => true,
                'description' => 'TikTok adapter expects short keywords/hashtags or direct URLs, never long business objectives.',
                'empty_result_message' => 'Source returned TikTok rows, but they could not be prepared for this view.',
            ];
        }
        if ($source_key === 'semrush' || $source_key === 'semrush_keyword_context' || strpos($slug, 'semrush') !== false) {
            return [
                'adapter_key' => 'semrush_seo_adapter',
                'actor_slug' => $slug,
                'required_fields' => [],
                'required_any' => ['keyword', 'urls', 'include_top_websites'],
                'supports_fields' => ['keyword', 'urls', 'country', 'database', 'language', 'limit', 'maxResults', 'includeRelated', 'includeQuestions', 'include_top_websites', 'industry_top_websites', 'country_top_websites', 'include_ai_visibility', 'include_overview', 'include_authority', 'include_traffic', 'include_backlinks', 'include_competitors', 'include_keyword_volume', 'include_keywords_ranking', 'include_seo', 'include_serp'],
                'primary_url_fields' => ['url', 'domain'],
                'title_fields' => ['keyword', 'domain', 'url', 'title'],
                'author_fields' => ['source'],
                'date_fields' => [],
                'metric_fields' => ['avgMonthlySearches', 'searchVolume', 'cpc', 'competition', 'keywordDifficulty', 'authorityScore', 'traffic', 'backlinks'],
                'media_fields' => [],
                'renderer_type' => 'seo_table',
                'heavy' => false,
                'description' => 'Semrush adapter accepts short keyword research payloads or domain/SEO payloads; objectives and context are AI-only.',
                'empty_result_message' => 'Semrush returned rows, but they were not valid keyword/domain rows.',
            ];
        }
        if ($source_key === 'google_search' || $source_key === 'google_intel' || $source_key === 'google_search_freshness' || $source_key === 'serp_freshness' || strpos($slug, 'google-search') !== false || strpos($slug, 'google-serp') !== false) {
            return [
                'adapter_key' => 'google_search_adapter',
                'actor_slug' => $slug,
                'required_any' => ['query', 'queries', 'searchTerms', 'keyword'],
                'supports_fields' => ['query', 'queries', 'searchTerms', 'keyword', 'country', 'language', 'maxResults'],
                'primary_url_fields' => ['url', 'link'],
                'title_fields' => ['title', 'name'],
                'author_fields' => ['source', 'domain'],
                'date_fields' => ['date', 'publishedAt'],
                'metric_fields' => ['rank', 'position'],
                'renderer_type' => 'search_result',
                'heavy' => false,
                'description' => 'Google Search adapter normalizes SERP-style rows.',
            ];
        }
        if ($source_key === 'linkedin' || strpos($slug, 'linkedin') !== false) {
            return [
                'adapter_key' => 'linkedin_post_adapter',
                'actor_slug' => $slug,
                'required_any' => ['startUrls', 'companyUrls', 'profileUrls', 'keywords', 'query', 'searchQuery'],
                'supports_fields' => ['startUrls', 'companyUrls', 'profileUrls', 'keywords', 'query', 'searchQuery', 'locations', 'industryIds', 'seniorityIds', 'functionIds', 'companyHeadcount'],
                'primary_url_fields' => ['url', 'postUrl', 'profileUrl', 'companyUrl'],
                'title_fields' => ['text', 'headline', 'name'],
                'author_fields' => ['authorName', 'companyName', 'profileName'],
                'date_fields' => ['postedAt', 'date', 'createdAt'],
                'metric_fields' => ['likesCount', 'commentsCount', 'reactionsCount'],
                'renderer_type' => 'professional_card',
                'heavy' => true,
                'description' => 'LinkedIn adapter supports approved actor modes only; raw provider IDs are admin/pro diagnostics.',
            ];
        }
        if ($source_key === 'youtube' || $source_key === 'youtube_topic_videos' || $source_key === 'youtube_trending_categories' || strpos($slug, 'youtube') !== false) {
            return [
                'adapter_key' => 'youtube_adapter',
                'actor_slug' => $slug,
                'required_any' => ['searchQueries', 'startUrls', 'search', 'keywords'],
                'supports_fields' => ['searchQueries', 'startUrls', 'search', 'keywords', 'maxResults', 'maxItems', 'sortBy', 'publishedAfter', 'language', 'country', 'includeTranscript', 'subtitlesLanguage', 'maxResultsShorts', 'maxResultStreams'],
                'primary_url_fields' => ['url', 'videoUrl'],
                'title_fields' => ['title', 'text'],
                'author_fields' => ['channelName', 'author', 'channelTitle'],
                'date_fields' => ['date', 'publishedAt'],
                'metric_fields' => ['viewCount', 'likes', 'commentsCount'],
                'renderer_type' => 'video_card',
                'heavy' => true,
                'description' => 'YouTube adapter normalizes videos/comments/transcripts into evidence rows.',
            ];
        }
        if ($source_key === 'twitter' || $source_key === 'x' || $source_key === 'x_topic_posts' || $source_key === 'x_trend_list' || strpos($slug, 'twitter') !== false || strpos($slug, 'tweet') !== false) {
            return [
                'adapter_key' => 'twitter_adapter',
                'actor_slug' => $slug,
                'required_any' => $source_key === 'x_trend_list' ? ['country', 'location', 'geo'] : ['searchTerms', 'queries', 'startUrls', 'handles'],
                'supports_fields' => $source_key === 'x_trend_list' ? ['country', 'location', 'geo', 'maxItems'] : ['searchTerms', 'queries', 'startUrls', 'handles', 'maxItems', 'sort', 'since', 'tweetLanguage', 'startDate', 'endDate'],
                'primary_url_fields' => ['url', 'tweetUrl'],
                'title_fields' => ['text', 'fullText'],
                'author_fields' => ['authorName', 'username', 'userName'],
                'date_fields' => ['createdAt', 'date'],
                'metric_fields' => ['replyCount', 'retweetCount', 'likeCount', 'quoteCount'],
                'renderer_type' => 'social_card',
                'heavy' => true,
                'description' => 'X/Twitter adapter normalizes posts and trends.',
            ];
        }
        if ($source_key === 'reddit' || $source_key === 'reddit_topic_posts' || $source_key === 'reddit_trends' || strpos($slug, 'reddit') !== false) {
            return [
                'adapter_key' => 'reddit_adapter',
                'actor_slug' => $slug,
                'required_any' => ['search', 'queries', 'startUrls', 'subreddits'],
                'supports_fields' => ['search', 'queries', 'searches', 'startUrls', 'subreddits', 'maxItems', 'maxPostCount', 'maxComments', 'searchPosts', 'searchComments', 'searchCommunities', 'sort', 'time', 'includeNSFW', 'proxy'],
                'primary_url_fields' => ['url', 'permalink'],
                'title_fields' => ['title', 'body', 'text'],
                'author_fields' => ['author', 'subreddit'],
                'date_fields' => ['createdAt', 'createdUtc'],
                'metric_fields' => ['score', 'numComments', 'upvoteRatio'],
                'renderer_type' => 'community_card',
                'heavy' => false,
                'description' => 'Reddit adapter normalizes subreddit/search rows.',
            ];
        }
        if ($source_key === 'facebook_ads' || $source_key === 'ad_creative_scraper' || strpos($slug, 'facebook-ads') !== false || strpos($slug, 'meta-ads') !== false) {
            return [
                'adapter_key' => 'facebook_ads_adapter',
                'actor_slug' => $slug,
                'required_any' => ['urls', 'startUrls', 'pageUrls', 'searchTerms', 'domains'],
                'supports_fields' => ['urls', 'startUrls', 'pageUrls', 'searchTerms', 'domains', 'country', 'maxItems', 'count', 'resultsLimit', 'activeStatus', 'scrapePageAds.activeStatus', 'scrapePageAds.sortBy', 'scrapePageAds.countryCode', 'scrapePageAds.period'],
                'primary_url_fields' => ['adSnapshotUrl', 'url', 'pageUrl'],
                'title_fields' => ['adText', 'body', 'headline'],
                'author_fields' => ['pageName', 'advertiserName'],
                'date_fields' => ['startDate', 'endDate', 'createdAt'],
                'metric_fields' => ['impressions', 'spend', 'reach'],
                'renderer_type' => 'ad_card',
                'heavy' => true,
                'description' => 'Facebook Ads adapter normalizes creative transparency rows.',
            ];
        }
        if ($source_key === 'google_ads' || $source_key === 'competitor_creative_scraper' || strpos($slug, 'google-ads') !== false || strpos($slug, 'google_ads') !== false) {
            return [
                'adapter_key' => 'google_ads_adapter',
                'actor_slug' => $slug,
                'required_any' => ['searchTargets', 'domain', 'domains', 'text'],
                'supports_fields' => ['searchTargets', 'domain', 'domains', 'text', 'region', 'geoTargetRegion', 'resultsPerQuery', 'maxAdvertiserAccounts', 'maxDomainMatches', 'targetPlatform', 'adFormatType', 'timeRangePreset', 'customStartDate', 'enableUrlFiltering', 'maxItems'],
                'primary_url_fields' => ['ad_creative_url', 'url', 'target_domain'],
                'title_fields' => ['headline', 'title', 'body'],
                'author_fields' => ['advertiser', 'target_domain'],
                'date_fields' => ['first_shown', 'last_shown'],
                'metric_fields' => ['format', 'region'],
                'renderer_type' => 'ad_table',
                'heavy' => false,
                'description' => 'Google Ads adapter normalizes Ads Transparency Center rows.',
            ];
        }
        if ($source_key === 'facebook_posts' || ($source_key === 'facebook' && strpos($slug, 'ads') === false) || strpos($slug, 'facebook-post') !== false) {
            return [
                'adapter_key' => 'facebook_posts_adapter',
                'actor_slug' => $slug,
                'required_any' => ['startUrls', 'searchQueries', 'queries', 'searchTerms'],
                'supports_fields' => ['startUrls', 'searchQueries', 'queries', 'searchTerms', 'resultsLimit', 'maxItems', 'sort', 'dateRange'],
                'primary_url_fields' => ['url', 'postUrl', 'pageUrl'],
                'title_fields' => ['text', 'message', 'caption', 'title'],
                'author_fields' => ['pageName', 'author', 'profileName'],
                'date_fields' => ['date', 'time', 'createdAt', 'postedAt'],
                'metric_fields' => ['likes', 'comments', 'shares', 'reactions'],
                'renderer_type' => 'social_card',
                'heavy' => true,
                'description' => 'Facebook posts adapter normalizes public post rows.',
            ];
        }
        if ($source_key === 'google_maps' || $source_key === 'google_reviews' || strpos($slug, 'google-maps') !== false || strpos($slug, 'maps') !== false || strpos($slug, 'reviews') !== false) {
            return [
                'adapter_key' => $source_key === 'google_reviews' ? 'google_reviews_adapter' : 'google_maps_adapter',
                'actor_slug' => $slug,
                'required_any' => ['searchStringsArray', 'search', 'startUrls'],
                'supports_fields' => ['searchStringsArray', 'search', 'startUrls', 'countryCode', 'language', 'maxCrawledPlacesPerSearch', 'reviewsLimit'],
                'primary_url_fields' => ['url', 'placeUrl', 'website'],
                'title_fields' => ['title', 'name', 'reviewText'],
                'author_fields' => ['categoryName', 'reviewerName', 'author'],
                'date_fields' => ['publishedAtDate', 'date', 'time'],
                'metric_fields' => ['totalScore', 'reviewsCount', 'stars', 'rating'],
                'renderer_type' => 'maps_table',
                'heavy' => false,
                'description' => 'Google Maps / Reviews adapter normalizes local result and review rows.',
            ];
        }
        if ($source_key === 'pinterest' || strpos($slug, 'pinterest') !== false) {
            return [
                'adapter_key' => 'pinterest_adapter',
                'actor_slug' => $slug,
                'required_any' => ['searchTerms', 'startUrls'],
                'supports_fields' => ['searchTerms', 'startUrls', 'maxItems', 'sort'],
                'primary_url_fields' => ['url', 'pinUrl', 'link'],
                'title_fields' => ['title', 'description', 'text'],
                'author_fields' => ['pinner', 'author', 'boardName'],
                'date_fields' => ['createdAt', 'date'],
                'metric_fields' => ['repinCount', 'commentCount', 'reactionCount'],
                'renderer_type' => 'visual_card',
                'heavy' => false,
                'description' => 'Pinterest adapter normalizes pins and boards.',
            ];
        }
        return [
            'adapter_key' => 'generic_apify',
            'actor_slug' => $slug,
            'required_fields' => [],
            'supports_fields' => [],
            'heavy' => self::is_heavy_actor($actor_slug),
            'description' => 'Generic actor; existing input shape is preserved.',
        ];
    }

    public static function is_heavy_actor($actor_slug) {
        $slug = self::normalize_actor_slug($actor_slug);
        return (bool) preg_match('/instagram|tiktok|youtube|facebook|ads|crawler|scraper/i', $slug);
    }

    private static function first_topic($request, $clusters = []) {
        $request = is_array($request) ? $request : [];
        $clusters = is_array($clusters) ? $clusters : [];
        foreach ($clusters as $candidate) {
            $candidate = trim(preg_replace('/\s+/', ' ', (string) $candidate));
            if ($candidate !== '') { return sanitize_text_field($candidate); }
        }
        foreach (['topic','query','keyword','primary_topic'] as $key) {
            $candidate = trim(preg_replace('/\s+/', ' ', (string) ($request[$key] ?? '')));
            if ($candidate !== '') { return sanitize_text_field($candidate); }
        }
        return '';
    }

    public static function build_input($source_key, $actor_slug, $request, $context = []) {
        $source_key = sanitize_key((string) $source_key);
        $request = is_array($request) ? $request : [];
        $context = is_array($context) ? $context : [];
        $clusters = is_array($context['clusters'] ?? null) ? $context['clusters'] : [];
        $existing = is_array($context['input'] ?? null) ? $context['input'] : [];
        $adapter = self::adapter_for($source_key, $actor_slug);
        $planner_source_keys = ['google_trends_interest','google_trends_trending_now','google_search_freshness','google_news_freshness','semrush_keyword_context','x_topic_posts','x_trend_list','tiktok_topic_videos','tiktok_hashtag_trends','tiktok_trending_videos','tiktok_trending_creators','tiktok_trending_sounds','youtube_topic_videos','youtube_trending_categories','reddit_topic_posts','reddit_trends','ad_creative_scraper','competitor_creative_scraper'];
        if (!empty($existing) && in_array($source_key, $planner_source_keys, true)) {
            $platform_hint_map = [
                'google_trends_interest' => 'google_trends',
                'google_trends_trending_now' => 'google_trends',
                'google_search_freshness' => 'google_search',
                'google_news_freshness' => 'google_search',
                'semrush_keyword_context' => 'semrush',
                'x_topic_posts' => 'twitter',
                'x_trend_list' => 'twitter',
                'tiktok_topic_videos' => 'tiktok',
                'tiktok_hashtag_trends' => 'tiktok',
                'tiktok_trending_videos' => 'tiktok',
                'tiktok_trending_creators' => 'tiktok',
                'tiktok_trending_sounds' => 'tiktok',
                'youtube_topic_videos' => 'youtube',
                'youtube_trending_categories' => 'youtube',
                'reddit_topic_posts' => 'reddit',
                'reddit_trends' => 'reddit',
                'ad_creative_scraper' => 'facebook_ads',
                'competitor_creative_scraper' => 'google_ads',
            ];
            $platform_hint = $platform_hint_map[$source_key] ?? $source_key;
            return class_exists('VES_Query_Planner') ? VES_Query_Planner::prune_payload_for_actor_contract($platform_hint, $actor_slug, $existing) : $existing;
        }

        $source_to_platform = [
            'google_trends' => 'google_trends',
            'google_trends_interest' => 'google_trends',
            'google_trends_trending_now' => 'google_trends',
            'google_search_freshness' => 'google_search',
            'google_news_freshness' => 'google_search',
            'serp_freshness' => 'google_search',
            'semrush_keyword_context' => 'semrush',
            'x_topic_posts' => 'twitter',
            'x_trend_list' => 'twitter',
            'tiktok_trend_list' => 'tiktok',
            'tiktok_topic_videos' => 'tiktok',
            'tiktok_hashtag_trends' => 'tiktok',
            'tiktok_trending_videos' => 'tiktok',
            'tiktok_trending_creators' => 'tiktok',
            'tiktok_trending_sounds' => 'tiktok',
            'youtube_topic_videos' => 'youtube',
            'youtube_trending_categories' => 'youtube',
            'youtube_trending_categories_optional' => 'youtube',
            'reddit_topic_posts' => 'reddit',
            'reddit_trends' => 'reddit',
            'reddit_trends_optional' => 'reddit',
            'ad_creative_scraper' => 'facebook_ads',
            'competitor_creative_scraper' => 'google_ads',
            'google_news' => 'google_news',
            'google_search' => 'google_search',
            'google_maps' => 'google_maps',
            'google_reviews' => 'google_reviews',
            'semrush' => 'semrush',
            'ahrefs' => 'ahrefs',
            'moz' => 'moz',
            'instagram' => 'instagram',
            'tiktok' => 'tiktok',
            'youtube' => 'youtube',
            'twitter' => 'twitter',
            'x' => 'twitter',
            'reddit' => 'reddit',
            'linkedin' => 'linkedin',
            'facebook' => 'facebook_posts',
            'facebook_posts' => 'facebook_posts',
            'facebook_ads' => 'facebook_ads',
            'meta_ads' => 'facebook_ads',
            'google_ads' => 'google_ads',
            'pinterest' => 'pinterest',
        ];
        $platform = $source_to_platform[$source_key] ?? $source_key;

        // Preserve the older Google Trends cluster behaviour, but route the shape
        // through the same source-specific planner so required field names stay
        // actor-safe before dispatch.
        if (!empty($clusters) && empty($request['queryTerms'] ?? '') && empty($request['targets'] ?? '')) {
            $request['queryTerms'] = implode("\n", array_slice(array_map('sanitize_text_field', $clusters), 0, 8));
        }
        if (($request['queryTerms'] ?? '') === '' && ($request['targets'] ?? '') === '') {
            $topic = self::first_topic($request, $clusters);
            if ($topic !== '') { $request['queryTerms'] = $topic; }
        }

        if (class_exists('VES_Query_Planner')) {
            $normalized = VES_Query_Planner::normalize_request_fields('adapter_registry', $platform, $request);
            $planned = VES_Query_Planner::build_actor_payload('adapter_registry', $platform, $actor_slug, $normalized);
            if (!empty($planned)) {
                $adapter_key = sanitize_key((string) ($adapter['adapter_key'] ?? ''));
                if (in_array($adapter_key, ['google_trends_data_xplorer_fast', 'google_trends_keyword_safe_default'], true)) {
                    $keyword = sanitize_text_field((string) ($planned['keyword'] ?? ($planned['searchTerms'][0] ?? ($planned['searchTerms'] ?? ''))));
                    $planned = array_filter([
                        'keyword' => $keyword,
                        'geo' => $planned['geo'] ?? '',
                        'timeRange' => $planned['timeRange'] ?? '',
                        'maxItems' => $planned['maxItems'] ?? 25,
                    ], static function($value) { return $value !== '' && $value !== [] && $value !== null; });
                } elseif ($adapter_key === 'google_trends_apify_search_terms') {
                    $terms = (array) ($planned['searchTerms'] ?? []);
                    if (empty($terms) && !empty($planned['keyword'])) { $terms = [$planned['keyword']]; }
                    $planned = array_filter([
                        'searchTerms' => array_values(array_filter(array_map('sanitize_text_field', $terms))),
                        'isMultiple' => count($terms) > 1,
                        'timeRange' => $planned['timeRange'] ?? '',
                        'geo' => $planned['geo'] ?? '',
                        'viewedFrom' => strtolower((string) ($planned['geo'] ?? 'us')),
                        'maxItems' => $planned['maxItems'] ?? 25,
                    ], static function($value) { return $value !== '' && $value !== [] && $value !== null; });
                } elseif ($adapter_key === 'google_news_keyword_safe') {
                    $keyword = sanitize_text_field((string) ($planned['keyword'] ?? ($planned['query'] ?? ($planned['queries'][0] ?? ''))));
                    $planned['keyword'] = $keyword;
                    unset($planned['query'], $planned['queries']);
                } elseif ($adapter_key === 'google_news_query_safe') {
                    $query = sanitize_text_field((string) ($planned['query'] ?? ($planned['keyword'] ?? ($planned['queries'][0] ?? ''))));
                    $planned['query'] = $query;
                }
                // Existing admin-configured actor defaults remain in place, but the
                // planned provider-safe fields win over stale/legacy target fields.
                $merged = array_merge($existing, $planned);
                if (class_exists('VES_Query_Planner')) {
                    $merged = VES_Query_Planner::prune_payload_for_actor_contract($platform, $actor_slug, $merged);
                }
                return $merged;
            }
        }

        if (class_exists('VES_Query_Planner')) {
            $existing = VES_Query_Planner::prune_payload_for_actor_contract($platform, $actor_slug, $existing);
        }
        return $existing;
    }

    private static function first_line($value) {
        if (is_array($value)) { $value = reset($value); }
        $parts = preg_split('/\r\n|\r|\n/', (string) $value);
        foreach ((array) $parts as $part) {
            $part = trim(preg_replace('/\s+/', ' ', (string) $part));
            if ($part !== '') { return sanitize_text_field($part); }
        }
        return '';
    }

    public static function normalize_brand_audit_google_payload($source_key, $module_key, $actor_slug, $input, $request = []) {
        $source_key = sanitize_key((string) $source_key);
        $module_key = sanitize_key((string) $module_key);
        $input = is_array($input) ? $input : [];
        $request = is_array($request) ? $request : [];
        $adapter = self::adapter_for($source_key === 'google_news' || $module_key === 'news' ? 'google_news' : ($source_key === 'google_trends' || $module_key === 'trends' ? 'google_trends' : $source_key), $actor_slug);
        $country = strtoupper(sanitize_text_field((string) ($request['country'] ?? $input['geo'] ?? 'ES')));
        $geo = $country === 'NONE' ? '' : $country;
        if ($module_key === 'trends' || $source_key === 'google_trends') {
            $term = self::first_line($input['search_terms'] ?? ($input['searchTerms'] ?? ($input['keyword'] ?? ($request['brand_name'] ?? ''))));
            if (($adapter['adapter_key'] ?? '') === 'google_trends_apify_search_terms') {
                $terms = array_values(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', (string) ($input['search_terms'] ?? $term)))));
                return array_filter([
                    'searchTerms' => array_slice($terms, 0, 8),
                    'isMultiple' => count($terms) > 1,
                    'timeRange' => sanitize_text_field((string) ($input['time_range'] ?? $input['timeRange'] ?? 'today 3-m')),
                    'geo' => $geo,
                    'maxItems' => max(1, (int) ($input['max_items'] ?? $input['maxItems'] ?? 25)),
                ], static function($value) { return $value !== '' && $value !== [] && $value !== null; });
            }
            return array_filter([
                'keyword' => $term,
                'timeRange' => sanitize_text_field((string) ($input['time_range'] ?? $input['timeRange'] ?? 'today 3-m')),
                'geo' => $geo,
                'maxItems' => max(1, (int) ($input['max_items'] ?? $input['maxItems'] ?? 25)),
            ], static function($value) { return $value !== '' && $value !== [] && $value !== null; });
        }
        if ($module_key === 'news' || $source_key === 'google_news') {
            $query = self::first_line($input['query'] ?? ($input['keyword'] ?? ($request['brand_name'] ?? '')));
            $base = [
                'gl' => strtolower($geo),
                'hl' => sanitize_key((string) ($input['hl'] ?? $request['language'] ?? 'es')),
                'lr' => sanitize_text_field((string) ($input['lr'] ?? '')),
                'time_period' => sanitize_text_field((string) ($input['time_period'] ?? 'last_year')),
                'max_items' => max(1, (int) ($input['max_items'] ?? 30)),
            ];
            if (($adapter['adapter_key'] ?? '') === 'google_news_keyword_safe') {
                return array_filter(array_merge(['keyword' => $query], $base), static function($value) { return $value !== '' && $value !== [] && $value !== null; });
            }
            return array_filter(array_merge(['query' => $query], $base), static function($value) { return $value !== '' && $value !== [] && $value !== null; });
        }
        return $input;
    }

    public static function normalize_trend_payload($source_key, $payload, $request = []) {
        $payload = is_array($payload) ? $payload : [];
        $actor_slug = (string) ($payload['actor_slug'] ?? '');
        $context = [
            'clusters' => is_array($request['clusters'] ?? null) ? $request['clusters'] : [],
            'input' => is_array($payload['input'] ?? null) ? $payload['input'] : [],
        ];
        $payload['input'] = self::build_input($source_key, $actor_slug, $request, $context);
        $adapter = self::adapter_for($source_key, $actor_slug);
        $payload['adapter_key'] = sanitize_key((string) ($adapter['adapter_key'] ?? 'generic_apify'));
        $payload['adapter_required_fields'] = array_values((array) ($adapter['required_fields'] ?? []));
        return $payload;
    }

    public static function prepare_source_payload($source_key, $payload, $request = []) {
        return self::normalize_trend_payload($source_key, $payload, $request);
    }

    private static function item_text($item) {
        $item = is_array($item) ? $item : [];
        return strtolower(wp_strip_all_tags(wp_json_encode($item, JSON_UNESCAPED_UNICODE)));
    }

    private static function extract_semantic_label($item) {
        $item = is_array($item) ? $item : [];
        foreach (['trend_name','name','title','label','query','keyword','hashtag','hashtagName','musicTitle','postDesc','description','text'] as $key) {
            if (!empty($item[$key]) && is_scalar($item[$key])) {
                $candidate = (string) $item[$key];
                if (class_exists('VES_Trend_Label_Guard')) {
                    $candidate = VES_Trend_Label_Guard::clean_label($candidate, $key);
                } else {
                    $candidate = trim(wp_strip_all_tags($candidate));
                }
                if ($candidate !== '') { return $candidate; }
            }
        }
        return '';
    }

    private static function read_path($item, $paths, $default = '') {
        $item = is_array($item) ? $item : [];
        foreach ((array) $paths as $path) {
            $path = (string) $path;
            if ($path === '') { continue; }
            $value = $item;
            foreach (explode('.', $path) as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } else {
                    $value = null;
                    break;
                }
            }
            if (is_array($value)) {
                if (!empty($value)) { return $value; }
            } elseif ($value !== null && trim((string) $value) !== '') {
                return is_scalar($value) ? $value : $default;
            }
        }
        return $default;
    }

    private static function text_value($item, $paths, $default = '') {
        $value = self::read_path($item, $paths, $default);
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $v) {
                if (is_scalar($v)) { $flat[] = (string) $v; }
            }
            $value = implode(' ', $flat);
        }
        $value = trim(wp_strip_all_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return sanitize_text_field($value);
    }

    private static function textarea_value($item, $paths, $default = '') {
        $value = self::read_path($item, $paths, $default);
        if (is_array($value)) { $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE); }
        return sanitize_textarea_field(trim(wp_strip_all_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    private static function url_value($item, $paths) {
        $value = self::read_path($item, $paths, '');
        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_scalar($v) && preg_match('#^https?://#i', (string) $v)) { $value = $v; break; }
            }
        }
        $value = trim((string) $value);
        return $value !== '' ? esc_url_raw($value) : '';
    }

    private static function number_value($item, $paths, $default = 0) {
        $value = self::read_path($item, $paths, null);
        if (is_array($value)) { return $default; }
        if ($value === null || $value === '') { return $default; }
        if (is_string($value)) { $value = str_replace([',', '$', '€', '%'], '', $value); }
        return is_numeric($value) ? (0 + $value) : $default;
    }

    private static function array_value($item, $paths) {
        $value = self::read_path($item, $paths, []);
        if (is_string($value)) {
            $parts = preg_split('/[,\s]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique(array_map('sanitize_text_field', $parts)));
        }
        if (!is_array($value)) { return []; }
        $out = [];
        foreach ($value as $v) {
            if (is_array($v)) {
                $name = self::text_value($v, ['name','tag','hashtag','query','title']);
                if ($name !== '') { $out[] = $name; }
            } elseif (is_scalar($v)) {
                $v = sanitize_text_field((string) $v);
                if ($v !== '') { $out[] = $v; }
            }
        }
        return array_values(array_unique($out));
    }

    private static function source_family($source_key, $actor_slug = '') {
        $source_key = sanitize_key((string) $source_key);
        $slug = self::normalize_actor_slug($actor_slug);
        if ($source_key === 'meta_ads') { return 'facebook_ads'; }
        if ($source_key === 'x') { return 'twitter'; }
        if ($source_key === 'google_reviews') { return 'google_maps'; }
        if (strpos($slug, 'google-trends') !== false) { return 'google_trends'; }
        if (strpos($slug, 'google-news') !== false || ($source_key === 'google_news')) { return 'google_news'; }
        if (strpos($slug, 'google-ads') !== false) { return 'google_ads'; }
        if (strpos($slug, 'facebook-ads') !== false || strpos($slug, 'meta-ads') !== false) { return 'facebook_ads'; }
        foreach (['reddit','semrush','instagram','tiktok','youtube','twitter','linkedin','facebook','pinterest','google_search','google_news','google_trends','google_maps','google_ads','facebook_ads'] as $key) {
            if ($source_key === $key || strpos($slug, str_replace('_','-', $key)) !== false || strpos($slug, $key) !== false) { return $key; }
        }
        return $source_key !== '' ? $source_key : 'generic';
    }

    private static function evidence_score($row, $type = 'social') {
        $score = 20;
        foreach (['url','title','text','keyword','post_text','ad_text','snippet'] as $key) {
            if (!empty($row[$key])) { $score += 10; break; }
        }
        foreach (['views','likes','comments','comments_count','search_volume','score','reactions','rank','trend_strength'] as $key) {
            if (isset($row[$key]) && (float) $row[$key] > 0) { $score += 10; }
        }
        if (!empty($row['published_at']) || !empty($row['created_at']) || !empty($row['first_seen'])) { $score += 10; }
        if (!empty($row['author_name']) || !empty($row['author']) || !empty($row['advertiser']) || !empty($row['source_name'])) { $score += 10; }
        if ($type === 'reddit' && !empty($row['discussion_type']) && $row['discussion_type'] !== 'noise') { $score += 15; }
        if ($type === 'seo' && !empty($row['keyword'])) { $score += 20; }
        if ($type === 'trend' && (!empty($row['related_queries_rising']) || !empty($row['interest_over_time']))) { $score += 20; }
        return max(0, min(100, (int) $score));
    }

    private static function canonical_id($source_key, $item, $index, $preferred = '') {
        $id = sanitize_text_field((string) $preferred);
        if ($id === '') {
            $id = self::text_value($item, ['id','postId','videoId','shortCode','tweetId','adArchiveID','creative_id','keyword']);
        }
        if ($id === '') {
            $url = self::url_value($item, ['url','webVideoUrl','videoUrl','postUrl','permalink','link','adSnapshotUrl']);
            $id = $url !== '' ? substr(md5($url), 0, 16) : substr(md5($source_key . '|' . $index . '|' . wp_json_encode($item)), 0, 16);
        }
        return $id;
    }

    private static function metrics_from_row($row) {
        $row = is_array($row) ? $row : [];
        $metric_keys = ['views','likes','comments','comments_count','shares','saves','score','upvotes','reactions','reposts','search_volume','difficulty','cpc','competition','rank','trend_strength'];
        $metrics = [];
        foreach ($metric_keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $metrics[$key] = 0 + $row[$key];
            }
        }
        return $metrics;
    }

    private static function evidence_type_for_family($family) {
        $family = sanitize_key((string) $family);
        if (in_array($family, ['google_trends'], true)) { return 'market_context'; }
        if (in_array($family, ['semrush','ahrefs','moz','google_search','google_news'], true)) { return 'search_evidence'; }
        if (in_array($family, ['facebook_ads','google_ads'], true)) { return 'ad_evidence'; }
        if ($family === 'reddit') { return 'discussion_evidence'; }
        if ($family === 'linkedin') { return 'professional_social_evidence'; }
        return 'social_evidence';
    }

    private static function enrich_canonical_row($family, $source_key, $row, $raw_item) {
        $family = sanitize_key((string) $family);
        $source_key = sanitize_key((string) $source_key);
        $row = is_array($row) ? $row : [];
        $raw_item = is_array($raw_item) ? $raw_item : [];

        if (empty($row['id'])) {
            $row['id'] = self::canonical_id($source_key, $raw_item, 0);
        }
        if (empty($row['platform'])) {
            $row['platform'] = $family === 'twitter' ? 'x' : $family;
        }
        if (empty($row['source'])) {
            $row['source'] = $source_key;
        }
        if (empty($row['title'])) {
            $row['title'] = self::text_value($raw_item, ['title','caption','text','description','headline','name','keyword','query','searchTerm']);
        }
        if (empty($row['text']) && empty($row['snippet']) && empty($row['caption'])) {
            $snippet = self::textarea_value($raw_item, ['text','caption','description','body','snippet','message','postText','title']);
            if ($snippet !== '') { $row['text'] = $snippet; }
        }
        if (empty($row['url'])) {
            $row['url'] = self::url_value($raw_item, ['url','webVideoUrl','videoUrl','postUrl','tweetUrl','permalink','link','adSnapshotUrl','snapshotUrl']);
        }
        if (empty($row['author']) && !empty($row['author_name'])) {
            $row['author'] = sanitize_text_field((string) $row['author_name']);
        }
        if (empty($row['author'])) {
            $row['author'] = self::text_value($raw_item, ['author','authorName','username','userName','ownerUsername','channelTitle','pageName','name']);
        }
        if (empty($row['published_at']) && !empty($row['created_at'])) {
            $row['published_at'] = sanitize_text_field((string) $row['created_at']);
        }
        if (empty($row['published_at'])) {
            $row['published_at'] = self::text_value($raw_item, ['published_at','createdAt','createTimeISO','timestamp','postedAt','publishedAt','date']);
        }

        $row['metrics'] = self::metrics_from_row($row);
        if (empty($row['media']) || !is_array($row['media'])) {
            $media = [];
            foreach (['thumbnail_url','media_url','creative_url'] as $key) {
                if (!empty($row[$key])) {
                    $media[$key] = esc_url_raw((string) $row[$key]);
                }
            }
            if ($media) { $row['media'] = $media; }
        }
        $row['evidence_type'] = self::evidence_type_for_family($family);
        if (empty($row['evidence_quality_score'])) {
            $row['evidence_quality_score'] = self::evidence_score($row, $family);
        }

        // Keep provider output inspectable for admins without exposing full raw JSON in normal user cards.
        $row['raw_payload_keys'] = array_slice(array_map('sanitize_key', array_keys($raw_item)), 0, 40);
        $row['raw_payload_hash'] = substr(md5(wp_json_encode($raw_item)), 0, 16);
        return $row;
    }

    private static function normalize_social_post($family, $source_key, $item, $index) {
        $family = sanitize_key((string) $family);
        $title = self::text_value($item, ['title','caption','text','description','desc','headline','fullText','snippet','name']);
        $text = self::textarea_value($item, ['text','caption','description','desc','fullText','body','message','postText','title']);
        $url = self::url_value($item, ['webVideoUrl','postPage','url','videoUrl','video.url','postUrl','tweetUrl','link','permalink','inputUrl']);
        $thumb = self::url_value($item, ['coverUrl','cover_url','thumbnail','thumbnailUrl','thumbnail_url','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.coverUrl','video.thumbnail','video.thumbnailUrl','video.dynamicCover','video.originCover','covers.0','media.0.thumbnail','media.0.thumbnailUrl','displayUrl','imageUrl','picture','image','channel.avatar','author.avatar']);
        $media = self::url_value($item, ['media_url','videoUrl','video.url','mediaUrl','videoMeta.downloadAddr','downloadUrl','playUrl','video.playAddr','imageUrl','displayUrl']);
        $media_paths = ['coverUrl','cover_url','thumbnail','thumbnailUrl','thumbnail_url','dynamicCover','originCover','videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.coverUrl','video.thumbnail','video.thumbnailUrl','video.dynamicCover','video.originCover','covers.0','media.0.thumbnail','media.0.thumbnailUrl','displayUrl','imageUrl','picture','image','channel.avatar','author.avatar'];
        $media_fields_found = [];
        foreach ($media_paths as $media_path) {
            $found_value = self::read_path($item, [$media_path], null);
            if ($found_value !== null && $found_value !== '' && $found_value !== []) { $media_fields_found[] = $media_path; }
        }
        $selected_thumbnail_path = '';
        if ($thumb !== '') {
            foreach ($media_paths as $media_path) {
                $candidate = self::url_value($item, [$media_path]);
                if ($candidate !== '') { $selected_thumbnail_path = $media_path; break; }
            }
        }
        if ($thumb === '' && $family === 'youtube') {
            $video_id = self::text_value($item, ['videoId','video_id','id']);
            if ($video_id === '' && $url !== '' && preg_match('/(?:[?&]v=|youtu\.be\/|shorts\/)([A-Za-z0-9_-]{6,20})/', $url, $m)) { $video_id = $m[1]; }
            $video_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $video_id);
            if ($video_id !== '' && strlen($video_id) >= 6 && strlen($video_id) <= 20) {
                $thumb = 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg';
                $selected_thumbnail_path = 'youtube_video_id_fallback';
                $media_fields_found[] = 'youtube_video_id_fallback';
            }
        }
        $views = self::number_value($item, ['views','playCount','viewCount','videoViewCount','playsCount','videoPlayCount','viewsCount'], 0);
        $likes = self::number_value($item, ['likes','diggCount','likeCount','likesCount','reactions','reactionsCount','reactionCount'], 0);
        $comments = self::number_value($item, ['comments','commentCount','commentsCount','replyCount','numComments'], 0);
        $shares = self::number_value($item, ['shares','shareCount','retweetCount','repostCount','reposts','repostsCount'], 0);
        $saves = self::number_value($item, ['saves','bookmarks','collectCount','saveCount','repinCount','bookmarkCount'], 0);
        $row = [
            'id' => self::canonical_id($source_key, $item, $index),
            'source' => sanitize_key((string) $source_key),
            'platform' => $family === 'twitter' ? 'x' : $family,
            'title' => $title !== '' ? $title : (function_exists('mb_substr') ? mb_substr($text, 0, 90, 'UTF-8') : substr($text, 0, 90)),
            'text' => $text,
            'url' => $url,
            'author_name' => self::text_value($item, ['author_name','authorName','authorMeta.name','author.name','channel.name','ownerFullName','channelName','channelTitle','pageName','user.name','username','ownerUsername','pinner']),
            'author_handle' => self::text_value($item, ['author_handle','authorMeta.uniqueId','author.username','channel.username','username','userName','ownerUsername','handle','screenName']),
            'author_url' => self::url_value($item, ['author_url','authorUrl','profileUrl','authorMeta.profileUrl','channel.url','channelUrl','user.url']),
            'published_at' => self::text_value($item, ['published_at','uploadedAtFormatted','createTimeISO','createTime','timestamp','postedAt','publishedAt','createdAt','date','time']),
            'date' => self::text_value($item, ['published_at','uploadedAtFormatted','createTimeISO','createTime','timestamp','postedAt','publishedAt','createdAt','date','time']),
            'thumbnail_url' => $thumb,
            'media_url' => $media,
            'media_fields_found' => array_values(array_unique($media_fields_found)),
            'selected_thumbnail_path' => $selected_thumbnail_path,
            'preview_status' => $thumb !== '' ? 'thumbnail' : ($media !== '' || $url !== '' ? 'compact_source' : 'missing'),
            'preview_error' => $thumb === '' ? ($media !== '' || $url !== '' ? 'thumbnail_missing_compact_source_available' : 'no_media_fields_found') : '',
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $saves,
            'duration' => self::text_value($item, ['duration','video.duration','videoMeta.duration','lengthSeconds','videoDuration']),
            'hashtags' => self::array_value($item, ['hashtags','hashtagsList','tags']),
            'relevance_reason' => '',
            'discard_reason' => ($title === '' && $text === '' && $url === '') ? 'missing_title_text_url' : '',
            'evidence_quality_score' => 0,
            '_ves_canonical_schema' => 'social_video_post',
        ];
        $row['evidence_quality_score'] = self::evidence_score($row, 'social');
        return $row;
    }

    private static function classify_reddit_discussion($title, $body) {
        $text = strtolower((string) $title . ' ' . (string) $body);
        if (preg_match('/\b(how|what|why|where|which|can i|should i|\?)\b/u', $text)) { return 'question'; }
        if (preg_match('/\b(problem|pain|struggle|hard|difficult|frustrat|annoy|issue|blocked|stuck)\b/u', $text)) { return 'pain_point'; }
        if (preg_match('/\b(expensive|doesn\'t work|not worth|hate|bad|avoid|scam|concern|risk)\b/u', $text)) { return 'objection'; }
        if (preg_match('/\b(vs|versus|alternative|compare|better than|instead of)\b/u', $text)) { return 'comparison'; }
        if (preg_match('/\b(recommend|suggest|best tool|what tool|favorite)\b/u', $text)) { return 'recommendation'; }
        return (trim($text) === '') ? 'noise' : 'recommendation';
    }

    private static function normalize_reddit($source_key, $item, $index) {
        $title = self::text_value($item, ['title','postTitle','name']);
        $body = self::textarea_value($item, ['body','selftext','text','comment','description']);
        $comments = (int) self::number_value($item, ['comments_count','commentsCount','numComments','num_comments','commentCount'], 0);
        $score = (int) self::number_value($item, ['score','upvotes','ups','points'], 0);
        $row = [
            'id' => self::canonical_id($source_key, $item, $index),
            'source' => sanitize_key((string) $source_key),
            'subreddit' => self::text_value($item, ['subreddit','communityName','community','subredditName']),
            'title' => $title,
            'body' => $body,
            'url' => self::url_value($item, ['url','permalink','postUrl','link']),
            'author' => self::text_value($item, ['author','username','userName']),
            'created_at' => self::text_value($item, ['created_at','createdAt','createdUtc','created','date']),
            'createdAt' => self::text_value($item, ['created_at','createdAt','createdUtc','created','date']),
            'score' => $score,
            'upvotes' => (int) self::number_value($item, ['upvotes','ups','score'], $score),
            'comments_count' => $comments,
            'upvote_ratio' => (float) self::number_value($item, ['upvote_ratio','upvoteRatio'], 0),
            'discussion_type' => self::classify_reddit_discussion($title, $body),
            'discussion_quality_score' => min(100, max(0, (int) (20 + min(35, $comments * 2) + min(30, $score / 5) + ($title !== '' ? 15 : 0)))),
            'relevance_reason' => '',
            'discard_reason' => ($title === '' && $body === '') ? 'missing_discussion_text' : '',
            '_ves_canonical_schema' => 'reddit_discussion',
        ];
        $row['evidence_quality_score'] = self::evidence_score($row, 'reddit');
        return $row;
    }

    private static function detect_seo_data_type($item) {
        $type = strtolower((string) self::text_value($item, ['data_type','type','_ves_seo_data_type','_ves_canonical_schema','content_type']));
        if ($type !== '') {
            if (strpos($type, 'seo_domain') !== false) { return 'domain_authority'; }
            return sanitize_key($type);
        }
        $has_domain = self::text_value($item, ['domain','root_domain','target_domain','url']) !== '';
        $has_keyword = self::text_value($item, ['keyword','Keyword','phrase','query','key','searchTerm','term']) !== '';
        if ($has_domain && !$has_keyword) {
            if (self::text_value($item, ['moz_domain_authority','mozDomainAuthority','moz_spam_score','authority_score','authorityScore']) !== '') { return 'domain_authority'; }
            if (self::text_value($item, ['traffic','organic_traffic','backlinks','competitors']) !== '') { return 'domain_overview'; }
        }
        if (self::text_value($item, ['cited_domain','citedDomain','visibility_score','visibilityScore']) !== '') { return 'ai_visibility'; }
        if (self::text_value($item, ['rank','position']) !== '' && $has_domain && !$has_keyword) { return 'top_websites'; }
        return 'keyword_volume';
    }

    private static function normalize_seo($source_key, $item) {
        $data_type = self::detect_seo_data_type($item);
        $domain_types = ['domain_authority','domain_overview','domain_seo','overview','website_traffic','traffic','backlinks','competitors'];
        if (in_array($data_type, $domain_types, true)) {
            $domain = self::text_value($item, ['domain','root_domain','target_domain','url']);
            $domain = preg_replace('/^https?:\/\//i', '', (string) $domain);
            $domain = preg_replace('/\/.*$/', '', (string) $domain);
            $row = [
                'domain' => sanitize_text_field($domain),
                'data_type' => $data_type,
                'authority_score' => self::number_value($item, ['authority_score','authorityScore','authority'], 0),
                'moz_domain_authority' => self::number_value($item, ['moz_domain_authority','mozDomainAuthority','moz_da','domain_authority','da'], 0),
                'moz_spam_score' => self::text_value($item, ['moz_spam_score','mozSpamScore','spam_score','spamScore']),
                'traffic' => self::number_value($item, ['traffic','organic_traffic','organicTraffic','estimated_traffic','visits'], 0),
                'backlinks' => self::number_value($item, ['backlinks','total_backlinks','backlinks_count','referring_domains'], 0),
                'competitors' => self::array_value($item, ['competitors','competitor_domains','competitorDomains']),
                'captured_at' => self::text_value($item, ['captured_at','data_captured_at','scrapedAt']),
                'last_updated' => self::text_value($item, ['last_updated','lastUpdated','updatedAt']),
                'module_availability' => self::array_value($item, ['module_availability','modules','requested_modules']),
                'source' => sanitize_key((string) $source_key),
                'url' => self::url_value($item, ['url','domain_url','sourceUrl']),
                '_ves_canonical_schema' => 'seo_domain',
                '_ves_seo_data_type' => $data_type,
            ];
            if (empty($row['url']) && !empty($row['domain'])) { $row['url'] = 'https://' . $row['domain']; }
            $row['title'] = $row['domain'];
            $row['keyword'] = '';
            $row['evidence_quality_score'] = max(35, self::evidence_score($row, 'seo'));
            $row['raw_payload_keys'] = array_slice(array_map('sanitize_key', array_keys($item)), 0, 40);
            $row['raw_payload_hash'] = substr(md5(wp_json_encode($item)), 0, 16);
            return $row;
        }
        if ($data_type === 'ai_visibility') {
            $row = [
                'data_type' => 'ai_visibility',
                'model' => self::text_value($item, ['model','source','engine']),
                'query' => self::text_value($item, ['query','keyword','prompt']),
                'cited_domain' => self::text_value($item, ['cited_domain','citedDomain','domain']),
                'mention_status' => self::text_value($item, ['mention_status','citation_status','status']),
                'visibility_score' => self::number_value($item, ['visibility_score','visibilityScore','score'], 0),
                'source' => sanitize_key((string) $source_key),
                '_ves_canonical_schema' => 'seo_ai_visibility',
                '_ves_seo_data_type' => 'ai_visibility',
            ];
            $row['keyword'] = $row['query'];
            $row['evidence_quality_score'] = self::evidence_score($row, 'seo');
            return $row;
        }
        if ($data_type === 'top_websites') {
            $row = [
                'data_type' => 'top_websites',
                'rank' => self::number_value($item, ['rank','position'], 0),
                'domain' => self::text_value($item, ['domain','root_domain','url']),
                'category' => self::text_value($item, ['category','industry']),
                'country' => self::text_value($item, ['country','database']),
                'traffic' => self::number_value($item, ['traffic','visits','estimated_traffic'], 0),
                'authority_score' => self::number_value($item, ['authority_score','authorityScore'], 0),
                'source' => sanitize_key((string) $source_key),
                '_ves_canonical_schema' => 'seo_top_websites',
                '_ves_seo_data_type' => 'top_websites',
            ];
            $row['keyword'] = '';
            $row['evidence_quality_score'] = self::evidence_score($row, 'seo');
            return $row;
        }
        $keyword = self::text_value($item, ['keyword','Keyword','phrase','query','key','searchTerm','term']);
        $volume = self::number_value($item, ['search_volume','searchVolume','avgMonthlySearches','volume','Volume','Search Volume','monthlySearches'], 0);
        $difficulty = self::number_value($item, ['difficulty','keywordDifficulty','kd','KD','Keyword Difficulty','competitionIndex'], 0);
        $cpc = self::number_value($item, ['cpc','CPC','lowCPC','highCPC','costPerClick'], 0);
        $competition = self::number_value($item, ['competition','Competition','competitionLevel','Competition Level'], 0);
        $opp = self::number_value($item, ['opportunity_score','opportunityScore','monetizationScore'], 0);
        if ($opp <= 0) { $opp = max(0, min(100, ($volume > 0 ? 35 : 0) + ($difficulty > 0 ? max(0, 35 - min(35, $difficulty / 3)) : 15) + ($cpc > 0 ? 15 : 0))); }
        $row = [
            'keyword' => $keyword,
            'data_type' => $data_type,
            'cluster' => self::text_value($item, ['cluster','group','topic','parentTopic']),
            'intent' => self::text_value($item, ['intent','searchIntent','Intent']),
            'search_volume' => $volume,
            'cpc' => $cpc,
            'difficulty' => $difficulty,
            'competition' => $competition,
            'serp_features' => self::array_value($item, ['serp_features','serpFeatures','features','SERP Features','Serp Features']),
            'serp_type' => self::text_value($item, ['serp_type','serpType','resultType']),
            'content_type' => self::text_value($item, ['content_type','contentType','type']),
            'opportunity_score' => $opp,
            'priority' => $opp >= 70 ? 'high' : ($opp >= 40 ? 'medium' : 'low'),
            'source' => sanitize_key((string) $source_key),
            'country' => self::text_value($item, ['country','database','Country']),
            'language' => self::text_value($item, ['language','lang','Language']),
            '_ves_canonical_schema' => 'seo_keyword',
            '_ves_seo_data_type' => $data_type,
        ];
        $row['evidence_quality_score'] = self::evidence_score($row, 'seo');
        return $row;
    }

    private static function normalize_ads($family, $source_key, $item) {
        $headline = self::text_value($item, ['headline','title','ad_title','creativeHeadline']);
        $body = self::textarea_value($item, ['body','ad_text','adText','text','description','creativeBody']);
        $row = [
            'advertiser' => self::text_value($item, ['advertiser','advertiserName','pageName','company','target_domain','domain']),
            'advertiser_url' => self::url_value($item, ['advertiser_url','advertiserUrl','pageUrl','advertiserPageUrl']),
            'platform' => $family === 'facebook_ads' ? 'meta_ads' : $family,
            'ad_text' => trim($headline . ' ' . $body),
            'headline' => $headline,
            'body' => $body,
            'cta' => self::text_value($item, ['cta','callToAction','ctaText','buttonText']),
            'format' => self::text_value($item, ['format','ad_format','creativeType','type']),
            'landing_page' => self::url_value($item, ['landing_page','landingPage','linkUrl','target_url','targetUrl','destinationUrl']),
            'creative_url' => self::url_value($item, ['creative_url','creativeUrl','imageUrl','videoUrl','mediaUrl']),
            'snapshot_url' => self::url_value($item, ['snapshot_url','snapshotUrl','adSnapshotUrl','url']),
            'active_status' => self::text_value($item, ['active_status','activeStatus','status','isActive']),
            'first_seen' => self::text_value($item, ['first_seen','firstSeen','first_shown','startDate','startedAt']),
            'last_seen' => self::text_value($item, ['last_seen','lastSeen','last_shown','endDate','endedAt']),
            'offer_type' => self::text_value($item, ['offer_type','offerType','theme']),
            'funnel_stage' => self::text_value($item, ['funnel_stage','funnelStage']),
            '_ves_canonical_schema' => 'ad_creative',
        ];
        $row['url'] = $row['snapshot_url'] ?: $row['landing_page'];
        $row['evidence_quality_score'] = self::evidence_score($row, 'ads');
        return $row;
    }

    private static function normalize_google_result($source_key, $item, $index) {
        $url = self::url_value($item, ['url','link','sourceUrl','newsUrl']);
        $domain = self::text_value($item, ['domain','displayedUrl','source.domain']);
        if ($domain === '' && $url !== '') { $host = parse_url($url, PHP_URL_HOST); $domain = $host ? sanitize_text_field($host) : ''; }
        $row = [
            'id' => self::canonical_id($source_key, $item, $index, $url),
            'title' => self::text_value($item, ['title','name','headline']),
            'url' => $url,
            'domain' => $domain,
            'snippet' => self::textarea_value($item, ['snippet','description','summary','body']),
            'rank' => (int) self::number_value($item, ['rank','position','organicPosition'], 0),
            'published_at' => self::text_value($item, ['published_at','publishedAt','date','publishedDate']),
            'date' => self::text_value($item, ['published_at','publishedAt','date','publishedDate']),
            'source_name' => self::text_value($item, ['source_name','sourceName','source','publisher']),
            'content_type' => sanitize_key((string) ($source_key === 'google_news' ? 'news' : self::text_value($item, ['content_type','type']))),
            'relevance_reason' => '',
            '_ves_canonical_schema' => 'google_search_news_result',
        ];
        $row['evidence_quality_score'] = self::evidence_score($row, 'search');
        return $row;
    }

    private static function normalize_google_trends($source_key, $item) {
        $keyword = self::text_value($item, ['keyword','searchTerm','term','query','name']);
        $related_top = self::array_value($item, ['related_queries_top','relatedQueriesTop','relatedQueries.top','related_queries.top','top']);
        $related_rising = self::array_value($item, ['related_queries_rising','relatedQueriesRising','relatedQueries.rising','related_queries.rising','rising']);
        $interest = self::read_path($item, ['interest_over_time','interestOverTime','timelineData','data'], []);
        $strength = self::number_value($item, ['trend_strength','trendStrength','score','value'], 0);
        $growth = self::text_value($item, ['growth_signal','growthSignal','growth','extracted_value']);
        $row = [
            'keyword' => $keyword,
            'geo' => self::text_value($item, ['geo','country','region']),
            'time_range' => self::text_value($item, ['time_range','timeRange','dateRange']),
            'interest_over_time' => is_array($interest) ? $interest : [],
            'related_queries_top' => $related_top,
            'related_queries_rising' => $related_rising,
            'related_topics_top' => self::array_value($item, ['related_topics_top','relatedTopicsTop','relatedTopics.top']),
            'related_topics_rising' => self::array_value($item, ['related_topics_rising','relatedTopicsRising','relatedTopics.rising']),
            'growth_signal' => $growth,
            'trend_strength' => $strength,
            '_ves_canonical_schema' => 'google_trends',
            // legacy aliases used by existing Trend Finder helpers
            'searchTerm' => $keyword,
            'relatedQueriesTop' => $related_top,
            'relatedQueriesRising' => $related_rising,
            'interestOverTime' => is_array($interest) ? $interest : [],
        ];
        $row['evidence_quality_score'] = self::evidence_score($row, 'trend');
        return $row;
    }

    private static function normalize_linkedin($source_key, $item, $index) {
        $text = self::textarea_value($item, ['post_text','postText','text','description','content','commentary']);
        $row = [
            'id' => self::canonical_id($source_key, $item, $index),
            'post_text' => $text,
            'post_url' => self::url_value($item, ['post_url','postUrl','url','activityUrl']),
            'author_name' => self::text_value($item, ['author_name','authorName','name','profileName']),
            'author_role' => self::text_value($item, ['author_role','authorRole','headline','position']),
            'company_name' => self::text_value($item, ['company_name','companyName','company','organizationName']),
            'company_url' => self::url_value($item, ['company_url','companyUrl','organizationUrl']),
            'published_at' => self::text_value($item, ['published_at','postedAt','date','createdAt']),
            'date' => self::text_value($item, ['published_at','postedAt','date','createdAt']),
            'reactions' => (int) self::number_value($item, ['reactions','reactionsCount','likes','likesCount'], 0),
            'comments' => (int) self::number_value($item, ['comments','commentsCount','commentCount'], 0),
            'reposts' => (int) self::number_value($item, ['reposts','repostsCount','shares','shareCount'], 0),
            'topic' => self::text_value($item, ['topic','category']),
            'content_pattern' => self::text_value($item, ['content_pattern','contentPattern','format']),
            '_ves_canonical_schema' => 'linkedin_post',
        ];
        $row['title'] = function_exists('mb_substr') ? mb_substr($text, 0, 120, 'UTF-8') : substr($text, 0, 120);
        $row['url'] = $row['post_url'];
        $row['evidence_quality_score'] = self::evidence_score($row, 'linkedin');
        return $row;
    }

    public static function normalize_output($source_key, $actor_slug, $items) {
        $items = is_array($items) ? $items : [];
        $family = self::source_family($source_key, $actor_slug);
        $out = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                if (is_scalar($item)) { $item = ['value' => sanitize_text_field((string) $item)]; }
                else { continue; }
            }
            switch ($family) {
                case 'reddit': $row = self::normalize_reddit($source_key, $item, $idx); break;
                case 'semrush': case 'ahrefs': case 'moz': $row = self::normalize_seo($source_key, $item); break;
                case 'facebook_ads': case 'google_ads': $row = self::normalize_ads($family, $source_key, $item); break;
                case 'google_search': case 'google_news': $row = self::normalize_google_result($family, $item, $idx); break;
                case 'google_trends': $row = self::normalize_google_trends($source_key, $item); break;
                case 'linkedin': $row = self::normalize_linkedin($source_key, $item, $idx); break;
                default: $row = self::normalize_social_post($family, $source_key, $item, $idx); break;
            }
            if (is_array($row)) { $out[] = self::enrich_canonical_row($family, $source_key, $row, $item); }
        }
        return $out;
    }

    public static function validate_output($source_key, $actor_slug, $items, $request = []) {
        $source_key = sanitize_key((string) $source_key);
        $slug = self::normalize_actor_slug($actor_slug);
        $items = self::normalize_output($source_key, $actor_slug, $items);
        $request = is_array($request) ? $request : [];
        $valid = [];
        $rejected = 0;
        $notes = [];
        $rejection_reasons = [];
        $distinct = [];
        $topic = strtolower(trim((string) ($request['topic'] ?? $request['query'] ?? $request['keyword'] ?? '')));
        $topic_tokens = array_values(array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}#@]+/u', ' ', $topic))));

        foreach ($items as $item) {
            $text = self::item_text($item);
            if ($text === '' || $text === '[]') {
                $rejected++;
                $rejection_reasons['missing_text'] = ($rejection_reasons['missing_text'] ?? 0) + 1;
                continue;
            }
            if (preg_match('#ads\.tiktok\.com/business/creativecenter/inspiration/popular/(hashtag|songs?|creators?|videos?)#i', $text)
                && !preg_match('/(hashtagname|title|postdesc|trend_name|name|label)/i', $text)) {
                $rejected++;
                $rejection_reasons['generic_navigation_url'] = ($rejection_reasons['generic_navigation_url'] ?? 0) + 1;
                $notes[] = 'Rejected generic TikTok Creative Center navigation URL.';
                continue;
            }
            if (preg_match('#^https?://#i', trim($text)) || preg_match('#"url"\s*:\s*"https?://[^" ]+"#i', $text)) {
                if (self::extract_semantic_label($item) === '') {
                    $rejected++;
                    $rejection_reasons['url_only_placeholder'] = ($rejection_reasons['url_only_placeholder'] ?? 0) + 1;
                    $notes[] = 'Rejected URL-only placeholder record.';
                    continue;
                }
            }
            $label = self::extract_semantic_label($item);
            if ($source_key === 'google_trends') {
                $has_trend_payload = $label !== '' || !empty($item['relatedQueriesTop']) || !empty($item['relatedQueriesRising']) || !empty($item['relatedTopicsTop']) || !empty($item['relatedTopicsRising']) || !empty($item['interestOverTime']);
                if (!$has_trend_payload) {
                    $rejected++;
                    $rejection_reasons['missing_google_trends_payload'] = ($rejection_reasons['missing_google_trends_payload'] ?? 0) + 1;
                    continue;
                }
            } elseif (in_array($source_key, ['tiktok_trends','twitter_trends','x_trends','youtube_trending'], true)) {
                if ($label === '') {
                    $rejected++;
                    $rejection_reasons['missing_trend_label'] = ($rejection_reasons['missing_trend_label'] ?? 0) + 1;
                    continue;
                }
            }
            if ($label !== '') { $distinct[strtolower($label)] = true; }
            $valid[] = $item;
        }

        $direct_matches = 0;
        $adjacent_matches = 0;
        if (!empty($topic_tokens)) {
            foreach ($valid as $item) {
                $text = self::item_text($item);
                $hits = 0;
                foreach ($topic_tokens as $token) {
                    if (strlen($token) >= 3 && strpos($text, strtolower($token)) !== false) { $hits++; }
                }
                if ($hits >= max(1, min(2, count($topic_tokens)))) { $direct_matches++; }
                elseif ($hits > 0) { $adjacent_matches++; }
            }
        }

        $evidence_role = 'topic_evidence';
        if ($source_key === 'youtube_trending') {
            $evidence_role = $direct_matches > 0 ? 'topic_evidence' : 'market_context';
        }
        $status = 'usable';
        $reason = '';
        if ($source_key === 'twitter_trends') {
            $cap = class_exists('VES_Config') ? VES_Config::trend_max_twitter_trend_items() : 40;
            if ($cap > 0 && count($valid) > $cap) {
                $valid = array_slice($valid, 0, $cap);
                $status = 'capped';
                $notes[] = 'X/Twitter Trends output capped to ' . $cap . ' usable records before AI/memory.';
            }
        }
        if (empty($valid)) {
            $status = 'insufficient_output';
            $reason = 'Source output was present but non-normalizable or filtered as noise.';
        } elseif ($source_key === 'tiktok_trends' && empty($distinct)) {
            $status = 'insufficient_output';
            $reason = 'TikTok Trends did not return distinct trend labels.';
        } elseif ($source_key === 'youtube_trending' && $direct_matches <= 0) {
            $status = 'market_context';
            $reason = 'YouTube Trending is broad market context unless returned videos directly match the requested topic.';
        }

        return [
            'status' => $status,
            'evidence_role' => $evidence_role,
            'valid_items' => array_values($valid),
            'valid_count' => count($valid),
            'rejected_count' => $rejected,
            'distinct_label_count' => count($distinct),
            'direct_match_count' => $direct_matches,
            'adjacent_match_count' => $adjacent_matches,
            'reason' => sanitize_text_field($reason),
            'notes' => array_values(array_slice(array_unique($notes), 0, 6)),
            'rejection_reasons' => array_filter($rejection_reasons),
        ];
    }

    public static function classify_status($result) {
        if (is_wp_error($result)) {
            $data = is_array($result->get_error_data()) ? $result->get_error_data() : [];
            $provider_state = strtoupper((string) ($data['provider_state'] ?? ''));
            if ($provider_state === 'QUEUED_RETRY_MEMORY_LIMIT' || $result->get_error_code() === 'apify_memory_limit') { return 'queued_retry_memory_limit'; }
            if ($provider_state === 'WAITING_RETRY') { return 'waiting_retry'; }
            if ($result->get_error_code() === 'skipped_invalid_input') { return 'skipped_invalid_input'; }
            return 'failed';
        }
        $status = strtoupper((string) ((is_array($result) ? ($result['status'] ?? ($result['data']['status'] ?? '')) : '')));
        if (in_array($status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) { return strtolower(str_replace('-', '_', $status)); }
        return $status !== '' ? strtolower($status) : 'unknown';
    }

    public static function validate_input($source_key, $actor_slug, $input) {
        $adapter = self::adapter_for($source_key, $actor_slug);
        $input = is_array($input) ? $input : [];
        $missing = [];
        foreach ((array) ($adapter['required_fields'] ?? []) as $field) {
            $value = $input[$field] ?? null;
            if (is_array($value)) {
                $value = array_values(array_filter($value, static function($v) { return trim((string) $v) !== ''; }));
                if (empty($value)) { $missing[] = (string) $field; }
            } elseif (trim((string) $value) === '') {
                $missing[] = (string) $field;
            }
        }
        $required_any = array_values(array_filter((array) ($adapter['required_any'] ?? [])));
        if (!empty($required_any)) {
            $has_any = false;
            foreach ($required_any as $field) {
                $value = $input[$field] ?? null;
                if (is_array($value)) {
                    $value = array_values(array_filter($value, static function($v) { return trim((string) $v) !== ''; }));
                    if (!empty($value)) { $has_any = true; break; }
                } elseif (trim((string) $value) !== '') {
                    $has_any = true;
                    break;
                }
            }
            if (!$has_any) { $missing[] = 'one_of:' . implode('|', array_map('sanitize_key', $required_any)); }
        }
        $forbidden = [];
        foreach ((array) ($adapter['forbidden_fields'] ?? []) as $field) {
            if (array_key_exists($field, $input)) { $forbidden[] = (string) $field; }
        }
        if (($adapter['adapter_key'] ?? '') === 'google_trends_data_xplorer_fast' && isset($input['searchTerms']) && !isset($input['keyword'])) {
            $missing[] = 'keyword';
        }
        $missing = array_values(array_unique($missing));
        $forbidden = array_values(array_unique($forbidden));
        if (!empty($missing) || !empty($forbidden)) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('adapter_invalid_input', 'SKIPPED_INVALID_INPUT: actor input contract failed before Apify dispatch.', [
                    'source_key' => sanitize_key((string) $source_key),
                    'actor_slug' => self::normalize_actor_slug($actor_slug),
                    'adapter_key' => sanitize_key((string) ($adapter['adapter_key'] ?? 'generic_apify')),
                    'missing_fields' => $missing,
                    'forbidden_fields' => $forbidden,
                    'required_any' => isset($required_any) ? $required_any : [],
                ]);
            }
            $raw_message = 'SKIPPED_INVALID_INPUT: adapter input contract failed before Apify dispatch.';
            if (!empty($missing)) { $raw_message .= ' Missing: ' . implode(', ', $missing) . '.'; }
            if (!empty($forbidden)) { $raw_message .= ' Unsupported: ' . implode(', ', $forbidden) . '.'; }
            $message = 'This source is not configured correctly. Admin diagnostics has details.';
            return new WP_Error('skipped_invalid_input', $message, [
                'status' => 'SKIPPED_INVALID_INPUT',
                'raw_contract_message' => $raw_message,
                'adapter_key' => sanitize_key((string) ($adapter['adapter_key'] ?? 'generic_apify')),
                'source_key' => sanitize_key((string) $source_key),
                'actor_slug' => self::normalize_actor_slug($actor_slug),
                'missing_fields' => $missing,
                'forbidden_fields' => $forbidden,
                'required_any' => isset($required_any) ? $required_any : [],
            ]);
        }
        return true;
    }

    public static function invalid_input_source($source_key, $source, $error) {
        $source = is_array($source) ? $source : [];
        $data = is_wp_error($error) && is_array($error->get_error_data()) ? $error->get_error_data() : [];
        $source['status'] = 'SKIPPED_INVALID_INPUT';
        $source['dispatch_status'] = 'skipped_invalid_input';
        $source['scrape_status'] = 'SKIPPED_INVALID_INPUT';
        $source['parse_status'] = 'not_started';
        $source['analytical_usability_status'] = 'failed';
        $source['item_count'] = 0;
        $source['run_id'] = '';
        $source['notes'] = 'This source is not configured correctly. Admin diagnostics has details.';
        $source['adapter_diagnostic'] = [
            'source_key' => sanitize_key((string) $source_key),
            'adapter_key' => sanitize_key((string) ($data['adapter_key'] ?? 'generic_apify')),
            'actor_slug' => sanitize_text_field((string) ($data['actor_slug'] ?? ($source['actor_slug'] ?? ''))),
            'missing_fields' => array_values((array) ($data['missing_fields'] ?? [])),
            'forbidden_fields' => array_values((array) ($data['forbidden_fields'] ?? [])),
            'required_any' => array_values((array) ($data['required_any'] ?? [])),
            'recommended_action' => 'Fix the actor-specific input adapter before dispatch.',
        ];
        return $source;
    }

    public static function memory_limit_retry_source($source_key, $source, $error = null) {
        $source = is_array($source) ? $source : [];
        $source['status'] = 'QUEUED_RETRY_MEMORY_LIMIT';
        $source['dispatch_status'] = 'queued_retry_memory_limit';
        $source['scrape_status'] = 'QUEUED_RETRY_MEMORY_LIMIT';
        $source['parse_status'] = 'not_started';
        $source['analytical_usability_status'] = 'pending';
        $source['retry_after_seconds'] = 180;
        $source['run_id'] = '';
        $source['notes'] = 'Provider capacity: queued due to Apify actor memory limit or local concurrency limit.';
        if (is_wp_error($error)) {
            $source['last_error_code'] = sanitize_key((string) $error->get_error_code());
            $source['last_error_message'] = sanitize_text_field((string) $error->get_error_message());
        }
        return $source;
    }

    public static function classify_item_relevance($item, $request = []) {
        $item = is_array($item) ? $item : [];
        $request = is_array($request) ? $request : [];
        $country = strtolower((string) ($request['country'] ?? ''));
        $language = strtolower((string) ($request['language'] ?? ''));
        $text = self::item_text($item);
        if ($text === '') { return 'unknown_empty'; }
        $topic = strtolower((string) ($request['topic'] ?? $request['query'] ?? ''));
        $topic_tokens = array_values(array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}#@]+/u', ' ', $topic))));
        $topic_hits = 0;
        foreach ($topic_tokens as $token) {
            if (strlen($token) >= 3 && strpos($text, $token) !== false) { $topic_hits++; }
        }
        $spanish = preg_match('/\b(espa[nñ]a|barcelona|madrid|valencia|sevilla|espa[nñ]ol|castellano|para ti|desde cero|emprender|dinero|marketing digital|comunidad|negocio)\b/u', $text);
        $spain = preg_match('/\b(spain|espa[nñ]a|barcelona|madrid|valencia|sevilla|bilbao|malaga|m[aá]laga|\bes\b)\b/u', $text);
        $english_noise = preg_match('/\b(united states|usa|uk|gb|dollars|beginner friendly|side hustle|clock in|extra income)\b/u', $text);
        if (($country === 'es' || $country === 'spain') && $language === 'es' && !$spanish && $english_noise) { return 'wrong_language'; }
        if (($country === 'es' || $country === 'spain') && preg_match('/\b(brazil|brasil|usa|united states|peru|pe|gb|uk)\b/u', $text) && !$spain) { return 'wrong_market'; }
        if ($topic_hits >= max(1, min(2, count($topic_tokens)))) { return 'direct_brand_match'; }
        if ($spanish && $spain) { return 'category_relevant'; }
        if ($topic_hits > 0 || $spanish || $spain) { return 'adjacent_context'; }
        if ($english_noise) { return 'noise'; }
        return 'weak_match';
    }

    public static function compact_diagnostics() {
        return [
            'google_trends' => [
                'data_xplorer/google-trends-fast-scraper' => 'requires keyword; searchTerms is blocked for this actor',
                'apify/google-trends-scraper' => 'requires searchTerms; multi-term payload is allowed',
            ],
            'adapters' => ['instagram_adapter', 'tiktok_adapter', 'semrush_keyword_magic_adapter', 'google_search_adapter', 'linkedin_post_adapter', 'youtube_adapter', 'twitter_adapter', 'reddit_adapter', 'facebook_ads_adapter', 'google_ads_adapter'],
            'retry_states' => ['QUEUED_RETRY_MEMORY_LIMIT', 'WAITING_RETRY', 'SKIPPED_INVALID_INPUT'],
        ];
    }
}
