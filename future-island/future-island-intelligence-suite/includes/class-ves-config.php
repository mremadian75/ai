<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v0.9.25.0 — Phase 2: internal default provider request count.
 * Normal users no longer see a "Cantidad" / "Candidate pool" field.
 * Forms ship a hidden `limit` input that defaults to this number.
 * Admin can override per-module from settings without exposing it in UI.
 */
if (!defined('VES_DEFAULT_PROVIDER_REQUEST_COUNT')) {
    define('VES_DEFAULT_PROVIDER_REQUEST_COUNT',
        (int) apply_filters('ves_default_provider_request_count', 50));
}

final class VES_Config {
    public static function get_settings() {
        if (class_exists('VES_Admin')) {
            return VES_Admin::get_settings();
        }
        return [];
    }

    public static function get_token() {
        if (defined('VE_SOCIAL_BACKEND_TOKEN') && VE_SOCIAL_BACKEND_TOKEN) {
            return trim((string) VE_SOCIAL_BACKEND_TOKEN);
        }
        return trim((string) (self::get_settings()['backend_token'] ?? ''));
    }

    public static function get_actor_slug($platform, $context = []) {
        $platform = sanitize_key((string) $platform);
        $settings = self::get_settings();
        $defaults = [
            'tiktok' => 'apidojo/tiktok-scraper',
            'tiktok_enrichment' => 'scraptik/tiktok-api',
            'tiktok_comments' => 'scraptik/tiktok-comments-scraper-api',
            'tiktok_fallback' => 'clockworks/tiktok-scraper',
            'tiktok_backup' => 'api-ninja/tiktok-data-scraper',
            'youtube' => 'streamers/youtube-scraper',
            'facebook' => 'apify/facebook-posts-scraper',
            'instagram' => 'apify/instagram-scraper',
            'facebook_ads' => 'curious_coder/facebook-ads-library-scraper',
            'google_ads' => 'dz_omar/google-ads-scraper',
            'twitter' => 'apidojo/tweet-scraper',
            'linkedin' => 'harvestapi/linkedin-profile-search',
            'reddit' => 'trudax/reddit-scraper-lite',
            'pinterest' => 'epctex/pinterest-scraper',
            'semrush' => 'saswave/semrush-agencies-partner-scraper',
            'semrush_keywords' => 'marceli/semrush-keyworlds-scraper',
            'semrush_keyword_magic' => 'burbn/semrush-keyword-magic-tool',
            'semrush_domain' => 'radeance/semrush-scraper',
            'moz' => 'radeance/moz-scraper',
            'ahrefs' => 'pro100chok/ahrefs-seo-tools',
            'google_trends' => 'apify/google-trends-scraper',
            'youtube_trending' => 'eunit/youtube-trending-videos-by-categories',
            'tiktok_trends' => 'clockworks/tiktok-trends-scraper',
            'twitter_trends' => 'karamelo/twitter-trends-scraper',
            'google_trends_interest' => 'data_xplorer/google-trends-fast-scraper',
            'google_trends_trending_now' => 'data_xplorer/google-trends-trending-now',
            'google_search_freshness' => 'apify/google-search-scraper',
            'google_news_freshness' => 'apify/google-search-scraper',
            'semrush_keyword_context' => 'burbn/semrush-keyword-magic-tool',
            'x_topic_posts' => 'apidojo/tweet-scraper',
            'x_trend_list' => 'karamelo/twitter-trends-scraper',
            'tiktok_topic_videos' => 'clockworks/tiktok-scraper',
            'tiktok_hashtag_trends' => 'lexis-solutions/tiktok-trending-hashtags',
            'tiktok_trending_videos' => 'data_xplorer/tiktok-trends',
            'tiktok_trending_creators' => 'lexis-solutions/tiktok-trending-creators',
            'tiktok_trending_sounds' => 'alien_force/tiktok-trending-sounds',
            'youtube_topic_videos' => 'streamers/youtube-scraper',
            'youtube_trending_categories' => 'eunit/youtube-trending-videos-by-categories',
            'reddit_topic_posts' => 'trudax/reddit-scraper-lite',
            'reddit_trends' => 'easyapi/reddit-trends-scraper',
            'ad_creative_scraper' => 'curious_coder/facebook-ads-library-scraper',
            'competitor_creative_scraper' => 'dz_omar/google-ads-scraper',
        ];

        // v0.9.24.23: the Ahrefs integration moved from the older
        // radeance/ahrefs-scraper schema to the All-in-One pro100chok actor.
        // If a site already saved the old default slug in settings, treat it as
        // a legacy default rather than forcing admins to manually update it.

        // v0.9.24.48: TikTok is split into discovery and enrichment actors.
        // Existing installs may have saved the old Clockworks slug as the default
        // TikTok actor. Treat that exact old default as a legacy fallback, not as
        // the primary discovery adapter, so plugin updates migrate cleanly.
        $tiktok_slug_setting = trim((string) ($settings['tiktok_slug'] ?? ''));
        if ($tiktok_slug_setting === '' || $tiktok_slug_setting === 'clockworks/tiktok-scraper') {
            $tiktok_slug_setting = $defaults['tiktok'];
        }

        $ahrefs_slug_setting = trim((string) ($settings['ahrefs_slug'] ?? ''));
        if ($ahrefs_slug_setting === '' || $ahrefs_slug_setting === 'radeance/ahrefs-scraper') {
            $ahrefs_slug_setting = $defaults['ahrefs'];
        }

        $map = [
            'tiktok'       => defined('VE_SOCIAL_TIKTOK_SLUG')       && VE_SOCIAL_TIKTOK_SLUG       ? VE_SOCIAL_TIKTOK_SLUG       : $tiktok_slug_setting,
            'tiktok_enrichment' => defined('VE_SOCIAL_TIKTOK_ENRICHMENT_SLUG') && VE_SOCIAL_TIKTOK_ENRICHMENT_SLUG ? VE_SOCIAL_TIKTOK_ENRICHMENT_SLUG : ($settings['tiktok_enrichment_slug'] ?? $defaults['tiktok_enrichment']),
            'tiktok_comments'   => defined('VE_SOCIAL_TIKTOK_COMMENTS_SLUG')   && VE_SOCIAL_TIKTOK_COMMENTS_SLUG   ? VE_SOCIAL_TIKTOK_COMMENTS_SLUG   : ($settings['tiktok_comments_slug'] ?? $defaults['tiktok_comments']),
            'tiktok_fallback'   => defined('VE_SOCIAL_TIKTOK_FALLBACK_SLUG')   && VE_SOCIAL_TIKTOK_FALLBACK_SLUG   ? VE_SOCIAL_TIKTOK_FALLBACK_SLUG   : ($settings['tiktok_fallback_slug'] ?? $defaults['tiktok_fallback']),
            'tiktok_backup'     => defined('VE_SOCIAL_TIKTOK_BACKUP_SLUG')     && VE_SOCIAL_TIKTOK_BACKUP_SLUG     ? VE_SOCIAL_TIKTOK_BACKUP_SLUG     : ($settings['tiktok_backup_slug'] ?? $defaults['tiktok_backup']),
            'youtube'      => defined('VE_SOCIAL_YOUTUBE_SLUG')      && VE_SOCIAL_YOUTUBE_SLUG      ? VE_SOCIAL_YOUTUBE_SLUG      : ($settings['youtube_slug'] ?? $defaults['youtube']),
            'facebook'     => defined('VE_SOCIAL_FACEBOOK_SLUG')     && VE_SOCIAL_FACEBOOK_SLUG     ? VE_SOCIAL_FACEBOOK_SLUG     : ($settings['facebook_slug'] ?? $defaults['facebook']),
            'instagram'    => defined('VE_SOCIAL_INSTAGRAM_SLUG')    && VE_SOCIAL_INSTAGRAM_SLUG    ? VE_SOCIAL_INSTAGRAM_SLUG    : ($settings['instagram_slug'] ?? $defaults['instagram']),
            'facebook_ads' => defined('VE_SOCIAL_FACEBOOK_ADS_SLUG') && VE_SOCIAL_FACEBOOK_ADS_SLUG ? VE_SOCIAL_FACEBOOK_ADS_SLUG : ($settings['facebook_ads_slug'] ?? $defaults['facebook_ads']),
            'google_ads'   => defined('VE_SOCIAL_GOOGLE_ADS_SLUG')   && VE_SOCIAL_GOOGLE_ADS_SLUG   ? VE_SOCIAL_GOOGLE_ADS_SLUG   : ($settings['google_ads_slug'] ?? $defaults['google_ads']),
            'twitter'          => defined('VE_SOCIAL_TWITTER_SLUG')          && VE_SOCIAL_TWITTER_SLUG          ? VE_SOCIAL_TWITTER_SLUG          : ($settings['twitter_slug'] ?? $defaults['twitter']),
            'linkedin'         => defined('VE_SOCIAL_LINKEDIN_SLUG')         && VE_SOCIAL_LINKEDIN_SLUG         ? VE_SOCIAL_LINKEDIN_SLUG         : ($settings['linkedin_slug'] ?? $defaults['linkedin']),
            'reddit'           => defined('VE_SOCIAL_REDDIT_SLUG')           && VE_SOCIAL_REDDIT_SLUG           ? VE_SOCIAL_REDDIT_SLUG           : ($settings['reddit_slug'] ?? $defaults['reddit']),
            'pinterest'        => defined('VE_SOCIAL_PINTEREST_SLUG')        && VE_SOCIAL_PINTEREST_SLUG        ? VE_SOCIAL_PINTEREST_SLUG        : ($settings['pinterest_slug'] ?? $defaults['pinterest']),
            'semrush'          => self::get_semrush_actor_slug($settings, $defaults, $context),
            'moz'              => defined('VE_SEO_MOZ_SLUG')                  && VE_SEO_MOZ_SLUG                  ? VE_SEO_MOZ_SLUG                  : ($settings['moz_slug'] ?? $defaults['moz']),
            'ahrefs'           => defined('VE_SEO_AHREFS_SLUG')               && VE_SEO_AHREFS_SLUG               ? VE_SEO_AHREFS_SLUG               : $ahrefs_slug_setting,
            'google_trends'    => defined('VE_SOCIAL_GOOGLE_TRENDS_SLUG')    && VE_SOCIAL_GOOGLE_TRENDS_SLUG    ? VE_SOCIAL_GOOGLE_TRENDS_SLUG    : ($settings['google_trends_slug'] ?? $defaults['google_trends']),
            'youtube_trending' => defined('VE_SOCIAL_YOUTUBE_TRENDING_SLUG') && VE_SOCIAL_YOUTUBE_TRENDING_SLUG ? VE_SOCIAL_YOUTUBE_TRENDING_SLUG : ($settings['youtube_trending_slug'] ?? $defaults['youtube_trending']),
            'tiktok_trends'    => defined('VE_SOCIAL_TIKTOK_TRENDS_SLUG')    && VE_SOCIAL_TIKTOK_TRENDS_SLUG    ? VE_SOCIAL_TIKTOK_TRENDS_SLUG    : ($settings['tiktok_trends_slug'] ?? $defaults['tiktok_trends']),
            'twitter_trends'   => defined('VE_SOCIAL_TWITTER_TRENDS_SLUG')   && VE_SOCIAL_TWITTER_TRENDS_SLUG   ? VE_SOCIAL_TWITTER_TRENDS_SLUG   : ($settings['twitter_trends_slug'] ?? $defaults['twitter_trends']),
            'google_trends_interest' => $settings['google_trends_interest_slug'] ?? $defaults['google_trends_interest'],
            'google_trends_trending_now' => $settings['google_trends_trending_now_slug'] ?? $defaults['google_trends_trending_now'],
            'google_search_freshness' => $settings['google_search_freshness_slug'] ?? $defaults['google_search_freshness'],
            'google_news_freshness' => $settings['google_news_freshness_slug'] ?? $defaults['google_news_freshness'],
            'semrush_keyword_context' => $settings['semrush_keyword_context_slug'] ?? $defaults['semrush_keyword_context'],
            'x_topic_posts' => $settings['x_topic_posts_slug'] ?? $defaults['x_topic_posts'],
            'x_trend_list' => $settings['x_trend_list_slug'] ?? $defaults['x_trend_list'],
            'tiktok_topic_videos' => $settings['tiktok_topic_videos_slug'] ?? $defaults['tiktok_topic_videos'],
            'tiktok_hashtag_trends' => $settings['tiktok_hashtag_trends_slug'] ?? $defaults['tiktok_hashtag_trends'],
            'tiktok_trending_videos' => $settings['tiktok_trending_videos_slug'] ?? $defaults['tiktok_trending_videos'],
            'tiktok_trending_creators' => $settings['tiktok_trending_creators_slug'] ?? $defaults['tiktok_trending_creators'],
            'tiktok_trending_sounds' => $settings['tiktok_trending_sounds_slug'] ?? $defaults['tiktok_trending_sounds'],
            'youtube_topic_videos' => $settings['youtube_topic_videos_slug'] ?? $defaults['youtube_topic_videos'],
            'youtube_trending_categories' => $settings['youtube_trending_categories_slug'] ?? $defaults['youtube_trending_categories'],
            'reddit_topic_posts' => $settings['reddit_topic_posts_slug'] ?? $defaults['reddit_topic_posts'],
            'reddit_trends' => $settings['reddit_trends_slug'] ?? $defaults['reddit_trends'],
            'ad_creative_scraper' => $settings['ad_creative_scraper_slug'] ?? $defaults['ad_creative_scraper'],
            'competitor_creative_scraper' => $settings['competitor_creative_scraper_slug'] ?? $defaults['competitor_creative_scraper'],
        ];

        $slug = trim((string) ($map[$platform] ?? ''));
        return $slug !== '' ? $slug : ($defaults[$platform] ?? '');
    }


    public static function get_trend_source_slots() {
        return [
            'google_trends_interest', 'google_trends_trending_now', 'google_search_freshness', 'google_news_freshness', 'semrush_keyword_context',
            'x_topic_posts', 'x_trend_list',
            'tiktok_topic_videos', 'tiktok_hashtag_trends', 'tiktok_trending_videos', 'tiktok_trending_creators', 'tiktok_trending_sounds',
            'youtube_topic_videos', 'youtube_trending_categories',
            'reddit_topic_posts', 'reddit_trends',
            'ad_creative_scraper', 'competitor_creative_scraper',
        ];
    }

    public static function is_source_slot_enabled($source_key) {
        $source_key = sanitize_key((string) $source_key);
        $settings = self::get_settings();
        $key = $source_key . '_enabled';
        if (array_key_exists($key, $settings)) {
            return !empty($settings[$key]);
        }
        return true;
    }

    public static function get_source_slot_meta($source_key) {
        $source_key = sanitize_key((string) $source_key);
        $settings = self::get_settings();
        return [
            'source_key' => $source_key,
            'actor_slug' => self::get_actor_slug($source_key),
            'enabled' => self::is_source_slot_enabled($source_key),
            'estimated_cost_level' => sanitize_key((string) ($settings[$source_key . '_cost_level'] ?? 'medium')),
            'reliability_note' => sanitize_text_field((string) ($settings[$source_key . '_reliability_note'] ?? '')),
            'last_status' => sanitize_text_field((string) ($settings[$source_key . '_last_status'] ?? 'not_run')),
        ];
    }


    private static function get_semrush_actor_slug($settings, $defaults, $context = []) {
        $mode = '';
        if (is_array($context)) {
            $mode = sanitize_key((string) ($context['mode'] ?? $context['semrushTool'] ?? ''));
        }
        // The SEO UI defaults to Keyword Magic and ves_build_platform_input() also
        // defaults Semrush to keyword_magic. Keep actor routing aligned when a
        // cached/preview request omits mode. The legacy agencies actor remains
        // available only through an explicit agencies/agency mode or setting usage.
        if ($mode === '') {
            $mode = 'keyword_magic';
        }
        if (in_array($mode, ['agency', 'agencies', 'partner_agencies'], true)) {
            if (defined('VE_SEO_SEMRUSH_SLUG') && VE_SEO_SEMRUSH_SLUG) {
                return VE_SEO_SEMRUSH_SLUG;
            }
            return trim((string) ($settings['semrush_slug'] ?? $defaults['semrush']));
        }
        if ($mode === 'keyword_simple') {
            if (defined('VE_SEO_SEMRUSH_KEYWORDS_SLUG') && VE_SEO_SEMRUSH_KEYWORDS_SLUG) {
                return VE_SEO_SEMRUSH_KEYWORDS_SLUG;
            }
            return trim((string) ($settings['semrush_keywords_slug'] ?? $defaults['semrush_keywords']));
        }
        if ($mode === 'keyword_magic') {
            if (defined('VE_SEO_SEMRUSH_KEYWORD_MAGIC_SLUG') && VE_SEO_SEMRUSH_KEYWORD_MAGIC_SLUG) {
                return VE_SEO_SEMRUSH_KEYWORD_MAGIC_SLUG;
            }
            return trim((string) ($settings['semrush_keyword_magic_slug'] ?? $defaults['semrush_keyword_magic']));
        }
        if ($mode === 'domain_seo' || $mode === 'top_websites') {
            if (defined('VE_SEO_SEMRUSH_DOMAIN_SLUG') && VE_SEO_SEMRUSH_DOMAIN_SLUG) {
                return VE_SEO_SEMRUSH_DOMAIN_SLUG;
            }
            return trim((string) ($settings['semrush_domain_slug'] ?? $defaults['semrush_domain']));
        }
        if (defined('VE_SEO_SEMRUSH_SLUG') && VE_SEO_SEMRUSH_SLUG) {
            return VE_SEO_SEMRUSH_SLUG;
        }
        return trim((string) ($settings['semrush_slug'] ?? $defaults['semrush']));
    }

    public static function get_openai_api_key() {
        if (defined('VE_SOCIAL_OPENAI_API_KEY') && VE_SOCIAL_OPENAI_API_KEY) {
            return trim((string) VE_SOCIAL_OPENAI_API_KEY);
        }
        return trim((string) (self::get_settings()['openai_api_key'] ?? ''));
    }

    public static function get_openai_model($purpose = 'fast_analysis') {
        return self::get_openai_model_for($purpose);
    }

    public static function get_openai_model_for($purpose = 'fast_analysis') {
        $purpose = sanitize_key((string) $purpose);
        $settings = self::get_settings();
        $constant_map = [
            'fast_analysis' => 'VE_SOCIAL_FAST_ANALYSIS_MODEL',
            'deep_analysis' => 'VE_SOCIAL_DEEP_ANALYSIS_MODEL',
            'brief_generation' => 'VE_SOCIAL_BRIEF_GENERATION_MODEL',
            'assistant' => 'VE_SOCIAL_ASSISTANT_MODEL',
        ];
        if (isset($constant_map[$purpose]) && defined($constant_map[$purpose]) && constant($constant_map[$purpose])) {
            return trim((string) constant($constant_map[$purpose]));
        }
        if (defined('VE_SOCIAL_OPENAI_MODEL') && VE_SOCIAL_OPENAI_MODEL && in_array($purpose, ['legacy', 'general'], true)) {
            return trim((string) VE_SOCIAL_OPENAI_MODEL);
        }
        $defaults = [
            'fast_analysis' => 'gpt-4.1-mini',
            'deep_analysis' => 'gpt-5-mini',
            'brief_generation' => 'gpt-4.1-mini',
            'assistant' => 'gpt-4.1-mini',
            'general' => 'gpt-4.1-mini',
            'legacy' => 'gpt-4.1-mini',
        ];
        $key_map = [
            'fast_analysis' => 'fast_analysis_model',
            'deep_analysis' => 'deep_analysis_model',
            'brief_generation' => 'brief_generation_model',
            'assistant' => 'assistant_model',
            'general' => 'openai_model',
            'legacy' => 'openai_model',
        ];
        if (!isset($key_map[$purpose])) { $purpose = 'fast_analysis'; }
        $model = trim((string) ($settings[$key_map[$purpose]] ?? ''));
        if ($model === '' && $purpose !== 'general') { $model = trim((string) ($settings['openai_model'] ?? '')); }
        return $model !== '' ? $model : ($defaults[$purpose] ?? 'gpt-4.1-mini');
    }

    public static function get_fast_analysis_model() { return self::get_openai_model_for('fast_analysis'); }
    public static function get_deep_analysis_model() { return self::get_openai_model_for('deep_analysis'); }
    public static function get_brief_generation_model() { return self::get_openai_model_for('brief_generation'); }
    public static function get_assistant_model() { return self::get_openai_model_for('assistant'); }

    public static function get_openai_timeout_seconds($purpose = 'fast_analysis') {
        if (defined('VE_SOCIAL_OPENAI_TIMEOUT')) { return max(10, min(90, (int) VE_SOCIAL_OPENAI_TIMEOUT)); }
        $purpose = sanitize_key((string) $purpose);
        $settings = self::get_settings();
        $key = $purpose === 'deep_analysis' ? 'openai_deep_timeout_seconds' : 'openai_fast_timeout_seconds';
        $default = $purpose === 'deep_analysis' ? 55 : 30;
        return max(10, min(90, (int) ($settings[$key] ?? $default)));
    }

    public static function get_openai_max_evidence_items() {
        if (defined('VE_SOCIAL_OPENAI_MAX_EVIDENCE_ITEMS')) { return max(4, min(80, (int) VE_SOCIAL_OPENAI_MAX_EVIDENCE_ITEMS)); }
        return max(4, min(80, (int) (self::get_settings()['openai_max_evidence_items'] ?? 24)));
    }

    public static function get_openai_max_evidence_refs() {
        if (defined('VE_SOCIAL_OPENAI_MAX_EVIDENCE_REFS')) { return max(4, min(80, (int) VE_SOCIAL_OPENAI_MAX_EVIDENCE_REFS)); }
        return max(4, min(80, (int) (self::get_settings()['openai_max_evidence_refs'] ?? 40)));
    }

    public static function apify_max_active_runs() {
        if (defined('VE_SOCIAL_APIFY_MAX_ACTIVE_RUNS')) { return max(1, min(50, (int) VE_SOCIAL_APIFY_MAX_ACTIVE_RUNS)); }
        return max(1, min(50, (int) (self::get_settings()['apify_max_active_runs'] ?? 6)));
    }

    public static function apify_max_active_runs_per_user() {
        if (defined('VE_SOCIAL_APIFY_MAX_ACTIVE_RUNS_PER_USER')) { return max(1, min(20, (int) VE_SOCIAL_APIFY_MAX_ACTIVE_RUNS_PER_USER)); }
        return max(1, min(20, (int) (self::get_settings()['apify_max_active_runs_per_user'] ?? 3)));
    }

    public static function apify_max_user_active_runs() { return self::apify_max_active_runs_per_user(); }

    public static function apify_max_heavy_runs() {
        if (defined('VE_SOCIAL_APIFY_MAX_HEAVY_RUNS')) { return max(1, min(20, (int) VE_SOCIAL_APIFY_MAX_HEAVY_RUNS)); }
        return max(1, min(20, (int) (self::get_settings()['apify_max_heavy_runs'] ?? 2)));
    }

    public static function apify_max_heavy_active_runs() { return self::apify_max_heavy_runs(); }

    public static function apify_max_sources_per_run() {
        if (defined('VE_SOCIAL_APIFY_MAX_SOURCES_PER_RUN')) { return max(1, min(100, (int) VE_SOCIAL_APIFY_MAX_SOURCES_PER_RUN)); }
        return max(1, min(100, (int) (self::get_settings()['apify_max_sources_per_run'] ?? 12)));
    }

    public static function apify_max_estimated_cost_per_run() {
        if (defined('VE_SOCIAL_APIFY_MAX_ESTIMATED_COST_PER_RUN')) { return max(0, (float) VE_SOCIAL_APIFY_MAX_ESTIMATED_COST_PER_RUN); }
        return max(0, (float) (self::get_settings()['apify_max_estimated_cost_per_run'] ?? 2.50));
    }

    public static function apify_cost_warning_threshold_usd() {
        if (defined('VE_SOCIAL_APIFY_COST_WARNING_THRESHOLD_USD')) { return max(0, (float) VE_SOCIAL_APIFY_COST_WARNING_THRESHOLD_USD); }
        return max(0, (float) (self::get_settings()['apify_cost_warning_threshold_usd'] ?? 1.00));
    }

    public static function trend_youtube_trending_enabled() {
        if (defined('VE_SOCIAL_ENABLE_YOUTUBE_TRENDING_IN_TREND_FINDER')) { return (bool) VE_SOCIAL_ENABLE_YOUTUBE_TRENDING_IN_TREND_FINDER; }
        return !empty(self::get_settings()['enable_youtube_trending_in_trend_finder']);
    }

    public static function trend_twitter_trends_enabled() {
        if (defined('VE_SOCIAL_ENABLE_TWITTER_TRENDS_IN_TREND_FINDER')) { return (bool) VE_SOCIAL_ENABLE_TWITTER_TRENDS_IN_TREND_FINDER; }
        return !empty(self::get_settings()['enable_twitter_trends_in_trend_finder']);
    }

    public static function trend_max_twitter_trend_items() {
        if (defined('VE_SOCIAL_MAX_TWITTER_TREND_ITEMS')) { return max(5, min(200, (int) VE_SOCIAL_MAX_TWITTER_TREND_ITEMS)); }
        return max(5, min(200, (int) (self::get_settings()['max_twitter_trend_items'] ?? 40)));
    }

    public static function trend_max_youtube_trending_cost() {
        if (defined('VE_SOCIAL_MAX_YOUTUBE_TRENDING_COST')) { return max(0, (float) VE_SOCIAL_MAX_YOUTUBE_TRENDING_COST); }
        return max(0, (float) (self::get_settings()['max_youtube_trending_cost'] ?? 0.25));
    }

    public static function max_external_provider_cost_per_run() {
        if (defined('VE_SOCIAL_MAX_EXTERNAL_PROVIDER_COST_PER_RUN')) { return max(0, (float) VE_SOCIAL_MAX_EXTERNAL_PROVIDER_COST_PER_RUN); }
        return max(0, (float) (self::get_settings()['max_external_provider_cost_per_run'] ?? self::apify_max_estimated_cost_per_run()));
    }

    public static function apify_estimate_provider_cost($sources_count = 1, $heavy_count = 0) {
        $sources_count = max(0, (int) $sources_count);
        $heavy_count = max(0, (int) $heavy_count);
        $estimated = ($sources_count * 0.03) + ($heavy_count * 0.12);
        return round(max(0, $estimated), 4);
    }

    public static function estimated_cost_usd_per_run($provider = 'apify') {
        $provider = sanitize_key((string) $provider);
        if ($provider === 'apify') { return 0.15; }
        if ($provider === 'serpapi' || $provider === 'google_search') { return 0.03; }
        if ($provider === 'openai') { return 0.02; }
        return 0.03;
    }

    public static function estimate_trend_source_provider_cost($source_key, $source = []) {
        $source_key = sanitize_key((string) $source_key);
        $slug = strtolower((string) (($source['actor_slug'] ?? '') ?: ($source['slug'] ?? '')));
        $weights = [
            'google_trends' => 0.01,
            'tiktok_trends' => 0.18,
            'youtube_trending' => 1.00,
            'twitter_trends' => 0.23,
        ];
        $estimate = $weights[$source_key] ?? 0.05;
        if ($source_key === 'youtube_trending') {
            $estimate = 1.00; // broad country/category trending can be materially more expensive than topic search.
        }
        if ($source_key === 'twitter_trends') {
            $items = self::trend_max_twitter_trend_items();
            $estimate = min($estimate, 0.05 + ($items * 0.002));
        }
        if (strpos($slug, 'youtube') !== false && $source_key !== 'youtube_trending') { $estimate += 0.15; }
        if (strpos($slug, 'tiktok') !== false && $source_key !== 'tiktok_trends') { $estimate += 0.12; }
        return round(max(0, $estimate), 4);
    }

    public static function estimate_trend_provider_cost($payloads) {
        $payloads = is_array($payloads) ? $payloads : [];
        $sources = 0; $heavy = 0; $estimated = 0.0; $source_costs = []; $source_limit_exceeded = false;
        foreach ($payloads as $source_key => $source) {
            $source = is_array($source) ? $source : [];
            if (!empty($source['adapter_validation_status']) && (string) $source['adapter_validation_status'] === 'SKIPPED_INVALID_INPUT') { continue; }
            $sources++;
            $slug = strtolower((string) ($source['actor_slug'] ?? ''));
            $is_heavy = (strpos($slug, 'youtube') !== false || strpos($slug, 'tiktok') !== false || strpos($slug, 'instagram') !== false || strpos($slug, 'facebook') !== false || strpos($slug, 'twitter') !== false || strpos($slug, 'linkedin') !== false);
            if ($is_heavy) { $heavy++; }
            $cost = self::estimate_trend_source_provider_cost((string) $source_key, $source);
            $source_limit = 0.0;
            if (sanitize_key((string) $source_key) === 'youtube_trending') {
                $source_limit = self::trend_max_youtube_trending_cost();
            }
            $this_source_exceeded = $source_limit > 0 && $cost > $source_limit;
            if ($this_source_exceeded) { $source_limit_exceeded = true; }
            $source_costs[$source_key] = [
                'estimated_usd' => $cost,
                'source_limit_usd' => $source_limit,
                'limit_exceeded' => $this_source_exceeded,
                'actor_slug' => sanitize_text_field((string) ($source['actor_slug'] ?? '')),
                'enabled' => true,
            ];
            $estimated += $cost;
        }
        $warning_threshold = self::apify_cost_warning_threshold_usd();
        $limit = self::max_external_provider_cost_per_run();
        $max_sources = self::apify_max_sources_per_run();
        return [
            'estimated_usd' => round(max(0, $estimated), 4),
            'source_count' => $sources,
            'heavy_source_count' => $heavy,
            'source_costs' => $source_costs,
            'warning_threshold_usd' => $warning_threshold,
            'max_estimated_cost_per_run_usd' => $limit,
            'max_sources_per_run' => $max_sources,
            'youtube_trending_enabled' => self::trend_youtube_trending_enabled(),
            'twitter_trends_enabled' => self::trend_twitter_trends_enabled(),
            'max_twitter_trend_items' => self::trend_max_twitter_trend_items(),
            'max_youtube_trending_cost' => self::trend_max_youtube_trending_cost(),
            'warning' => ($warning_threshold > 0 && $estimated >= $warning_threshold) || ($max_sources > 0 && $sources > $max_sources) || $source_limit_exceeded,
            'limit_exceeded' => ($limit > 0 && $estimated > $limit) || ($max_sources > 0 && $sources > $max_sources) || $source_limit_exceeded,
            'note' => 'External Apify provider estimate; separate from internal credits.',
        ];
    }

    public static function validate_trend_provider_cost_guard($payloads) {
        $estimate = self::estimate_trend_provider_cost($payloads);
        if (!empty($estimate['limit_exceeded'])) {
            $estimate['admin_note'] = 'External Apify provider-cost guard evaluated the run (estimated $' . number_format((float) ($estimate['estimated_usd'] ?? 0), 4) . ' vs limit $' . number_format((float) ($estimate['max_estimated_cost_per_run_usd'] ?? 0), 2) . ').';

            // v0.9.24.77: admins may intentionally run a deeper Trend Finder
            // scan after seeing the estimate. This prevents the product from
            // behaving like a broken scraper when the real issue is simply a
            // higher-priced source mix. Non-admin users still get a clean block.
            $admin_override = function_exists('current_user_can') && current_user_can('manage_options');
            $admin_override = (bool) apply_filters('ves_provider_cost_guard_allow_admin_override', $admin_override, $estimate, $payloads);
            if ($admin_override) {
                $estimate['admin_override_applied'] = true;
                $estimate['warning'] = true;
                $estimate['note'] = 'Provider estimate exceeds the configured threshold, but admin override allowed this deeper scan.';
                return $estimate;
            }

            $user_message = __('This run requires a higher provider budget. Reduce sources/results or ask your admin to approve a deeper scan.', 'vietnam-estudio-social-scraper');
            $user_message = (string) apply_filters('ves_provider_cost_guard_user_message', $user_message, $estimate);
            return new WP_Error('provider_cost_guard_exceeded', $user_message, [
                'status' => 402,
                'provider_cost' => $estimate,
            ]);
        }
        return $estimate;
    }

    public static function brand_audit_batch_size() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_BATCH_SIZE')) { return max(1, min(20, (int) VE_SOCIAL_BRAND_AUDIT_BATCH_SIZE)); }
        return max(1, min(20, (int) (self::get_settings()['brand_audit_batch_size'] ?? 6)));
    }

    public static function brand_audit_max_competitors_standard() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_MAX_COMPETITORS_STANDARD')) { return max(0, min(20, (int) VE_SOCIAL_BRAND_AUDIT_MAX_COMPETITORS_STANDARD)); }
        return max(0, min(20, (int) (self::get_settings()['brand_audit_max_competitors_standard'] ?? 3)));
    }

    public static function brand_audit_max_competitors_deep() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_MAX_COMPETITORS_DEEP')) { return max(0, min(30, (int) VE_SOCIAL_BRAND_AUDIT_MAX_COMPETITORS_DEEP)); }
        return max(0, min(30, (int) (self::get_settings()['brand_audit_max_competitors_deep'] ?? 5)));
    }

    public static function brand_audit_max_heavy_sources_per_phase() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_MAX_HEAVY_SOURCES_PER_PHASE')) { return max(1, min(10, (int) VE_SOCIAL_BRAND_AUDIT_MAX_HEAVY_SOURCES_PER_PHASE)); }
        return max(1, min(10, (int) (self::get_settings()['brand_audit_max_heavy_sources_per_phase'] ?? 2)));
    }

    public static function brand_audit_enable_competitor_social_expansion() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_ENABLE_COMPETITOR_SOCIAL_EXPANSION')) { return (bool) VE_SOCIAL_BRAND_AUDIT_ENABLE_COMPETITOR_SOCIAL_EXPANSION; }
        return !empty(self::get_settings()['brand_audit_enable_competitor_social_expansion']);
    }

    public static function brand_audit_auto_expand_after_phase_1() {
        if (defined('VE_SOCIAL_BRAND_AUDIT_AUTO_EXPAND_AFTER_PHASE_1')) { return (bool) VE_SOCIAL_BRAND_AUDIT_AUTO_EXPAND_AFTER_PHASE_1; }
        return !empty(self::get_settings()['brand_audit_auto_expand_after_phase_1']);
    }

    public static function brand_audit_max_competitors_for_depth($depth) {
        $depth = sanitize_key((string) $depth);
        if ($depth === 'deep') { return self::brand_audit_max_competitors_deep(); }
        return self::brand_audit_max_competitors_standard();
    }

    public static function get_openai_reasoning_effort() {
        if (defined('VE_SOCIAL_OPENAI_REASONING_EFFORT') && VE_SOCIAL_OPENAI_REASONING_EFFORT) {
            return sanitize_key((string) VE_SOCIAL_OPENAI_REASONING_EFFORT);
        }
        $settings = self::get_settings();
        $effort = sanitize_key((string) ($settings['openai_reasoning_effort'] ?? 'auto'));
        return $effort !== '' ? $effort : 'auto';
    }

    public static function require_login() {
        if (defined('VE_SOCIAL_REQUIRE_LOGIN')) {
            return (bool) VE_SOCIAL_REQUIRE_LOGIN;
        }
        return !empty(self::get_settings()['require_login']);
    }

    public static function rate_limit_seconds() {
        if (defined('VE_SOCIAL_RATE_LIMIT_SECONDS')) {
            return max(5, (int) VE_SOCIAL_RATE_LIMIT_SECONDS);
        }
        return max(5, (int) (self::get_settings()['rate_limit_seconds'] ?? 12));
    }

    public static function max_items() {
        if (defined('VE_SOCIAL_MAX_ITEMS')) {
            return max(1, min(5000, (int) VE_SOCIAL_MAX_ITEMS));
        }
        return max(1, min(5000, (int) (self::get_settings()['max_items'] ?? 500)));
    }

    public static function hard_max_charge_usd() {
        if (defined('VE_SOCIAL_MAX_CHARGE_USD')) {
            return max(0.1, (float) VE_SOCIAL_MAX_CHARGE_USD);
        }
        return max(0.1, (float) (self::get_settings()['max_charge_usd'] ?? 3.0));
    }


    public static function default_signup_credits() {
        // Default 0 → the user's PLAN signup_credits is used (free = 25 ≈ 10 social
        // requests). Set a positive value in settings to force a flat grant instead.
        return max(0, (float) (self::get_settings()['default_signup_credits'] ?? 0));
    }

    public static function default_plan_slug() {
        // Non-subscribers default to the restricted FREE tier (Social + TikTok/
        // YouTube/Instagram, 10 requests). Admins bypass all plan restrictions.
        $slug = sanitize_key((string) (self::get_settings()['default_plan_slug'] ?? 'free'));
        return $slug !== '' ? $slug : 'free';
    }

    public static function registration_role() {
        $role = sanitize_key((string) (self::get_settings()['registration_role'] ?? 'ves_customer'));
        return $role !== '' ? $role : 'ves_customer';
    }

    public static function panel_allowed_roles() {
        $roles = self::get_settings()['panel_allowed_roles'] ?? ['administrator', 'ves_customer'];
        return is_array($roles) ? array_values(array_map('sanitize_key', $roles)) : ['administrator', 'ves_customer'];
    }

    public static function cost_manual_run() {
        return max(0, (float) (self::get_settings()['cost_manual_run'] ?? 1));
    }

    public static function cost_ai_analysis() {
        return max(0, (float) (self::get_settings()['cost_ai_analysis'] ?? 1));
    }

    public static function cost_trend_run() {
        return max(0, (float) (self::get_settings()['cost_trend_run'] ?? 4));
    }

    public static function cost_trend_brief() {
        return max(0, (float) (self::get_settings()['cost_trend_brief'] ?? 1));
    }

    public static function cost_background_monitor() {
        return max(0, (float) (self::get_settings()['cost_background_monitor'] ?? 4));
    }

    public static function cost_brand_audit_light() {
        return max(0, (float) (self::get_settings()['cost_brand_audit_light'] ?? 8));
    }

    public static function cost_brand_audit_standard() {
        return max(0, (float) (self::get_settings()['cost_brand_audit_standard'] ?? 18));
    }

    public static function cost_brand_audit_deep() {
        return max(0, (float) (self::get_settings()['cost_brand_audit_deep'] ?? 35));
    }


    public static function cost_creative_asset_analysis() {
        return max(0, (float) (self::get_settings()['cost_creative_asset_analysis'] ?? 8));
    }

    public static function admin_error_details() {
        if (defined('VE_SOCIAL_ADMIN_ERROR_DETAILS')) {
            return (bool) VE_SOCIAL_ADMIN_ERROR_DETAILS;
        }
        return !empty(self::get_settings()['admin_error_details']);
    }
}
