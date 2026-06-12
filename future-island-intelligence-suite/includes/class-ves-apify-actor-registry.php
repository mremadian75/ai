<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Server-side Apify actor/task registry.
 *
 * v0.9.24.77 expands the registry from a small internal map into a source-
 * specific catalogue that can drive module-specific adapters, preflight cost
 * estimates and admin diagnostics. This remains code-backed; site-specific
 * overrides can still be stored in ves_apify_actor_registry_overrides.
 */
final class VES_Apify_Actor_Registry {
    const DB_VERSION = '1.1.0';
    const OPTION_KEY = 'ves_apify_actor_registry_overrides';

    private static function base($overrides = []) {
        $actor_id = (string) ($overrides['actor_id'] ?? '');
        $defaults = [
            'module' => 'unassigned',
            'provider' => 'apify',
            'actor_id' => $actor_id,
            'task_id' => '',
            'display_name' => $actor_id !== '' ? $actor_id : 'Unconfigured actor',
            'source_type' => 'public_web',
            'use_case' => '',
            'purpose' => '',
            'supported_modes' => [],
            'required_fields' => [],
            'optional_fields' => [],
            'result_schema' => [],
            'pricing_model' => 'estimated_per_result',
            'estimated_provider_cost' => 0.0025,
            'estimated_cost_per_item' => 0.0025,
            'default_result_limit' => 20,
            'max_result_limit' => 80,
            'max_results_default' => 20,
            'max_results_max' => 80,
            'candidate_pool_multiplier' => 3,
            'timeout' => 90,
            'timeout_seconds' => 90,
            'retry_policy' => ['retries' => 1, 'backoff_seconds' => 2],
            'retry_count' => 1,
            'enabled' => true,
            'requires_login' => true,
            'input_schema' => [],
            'output_schema' => [],
            'admin_notes' => '',
            'fallback_actors' => [],
            'health_status' => 'unknown',
            'permission_status' => 'not_checked',
            'primary' => false,
            // Phase 9A.2: only an explicitly zero-cost actor may dispatch without a
            // maxTotalChargeUsd ceiling. Default false — paid until proven otherwise.
            'zero_cost' => false,
        ];
        $merged = array_merge($defaults, $overrides);
        if (empty($merged['purpose']) && !empty($merged['use_case'])) { $merged['purpose'] = $merged['use_case']; }
        if (empty($merged['input_schema'])) {
            $schema = [];
            foreach ((array) ($merged['required_fields'] ?? []) as $field) { $schema[(string) $field] = 'required'; }
            foreach ((array) ($merged['optional_fields'] ?? []) as $field) { $schema[(string) $field] = 'optional'; }
            $merged['input_schema'] = $schema;
        }
        if (empty($merged['output_schema'])) { $merged['output_schema'] = (array) ($merged['result_schema'] ?? []); }
        $merged['estimated_cost_per_item'] = (float) ($merged['estimated_cost_per_item'] ?? $merged['estimated_provider_cost'] ?? 0);
        $merged['estimated_provider_cost'] = (float) ($merged['estimated_provider_cost'] ?? $merged['estimated_cost_per_item'] ?? 0);
        $merged['max_results_default'] = (int) ($merged['max_results_default'] ?? $merged['default_result_limit'] ?? 20);
        $merged['default_result_limit'] = (int) ($merged['default_result_limit'] ?? $merged['max_results_default'] ?? 20);
        $merged['max_results_max'] = (int) ($merged['max_results_max'] ?? $merged['max_result_limit'] ?? 80);
        $merged['max_result_limit'] = (int) ($merged['max_result_limit'] ?? $merged['max_results_max'] ?? 80);
        $merged['timeout_seconds'] = (int) ($merged['timeout_seconds'] ?? $merged['timeout'] ?? 90);
        $merged['timeout'] = (int) ($merged['timeout'] ?? $merged['timeout_seconds'] ?? 90);
        $merged['retry_count'] = (int) ($merged['retry_count'] ?? ($merged['retry_policy']['retries'] ?? 1));
        return $merged;
    }

    public static function defaults() {
        $content = ['id','url','title','caption','author','published_at','thumbnail_url','metrics','language','country','raw_payload'];
        $keyword = ['keyword','cluster','intent','search_volume','cpc','difficulty','trend','serp_type','opportunity_score','recommended_content_type'];
        $ad = ['advertiser','domain','headline','body','format','cta','landing_url','creative_url','first_seen','region'];
        $review = ['source','rating','text','author','date','url','sentiment'];
        $web = ['title','url','snippet','source','published_at','text'];
        return [
            // SOCIAL / INSTAGRAM
            'instagram_scraper' => self::base(['module'=>'social_media','actor_id'=>'apify/instagram-scraper','display_name'=>'Instagram Scraper','source_type'=>'instagram','use_case'=>'Profile, hashtag, post URL and search collection for Instagram evidence.','supported_modes'=>['keyword','hashtag','profile','post_url','competitor_content_scan'],'required_fields'=>['targets'],'optional_fields'=>['country','language','date_range','max_results','include_author_metadata'],'result_schema'=>$content,'estimated_provider_cost'=>0.0019,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>4,'timeout'=>120,'primary'=>true,'fallback_actors'=>['instagram_hashtag_scraper','instagram_post_scraper','instagram_reel_scraper']]),
            'instagram_hashtag_scraper' => self::base(['module'=>'social_media','actor_id'=>'apify/instagram-hashtag-scraper','display_name'=>'Instagram Hashtag Scraper','source_type'=>'instagram','use_case'=>'Hashtag discovery when the main Instagram actor is too broad.','supported_modes'=>['hashtag'],'required_fields'=>['hashtag'],'result_schema'=>$content,'estimated_provider_cost'=>0.0021,'default_result_limit'=>30,'max_result_limit'=>120,'candidate_pool_multiplier'=>4]),
            'instagram_post_scraper' => self::base(['module'=>'social_media','actor_id'=>'apify/instagram-post-scraper','display_name'=>'Instagram Post Scraper','source_type'=>'instagram','use_case'=>'Exact post URL extraction and enrichment.','supported_modes'=>['post_url'],'required_fields'=>['post_urls'],'result_schema'=>$content,'estimated_provider_cost'=>0.0013,'default_result_limit'=>10,'max_result_limit'=>50,'candidate_pool_multiplier'=>1]),
            'instagram_reel_scraper' => self::base(['module'=>'social_media','actor_id'=>'apify/instagram-reel-scraper','display_name'=>'Instagram Reel Scraper','source_type'=>'instagram','use_case'=>'Short-form Instagram/Reels scan where configured.','supported_modes'=>['keyword','profile','reel_url'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0014,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3]),
            'instagram_followers_following' => self::base(['module'=>'social_media','actor_id'=>'scraping_solutions/instagram-scraper-followers-following-no-cookies','display_name'=>'Instagram Followers / Following','source_type'=>'instagram','use_case'=>'Audience/account graph collection; high-risk and admin-gated.','supported_modes'=>['profile_audience'],'required_fields'=>['profile_url'],'result_schema'=>['username','profile_url','bio','followers','following'],'estimated_provider_cost'=>0.00065,'default_result_limit'=>100,'max_result_limit'=>1000,'candidate_pool_multiplier'=>1,'enabled'=>false,'admin_notes'=>'Disabled by default; configure only for legally acceptable audience/account graph use cases.']),

            // SOCIAL / TIKTOK
            'tiktok_apidojo' => self::base(['module'=>'social_media','actor_id'=>'apidojo/tiktok-scraper','display_name'=>'TikTok Scraper','source_type'=>'tiktok','use_case'=>'Primary TikTok keyword/hashtag/profile discovery.','supported_modes'=>['keyword','hashtag','profile','post_url','trend_discovery'],'required_fields'=>['targets'],'optional_fields'=>['min_views','min_likes','date_range','country','language'],'result_schema'=>$content,'estimated_provider_cost'=>0.00030,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>5,'timeout'=>120,'primary'=>true,'fallback_actors'=>['tiktok_clockworks','tiktok_scraptik','tiktok_video']]),
            'tiktok_clockworks' => self::base(['module'=>'social_media','actor_id'=>'clockworks/tiktok-scraper','display_name'=>'TikTok Scraper (Clockworks)','source_type'=>'tiktok','use_case'=>'Fallback TikTok collection.','supported_modes'=>['keyword','hashtag','profile','post_url'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0023,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>4]),
            'tiktok_free' => self::base(['module'=>'social_media','actor_id'=>'clockworks/free-tiktok-scraper','display_name'=>'Free TikTok Scraper','source_type'=>'tiktok','use_case'=>'Low-cost smoke test / fallback only.','supported_modes'=>['keyword','hashtag'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0010,'default_result_limit'=>10,'max_result_limit'=>40,'candidate_pool_multiplier'=>2,'admin_notes'=>'Use only when main actors are unavailable; field coverage may be weaker.']),
            'tiktok_scraptik' => self::base(['module'=>'social_media','actor_id'=>'scraptik/tiktok-api','display_name'=>'Full TikTok API Scraper','source_type'=>'tiktok','use_case'=>'Selected post/profile enrichment.','supported_modes'=>['post_url','profile_enrichment'],'required_fields'=>['url'],'result_schema'=>$content,'estimated_provider_cost'=>0.0020,'default_result_limit'=>10,'max_result_limit'=>50,'candidate_pool_multiplier'=>1]),
            'tiktok_trends' => self::base(['module'=>'trend_finder','actor_id'=>'clockworks/tiktok-trends-scraper','display_name'=>'TikTok Trends Scraper','source_type'=>'tiktok','use_case'=>'Trend Finder social-driven/deep scan mode.','supported_modes'=>['social_driven','viral','emerging'],'required_fields'=>['topic','market'],'result_schema'=>['trend_title','source','engagement','growth','freshness','url'],'estimated_provider_cost'=>0.0023,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>5,'timeout'=>180,'fallback_actors'=>['tiktok_apidojo']]),
            'tiktok_followers' => self::base(['module'=>'social_media','actor_id'=>'clockworks/tiktok-followers-scraper','display_name'=>'TikTok Followers Scraper','source_type'=>'tiktok','use_case'=>'Audience/account graph collection; disabled by default.','supported_modes'=>['profile_audience'],'required_fields'=>['profile_url'],'result_schema'=>['username','profile_url','followers'],'estimated_provider_cost'=>0.0010,'default_result_limit'=>100,'max_result_limit'=>1000,'enabled'=>false]),
            'tiktok_video' => self::base(['module'=>'social_media','actor_id'=>'clockworks/tiktok-video-scraper','display_name'=>'TikTok Video Scraper','source_type'=>'tiktok','use_case'=>'Exact TikTok video URL enrichment.','supported_modes'=>['post_url'],'required_fields'=>['post_url'],'result_schema'=>$content,'estimated_provider_cost'=>0.0075,'default_result_limit'=>5,'max_result_limit'=>30,'candidate_pool_multiplier'=>1]),

            // X / YOUTUBE / FACEBOOK / REDDIT
            'twitter_tweet_scraper' => self::base(['module'=>'social_media','actor_id'=>'apidojo/tweet-scraper','display_name'=>'Tweet Scraper V2 / X Twitter Scraper','source_type'=>'twitter','use_case'=>'X search/profile/post evidence.','supported_modes'=>['keyword','profile','post_url','trend_discovery'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0004,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>4,'primary'=>true,'fallback_actors'=>['twitter_trends']]),
            'twitter_trends' => self::base(['module'=>'trend_finder','actor_id'=>'karamelo/twitter-trends-scraper','display_name'=>'Twitter Trends Scraper','source_type'=>'twitter','use_case'=>'X/Twitter trend source.','supported_modes'=>['trend_discovery','social_driven'],'required_fields'=>['market'],'result_schema'=>['trend_title','rank','volume','url','country'],'estimated_provider_cost'=>0.00018,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'youtube_scraper' => self::base(['module'=>'social_media','actor_id'=>'streamers/youtube-scraper','display_name'=>'YouTube Scraper','source_type'=>'youtube','use_case'=>'YouTube search/channel/video evidence.','supported_modes'=>['keyword','channel','video_url','trend_discovery'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0026,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>4,'primary'=>true,'fallback_actors'=>['youtube_trending_categories','youtube_trending_apidojo']]),
            'youtube_trending_categories' => self::base(['module'=>'trend_finder','actor_id'=>'eunit/youtube-trending-videos-by-categories','display_name'=>'YouTube Trending Videos by Categories','source_type'=>'youtube','use_case'=>'Trend Finder YouTube category scan; expensive.','supported_modes'=>['viral','social_driven','deep_scan'],'required_fields'=>['market','category'],'result_schema'=>$content,'estimated_provider_cost'=>0.0010,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3,'timeout'=>180]),
            'youtube_trending_apidojo' => self::base(['module'=>'trend_finder','actor_id'=>'apidojo/youtube-trending-scraper','display_name'=>'Fast YouTube Trending Scraper API','source_type'=>'youtube','use_case'=>'Alternative YouTube trending source.','supported_modes'=>['viral','trend_discovery'],'required_fields'=>['market'],'result_schema'=>$content,'estimated_provider_cost'=>0.0005,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3]),
            'facebook_posts' => self::base(['module'=>'social_media','actor_id'=>'apify/facebook-posts-scraper','display_name'=>'Facebook Posts Scraper','source_type'=>'facebook','use_case'=>'Public page/post collection.','supported_modes'=>['profile','post_url','competitor_content_scan'],'required_fields'=>['urls'],'result_schema'=>$content,'estimated_provider_cost'=>0.0025,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'primary'=>true,'fallback_actors'=>['facebook_comments']]),
            'facebook_comments' => self::base(['module'=>'social_media','actor_id'=>'apify/facebook-comments-scraper','display_name'=>'Facebook Comments Scraper','source_type'=>'facebook','use_case'=>'Comment intelligence for selected Facebook posts.','supported_modes'=>['comments'],'required_fields'=>['post_url'],'result_schema'=>['comment','author','date','likes','url'],'estimated_provider_cost'=>0.0017,'default_result_limit'=>50,'max_result_limit'=>500,'candidate_pool_multiplier'=>1]),
            'facebook_ads_library' => self::base(['module'=>'ads_intelligence','actor_id'=>'curious_coder/facebook-ads-library-scraper','display_name'=>'Facebook Ad Library Scraper','source_type'=>'facebook_ads','use_case'=>'Meta ad library competitor creative scan.','supported_modes'=>['advertiser','domain','competitor_domain_scan','creative_pattern_scan'],'required_fields'=>['advertiser_or_domain'],'result_schema'=>$ad,'estimated_provider_cost'=>0.00075,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'primary'=>true]),
            'reddit_runtime' => self::base(['module'=>'social_media','actor_id'=>'runtime/reddit-scraper','display_name'=>'Reddit Scraper - Detect pain points, leads, emerging trends','source_type'=>'reddit','use_case'=>'Deep Reddit pain-point and community scan.','supported_modes'=>['keyword','community','url','trend_discovery'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0036,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>4,'fallback_actors'=>['reddit_lite']]),
            'reddit_lite' => self::base(['module'=>'social_media','actor_id'=>'trudax/reddit-scraper-lite','display_name'=>'Reddit Scraper Lite','source_type'=>'reddit','use_case'=>'Low-friction Reddit collection.','supported_modes'=>['keyword','community','url'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0036,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3,'primary'=>true]),
            'pinterest_placeholder' => self::base(['module'=>'social_media','actor_id'=>'epctex/pinterest-scraper','display_name'=>'Pinterest Scraper','source_type'=>'pinterest','use_case'=>'Pinterest pin/profile/search support when actor is configured.','supported_modes'=>['keyword','profile','pin_url'],'required_fields'=>['targets'],'result_schema'=>$content,'estimated_provider_cost'=>0.0025,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3,'health_status'=>'configured_in_legacy_settings']),

            // LINKEDIN
            'linkedin_posts' => self::base(['module'=>'linkedin','actor_id'=>'supreme_coder/linkedin-post','display_name'=>'LinkedIn Post Scraper','source_type'=>'linkedin','use_case'=>'LinkedIn post/topic/company content scan only.','supported_modes'=>['analyze_posts','thought_leadership_topic_scan','competitor_company_scan'],'required_fields'=>['query_or_company_url'],'result_schema'=>$content,'estimated_provider_cost'=>0.0010,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>3,'primary'=>true,'admin_notes'=>'Observed actor portfolio only confirms post scraping. People/company/profile search modes require separate actor setup.']),
            'linkedin_people_setup_required' => self::base(['module'=>'linkedin','actor_id'=>'','display_name'=>'LinkedIn People / Company Actor Setup Required','source_type'=>'linkedin','use_case'=>'Placeholder for people/company/profile search.','supported_modes'=>['find_people','scan_profile','scan_company','search_companies','find_leads'],'required_fields'=>[],'result_schema'=>['not_configured'],'estimated_provider_cost'=>0,'default_result_limit'=>0,'max_result_limit'=>0,'enabled'=>false,'health_status'=>'setup_required','admin_notes'=>'Do not show as runnable to normal users until a compliant actor is configured.']),

            // GOOGLE / SEARCH / TRENDS / NEWS / MAPS
            'google_search' => self::base(['module'=>'google_intelligence','actor_id'=>'apify/google-search-scraper','display_name'=>'Google Search Results Scraper','source_type'=>'google_search','use_case'=>'Organic SERP and public search evidence.','supported_modes'=>['search','serp_analysis','content_gap'],'required_fields'=>['query','market'],'result_schema'=>$web,'estimated_provider_cost'=>0.00195,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'primary'=>true,'fallback_actors'=>['google_serp']]),
            'google_serp' => self::base(['module'=>'google_intelligence','actor_id'=>'scraperlink/google-search-results-serp-scraper','display_name'=>'Google Search Results (<$0.05/1K Results)','source_type'=>'google_search','use_case'=>'Low-cost SERP fallback.','supported_modes'=>['search','serp_analysis'],'required_fields'=>['query'],'result_schema'=>$web,'estimated_provider_cost'=>0.00005,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3]),
            'google_trends' => self::base(['module'=>'trend_finder','actor_id'=>'apify/google-trends-scraper','display_name'=>'Google Trends Scraper','source_type'=>'google_trends','use_case'=>'Search-driven trend discovery.','supported_modes'=>['search_driven','seasonal','emerging'],'required_fields'=>['topic','market','time_range'],'result_schema'=>['query','interest_over_time','related_queries','region','trend_score'],'estimated_provider_cost'=>0.0005,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2,'primary'=>true,'fallback_actors'=>['google_trends_fast']]),
            'google_trends_fast' => self::base(['module'=>'trend_finder','actor_id'=>'data_xplorer/google-trends-fast-scraper','display_name'=>'Google Trends Fast Scraper','source_type'=>'google_trends','use_case'=>'Fast/low-cost trends fallback.','supported_modes'=>['search_driven','emerging'],'required_fields'=>['topic'],'result_schema'=>['query','trend_score','region'],'estimated_provider_cost'=>0.0010,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2]),
            'google_trends_interest' => self::base(['module'=>'trend_finder','actor_id'=>'apify/google-trends-scraper','actor_slug'=>'apify/google-trends-scraper','display_name'=>'Google Trends Interest Baseline','source_key'=>'google_trends_interest','source_family'=>'search_demand','source_role'=>'interest_baseline','platform'=>'google_trends','source_type'=>'google_trends','use_case'=>'Required low-cost search-demand baseline for Trend Finder.','supported_modes'=>['cheap_probe','balanced','deep_scan'],'required_fields'=>['searchTerms'],'input_builder'=>'google_trends_interest','output_normalizer'=>'trend_signal_google_trends','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Uses short seed/local/query variants only. Business goal remains AI context.','estimated_provider_cost'=>0.0005,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2,'primary'=>true,'fallback_actors'=>['google_search_freshness']]),
            'google_trends_trending_now' => self::base(['module'=>'trend_finder','actor_id'=>'data_xplorer/google-trends-trending-now','actor_slug'=>'data_xplorer/google-trends-trending-now','display_name'=>'Google Trends Trending Now','source_key'=>'google_trends_trending_now','source_family'=>'market_context','source_role'=>'ambient_trending_now','platform'=>'google_trends','source_type'=>'google_trends','use_case'=>'Ambient market context; not direct topic proof unless semantically matched.','supported_modes'=>['deep_scan'],'required_fields'=>['country'],'input_builder'=>'google_trends_trending_now','output_normalizer'=>'trend_signal_trending_now','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Ambient only by default.','estimated_provider_cost'=>0.0010,'default_result_limit'=>25,'max_result_limit'=>80,'candidate_pool_multiplier'=>1]),
            'google_search_freshness' => self::base(['module'=>'trend_finder','actor_id'=>'apify/google-search-scraper','actor_slug'=>'apify/google-search-scraper','display_name'=>'Google Search Freshness','source_key'=>'google_search_freshness','source_family'=>'search_demand','source_role'=>'fresh_serp_context','platform'=>'google_search','source_type'=>'google_search','use_case'=>'Fresh SERP/news-like validation fallback for weak Google Trends signals.','supported_modes'=>['cheap_probe','balanced','deep_scan'],'required_fields'=>['queries'],'input_builder'=>'google_search_freshness','output_normalizer'=>'trend_signal_google_search','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Uses local/question variants only.','estimated_provider_cost'=>0.0010,'default_result_limit'=>10,'max_result_limit'=>50,'candidate_pool_multiplier'=>1]),
            'google_news_freshness' => self::base(['module'=>'trend_finder','actor_id'=>'apify/google-search-scraper','actor_slug'=>'apify/google-search-scraper','display_name'=>'Google News Freshness','source_key'=>'google_news_freshness','source_family'=>'search_demand','source_role'=>'news_freshness','platform'=>'google_search','source_type'=>'google_search','use_case'=>'News-like fallback for source failure or weak search demand.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['queries'],'input_builder'=>'google_news_freshness','output_normalizer'=>'trend_signal_google_search','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Configure with an actor/source that returns fresh search/news results.','estimated_provider_cost'=>0.0010,'default_result_limit'=>10,'max_result_limit'=>50,'candidate_pool_multiplier'=>1]),
            'x_topic_posts' => self::base(['module'=>'trend_finder','actor_id'=>'apidojo/tweet-scraper','actor_slug'=>'apidojo/tweet-scraper','display_name'=>'X Topic Posts','source_key'=>'x_topic_posts','source_family'=>'social_conversation','source_role'=>'topic_posts','platform'=>'twitter','source_type'=>'twitter','use_case'=>'Topic-specific X posts for direct/adjacent social conversation evidence.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['searchTerms'],'input_builder'=>'x_topic_posts','output_normalizer'=>'trend_signal_social_post','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'medium','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Fallback from ambient X trend list when country trends do not match topic.','estimated_provider_cost'=>0.0004,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>2]),
            'x_trend_list' => self::base(['module'=>'trend_finder','actor_id'=>'karamelo/twitter-trends-scraper','actor_slug'=>'karamelo/twitter-trends-scraper','display_name'=>'X Trend List','source_key'=>'x_trend_list','source_family'=>'social_trend_feed','source_role'=>'ambient_trend_list','platform'=>'twitter','source_type'=>'twitter','use_case'=>'Country-level ambient X trend list.','supported_modes'=>['deep_scan'],'required_fields'=>['market'],'input_builder'=>'x_trend_list','output_normalizer'=>'trend_signal_ambient_list','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'low','requires_rental_or_access'=>false,'admin_notes'=>'Ambient unless semantically matched; never confirms a topic alone.','estimated_provider_cost'=>0.00018,'default_result_limit'=>30,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'tiktok_topic_videos' => self::base(['module'=>'trend_finder','actor_id'=>'clockworks/tiktok-scraper','actor_slug'=>'clockworks/tiktok-scraper','display_name'=>'TikTok Topic Videos','source_key'=>'tiktok_topic_videos','source_family'=>'content_format','source_role'=>'topic_videos','platform'=>'tiktok','source_type'=>'tiktok','use_case'=>'TikTok topic search videos; supports format/hook evidence and direct signals when semantically matched.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['searchQueries'],'input_builder'=>'tiktok_topic_videos','output_normalizer'=>'trend_signal_social_video','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'medium','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Uses Clockworks searchQueries/hashtags only; no mixed keywords/searchTerms/startUrls.','estimated_provider_cost'=>0.0023,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'fallback_actors'=>['tiktok_hashtag_trends']]),
            'tiktok_hashtag_trends' => self::base(['module'=>'trend_finder','actor_id'=>'clockworks/tiktok-hashtag-scraper','actor_slug'=>'clockworks/tiktok-hashtag-scraper','display_name'=>'TikTok Hashtag Trends','source_key'=>'tiktok_hashtag_trends','source_family'=>'social_trend_feed','source_role'=>'hashtag_trends','platform'=>'tiktok','source_type'=>'tiktok','use_case'=>'TikTok hashtag evidence for short hashtag candidates.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['hashtags'],'input_builder'=>'tiktok_hashtag_trends','output_normalizer'=>'trend_signal_social_video','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'medium','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Hashtag-only contract.','estimated_provider_cost'=>0.0030,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>2,'fallback_actors'=>['tiktok_topic_videos','lexis-solutions/tiktok-trending-hashtags']]),
            'youtube_topic_videos' => self::base(['module'=>'trend_finder','actor_id'=>'streamers/youtube-scraper','actor_slug'=>'streamers/youtube-scraper','display_name'=>'YouTube Topic Videos','source_key'=>'youtube_topic_videos','source_family'=>'content_format','source_role'=>'topic_videos','platform'=>'youtube','source_type'=>'youtube','use_case'=>'YouTube topic search for educational/content-format signals.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['searchQueries'],'input_builder'=>'youtube_topic_videos','output_normalizer'=>'trend_signal_social_video','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'medium','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Topic search fallback for generic trending categories.','estimated_provider_cost'=>0.0026,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>2]),
            'reddit_topic_posts' => self::base(['module'=>'trend_finder','actor_id'=>'trudax/reddit-scraper-lite','actor_slug'=>'trudax/reddit-scraper-lite','display_name'=>'Reddit Topic Posts','source_key'=>'reddit_topic_posts','source_family'=>'social_conversation','source_role'=>'discussion_posts','platform'=>'reddit','source_type'=>'reddit','use_case'=>'Reddit discussions for pain points, objections, questions and language of customer.','supported_modes'=>['balanced','deep_scan'],'required_fields'=>['searches'],'input_builder'=>'reddit_topic_posts','output_normalizer'=>'trend_signal_reddit','renderer_type'=>'trend_source_card','cost_method'=>'pay_per_result','permission_status'=>'configured','health_status'=>'unknown','estimated_cost_level'=>'low','reliability_level'=>'medium','requires_rental_or_access'=>false,'admin_notes'=>'Discussion evidence; not a viral-trend proof source alone.','estimated_provider_cost'=>0.0005,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>2]),
            'google_news_easyapi' => self::base(['module'=>'google_intelligence','actor_id'=>'easyapi/google-news-scraper','display_name'=>'Google News Scraper','source_type'=>'google_news','use_case'=>'News signal collection.','supported_modes'=>['news','trend_discovery'],'required_fields'=>['topic'],'result_schema'=>$web,'estimated_provider_cost'=>0.0050,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2,'fallback_actors'=>['google_news_ihotanova']]),
            'google_news_ihotanova' => self::base(['module'=>'google_intelligence','actor_id'=>'ihotanova/google-news-scraper','display_name'=>'Google News Scraper (Ihotanova)','source_type'=>'google_news','use_case'=>'News fallback.','supported_modes'=>['news'],'required_fields'=>['topic'],'result_schema'=>$web,'estimated_provider_cost'=>0.0050,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2]),
            // v0.3.55: shipped-default actors that dispatch paths resolve but the
            // registry never listed. Their absence made the allowlist gate block
            // the plugin's own default configuration (live "actor not
            // allowlisted" failures: DTF Google News skipped, trend slots dead).
            'google_news_fast' => self::base(['module'=>'deep_trend_finder','actor_id'=>'data_xplorer/google-news-scraper-fast','display_name'=>'Google News Fast Scraper','source_type'=>'google_news','use_case'=>'Deep Trend Finder default Google News source.','supported_modes'=>['news','trend_discovery'],'required_fields'=>['keywords'],'result_schema'=>$web,'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>200,'candidate_pool_multiplier'=>2,'primary'=>true]),
            'tiktok_trending_videos' => self::base(['module'=>'trend_finder','actor_id'=>'data_xplorer/tiktok-trends','display_name'=>'TikTok Trending Videos','source_type'=>'tiktok','use_case'=>'Ambient TikTok trending video feed for trend slots.','supported_modes'=>['deep_scan','viral'],'required_fields'=>['country'],'result_schema'=>$content,'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'tiktok_trending_creators' => self::base(['module'=>'trend_finder','actor_id'=>'lexis-solutions/tiktok-trending-creators','display_name'=>'TikTok Trending Creators','source_type'=>'tiktok','use_case'=>'Ambient TikTok creator trend feed.','supported_modes'=>['deep_scan'],'required_fields'=>['country'],'result_schema'=>['username','profile_url','followers','growth'],'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'tiktok_trending_sounds' => self::base(['module'=>'trend_finder','actor_id'=>'alien_force/tiktok-trending-sounds','display_name'=>'TikTok Trending Sounds','source_type'=>'tiktok','use_case'=>'Ambient TikTok sound trend feed.','supported_modes'=>['deep_scan'],'required_fields'=>['country'],'result_schema'=>['sound_title','author','videos_count','url'],'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'reddit_trends' => self::base(['module'=>'trend_finder','actor_id'=>'easyapi/reddit-trends-scraper','display_name'=>'Reddit Trends Scraper','source_type'=>'reddit','use_case'=>'Ambient Reddit trend feed for trend slots.','supported_modes'=>['deep_scan','trend_discovery'],'required_fields'=>['market'],'result_schema'=>$content,'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'google_places' => self::base(['module'=>'google_intelligence','actor_id'=>'compass/crawler-google-places','display_name'=>'Google Maps Scraper','source_type'=>'google_maps','use_case'=>'Maps/category/local business discovery.','supported_modes'=>['maps','local_competitor_scan'],'required_fields'=>['business_or_category','location'],'result_schema'=>['name','address','rating','reviews_count','category','url','phone','website'],'estimated_provider_cost'=>0.033,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2]),
            'google_maps_reviews' => self::base(['module'=>'reviews_intelligence','actor_id'=>'compass/google-maps-reviews-scraper','display_name'=>'Google Maps Reviews Scraper','source_type'=>'google_maps_reviews','use_case'=>'Public review intelligence.','supported_modes'=>['reviews','maps_reviews'],'required_fields'=>['place_url_or_business'],'result_schema'=>$review,'estimated_provider_cost'=>0.0004,'default_result_limit'=>50,'max_result_limit'=>500,'candidate_pool_multiplier'=>1]),
            'google_images' => self::base(['module'=>'google_intelligence','actor_id'=>'easyapi/google-images-scraper','display_name'=>'Google Images Scraper','source_type'=>'google_images','use_case'=>'Image search evidence for creative/category signals.','supported_modes'=>['images'],'required_fields'=>['query'],'result_schema'=>['title','image_url','source_url','domain','thumbnail_url'],'estimated_provider_cost'=>0.0050,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>2]),

            // SEO / SEM / AEO
            'seo_semrush_keyword_magic' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'burbn/semrush-keyword-magic-tool','display_name'=>'Semrush Keyword Magic Tool','source_type'=>'seo_keywords','use_case'=>'Keyword research: volume, intent, CPC, competition/difficulty where available.','supported_modes'=>['keyword_research','content_gap','aeo_opportunity'],'required_fields'=>['seed_keyword','country','language'],'result_schema'=>$keyword,'pricing_model'=>'from_5_usd_per_1000_results','estimated_provider_cost'=>0.0050,'default_result_limit'=>25,'max_result_limit'=>1000,'candidate_pool_multiplier'=>5,'timeout'=>120,'primary'=>true,'fallback_actors'=>['google_search','seo_ahrefs','seo_moz','seo_answer_the_public']]),
            'seo_semrush_pro' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'pro100chok/semrush-scraper','display_name'=>'Semrush All-in-One Scraper — Traffic, Authority, Backlinks','source_type'=>'seo_domain','use_case'=>'Domain overview, authority, traffic, backlinks.','supported_modes'=>['competitor_seo_scan','domain_overview','backlinks'],'required_fields'=>['domain_or_url'],'result_schema'=>['domain','authority','traffic','backlinks','keywords','competitors'],'estimated_provider_cost'=>0.0020,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1,'fallback_actors'=>['seo_semrush_radeance','seo_ahrefs','seo_moz']]),
            // v0.9.30.1: registry key and actor_id corrected from the `raedance` typo to
            // `radeance`, the actual Apify vendor namespace used by class-ves-config.php,
            // class-ves-admin.php defaults and the SEO templates. The previous mismatch
            // meant the Domain/SEO fallback path could reference a non-existent actor.
            'seo_semrush_radeance' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'radeance/semrush-scraper','display_name'=>'Semrush Scraper','source_type'=>'seo_domain','use_case'=>'Domain/SEO data fallback.','supported_modes'=>['competitor_seo_scan','domain_overview','top_websites'],'required_fields'=>['domain_or_url'],'result_schema'=>['domain','authority','traffic','backlinks','keywords','competitors'],'estimated_provider_cost'=>0.0050,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1]),
            'seo_ahrefs' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'pro100chok/ahrefs-seo-tools','display_name'=>'Ahrefs All-in-One SEO Scraper - DR, Backlinks, Keywords','source_type'=>'seo_domain','use_case'=>'Ahrefs-style keyword/domain/backlink scan.','supported_modes'=>['keyword_research','serp_analysis','backlink_domain_overview','competitor_seo_scan'],'required_fields'=>['keyword_or_domain'],'result_schema'=>$keyword,'estimated_provider_cost'=>0.0050,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'fallback_actors'=>['seo_moz','google_search']]),
            'seo_moz' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'radeance/moz-scraper','display_name'=>'MOZ Scraper','source_type'=>'seo_domain','use_case'=>'Domain authority and SEO benchmarking.','supported_modes'=>['domain_overview','backlink_domain_overview'],'required_fields'=>['domain_or_url'],'result_schema'=>['domain','domain_authority','page_authority','spam_score','links'],'estimated_provider_cost'=>0.0080,'default_result_limit'=>20,'max_result_limit'=>80,'candidate_pool_multiplier'=>1]),
            'seo_answer_the_public' => self::base(['module'=>'seo_sem_aeo','actor_id'=>'deadlyaccurate/answer-the-public','display_name'=>'Answer The Public','source_type'=>'seo_questions','use_case'=>'Questions/comparisons/prepositions content gap source.','supported_modes'=>['questions','aeo_opportunity','content_gap'],'required_fields'=>['seed_keyword','market'],'result_schema'=>['question','preposition','comparison','related_term','cluster'],'estimated_provider_cost'=>0.0040,'default_result_limit'=>50,'max_result_limit'=>500,'candidate_pool_multiplier'=>2,'enabled'=>false,'admin_notes'=>'Enable only after verifying actor maintenance/API access.']),

            // ADS / REVIEWS / WEB
            'ads_google_silva' => self::base(['module'=>'ads_intelligence','actor_id'=>'silva95gustavo/google-ads-scraper','display_name'=>'Google Ads Scraper','source_type'=>'google_ads','use_case'=>'Google Ads Transparency Center competitor scan.','supported_modes'=>['advertiser','domain','competitor_domain_scan'],'required_fields'=>['domain_or_advertiser'],'result_schema'=>$ad,'estimated_provider_cost'=>0.0300,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3,'fallback_actors'=>['ads_google_dz']]),
            'ads_google_dz' => self::base(['module'=>'ads_intelligence','actor_id'=>'dz_omar/google-ads-scraper','display_name'=>'Google Ads Scraper (dz_omar)','source_type'=>'google_ads','use_case'=>'Google Ads Transparency fallback.','supported_modes'=>['advertiser','domain'],'required_fields'=>['domain_or_advertiser'],'result_schema'=>$ad,'estimated_provider_cost'=>0.00195,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>3]),
            'reviews_amazon' => self::base(['module'=>'reviews_intelligence','actor_id'=>'axesso_data/amazon-reviews-scraper','display_name'=>'Amazon Reviews Scraper','source_type'=>'amazon_reviews','use_case'=>'Ecommerce review/pain-point scan.','supported_modes'=>['product_reviews','competitor_reviews'],'required_fields'=>['product_url'],'result_schema'=>$review,'estimated_provider_cost'=>0.00075,'default_result_limit'=>50,'max_result_limit'=>500,'candidate_pool_multiplier'=>1]),
            'web_scraper' => self::base(['module'=>'web_intelligence','actor_id'=>'apify/web-scraper','display_name'=>'Web Scraper','source_type'=>'web','use_case'=>'General web page extraction; admin-configured only.','supported_modes'=>['url_ingest','competitor_web_scan'],'required_fields'=>['url'],'result_schema'=>$web,'estimated_provider_cost'=>0.0010,'default_result_limit'=>20,'max_result_limit'=>100,'candidate_pool_multiplier'=>1,'enabled'=>false,'admin_notes'=>'Enable only for vetted domains and robots/legal constraints.']),
            'website_crawler' => self::base(['module'=>'web_intelligence','actor_id'=>'apify/website-content-crawler','display_name'=>'Website Content Crawler','source_type'=>'web','use_case'=>'Website crawl for brand/competitor content evidence.','supported_modes'=>['website_crawl','brand_deep_audit'],'required_fields'=>['url'],'result_schema'=>$web,'estimated_provider_cost'=>0.0010,'default_result_limit'=>50,'max_result_limit'=>500,'candidate_pool_multiplier'=>1]),
            'telegram_group_member' => self::base(['module'=>'social_media','actor_id'=>'truefetch/telegram-group-member','display_name'=>'Telegram Group Member','source_type'=>'telegram','use_case'=>'Telegram member extraction; not core marketing intelligence.','supported_modes'=>['audience_graph'],'required_fields'=>['group_url'],'result_schema'=>['username','name','profile_url'],'estimated_provider_cost'=>0.00051,'default_result_limit'=>100,'max_result_limit'=>5000,'enabled'=>false,'admin_notes'=>'Disabled by default; not a core public-signal module.']),
        ];
    }

    public static function registry() {
        $registry = self::defaults();
        $overrides = get_option(self::OPTION_KEY, []);
        if (is_array($overrides)) {
            foreach ($overrides as $key => $override) {
                $key = sanitize_key((string) $key);
                if ($key === '' || !is_array($override)) { continue; }
                $registry[$key] = array_merge($registry[$key] ?? self::base(), $override);
            }
        }
        return $registry;
    }

    public static function all_for_admin() {
        $rows = [];
        foreach (self::registry() as $key => $config) {
            $rows[] = array_merge(['key' => $key], is_array($config) ? $config : []);
        }
        usort($rows, static function($a, $b) {
            return strcmp((string) ($a['module'] ?? ''), (string) ($b['module'] ?? '')) ?: strcmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        });
        return $rows;
    }

    // ── v0.1 RC — actor slug allowlist (provider dispatch hard gate) ──────────

    /** Canonical slug form used for allowlist comparison: lowercase, `owner~actor`. */
    public static function normalize_slug($slug) {
        $slug = strtolower(str_replace('/', '~', trim((string) $slug)));
        return preg_replace('/[^a-z0-9~\-_.]/', '', $slug);
    }

    /**
     * Every actor slug this install is allowed to dispatch. Sources, in order:
     * the code-backed registry (actor_id + fallback_actors, including disabled
     * entries — "known" is what the gate means, enable/disable is policy on top),
     * the legacy per-platform slug map (VES_Config::get_actor_slug), the
     * ves_apify_actor_allowlist_extra option, and the ves_apify_actor_allowlist
     * filter. Returned normalized + deduped.
     */
    public static function allowed_slugs() {
        $slugs = [];
        foreach (self::registry() as $config) {
            if (!is_array($config)) { continue; }
            $candidates = array_merge(
                [(string) ($config['actor_id'] ?? '')],
                array_map('strval', (array) ($config['fallback_actors'] ?? []))
            );
            foreach ($candidates as $c) {
                $c = self::normalize_slug($c);
                if ($c !== '') { $slugs[$c] = true; }
            }
        }
        // The configured-slug enumeration must cover EVERY platform key a
        // dispatch path can resolve. The previous hand-maintained subset missed
        // the trend slots (tiktok_trending_videos, reddit_trends, ...) and the
        // semrush modes, so the gate blocked the plugin's own shipped defaults
        // at dispatch time (live "actor not allowlisted" failures).
        if (class_exists('VES_Config') && method_exists('VES_Config', 'all_actor_slugs')) {
            foreach ((array) VES_Config::all_actor_slugs() as $configured) {
                $c = self::normalize_slug($configured);
                if ($c !== '') { $slugs[$c] = true; }
            }
        } elseif (class_exists('VES_Config') && method_exists('VES_Config', 'get_actor_slug')) {
            $legacy_platforms = ['tiktok', 'tiktok_enrichment', 'tiktok_comments', 'tiktok_fallback', 'tiktok_backup', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'google', 'google_serp', 'google_trends', 'google_news', 'google_maps', 'amazon', 'web', 'telegram'];
            foreach ($legacy_platforms as $platform) {
                $c = self::normalize_slug(VES_Config::get_actor_slug($platform));
                if ($c !== '') { $slugs[$c] = true; }
            }
        }
        $extra = function_exists('get_option') ? get_option('ves_apify_actor_allowlist_extra', []) : [];
        foreach ((array) $extra as $c) {
            $c = self::normalize_slug($c);
            if ($c !== '') { $slugs[$c] = true; }
        }
        $list = array_keys($slugs);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('ves_apify_actor_allowlist', $list);
            if (is_array($filtered)) {
                $list = [];
                foreach ($filtered as $c) { $c = self::normalize_slug($c); if ($c !== '') { $list[] = $c; } }
                $list = array_values(array_unique($list));
            }
        }
        return $list;
    }

    /** Hard gate used by VES_Apify_Client before any run dispatch. */
    public static function is_allowed_slug($slug) {
        $slug = self::normalize_slug($slug);
        if ($slug === '') { return false; }
        return in_array($slug, self::allowed_slugs(), true);
    }

    /**
     * v0.3.55 — preflight used by run planners/dispatchers BEFORE any dispatch
     * attempt. A source whose actor cannot pass the dispatch gate must be
     * excluded from the plan (skipped, never runnable in the UI) instead of
     * failing mid-run with a misleading transport error.
     *
     * Returns ['ok' => bool, 'reason' => '', 'detail' => ''] where reason is one
     * of: actor_not_configured | actor_not_allowlisted | ok.
     */
    public static function preflight_actor_slug($slug) {
        $raw = trim((string) $slug);
        if ($raw === '') {
            return [
                'ok' => false,
                'reason' => 'actor_not_configured',
                'detail' => 'No provider actor is configured for this source.',
            ];
        }
        if (!self::is_allowed_slug($raw)) {
            return [
                'ok' => false,
                'reason' => 'actor_not_allowlisted',
                'detail' => 'Actor "' . $raw . '" is not registered in the server actor registry/allowlist.',
            ];
        }
        return ['ok' => true, 'reason' => 'ok', 'detail' => ''];
    }

    /**
     * Phase 9A.2 — true only when a registry entry explicitly marks this actor
     * zero-cost. Used as the ONLY exception to the hard charge-ceiling gate; an
     * unknown slug is never zero-cost.
     */
    public static function is_zero_cost_slug($slug) {
        $slug = self::normalize_slug($slug);
        if ($slug === '' || !self::is_allowed_slug($slug)) { return false; }
        foreach (self::registry() as $config) {
            if (!is_array($config) || empty($config['zero_cost'])) { continue; }
            $candidates = array_merge(
                [(string) ($config['actor_id'] ?? '')],
                array_map('strval', (array) ($config['fallback_actors'] ?? []))
            );
            foreach ($candidates as $c) {
                if (self::normalize_slug($c) === $slug) { return true; }
            }
        }
        return false;
    }

    private static function find_primary($module, $source_type = '', $mode = '') {
        $module = sanitize_key((string) $module);
        $source_type = sanitize_key((string) $source_type);
        $mode = sanitize_key((string) $mode);
        $fallback = null;
        foreach (self::registry() as $key => $config) {
            if (sanitize_key((string) ($config['module'] ?? '')) !== $module) { continue; }
            if ($source_type !== '' && sanitize_key((string) ($config['source_type'] ?? '')) !== $source_type) { continue; }
            $modes = array_map('sanitize_key', (array) ($config['supported_modes'] ?? []));
            if ($mode !== '' && !in_array($mode, $modes, true)) { continue; }
            $candidate = array_merge(['key' => $key], $config);
            if (!empty($config['primary'])) { return $candidate; }
            if ($fallback === null) { $fallback = $candidate; }
        }
        return $fallback;
    }

    public static function resolve($module_or_key, $source = []) {
        $registry = self::registry();
        $needle = sanitize_key((string) $module_or_key);
        if (isset($registry[$needle])) { return array_merge(['key' => $needle], $registry[$needle]); }
        $source = is_array($source) ? $source : [];
        $source_type = sanitize_key((string) ($source['source_type'] ?? $source['type'] ?? $source['platform'] ?? ''));
        $mode = sanitize_key((string) ($source['mode'] ?? $source['use_case'] ?? $source['objective'] ?? $source['linkedinObjective'] ?? ''));

        if ($needle === 'brand_deep_audit') {
            if (strpos($source_type, 'review') !== false || strpos($source_type, 'trust') !== false) { return array_merge(['key' => 'google_maps_reviews'], $registry['google_maps_reviews']); }
            if (in_array($source_type, ['tiktok','instagram','youtube','twitter','facebook','reddit'], true)) {
                $candidate = self::resolve($source_type === 'twitter' ? 'twitter_tweet_scraper' : ($source_type . '_scraper'), $source);
                if (!empty($candidate['key'])) { return $candidate; }
            }
            return array_merge(['key' => 'google_search'], $registry['google_search']);
        }

        if ($needle === 'trend_finder') {
            if ($source_type === 'youtube') { return array_merge(['key' => 'youtube_trending_categories'], $registry['youtube_trending_categories']); }
            if ($source_type === 'tiktok') { return array_merge(['key' => 'tiktok_trends'], $registry['tiktok_trends']); }
            if ($source_type === 'twitter') { return array_merge(['key' => 'twitter_trends'], $registry['twitter_trends']); }
            return array_merge(['key' => 'google_trends'], $registry['google_trends']);
        }

        if ($needle === 'linkedin') {
            if (in_array($mode, ['analyze_posts','thought_leadership_topic_scan','competitor_company_scan','search',''], true)) { return array_merge(['key' => 'linkedin_posts'], $registry['linkedin_posts']); }
            return array_merge(['key' => 'linkedin_people_setup_required'], $registry['linkedin_people_setup_required']);
        }

        if (in_array($needle, ['semrush','seo','seo_sem_aeo'], true)) {
            if (in_array($mode, ['keyword_magic','keyword_research','content_gap','aeo_opportunity',''], true)) { return array_merge(['key' => 'seo_semrush_keyword_magic'], $registry['seo_semrush_keyword_magic']); }
            if (in_array($mode, ['domain_seo','top_websites','competitor_seo_scan','domain_overview'], true)) { return array_merge(['key' => 'seo_semrush_radeance'], $registry['seo_semrush_radeance']); }
        }

        $alias = [
            'instagram' => 'instagram_scraper', 'tiktok' => 'tiktok_apidojo', 'youtube' => 'youtube_scraper', 'facebook' => 'facebook_posts', 'twitter' => 'twitter_tweet_scraper', 'reddit' => 'reddit_lite', 'pinterest' => 'pinterest_placeholder', 'facebook_ads' => 'facebook_ads_library', 'google_ads' => 'ads_google_dz', 'moz' => 'seo_moz', 'ahrefs' => 'seo_ahrefs', 'google_intel' => 'google_search', 'google_search' => 'google_search',
        ];
        if (isset($alias[$needle]) && isset($registry[$alias[$needle]])) { return array_merge(['key' => $alias[$needle]], $registry[$alias[$needle]]); }
        $primary = self::find_primary($needle, $source_type, $mode);
        if ($primary) { return $primary; }
        foreach ($registry as $key => $config) {
            if (sanitize_key((string) ($config['module'] ?? '')) === $needle) { return array_merge(['key' => $key], $config); }
        }
        return array_merge(['key' => 'google_search'], $registry['google_search']);
    }

    public static function validate_input($actor, $input = []) {
        $actor = is_array($actor) ? $actor : self::resolve((string) $actor);
        $input = is_array($input) ? $input : [];
        if (empty($actor['enabled'])) {
            return new WP_Error('ves_actor_disabled', __('This data source is not configured yet. Ask an admin to enable or configure the required actor.', 'ves'), ['actor_key' => $actor['key'] ?? '', 'health_status' => $actor['health_status'] ?? 'disabled']);
        }
        if (!empty($actor['requires_login']) && !is_user_logged_in()) {
            return new WP_Error('ves_login_required', __('Please sign in to run this data collection.', 'ves'));
        }
        $max = max(1, (int) ($actor['max_results_max'] ?? $actor['max_result_limit'] ?? 50));
        $requested = max(1, (int) ($input['max_results'] ?? $input['maxItems'] ?? $input['resultsLimit'] ?? $actor['max_results_default'] ?? $actor['default_result_limit'] ?? 20));
        if ($requested > $max) {
            return new WP_Error('ves_actor_limit_exceeded', sprintf(__('This run asks for %1$d items, but the configured source limit is %2$d. Reduce the result count and try again.', 'ves'), $requested, $max), ['requested' => $requested, 'max' => $max]);
        }
        return true;
    }

    public static function estimate_input_cost($actor, $input = []) {
        $actor = is_array($actor) ? $actor : self::resolve((string) $actor);
        $input = is_array($input) ? $input : [];
        $requested = max(1, (int) ($input['desired_final_results'] ?? $input['limit'] ?? $input['max_results'] ?? $input['maxItems'] ?? $actor['default_result_limit'] ?? 20));
        $multiplier = max(1.0, (float) ($input['candidate_pool_multiplier'] ?? $actor['candidate_pool_multiplier'] ?? 1));
        $candidate_pool = max($requested, (int) ($input['candidate_pool_size'] ?? ceil($requested * $multiplier)));
        $candidate_pool = min($candidate_pool, max(1, (int) ($actor['max_result_limit'] ?? $actor['max_results_max'] ?? $candidate_pool)));
        $unit = max(0.0, (float) ($actor['estimated_provider_cost'] ?? $actor['estimated_cost_per_item'] ?? 0.0));
        return [
            'actor_key' => (string) ($actor['key'] ?? ''),
            'module' => (string) ($actor['module'] ?? ''),
            'provider' => (string) ($actor['provider'] ?? 'apify'),
            'source_type' => (string) ($actor['source_type'] ?? ''),
            'requested_final_results' => $requested,
            'candidate_pool_size' => $candidate_pool,
            'candidate_pool_multiplier' => $multiplier,
            'estimated_cost_per_item' => $unit,
            'estimated_usd' => round($candidate_pool * $unit, 6),
            'pricing_model' => (string) ($actor['pricing_model'] ?? 'estimated_per_result'),
        ];
    }

    public static function estimate_sources($sources = []) {
        $sources = is_array($sources) ? $sources : [];
        $items = [];
        $total = 0.0;
        foreach ($sources as $source) {
            $source = is_array($source) ? $source : [];
            $module = sanitize_key((string) ($source['module'] ?? 'brand_deep_audit'));
            $actor = self::resolve($module, $source);
            $input = [
                'max_results' => $source['max_results'] ?? $source['maxItems'] ?? $actor['max_results_default'] ?? 20,
                'desired_final_results' => $source['desired_final_results'] ?? $source['limit'] ?? null,
                'candidate_pool_size' => $source['candidate_pool_size'] ?? null,
            ];
            $estimate = self::estimate_input_cost($actor, $input);
            $estimate['display_name'] = (string) ($actor['display_name'] ?? $estimate['actor_key']);
            $estimate['source_type'] = (string) ($source['source_type'] ?? $source['type'] ?? ($actor['source_type'] ?? ''));
            $items[] = $estimate;
            $total += (float) $estimate['estimated_usd'];
        }
        return [
            'provider' => 'apify',
            'source_count' => count($items),
            'estimated_usd' => round($total, 6),
            'items' => $items,
        ];
    }
}
