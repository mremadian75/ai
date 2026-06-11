<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Generic Apify live adapter for non-TikTok Deep Trend Finder sources.
 *
 * This intentionally reuses the mother/Core Apify client when available so
 * tokens, cost guards and concurrency controls stay centralized. Direct mode is
 * only a QA fallback using the existing Apify token setting/constant.
 */
final class FIDTF_Generic_Apify_Live_Adapter {
    const API_BASE = 'https://api.apify.com/v2';

    private $source_key = '';
    private $last_provider_dataset_rows = 0;
    private $last_provider_dataset_total_rows = 0;
    private $last_flattened_raw_items = 0;

    public function __construct(string $source_key) {
        $this->source_key = sanitize_key($source_key);
    }

    public function start(array $canonical_payload) {
        $source_key = sanitize_key((string) ($canonical_payload['source_key'] ?? $this->source_key));
        if ($source_key === '' || $source_key === 'tiktok') {
            return new WP_Error('invalid_generic_apify_payload', 'Generic Apify adapter received an invalid source payload.', ['status' => 400, 'retryable' => false]);
        }
        $this->source_key = $source_key;
        $actor_id = FIDTF_Settings::actor_for($source_key);
        if ($actor_id === '') {
            return new WP_Error('source_actor_missing', ucfirst(str_replace('_', ' ', $source_key)) . ' actor is not configured.', ['status' => 503, 'retryable' => false, 'request_attempted' => false]);
        }
        if (!FIDTF_Settings::source_live_ready($source_key)) {
            return new WP_Error('source_live_bridge_disabled', ucfirst(str_replace('_', ' ', $source_key)) . ' live bridge is disabled or provider is not ready.', ['status' => 202, 'retryable' => false, 'request_attempted' => false]);
        }

        $actor_input = $this->build_actor_input($source_key, $canonical_payload);
        $profile = $this->actor_profile($source_key, $actor_id);
        $validation = $this->validate_actor_input($source_key, $profile, $actor_input);
        if (empty($validation['ok'])) {
            return new WP_Error('source_actor_contract_invalid', ucfirst(str_replace('_', ' ', $source_key)) . ' actor input contract failed local validation.', ['status' => 400, 'retryable' => false, 'contract_errors' => $validation['errors'], 'actor_input_profile' => $profile, 'request_attempted' => false]);
        }
        $context = $this->context($source_key, $actor_id, $canonical_payload, $actor_input);

        if (FIDTF_Core_Apify_Client_Adapter::available()) {
            return $this->start_with_core_client($context, $actor_input);
        }
        return $this->start_direct_apify_run($context, $actor_input);
    }

    public function refresh(array $job, array $canonical_payload) {
        $source_key = sanitize_key((string) ($job['source_key'] ?? $canonical_payload['source_key'] ?? $this->source_key));
        $this->source_key = $source_key;
        $provider_run_id = sanitize_text_field((string) ($job['provider_run_id'] ?? ''));
        if ($provider_run_id === '') {
            return new WP_Error('missing_provider_run_id', ucfirst(str_replace('_', ' ', $source_key)) . ' job has no provider run id to refresh.', ['status' => 400, 'retryable' => false]);
        }
        $actor_id = FIDTF_Settings::actor_for($source_key);
        $actor_input = $this->build_actor_input($source_key, $canonical_payload);
        $profile = $this->actor_profile($source_key, $actor_id);
        $validation = $this->validate_actor_input($source_key, $profile, $actor_input);
        if (empty($validation['ok'])) {
            return new WP_Error('source_actor_contract_invalid', ucfirst(str_replace('_', ' ', $source_key)) . ' actor input contract failed local validation.', ['status' => 400, 'retryable' => false, 'contract_errors' => $validation['errors'], 'actor_input_profile' => $profile, 'request_attempted' => false]);
        }
        $context = $this->context($source_key, $actor_id, $canonical_payload, $actor_input);
        $context['provider_run_id'] = $provider_run_id;

        if (FIDTF_Core_Apify_Client_Adapter::available()) {
            return $this->refresh_with_core_client($context, $this->limit_from_actor_input($actor_input));
        }
        return $this->refresh_direct_apify_run($context, $provider_run_id);
    }

    private function context(string $source_key, string $actor_id, array $canonical_payload, array $actor_input): array {
        return [
            'source_key' => $source_key,
            'provider' => 'generic_apify_live_bridge',
            'provider_mode' => FIDTF_Core_Apify_Client_Adapter::available() ? 'core_apify_client' : 'apify_bridge',
            'actor_id' => $actor_id,
            'discovery_actor_id' => $actor_id,
            'actor_input' => $actor_input,
            'actor_input_profile' => $this->actor_profile($source_key, $actor_id),
            'discovery_actor_input' => $actor_input,
            'payload' => $canonical_payload,
            'has_token' => FIDTF_Settings::tiktok_apify_token() !== '',
        ];
    }

    private function build_actor_input(string $source_key, array $canonical_payload): array {
        $source_plan = (array) ($canonical_payload['source_plan'] ?? []);
        $context = (array) ($canonical_payload['request_context'] ?? []);
        $limits = (array) ($canonical_payload['limits'] ?? []);
        $limit = max(1, min(300, (int) ($limits['max_items'] ?? $source_plan['max_items'] ?? FIDTF_Settings::source_limit($source_key))));
        $keywords = $this->clean_list(array_merge((array) ($context['keywords'] ?? []), (array) ($source_plan['queries'] ?? []), (array) ($source_plan['seed_keywords'] ?? []), (array) ($source_plan['related_query_hints'] ?? [])), 30);
        if (empty($keywords)) {
            $brief = sanitize_text_field((string) ($context['user_brief'] ?? ''));
            if ($brief !== '') { $keywords[] = $this->short_query($brief); }
        }
        $hashtags = $this->clean_hashtags((array) ($source_plan['hashtags'] ?? []), 20);
        $market = sanitize_text_field((string) ($context['market'] ?? ($source_plan['geo'] ?? '')));
        $language = sanitize_key((string) ($context['language'] ?? ($source_plan['language'] ?? '')));
        $date_range = sanitize_key((string) ($context['date_range'] ?? ($source_plan['time_range'] ?? 'last_30_days')));
        $primary_query = $this->primary_query($keywords, $hashtags, (string) ($context['brand'] ?? ''));
        $actor_id = FIDTF_Settings::actor_for($source_key);
        $profile = $this->actor_profile($source_key, $actor_id);

        switch ($profile) {
            case 'instagram_post_scraper':
                $profiles = $this->clean_list((array) ($source_plan['profile_or_topic_hints'] ?? []), 10);
                $username = '';
                if (!empty($profiles)) { $username = ltrim((string) $profiles[0], '@'); }
                if ($username === '' && !empty($context['brand'])) { $username = ltrim(sanitize_key((string) $context['brand']), '@'); }
                if ($username !== '') {
                    $input = [
                        'username' => [$username],
                        'resultsLimit' => $limit,
                        'dataDetailLevel' => 'basicData',
                        'onlyPostsNewerThan' => $this->relative_since($date_range),
                        'skipPinnedPosts' => true,
                    ];
                } else {
                    $input = [
                        'startUrls' => array_map(static function($u) { return ['url' => $u]; }, $this->instagram_urls_from_query($primary_query, $hashtags)),
                        'resultsLimit' => $limit,
                        'dataDetailLevel' => 'basicData',
                    ];
                }
                break;
            case 'instagram_apify_official':
            case 'instagram_generic':
                // v0.3.31 live QA: apify/instagram-scraper search mode returns
                // hashtag/profile/place discovery rows, not post rows, even when
                // resultsType=posts is sent. For trend finding we need actual post
                // evidence, so hashtag-like queries are converted to direct hashtag
                // URLs. Direct Instagram URLs are preserved as-is.
                if (preg_match('~^https?://(www\.)?instagram\.com/~i', $primary_query)) {
                    $direct_urls = [$primary_query];
                } else {
                    $tag_source = !empty($hashtags) ? (string) $hashtags[0] : $primary_query;
                    $tag = $this->instagram_hashtag_slug($tag_source);
                    $direct_urls = $tag !== '' ? ['https://www.instagram.com/explore/tags/' . $tag . '/'] : [];
                }
                $input = [
                    'directUrls' => $direct_urls,
                    'resultsType' => 'posts',
                    'resultsLimit' => $limit,
                    'addParentData' => false,
                ];
                if (empty($direct_urls)) {
                    $input = [
                        'search' => ltrim($primary_query, '#@'),
                        'searchType' => 'hashtag',
                        'searchLimit' => min($limit, 50),
                        'resultsType' => 'posts',
                        'resultsLimit' => $limit,
                        'addParentData' => false,
                    ];
                }
                break;
            case 'reddit_trudax_lite':
            case 'reddit_generic':
                // trudax/reddit-scraper-lite: current input uses searches, searchPosts,
                // maxPostCount, maxComments and optional searchCommunityName. Avoid legacy
                // queries/searchTerms keys that the actor does not document.
                $input = [
                    'searches' => $keywords ?: [$primary_query],
                    'searchPosts' => true,
                    'searchComments' => false,
                    'searchCommunities' => false,
                    'searchUsers' => false,
                    'skipComments' => true,
                    'includeNSFW' => false,
                    'sort' => 'relevance',
                    'time' => $this->reddit_time($date_range),
                    // Do not send the ambiguous `type` field for post search.
                    // The Trudax actor documents searches + searchPosts for post
                    // discovery; `type` is shown in docs for community search and
                    // can become a schema-risk if the actor tightens enums.
                    'maxItems' => min($limit, 200),
                    'maxPostCount' => min($limit, 200),
                    'maxComments' => 0,
                    'maxCommunitiesCount' => 0,
                    'maxUserCount' => 0,
                    'maxLeaderBoardItems' => 0,
                    'scrollTimeout' => 20,
                    'proxy' => ['useApifyProxy' => true],
                ];
                $subreddits = $this->clean_list((array) ($source_plan['subreddits'] ?? []), 20);
                if (!empty($subreddits)) { $input['searchCommunityName'] = ltrim((string) $subreddits[0], 'r/'); }
                break;
            case 'google_trends_data_xplorer':
            case 'google_trends_generic':
                // data_xplorer/google-trends-fast-scraper: keyword analysis uses a
                // singular keyword + predefinedTimeframe. Trending discovery uses
                // enableTrendingSearches/trendingSearches* fields.
                $input = [
                    // v0.3.31 live QA: the actor defaults enableTrendingSearches to
                    // true. Without this explicit false, keyword runs silently return
                    // generic US trending searches instead of the requested keyword.
                    'enableTrendingSearches' => false,
                    'keyword' => $primary_query,
                    'predefinedTimeframe' => $this->trends_time_range($date_range),
                    'geo' => $this->geo_code($market),
                    'fetchRegionalData' => false,
                    'proxyConfiguration' => $this->reliable_google_proxy(),
                ];
                if ($primary_query === 'trend' || $primary_query === '') {
                    $input = [
                        'enableTrendingSearches' => true,
                        'trendingSearchesCountry' => $this->geo_code($market) ?: 'US',
                        'trendingSearchesTimeframe' => $this->trends_timeframe_hours($date_range),
                        'trendingSearchesMaxItems' => min($limit, 300),
                        'proxyConfiguration' => $this->reliable_google_proxy(),
                    ];
                }
                break;
            case 'google_news_data_xplorer':
            case 'google_news_generic':
                // data_xplorer/google-news-scraper-fast: current input uses keywords,
                // maxArticles, timeframe and region_language.
                $input = [
                    'keywords' => $keywords ?: [$primary_query],
                    'maxArticles' => min($limit, 200),
                    'timeframe' => $this->news_timeframe($date_range),
                    'region_language' => $this->region_language($market, $language),
                    'decodeUrls' => false,
                    'extractDescriptions' => false,
                    'extractImages' => true,
                    'proxyConfiguration' => $this->reliable_google_proxy(),
                ];
                break;
            default:
                $input = [
                    'query' => $primary_query,
                    'queries' => $keywords ?: [$primary_query],
                    'keywords' => $keywords ?: [$primary_query],
                    'maxItems' => $limit,
                ];
                break;
        }

        // Allow the mother plugin / site-specific code to adapt actor inputs when a
        // chosen Apify actor uses a stricter schema. This keeps multi-source live
        // activation extensible without exposing raw provider mechanics to users.
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fidtf_generic_apify_actor_input', $input, $source_key, $actor_id, $canonical_payload, [
                'profile' => $profile,
                'primary_query' => $primary_query,
                'keywords' => $keywords,
                'hashtags' => $hashtags,
                'limit' => $limit,
                'market' => $market,
                'language' => $language,
                'date_range' => $date_range,
            ]);
            if (is_array($filtered) && !empty($filtered)) { $input = $filtered; }
        }
        return $input;
    }


    private function relative_since(string $date_range): string {
        switch (sanitize_key($date_range)) {
            case 'last_24_hours': return '1 day';
            case 'last_7_days': return '7 days';
            case 'last_90_days': return '3 months';
            case 'last_12_months': return '1 year';
            case 'last_30_days':
            default: return '30 days';
        }
    }

    private function instagram_urls_from_query(string $primary_query, array $hashtags): array {
        if (preg_match('~^https?://(www\.)?instagram\.com/~i', $primary_query)) { return [$primary_query]; }
        $tag_source = !empty($hashtags) ? (string) $hashtags[0] : $primary_query;
        $tag = $this->instagram_hashtag_slug($tag_source);
        return $tag !== '' ? ['https://www.instagram.com/explore/tags/' . $tag . '/'] : [];
    }

    private function actor_profile(string $source_key, string $actor_id): string {
        $actor = strtolower(trim($actor_id));
        $actor = str_replace('~', '/', $actor);
        if ($source_key === 'instagram') {
            if ($actor === 'apify/instagram-post-scraper' || strpos($actor, 'instagram-post-scraper') !== false) { return 'instagram_post_scraper'; }
            if ($actor === 'apify/instagram-scraper' || preg_match('~/instagram-(scraper|hashtag-scraper)$~', $actor)) { return 'instagram_apify_official'; }
            if (strpos($actor, 'instagram') !== false) { return 'instagram_generic'; }
        }
        if ($source_key === 'reddit') {
            if ($actor === 'trudax/reddit-scraper-lite' || strpos($actor, 'reddit-scraper-lite') !== false) { return 'reddit_trudax_lite'; }
            if (strpos($actor, 'reddit') !== false) { return 'reddit_generic'; }
        }
        if ($source_key === 'google_trends') {
            if ($actor === 'data_xplorer/google-trends-fast-scraper' || strpos($actor, 'google-trends-fast') !== false) { return 'google_trends_data_xplorer'; }
            if (strpos($actor, 'trend') !== false) { return 'google_trends_generic'; }
        }
        if ($source_key === 'google_news') {
            if ($actor === 'data_xplorer/google-news-scraper-fast' || strpos($actor, 'google-news-scraper-fast') !== false) { return 'google_news_data_xplorer'; }
            if (strpos($actor, 'news') !== false) { return 'google_news_generic'; }
        }
        return 'generic_query_actor';
    }

    private function validate_actor_input(string $source_key, string $profile, array $input): array {
        $errors = [];
        if ($source_key === 'instagram') {
            $has_direct = !empty($input['directUrls']) && is_array($input['directUrls']);
            $has_search = isset($input['search']) && trim((string) $input['search']) !== '';
            if (!$has_direct && !$has_search) { $errors[] = 'instagram_missing_search_or_direct_url'; }
            if (isset($input['resultsLimit']) && (int) $input['resultsLimit'] < 1) { $errors[] = 'instagram_invalid_results_limit'; }
        } elseif ($source_key === 'reddit') {
            if (empty($input['searches']) || !is_array($input['searches'])) { $errors[] = 'reddit_missing_searches'; }
            if (isset($input['maxPostCount']) && (int) $input['maxPostCount'] < 1) { $errors[] = 'reddit_invalid_max_post_count'; }
        } elseif ($source_key === 'google_trends') {
            $keyword_mode = isset($input['keyword']) && trim((string) $input['keyword']) !== '';
            $trending_mode = !empty($input['enableTrendingSearches']);
            if (!$keyword_mode && !$trending_mode) { $errors[] = 'google_trends_missing_keyword_or_trending_mode'; }
            if ($keyword_mode && (!array_key_exists('enableTrendingSearches', $input) || $input['enableTrendingSearches'] !== false)) { $errors[] = 'google_trends_keyword_mode_must_disable_trending_searches'; }
        } elseif ($source_key === 'google_news') {
            if (empty($input['keywords']) || !is_array($input['keywords'])) { $errors[] = 'google_news_missing_keywords'; }
            if (isset($input['maxArticles']) && (int) $input['maxArticles'] < 1) { $errors[] = 'google_news_invalid_max_articles'; }
        } else {
            if (empty($input['query']) && empty($input['queries']) && empty($input['keywords'])) { $errors[] = 'generic_actor_missing_query'; }
        }
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'profile' => $profile];
        }
        return ['ok' => true, 'errors' => [], 'profile' => $profile];
    }

    private function start_with_core_client(array $context, array $actor_input) {
        $actor_id = sanitize_text_field((string) ($context['actor_id'] ?? ''));
        $url = $this->run_url($actor_id);
        try {
            $response = call_user_func(['VES_Apify_Client', 'request'], 'POST', $url, wp_json_encode($actor_input));
        } catch (Throwable $e) {
            return new WP_Error('source_provider_exception', $this->label() . ' provider could not start.', ['status' => 500, 'retryable' => true]);
        }
        if (is_wp_error($response)) { return $this->map_core_error($response); }
        return $this->normalize_start_response($response, $context, $actor_input);
    }

    private function refresh_with_core_client(array $context, int $limit) {
        $run_id = sanitize_text_field((string) ($context['provider_run_id'] ?? ''));
        try {
            $response = call_user_func(['VES_Apify_Client', 'fetch_run'], $run_id, 0);
        } catch (Throwable $e) {
            return new WP_Error('source_provider_exception', $this->label() . ' provider refresh failed.', ['status' => 500, 'retryable' => true]);
        }
        if (is_wp_error($response)) { return $this->map_core_error($response); }
        return $this->normalize_refresh_response($response, $run_id, $limit, $context);
    }

    /**
     * Phase 9D.2 — legacy direct run-start is DISABLED. A paid Apify dispatch
     * may only happen through the core gate (FIDTF_Core_Apify_Client_Adapter →
     * VES_Apify_Client::request with fail-closed allowlist + hard charge
     * ceiling). When the core client is unavailable this FAILS CLOSED with a
     * scrubbed security event — it never falls back to an unguarded
     * wp_remote_post. Read paths (refresh/datasets) below remain read-only.
     */
    private function start_direct_apify_run(array $context, array $actor_input) {
        if (class_exists('FIDTF_Core_Apify_Client_Adapter') && FIDTF_Core_Apify_Client_Adapter::available()) {
            return $this->start_with_core_client($context, $actor_input);
        }
        if (class_exists('VES_Security_Event_Log')) {
            VES_Security_Event_Log::record('provider_dispatch_blocked', 'DTF generic Apify dispatch blocked: core provider client unavailable (fail-closed, legacy direct path disabled).', [
                'module' => 'deep_trend_finder',
                'source_key' => sanitize_key((string) ($context['source_key'] ?? $this->source_key)),
            ]);
        }
        return new WP_Error('source_dispatch_blocked_fail_closed', $this->label() . ' dispatch is blocked: the guarded provider client is unavailable and direct dispatch is disabled.', ['status' => 503, 'retryable' => false, 'request_attempted' => false]);
    }

    private function refresh_direct_apify_run(array $context, string $provider_run_id) {
        $token = FIDTF_Settings::tiktok_apify_token();
        if ($token === '' || !function_exists('wp_remote_get')) {
            return new WP_Error('source_provider_unavailable', $this->label() . ' provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        $response = wp_remote_get(self::API_BASE . '/actor-runs/' . rawurlencode($provider_run_id), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 12,
        ]);
        if (is_wp_error($response)) { return $this->map_http_error($response); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        if ($code < 200 || $code >= 300) { return $this->http_status_error($code); }
        $decoded = json_decode($body, true);
        return $this->normalize_refresh_response(is_array($decoded) ? $decoded : [], $provider_run_id, $this->limit_from_actor_input((array) ($context['actor_input'] ?? [])), $context);
    }

    private function normalize_direct_http_response($response, array $context, array $actor_input) {
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        if ($code < 200 || $code >= 300) { return $this->http_status_error($code); }
        $decoded = json_decode($body, true);
        return $this->normalize_start_response(is_array($decoded) ? $decoded : [], $context, $actor_input);
    }

    private function normalize_start_response($response, array $context, array $actor_input) {
        $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
        $data = $this->body_data($body);
        $items = $this->items_from_response($body);
        $run_id = $this->extract_run_id($body);
        $status = strtoupper((string) ($data['status'] ?? $body['status'] ?? ''));
        $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? $body['defaultDatasetId'] ?? ''));
        if ($dataset_id !== '') { $context['provider_dataset_id'] = $dataset_id; $context['default_dataset_id'] = $dataset_id; }
        if (!empty($items)) { return $this->completed_result($run_id, $items, $context, $actor_input); }
        if ($run_id === '') {
            return new WP_Error('source_provider_invalid_run_response', $this->label() . ' provider returned an invalid run response.', ['status' => 502, 'retryable' => false]);
        }
        if ($this->apify_run_succeeded($status)) {
            $items = $this->fetch_items_for_run($run_id, $this->limit_from_actor_input($actor_input), $dataset_id, $actor_input);
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($run_id, $items, $context, $actor_input);
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('source_provider_failed', $this->label() . ' provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return $this->running_result($run_id, $context, $actor_input, $this->non_terminal_status_for_response($status, 'queued'), 'run_queued');
    }

    private function normalize_refresh_response($response, string $run_id, int $limit, array $context = []) {
        $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
        $data = $this->body_data($body);
        $status = strtoupper((string) ($data['status'] ?? $body['status'] ?? ''));
        $dataset_id = sanitize_text_field((string) ($data['defaultDatasetId'] ?? $body['defaultDatasetId'] ?? ''));
        if ($dataset_id !== '') { $context['provider_dataset_id'] = $dataset_id; $context['default_dataset_id'] = $dataset_id; }
        if ($this->apify_run_succeeded($status)) {
            $items = $this->fetch_items_for_run($run_id, $limit, $dataset_id, (array) ($context['actor_input'] ?? []));
            if (is_wp_error($items)) { return $items; }
            return $this->completed_result($run_id, $items, $context, (array) ($context['actor_input'] ?? []));
        }
        if ($this->apify_run_failed($status)) {
            return new WP_Error('source_provider_failed', $this->label() . ' provider run failed.', ['status' => 502, 'retryable' => false]);
        }
        return $this->running_result($run_id, $context, (array) ($context['actor_input'] ?? []), $this->non_terminal_status_for_response($status, 'running'), 'run_running');
    }

    private function fetch_items_for_run(string $run_id, int $limit, string $dataset_id = '', array $actor_input = []) {
        $limit = max(1, min(300, $limit));
        if ($dataset_id !== '') { $this->last_provider_dataset_total_rows = $this->fetch_dataset_total_count($dataset_id); }
        $items = [];
        if (method_exists('VES_Apify_Client', 'fetch_items')) {
            try { $items = call_user_func(['VES_Apify_Client', 'fetch_items'], $run_id, $limit); }
            catch (Throwable $e) { return new WP_Error('source_dataset_fetch_failed', $this->label() . ' dataset fetch failed.', ['status' => 500, 'retryable' => true]); }
            if (is_wp_error($items)) { return $this->map_core_error($items); }
        }
        $sanitized = $this->sanitize_items(is_array($items) ? $items : [], $actor_input);
        if (empty($sanitized) && $dataset_id !== '') {
            $fallback = $this->fetch_items_for_dataset($dataset_id, $limit, $actor_input);
            if (is_wp_error($fallback)) { return $fallback; }
            return $fallback;
        }
        return $sanitized;
    }

    private function fetch_items_for_dataset(string $dataset_id, int $limit, array $actor_input = []) {
        // v0.3.32 live QA: do not use Apify clean=true here. Instagram
        // direct hashtag and Clockworks TikTok can report cleanItemCount=0
        // while itemCount contains valid evidence rows. Fetch raw dataset rows
        // and let the plugin normalizer/sanitizer decide usability.
        $dataset_id = sanitize_text_field($dataset_id);
        if ($dataset_id === '') { return []; }
        $limit = max(1, min(300, $limit));
        if (method_exists('VES_Apify_Client', 'request')) {
            $base = self::API_BASE . '/datasets/' . rawurlencode($dataset_id) . '/items';
            $url = function_exists('add_query_arg') ? add_query_arg(['format' => 'json', 'clean' => 'false', 'limit' => $limit], $base) : $base . '?' . http_build_query(['format'=>'json','clean'=>'false','limit'=>$limit]);
            try { $response = call_user_func(['VES_Apify_Client', 'request'], 'GET', $url); }
            catch (Throwable $e) { return new WP_Error('source_dataset_fetch_failed', $this->label() . ' dataset fallback fetch failed.', ['status' => 500, 'retryable' => true]); }
            if (is_wp_error($response)) { return $this->map_core_error($response); }
            if ($this->last_provider_dataset_total_rows < 1) { $this->last_provider_dataset_total_rows = $this->fetch_dataset_total_count($dataset_id); }
            $body = is_array($response) ? ($response['body'] ?? $response) : [];
            return $this->sanitize_items(is_array($body) ? $body : [], $actor_input);
        }
        $token = FIDTF_Settings::tiktok_apify_token();
        if ($token === '' || !function_exists('wp_remote_get')) { return []; }
        if ($this->last_provider_dataset_total_rows < 1) { $this->last_provider_dataset_total_rows = $this->fetch_dataset_total_count($dataset_id); }
        $url = self::API_BASE . '/datasets/' . rawurlencode($dataset_id) . '/items?format=json&clean=false&limit=' . rawurlencode((string) $limit);
        $response = wp_remote_get($url, ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 20]);
        if (is_wp_error($response)) { return $this->map_http_error($response); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        if ($code < 200 || $code >= 300) { return $this->http_status_error($code); }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return $this->sanitize_items(is_array($decoded) ? $decoded : [], $actor_input);
    }

    private function fetch_dataset_total_count(string $dataset_id): int {
        $dataset_id = sanitize_text_field($dataset_id);
        if ($dataset_id === '') { return 0; }
        if (method_exists('VES_Apify_Client', 'request')) {
            try { $response = call_user_func(['VES_Apify_Client', 'request'], 'GET', self::API_BASE . '/datasets/' . rawurlencode($dataset_id)); }
            catch (Throwable $e) { return 0; }
            if (is_wp_error($response)) { return 0; }
            $body = is_array($response) ? (array) ($response['body'] ?? $response) : [];
            $data = is_array($body['data'] ?? null) ? (array) $body['data'] : $body;
            return max(0, (int) ($data['itemCount'] ?? $data['cleanItemCount'] ?? 0));
        }
        $token = FIDTF_Settings::tiktok_apify_token();
        if ($token !== '' && function_exists('wp_remote_get')) {
            $response = wp_remote_get(self::API_BASE . '/datasets/' . rawurlencode($dataset_id), ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 12]);
            if (!is_wp_error($response)) {
                $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
                    if (is_array($decoded)) {
                        $data = is_array($decoded['data'] ?? null) ? (array) $decoded['data'] : $decoded;
                        return max(0, (int) ($data['itemCount'] ?? $data['cleanItemCount'] ?? 0));
                    }
                }
            }
        }
        return 0;
    }

    private function completed_result(string $provider_run_id, array $items, array $context = [], array $actor_input = []): array {
        $count = count($items);
        $status = $count > 0 ? 'completed' : 'completed_no_relevant_evidence';
        if ($count > 0) {
            $message = $this->label() . ' collection completed.';
            $outcome_reason = 'evidence_returned';
        } elseif ($this->last_flattened_raw_items > 0) {
            $message = $this->label() . ' collection returned rows, but no usable normalized evidence was produced.';
            $outcome_reason = 'normalized_empty';
        } elseif ($this->last_provider_dataset_rows > 0 || $this->last_provider_dataset_total_rows > 0) {
            $message = $this->label() . ' collection returned provider rows, but the adapter could not flatten usable evidence.';
            $outcome_reason = 'flattened_empty';
        } else {
            $message = $this->label() . ' collection completed but the provider dataset was empty.';
            $outcome_reason = 'zero_dataset_items';
        }
        return [
            'ok' => true,
            'status' => $status,
            'provider_run_id' => $provider_run_id,
            'items' => $items,
            'raw_count' => $count,
            'provider_dataset_rows' => $this->last_provider_dataset_rows,
            'provider_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'flattened_raw_items' => $this->last_flattened_raw_items,
            'provider_mode_used' => $this->source_key . '_apify_live',
            'request_attempted' => true,
            'outcome_reason' => $outcome_reason,
            'message' => $message,
            'discovery_actor_id' => sanitize_text_field((string) ($context['actor_id'] ?? '')),
            'actor_input_profile' => sanitize_key((string) ($context['actor_input_profile'] ?? '')),
            'discovery_actor_input' => $actor_input,
            'discovery_run_id' => $provider_run_id,
            'discovery_dataset_id' => sanitize_text_field((string) ($context['provider_dataset_id'] ?? $context['default_dataset_id'] ?? '')),
            'discovery_dataset_rows' => $this->last_provider_dataset_rows,
            'discovery_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'discovery_flattened_items' => $this->last_flattened_raw_items,
        ];
    }

    private function running_result(string $run_id, array $context, array $actor_input, string $status, string $reason): array {
        return [
            'ok' => true,
            'status' => $status,
            'provider_run_id' => $run_id,
            'items' => [],
            'provider_mode_used' => $this->source_key . '_apify_live',
            'request_attempted' => true,
            'outcome_reason' => $reason,
            'message' => $this->label() . ' provider run has started. Waiting for provider result.',
            'retryable' => true,
            'retry_after' => $this->poll_after_seconds(),
            'poll_after_seconds' => $this->poll_after_seconds(),
            'provider_non_terminal' => true,
            'discovery_actor_id' => sanitize_text_field((string) ($context['actor_id'] ?? '')),
            'actor_input_profile' => sanitize_key((string) ($context['actor_input_profile'] ?? '')),
            'discovery_actor_input' => $actor_input,
            'discovery_run_id' => $run_id,
            'discovery_dataset_id' => sanitize_text_field((string) ($context['provider_dataset_id'] ?? $context['default_dataset_id'] ?? '')),
            'provider_dataset_rows' => $this->last_provider_dataset_rows,
            'provider_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'flattened_raw_items' => $this->last_flattened_raw_items,
            'discovery_dataset_rows' => $this->last_provider_dataset_rows,
            'discovery_dataset_total_rows' => $this->last_provider_dataset_total_rows,
            'discovery_flattened_items' => $this->last_flattened_raw_items,
        ];
    }

    private function sanitize_items(array $items, array $actor_input = []): array {
        $this->last_provider_dataset_rows = count($items);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            foreach ($this->expand_source_item($item, $actor_input) as $expanded) {
                if (is_array($expanded)) { $out[] = $expanded; }
            }
        }
        $this->last_flattened_raw_items = count($out);
        if ($this->last_provider_dataset_total_rows < $this->last_provider_dataset_rows) {
            $this->last_provider_dataset_total_rows = $this->last_provider_dataset_rows;
        }
        return $out;
    }

    private function expand_source_item(array $item, array $actor_input = []): array {
        foreach (['items', 'results', 'posts', 'articles', 'data'] as $container_key) {
            if (isset($item[$container_key]) && is_array($item[$container_key]) && $this->is_list_array($item[$container_key])) {
                $out = [];
                foreach ($item[$container_key] as $child) {
                    if (is_array($child)) {
                        $child['provider_container_type'] = $child['provider_container_type'] ?? $this->source_key . '_' . $container_key;
                        $child = $this->attach_actor_context_to_item($child, $actor_input);
                        $out[] = $child;
                    }
                }
                if (!empty($out)) { return $out; }
            }
        }
        if ($this->source_key === 'google_trends') {
            foreach (['trending_searches', 'trendingSearches', 'trends'] as $trend_key) {
                if (isset($item[$trend_key]) && is_array($item[$trend_key])) {
                    $out = [];
                    foreach ($item[$trend_key] as $trend) {
                        if (!is_array($trend)) { continue; }
                        $trend['geo'] = $trend['geo'] ?? ($item['geo'] ?? '');
                        $trend['language'] = $trend['language'] ?? ($item['language'] ?? '');
                        $trend['timeframe_hours'] = $trend['timeframe_hours'] ?? ($item['timeframe_hours'] ?? $item['timeframeHours'] ?? '');
                        $trend['provider_container_type'] = 'google_trends_trending_search';
                        $trend = $this->attach_actor_context_to_item($trend, $actor_input);
                        $out[] = $trend;
                    }
                    if (!empty($out)) { return $out; }
                }
            }
        }
        if ($this->source_key === 'google_trends' && (isset($item['timeline_data']) || isset($item['timelineData']) || isset($item['interestOverTime']) || isset($item['interest_over_time']) || isset($item['related_queries']) || isset($item['relatedQueries']))) {
            $item['provider_container_type'] = 'google_trends_keyword_analysis';
            $item = $this->attach_actor_context_to_item($item, $actor_input);
            return [$item];
        }
        return [$this->attach_actor_context_to_item($item, $actor_input)];
    }

    private function attach_actor_context_to_item(array $item, array $actor_input): array {
        $query = $this->primary_query_from_actor_input($actor_input);
        if ($query !== '' && empty($item['provider_query']) && empty($item['searchQuery']) && empty($item['keyword']) && empty($item['query']) && empty($item['metadata']['keyword'])) {
            $item['provider_query'] = $query;
        }
        if ($this->source_key !== '' && empty($item['provider_source_key'])) {
            $item['provider_source_key'] = $this->source_key;
        }
        return $item;
    }

    private function primary_query_from_actor_input(array $actor_input): string {
        $candidates = [];
        foreach (['keyword', 'search', 'query'] as $key) {
            if (!empty($actor_input[$key]) && is_scalar($actor_input[$key])) { $candidates[] = (string) $actor_input[$key]; }
        }
        foreach (['searchQueries', 'searches', 'keywords', 'queries', 'directUrls'] as $key) {
            if (!empty($actor_input[$key]) && is_array($actor_input[$key])) {
                foreach ($actor_input[$key] as $value) {
                    if (is_scalar($value)) { $candidates[] = (string) $value; break; }
                }
            }
        }
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') { continue; }
            if (preg_match('~instagram\.com/explore/tags/([^/]+)/?~i', $candidate, $m)) {
                return sanitize_text_field((string) $m[1]);
            }
            return sanitize_text_field(ltrim($candidate, '#@'));
        }
        return '';
    }


    private function poll_after_seconds(): int {
        if ($this->source_key === 'google_trends') { return 90; }
        if ($this->source_key === 'reddit') { return 45; }
        return 30;
    }

    private function map_core_error($error) {
        if (!is_wp_error($error)) { return $error; }
        $code = sanitize_key((string) $error->get_error_code());
        $data = (array) $error->get_error_data();
        if (in_array($code, ['apify_concurrency_limit', 'apify_concurrency_lock_busy', 'apify_transport', 'apify_rate_limited', 'apify_upstream_unavailable'], true)) {
            return new WP_Error($code === 'apify_rate_limited' ? 'provider_rate_limited' : 'provider_busy', $this->label() . ' provider is temporarily unavailable.', ['status' => (int) ($data['status'] ?? 503), 'retryable' => true, 'retry_after' => (int) ($data['retry_after'] ?? 75)]);
        }
        if (in_array($code, ['missing_backend_token', 'apify_actor_rental_required'], true)) {
            return new WP_Error('source_provider_unavailable', $this->label() . ' provider is not configured.', ['status' => 503, 'retryable' => false]);
        }
        if ($code === 'apify_invalid_input') {
            return new WP_Error('source_provider_invalid_input', $this->label() . ' actor rejected the run input. Check the actor mapping/input schema.', ['status' => 400, 'retryable' => false]);
        }
        return new WP_Error($code !== '' ? $code : 'source_provider_failed', $this->label() . ' provider returned an error.', ['status' => (int) ($data['status'] ?? 502), 'retryable' => !empty($data['retryable'])]);
    }

    private function map_http_error($error) { return new WP_Error('provider_transport_failed', $this->label() . ' provider transport failed.', ['status' => 503, 'retryable' => true]); }
    private function http_status_error(int $code) {
        if ($code === 429) { return new WP_Error('provider_rate_limited', $this->label() . ' provider is rate limited.', ['status' => 429, 'retryable' => true, 'retry_after' => 120]); }
        if ($code >= 500) { return new WP_Error('provider_busy', $this->label() . ' provider is temporarily unavailable.', ['status' => $code, 'retryable' => true, 'retry_after' => 75]); }
        return new WP_Error('source_provider_failed', $this->label() . ' provider returned an error.', ['status' => $code, 'retryable' => false]);
    }

    private function apify_run_succeeded(string $status): bool { return strtoupper($status) === 'SUCCEEDED'; }
    private function apify_run_failed(string $status): bool { return in_array(strtoupper($status), ['FAILED', 'ABORTED', 'TIMED-OUT'], true); }
    private function non_terminal_status_for_response(string $status, string $default = 'running'): string {
        $status = strtoupper($status);
        if (in_array($status, ['READY', 'STARTING'], true)) { return 'queued'; }
        if (in_array($status, ['RUNNING', 'TIMING-OUT'], true)) { return 'running'; }
        return $default;
    }
    private function body_data(array $body): array { return is_array($body['data'] ?? null) ? (array) $body['data'] : $body; }
    private function extract_run_id(array $body): string {
        $data = $this->body_data($body);
        foreach ([$data, $body] as $src) {
            foreach (['id', 'runId', 'provider_run_id'] as $key) {
                if (!empty($src[$key])) { return sanitize_text_field((string) $src[$key]); }
            }
        }
        return '';
    }
    private function items_from_response(array $body): array {
        foreach (['items', 'results', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && $this->is_list_array($body[$key])) { return $this->sanitize_items($body[$key], (array) ($body['actor_input'] ?? [])); }
        }
        return [];
    }
    private function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) { if ($key !== $expected) { return false; } $expected++; }
        return true;
    }
    private function run_url(string $actor_id): string {
        if (function_exists('ves_prepare_run_options') && function_exists('ves_make_run_url')) {
            $options = ves_prepare_run_options();
            $options['waitForFinish'] = 0;
            return ves_make_run_url($actor_id, $options);
        }
        return self::API_BASE . '/acts/' . $this->actor_path($actor_id) . '/runs?build=latest&waitForFinish=0&timeout=0';
    }
    private function actor_path(string $actor_id): string { return rawurlencode(str_replace('/', '~', trim($actor_id))); }
    private function limit_from_actor_input(array $actor_input): int {
        foreach (['maxItems', 'resultsLimit', 'searchLimit', 'limit', 'maxPostCount', 'maxArticles', 'trendingSearchesMaxItems'] as $key) {
            if (isset($actor_input[$key])) { return max(1, min(300, (int) $actor_input[$key])); }
        }
        return FIDTF_Settings::source_limit($this->source_key);
    }
    private function clean_list(array $items, int $limit): array {
        $out = [];
        foreach ($items as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item !== '') { $out[] = sanitize_text_field($item); }
            if (count($out) >= $limit) { break; }
        }
        return array_values(array_unique($out));
    }
    private function clean_hashtags(array $items, int $limit): array {
        $out = [];
        foreach ($items as $item) {
            $item = ltrim(trim(wp_strip_all_tags((string) $item)), '#');
            if ($item !== '') { $out[] = sanitize_key($item); }
            if (count($out) >= $limit) { break; }
        }
        return array_values(array_unique($out));
    }
    private function primary_query(array $keywords, array $hashtags, string $brand): string {
        if (!empty($keywords[0])) { return $keywords[0]; }
        if (!empty($hashtags[0])) { return '#' . $hashtags[0]; }
        if (trim($brand) !== '') { return sanitize_text_field($brand); }
        return 'trend';
    }
    private function short_query(string $brief): string {
        $brief = preg_replace('/\s+/u', ' ', trim(wp_strip_all_tags($brief)));
        if ($brief === null || $brief === '') { return 'trend'; }
        if (function_exists('mb_substr')) { return mb_substr($brief, 0, 80, 'UTF-8'); }
        return substr($brief, 0, 80);
    }
    private function reddit_time(string $date_range): string {
        return in_array($date_range, ['last_7_days', 'last_14_days'], true) ? 'week' : (in_array($date_range, ['last_90_days'], true) ? 'year' : 'month');
    }
    private function trends_time_range(string $date_range): string {
        if ($date_range === 'last_24_hours') { return 'now 1-d'; }
        if ($date_range === 'last_7_days') { return 'now 7-d'; }
        if ($date_range === 'last_14_days') { return 'today 1-m'; }
        if ($date_range === 'last_90_days') { return 'today 3-m'; }
        return 'today 1-m';
    }
    private function trends_timeframe_hours(string $date_range): string {
        if ($date_range === 'last_4_hours') { return '4'; }
        if ($date_range === 'last_7_days') { return '168'; }
        if ($date_range === 'last_48_hours') { return '48'; }
        return '24';
    }
    private function news_timeframe(string $date_range): string {
        if ($date_range === 'last_1_hour' || $date_range === 'last_hour') { return '1h'; }
        if ($date_range === 'last_7_days') { return '7d'; }
        if ($date_range === 'last_90_days' || $date_range === 'last_year') { return '1y'; }
        return '1d';
    }
    private function geo_code(string $market): string {
        $m = strtolower(trim($market));
        if (in_array($m, ['spain', 'es', 'españa'], true)) { return 'ES'; }
        if (in_array($m, ['united states', 'usa', 'us'], true)) { return 'US'; }
        if (in_array($m, ['united kingdom', 'uk', 'gb'], true)) { return 'GB'; }
        return strtoupper(substr(preg_replace('/[^a-z]/i', '', $market), 0, 2));
    }

    private function normalize_language(string $language, string $fallback = 'es'): string {
        $language = strtolower(trim($language));
        $language = preg_replace('/[^a-z]/', '', $language) ?: '';
        if ($language === '') { return $fallback; }
        return substr($language, 0, 2);
    }

    private function region_language(string $market, string $language): string {
        $geo = $this->geo_code($market) ?: 'ES';
        $lang = $this->normalize_language($language, strtolower($geo) === 'es' ? 'es' : 'en');
        return $geo . ':' . $lang;
    }

    private function reliable_google_proxy(): array {
        // v0.3.31 live QA: default to standard Apify proxy for lower cost and
        // broader account compatibility. Sites that need residential Google
        // collection can override this with the filter below.
        $proxy = ['useApifyProxy' => true];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fidtf_google_actor_proxy_configuration', $proxy, $this->source_key);
            if (is_array($filtered) && !empty($filtered)) { return $filtered; }
        }
        return $proxy;
    }

    private function instagram_hashtag_slug(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('~^https?://(www\.)?instagram\.com/explore/tags/([^/]+)/?.*$~i', '$2', $value);
        $value = ltrim((string) $value, '#@');
        $value = preg_replace('/[^a-z0-9_]+/i', '', $value);
        return trim((string) $value);
    }

    private function label(): string { return ucwords(str_replace('_', ' ', $this->source_key)); }
}
