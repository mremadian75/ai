<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Ajax_Controller {
    private static $ai_context_cache = [];
    private static $normalized_post_cache = null;
    private static $active_run_context = [];

    private static function production_mvp_enabled() {
        return defined('VES_PRODUCTION_MVP') && VES_PRODUCTION_MVP;
    }

    public static function production_mvp_disabled_message() {
        return 'Este módulo no está habilitado en el MVP de producción.';
    }

    private static function production_mvp_disabled_platforms() {
        return ['linkedin', 'facebook_ads', 'google_ads', 'semrush', 'moz', 'ahrefs'];
    }

    private static function production_mvp_disabled_google_modules() {
        return ['news', 'trends', 'images', 'maps', 'crawler', 'advanced'];
    }

    private static function production_mvp_module_key_for_platform($platform) {
        $platform = sanitize_key((string) $platform);
        if ($platform === 'linkedin') { return 'linkedin'; }
        if (in_array($platform, ['facebook_ads', 'google_ads'], true)) { return 'ads_intelligence'; }
        if (in_array($platform, ['semrush', 'moz', 'ahrefs'], true)) { return 'seo_sem_aeo_advanced'; }
        return $platform !== '' ? $platform : 'unknown';
    }

    private static function mvp_disabled_ajax_response($request_id, $source, $module_key, $context = []) {
        $context = is_array($context) ? $context : [];
        $module_key = sanitize_key((string) $module_key);
        $payload = array_merge([
            'message' => self::production_mvp_disabled_message(),
            'gated' => true,
            'mvp_disabled' => true,
            'code' => 'ves_mvp_module_disabled',
            'module' => $module_key,
            'run_id' => null,
            'bundle_id' => null,
            'provider_call_attempted' => false,
            'provider_run_created' => false,
            'credits_reserved' => false,
            'credit_settlement_attempted' => false,
            'request_id' => $request_id,
        ], $context);
        self::log($source, self::production_mvp_disabled_message(), $payload);
        wp_send_json([
            'success' => false,
            'gated' => true,
            'mvp_disabled' => true,
            'code' => 'ves_mvp_module_disabled',
            'message' => self::production_mvp_disabled_message(),
            'data' => $payload,
        ], 200);
        exit;
    }

    private static function google_basic_diagnostic_payload($request_id, $code = 'google_basic_unavailable', $message = '') {
        $code = sanitize_key((string) $code);
        if ($message === '') {
            $message = 'Google Intelligence Basic no está disponible con la configuración actual.';
        }
        return [
            'platform' => 'google_intel',
            'module' => 'search',
            'module_label' => 'Google Search',
            'status' => 'CONFIG_REQUIRED',
            'result_quality' => 'config_required',
            'analysis_ready' => false,
            'run_id' => '',
            'items' => [],
            'display_items' => [],
            'analysis_items' => [],
            'item_count_preview' => 0,
            'all_items_count' => 0,
            'message' => $message,
            'user_message' => $message,
            'status_message' => $message,
            'error_code' => $code,
            'retryable' => false,
            'safeDiagnostic' => [
                'retryable' => false,
                'error_category' => $code,
                'stage' => 'google_basic_config',
                'recommended_action' => 'admin_config_required',
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ],
            'actions' => ['diagnostic'],
            'credits_reserved' => false,
            'final_credit_settled' => false,
            'credit_voided' => false,
            'request_id' => $request_id,
        ];
    }
    private static function get_primary_metric_label($platform, $items = null) {
        $label = function_exists('ves_metric_filter_label') ? ves_metric_filter_label($platform) : '';
        $label = trim((string) $label);
        if ($label !== '') {
            return $label;
        }
        $detected = function_exists('ves_detect_primary_metric_label') ? ves_detect_primary_metric_label($items, $platform) : 'Views';
        $detected = trim((string) $detected);
        return 'Mínimo ' . strtolower($detected !== '' ? $detected : 'views');
    }


    private static function requested_result_limit($src = []) {
        $src = is_array($src) ? $src : [];
        $raw = isset($src['limit']) ? wp_unslash($src['limit']) : null;
        if (function_exists('ves_clean_int')) {
            $limit = ves_clean_int($raw, 1, function_exists('ves_max_items') ? ves_max_items() : 200);
        } else {
            $limit = (int) $raw;
        }
        return $limit > 0 ? $limit : 20;
    }

    private static function dataset_fetch_limit($platform, $src = [], $is_post_url_mode = false) {
        $platform = sanitize_key((string) $platform);
        $requested = self::requested_result_limit($src);

        if ($is_post_url_mode) {
            return max(1, min(50, $requested));
        }

        // v0.9.24.4: pass $src so ves_compute_actor_limit can activate the
        // metric over-fetch multiplier when minViews/minLikes > 0. This ensures
        // the PHP dataset-fetch window matches what the actor was asked to collect.
        $actor_limit = function_exists('ves_compute_actor_limit')
            ? (int) ves_compute_actor_limit($requested, $platform, (array) $src)
            : (int) max(20, $requested * 4);

        // Pulling 250 full TikTok/Instagram items can exhaust PHP memory on shared
        // hosting and causes WordPress to return its critical-error HTML page.
        // When metric over-fetch is active the cap must accommodate the multiplied
        // window (e.g. user asks 20, minViews=1000 → actor fetches 100 → cap>=100).
        $default_cap = in_array($platform, ['tiktok', 'instagram', 'pinterest'], true) ? 100 : 120;
        $cap = defined('VE_SOCIAL_DATASET_FETCH_LIMIT') ? (int) VE_SOCIAL_DATASET_FETCH_LIMIT : $default_cap;
        $cap = max($actor_limit, max(20, min(200, $cap)));

        $target = max($requested, $actor_limit);
        return max(1, min($cap, $target));
    }

    private static function secondary_items_limit($src = []) {
        $requested = self::requested_result_limit($src);
        $limit = max(8, min(30, $requested * 3));
        if (defined('VE_SOCIAL_SECONDARY_ITEMS_LIMIT')) {
            $limit = max(0, min(60, (int) VE_SOCIAL_SECONDARY_ITEMS_LIMIT));
        }
        return $limit;
    }

    /**
     * TikTok profile mode can return only a profile/container object even when the
     * account has public posts. In that case we run a second, search-based lookup
     * by author handle and let the normal local filters do the rest.
     */
    private static function tiktok_profile_handles_from_request($src = []) {
        $src = is_array($src) ? $src : [];
        $targets_raw = isset($src['targets']) ? wp_unslash($src['targets']) : '';
        $handles = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $targets_raw) as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            $url = function_exists('ves_normalize_url') ? ves_normalize_url($line) : '';
            if ($url !== '' && function_exists('ves_platform_url_kind') && ves_platform_url_kind('tiktok', $url) === 'profile') {
                $from_url = function_exists('ves_tiktok_profile_handles_from_urls') ? ves_tiktok_profile_handles_from_urls([$url]) : [];
                $handles = array_merge($handles, $from_url);
                continue;
            }
            if ($url === '' || stripos($line, 'tiktok.com') === false) {
                $handle = preg_replace('/[^A-Za-z0-9_.]/', '', ltrim($line, "@ \t\n\r\0\x0B"));
                if ($handle !== '') { $handles[] = $handle; }
            }
        }
        return array_values(array_unique(array_filter($handles)));
    }

    private static function tiktok_item_author_blob($item) {
        if (!is_array($item)) { return ''; }
        $parts = [];
        $paths = [
            ['authorMeta', 'name'], ['authorMeta', 'nickName'], ['authorMeta', 'uniqueId'], ['authorMeta', 'id'],
            ['author', 'username'], ['author', 'uniqueId'], ['author', 'nickname'], ['author', 'name'],
            ['user', 'username'], ['user', 'uniqueId'], ['user', 'nickname'], ['user', 'name'],
            ['authorUsername'], ['authorName'], ['username'], ['uniqueId'], ['nickname'], ['userName'],
            ['webVideoUrl'], ['url'], ['link'], ['videoUrl'],
        ];
        foreach ($paths as $path) {
            $value = $item;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) { $value = null; break; }
                $value = $value[$key];
            }
            if (is_scalar($value) && trim((string) $value) !== '') { $parts[] = (string) $value; }
        }
        return strtolower(implode(' ', $parts));
    }

    private static function tiktok_has_video_items($items) {
        if (!is_array($items) || empty($items)) { return false; }
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            if (!empty($item['webVideoUrl']) || !empty($item['videoUrl']) || !empty($item['shareUrl']) || !empty($item['postPage'])) { return true; }
            if (!empty($item['id']) && (isset($item['playCount']) || isset($item['diggCount']) || isset($item['collectCount']) || isset($item['commentCount']))) { return true; }
            if (!empty($item['id']) && (isset($item['views']) || isset($item['likes']) || isset($item['comments']) || isset($item['shares']))) { return true; }
            if (!empty($item['id']) && isset($item['video']) && is_array($item['video'])) { return true; }
            if (!empty($item['id']) && !empty($item['text']) && (isset($item['authorMeta']) || isset($item['author']) || isset($item['channel']))) { return true; }
        }
        return false;
    }

    private static function tiktok_filter_fallback_items_by_author($items, $handles) {
        if (!is_array($items) || empty($items) || empty($handles)) { return is_array($items) ? $items : []; }
        $needles = [];
        foreach ((array) $handles as $handle) {
            $handle = strtolower(preg_replace('/[^A-Za-z0-9_.]/', '', (string) $handle));
            if ($handle !== '') {
                $needles[] = $handle;
                $needles[] = '@' . $handle;
                $needles[] = '/@' . $handle . '/';
            }
        }
        $matched = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $blob = self::tiktok_item_author_blob($item);
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($blob, $needle) !== false) {
                    $matched[] = $item;
                    continue 2;
                }
            }
        }
        // If the actor omitted author fields completely, do not destroy the dataset.
        // Normal relevance filters will still classify/noise-control those rows.
        return !empty($matched) ? array_values($matched) : array_values($items);
    }

    private static function tiktok_should_try_profile_fallback($items, $src, $is_profile_url_mode, $is_mixed_url_mode, $is_post_url_mode) {
        if ($is_post_url_mode || (! $is_profile_url_mode && ! $is_mixed_url_mode)) { return false; }
        $handles = self::tiktok_profile_handles_from_request($src);
        if (empty($handles)) { return false; }
        return !self::tiktok_has_video_items($items);
    }

    private static function run_tiktok_profile_search_fallback($actor_slug, $src, $request_id) {
        $fallback_actor_slug = function_exists('ves_get_actor_slug') ? ves_get_actor_slug('tiktok_fallback', ['mode' => 'profile_search_fallback']) : '';
        if (trim((string) $fallback_actor_slug) !== '') { $actor_slug = $fallback_actor_slug; }
        $handles = self::tiktok_profile_handles_from_request($src);
        if (empty($handles)) {
            return new WP_Error('tiktok_no_profile_handle', 'No se pudo extraer el usuario del perfil TikTok.');
        }
        $src = is_array($src) ? $src : [];
        $requested = self::requested_result_limit($src);
        $limit = function_exists('ves_compute_actor_limit') ? ves_compute_actor_limit($requested, 'tiktok', $src) : max(20, $requested);
        $limit = max(20, min(100, (int) $limit));
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($src['country'] ?? 'None') : 'None';
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : '';
        $date_filter = function_exists('ves_resolve_date_filter_inputs')
            ? ves_resolve_date_filter_inputs($src)
            : ['preset' => sanitize_key((string) ($src['datePreset'] ?? 'any')), 'iso' => ''];
        $tiktok_date_code = function_exists('ves_tt_search_date_posted') ? ves_tt_search_date_posted($date_filter['preset'] ?? 'any') : '0';
        $tiktok_video_date_filter = function_exists('ves_tt_video_search_date_filter') ? ves_tt_video_search_date_filter($date_filter['preset'] ?? 'any') : 'ALL_TIME';

        $queries = [];
        foreach ($handles as $handle) {
            $handle = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $handle);
            if ($handle === '') { continue; }
            $queries[] = $handle;
            $queries[] = '@' . $handle;
        }
        $context = isset($src['searchContext']) ? sanitize_text_field(wp_unslash($src['searchContext'])) : (isset($src['context']) ? sanitize_text_field(wp_unslash($src['context'])) : '');
        if ($context !== '') { $queries[] = $context; }
        $queries = array_values(array_unique(array_filter($queries)));

        $input = [
            'resultsPerPage' => $limit,
            'searchQueries' => $queries,
            'searchSection' => '/video',
            'searchSorting' => '0',
            // v0.9.24.16: fallback is only used for profile feed misses;
            // use ES proxy hint when country is Any/None.
            'proxyCountryCode' => ($country !== '' && $country !== 'None') ? $country : 'ES',
            'downloadSubtitlesOptions' => 'NEVER_DOWNLOAD_SUBTITLES',
            'shouldDownloadVideos' => false,
            'shouldDownloadCovers' => false,
            'shouldDownloadSlideshowImages' => false,
            'shouldDownloadAvatars' => false,
            'shouldDownloadMusicCovers' => false,
        ];
        if ($tiktok_date_code !== '0') { $input['searchDatePosted'] = $tiktok_date_code; }
        if ($tiktok_video_date_filter !== 'ALL_TIME') { $input['videoSearchDateFilter'] = $tiktok_video_date_filter; }

        $url = ves_make_run_url($actor_slug, ves_prepare_run_options());
        self::log('tiktok_profile_fallback_dispatch', 'TikTok profile feed was empty; trying search-based author fallback.', [
            'request_id' => $request_id,
            'actor_slug' => $actor_slug,
            'handles' => $handles,
            'summary' => self::summarize_input('tiktok', $input, $src),
        ]);
        $response = VES_Apify_Client::request('POST', $url, wp_json_encode($input));
        if (is_wp_error($response)) { return $response; }
        $run_body = $response['body'];
        $status = strtoupper((string) ($run_body['data']['status'] ?? ''));
        if ($status !== 'SUCCEEDED') {
            return new WP_Error('tiktok_fallback_not_ready', 'El fallback por búsqueda de autor no terminó a tiempo.', ['status' => $status, 'run_id' => $run_body['data']['id'] ?? '']);
        }
        $run_id = (string) ($run_body['data']['id'] ?? '');
        $items = VES_Apify_Client::fetch_items($run_id, self::dataset_fetch_limit('tiktok', $src, false));
        if (is_wp_error($items)) { return $items; }
        if (function_exists('ves_preprocess_tiktok_items')) { $items = ves_preprocess_tiktok_items($items); }
        if (function_exists('ves_strip_actor_error_items')) { $items = ves_strip_actor_error_items($items); }
        if (function_exists('ves_strip_unusable_items')) { $items = ves_strip_unusable_items('tiktok', $items); }
        $items = self::tiktok_filter_fallback_items_by_author($items, $handles);
        return [
            'run_body' => $run_body,
            'items' => is_array($items) ? array_values($items) : [],
            'run_id' => $run_id,
            'handles' => $handles,
            'input' => $input,
        ];
    }


    private static function maybe_relax_empty_filtered_results($platform, $candidate_items, $src, $requested_limit, $is_post_url_mode) {
        if ($is_post_url_mode || !is_array($candidate_items) || empty($candidate_items)) {
            return [[], false];
        }

        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        $src['platform'] = $platform;

        $items = $candidate_items;
        if (function_exists('ves_enrich_items_for_display')) {
            $items = ves_enrich_items_for_display($platform, $items, $src);
        }
        if (function_exists('ves_dedupe_items_by_identity')) {
            $items = ves_dedupe_items_by_identity($items);
        }
        if (function_exists('ves_filter_and_rank_items_by_search_relevance')) {
            list($ranked, $dropped, $used) = ves_filter_and_rank_items_by_search_relevance($items, $src, $platform);
            if ($used && !empty($ranked)) {
                $items = $ranked;
            }
        }
        if (function_exists('ves_apply_requested_filters')) {
            $items = ves_apply_requested_filters($items, $src);
        }
        if (function_exists('ves_sort_items_by_user_priority')) {
            $items = ves_sort_items_by_user_priority($items, $src, $platform);
        } elseif (function_exists('ves_sort_items_by_relevance_desc')) {
            $items = ves_sort_items_by_relevance_desc($items);
        }

        $requested_limit = function_exists('ves_clean_int')
            ? ves_clean_int($requested_limit, 1, function_exists('ves_max_items') ? ves_max_items() : 200)
            : (int) $requested_limit;
        if ($requested_limit > 0 && count($items) > $requested_limit) {
            $items = array_slice($items, 0, $requested_limit);
        }

        return [array_values($items), !empty($items)];
    }


    private static function result_identity_key($item) {
        if (!is_array($item)) {
            return '';
        }
        $keys = [
            'ves_url', 'url', 'link', 'webVideoUrl', 'videoUrl', 'shortCode', 'id', 'videoId', 'tweetId', 'postId', 'pk', 'code', 'ad_archive_id'
        ];
        foreach ($keys as $key) {
            $value = function_exists('ves_arr_get') ? ves_arr_get($item, $key, '') : ($item[$key] ?? '');
            if (is_scalar($value) && trim((string) $value) !== '') {
                return strtolower($key . ':' . trim((string) $value));
            }
        }
        return md5((string) wp_json_encode($item));
    }

    private static function ensure_social_relevance_metadata($item, $platform, $src = []) {
        $item = is_array($item) ? $item : [];
        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        if (!empty($item['ves_relevance_class'])) {
            return $item;
        }
        if (function_exists('ves_requested_search_context') && function_exists('ves_score_item_search_relevance')) {
            $ctx = ves_requested_search_context($src);
            list($score, $reasons) = ves_score_item_search_relevance($item, $ctx, $platform);
            $class = function_exists('ves_classify_social_item_relevance') ? ves_classify_social_item_relevance($item, $ctx, $platform, $score, $reasons) : 'weak_match';
            $item['ves_relevance_score'] = $score;
            $item['ves_relevance_reasons'] = $reasons;
            $item['ves_relevance_class'] = $class;
            $item['ves_relevance_label'] = function_exists('ves_social_relevance_label') ? ves_social_relevance_label($class) : $class;
            $item['ves_relevance_explanation'] = $item['ves_relevance_label'] . (!empty($reasons) ? ' · ' . implode(', ', array_slice($reasons, 0, 3)) : '');
        }
        return $item;
    }

    private static function relevance_bucket($item) {
        $class = sanitize_key((string) ($item['ves_relevance_class'] ?? ''));
        if (in_array($class, ['source_profile_match', 'source_url_result', 'source_result', 'direct_brand_match', 'category_relevant'], true)) { return 'best_candidate'; }
        if (in_array($class, ['adjacent_context', 'weak_match'], true)) { return 'adjacent'; }
        if (in_array($class, ['wrong_language', 'wrong_market', 'false_friend', 'below_metric_threshold', 'missing_semantic_match', 'noise'], true)) { return 'discarded'; }
        $score = (float) ($item['ves_relevance_score'] ?? 0);
        if ($score >= 6.0) { return 'best_candidate'; }
        if ($score >= 2.0) { return 'adjacent'; }
        return 'discarded';
    }


    private static function locale_mismatch_flags($item, $requested_language, $requested_country) {
        $item = is_array($item) ? $item : [];
        $raw = isset($item['raw']) && is_array($item['raw']) ? $item['raw'] : $item;
        $requested_language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($requested_language) : sanitize_key((string) $requested_language);
        $requested_country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($requested_country) : strtoupper((string) $requested_country);
        $language_mismatch = false;
        $country_mismatch = false;

        if ($requested_language !== '') {
            $explicit_lang = function_exists('ves_item_language') ? ves_item_language($raw) : '';
            if ($explicit_lang !== '' && $explicit_lang !== $requested_language) {
                $language_mismatch = true;
            } elseif (function_exists('ves_extract_item_text_blob') && function_exists('ves_detect_text_language')) {
                $blob = ves_extract_item_text_blob($item);
                if ($blob === '' && $raw !== $item) { $blob = ves_extract_item_text_blob($raw); }
                $detected = $blob !== '' ? ves_detect_text_language($blob) : '';
                if ($detected !== '' && $detected !== $requested_language) { $language_mismatch = true; }
            }
        }

        if ($requested_country !== '' && $requested_country !== 'None' && function_exists('ves_extract_item_country')) {
            $item_country = ves_extract_item_country($raw);
            if ($item_country !== '' && strtoupper((string) $item_country) !== strtoupper((string) $requested_country)) {
                $country_mismatch = true;
            }
        }

        return [$language_mismatch, $country_mismatch];
    }

    private static function annotate_locale_adjacent_items($items, $requested_language, $requested_country, &$language_mismatch_count, &$country_mismatch_count) {
        $language_mismatch_count = 0;
        $country_mismatch_count = 0;
        if (!is_array($items) || empty($items)) { return is_array($items) ? $items : []; }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            list($lang_mismatch, $country_mismatch) = self::locale_mismatch_flags($item, $requested_language, $requested_country);
            if ($lang_mismatch) { $language_mismatch_count++; }
            if ($country_mismatch) { $country_mismatch_count++; }
            if ($lang_mismatch || $country_mismatch) {
                $item['ves_locale_match_type'] = 'adjacent_match';
                $item['locale_match_type'] = 'adjacent_match';
                $item['evidence_quality'] = 'limited';
                $item['evidence_quality_note'] = 'Adjacent signal: language or market differs from selected filters.';
                $item['locale_mismatch_reason'] = trim(($lang_mismatch ? 'language_mismatch ' : '') . ($country_mismatch ? 'country_mismatch' : ''));
            } else {
                $item['ves_locale_match_type'] = 'direct_match';
                $item['locale_match_type'] = 'direct_match';
            }
            $out[] = $item;
        }
        return array_values($out);
    }

    private static function discovery_metric_threshold($src = []) {
        $src = is_array($src) ? $src : [];
        if (function_exists('ves_requested_min_metric')) {
            return (int) ves_requested_min_metric($src);
        }
        if (isset($src['minViews'])) {
            return function_exists('ves_clean_int') ? ves_clean_int($src['minViews'], 0, null) : max(0, (int) $src['minViews']);
        }
        if (isset($src['minLikes'])) {
            return function_exists('ves_clean_int') ? ves_clean_int($src['minLikes'], 0, null) : max(0, (int) $src['minLikes']);
        }
        return 0;
    }

    private static function apply_discovery_metric_gate($items, $platform, $src = [], &$dropped_by_metric = 0) {
        $items = is_array($items) ? array_values($items) : [];
        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        $src['platform'] = $platform;
        $dropped_by_metric = 0;

        $threshold = self::discovery_metric_threshold($src);
        if ($threshold <= 0 || (function_exists('ves_is_post_url_mode') && ves_is_post_url_mode($src))) {
            return $items;
        }

        $filtered = function_exists('ves_apply_requested_filters') ? ves_apply_requested_filters($items, $src) : $items;
        $dropped_by_metric = max(0, count($items) - count($filtered));
        return array_values($filtered);
    }

    private static function should_bypass_discovery_relevance_gate($platform, $src = []) {
        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        $mode = sanitize_key((string) ($src['mode'] ?? ''));

        if ($mode === 'urls') {
            return true;
        }
        if (function_exists('ves_is_any_url_mode') && ves_is_any_url_mode($src)) {
            return true;
        }

        $targets_raw = isset($src['targets']) ? wp_unslash($src['targets']) : '';
        foreach (preg_split('/\r\n|\r|\n/', (string) $targets_raw) as $line) {
            $line = trim((string) $line);
            if ($line !== '' && preg_match('~^https?://~i', $line)) {
                return true;
            }
        }

        // If the request has no semantic query at all, there is no fair basis for
        // classifying returned posts as noise. This happens in profile scraping:
        // the source itself is the objective, so source-matched posts must show.
        if (function_exists('ves_requested_search_context')) {
            $ctx = ves_requested_search_context($src);
            $raw_context = trim((string) ($src['searchContext'] ?? ($src['context'] ?? '')));
            // `context_tokens` can be auto-filled from language/country (es, Spain,
            // Madrid, etc.). Those are not a semantic query and must not turn a
            // profile scrape into a relevance/noise gate.
            $has_terms = !empty($ctx['phrases']) || !empty($ctx['tokens']) || ($raw_context !== '' && !empty($ctx['context_tokens']));
            return !$has_terms;
        }

        return false;
    }

    private static function mark_source_result_metadata($item, $platform, $src = []) {
        $item = is_array($item) ? $item : [];
        if (empty($item['ves_relevance_class'])) {
            $item['ves_relevance_score'] = 6.0;
            $item['ves_relevance_reasons'] = ['source_profile_or_url_result'];
            $item['ves_relevance_class'] = 'source_profile_match';
            $item['ves_relevance_label'] = 'Resultado del perfil/URL solicitado';
            $item['ves_relevance_explanation'] = 'Resultado del perfil/URL solicitado · sin filtro semántico aplicado';
        }
        return $item;
    }

    private static function attach_discovery_sections(&$formatted, $best_items, $candidate_items, $platform, $max_other_items = 30, $src = []) {
        $best_items = is_array($best_items) ? array_values($best_items) : [];
        $candidate_items = is_array($candidate_items) ? array_values($candidate_items) : [];
        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        $max_other_items = max(0, min(60, (int) $max_other_items));

        // v0.9.24.53: sections can receive a broader candidate pool for diagnostics.
        // Never let those candidates bypass the user's hard metric threshold.
        $section_metric_drops = 0;
        $candidate_items = self::apply_discovery_metric_gate($candidate_items, $platform, $src, $section_metric_drops);
        $best_metric_drops = 0;
        $best_items = self::apply_discovery_metric_gate($best_items, $platform, $src, $best_metric_drops);

        if (empty($candidate_items)) {
            $candidate_items = $best_items;
        }

        if (self::should_bypass_discovery_relevance_gate($platform, $src)) {
            $best_marked = [];
            $best_keys = [];
            foreach ($best_items as $item) {
                if (!is_array($item)) { continue; }
                $item = self::mark_source_result_metadata($item, $platform, $src);
                $item['ves_result_bucket'] = 'best';
                $item['ves_result_bucket_label'] = 'Coincidencia directa';
                $best_marked[] = $item;
                $key = self::result_identity_key($item);
                if ($key !== '') { $best_keys[$key] = true; }
            }

            $discarded_items = [];
            foreach ($candidate_items as $item) {
                if (!is_array($item)) { continue; }
                $key = self::result_identity_key($item);
                if ($key !== '' && isset($best_keys[$key])) { continue; }
                $item = self::mark_source_result_metadata($item, $platform, $src);
                $item['ves_result_bucket'] = 'discarded';
                $item['ves_result_bucket_label'] = 'Filtrado';
                $discarded_items[] = $item;
            }

            $formatted['best_match_items'] = array_values($best_marked);
            $formatted['other_items'] = [];
            $formatted['discarded_items'] = array_values(array_slice($discarded_items, 0, 120));
            $formatted['best_match_count'] = count($formatted['best_match_items']);
            $formatted['other_items_count'] = 0;
            $formatted['discarded_items_count'] = count($discarded_items);
            $formatted['all_items_count'] = count($formatted['best_match_items']);
            if (!empty($formatted['discarded_items'])) { $formatted['discarded_results_available'] = true; }
            $formatted['relevance_gate_bypassed'] = true;
            return;
        }

        $pre_discarded_items = [];
        $filtered_best_items = [];
        foreach ($best_items as $item) {
            if (!is_array($item)) { continue; }
            $item = self::ensure_social_relevance_metadata($item, $platform, $src);
            $initial_bucket = self::relevance_bucket($item);
            $direct_hint = strtolower(wp_json_encode([
                $item['ves_relevance_class'] ?? '',
                $item['ves_relevance_label'] ?? '',
                $item['ves_relevance_reasons'] ?? [],
                $item['ves_relevance_explanation'] ?? '',
            ], JSON_UNESCAPED_UNICODE));
            $is_direct_signal = (bool) preg_match('/direct|brand|source_profile|coincidencia|marca/', $direct_hint);
            if ($initial_bucket === 'discarded' && ! $is_direct_signal) {
                $item['ves_result_bucket'] = 'discarded';
                $item['ves_result_bucket_label'] = 'Descartado / ruido';
                $pre_discarded_items[] = $item;
                continue;
            }
            $item['ves_result_bucket'] = 'best';
            $item['ves_result_bucket_label'] = 'Coincidencia directa';
            $filtered_best_items[] = $item;
        }
        $best_items = $filtered_best_items;

        $best_keys = [];
        foreach ($best_items as $item) {
            $key = self::result_identity_key($item);
            if ($key !== '') { $best_keys[$key] = true; }
        }

        $other_items = [];
        $discarded_items = $pre_discarded_items;
        $seen_other = [];
        $seen_discarded = [];
        foreach ($pre_discarded_items as $discarded_item) {
            $discarded_key = self::result_identity_key($discarded_item);
            if ($discarded_key !== '') { $seen_discarded[$discarded_key] = true; }
        }
        foreach ($candidate_items as $item) {
            if (!is_array($item)) { continue; }
            $item = self::ensure_social_relevance_metadata($item, $platform, $src);
            $key = self::result_identity_key($item);
            if ($key !== '' && isset($best_keys[$key])) { continue; }
            $bucket = self::relevance_bucket($item);

            // Promote clear direct/category matches that post-filters missed, so real
            // brand posts do not appear as zero Best Match while the cards are visible.
            if ($bucket === 'best_candidate' && count($best_items) < max(1, self::requested_result_limit($src))) {
                if ($key === '' || !isset($best_keys[$key])) {
                    $item['ves_result_bucket'] = 'best';
                    $item['ves_result_bucket_label'] = 'Coincidencia directa';
                    $best_items[] = $item;
                    if ($key !== '') { $best_keys[$key] = true; }
                    continue;
                }
            }

            if ($bucket === 'discarded') {
                $direct_hint = strtolower(wp_json_encode([
                    $item['ves_relevance_class'] ?? '',
                    $item['ves_relevance_label'] ?? '',
                    $item['ves_relevance_reasons'] ?? [],
                    $item['ves_relevance_explanation'] ?? '',
                ], JSON_UNESCAPED_UNICODE));
                if ((bool) preg_match('/direct|brand|source_profile|coincidencia|marca/', $direct_hint)) {
                    $item['ves_result_bucket'] = 'best';
                    $item['ves_result_bucket_label'] = 'Coincidencia directa';
                    $best_items[] = $item;
                    if ($key !== '') { $best_keys[$key] = true; }
                    continue;
                }
                if ($key !== '' && isset($seen_discarded[$key])) { continue; }
                if ($key !== '') { $seen_discarded[$key] = true; }
                $item['ves_result_bucket'] = 'discarded';
                $item['ves_result_bucket_label'] = 'Descartado / ruido';
                $discarded_items[] = $item;
                continue;
            }

            if ($key !== '' && isset($seen_other[$key])) { continue; }
            if ($key !== '') { $seen_other[$key] = true; }
            $item['ves_result_bucket'] = 'other';
            $item['ves_result_bucket_label'] = 'Relevante / adyacente';
            if ($max_other_items <= 0 || count($other_items) < $max_other_items) {
                $other_items[] = $item;
            }
        }

        $formatted['best_match_items'] = array_values($best_items);
        $formatted['other_items'] = array_values(array_slice($other_items, 0, $max_other_items));
        $formatted['discarded_items'] = array_values(array_slice($discarded_items, 0, 120));
        $formatted['best_match_count'] = count($formatted['best_match_items']);
        $formatted['other_items_count'] = count($formatted['other_items']);
        $formatted['discarded_items_count'] = count($discarded_items);
        $formatted['all_items_count'] = count($formatted['best_match_items']) + count($formatted['other_items']);
        if (!empty($formatted['other_items'])) { $formatted['secondary_results_available'] = true; }
        if (!empty($formatted['discarded_items'])) { $formatted['discarded_results_available'] = true; }
    }

    /**
     * v0.9.24.56 — final response safety gate.
     * All earlier filters should already have removed items below locked constraints,
     * but continuation merges, cached branches and future section builders can still
     * reintroduce stale candidate pools. Before any response is saved or returned,
     * re-run the hard metric gate across every display bucket and recompute counts.
     */
    private static function finalize_discovery_response_gates(&$formatted, $platform, $src = []) {
        if (!is_array($formatted)) { return; }
        $platform = sanitize_key((string) $platform);
        $src = is_array($src) ? $src : [];
        $section_keys = ['items', 'best_match_items', 'other_items', 'discarded_items'];
        $final_metric_drops = 0;

        foreach ($section_keys as $key) {
            if (!isset($formatted[$key]) || !is_array($formatted[$key])) { continue; }
            $drops = 0;
            $formatted[$key] = self::apply_discovery_metric_gate($formatted[$key], $platform, $src, $drops);
            $final_metric_drops += (int) $drops;
        }

        // Remove duplicates across display buckets with priority: best -> other -> discarded.
        $seen = [];
        foreach (['best_match_items', 'other_items', 'discarded_items'] as $key) {
            if (empty($formatted[$key]) || !is_array($formatted[$key])) { continue; }
            $deduped = [];
            foreach ($formatted[$key] as $item) {
                if (!is_array($item)) { continue; }
                $identity = self::result_identity_key($item);
                if ($identity !== '' && isset($seen[$identity])) { continue; }
                if ($identity !== '') { $seen[$identity] = true; }
                $deduped[] = $item;
            }
            $formatted[$key] = array_values($deduped);
        }

        $best_count = isset($formatted['best_match_items']) && is_array($formatted['best_match_items']) ? count($formatted['best_match_items']) : 0;
        $other_count = isset($formatted['other_items']) && is_array($formatted['other_items']) ? count($formatted['other_items']) : 0;
        $discarded_count = isset($formatted['discarded_items']) && is_array($formatted['discarded_items']) ? count($formatted['discarded_items']) : 0;

        if ($best_count || $other_count || $discarded_count) {
            $formatted['best_match_count'] = $best_count;
            $formatted['other_items_count'] = $other_count;
            $formatted['discarded_items_count'] = $discarded_count;
            $formatted['all_items_count'] = $best_count + $other_count;
            $formatted['item_count_preview'] = $best_count + $other_count;
            $formatted['secondary_results_available'] = $other_count > 0;
            $formatted['discarded_results_available'] = $discarded_count > 0;
            // Keep JSON/current view aligned with visible cards.
            $formatted['items'] = array_values(array_merge($formatted['best_match_items'] ?? [], $formatted['other_items'] ?? []));
        } elseif (isset($formatted['items']) && is_array($formatted['items'])) {
            $formatted['item_count_preview'] = count($formatted['items']);
            $formatted['all_items_count'] = count($formatted['items']);
        }

        if ($final_metric_drops > 0) {
            $formatted['dropped_by_metric_final_gate'] = (int) (($formatted['dropped_by_metric_final_gate'] ?? 0) + $final_metric_drops);
            $formatted['dropped_by_metric'] = (int) (($formatted['dropped_by_metric'] ?? 0) + $final_metric_drops);
        }

        if (empty($formatted['locked_constraints']) || !is_array($formatted['locked_constraints'])) {
            $formatted['locked_constraints'] = self::request_locked_constraints($platform, $src);
        }
    }

    private static function extract_query_terms_from_input($platform, $input) {
        $platform = sanitize_key((string) $platform);
        $input = is_array($input) ? $input : [];
        $terms = [];
        foreach (['searchQueries', 'keywords', 'search', 'queries', 'searchTerms', 'searches', 'searchQuery', 'keyword'] as $key) {
            if (empty($input[$key])) { continue; }
            if (is_array($input[$key])) { $terms = array_merge($terms, array_map('strval', $input[$key])); }
            else { $terms = array_merge($terms, preg_split('/[,\n]+/u', (string) $input[$key])); }
        }
        foreach (['hashtags', 'directUrls', 'urls', 'startUrls'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $value) {
                $value = is_array($value) ? (string) ($value['url'] ?? '') : (string) $value;
                if (preg_match('#/tags/([^/]+)/?#i', $value, $m)) { $terms[] = rawurldecode($m[1]); }
                elseif ($key === 'hashtags') { $terms[] = ltrim($value, '#'); }
            }
        }
        $clean = [];
        foreach ($terms as $term) {
            $term = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $term)));
            if ($term !== '') { $clean[] = $term; }
        }
        return array_values(array_unique(array_slice($clean, 0, 20)));
    }

    private static function contextual_empty_result_message($platform, $input, $request = []) {
        $platform = sanitize_key((string) $platform);
        $input = is_array($input) ? $input : [];
        $request = is_array($request) ? $request : [];
        $mode = sanitize_key((string) ($request['mode'] ?? ($input['searchType'] ?? '')));

        if ($platform === 'semrush') {
            if ($mode === 'keyword_magic') {
                $keyword = sanitize_text_field((string) ($input['keyword'] ?? ''));
                $country = sanitize_key((string) ($input['country'] ?? ''));
                $word_count = preg_match_all('/[\p{L}\p{N}]+/u', $keyword, $m);
                if ($keyword !== '' && ($word_count > 6 || (class_exists('VES_Query_Planner') && VES_Query_Planner::looks_like_objective($keyword)))) {
                    return 'Semrush received an overlong or objective-like keyword. Use a short seed keyword such as "seo tools", "herramientas seo" or "ai seo tools"; keep business objective and search context for AI analysis only.';
                }
                return sprintf(
                    'Semrush Keyword Magic terminó sin filas para "%s"%s. Prueba una seed keyword más amplia, otro país/idioma o confirma que el actor de Semrush responde en tu cuenta Apify.',
                    $keyword !== '' ? $keyword : 'la keyword enviada',
                    $country !== '' ? ' en ' . strtoupper($country) : ''
                );
            }
            if ($mode === 'keyword_simple') {
                return 'Semrush Keywords Scraper terminó sin filas. La fuente puede requerir acceso adicional o devolver vacío para keywords demasiado específicas.';
            }
            if ($mode === 'top_websites') {
                return 'Semrush Top Websites terminó sin datos. Prueba otra industria/país o confirma que la fuente SEO tiene acceso activo.';
            }
            return 'Semrush Domain/SEO terminó sin datos. Revisa que el dominio/URL sea público, activa menos módulos o prueba con país/keyword diferentes.';
        }

        if ($platform === 'reddit') {
            if ($mode === 'urls') {
                return 'Reddit terminó sin posts para esas URLs. Si usaste una URL de subreddit, prueba "Both metadata and posts" o "Community metadata only" para comprobar que la comunidad existe.';
            }
            return 'Reddit terminó sin resultados. Prueba con sort=relevance/top, time=all, una comunidad más amplia o desactiva búsqueda de comments/users si no la necesitas.';
        }

        if ($platform === 'pinterest') {
            return 'Pinterest terminó sin pins. Prueba una keyword más amplia, usa URLs públicas de pin/board/perfil o cambia proxy. Si la fuente no tiene acceso activo, la ejecución puede fallar antes de llegar aquí.';
        }

        if ($platform === 'ahrefs') {
            $type = sanitize_key((string) ($input['searchType'] ?? $mode));
            return $type !== ''
                ? 'Ahrefs terminó sin datos para searchType "' . $type . '". Revisa dominio/keyword/país y que el tipo de búsqueda sea compatible con esos inputs.'
                : 'Ahrefs terminó sin datos. Revisa dominio, keyword, país y tipo de análisis.';
        }

        if ($platform === 'moz') {
            return 'MOZ terminó sin datos. Revisa que el dominio sea público y sin errores tipográficos; prueba solo el dominio raíz si usaste una URL con path.';
        }

        return '';
    }

    private static function build_query_diagnostics($platform, $input, $candidate_items, $accepted_items, $request = []) {
        $queries = self::extract_query_terms_from_input($platform, $input);
        if (empty($queries)) { return []; }
        $candidate_items = is_array($candidate_items) ? $candidate_items : [];
        $accepted_items = is_array($accepted_items) ? $accepted_items : [];
        $text_for = static function($item) use ($platform) {
            if (function_exists('ves_item_content_blob_for_relevance')) {
                return ves_item_content_blob_for_relevance($platform, $item) . ' ' . ves_item_author_blob_for_relevance($item) . ' ' . ves_item_url_blob_for_relevance($item);
            }
            return wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        };
        $rows = [];
        foreach ($queries as $query) {
            $q_norm = function_exists('ves_search_normalize_text') ? ves_search_normalize_text($query) : strtolower($query);
            $returned = 0; $accepted = 0; $reason_counts = [];
            foreach ($candidate_items as $item) {
                $item = is_array($item) ? self::ensure_social_relevance_metadata($item, $platform, $input) : $item;
                $blob = function_exists('ves_search_normalize_text') ? ves_search_normalize_text($text_for($item)) : strtolower((string) $text_for($item));
                if ($q_norm !== '' && strpos($blob, $q_norm) !== false) {
                    $returned++;
                    $class = sanitize_key((string) ($item['ves_relevance_class'] ?? 'unclassified'));
                    $reason_counts[$class] = (int) ($reason_counts[$class] ?? 0) + 1;
                }
            }
            foreach ($accepted_items as $item) {
                $item = is_array($item) ? self::ensure_social_relevance_metadata($item, $platform, $input) : $item;
                $blob = function_exists('ves_search_normalize_text') ? ves_search_normalize_text($text_for($item)) : strtolower((string) $text_for($item));
                if ($q_norm !== '' && strpos($blob, $q_norm) !== false) { $accepted++; }
            }
            arsort($reason_counts);
            $rows[] = [
                'query' => $query,
                'items_returned' => $returned,
                'items_accepted' => $accepted,
                'items_rejected' => max(0, $returned - $accepted),
                'main_rejection_reasons' => array_slice(array_keys($reason_counts), 0, 5),
            ];
        }
        return $rows;
    }

    private static function request_id() {
        try {
            return strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'ves-' . strtolower(wp_generate_password(12, false, false));
        }
    }

    private static function normalized_post() {
        if (is_array(self::$normalized_post_cache)) {
            return self::$normalized_post_cache;
        }
        $request = is_array($_POST) ? wp_unslash($_POST) : [];
        self::$normalized_post_cache = is_array($request) ? $request : [];
        return self::$normalized_post_cache;
    }

    private static function post_raw($key, $aliases = [], $default = null) {
        $request = self::normalized_post();
        $keys = array_merge([(string) $key], (array) $aliases);
        foreach ($keys as $candidate) {
            if (array_key_exists((string) $candidate, $request)) {
                return $request[(string) $candidate];
            }
        }
        return $default;
    }

    private static function post_text($key, $default = '', $max = 500, $aliases = []) {
        $value = sanitize_text_field((string) self::post_raw($key, $aliases, $default));
        if ($max > 0 && strlen($value) > $max) { $value = substr($value, 0, $max); }
        return $value;
    }

    private static function post_textarea($key, $default = '', $max = 2000, $aliases = []) {
        $value = sanitize_textarea_field((string) self::post_raw($key, $aliases, $default));
        if ($max > 0 && strlen($value) > $max) { $value = substr($value, 0, $max); }
        return $value;
    }

    private static function post_key($key, $default = '', $aliases = []) {
        return sanitize_key((string) self::post_raw($key, $aliases, $default));
    }

    private static function post_int($key, $default = 0, $min = 0, $max = PHP_INT_MAX, $aliases = []) {
        $raw = self::post_raw($key, $aliases, $default);
        $value = function_exists('ves_clean_int') ? ves_clean_int($raw, $min, $max) : (int) $raw;
        return max((int) $min, min((int) $max, (int) $value));
    }

    private static function post_bool($key, $default = false, $aliases = []) {
        $raw = self::post_raw($key, $aliases, $default ? '1' : '0');
        if (is_bool($raw)) { return $raw; }
        return in_array(strtolower((string) $raw), ['1','true','yes','on'], true);
    }

    private static function post_enum($key, $allowed, $default = '', $aliases = []) {
        $value = self::post_key($key, $default, $aliases);
        $allowed = array_map('sanitize_key', (array) $allowed);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function post_bundle_id() {
        return self::post_text('bundleId', '', 120, ['runId','bundle_id','run_id']);
    }

    private static function post_page_args($default_per_page = 20, $max_per_page = 100) {
        return [
            'page' => self::post_int('page', 1, 1, 9999),
            'per_page' => self::post_int('perPage', $default_per_page, 1, $max_per_page, ['per_page']),
        ];
    }

    private static function start_gate_scope_id() {
        if (is_user_logged_in()) {
            return 'u_' . get_current_user_id();
        }
        return 'ip_' . md5(function_exists('ves_get_ip') ? ves_get_ip() : '0.0.0.0');
    }

    private static function start_gate_key($action, $platform = '', $extra = '') {
        $action = sanitize_key((string) $action);
        $platform = sanitize_key((string) $platform);
        $extra = sanitize_text_field((string) $extra);
        $parts = ['ves_start_gate', self::start_gate_scope_id(), $action];
        if ($platform !== '') {
            $parts[] = $platform;
        }
        if ($extra !== '') {
            $parts[] = substr(md5($extra), 0, 12);
        }
        return implode('_', $parts);
    }

    /**
     * v0.9.30.1: deterministic fingerprint of a Trend Finder request used for the
     * concurrency start-gate. Normalizes the meaningful stable inputs (topic,
     * country, duration, reason, project) so that identical requests — regardless
     * of casing/whitespace — map to the same gate key, while distinct requests
     * that merely share a topic do not collide.
     */
    private static function trend_request_fingerprint($request) {
        $request = is_array($request) ? $request : [];
        $parts = [
            'topic'    => strtolower(trim((string) ($request['topic'] ?? ''))),
            'country'  => strtolower(trim((string) ($request['country'] ?? ''))),
            'duration' => strtolower(trim((string) ($request['duration'] ?? ''))),
            'reason'   => strtolower(trim((string) ($request['reason'] ?? ''))),
            'project'  => sanitize_text_field((string) ($request['project_id'] ?? ($request['projectId'] ?? ''))),
            'trend_type' => sanitize_key((string) ($request['trendType'] ?? ($request['mode'] ?? ''))),
        ];
        ksort($parts);
        return md5((string) wp_json_encode($parts));
    }

    private static function acquire_start_gate($key, $request_id, $source, $context = []) {
        $key = sanitize_key((string) $key);
        if ($key === '') {
            return '';
        }
        $existing = get_transient($key);
        if ($existing) {
            // v0.9.31.1: stale-lock auto-clear. The gate value now carries the acquiring
            // request id + acquire timestamp. If an existing gate is older than 75s (TTL is
            // 90s) it is almost certainly an orphan left by a fatal that did not release it,
            // so we clear it and let this request proceed instead of blocking for the full
            // TTL. Legacy scalar values (no timestamp) keep the original blocking behaviour.
            $gate_ts = is_array($existing) ? (int) ($existing['ts'] ?? 0) : 0;
            $gate_owner = is_array($existing) ? sanitize_text_field((string) ($existing['rid'] ?? '')) : '';
            $gate_age = $gate_ts > 0 ? max(0, time() - $gate_ts) : null;
            if ($gate_age !== null && $gate_age > 75) {
                self::release_start_gate($key);
                self::log('start_gate_stale_cleared', 'Cleared a stale start gate before re-dispatch.', [
                    'gate_key' => $key,
                    'gate_age_secs' => $gate_age,
                    'gate_owner_request_id' => $gate_owner,
                    'stage' => 'start_gate_stale_cleared',
                ]);
            } else {
                self::error(
                    'This exact dispatch plan is already running. Wait for the current run to finish or change the query/source plan.',
                    429,
                    $request_id,
                    $source,
                    array_merge((array) $context, [
                        'stage' => 'start_gate',
                        'gate_key' => $key,
                        'gate_age_secs' => $gate_age,
                        'gate_owner_request_id' => $gate_owner,
                        'gate_expires_in_secs' => $gate_age !== null ? max(0, 90 - $gate_age) : null,
                        'error_category' => 'duplicate_dispatch_blocked',
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ])
                );
            }
        }
        // Keep identical dispatch plans blocked only briefly. Live staging showed
        // that a fatal before provider dispatch could otherwise make the scraper
        // appear dead for 10 minutes even though no Apify request was attempted.
        set_transient($key, ['ts' => time(), 'rid' => $request_id], 90);
        self::remember_active_run_context([
            'start_gate_key' => $key,
            'stage' => 'start_gate_acquired',
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);
        return $key;
    }

    private static function release_start_gate($key) {
        $key = sanitize_key((string) $key);
        if ($key !== '') {
            delete_transient($key);
        }
    }


    private static function source_execution_option_key() {
        return 'ves_source_execution_index_v2';
    }

    private static function load_source_execution_index() {
        $index = get_option(self::source_execution_option_key(), []);
        return is_array($index) ? $index : [];
    }

    private static function save_source_execution_index($index) {
        $index = is_array($index) ? $index : [];
        $now = time();
        foreach ($index as $key => $entry) {
            $ts = isset($entry['updated_at']) ? (int) $entry['updated_at'] : 0;
            if ($ts > 0 && ($now - $ts) > DAY_IN_SECONDS) { unset($index[$key]); }
        }
        if (count($index) > 200) { $index = array_slice($index, -200, null, true); }
        update_option(self::source_execution_option_key(), $index, false);
    }

    private static function source_execution_key($module, $platform, $actor_slug, $input, $mode = '', $run_scope = '') {
        $module = sanitize_key((string) $module);
        $platform = sanitize_key((string) $platform);
        $actor_slug = sanitize_text_field((string) $actor_slug);
        $mode = sanitize_key((string) $mode);
        $run_scope = sanitize_text_field((string) $run_scope);
        $normalized_query_hash = md5((string) wp_json_encode($input));
        $base = implode('|', [get_current_user_id(), $run_scope, $module, $platform, $actor_slug, $normalized_query_hash, $mode]);
        return 'ves_src_' . substr(hash('sha256', $base), 0, 32);
    }

    private static function get_source_execution($key) {
        $key = sanitize_key((string) $key);
        if ($key === '') { return null; }
        $index = self::load_source_execution_index();
        return isset($index[$key]) && is_array($index[$key]) ? $index[$key] : null;
    }

    private static function remember_source_execution($key, $entry) {
        $key = sanitize_key((string) $key);
        if ($key === '') { return; }
        $index = self::load_source_execution_index();
        $entry = is_array($entry) ? $entry : [];
        $entry['updated_at'] = time();
        $index[$key] = array_merge(is_array($index[$key] ?? null) ? $index[$key] : [], $entry);
        self::save_source_execution_index($index);
    }

    private static function maybe_return_duplicate_source_execution($key, $request_id, $platform, $actor_slug) {
        $entry = self::get_source_execution($key);
        if (!$entry || empty($entry['provider_run_id'])) { return; }
        $run_id = sanitize_text_field((string) $entry['provider_run_id']);
        $status = strtoupper((string) ($entry['status'] ?? ''));
        if ($run_id === '') { return; }
        // v0.9.30.1: a terminal-failed prior run must not permanently block a valid
        // retry of the same request. If the recorded status is failed/aborted/timed-out
        // we drop the stale record, log the decision, and let the caller dispatch
        // afresh. Reuse only applies to runs that are still running or succeeded.
        $failed_terminal = $status !== '' && (
            in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'TIMED_OUT', 'ERROR', 'DISPATCH_ERROR'], true)
            || strpos($status, 'FAILED') !== false
        );
        if ($failed_terminal) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('duplicate_retry_allowed_after_failure', 'Previous provider run for this source key was terminal-failed; allowing a fresh retry dispatch instead of reusing it.', [
                    'source_execution_key' => $key,
                    'previous_provider_run_id' => $run_id,
                    'previous_status' => $status,
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                ]);
            }
            self::remember_source_execution($key, ['provider_run_id' => '', 'status' => $status . '_RETRYABLE']);
            return;
        }
        if (class_exists('VES_Admin')) {
            VES_Admin::record_diagnostic('duplicate_provider_dispatch_prevented', 'Existing provider run reused instead of dispatching a duplicate.', [
                'source_execution_key' => $key,
                'provider_run_id' => $run_id,
                'status' => $status,
                'platform' => $platform,
                'actor_slug' => $actor_slug,
            ]);
        }
        $run_body = null;
        if (class_exists('VES_Apify_Client') && method_exists('VES_Apify_Client', 'fetch_run')) {
            $fetched = VES_Apify_Client::fetch_run($run_id, 0);
            if (!is_wp_error($fetched) && is_array($fetched['body'] ?? null)) {
                $run_body = $fetched['body'];
                $status = strtoupper((string) ($run_body['data']['status'] ?? $status));
                self::remember_source_execution($key, ['status' => $status]);
            }
        }
        // v0.9.30.1: the live status may reveal the recorded run has since failed.
        // In that case do not return the failed run as a "reused" success — drop the
        // stale record and fall through so the caller dispatches a fresh retry.
        $live_failed_terminal = $status !== '' && (
            in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'TIMED_OUT', 'ERROR', 'DISPATCH_ERROR'], true)
            || strpos($status, 'FAILED') !== false
        );
        if ($live_failed_terminal) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('duplicate_retry_allowed_after_failure', 'Recorded provider run resolved to a terminal-failed state on live check; allowing a fresh retry dispatch.', [
                    'source_execution_key' => $key,
                    'previous_provider_run_id' => $run_id,
                    'previous_status' => $status,
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                ]);
            }
            self::remember_source_execution($key, ['provider_run_id' => '', 'status' => $status . '_RETRYABLE']);
            return;
        }
        // v0.9.30.2: provider mechanics (source_execution_key, provider_run_id,
        // actor_slug, raw run body) must NOT reach normal users. They are recorded
        // in admin diagnostics above. The response sent to normal users contains
        // only product-safe fields: status, message, charge and public run state.
        // run_id is retained because the frontend poller still needs it; see
        // KNOWN_LIMITATIONS.md — a public-safe run token is a remaining beta
        // blocker tracked for v0.10.0. Admin/debug sessions still receive the full
        // provider detail set for diagnostics.
        $public_response = [
            'success' => true,
            'duplicate_prevented' => true,
            'run_id' => $run_id,
            'status' => $status ?: 'REUSED',
            'platform' => $platform,
            'message' => 'Se reutilizó una ejecución existente; no se han reservado créditos ni lanzado otra colección.',
            'charge' => 0,
            'usage_ledger' => [
                'credits_charged_final' => 0,
                'credits_refunded_or_voided' => 0,
                'reason' => 'duplicate_provider_run_reused',
            ],
            'request_id' => $request_id,
        ];
        if (self::request_debug_enabled()) {
            $public_response['source_execution_key'] = $key;
            $public_response['provider_run_id'] = $run_id;
            $public_response['actor_slug'] = $actor_slug;
            $public_response['run'] = $run_body;
        }
        wp_send_json(self::public_payload($public_response));
        exit;
    }

    private static function enforce_provider_cost_limit_before_dispatch($request_id, $platform, $actor_slug, $source_count = 1, $heavy_count = null) {
        if (!class_exists('VES_Access_Control') || !class_exists('VES_Config')) { return; }
        $limit = VES_Access_Control::get_plan_limit(get_current_user_id(), 'max_provider_cost_per_run_usd');
        if ($limit === null || (float) $limit <= 0) { return; }
        if ($heavy_count === null) {
            $heavy_count = preg_match('/instagram|tiktok|youtube|facebook|linkedin|crawler|scraper/i', (string) $platform . ' ' . (string) $actor_slug) ? 1 : 0;
        }
        $estimated = method_exists('VES_Config', 'apify_estimate_provider_cost') ? (float) VES_Config::apify_estimate_provider_cost(max(1, (int) $source_count), max(0, (int) $heavy_count)) : 0.0;
        if ($estimated > (float) $limit) {
            self::error('La ejecución supera el límite de coste de proveedor configurado para tu plan. Reduce fuentes/resultados o pide al administrador ampliar el límite.', 402, $request_id, 'provider_cost_guard', [
                'estimated_provider_cost_usd' => $estimated,
                'max_provider_cost_per_run_usd' => (float) $limit,
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'no_provider_call' => true,
                'no_credit_reservation' => true,
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => false,
                'error_category' => 'provider_cost_guard_blocked',
            ]);
        }
    }

    // ── v0.9.24.4 continuation helpers ────────────────────────────────────
    private static function continuation_key($search_fingerprint) {
        return 'ves_cont_' . md5((string) $search_fingerprint);
    }

    private static function search_fingerprint($platform, $src) {
        // v0.9.24.9: include user_id in fingerprint so two users with identical
        // search params get different continuation transient keys (prevents data leak).
        $fields = ['targets', 'platform', 'mode', 'context', 'searchContext', 'country', 'language', 'datePreset', 'dateFromIso', 'minViews', 'min_views', 'minimumViews', 'minimum_views', 'minMetric', 'min_metric', 'minimumMetric', 'minimum_metric', 'minLikes', 'min_likes', 'limit', 'sortPriority'];
        $snap = [];
        foreach ($fields as $f) {
            if (isset($src[$f]) && $src[$f] !== '') {
                $snap[$f] = $src[$f];
            }
        }
        $snap['_platform'] = $platform;
        $snap['_user_id'] = get_current_user_id();
        return md5(serialize($snap));
    }

    private static function load_continuation_items($token) {
        $key = self::continuation_key($token);
        $stored = get_transient($key);
        return is_array($stored) ? $stored : [];
    }

    private static function save_continuation_items($token, array $items) {
        $key = self::continuation_key($token);
        // Keep continuation data for 30 minutes — enough to run 2-3 follow-up scrapes.
        set_transient($key, array_values($items), 30 * MINUTE_IN_SECONDS);
    }

    private static function clear_continuation_items($token) {
        delete_transient(self::continuation_key($token));
    }

    private static function log($source, $message, $context = []) {
        if (!class_exists('VES_Admin')) {
            return;
        }
        try {
            VES_Admin::record_diagnostic($source, $message, $context);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Vietnam Estudio Social] diagnostic logging failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * v0.9.30.7 Phase 0: product-safe support code for normal users.
     * Derived from request_id but not reversible or provider-specific.
     */
    private static function public_support_code($request_id) {
        $seed = sanitize_text_field((string) $request_id);
        if ($seed === '') {
            // v0.9.31.5 BUG-03: empty-seed hardening. Add wp_rand entropy so the
            // hash cannot collapse to all-zero base36 (which produced FI-000000
            // in the field). Also raise an admin diagnostic so the upstream
            // path missing a request_id can be tracked.
            $seed = (string) microtime(true) . '|' . wp_rand(100000, 999999);
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic(
                    'support_code_empty_seed',
                    'public_support_code() called with empty request_id.',
                    ['seed_used' => $seed]
                );
            }
        }
        $hex = strtoupper(substr(hash('sha256', $seed . '|future-island-diagnostics'), 0, 10));
        $num = base_convert($hex, 16, 36);
        $num = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $num));
        // v0.9.31.5 BUG-03: belt-and-braces guard against an all-zero result
        // (empty string or any number of leading zeros after sanitisation).
        if ($num === '' || ltrim($num, '0') === '') {
            $num = strtoupper(substr(base_convert((string) wp_rand(10000000, 99999999), 10, 36) . 'X', 0, 6));
        }
        return 'FI-' . substr(str_pad($num, 6, '0'), 0, 6);
    }

    private static function classify_error_category($source, $message = '', $status = 500, $context = []) {
        $source = sanitize_key((string) $source);
        $status = (int) $status;
        $context = is_array($context) ? $context : [];
        if (!empty($context['error_category'])) { return sanitize_key((string) $context['error_category']); }
        $stage = sanitize_key((string) ($context['stage'] ?? ''));
        $code = sanitize_key((string) ($context['error_code'] ?? ''));
        $state = strtolower((string) ($context['provider_state'] ?? ''));
        $haystack = strtolower((string) $message . ' ' . $source . ' ' . $stage . ' ' . $code . ' ' . $state);

        // v0.9.30.17: only caught/shutdown PHP fatals should be re-bucketed
        // using provider_call_attempted/provider_run_created. In v0.9.30.16 this
        // check ran too early and swallowed real provider categories such as
        // provider_auth_error, provider_run_id_missing, dataset_fetch_failed, etc.
        $is_fatal_context = in_array($source, ['ajax_fatal', 'ajax_start_fatal', 'ajax_shutdown_fatal'], true)
            || $stage === 'caught_throwable'
            || $stage === 'shutdown_fatal'
            || strpos($haystack, 'caught_throwable') !== false
            || strpos($haystack, 'shutdown_fatal') !== false
            || strpos($haystack, 'ajax_shutdown_fatal') !== false;
        if ($is_fatal_context) {
            if (!empty($context['provider_run_created'])) { return 'post_provider_run_fatal'; }
            if (!empty($context['provider_call_attempted'])) { return 'post_dispatch_fatal'; }
            return 'ajax_fatal';
        }
        if (strpos($haystack, 'provider access') !== false || strpos($haystack, 'source access') !== false) { return 'provider_access_denied'; }
        $backend_token_missing = $stage === 'missing_backend_token'
            || strpos($haystack, 'missing_backend_token') !== false
            || strpos($haystack, 'missing_apify_token') !== false
            || strpos($haystack, 'backend token') !== false
            || strpos($haystack, 'apify token') !== false
            || strpos($haystack, 'api token') !== false
            || strpos($haystack, 'api key') !== false;
        if ($backend_token_missing) { return 'missing_backend_token'; }
        if (strpos($haystack, 'public_run_token') !== false || strpos($haystack, 'run_owner') !== false) { return 'module_access_denied'; }
        if (strpos($source, 'auth') !== false || strpos($haystack, 'login_required') !== false || strpos($haystack, 'nonce') !== false) { return 'module_access_denied'; }
        if (strpos($haystack, 'platform') !== false && (strpos($haystack, 'invalid') !== false || strpos($haystack, 'no válida') !== false)) { return 'invalid_platform'; }
        if ($source === 'ajax_validation' || strpos($haystack, 'invalid_input') !== false) {
            if (strpos($haystack, 'actor_payload_contract') !== false || strpos($haystack, 'contract') !== false) { return 'actor_payload_contract_failed'; }
            return 'invalid_input';
        }
        if ($source === 'usage_billing' || strpos($haystack, 'reserve_usage') !== false || strpos($haystack, 'settle_usage') !== false || strpos($haystack, 'post_usage') !== false
            || strpos($haystack, 'usage_reservation_failed') !== false
            || strpos($haystack, 'credit_reservation_failed') !== false
            || strpos($haystack, 'reserva de creditos') !== false
            || strpos($haystack, 'reserva de créditos') !== false
            || strpos($haystack, 'credit reservation') !== false) { return 'usage_reservation_failed'; }
        if (strpos($source, 'provider_cost') !== false || strpos($haystack, 'provider_cost') !== false || ((int) $status === 402 && strpos($haystack, 'credit') === false && strpos($haystack, 'usage') === false)) { return 'provider_cost_guard_blocked'; }
        if (strpos($source, 'slot') !== false || strpos($haystack, 'concurrency') !== false || strpos($haystack, 'limit') !== false || strpos($haystack, 'duplicate_dispatch') !== false) {
            return strpos($haystack, 'duplicate') !== false ? 'duplicate_dispatch_blocked' : 'dispatch_slot_limit';
        }
        $is_provider_runtime_error = $source === 'actor_dispatch_error'
            || strpos($source, 'actor_poll') !== false
            || strpos($source, 'actor_abort') !== false
            || strpos($source, 'google_actor') !== false
            || strpos($source, 'google_items') !== false
            || strpos($source, 'google_actor_items') !== false
            || strpos($source, 'dispatch') !== false
            || strpos($haystack, 'apify_') !== false
            || strpos($haystack, 'ves_invalid_json') !== false;
        if ($is_provider_runtime_error) {
            // v0.9.31.1: actor rental / paid-plan requirement must be detected BEFORE the
            // generic 401/403 buckets. Apify returns 403 for "rent a paid actor" /
            // "requiere rental/plan activo", which previously fell into provider_access_denied
            // ("not fully configured") and hid the real cause (the actor needs an active
            // rental/subscription on the provider side, not a token/config fix).
            $rental_required = strpos($haystack, 'rental') !== false
                || strpos($haystack, 'rent a paid') !== false
                || strpos($haystack, 'paid actor') !== false
                || strpos($haystack, 'free trial has expired') !== false
                || strpos($haystack, 'requiere rental') !== false
                || strpos($haystack, 'requiere plan') !== false
                || strpos($haystack, 'apify_actor_rental_required') !== false;
            if ($rental_required) { return 'provider_actor_rental_required'; }
            if (strpos($haystack, 'transport') !== false) { return 'provider_transport_error'; }
            if ((int) $status === 429 || strpos($haystack, 'rate') !== false || strpos($haystack, 'waiting_retry') !== false) { return 'provider_rate_limit'; }
            if ((int) $status === 401 || strpos($haystack, 'auth') !== false || strpos($haystack, 'unauthorized') !== false) { return 'provider_auth_error'; }
            if ((int) $status === 403 || strpos($haystack, 'forbidden') !== false) { return 'provider_access_denied'; }
            if (strpos($haystack, 'permission') !== false || strpos($haystack, 'approval') !== false) { return 'provider_permission_required'; }
            if (strpos($haystack, 'invalid_json') !== false || strpos($haystack, 'ves_invalid_json') !== false) { return 'provider_invalid_json'; }
            if ($stage === 'missing_run_id' || $stage === 'provider_run_id_missing' || strpos($haystack, 'missing_run_id') !== false || strpos($haystack, 'provider_run_id_missing') !== false || strpos($haystack, 'identificador') !== false || strpos($haystack, 'process identifier') !== false) { return 'provider_run_id_missing'; }
            // v0.9.31.1: a 400 (or an explicit invalid-input/payload-rejected marker) means the
            // provider rejected the request payload/schema. Classify distinctly so the user sees
            // an actionable "input invalid" message and admins know it is NOT a transient/terminal
            // run failure (which would otherwise be matched by the broad 'failed' check below,
            // since the dispatch stage string itself contains "failed").
            if ((int) $status === 400
                || strpos($haystack, 'apify_invalid_input') !== false
                || strpos($haystack, 'invalid_input') !== false
                || strpos($haystack, 'payload_rejected') !== false
                || strpos($haystack, 'bad request') !== false
                || strpos($haystack, 'schema') !== false) { return 'provider_payload_rejected'; }
            if (strpos($haystack, 'terminal') !== false || strpos($haystack, 'failed') !== false || strpos($haystack, 'aborted') !== false || strpos($haystack, 'timed') !== false) { return 'provider_terminal_failure'; }
            return ($status >= 400 && $status < 600) ? 'provider_http_error' : 'provider_transport_error';
        }
        if ($source === 'dataset_fetch_failed' || $stage === 'dataset_fetch_failed' || strpos($haystack, 'dataset_fetch_failed') !== false || strpos($haystack, 'fetch_items') !== false || strpos($haystack, 'could not be retrieved') !== false || strpos($haystack, 'results could not be retrieved') !== false) { return 'dataset_fetch_failed'; }
        if ($source === 'normalization_failed' || strpos($haystack, 'normalization') !== false || strpos($haystack, 'normalizer') !== false) { return 'normalization_failed'; }
        // v0.9.31.3: provider returned HTTP 200 with zero items (not an error, just empty).
        if ($stage === 'provider_result_empty' || strpos($haystack, 'provider_result_empty') !== false
            || (isset($context['item_count']) && (int) $context['item_count'] === 0 && (int) $status === 200)) {
            return 'provider_empty';
        }
        // v0.9.31.3: items were fetched but all removed by local filters (minViews, date, etc.).
        if ($stage === 'post_filter_empty' || strpos($haystack, 'local_filters_removed_all') !== false
            || (isset($context['discard_reason']) && strpos((string) $context['discard_reason'], 'filters') !== false
                && isset($context['pre_filter_count']) && (int) $context['pre_filter_count'] > 0
                && isset($context['post_filter_count']) && (int) $context['post_filter_count'] === 0)) {
            return 'local_filters_removed_all';
        }
        if ($status === 429) { return 'provider_rate_limit'; }
        if ($status === 401) { return 'provider_auth_error'; }
        if ($status === 403) { return 'provider_access_denied'; }
        return 'unknown_error';
    }

    private static function public_message_for_error_category($category) {
        $category = sanitize_key((string) $category);
        // v0.9.30.21: provider_actor_rental_required is NOT a configuration error —
        // the token is valid and the provider responded. It means the specific actor
        // requires an active rental or paid plan on the provider side. Give it a
        // distinct message so admins and operators know what to actually fix.
        if ($category === 'provider_actor_rental_required') {
            return 'Esta fuente no está disponible con la configuración actual. Pide a un administrador activar el actor o elegir otra fuente.';
        }
        $config = ['missing_backend_token','module_access_denied','provider_access_denied','provider_auth_error','provider_permission_required'];
        if (in_array($category, $config, true)) {
            return 'This source is not fully configured. Ask an admin to review Diagnostics or try another configured source.';
        }
        if ($category === 'dispatch_slot_limit' || $category === 'run_dispatch_busy') {
            return 'El sistema no pudo iniciar la ejecución en este momento. No se consumieron créditos finales. Reintenta en unos segundos.';
        }
        if ($category === 'duplicate_dispatch_blocked') {
            return 'Ya hay una ejecución en curso. Espera unos segundos o vuelve a intentarlo.';
        }
        if (in_array($category, ['provider_rate_limit','provider_transport_error','provider_busy','provider_timeout'], true)) {
            return 'Una fuente está temporalmente ocupada. Puedes reintentar o ejecutar una búsqueda reducida.';
        }
        if ($category === 'invalid_input' || $category === 'invalid_platform') {
            return 'This source needs a clearer search query or URL before it can run.';
        }
        // v0.9.31.1: the provider accepted the request but rejected the payload (HTTP 400).
        // This is an input/configuration problem for this specific source, not a transient
        // outage — guide the user to fix the input and admins to review the payload.
        if ($category === 'provider_payload_rejected' || $category === 'provider_invalid_input') {
            return 'The data provider rejected this source payload. Use a clearer advertiser, domain, keyword, or transparency URL; admins can review the safe payload summary in Diagnostics.';
        }
        if ($category === 'actor_payload_contract_failed') {
            return 'This source configuration needs admin review before it can run.';
        }
        if (in_array($category, ['provider_run_id_missing','provider_terminal_failure','provider_http_error','provider_invalid_json'], true)) {
            return 'The source run did not complete successfully. Try again or choose another source.';
        }
        if ($category === 'dataset_fetch_failed') {
            return 'The source ran, but results could not be retrieved. Try again in a few minutes.';
        }
        if ($category === 'normalization_failed') {
            return 'The source returned results, but they could not be prepared for display. Ask an admin to review Diagnostics.';
        }
        if ($category === 'post_dispatch_fatal') {
            return 'The source request was attempted, but the response could not be processed. Ask an admin to review Diagnostics using the diagnostic code.';
        }
        if ($category === 'post_provider_run_fatal') {
            return 'The source started, but the result pipeline failed after dispatch. Ask an admin to review Diagnostics using the diagnostic code.';
        }
        if ($category === 'usage_reservation_failed') {
            return 'El sistema no pudo reservar créditos para iniciar la ejecución. No se consumieron créditos finales.';
        }
        if ($category === 'provider_cost_guard_blocked') {
            return 'This run needs more available credits or a lower resource setting before it can start.';
        }
        if ($category === 'ajax_fatal') {
            return 'The request stopped before the source could run. Ask an admin to review Diagnostics using the diagnostic code.';
        }
        // v0.9.31.3: new precision categories.
        if ($category === 'provider_empty') {
            return 'The source returned no results for this query. Try a broader keyword, different date range, or higher item limit.';
        }
        if ($category === 'local_filters_removed_all') {
            return 'Results were collected but all were removed by your active filters (e.g. minimum views or date). Try lowering the filter threshold or clearing filters.';
        }
        if ($category === 'unknown_error') {
            return 'The request could not be completed. Ask an admin to review Diagnostics using the diagnostic code.';
        }
        return 'The request could not be completed. Ask an admin to review Diagnostics using the diagnostic code.';
    }

    /**
     * v0.9.31.4: classify whether a given error category is user-retryable.
     * true  = transient; false = requires admin/config action; null = unknown.
     */
    private static function classify_retryable($cat) {
        static $yes = ['provider_rate_limit','dispatch_slot_limit','run_dispatch_busy','duplicate_dispatch_blocked','provider_transport_error','provider_empty','provider_busy','provider_timeout','usage_reservation_failed'];
        static $no  = ['provider_auth_error','provider_access_denied','provider_payload_rejected','provider_invalid_input','invalid_input','invalid_platform','module_access_denied'];
        if (in_array($cat, $yes, true)) { return true; }
        if (in_array($cat, $no, true))  { return false; }
        return null;
    }

    /**
     * v0.9.30.19: leak-free diagnostic surface for NON-admin operators.
     * request_debug_enabled() and admin_detail both require manage_options, so a
     * front-end member testing the product could never see WHERE a run stopped —
     * only the generic public message + FI code. This exposes the failing stage
     * and category (which contain no actor slugs, provider costs, tokens, URLs, or
     * file/line) so the operator can self-diagnose. Raw file/line stay admin-only.
     */
    private static function build_safe_diagnostic($category, $context, $support_code) {
        $context = is_array($context) ? $context : [];
        $stage = sanitize_key((string) ($context['stage'] ?? ''));
        $last = sanitize_key((string) ($context['last_successful_stage'] ?? ($context['last_stage'] ?? '')));
        $cat = sanitize_key((string) $category);
        return [
            'public_support_code' => (string) $support_code,
            'error_category' => $cat,
            'stage' => $stage,
            'last_successful_stage' => $last,
            'provider_call_attempted' => !empty($context['provider_call_attempted']),
            'provider_run_created' => !empty($context['provider_run_created']),
            'retryable' => self::classify_retryable($cat),
            'credits_reserved' => !empty($context['credits_reserved']),
            'final_credit_settled' => !empty($context['final_credit_settled']),
            'credit_voided' => !empty($context['credit_voided']) || !empty($context['credits_refunded_or_voided']),
        ];
    }

    private static function remember_active_run_context($context = []) {
        $context = is_array($context) ? $context : [];
        if (empty($context)) { return; }
        $existing = is_array(self::$active_run_context) ? self::$active_run_context : [];
        $stage = sanitize_key((string) ($context['stage'] ?? ''));
        if ($stage !== '') {
            $history = isset($existing['stage_history']) && is_array($existing['stage_history']) ? $existing['stage_history'] : [];
            $history[] = ['stage' => $stage, 'time' => time()];
            $context['last_stage'] = $stage;
            $context['stage_history'] = array_slice($history, -25);
        }
        foreach ($context as $key => $value) {
            if ($value === null || $value === '') { continue; }
            $existing[$key] = $value;
        }
        self::$active_run_context = $existing;
    }

    private static function cleanup_active_run_resources_after_fatal($reason = '') {
        $ctx = is_array(self::$active_run_context) ? self::$active_run_context : [];
        $start_gate_key = sanitize_key((string) ($ctx['start_gate_key'] ?? ''));
        if ($start_gate_key !== '') {
            self::release_start_gate($start_gate_key);
        }
        $dispatch_slot_id = sanitize_text_field((string) ($ctx['dispatch_slot_id'] ?? ''));
        if ($dispatch_slot_id !== '' && class_exists('VES_Apify_Client') && method_exists('VES_Apify_Client', 'release_dispatch_slot')) {
            VES_Apify_Client::release_dispatch_slot($dispatch_slot_id);
        }
        $usage_key = sanitize_text_field((string) ($ctx['usage_key'] ?? ''));
        $provider_run_created = !empty($ctx['provider_run_created']);
        if ($usage_key !== '' && !$provider_run_created && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'void_reserved_usage')) {
            VES_Usage_Billing::void_reserved_usage($usage_key, $reason !== '' ? $reason : 'Run stopped before provider dispatch completed.');
        }
    }

    private static function log_run_stage($request_id, $platform, $stage, $context = []) {
        $context = is_array($context) ? $context : [];
        $context['request_id'] = sanitize_text_field((string) $request_id);
        $context['public_support_code'] = self::public_support_code($request_id);
        $context['user_id'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $context['platform'] = sanitize_key((string) $platform);
        $context['stage'] = sanitize_key((string) $stage);
        self::remember_active_run_context($context);
        self::log('standard_run_stage', 'Stage: ' . sanitize_key((string) $stage), $context);
    }

    private static function register_ajax_shutdown_handler($request_id, $action = '') {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $request_id = sanitize_text_field((string) $request_id);
        $action = sanitize_key((string) $action);
        register_shutdown_function(static function () use ($request_id, $action) {
            $last_error = error_get_last();
            if (!is_array($last_error)) {
                return;
            }
            $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!in_array((int) ($last_error['type'] ?? 0), $fatal_types, true)) {
                return;
            }
            if (headers_sent()) {
                return;
            }
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            $message = 'Fatal PHP error before a valid AJAX response: ' . (string) ($last_error['message'] ?? 'unknown error');
            // v0.9.31.1: release runtime locks (start gate, dispatch slot) and void any
            // unsettled usage on a fatal shutdown. Previously only the caught-throwable
            // fatal() path cleaned up, so a fatal DURING dispatch left the start_gate
            // transient stale for its full 90s TTL — the next attempt was rejected as
            // duplicate_dispatch_blocked and surfaced as "temporarily busy" (FI-590xxx),
            // most visibly on Trend Finder. Mirror fatal()'s cleanup here.
            self::cleanup_active_run_resources_after_fatal($message);
            $support_code = self::public_support_code($request_id);
            $active_context = is_array(self::$active_run_context) ? self::$active_run_context : [];
            $last_stage = sanitize_key((string) ($active_context['last_stage'] ?? ($active_context['stage'] ?? '')));
            $fatal_context = array_merge($active_context, [
                'request_id' => $request_id,
                'public_support_code' => $support_code,
                'action' => $action,
                'stage' => 'shutdown_fatal',
                'last_successful_stage' => $last_stage,
                'file' => (string) ($last_error['file'] ?? ''),
                'line' => (int) ($last_error['line'] ?? 0),
                'provider_call_attempted' => !empty($active_context['provider_call_attempted']),
                'provider_run_created' => !empty($active_context['provider_run_created']),
            ]);
            $category = self::classify_error_category('ajax_shutdown_fatal', $message, 500, $fatal_context);
            $fatal_context['error_category'] = $category;
            if (class_exists('VES_Admin')) {
                try {
                    VES_Admin::record_diagnostic('ajax_shutdown_fatal', $message, $fatal_context);
                } catch (Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[Vietnam Estudio Social] fatal diagnostic logging failed: ' . $e->getMessage());
                    }
                }
            }
            $payload = [
                'message' => self::public_message_for_error_category($category) . ' Diagnostic code: ' . $support_code,
                'public_support_code' => $support_code,
                'source' => 'run_error',
                'safe_diagnostic' => self::build_safe_diagnostic($category, $fatal_context, $support_code),
            ];
            $show_admin_detail = self::request_debug_enabled() || (function_exists('current_user_can') && current_user_can('manage_options'));
            if ($show_admin_detail) {
                $payload['status'] = 500;
                $payload['source'] = 'ajax_shutdown_fatal';
                $payload['error_category'] = $category;
                $payload['request_id'] = $request_id;
                $payload['admin_detail'] = [
                    'request_id' => $request_id,
                    'public_support_code' => $support_code,
                    'error_category' => $category,
                    'source' => 'ajax_shutdown_fatal',
                    'stage' => 'shutdown_fatal',
                    'last_successful_stage' => $last_stage,
                    'provider_call_attempted' => !empty($fatal_context['provider_call_attempted']),
                    'provider_run_created' => !empty($fatal_context['provider_run_created']),
                    'file' => sanitize_text_field((string) ($fatal_context['file'] ?? '')),
                    'line' => isset($fatal_context['line']) ? (int) $fatal_context['line'] : 0,
                ];
            }
            wp_send_json_error($payload, 200);
        });
    }

    private static function fatal($exception, $request_id, $source, $context = []) {
        // safe_diagnostic is included in the JSON payload below; keep this near
        // the function head so regression tests catch accidental removal.
        $message = $exception instanceof Throwable ? $exception->getMessage() : 'Unknown fatal error';
        $active_context = is_array(self::$active_run_context) ? self::$active_run_context : [];
        $last_stage = sanitize_key((string) ($active_context['last_stage'] ?? ($active_context['stage'] ?? '')));
        $context = array_merge($active_context, (array) $context, [
            'stage' => 'caught_throwable',
            'last_successful_stage' => $last_stage,
            'exception_class' => is_object($exception) ? get_class($exception) : gettype($exception),
            'file' => $exception instanceof Throwable ? $exception->getFile() : '',
            'line' => $exception instanceof Throwable ? $exception->getLine() : 0,
        ]);
        self::cleanup_active_run_resources_after_fatal($message);
        self::log($source, $message, $context);
        $support_code = self::public_support_code($request_id);
        $category = self::classify_error_category($source, $message, 500, $context);
        $context['request_id'] = $request_id;
        $context['public_support_code'] = $support_code;
        $context['error_category'] = $category;
        $context['provider_call_attempted'] = !empty($context['provider_call_attempted']);
        $context['provider_run_created'] = !empty($context['provider_run_created']);
        self::log($source . '_categorized', $message, $context);
        $public_message = self::public_error_message($message, 500, $context);
        if ($public_message === $message || strpos($public_message, '{') !== false) {
            $public_message = 'Something went wrong before the request completed. Ask an admin to review Diagnostics.';
        }
        $actions = ['diagnostic'];
        if (self::classify_retryable($category) === true) { $actions[] = 'retry'; }
        if (in_array($category, ['provider_busy','provider_rate_limit','provider_timeout','provider_transport_error'], true)) { $actions[] = 'reduced_scope'; }
        $payload = [
            'message' => $public_message . ' Diagnostic code: ' . $support_code,
            'public_support_code' => $support_code,
            'source' => 'run_error',
            'error_code' => $category,
            'retryable' => self::classify_retryable($category),
            'actions' => array_values(array_unique($actions)),
            'credits_reserved' => !empty($context['credits_reserved']),
            'final_credit_settled' => !empty($context['final_credit_settled']),
            'credit_voided' => !empty($context['credit_voided']) || !empty($context['credits_refunded_or_voided']),
            'safe_diagnostic' => self::build_safe_diagnostic($category, $context, $support_code),
        ];
        $show_admin_detail = self::request_debug_enabled() || (function_exists('current_user_can') && current_user_can('manage_options'));
        if ($show_admin_detail) {
            $payload['request_id'] = $request_id;
            $payload['status'] = 500;
            $payload['source'] = sanitize_key((string) $source);
            $payload['error_category'] = $category;
            $payload['technical_message'] = $message;
            $payload['admin_detail'] = [
                'request_id' => $request_id,
                'public_support_code' => $support_code,
                'error_category' => $category,
                'source' => sanitize_key((string) $source),
                'stage' => sanitize_key((string) ($context['stage'] ?? '')),
                'last_successful_stage' => sanitize_key((string) ($context['last_successful_stage'] ?? '')),
                'provider_call_attempted' => !empty($context['provider_call_attempted']),
                'provider_run_created' => !empty($context['provider_run_created']),
                'file' => sanitize_text_field((string) ($context['file'] ?? '')),
                'line' => isset($context['line']) ? (int) $context['line'] : 0,
            ];
        }
        wp_send_json_error($payload, 200);
    }

    private static function summarize_input($platform, $input, $src = []) {
        try {
            $summary = [
                'platform' => sanitize_key((string) $platform),
                'mode' => sanitize_key((string) ($src['mode'] ?? '')),
                'payload_keys' => array_keys(is_array($input) ? $input : []),
                'payload_size_bytes' => strlen((string) wp_json_encode($input)),
            ];

            $targets = isset($src['targets']) ? (string) $src['targets'] : '';
            $lines = preg_split('/\r\n|\r|\n/', $targets);
            $targets_count = 0;
            foreach ((array) $lines as $line) {
                if (trim((string) $line) !== '') {
                    $targets_count++;
                }
            }
            $summary['targets_count'] = $targets_count;
            $summary['limit'] = isset($src['limit']) ? (int) $src['limit'] : null;
            $summary['date_preset'] = isset($src['datePreset']) ? sanitize_key((string) $src['datePreset']) : '';

            if ($platform === 'tiktok') {
                try {
                    $actor_slug = function_exists('ves_get_actor_slug') ? ves_get_actor_slug('tiktok', $src) : '';
                    $actor_schema = (function_exists('ves_tiktok_actor_uses_apidojo_schema') && ves_tiktok_actor_uses_apidojo_schema($actor_slug)) ? 'apidojo_discovery' : 'legacy_clockworks_fallback';
                } catch (Throwable $summary_actor_error) {
                    $actor_slug = '';
                    $actor_schema = 'unknown';
                    self::log('input_summary_degraded', $summary_actor_error->getMessage(), [
                        'stage' => 'summarize_input_actor_slug_failed',
                        'platform' => 'tiktok',
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                }
                $summary['actor_role'] = 'discovery';
                $summary['actor_schema'] = $actor_schema;
                $summary['search_mode'] = !empty($input['keywords']) ? 'keywords' : (!empty($input['startUrls']) ? 'start_urls' : (!empty($input['searchQueries']) ? 'legacy_search_queries' : (!empty($input['profiles']) ? 'legacy_profiles' : (!empty($input['postURLs']) ? 'legacy_post_urls' : 'none'))));
                $summary['keywords_count'] = is_array($input['keywords'] ?? null) ? count($input['keywords']) : 0;
                $summary['start_urls_count'] = is_array($input['startUrls'] ?? null) ? count($input['startUrls']) : 0;
                $summary['location'] = isset($input['location']) ? sanitize_text_field((string) $input['location']) : (isset($input['proxyCountryCode']) ? sanitize_text_field((string) $input['proxyCountryCode']) : '');
                $summary['date_strategy'] = ($summary['actor_schema'] === 'apidojo_discovery') ? 'local_post_filter_when_locked' : 'actor_field_plus_local_filter';
            }

            if ($platform === 'instagram') {
                $summary['results_type'] = isset($input['resultsType']) ? sanitize_key((string) $input['resultsType']) : '';
                $summary['search_type'] = isset($input['searchType']) ? sanitize_key((string) $input['searchType']) : '';
            }
            if ($platform === 'facebook_ads') {
                $summary['country_code'] = isset($input['scrapePageAds.countryCode']) ? sanitize_text_field((string) $input['scrapePageAds.countryCode']) : '';
                $summary['active_status'] = isset($input['scrapePageAds.activeStatus']) ? sanitize_text_field((string) $input['scrapePageAds.activeStatus']) : '';
                $summary['input_mode'] = !empty($input['urls']) ? 'ads_library_urls' : (!empty($input['searchTerms']) ? 'search_terms' : 'empty');
                $summary['urls_count'] = is_array($input['urls'] ?? null) ? count($input['urls']) : 0;
                $summary['domains_count'] = is_array($input['domains'] ?? null) ? count($input['domains']) : 0;
                $summary['search_terms_count'] = is_array($input['searchTerms'] ?? null) ? count($input['searchTerms']) : 0;
                $summary['results_limit'] = isset($input['count']) ? (int) $input['count'] : (isset($input['maxItems']) ? (int) $input['maxItems'] : null);
            } elseif ($platform === 'google_ads') {
                $summary['target_platform'] = isset($input['targetPlatform']) ? sanitize_text_field((string) $input['targetPlatform']) : 'ALL';
                $summary['ad_format'] = isset($input['adFormatType']) ? sanitize_text_field((string) $input['adFormatType']) : 'ALL';
                $summary['region'] = isset($input['geoTargetRegion']) ? sanitize_text_field((string) $input['geoTargetRegion']) : 'WORLDWIDE';
                $summary['time_range'] = isset($input['customStartDate']) ? ('custom_from_' . sanitize_text_field((string) $input['customStartDate'])) : (isset($input['timeRangePreset']) ? sanitize_text_field((string) $input['timeRangePreset']) : 'ALL_TIME');
                $summary['search_targets_count'] = is_array($input['searchTargets'] ?? null) ? count($input['searchTargets']) : 0;
                $summary['domains_count'] = is_array($input['domains'] ?? null) ? count($input['domains']) : 0;
                $summary['max_domain_matches'] = isset($input['maxDomainMatches']) ? (int) $input['maxDomainMatches'] : null;
                $summary['enable_url_filtering'] = !empty($input['enableUrlFiltering']);
                $summary['results_per_query'] = isset($input['resultsPerQuery']) ? (int) $input['resultsPerQuery'] : null;
            }
            if ($platform === 'twitter') {
                $summary['search_mode'] = !empty($input['searchTerms']) ? 'search' : (!empty($input['twitterHandles']) ? 'handles' : (!empty($input['startUrls']) ? 'urls' : 'conversationIds'));
                $summary['minimum_retweets'] = isset($input['minimumRetweets']) ? (int) $input['minimumRetweets'] : 0;
            }
            if ($platform === 'reddit') {
                $summary['search_mode'] = !empty($input['startUrls']) ? 'urls' : (!empty($input['searches']) ? 'search' : 'none');
                $summary['searches_count'] = is_array($input['searches'] ?? null) ? count($input['searches']) : 0;
            } elseif ($platform === 'pinterest') {
                $summary['search_mode'] = !empty($input['startUrls']) ? 'urls' : (!empty($input['search']) ? 'search' : 'none');
                $summary['pinterest_url_count'] = is_array($input['startUrls'] ?? null) ? count($input['startUrls']) : 0;
                $summary['pinterest_has_search'] = !empty($input['search']);
                $summary['include_comments'] = !empty($input['includeComments']);
            } elseif (in_array($platform, ['semrush', 'moz', 'ahrefs'], true)) {
                $summary['seo_search_type'] = isset($input['searchType']) ? sanitize_key((string) $input['searchType']) : sanitize_key((string) ($src['mode'] ?? ''));
                $summary['seo_url_count'] = is_array($input['urls'] ?? null) ? count($input['urls']) : 0;
                $summary['seo_has_keyword'] = !empty($input['keyword']);
            }

            return $summary;
        } catch (Throwable $summary_error) {
            self::log('input_summary_degraded', $summary_error->getMessage(), [
                'stage' => 'summarize_input_failed',
                'platform' => sanitize_key((string) $platform),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'exception_class' => get_class($summary_error),
                'file' => $summary_error->getFile(),
                'line' => $summary_error->getLine(),
            ]);
            return [
                'platform' => sanitize_key((string) $platform),
                'mode' => is_array($src) ? sanitize_key((string) ($src['mode'] ?? '')) : '',
                'payload_keys' => array_keys(is_array($input) ? $input : []),
                'payload_size_bytes' => 0,
                'summary_degraded' => true,
            ];
        }
    }

    private static function bool_request_flag($src, $keys = []) {
        $src = is_array($src) ? $src : [];
        foreach ((array) $keys as $key) {
            if (!array_key_exists($key, $src)) { continue; }
            $value = $src[$key];
            if (is_bool($value)) { return $value; }
            return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private static function date_cutoff_iso_from_request($src) {
        $src = is_array($src) ? $src : [];
        $date_filter = function_exists('ves_resolve_date_filter_inputs')
            ? ves_resolve_date_filter_inputs($src)
            : ['preset' => sanitize_key((string) ($src['datePreset'] ?? 'any')), 'iso' => ''];
        $iso = function_exists('ves_clean_iso_date') ? ves_clean_iso_date($date_filter['iso'] ?? '') : (string) ($date_filter['iso'] ?? '');
        if ($iso !== '') { return $iso; }

        $preset = sanitize_key((string) ($date_filter['preset'] ?? 'any'));
        $days_map = [
            '1d' => 1,
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            '60d' => 60,
            '90d' => 90,
            '180d' => 180,
            '365d' => 365,
        ];
        if (!isset($days_map[$preset])) { return ''; }
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        return gmdate('Y-m-d', time() - ((int) $days_map[$preset] * $day));
    }

    private static function request_locked_constraints($platform, $request) {
        $platform = sanitize_key((string) $platform);
        $request = is_array($request) ? $request : [];
        $date_filter = function_exists('ves_resolve_date_filter_inputs')
            ? ves_resolve_date_filter_inputs($request)
            : ['preset' => sanitize_key((string) ($request['datePreset'] ?? 'any')), 'iso' => ''];
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($request['country'] ?? 'None') : sanitize_text_field((string) ($request['country'] ?? 'None'));
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($request['language'] ?? '') : sanitize_key((string) ($request['language'] ?? ''));
        $limit = self::requested_result_limit($request);
        $min_metric = function_exists('ves_requested_min_metric')
            ? ves_requested_min_metric($request)
            : (isset($request['minViews'])
                ? (function_exists('ves_clean_int') ? ves_clean_int($request['minViews'], 0, null) : (int) $request['minViews'])
                : (isset($request['minLikes']) ? (function_exists('ves_clean_int') ? ves_clean_int($request['minLikes'], 0, null) : (int) $request['minLikes']) : 0));

        return [
            'platform' => $platform,
            'target_type' => sanitize_key((string) ($request['mode'] ?? '')),
            'date_preset' => sanitize_key((string) ($date_filter['preset'] ?? 'any')),
            'date_from_iso' => sanitize_text_field((string) ($date_filter['iso'] ?? '')),
            'effective_date_cutoff_iso' => self::date_cutoff_iso_from_request($request),
            'country' => $country,
            'language' => $language,
            'requested_results' => $limit,
            'minimum_metric' => max(0, (int) $min_metric),
            'minimum_metric_label' => self::get_primary_metric_label($platform),
            'relaxed_fallback_allowed' => self::bool_request_flag($request, ['allowRelaxedFallback', 'allow_relaxed_fallback']),
        ];
    }

    private static function enforce_locked_actor_constraints($platform, $request, $input) {
        $platform = sanitize_key((string) $platform);
        $request = is_array($request) ? $request : [];
        $input = is_array($input) ? $input : [];
        $locked = self::request_locked_constraints($platform, $request);
        $mode = sanitize_key((string) ($request['mode'] ?? ''));
        $is_post_url_mode = function_exists('ves_is_post_url_mode') ? ves_is_post_url_mode($request) : in_array($mode, ['url_posts', 'post_urls', 'post_url'], true);
        $date_cutoff = sanitize_text_field((string) ($locked['effective_date_cutoff_iso'] ?? ''));
        $date_preset = sanitize_key((string) ($locked['date_preset'] ?? 'any'));
        $country = (string) ($locked['country'] ?? '');
        $language = (string) ($locked['language'] ?? '');

        // Global guard: if the user locked any finite date window, never dispatch an
        // explicit ALL_TIME-style actor field. Some actors accept multiple date keys;
        // sending a stale ALL_TIME together with a stricter field silently weakens the run.
        if ($date_preset !== '' && $date_preset !== 'any') {
            foreach (['timeRangePreset', 'videoSearchDateFilter', 'dateFilter', 'postedWithin', 'timeRange', 'range'] as $date_key) {
                if (isset($input[$date_key]) && strtoupper((string) $input[$date_key]) === 'ALL_TIME') {
                    unset($input[$date_key]);
                }
            }
        }

        if ($platform === 'tiktok') {
            $actor_slug_for_constraints = function_exists('ves_get_actor_slug') ? ves_get_actor_slug('tiktok', $request) : '';
            $is_apidojo_constraints = function_exists('ves_tiktok_actor_uses_apidojo_schema')
                ? ves_tiktok_actor_uses_apidojo_schema($actor_slug_for_constraints)
                : (stripos((string) $actor_slug_for_constraints, 'apidojo/tiktok-scraper') !== false);
            if ($is_apidojo_constraints) {
                foreach (['searchQueries','profiles','postURLs','searchDatePosted','videoSearchDateFilter','oldestPostDateUnified','profileScrapeSections','profileSorting','excludePinnedPosts','maxProfilesPerQuery','resultsPerPage','proxyCountryCode'] as $legacy_key) {
                    if (array_key_exists($legacy_key, $input)) { unset($input[$legacy_key]); }
                }
                if ($country !== '' && $country !== 'None') {
                    $input['location'] = strtoupper((string) $country);
                } else {
                    // v0.9.24.67: unrestricted country means no actor-side location field.
                    // Do not inherit Apidojo's UI prefill/default (US) in a credit-metered SaaS run.
                    unset($input['location']);
                }
                $input['dateRange'] = function_exists('ves_apidojo_tiktok_date_range') ? ves_apidojo_tiktok_date_range($date_preset) : ($input['dateRange'] ?? 'DEFAULT');
                $sort_priority = function_exists('ves_sort_priority') ? ves_sort_priority($request) : sanitize_key((string) ($request['sortPriority'] ?? 'relevance'));
                $input['sortType'] = function_exists('ves_apidojo_tiktok_sort_type') ? ves_apidojo_tiktok_sort_type($sort_priority) : ($input['sortType'] ?? 'RELEVANCE');
                return $input;
            }

            // The Clockworks TikTok fallback actor changed its video-search date field.
            // searchDatePosted is legacy; videoSearchDateFilter is the current schema field.
            // Do not omit videoSearchDateFilter for finite windows, because Apify merges
            // the actor default videoSearchDateFilter=ALL_TIME into the stored run input.
            foreach (['videoSearchDatePosted', 'timeRangePreset', 'dateFilter', 'postedWithin'] as $conflict_key) {
                if (array_key_exists($conflict_key, $input)) {
                    unset($input[$conflict_key]);
                }
            }

            $date_code = function_exists('ves_tt_search_date_posted') ? ves_tt_search_date_posted($date_preset) : '0';
            $video_date_filter = function_exists('ves_tt_video_search_date_filter') ? ves_tt_video_search_date_filter($date_preset) : 'ALL_TIME';
            if (!empty($input['searchQueries'])) {
                if ($date_code !== '0') {
                    $input['searchDatePosted'] = $date_code;
                } elseif (isset($input['searchDatePosted'])) {
                    unset($input['searchDatePosted']);
                }
                if ($video_date_filter !== 'ALL_TIME') {
                    $input['videoSearchDateFilter'] = $video_date_filter;
                } elseif (isset($input['videoSearchDateFilter'])) {
                    unset($input['videoSearchDateFilter']);
                }
            }

            if (!$is_post_url_mode && $date_cutoff !== '' && !empty($input['profiles'])) {
                $input['oldestPostDateUnified'] = $date_cutoff;
            }

            if ($country !== '' && $country !== 'None') {
                $input['proxyCountryCode'] = $country;
            } elseif (($language === 'es') && (!isset($input['proxyCountryCode']) || $input['proxyCountryCode'] === 'None')) {
                $input['proxyCountryCode'] = 'ES';
            }
        }

        if ($platform === 'youtube' && !$is_post_url_mode && $date_cutoff !== '') {
            $input['oldestPostDate'] = $date_cutoff;
        }

        if (($platform === 'facebook' || $platform === 'instagram') && !$is_post_url_mode && $date_cutoff !== '') {
            $input['onlyPostsNewerThan'] = $date_cutoff;
        }

        if ($platform === 'twitter' && !$is_post_url_mode && $date_cutoff !== '') {
            $input['start'] = $date_cutoff;
        }

        if ($platform === 'reddit' && !$is_post_url_mode && $date_preset !== '' && $date_preset !== 'any') {
            $input['time'] = function_exists('ves_reddit_time_from_preset') ? ves_reddit_time_from_preset($date_preset) : 'month';
        }

        if ($platform === 'google_ads') {
            if ($country !== '' && $country !== 'None' && function_exists('ves_google_ads_region_from_country')) {
                $input['geoTargetRegion'] = ves_google_ads_region_from_country($country);
            }
            if ($date_cutoff !== '') {
                $input['customStartDate'] = $date_cutoff;
                if (isset($input['timeRangePreset']) && strtoupper((string) $input['timeRangePreset']) === 'ALL_TIME') {
                    unset($input['timeRangePreset']);
                }
            }
        }

        if ($platform === 'facebook_ads') {
            unset($input['scrapePageAds']);
            if ($country !== '' && $country !== 'None') {
                $input['scrapePageAds.countryCode'] = strtoupper($country);
            }
            if ($date_preset !== '' && $date_preset !== 'any' && function_exists('ves_fb_ads_period_from_preset')) {
                $fb_period = ves_fb_ads_period_from_preset($date_preset);
                if ($fb_period !== '') {
                    $input['scrapePageAds.period'] = $fb_period;
                }
            }
        }

        return $input;
    }

    private static function build_scrape_execution_plan($platform, $request, $input) {
        $platform = sanitize_key((string) $platform);
        $request = is_array($request) ? $request : [];
        $input = is_array($input) ? $input : [];
        $locked = self::request_locked_constraints($platform, $request);
        $targets_raw = trim((string) ($request['targets'] ?? ''));
        $context = trim((string) ($request['searchContext'] ?? ($request['context'] ?? '')));
        $normalized_request = class_exists('VES_Query_Planner') ? VES_Query_Planner::normalize_request_fields($platform === 'semrush' ? 'seo' : 'social', $platform, $request) : [];
        $queries = self::extract_query_terms_from_input($platform, $input);
        $query_plan = [];
        foreach ($queries as $idx => $query) {
            $reason = $idx === 0 ? 'primary user target / exact query' : 'AI-safe contextual expansion; user filters remain locked';
            $query_plan[] = [
                'query' => $query,
                'priority' => $idx + 1,
                'reason' => $reason,
            ];
        }
        $warnings = [];
        $suggestions = [];
        $date_preset = sanitize_key((string) ($locked['date_preset'] ?? 'any'));
        $country = (string) ($locked['country'] ?? '');
        $date_field = '';
        $date_value = '';
        foreach (['dateRange', 'videoSearchDateFilter', 'searchDatePosted', 'oldestPostDateUnified', 'oldestPostDate', 'onlyPostsNewerThan', 'start', 'customStartDate', 'time', 'timeRangePreset'] as $candidate_date_key) {
            if (isset($input[$candidate_date_key]) && $input[$candidate_date_key] !== '') {
                $date_field = $candidate_date_key;
                $date_value = sanitize_text_field((string) $input[$candidate_date_key]);
                break;
            }
        }

        if ($date_preset !== '' && $date_preset !== 'any') {
            if ($date_field !== '') {
                $warnings[] = ucfirst(str_replace('_', ' ', $platform)) . ' date is locked through ' . $date_field . '=' . $date_value . '; contradictory ALL_TIME-style fields are removed before dispatch.';
            } else {
                $warnings[] = ucfirst(str_replace('_', ' ', $platform)) . ' actor has no reliable date field for this mode; the same date constraint will be enforced locally after collection.';
            }
        }
        if (($locked['minimum_metric'] ?? 0) > 0) {
            $suggestions[] = 'If fewer results are returned, lower the minimum metric only after the user explicitly approves a relaxed rerun.';
        }
        if ($country !== '' && $country !== 'None') {
            $warnings[] = 'Country is treated as a locked market/proxy constraint and as a local relevance signal; it is not silently relaxed.';
        }
        if (($locked['language'] ?? '') !== '') {
            $warnings[] = 'Language is treated as a locked analysis/filter constraint when the source exposes language metadata; unknown language values are not treated as mismatches.';
        }
        if (empty($query_plan) && $targets_raw !== '') {
            $query_plan[] = ['query' => self::short_text_for_plan($targets_raw, 160), 'priority' => 1, 'reason' => 'raw target; no query expansion applied'];
        }

        $country_contract = '';
        if (isset($input['proxyCountryCode'])) {
            $country_contract = sanitize_text_field((string) $input['proxyCountryCode']);
        } elseif (isset($input['geoTargetRegion'])) {
            $country_contract = sanitize_text_field((string) $input['geoTargetRegion']);
        } elseif (isset($input['scrapePageAds.countryCode'])) {
            $country_contract = sanitize_text_field((string) $input['scrapePageAds.countryCode']);
        }

        return [
            'planner_version' => 'deterministic_preflight_v046',
            'planner_mode' => 'locked_constraints_first_all_scrapers',
            'canonical_request' => [
                'platform' => $platform,
                'target_type' => sanitize_key((string) ($request['mode'] ?? '')),
                'targets' => $targets_raw,
                'scraper_query_terms' => array_values((array) ($normalized_request['scraper_query_terms'] ?? [])),
                'seed_keywords' => array_values((array) ($normalized_request['seed_keywords'] ?? [])),
                'hashtags' => array_values((array) ($normalized_request['hashtags'] ?? [])),
                'target_urls' => array_values((array) ($normalized_request['target_urls'] ?? [])),
                'target_profiles' => array_values((array) ($normalized_request['target_profiles'] ?? [])),
                'business_objective' => sanitize_text_field((string) ($normalized_request['business_objective'] ?? ($request['businessObjective'] ?? ''))),
                'search_context' => $context,
                'ai_analysis_goal' => sanitize_text_field((string) ($normalized_request['ai_analysis_goal'] ?? ($request['aiAnalysisGoal'] ?? ''))),
                'ranking_mode' => sanitize_key((string) ($normalized_request['ranking_mode'] ?? ($request['rankingMode'] ?? ''))),
                'candidate_pool_size' => (int) ($normalized_request['candidate_pool_size'] ?? ($request['candidatePoolSize'] ?? 0)),
                'desired_final_results' => (int) ($normalized_request['desired_final_results'] ?? ($request['limit'] ?? 0)),
            ],
            'locked_constraints' => $locked,
            'query_plan' => $query_plan,
            'dispatch_plan' => [
                'actor' => (function_exists('ves_get_actor_slug') ? sanitize_text_field((string) ves_get_actor_slug($platform, $request)) : ''),
                'number_of_runs' => 1,
                'primary_or_fallback' => 'primary',
                'estimated_provider_cost_usd' => (class_exists('VES_Config') && method_exists('VES_Config', 'apify_estimate_provider_cost')) ? VES_Config::apify_estimate_provider_cost(1, preg_match('/instagram|tiktok|youtube|facebook|crawler|scraper/i', $platform) ? 1 : 0) : null,
                'estimated_saas_credits' => (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'estimate_for_run')) ? VES_Usage_Billing::estimate_for_run($platform, (int) ($locked['limit'] ?? ($request['limit'] ?? 0))) : (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'get_cost') ? VES_Usage_Billing::get_cost('manual_run') : null),
            ],
            'actor_payload_contract' => [
                'payload_keys' => array_keys($input),
                'actor_role' => $platform === 'tiktok' ? 'discovery' : '',
                'actor_schema' => ($platform === 'tiktok' && function_exists('ves_get_actor_slug') && function_exists('ves_tiktok_actor_uses_apidojo_schema') && ves_tiktok_actor_uses_apidojo_schema(ves_get_actor_slug('tiktok', $request))) ? 'apidojo_discovery' : '',
                'date_field' => $date_field,
                'date_value' => $date_value,
                'country_field_value' => $country_contract,
                'proxy_country' => isset($input['proxyCountryCode']) ? sanitize_text_field((string) $input['proxyCountryCode']) : '',
                'results_per_page' => isset($input['resultsPerPage']) ? (int) $input['resultsPerPage'] : (isset($input['resultsPerQuery']) ? (int) $input['resultsPerQuery'] : (isset($input['maxItems']) ? (int) $input['maxItems'] : null)),
            ],
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'fallback_suggestions' => array_values(array_unique(array_filter($suggestions))),
            'forbidden_changes' => [
                'Do not silently change date, country, language, requested quantity, or minimum metric.',
                'Do not replace the user target with generic adjacent terms; only add explainable query expansions.',
                'Do not show relaxed fallback results unless allowRelaxedFallback is explicitly enabled.',
            ],
        ];
    }

    private static function short_text_for_plan($text, $max = 180) {
        $text = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $text)));
        $max = max(20, (int) $max);
        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $max) { return $text; }
        return (function_exists('mb_substr') ? mb_substr($text, 0, $max - 3, 'UTF-8') : substr($text, 0, $max - 3)) . '...';
    }

    private static function adapter_name_for_platform($platform, $actor_slug = '') {
        $platform = sanitize_key((string) $platform);
        $actor_slug = strtolower((string) $actor_slug);
        if ($platform === 'instagram' || strpos($actor_slug, 'instagram') !== false) { return 'InstagramAdapter'; }
        if ($platform === 'tiktok' || strpos($actor_slug, 'tiktok') !== false) { return 'TikTokAdapter'; }
        if ($platform === 'semrush' || strpos($actor_slug, 'semrush') !== false) { return 'SemrushKeywordMagicAdapter'; }
        if ($platform === 'google_intel' || strpos($actor_slug, 'google-search') !== false || strpos($actor_slug, 'google-trends') !== false) { return 'GoogleAdapter'; }
        if ($platform === 'linkedin' || strpos($actor_slug, 'linkedin') !== false) { return 'LinkedInPostAdapter'; }
        if ($platform === 'youtube' || strpos($actor_slug, 'youtube') !== false) { return 'YouTubeAdapter'; }
        if ($platform === 'twitter' || strpos($actor_slug, 'tweet') !== false || strpos($actor_slug, 'twitter') !== false) { return 'TwitterAdapter'; }
        if ($platform === 'reddit' || strpos($actor_slug, 'reddit') !== false) { return 'RedditAdapter'; }
        if ($platform === 'facebook_ads' || strpos($actor_slug, 'facebook-ads') !== false) { return 'FacebookAdsAdapter'; }
        if ($platform === 'google_ads' || strpos($actor_slug, 'google-ads') !== false) { return 'GoogleAdsAdapter'; }
        return ucfirst($platform ?: 'generic') . 'Adapter';
    }

    private static function public_error_message($message, $status = 500, $context = []) {
        // v0.9.30.7: product-safe, category-aware public error messaging.
        // Raw categories and provider mechanics stay in admin diagnostics.
        $context = is_array($context) ? $context : [];
        $category = self::classify_error_category($context['source'] ?? '', $message, $status, $context);
        if (!empty($context['error_category'])) {
            $category = sanitize_key((string) $context['error_category']);
        }
        $raw = (string) $message;
        $haystack = strtolower($raw . ' ' . wp_json_encode($context));
        if (strpos($haystack, 'payload was valid but no rows') !== false || strpos($haystack, 'no rows') !== false || strpos($haystack, 'no usable') !== false) {
            return 'No usable signals matched your filters. Try broader terms, fewer filters, another language, or a wider date range.';
        }
        return self::public_message_for_error_category($category);
    }

    private static function error($message, $status, $request_id, $source, $context = []) {
        $status = (int) $status;
        if ($status < 100) {
            $status = 500;
        }
        $context = is_array($context) ? $context : [];
        // v0.9.30.16: preserve the live run state for every explicit error(),
        // not only for caught Throwables. This prevents post-dispatch failures
        // from being misclassified as pre-source failures when the local call
        // site forgot to repeat provider_call_attempted/provider_run_created.
        $active_context = is_array(self::$active_run_context) ? self::$active_run_context : [];
        if (!empty($active_context)) {
            $last_stage = sanitize_key((string) ($active_context['last_stage'] ?? ($active_context['stage'] ?? '')));
            $context = array_merge($active_context, $context);
            if ($last_stage !== '' && empty($context['last_successful_stage'])) {
                $context['last_successful_stage'] = $last_stage;
            }
        }
        $support_code = self::public_support_code($request_id);
        $category = self::classify_error_category($source, $message, $status, $context);
        $context['request_id'] = $request_id;
        $context['public_support_code'] = $support_code;
        $context['http_status'] = $status;
        $context['source'] = sanitize_key((string) $source);
        $context['error_category'] = $category;
        $context['provider_call_attempted'] = !empty($context['provider_call_attempted']);
        $context['provider_run_created'] = !empty($context['provider_run_created']);
        $context['credits_reserved'] = !empty($context['credits_reserved']);
        $context['credits_refunded_or_voided'] = !empty($context['credits_refunded_or_voided']);
        self::log($source, $message, $context);
        self::log('standard_run_error', 'Categorized standard run failure: ' . $category, $context);

        // Some hosting/CDN stacks replace non-2xx admin-ajax bodies (especially 429/403)
        // with HTML error pages. Keep application-level errors inside a JSON envelope.
        $public_message = self::public_error_message($message, $status, $context);
        $actions = ['diagnostic'];
        if (self::classify_retryable($category) === true) { $actions[] = 'retry'; }
        if (in_array($category, ['provider_busy','provider_rate_limit','provider_timeout','provider_transport_error'], true)) { $actions[] = 'reduced_scope'; }
        $payload = [
            'message' => $public_message . ' Diagnostic code: ' . $support_code,
            'public_support_code' => $support_code,
            'source' => 'run_error',
            'error_code' => $category,
            'retryable' => self::classify_retryable($category),
            'actions' => array_values(array_unique($actions)),
            'credits_reserved' => !empty($context['credits_reserved']),
            'final_credit_settled' => !empty($context['final_credit_settled']),
            'credit_voided' => !empty($context['credit_voided']) || !empty($context['credits_refunded_or_voided']),
            'safe_diagnostic' => self::build_safe_diagnostic($category, $context, $support_code),
        ];
        $show_admin_detail = self::request_debug_enabled() || (function_exists('current_user_can') && current_user_can('manage_options'));
        if ($show_admin_detail) {
            $payload['request_id'] = $request_id;
            $payload['status'] = $status;
            $payload['source'] = sanitize_key((string) $source);
            $payload['error_category'] = $category;
            $payload['technical_message'] = $message;
            $payload['admin_detail'] = [
                'request_id' => $request_id,
                'public_support_code' => $support_code,
                'logical_status' => $status,
                'error_category' => $category,
                'stage' => sanitize_key((string) ($context['stage'] ?? '')),
                'provider_call_attempted' => !empty($context['provider_call_attempted']),
                'provider_run_created' => !empty($context['provider_run_created']),
                'provider_run_id' => sanitize_text_field((string) ($context['provider_run_id'] ?? ($context['run_id'] ?? ''))),
                'source_execution_key' => sanitize_text_field((string) ($context['source_execution_key'] ?? '')),
                'dispatch_slot_id' => sanitize_text_field((string) ($context['dispatch_slot_id'] ?? '')),
                'provider_state' => sanitize_text_field((string) ($context['provider_state'] ?? '')),
                'provider_http_status' => isset($context['provider_http_status']) ? (int) $context['provider_http_status'] : (isset($context['http_status']) ? (int) $context['http_status'] : null),
            ];
            if (!empty($context['seed_suggestions']) && is_array($context['seed_suggestions'])) {
                $payload['correction_suggestions'] = array_values(array_slice(array_map('sanitize_text_field', $context['seed_suggestions']), 0, 5));
            }
            if (!empty($context['requested_objective'])) {
                $payload['requested_objective'] = sanitize_key((string) $context['requested_objective']);
            }
        } elseif (!empty($context['seed_suggestions']) && is_array($context['seed_suggestions'])) {
            $payload['correction_suggestions'] = array_values(array_slice(array_map('sanitize_text_field', $context['seed_suggestions']), 0, 5));
        }
        wp_send_json_error($payload, 200);
    }

    private static function legacy_trend_deprecated_payload($context = []) {
        return class_exists('VES_Deep_Trend_Addon_Bridge')
            ? VES_Deep_Trend_Addon_Bridge::deprecated_payload(is_array($context) ? $context : [])
            : [
                'deprecated' => true,
                'replacement' => 'future_island_deep_trend_finder',
                'message' => __('The internal Trend Finder has been replaced by the Deep Trend Finder add-on.', 'ves'),
                'addon_active' => false,
                'addon_url' => null,
                'context' => is_array($context) ? $context : [],
            ];
    }

    private static function deprecated_trend_success_payload($data, $action) {
        return array_merge(is_array($data) ? $data : [], self::legacy_trend_deprecated_payload(['action' => sanitize_key((string) $action)]));
    }

    private static function success($data = []) {
        $payload = is_array($data) ? $data : [];
        // v0.9.28.0 — Phase 5: universal Provider Outcome wiring.
        // If the payload looks like a module-run response (has raw or usable
        // counts) and hasn't already been classified, attach the canonical
        // provider_outcome + outcome_message fields. Skip for metadata/index
        // payloads (memory dumps, abort acks, etc.).
        $payload = self::maybe_attach_provider_outcome($payload);
        wp_send_json_success(self::public_payload($payload));
    }

    /**
     * v0.9.28.0 — Phase 5 helper. Pure function: takes a payload, returns
     * the same payload (possibly with provider_outcome + outcome_message added).
     * Never overwrites an existing provider_outcome. Tolerant of missing keys.
     */
    private static function maybe_attach_provider_outcome(array $payload) {
        if (!class_exists('VES_Provider_Outcome')) { return $payload; }
        if (!empty($payload['provider_outcome'])) { return $payload; }

        // Only run when we have something resembling a provider run.
        $candidate_raw_keys    = ['raw_provider_returned_count', 'raw_items', 'fetched_dataset_count', 'raw_count'];
        $candidate_usable_keys = ['useful_delivered_rows', 'displayed_rows', 'filtered_items', 'all_items_count', 'item_count_preview', 'usable_count'];
        $raw_count    = null;
        $usable_count = null;
        foreach ($candidate_raw_keys as $k) {
            if (isset($payload[$k]) && is_numeric($payload[$k])) { $raw_count = (int) $payload[$k]; break; }
        }
        foreach ($candidate_usable_keys as $k) {
            if (isset($payload[$k]) && is_numeric($payload[$k])) { $usable_count = (int) $payload[$k]; break; }
        }
        if ($raw_count === null && $usable_count === null) {
            return $payload; // Not a module run response.
        }
        $raw_count    = $raw_count    ?? 0;
        $usable_count = $usable_count ?? 0;

        // Status hints from the payload itself.
        $actor_status = '';
        $error_code   = '';
        if (isset($payload['run_lifecycle_status'])) {
            $lc = (string) $payload['run_lifecycle_status'];
            if ($lc === 'completed' || $lc === 'completed_no_usable_results') {
                $actor_status = 'SUCCEEDED';
            } elseif ($lc === 'failed') {
                $actor_status = 'FAILED';
            }
        }
        if (isset($payload['status'])) {
            $st = strtoupper((string) $payload['status']);
            if (in_array($st, ['SUCCEEDED','FAILED','ABORTED','TIMED-OUT'], true)) {
                $actor_status = $st;
            }
        }
        if (!empty($payload['actor_error']) || !empty($payload['error_code'])) {
            $error_code = (string) ($payload['error_code'] ?? 'provider_error');
        }

        // Multi-source signals (Trend Finder fan-out).
        $multi_source = false;
        $sources_ok   = 0;
        $sources_bad  = 0;
        if (isset($payload['source_diagnostics']) && is_array($payload['source_diagnostics'])) {
            $multi_source = true;
            foreach ($payload['source_diagnostics'] as $src) {
                if (!is_array($src)) { continue; }
                $st = strtolower((string) ($src['status'] ?? $src['provider_outcome'] ?? ''));
                if (strpos($st, 'success') !== false || strpos($st, 'usable') !== false) {
                    $sources_ok++;
                } elseif (strpos($st, 'fail') !== false || strpos($st, 'error') !== false || strpos($st, 'skipped') !== false) {
                    $sources_bad++;
                }
            }
        } elseif (isset($payload['sources']) && is_array($payload['sources'])) {
            $multi_source = true;
            foreach ($payload['sources'] as $src) {
                if (!is_array($src)) { continue; }
                $st = strtolower((string) ($src['status'] ?? ''));
                if (strpos($st, 'success') !== false || strpos($st, 'usable') !== false) {
                    $sources_ok++;
                } elseif (strpos($st, 'fail') !== false || strpos($st, 'error') !== false) {
                    $sources_bad++;
                }
            }
        }

        $outcome = VES_Provider_Outcome::classify([
            'actor_status'      => $actor_status,
            'http_status'       => 200,
            'raw_count'         => $raw_count,
            'usable_count'      => $usable_count,
            'error_code'        => $error_code,
            'multi_source'      => $multi_source,
            'sources_succeeded' => $sources_ok,
            'sources_failed'    => $sources_bad,
        ]);

        // Module/platform hint for the user message.
        $platform = '';
        $module   = '';
        if (isset($payload['platform']))      { $platform = (string) $payload['platform']; }
        if (isset($payload['module']))        { $module   = (string) $payload['module']; }
        if (!$module && isset($payload['module_label'])) { $module = strtolower((string) $payload['module_label']); }
        if (!$module && $platform) {
            $module = in_array($platform, ['semrush','moz','ahrefs'], true) ? 'seo'
                    : (in_array($platform, ['facebook_ads','google_ads'], true) ? 'ads'
                    : ($platform === 'linkedin' ? 'linkedin'
                    : ($platform === 'trend_finder' ? 'trend' : 'social')));
        }

        $payload['provider_outcome'] = $outcome;
        $payload['outcome_message']  = VES_Provider_Outcome::user_message($outcome, [
            'platform'  => $platform,
            'module'    => $module,
            'raw_count' => $raw_count,
        ]);
        return $payload;
    }


    /**
     * Keep operational/debug payloads out of non-admin responses. UI hiding is not
     * enough because users can still inspect AJAX responses, JSON views, exports,
     * or browser devtools. Admins keep the full diagnostic envelope.
     */
    private static function request_debug_enabled() {
        $debug = '';
        foreach (['debugMode', 'debug_mode', 'vesDebug', 'ves_debug'] as $key) {
            if (isset($_REQUEST[$key])) {
                $debug = sanitize_text_field(wp_unslash($_REQUEST[$key]));
                break;
            }
        }
        if ($debug === '' && isset($_GET['ves_debug'])) {
            $debug = sanitize_text_field(wp_unslash($_GET['ves_debug']));
        }
        return current_user_can('manage_options') && in_array((string) $debug, ['1', 'true', 'yes', 'on'], true);
    }

    private static function add_public_resource_notice($data) {
        if (!is_array($data)) { return $data; }
        $provider_cost = isset($data['provider_cost']) && is_array($data['provider_cost']) ? $data['provider_cost'] : null;
        if ($provider_cost && !empty($provider_cost['warning'])) {
            $data['resource_notice'] = [
                'warning' => true,
                'source_count' => isset($provider_cost['source_count']) ? (int) $provider_cost['source_count'] : 0,
                'message' => 'This run uses multiple external sources and may consume more credits.',
            ];
        }
        return $data;
    }

    private static function public_payload($data) {
        if (self::request_debug_enabled()) {
            return $data;
        }
        $data = self::add_public_resource_notice($data);
        $data = self::replace_provider_run_id_for_public($data);
        return self::strip_admin_diagnostics($data);
    }

    private static function strip_admin_diagnostics($value) {
        if (!is_array($value)) {
            return $value;
        }

        $blocked = [
            'raw_item',
            'raw_provider_item',
            'provider_raw',
            'raw_payload',
            'raw_payload_json',
            'raw_input',
            'raw_output',
            'raw_dataset',
            'dataset_items',
            'actor_input',
            'actor_payload',
            'input',
            'prompt',
            'system_prompt',
            'messages',
            // v0.9.30.2: provider mechanics — must never reach normal users.
            // public_payload() returns early for admin/debug sessions, so these
            // are only stripped for normal users; admins keep full diagnostics.
            'actor_slug',
            'provider_run_id',
            'source_execution_key',
            'provider_actor_slug',
            'provider_dataset_id',
            'dataset_id',
            'run_body',
            'total_charge_usd',
            'provider_cost_usd',
            'provider_cost',
            'actual_provider_cost_usd',
            'ves_normalized_payload',
            'ves_pre_enrichment_item',
            'ves_tiktok_enrichment_items',
            'ves_enrichment_status',
            'ves_enrichment_run_id',
            'ves_enrichment_schema',
            'ves_comments_enriched_from_endpoint',
            'ves_comments_enriched_from_dataset',
            'ves_comments_enriched_from_fallback_actor',
            'apify_raw',
            'openai_raw',
            'source_quality_breakdown',
            'diagnostics',
            'debug',
            'debug_context',
            'raw_debug',
            'stack',
            'trace',
            'query_diagnostics',
            'query_plan',
            'source_preview',
            'adapter_diagnostic',
            'first_raw_snapshot',
            'technical_message',
            'raw_contract_message',
            'error_data',
            'intelligence_records',
            'metric_snapshots',
            'pattern_candidates',
            'insight_records',
            'opportunity_records',
        ];

        $out = [];
        foreach ($value as $key => $child) {
            $key_string = is_string($key) ? $key : (string) $key;
            $key_lc = strtolower($key_string);
            if ($key_lc === 'raw_preview_payload' || $key_lc === 'selected_preview_payload') {
                $out[$key] = self::strip_admin_diagnostics($child);
                continue;
            }
            if (in_array($key_lc, $blocked, true)) {
                continue;
            }
            if ($key_lc === 'notes' || $key_lc === 'admin_note' || $key_lc === 'admin_notes' || $key_lc === 'request_id') {
                continue;
            }
            if (strpos($key_lc, 'diagnostic') !== false
                || strpos($key_lc, 'debug') !== false
                || strpos($key_lc, 'stack') !== false
                || strpos($key_lc, 'trace') !== false
                || strpos($key_lc, 'raw_') === 0
                || strpos($key_lc, '_raw') !== false
                || strpos($key_lc, 'provider_raw') !== false
                // v0.9.30.2: provider-mechanic key variants. Plain 'run_id' is
                // intentionally still allowed because the frontend poller needs
                // it; only provider-labelled run ids and dataset ids are blocked.
                || strpos($key_lc, 'actor_slug') !== false
                || strpos($key_lc, 'actor_id') !== false
                || strpos($key_lc, 'dataset_id') !== false
                || strpos($key_lc, 'provider_run_id') !== false
                || strpos($key_lc, 'source_execution_key') !== false
                || (strpos($key_lc, '_cost_usd') !== false && strpos($key_lc, 'estimated') === false)
                || strpos($key_lc, '_charge_usd') !== false
                || (strpos($key_lc, 'apify') !== false && strpos($key_lc, 'run_id') === false)
                || strpos($key_lc, 'openai') !== false) {
                continue;
            }
            $out[$key] = self::strip_admin_diagnostics($child);
        }
        return $out;
    }

    private static function deny_if_role_forbidden($request_id, $source, $stage) {
        try {
            if (is_user_logged_in() && class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel(get_current_user_id())) {
                self::error('Tu cuenta no tiene permisos para acceder al panel.', 403, $request_id, $source, ['stage' => $stage]);
            }
        } catch (Throwable $role_error) {
            self::log('role_access_degraded', $role_error->getMessage(), [
                'request_id' => $request_id,
                'stage' => $stage,
                'exception_class' => get_class($role_error),
                'file' => $role_error->getFile(),
                'line' => $role_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            if (!current_user_can('manage_options')) {
                self::error('Role access check failed.', 403, $request_id, $source, ['stage' => $stage, 'error_category' => 'module_access_denied']);
            }
        }
    }


    private static function get_ai_context_pack($args = []) {
        $args = is_array($args) ? $args : [];
        if (is_user_logged_in()) { $args['user_id'] = get_current_user_id(); }
        $cache_key = md5(wp_json_encode($args, JSON_UNESCAPED_UNICODE));
        if (isset(self::$ai_context_cache[$cache_key])) { return self::$ai_context_cache[$cache_key]; }
        if (class_exists('VES_AI_Context_Builder') && is_user_logged_in()) {
            self::$ai_context_cache[$cache_key] = VES_AI_Context_Builder::build($args);
            return self::$ai_context_cache[$cache_key];
        }

        $project_context = (class_exists('VES_Project_Context') && is_user_logged_in()) ? VES_Project_Context::build_ai_context_block(get_current_user_id(), $args) : null;
        $related_memory = [];
        if (class_exists('VES_Temp_Memory')) {
            $temp_context = VES_Temp_Memory::get_related_memory_context($args, 5);
            if (is_array($temp_context)) { $related_memory = array_merge($related_memory, $temp_context); }
        }
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'retrieve_context_pack')) {
            $persistent_context = VES_Memory_Records::retrieve_context_pack($args, 5);
            if (is_array($persistent_context) && !empty($persistent_context)) {
                $related_memory[] = [
                    'entry_type' => 'persistent_workspace_memory',
                    'platform' => 'workspace',
                    'report_title' => 'Relevant company memory',
                    'summary' => wp_json_encode($persistent_context, JSON_UNESCAPED_UNICODE),
                    'records' => $persistent_context,
                ];
            }
        }
        self::$ai_context_cache[$cache_key] = [
            'project_context' => $project_context,
            'related_memory' => array_slice($related_memory, 0, 8),
            'diagnostics' => [],
            'used_context' => 'Used context: ' . (!empty($project_context) || !empty($related_memory) ? 'available project memory.' : 'current request only.'),
        ];
        return self::$ai_context_cache[$cache_key];
    }

    private static function get_ai_project_context($args = []) {
        $pack = self::get_ai_context_pack($args);
        return is_array($pack) ? ($pack['project_context'] ?? null) : null;
    }

    private static function get_related_memory_context($args = []) {
        $pack = self::get_ai_context_pack($args);
        return is_array($pack) && is_array($pack['related_memory'] ?? null) ? $pack['related_memory'] : [];
    }

    private static function attach_ai_context_meta($response, $context_pack = []) {
        $response = is_array($response) ? $response : [];
        $context_pack = is_array($context_pack) ? $context_pack : [];
        $response['used_context'] = sanitize_text_field((string) ($context_pack['used_context'] ?? 'Used context: current request only.'));
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            $response['memory_diagnostics'] = is_array($context_pack['diagnostics'] ?? null) ? $context_pack['diagnostics'] : [];
        }
        return $response;
    }

    private static function project_context_memory_terms($project_context) {
        $terms = [];
        foreach ((array) ($project_context['workspace_knowledge'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['title', 'summary'] as $key) {
                if (!empty($item[$key])) {
                    $terms[] = (string) $item[$key];
                }
            }
            if (!empty($item['tags']) && is_array($item['tags'])) {
                $terms = array_merge($terms, array_map('strval', $item['tags']));
            }
        }
        return array_slice(array_values(array_filter($terms)), 0, 16);
    }

    private static function charge_if_needed($request_id, $event_type, $source, $context = []) {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing')) {
            return;
        }
        $charged = VES_Usage_Billing::charge_current_user($event_type, '', $context);
        if (is_wp_error($charged)) {
            self::error($charged->get_error_message(), 402, $request_id, $source, ['stage' => 'insufficient_credits', 'event_type' => $event_type]);
        }
    }

    private static function current_usage_scope_id() {
        $scope = self::current_standard_run_scope();
        $user_id = (int) ($scope['user_id'] ?? 0);
        if ($user_id > 0) {
            return 'user_' . $user_id;
        }
        $session_key = sanitize_text_field((string) ($scope['session_key'] ?? ''));
        return $session_key !== '' ? 'session_' . md5($session_key) : 'guest';
    }

    private static function reserve_standard_usage_or_error($request_id, $event_type, $source, $context = []) {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'reserve_usage')) {
            self::log('usage_billing_degraded', 'Usage billing class or reserve_usage method unavailable; dispatch continues without a reservation.', [
                'request_id' => $request_id,
                'stage' => 'reserve_usage_unavailable',
                'event_type' => $event_type,
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => false,
            ]);
            return '';
        }

        $usage_key = sanitize_key((string) $event_type) . ':' . self::current_usage_scope_id() . ':' . sanitize_text_field((string) $request_id);
        try {
            $reserved = VES_Usage_Billing::reserve_usage(get_current_user_id(), $event_type, $usage_key, 'Reserva de uso', array_merge((array) $context, [
                'usage_key' => $usage_key,
                'request_id' => $request_id,
            ]));
        } catch (Throwable $usage_error) {
            // v0.9.30.12 hotfix: a broken/missing usage table must not masquerade
            // as an Apify/source failure in beta. Admins can still dispatch and the
            // billing fault is preserved in Diagnostics. Non-admin users keep the
            // safer blocking behavior.
            self::log('usage_billing_degraded', $usage_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'stage' => 'reserve_usage_throwable_degraded',
                'event_type' => $event_type,
                'usage_key' => $usage_key,
                'exception_class' => get_class($usage_error),
                'file' => $usage_error->getFile(),
                'line' => $usage_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => false,
            ]);
            // An exception while reserving is an infrastructure fault (DB/billing),
            // not a credit/quota denial. Degrade for ALL users — never hard-block a
            // run because of a transient billing error. Previously only admins
            // degraded here, which is exactly why "admin works, normal user fails".
            // The fault is logged above with the diagnostic code for admin review.
            return '';
        }
        if (is_wp_error($reserved)) {
            $code = method_exists($reserved, 'get_error_code') ? sanitize_key((string) $reserved->get_error_code()) : '';
            // Infrastructure faults (table/DB write failed) are NOT credit/quota
            // denials — degrade for ALL users instead of only admins, so a usage-log
            // write problem never hard-blocks a normal user. record_event() now also
            // self-heals the table, so this should rarely trigger; when it does, the
            // run proceeds and the fault is logged with the diagnostic code.
            if (in_array($code, ['usage_event_failed', 'missing_usage_key', 'usage_table_missing', 'db_error', 'credit_lock_timeout'], true)) {
                self::log('usage_billing_degraded', $reserved->get_error_message(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'stage' => 'reserve_usage_wp_error_degraded',
                    'event_type' => $event_type,
                    'usage_key' => $usage_key,
                    'error_code' => $code,
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                    'credits_reserved' => false,
                ]);
                return '';
            }
            // Free-tier exhaustion (out of credits or monthly run cap) is expected,
            // not a fault: show a clear "you've used your free analyses, book a demo"
            // message instead of a scary diagnostic code. The demo button is the
            // banner already rendered above the panel.
            $exhausted = in_array($code, ['insufficient_credits', 'plan_limit_reached', 'plan_monthly_runs_reached', 'plan_daily_limit_reached', 'plan_ai_limit_reached'], true);
            $message = $reserved->get_error_message();
            $extra = [
                'stage' => 'reserve_usage',
                'event_type' => $event_type,
                'error_category' => 'usage_reservation_failed',
                'reserve_code' => $code,
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => false,
            ];
            if ($exhausted && !current_user_can('manage_options')) {
                $message = 'Has agotado tus análisis gratuitos. Reserva una demo gratuita de 30 minutos para desbloquear el uso ilimitado del SaaS (botón de demo arriba).';
                $extra['error_category'] = 'free_quota_exhausted';
                $extra['retryable'] = false;
                if (class_exists('VES_Lead_Gate')) { $extra['demo_booking_url'] = VES_Lead_Gate::booking_url(); }
            } elseif ($code !== '') {
                // Surface the SPECIFIC reserve failure code (e.g. usage_event_failed,
                // insufficient_credits) in the diagnostic instead of the umbrella
                // category, so the real reason is visible without guessing.
                $extra['error_category'] = $code;
            }
            self::error($message, 402, $request_id, $source, $extra);
        }
        return $usage_key;
    }

    private static function post_standard_usage_or_error($usage_key, $request_id, $source, $message = 'Uso confirmado') {
        $usage_key = sanitize_text_field((string) $usage_key);
        if ($usage_key === '' || !class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'post_reserved_usage')) {
            return;
        }
        try {
            $posted = VES_Usage_Billing::post_reserved_usage($usage_key, $message);
            if (is_wp_error($posted)) {
                // v0.9.30.20: billing settlement AFTER a successful provider run must never
                // terminate the request. A missing or already-settled reservation is a billing
                // accounting issue, not a run failure. Log and return — the caller already has
                // provider results to deliver.
                self::log('usage_billing_post_degraded', $posted->get_error_message(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'stage' => 'post_usage_wp_error_degraded',
                    'usage_key' => $usage_key,
                    'error_code' => method_exists($posted, 'get_error_code') ? $posted->get_error_code() : '',
                ]);
                return;
            }
            if (false === $posted) {
                self::log('usage_billing_post_degraded', 'No se pudo confirmar el cargo de créditos.', [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'stage' => 'post_usage_false_degraded',
                    'usage_key' => $usage_key,
                ]);
            }
        } catch (Throwable $usage_error) {
            self::log('usage_billing_post_degraded', $usage_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'stage' => 'post_usage_throwable_degraded',
                'usage_key' => $usage_key,
                'exception_class' => get_class($usage_error),
                'file' => $usage_error->getFile(),
                'line' => $usage_error->getLine(),
            ]);
        }
    }

    private static function delivery_usage_context($formatted, $platform, $request = [], $actor_slug = '') {
        $formatted = is_array($formatted) ? $formatted : [];
        $request = is_array($request) ? $request : [];
        $requested = isset($request['limit']) ? max(1, min(500, (int) $request['limit'])) : 20;
        $delivered = isset($formatted['all_items_count']) ? (int) $formatted['all_items_count'] : (int) ($formatted['item_count_preview'] ?? (is_array($formatted['items'] ?? null) ? count($formatted['items']) : 0));
        $raw = (int) ($formatted['raw_provider_returned_count'] ?? $formatted['fetched_dataset_count'] ?? $formatted['raw_items'] ?? $formatted['received_count'] ?? 0);
        if ($raw <= 0 && isset($formatted['items']) && is_array($formatted['items'])) { $raw = count($formatted['items']); }
        $discarded = 0;
        foreach (['discarded_count','discarded_items_count','dropped_by_metric','dropped_by_relevance','dropped_by_language','dropped_by_country','dropped_by_date'] as $key) {
            if (isset($formatted[$key]) && is_numeric($formatted[$key])) { $discarded += (int) $formatted[$key]; }
        }
        if ($discarded <= 0 && $raw > $delivered) { $discarded = max(0, $raw - $delivered); }
        $candidate = (int) ($formatted['candidate_pool_size'] ?? $formatted['actor_limit'] ?? $formatted['requested_actor_limit'] ?? ($raw > 0 ? $raw : $requested));
        $provider_cost = isset($formatted['provider_cost_usd']) ? (float) $formatted['provider_cost_usd'] : (isset($formatted['total_charge_usd']) ? (float) $formatted['total_charge_usd'] : 0.0);
        $reserved_cost = class_exists('VES_Usage_Billing') ? (float) VES_Usage_Billing::get_cost('manual_run') : 1.0;
        $configured_base_fee = (float) apply_filters('ves_usage_base_scan_fee_manual_run', 0.25, $platform, $formatted, $request);
        $configured_per_result_fee = null;
        if (class_exists('VES_Access_Control')) {
            $configured_base_fee = (float) VES_Access_Control::get_plan_limit(get_current_user_id(), 'base_scan_fee', $configured_base_fee);
            $configured_per_result_fee = VES_Access_Control::get_plan_limit(get_current_user_id(), 'per_usable_result_fee', null);
            $configured_per_result_fee = is_numeric($configured_per_result_fee) ? (float) $configured_per_result_fee : null;
        }
        $base_fee = 0.0;
        if ($provider_cost > 0 || $raw > 0) {
            $base_fee = max(0.0, $configured_base_fee);
        }
        $per_result = ($configured_per_result_fee !== null && $configured_per_result_fee >= 0)
            ? $configured_per_result_fee
            : ($requested > 0 ? max(0.0, ($reserved_cost - $base_fee) / max(1, $requested)) : $reserved_cost);
        $final_cost = $base_fee + ($delivered * $per_result);
        $final_cost = max(0.0, min($reserved_cost, round($final_cost, 2)));
        // v0.1 RC: explicit settlement classification so the ledger says WHY a
        // reserved charge was reduced. Provider success with nothing delivered is
        // never final-charged as a successful delivery:
        //  - zero raw rows + zero delivered  -> not chargeable at all (0.0)
        //  - raw rows but zero delivered     -> failed delivery, base scan fee only
        $settlement_classification = 'delivered_charged';
        if ($delivered <= 0 && $raw > 0) {
            $final_cost = min($reserved_cost, max(0.0, round($base_fee, 2)));
            $settlement_classification = 'failed_delivery_base_fee_only';
        }
        if ($delivered <= 0 && $raw <= 0) {
            $final_cost = 0.0;
            $settlement_classification = 'not_chargeable_zero_delivery';
        }
        $project_id = isset($request['project_id']) ? (int) $request['project_id'] : (isset($request['projectId']) ? (int) $request['projectId'] : 0);
        return [
            'settlement_classification' => $settlement_classification,
            'requested_result_count' => $requested,
            'candidate_scraped_count' => max($candidate, $raw, $delivered),
            'raw_provider_returned_count' => $raw,
            'discarded_count' => $discarded,
            'final_delivered_count' => max(0, $delivered),
            'credits_reserved' => $reserved_cost,
            'credits_charged_final' => $final_cost,
            'credits_refunded_or_voided' => max(0, round($reserved_cost - $final_cost, 2)),
            'provider_cost_usd' => $provider_cost,
            'module' => 'manual_run',
            'provider' => 'apify',
            'actor' => sanitize_text_field((string) $actor_slug),
            'platform' => sanitize_key((string) $platform),
            'project_id' => $project_id,
            'run_id' => sanitize_text_field((string) ($formatted['run_id'] ?? '')),
            'apify_run_id' => sanitize_text_field((string) ($formatted['run_id'] ?? $formatted['provider_run_id'] ?? '')),
            'actor_id' => sanitize_text_field((string) $actor_slug),
            'requested_results' => $requested,
            'raw_provider_rows' => $raw,
            'normalized_rows' => (int) ($formatted['normalized_rows'] ?? $raw),
            'displayed_rows' => max(0, $delivered),
            'useful_delivered_rows' => max(0, $delivered),
            'discarded_rows' => $discarded,
            'provider_actual_cost_usd' => $provider_cost,
            'run_status' => sanitize_key((string) ($formatted['run_lifecycle_status'] ?? $formatted['status'] ?? '')),
            'failure_reason' => sanitize_text_field((string) ($formatted['failure_reason'] ?? $formatted['status_message'] ?? '')),
        ];
    }

    private static function settle_standard_usage_by_delivery($usage_key, $request_id, $source, $formatted, $platform, $request = [], $actor_slug = '') {
        $usage_key = sanitize_text_field((string) $usage_key);
        if ($usage_key === '' || !class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'settle_reserved_usage')) {
            return $formatted;
        }
        $context = self::delivery_usage_context($formatted, $platform, $request, $actor_slug);
        $classification = (string) ($context['settlement_classification'] ?? 'delivered_charged');
        $settle_message = 'Run manual confirmado por resultados entregados';
        if ($classification !== 'delivered_charged') {
            // v0.1 RC: honest ledger message + non-billable diagnostic when the
            // provider ran but nothing usable was delivered.
            $settle_message = $classification === 'not_chargeable_zero_delivery'
                ? 'Run sin resultados entregables — sin cargo final'
                : 'Run sin resultados entregables — solo tarifa base de escaneo';
            self::log('usage_settlement_zero_delivery', 'Provider run settled with zero delivered items.', [
                'request_id' => $request_id,
                'stage' => 'settlement_classification',
                'usage_key' => $usage_key,
                'settlement_classification' => $classification,
                'raw_provider_returned_count' => (int) ($context['raw_provider_returned_count'] ?? 0),
                'credits_charged_final' => (float) ($context['credits_charged_final'] ?? 0),
            ]);
        }
        try {
            $settled = VES_Usage_Billing::settle_reserved_usage($usage_key, (float) ($context['credits_charged_final'] ?? 0), $settle_message, $context);
            if (is_wp_error($settled)) {
                // v0.9.30.20: settle is always post-provider; never terminate on billing failure.
                self::log('usage_billing_settle_degraded', $settled->get_error_message(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'stage' => 'settle_usage_wp_error_degraded',
                    'usage_key' => $usage_key,
                    'error_code' => method_exists($settled, 'get_error_code') ? $settled->get_error_code() : '',
                ]);
                if (is_array($formatted)) {
                    $formatted['usage_ledger'] = array_merge($context, [
                        'billing_settlement_status' => 'degraded',
                        'billing_settlement_error' => 'admin_diagnostics_available',
                    ]);
                }
                return $formatted;
            }
            if (is_array($formatted)) {
                $formatted['usage_ledger'] = array_merge($context, is_array($settled) ? $settled : []);
            }
        } catch (Throwable $usage_error) {
            self::log('usage_billing_settle_degraded', $usage_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'stage' => 'settle_usage_throwable_degraded',
                'usage_key' => $usage_key,
                'exception_class' => get_class($usage_error),
                'file' => $usage_error->getFile(),
                'line' => $usage_error->getLine(),
            ]);
            if (is_array($formatted)) {
                $formatted['usage_ledger'] = array_merge($context, [
                    'billing_settlement_status' => 'degraded',
                    'billing_settlement_error' => 'admin_diagnostics_available',
                ]);
            }
        }
        return $formatted;
    }

    private static function void_standard_usage($usage_key, $reason = '') {
        $usage_key = sanitize_text_field((string) $usage_key);
        if ($usage_key !== '' && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'void_reserved_usage')) {
            try {
                VES_Usage_Billing::void_reserved_usage($usage_key, $reason !== '' ? $reason : 'Uso cancelado');
            } catch (Throwable $usage_error) {
                self::log('usage_billing_void_degraded', $usage_error->getMessage(), [
                    'stage' => 'void_usage_throwable_degraded',
                    'usage_key' => $usage_key,
                    'exception_class' => get_class($usage_error),
                    'file' => $usage_error->getFile(),
                    'line' => $usage_error->getLine(),
                ]);
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────────────
    // PHASE 1 COST SPINE — integration note (no behavior change in this phase).
    //
    // This controller is large (~8.6k LOC). The AI/credit-sensitive endpoints are:
    //   - ves_analyze_item / ves_analyze_items / ves_ai_refine_input  (OpenAI)
    //   - ves_trends_start / ves_google_analyze / ves_assistant_ask    (OpenAI)
    //   - ves_brand_audit_start / ves_trends_brief_create             (OpenAI)
    //   - ves_generate_brief_from_opportunity                          (OpenAI)
    //   - ves_start (provider scrape; Apify cost, credit-reserved)
    //
    // AI usage from these endpoints is ALREADY tracked transparently: every OpenAI
    // call funnels through VES_OpenAI_Client (instrumented) and credit charges go
    // through VES_Billing. To attach a per-endpoint pre-call estimate, call
    // VES_AI_Usage_Tracker::preflight([...]) before dispatch (advisory, non-blocking
    // in Phase 1). Do NOT add wallet deduction here — that stays in VES_Billing.
    //
    // TODO(phase-future): split this god-class into per-domain controllers
    // (scraper / trends / brand-audit / assistant). Out of scope for Phase 0/1.
    // ───────────────────────────────────────────────────────────────────────────
    public static function register() {
        add_action('wp_ajax_ves_start', [__CLASS__, 'start']);
        add_action('wp_ajax_ves_poll', [__CLASS__, 'poll']);
        add_action('wp_ajax_ves_abort', [__CLASS__, 'abort']);
        add_action('wp_ajax_ves_payload_preview', [__CLASS__, 'payload_preview']);
        add_action('wp_ajax_ves_analyze_item', [__CLASS__, 'analyze_item']);
        add_action('wp_ajax_ves_analyze_items', [__CLASS__, 'analyze_items']);
        add_action('wp_ajax_ves_ai_refine_input', [__CLASS__, 'ai_refine_input']);
        add_action('wp_ajax_ves_google_start', [__CLASS__, 'google_start']);
        add_action('wp_ajax_ves_google_poll', [__CLASS__, 'google_poll']);
        add_action('wp_ajax_ves_google_analyze', [__CLASS__, 'google_analyze']);
        add_action('wp_ajax_ves_trends_start', [__CLASS__, 'trends_start']);
        add_action('wp_ajax_ves_trends_poll', [__CLASS__, 'trends_poll']);
        add_action('wp_ajax_ves_trends_abort', [__CLASS__, 'trends_abort']);
        add_action('wp_ajax_ves_trends_history', [__CLASS__, 'trends_history']);
        add_action('wp_ajax_ves_trends_dashboard', [__CLASS__, 'trends_dashboard']);
        add_action('wp_ajax_ves_trends_compare', [__CLASS__, 'trends_compare']);
        add_action('wp_ajax_ves_trends_brief_create', [__CLASS__, 'trends_brief_create']);
        add_action('wp_ajax_ves_brand_audit_start', [__CLASS__, 'brand_audit_start']);
        add_action('wp_ajax_ves_brand_audit_poll', [__CLASS__, 'brand_audit_poll']);
        add_action('wp_ajax_ves_brand_audit_active', [__CLASS__, 'brand_audit_active']);
        add_action('wp_ajax_ves_brand_audit_history', [__CLASS__, 'brand_audit_history']);
        add_action('wp_ajax_ves_brand_audit_abort', [__CLASS__, 'brand_audit_abort']);
        add_action('wp_ajax_ves_evidence_summary', [__CLASS__, 'evidence_summary']);
        add_action('wp_ajax_ves_evidence_list', [__CLASS__, 'evidence_list']);
        add_action('wp_ajax_ves_evidence_get', [__CLASS__, 'evidence_get']);
        add_action('wp_ajax_ves_assistant_ask', [__CLASS__, 'assistant_ask']);
        add_action('wp_ajax_ves_intelligence_summary', [__CLASS__, 'intelligence_summary']);
        add_action('wp_ajax_ves_metric_snapshots_list', [__CLASS__, 'metric_snapshots_list']);
        add_action('wp_ajax_ves_pattern_candidates_list', [__CLASS__, 'pattern_candidates_list']);
        add_action('wp_ajax_ves_insight_records_list', [__CLASS__, 'insight_records_list']);
        add_action('wp_ajax_ves_opportunity_records_list', [__CLASS__, 'opportunity_records_list']);
        add_action('wp_ajax_ves_insight_update_status', [__CLASS__, 'insight_update_status']);
        add_action('wp_ajax_ves_opportunity_update_status', [__CLASS__, 'opportunity_update_status']);
        add_action('wp_ajax_ves_assistant_thread_messages', [__CLASS__, 'assistant_thread_messages']);
        add_action('wp_ajax_ves_brand_audit_backfill_intelligence', [__CLASS__, 'brand_audit_backfill_intelligence']);
        add_action('wp_ajax_ves_schema_health_check', [__CLASS__, 'schema_health_check']);
        add_action('wp_ajax_ves_schema_repair', [__CLASS__, 'schema_repair']);
        add_action('wp_ajax_ves_evidence_refs_count', [__CLASS__, 'evidence_refs_count']);
        add_action('wp_ajax_ves_evidence_refs_repair', [__CLASS__, 'evidence_refs_repair']);
        add_action('wp_ajax_ves_trend_memory_repair_count', [__CLASS__, 'trend_memory_repair_count']);
        add_action('wp_ajax_ves_trend_memory_repair_dry_run', [__CLASS__, 'trend_memory_repair_dry_run']);
        add_action('wp_ajax_ves_trend_memory_repair_apply', [__CLASS__, 'trend_memory_repair_apply']);
        add_action('wp_ajax_ves_apify_slot_diagnostics', [__CLASS__, 'apify_slot_diagnostics']);
        add_action('wp_ajax_ves_generate_brief_from_opportunity', [__CLASS__, 'generate_brief_from_opportunity']);
        add_action('wp_ajax_ves_brief_records_list', [__CLASS__, 'brief_records_list']);
        add_action('wp_ajax_ves_brief_update_status', [__CLASS__, 'brief_update_status']);
        add_action('wp_ajax_ves_brief_library_list', [__CLASS__, 'brief_library_list']);
        add_action('wp_ajax_ves_workflow_events_list', [__CLASS__, 'workflow_events_list']);
        add_action('wp_ajax_ves_usage_estimate', [__CLASS__, 'usage_estimate']);
        add_action('wp_ajax_ves_trend_monitors_list', [__CLASS__, 'trend_monitors_list']);
        add_action('wp_ajax_ves_trend_monitors_save', [__CLASS__, 'trend_monitors_save']);
        add_action('wp_ajax_ves_trend_monitors_delete', [__CLASS__, 'trend_monitors_delete']);
        add_action('wp_ajax_ves_usage_summary', [__CLASS__, 'usage_summary']);
        add_action('wp_ajax_ves_project_context_get', [__CLASS__, 'project_context_get']);
        add_action('wp_ajax_ves_project_context_save', [__CLASS__, 'project_context_save']);
        add_action('wp_ajax_ves_workspace_knowledge_list', [__CLASS__, 'workspace_knowledge_list']);
        add_action('wp_ajax_ves_workspace_knowledge_upload', [__CLASS__, 'workspace_knowledge_upload']);
        add_action('wp_ajax_ves_workspace_knowledge_ingest', [__CLASS__, 'workspace_knowledge_ingest']);
        add_action('wp_ajax_ves_workspace_knowledge_delete', [__CLASS__, 'workspace_knowledge_delete']);
        add_action('wp_ajax_ves_memory_list', [__CLASS__, 'memory_list']);
        add_action('wp_ajax_ves_memory_get', [__CLASS__, 'memory_get']);

        // v0.9.24.80: public/nopriv AJAX is disabled for intelligence data.
        // All evidence, usage, memory, polling and report endpoints now require an
        // authenticated session plus their existing nonce/run/project-scope checks.
    }

    private static function guard_nopriv_read_action($action) {
        $action = sanitize_key((string) $action);
        $ip = function_exists('ves_get_ip') ? ves_get_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'ves_nopriv_read_' . md5($action . '|' . (string) $ip);
        $current = (int) get_transient($key);
        $limit = (int) apply_filters('ves_nopriv_read_rate_limit', 45, $action);
        if ($current >= $limit) {
            wp_send_json_error([
                'message' => 'Demasiadas solicitudes. Espera un momento y vuelve a intentarlo.',
                'requestId' => self::request_id(),
                'source' => 'ajax_rate_limit',
                'details' => ['action' => $action],
            ], 429);
        }
        set_transient($key, $current + 1, 60);
    }

    private static function require_panel_access($request_id, $source, $stage) {
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para usar esta herramienta.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'login_required']);
        }
        self::deny_if_role_forbidden($request_id, $source, $stage);
    }


    private static function require_project_access_from_request($request_id, $source, $stage, $request = []) {
        $request = is_array($request) ? $request : [];
        if (!class_exists('VES_Projects')) { return 0; }
        try {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($request, ['user_id' => $user_id]), $user_id) : $user_id;
            foreach (['project_id', 'projectId'] as $key) {
                if (empty($request[$key]) || !is_numeric($request[$key])) { continue; }
                $project_id = max(0, (int) $request[$key]);
                if ($project_id > 0 && !VES_Projects::user_can_access_project($project_id, $user_id, $workspace_id)) {
                    self::error('No autorizado para usar este proyecto.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'project_scope']);
                }
                return $project_id;
            }
            return (int) VES_Projects::resolve_project_from_request($request, $user_id, $workspace_id);
        } catch (Throwable $project_error) {
            self::log('project_scope_degraded', $project_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'stage' => $stage,
                'exception_class' => get_class($project_error),
                'file' => $project_error->getFile(),
                'line' => $project_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            if (current_user_can('manage_options')) {
                return 0;
            }
            self::error('Project access check failed before dispatch.', 403, $request_id, $source, [
                'stage' => $stage,
                'error_category' => 'module_access_denied',
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
        }
    }

    private static function standard_run_owner_key($run_id) {
        $run_id = sanitize_text_field((string) $run_id);
        return $run_id !== '' ? 'ves_std_run_owner_' . md5($run_id) : '';
    }

    private static function public_run_token_key($token) {
        $token = sanitize_text_field((string) $token);
        return $token !== '' ? 'ves_public_run_token_' . md5($token) : '';
    }

    private static function is_public_run_token($value) {
        return is_string($value) && preg_match('/^vesrun[a-f0-9]{32}$/i', $value);
    }

    private static function issue_public_run_token($provider_run_id, $platform = '') {
        $provider_run_id = sanitize_text_field((string) $provider_run_id);
        if ($provider_run_id === '') { return ''; }
        $platform = sanitize_key((string) $platform);
        $scope = self::current_standard_run_scope();
        $seed = $provider_run_id . '|' . $platform . '|' . microtime(true) . '|' . (function_exists('wp_rand') ? (string) wp_rand() : (string) random_int(100000, 999999));
        if (function_exists('wp_salt')) { $seed .= '|' . wp_salt('auth'); }
        $token = 'vesrun' . substr(hash('sha256', $seed), 0, 32);
        set_transient(self::public_run_token_key($token), [
            'provider_run_id' => $provider_run_id,
            'platform' => $platform,
            'user_id' => (int) ($scope['user_id'] ?? 0),
            'session_key' => (string) ($scope['session_key'] ?? ''),
            'created_at' => time(),
        ], 2 * DAY_IN_SECONDS);
        return $token;
    }

    private static function replace_provider_run_id_for_public($data) {
        if (!is_array($data)) { return $data; }
        $platform = sanitize_key((string) ($data['platform'] ?? ''));
        foreach (['run_id', 'runId'] as $key) {
            if (!isset($data[$key]) || is_array($data[$key]) || is_object($data[$key])) { continue; }
            $raw = sanitize_text_field((string) $data[$key]);
            if ($raw !== '' && !self::is_public_run_token($raw)) {
                $token = self::issue_public_run_token($raw, $platform);
                if ($token !== '') { $data[$key] = $token; }
            }
        }
        return $data;
    }

    private static function resolve_public_run_id($run_id, $platform, $request_id, $source, $stage) {
        $run_id = sanitize_text_field((string) $run_id);
        $platform = sanitize_key((string) $platform);
        if (!self::is_public_run_token($run_id)) { return $run_id; }
        $entry = get_transient(self::public_run_token_key($run_id));
        if (!$entry || !is_array($entry)) {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'public_run_token_missing']);
        }
        $stored_platform = sanitize_key((string) ($entry['platform'] ?? ''));
        if ($platform !== '' && $stored_platform !== '' && $platform !== $stored_platform) {
            self::error('No autorizado para consultar este proceso.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'public_run_token_platform_mismatch']);
        }
        if (!self::standard_run_scope_matches($entry)) {
            self::error('No autorizado para acceder a este proceso.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'public_run_token_scope_mismatch']);
        }
        $provider_run_id = sanitize_text_field((string) ($entry['provider_run_id'] ?? ''));
        if ($provider_run_id === '') {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'public_run_token_empty']);
        }
        return $provider_run_id;
    }

    private static function current_standard_run_scope() {
        if (is_user_logged_in()) {
            $user_id = (int) get_current_user_id();
            return [
                'user_id' => $user_id,
                'session_key' => 'user:' . $user_id,
            ];
        }

        $session_key = '';
        if (class_exists('VES_Temp_Memory')) {
            $session_key = (string) VES_Temp_Memory::maybe_set_session_cookie();
        }
        if ($session_key === '' && function_exists('ves_get_ip')) {
            $session_key = 'ip:' . md5((string) ves_get_ip());
        }

        return [
            'user_id' => 0,
            'session_key' => sanitize_text_field($session_key),
        ];
    }

    private static function remember_standard_run_owner($run_id, $platform, $usage_key = '', $actor_slug = '') {
        $key = self::standard_run_owner_key($run_id);
        if ($key === '') {
            return;
        }

        $scope = self::current_standard_run_scope();
        set_transient($key, [
            'user_id' => (int) ($scope['user_id'] ?? 0),
            'session_key' => (string) ($scope['session_key'] ?? ''),
            'platform' => sanitize_key((string) $platform),
            'usage_key' => sanitize_text_field((string) $usage_key),
            // v0.9.24.65 BUG-A fix: store actor_slug so poll() can avoid rebuilding input.
            'actor_slug' => sanitize_text_field((string) $actor_slug),
            'created_at' => time(),
        ], 2 * DAY_IN_SECONDS);
    }

    private static function standard_run_scope_matches($owner) {
        if (!is_array($owner)) {
            return false;
        }

        $owner_user_id = (int) ($owner['user_id'] ?? 0);
        if ($owner_user_id > 0) {
            return is_user_logged_in() && get_current_user_id() === $owner_user_id;
        }

        $current = self::current_standard_run_scope();
        $current_session = (string) ($current['session_key'] ?? '');
        $owner_session = (string) ($owner['session_key'] ?? '');
        return $current_session !== '' && $owner_session !== '' && hash_equals($owner_session, $current_session);
    }

    private static function require_standard_run_access($run_id, $platform, $request_id, $source, $stage) {
        if (current_user_can('manage_options')) {
            return;
        }

        $key = self::standard_run_owner_key($run_id);
        $owner = $key !== '' ? get_transient($key) : false;
        if (!$owner || !is_array($owner)) {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'run_owner_missing']);
        }

        $stored_platform = sanitize_key((string) ($owner['platform'] ?? ''));
        $platform = sanitize_key((string) $platform);
        if ($platform !== '' && $stored_platform !== '' && $platform !== $stored_platform) {
            self::error('No autorizado para consultar este proceso.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'platform_mismatch']);
        }

        if (!self::standard_run_scope_matches($owner)) {
            self::error('No autorizado para acceder a este proceso.', 403, $request_id, $source, ['stage' => $stage, 'reason' => 'run_owner_mismatch']);
        }
    }

    private static function redact_sensitive_payload_for_preview($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $key_string = (string) $key;
                if (preg_match('/(token|api[_-]?key|secret|password|authorization|bearer)/i', $key_string)) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = self::redact_sensitive_payload_for_preview($child);
            }
            return $out;
        }
        if (is_string($value) && preg_match('/^(Bearer\s+)?[A-Za-z0-9_\-]{24,}$/', $value)) {
            return '[redacted]';
        }
        return $value;
    }

    private static function actor_readiness_notes($platform, $actor_slug, $input, $request = []) {
        $platform = sanitize_key((string) $platform);
        $mode = sanitize_key((string) ($request['mode'] ?? ($input['searchType'] ?? '')));
        $notes = [];
        if ($actor_slug === '') {
            $notes[] = 'No actor slug is configured for this platform/mode.';
        }
        if ($platform === 'tiktok') {
            if (function_exists('ves_tiktok_actor_uses_apidojo_schema') && ves_tiktok_actor_uses_apidojo_schema($actor_slug)) {
                $notes[] = 'TikTok Discovery uses Apidojo as the primary candidate-search actor. Finite date windows are enforced locally after collection when the actor has no safe equivalent date field.';
                $notes[] = 'Selected-video Analyze is routed separately through the TikTok enrichment actor, not through this discovery actor.';
            } else {
                $notes[] = 'TikTok is using a legacy/fallback actor schema. Keep this as fallback only unless Apidojo is unavailable.';
            }
        }
        if (in_array($platform, ['pinterest'], true)) {
            $notes[] = 'This actor may require active paid rental in Apify before runs can start.';
        }
        if ($platform === 'semrush' && $mode === 'keyword_simple') {
            $notes[] = 'This Semrush keywords actor may require active paid rental in Apify.';
        }
        if ($platform === 'semrush' && $mode === 'keyword_magic') {
            $notes[] = 'Keyword Magic enforces maxResults >= 25; this payload is clamped server-side.';
        }
        if ($platform === 'reddit') {
            $notes[] = 'Reddit actor requires a proxy object; the preview should include proxy.useApifyProxy=true.';
        }
        if ($platform === 'ahrefs' && $mode === 'keyword_rank') {
            $notes[] = 'Keyword Rank requires both keyword and URL/domain.';
        }
        if ($platform === 'moz') {
            $notes[] = 'MOZ works best with clean root domains when paths return sparse data.';
        }
        return array_values(array_unique(array_filter($notes)));
    }


    private static function minimal_legacy_input_for_dispatch($platform, $request) {
        $platform = sanitize_key((string) $platform);
        $request = is_array($request) ? $request : [];
        $raw = (string) ($request['targets'] ?? $request['query'] ?? $request['keyword'] ?? $request['search'] ?? '');
        $lines = preg_split('/\r\n|\r|\n|,|;/u', $raw);
        $terms = [];
        $urls = [];
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '') { continue; }
            if (preg_match('~^https?://~i', $line)) { $urls[] = esc_url_raw($line); continue; }
            $term = sanitize_text_field(ltrim($line, '#@'));
            if ($term !== '') { $terms[] = $term; }
        }
        $terms = array_values(array_unique(array_filter($terms)));
        $urls = array_values(array_unique(array_filter($urls)));
        $limit = isset($request['limit']) ? max(1, min(500, (int) $request['limit'])) : 20;
        switch ($platform) {
            case 'tiktok':
                return !empty($urls) ? ['startUrls' => $urls, 'resultsPerPage' => $limit] : ['keywords' => array_slice($terms, 0, 5), 'resultsPerPage' => $limit];
            case 'youtube':
                return !empty($urls) ? ['startUrls' => $urls, 'maxResults' => $limit] : ['searchQueries' => array_slice($terms, 0, 5), 'maxResults' => $limit];
            case 'instagram':
                $hashtags = [];
                $profiles = [];
                foreach ($terms as $term) {
                    if (preg_match('/\s/u', $term)) { continue; }
                    $hashtags[] = ltrim($term, '#');
                }
                $out = ['resultsLimit' => $limit];
                if (!empty($urls)) { $out['directUrls'] = $urls; }
                if (!empty($hashtags)) { $out['hashtags'] = array_slice($hashtags, 0, 5); }
                if (!empty($profiles)) { $out['profiles'] = array_slice($profiles, 0, 5); }
                return $out;
            case 'twitter':
                return !empty($urls) ? ['startUrls' => $urls, 'maxItems' => $limit] : ['searchTerms' => array_slice($terms, 0, 5), 'maxItems' => $limit];
            case 'reddit':
                return !empty($urls) ? ['startUrls' => array_map(static fn($url) => ['url' => $url], $urls), 'maxItems' => $limit] : ['searches' => array_slice($terms, 0, 5), 'queries' => array_slice($terms, 0, 5), 'search' => (string) ($terms[0] ?? ''), 'maxItems' => $limit];
            case 'pinterest':
                return !empty($urls) ? ['startUrls' => $urls, 'maxItems' => $limit] : ['search' => (string) ($terms[0] ?? ''), 'maxItems' => $limit];
            case 'facebook':
                return ['startUrls' => array_map(static fn($url) => ['url' => $url], $urls), 'maxItems' => $limit];
            case 'facebook_ads':
                return !empty($urls) ? ['urls' => $urls, 'maxItems' => $limit] : ['searchTerms' => array_slice($terms, 0, 5), 'maxItems' => $limit];
            case 'google_ads':
                return !empty($terms) ? ['searchTargets' => array_slice($terms, 0, 5), 'text' => (string) ($terms[0] ?? ''), 'maxItems' => $limit] : ['domains' => $urls, 'maxItems' => $limit];
            case 'semrush':
                return ['keyword' => (string) ($terms[0] ?? ''), 'maxResults' => max(25, $limit)];
            case 'moz':
                return ['urls' => !empty($urls) ? $urls : $terms];
            case 'ahrefs':
                return ['urls' => !empty($urls) ? $urls : $terms, 'keyword' => (string) ($terms[0] ?? '')];
            default:
                return [];
        }
    }

    public static function payload_preview() {
        $request_id = self::request_id();
        try {
            $request = self::normalized_post();
            if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
                self::error('Sesión caducada. Refresca la página.', 403, $request_id, 'payload_preview_auth', ['stage' => 'nonce']);
            }
            if (!current_user_can('manage_options')) {
                self::error('Solo administradores pueden previsualizar payloads de actor.', 403, $request_id, 'payload_preview_auth', ['stage' => 'capability']);
            }
            if (empty($request['debugMode']) && empty($request['debug_mode']) && empty($request['vesDebug'])) {
                self::error('Payload preview is available only from Admin Diagnostics or explicit debug mode.', 403, $request_id, 'payload_preview_auth', ['stage' => 'debug_mode_required']);
            }
            $platform = sanitize_key((string) ($request['platform'] ?? ''));
            if (!in_array($platform, ['tiktok', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'moz', 'ahrefs'], true)) {
                self::error('Plataforma no válida.', 400, $request_id, 'payload_preview_validation', ['stage' => 'platform']);
            }
            $input = function_exists('ves_build_platform_input') ? ves_build_platform_input($platform, $request) : [];
            $input = self::enforce_locked_actor_constraints($platform, $request, $input);
            $execution_plan = self::build_scrape_execution_plan($platform, $request, $input);
            $actor_slug = function_exists('ves_get_actor_slug') ? ves_get_actor_slug($platform, $request) : '';
            $valid = function_exists('ves_validate_platform_input') ? ves_validate_platform_input($platform, $input) : !empty($input);
            $contract_validation = ($valid && function_exists('ves_validate_actor_payload_contract')) ? ves_validate_actor_payload_contract($platform, $actor_slug, $input) : true;
            if (is_wp_error($contract_validation)) {
                $valid = false;
                $message = $contract_validation->get_error_message();
            } else {
                $message = $valid ? 'Payload válido para dispatch.' : (function_exists('ves_invalid_input_message') ? ves_invalid_input_message($platform, $request) : 'Payload inválido.');
            }
            $summary = self::summarize_input($platform, $input, $request);
            self::success([
                'request_id' => $request_id,
                'platform' => $platform,
                'mode' => sanitize_key((string) ($request['mode'] ?? '')),
                'actor_slug' => sanitize_text_field((string) $actor_slug),
                'valid' => (bool) $valid,
                'message' => $message,
                'summary' => $summary,
                'readiness_notes' => self::actor_readiness_notes($platform, $actor_slug, $input, $request),
                'execution_plan' => $execution_plan,
                'actor_input' => self::redact_sensitive_payload_for_preview($input),
            ]);
        } catch (Throwable $e) {
            self::fatal($e, $request_id, 'payload_preview_fatal', ['action' => 'ves_payload_preview']);
        }
    }

    public static function start() {
        $request_id = self::request_id();
        self::$active_run_context = ['request_id' => $request_id, 'action' => 'ves_start'];
        self::register_ajax_shutdown_handler($request_id, 'ves_start');
        try {
            self::start_impl($request_id);
        } catch (Throwable $e) {
            self::fatal($e, $request_id, 'ajax_start_fatal', ['action' => 'ves_start']);
        }
    }

    private static function start_impl($request_id) {
        $request = self::normalized_post();
        $initial_platform = isset($request['platform']) ? sanitize_key((string) $request['platform']) : '';
        self::log('ajax_start_preflight', 'Standard scraper AJAX handler entered', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform' => $initial_platform,
            'action' => 'ves_start',
            'stage' => 'request_received',
        ]);
        self::log_run_stage($request_id, $initial_platform, 'request_received', [
            'provider_call_attempted' => false,
            'provider_run_created' => false,
            'credits_reserved' => false,
        ]);

        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada. Refresca la página.', 403, $request_id, 'ajax_auth', ['stage' => 'nonce']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para usar esta herramienta.', 403, $request_id, 'ajax_auth', ['stage' => 'login_required']);
        }
        self::deny_if_role_forbidden($request_id, 'ajax_auth', 'role_start');
        self::require_project_access_from_request($request_id, 'ajax_auth', 'project_scope_start', $request);

        $platform = sanitize_key((string) ($request['platform'] ?? ''));
        if (self::production_mvp_enabled() && in_array($platform, self::production_mvp_disabled_platforms(), true)) {
            self::mvp_disabled_ajax_response($request_id, 'ajax_mvp_gate', self::production_mvp_module_key_for_platform($platform), [
                'stage' => 'production_mvp_gate',
                'platform' => $platform,
            ]);
        }

        if (!ves_get_token()) {
            self::error('Backend token is not configured. Save the Backend / Apify token in VE Social Scraper settings or define VE_SOCIAL_BACKEND_TOKEN.', 500, $request_id, 'ajax_config', ['stage' => 'missing_backend_token', 'error_category' => 'missing_backend_token', 'provider_call_attempted' => false, 'provider_run_created' => false, 'credits_reserved' => false]);
        }

        $platform = sanitize_key((string) ($request['platform'] ?? ''));
        if (!in_array($platform, ['tiktok', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'moz', 'ahrefs'], true)) {
            self::error('Plataforma no válida.', 400, $request_id, 'ajax_validation', ['stage' => 'invalid_platform', 'error_category' => 'invalid_platform', 'provider_call_attempted' => false, 'provider_run_created' => false, 'credits_reserved' => false]);
        }
        self::log_run_stage($request_id, $platform, 'access_checked', ['provider_call_attempted' => false, 'provider_run_created' => false, 'credits_reserved' => false]);

        // v0.9.26.0 — Phase 1 server-side enforcement. Block locked/hidden
        // modules and providers BEFORE any credit reservation or provider call.
        // Each AJAX entry uses the appropriate module key.
        if (class_exists('VES_Access_Control')) {
            try {
                $module_key = in_array($platform, ['facebook_ads','google_ads'], true)
                    ? 'ads'
                    : ($platform === 'linkedin' ? 'linkedin'
                    : (in_array($platform, ['semrush','moz','ahrefs'], true) ? 'seo' : 'social'));
                $user_id = get_current_user_id();
                if (!VES_Access_Control::user_can_access_module($user_id, $module_key)) {
                    self::error('This source is not enabled for your account.', 403, $request_id, 'ajax_access_control', [
                        'stage' => 'module_access_denied',
                        'error_category' => 'module_access_denied',
                        'module_key' => $module_key,
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                        'credits_reserved' => false,
                    ]);
                }
                $provider_map = [
                    'tiktok' => 'tiktok', 'instagram' => 'instagram', 'youtube' => 'youtube',
                    'reddit' => 'reddit', 'twitter' => 'twitter', 'facebook' => 'facebook',
                    'pinterest' => 'pinterest', 'linkedin' => 'linkedin',
                    'facebook_ads' => 'meta_ads', 'google_ads' => 'google_ads',
                    'semrush' => 'semrush', 'moz' => 'moz', 'ahrefs' => 'ahrefs',
                ];
                $provider_key = $provider_map[$platform] ?? '';
                if ($provider_key !== '' && !VES_Access_Control::user_can_access_provider($user_id, $provider_key)) {
                    self::error('This source is not enabled for your account.', 403, $request_id, 'ajax_access_control', [
                        'stage' => 'provider_access_denied',
                        'error_category' => 'provider_access_denied',
                        'module_key' => $module_key,
                        'provider_key' => $provider_key,
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                        'credits_reserved' => false,
                    ]);
                }
            } catch (Throwable $access_error) {
                self::log('access_control_degraded', $access_error->getMessage(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'stage' => 'access_control_throwable_degraded',
                    'exception_class' => get_class($access_error),
                    'file' => $access_error->getFile(),
                    'line' => $access_error->getLine(),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                ]);
                if (!current_user_can('manage_options')) {
                    self::error('Access control failed before dispatch.', 403, $request_id, 'ajax_access_control', [
                        'stage' => 'access_control_throwable',
                        'error_category' => 'module_access_denied',
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                }
            }
        }

        $is_post_url_mode = function_exists('ves_is_post_url_mode') ? ves_is_post_url_mode($request) : false;
        $is_profile_url_mode = function_exists('ves_is_profile_url_mode') ? ves_is_profile_url_mode($request) : false;
        $is_mixed_url_mode = function_exists('ves_is_mixed_url_mode') ? ves_is_mixed_url_mode($request) : false;

        // v0.9.26.0 — Phase 8: LinkedIn pre-dispatch validation guard.
        // Block invalid/unsupported LinkedIn input BEFORE any provider call so
        // no credits are reserved or spent. The configured actor in this build
        // mainly supports post analysis; persona/company modes require admin
        // actor setup. We accept any of: targets text, supported objective with
        // a URL, or a post URL field.
        if ($platform === 'linkedin') {
            $li_targets = isset($request['targets']) ? trim((string) $request['targets']) : '';
            $li_objective = isset($request['linkedinObjective']) ? sanitize_key((string) $request['linkedinObjective']) : '';
            $li_post_url = isset($request['postUrls']) ? trim((string) $request['postUrls']) : '';
            $li_post_url = $li_post_url !== '' ? $li_post_url : (isset($request['linkedinPostUrls']) ? trim((string) $request['linkedinPostUrls']) : '');
            $supported_objectives = ['analyze_posts','thought_leadership_topic_scan'];
            $is_supported_objective = in_array($li_objective, $supported_objectives, true);
            $has_targets = $li_targets !== '';
            $has_post_url = $li_post_url !== '' && stripos($li_post_url, 'linkedin.com') !== false;
            if (!$is_supported_objective) {
                wp_send_json([
                    'success' => false,
                    'code'    => 'linkedin_unsupported_objective',
                    'title'   => 'Objetivo LinkedIn no soportado',
                    'message' => 'Este modo de LinkedIn requiere una integración compatible de perfiles/empresas. Cambia a Analizar posts o Escaneo de liderazgo temático, o pide al administrador que active una fuente compatible.',
                    'charge'  => 0,
                    'credits_refunded' => true,
                    'request_id' => $request_id,
                ], 400);
                exit;
            }
            if (!$has_targets && !$has_post_url) {
                wp_send_json([
                    'success' => false,
                    'code'    => 'linkedin_missing_input',
                    'title'   => 'Falta entrada para LinkedIn',
                    'message' => 'LinkedIn necesita un tema o una URL de post antes de ejecutarse en esta instalación.',
                    'charge'  => 0,
                    'credits_refunded' => true,
                    'request_id' => $request_id,
                ], 400);
                exit;
            }
        }

        self::log_run_stage($request_id, $platform, 'input_build_started', ['provider_call_attempted' => false, 'provider_run_created' => false]);
        self::log('ajax_start_stage', 'Building provider input', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform' => $platform,
            'stage' => 'input_build_started',
        ]);
        // v0.9.24.4: load any items accumulated in a previous continuation run.
        $continuation_token = sanitize_key((string) ($request['continuationToken'] ?? ''));
        $continuation_items = $continuation_token !== '' ? self::load_continuation_items($continuation_token) : [];

        try {
            $input = function_exists('ves_build_platform_input') ? ves_build_platform_input($platform, $request) : [];
            $input = self::enforce_locked_actor_constraints($platform, $request, $input);
            $execution_plan = self::build_scrape_execution_plan($platform, $request, $input);
        } catch (Throwable $input_error) {
            self::log('input_build_fallback', $input_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'stage' => 'input_build_fallback_to_minimal_legacy_payload',
                'exception_class' => get_class($input_error),
                'file' => $input_error->getFile(),
                'line' => $input_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            $input = self::minimal_legacy_input_for_dispatch($platform, $request);
            $execution_plan = [];
        }
        try {
            $actor_slug = function_exists('ves_get_actor_slug') ? ves_get_actor_slug($platform, $request) : '';
        } catch (Throwable $actor_error) {
            self::log('actor_slug_resolution_failed', $actor_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'stage' => 'actor_slug_resolution_failed',
                'exception_class' => get_class($actor_error),
                'file' => $actor_error->getFile(),
                'line' => $actor_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            $actor_slug = '';
        }
        self::log_run_stage($request_id, $platform, 'input_build_completed', [
            'actor_slug' => $actor_slug,
            'input_summary' => self::summarize_input($platform, $input, $request),
            'sanitized_actor_input_sample' => self::redact_sensitive_payload_for_preview($input),
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);
        self::log('ajax_start_stage', 'Provider input built', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform' => $platform,
            'stage' => 'input_build_completed',
            'actor_slug' => $actor_slug,
            'summary' => self::summarize_input($platform, $input, $request),
        ]);
        if ($actor_slug === '') {
            self::error('Plataforma no configurada.', 500, $request_id, 'ajax_config', ['platform' => $platform, 'stage' => 'missing_actor_slug', 'error_category' => 'invalid_platform', 'provider_call_attempted' => false, 'provider_run_created' => false]);
        }

        if (class_exists('VES_Query_Planner')) {
            try {
                $intent_validation = method_exists('VES_Query_Planner', 'validate_scraper_query')
                    ? VES_Query_Planner::validate_scraper_query('standard', $platform, $request)
                    : true;
                if (is_wp_error($intent_validation)) {
                    // v0.9.30.12 hotfix: canonical query validation is advisory
                    // for standard Social Media runs. It must not stop dispatch
                    // before Apify when the legacy payload is valid. Keep the
                    // reason in Diagnostics and let the normal payload validator
                    // decide whether the request can run.
                    $data = $intent_validation->get_error_data();
                    $data = is_array($data) ? $data : [];
                    self::log('query_planner_validation_fallback', $intent_validation->get_error_message(), [
                        'request_id' => $request_id,
                        'public_support_code' => self::public_support_code($request_id),
                        'platform' => $platform,
                        'actor_slug' => $actor_slug,
                        'stage' => 'canonical_query_planner_validation_fallback',
                        'error_code' => $intent_validation->get_error_code(),
                        'seed_suggestions' => $data['seed_suggestions'] ?? [],
                        'requested_objective' => $data['requested_objective'] ?? '',
                        'setup_required' => !empty($data['setup_required']),
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                        'credits_reserved' => false,
                        'summary' => self::summarize_input($platform, is_array($input) ? $input : [], $request),
                    ]);
                    self::log_run_stage($request_id, $platform, 'query_planner_validation_fallback', [
                        'actor_slug' => $actor_slug,
                        'input_summary' => self::summarize_input($platform, is_array($input) ? $input : [], $request),
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                }
                $normalized_request = method_exists('VES_Query_Planner', 'normalize_request_fields')
                    ? VES_Query_Planner::normalize_request_fields('standard', $platform, $request)
                    : [];
                $planned_input = method_exists('VES_Query_Planner', 'build_actor_payload')
                    ? VES_Query_Planner::build_actor_payload('standard', $platform, $actor_slug, $normalized_request)
                    : [];
                if (is_array($planned_input) && !empty($planned_input)) {
                    $input = $planned_input;
                }
                if (method_exists('VES_Query_Planner', 'prune_payload_for_actor_contract')) {
                    $input = VES_Query_Planner::prune_payload_for_actor_contract($platform, $actor_slug, $input);
                }
                $input = self::enforce_locked_actor_constraints($platform, $request, $input);
                $execution_plan = self::build_scrape_execution_plan($platform, $request, $input);
                self::log_run_stage($request_id, $platform, 'query_planner_applied', [
                    'actor_slug' => $actor_slug,
                    'input_summary' => self::summarize_input($platform, $input, $request),
                    'sanitized_actor_input_sample' => self::redact_sensitive_payload_for_preview($input),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                ]);
                self::log('ajax_start_stage', 'Canonical query planner applied', [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'stage' => 'query_planner_applied',
                    'actor_slug' => $actor_slug,
                    'summary' => self::summarize_input($platform, $input, $request),
                ]);
            } catch (Throwable $planner_error) {
                // v0.9.30.11 hotfix: the canonical planner must never be a hard stop
                // before the source dispatch. Keep the legacy actor input and continue,
                // while preserving the technical reason for admins in Diagnostics.
                self::log('query_planner_fallback', $planner_error->getMessage(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                    'stage' => 'query_planner_fallback_to_legacy_input',
                    'exception_class' => get_class($planner_error),
                    'file' => $planner_error->getFile(),
                    'line' => $planner_error->getLine(),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                    'credits_reserved' => false,
                ]);
                self::log_run_stage($request_id, $platform, 'query_planner_fallback_to_legacy_input', [
                    'actor_slug' => $actor_slug,
                    'input_summary' => self::summarize_input($platform, $input, $request),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                ]);
            }
        }
        try {
            $platform_input_valid = function_exists('ves_validate_platform_input') ? ves_validate_platform_input($platform, $input) : !empty($input);
        } catch (Throwable $validation_error) {
            self::log('platform_input_validation_degraded', $validation_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'stage' => 'platform_input_validation_throwable_degraded',
                'exception_class' => get_class($validation_error),
                'file' => $validation_error->getFile(),
                'line' => $validation_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            $platform_input_valid = is_array($input) && !empty($input);
        }
        if (!$platform_input_valid) {
            self::error(
                function_exists('ves_invalid_input_message') ? ves_invalid_input_message($platform, $request) : 'Introduce al menos un objetivo válido.',
                400,
                $request_id,
                'ajax_validation',
                ['platform' => $platform, 'stage' => 'invalid_input', 'error_category' => 'invalid_input', 'summary' => self::summarize_input($platform, $input, $request), 'provider_call_attempted' => false, 'provider_run_created' => false]
            );
        }
        if (function_exists('ves_validate_actor_payload_contract')) {
            try {
                $contract_validation = ves_validate_actor_payload_contract($platform, $actor_slug, $input);
                if (is_wp_error($contract_validation)) {
                    $raw_contract_message = $contract_validation->get_error_message();
                    $soft_dispatch_platforms = ['tiktok','youtube','instagram','facebook','twitter','reddit','pinterest'];
                    $legacy_payload_valid = function_exists('ves_validate_platform_input') ? ves_validate_platform_input($platform, $input) : !empty($input);
                    if (in_array($platform, $soft_dispatch_platforms, true) && $legacy_payload_valid) {
                        // v0.9.30.12 hotfix: if the contract validator is stricter
                        // than the legacy payload builder, do not stop the Social
                        // source before Apify. Let Apify return the real provider
                        // response and keep the mismatch in admin Diagnostics.
                        self::log('actor_payload_contract_soft_bypass', $raw_contract_message, [
                            'request_id' => $request_id,
                            'public_support_code' => self::public_support_code($request_id),
                            'platform' => $platform,
                            'actor_slug' => $actor_slug,
                            'stage' => 'actor_payload_contract_soft_bypass',
                            'error_code' => $contract_validation->get_error_code(),
                            'error_data' => self::request_debug_enabled() ? $contract_validation->get_error_data() : ['admin_diagnostics_available' => true],
                            'summary' => self::summarize_input($platform, $input, $request),
                            'provider_call_attempted' => false,
                            'provider_run_created' => false,
                        ]);
                        self::log_run_stage($request_id, $platform, 'actor_payload_contract_soft_bypass', [
                            'actor_slug' => $actor_slug,
                            'input_summary' => self::summarize_input($platform, $input, $request),
                            'provider_call_attempted' => false,
                            'provider_run_created' => false,
                        ]);
                    } else {
                        $public_contract_message = self::request_debug_enabled()
                            ? $raw_contract_message
                            : (class_exists('VES_Query_Planner')
                                ? VES_Query_Planner::provider_error_public_message($contract_validation->get_error_code(), $raw_contract_message)
                                : 'This source is not configured correctly. Admin diagnostics has details.');
                        self::error($public_contract_message, 400, $request_id, 'ajax_validation', [
                            'platform' => $platform,
                            'actor_slug' => $actor_slug,
                            'stage' => 'actor_payload_contract',
                            'error_code' => $contract_validation->get_error_code(),
                            'error_data' => self::request_debug_enabled() ? $contract_validation->get_error_data() : ['admin_diagnostics_available' => true],
                            'summary' => self::summarize_input($platform, $input, $request),
                            'error_category' => 'actor_payload_contract_failed',
                            'provider_call_attempted' => false,
                            'provider_run_created' => false,
                        ]);
                    }
                }
            } catch (Throwable $contract_error) {
                self::log('actor_payload_contract_validator_fallback', $contract_error->getMessage(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                    'stage' => 'actor_payload_contract_validator_fallback',
                    'exception_class' => get_class($contract_error),
                    'file' => $contract_error->getFile(),
                    'line' => $contract_error->getLine(),
                    'summary' => self::summarize_input($platform, is_array($input) ? $input : [], $request),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                ]);
                self::log_run_stage($request_id, $platform, 'actor_payload_contract_validator_fallback', [
                    'actor_slug' => $actor_slug,
                    'input_summary' => self::summarize_input($platform, is_array($input) ? $input : [], $request),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                ]);
            }
        }

        self::log_run_stage($request_id, $platform, 'payload_contract_validated', [
            'actor_slug' => $actor_slug,
            'input_summary' => self::summarize_input($platform, $input, $request),
            'sanitized_actor_input_sample' => self::redact_sensitive_payload_for_preview($input),
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);

        $mode = sanitize_key((string) ($request['mode'] ?? ($input['searchType'] ?? '')));
        $standard_dispatch_plan = [
            'module' => 'standard',
            'platform' => $platform,
            'actor' => $actor_slug,
            'input' => $input,
            'user_id' => (int) get_current_user_id(),
            'project_id' => sanitize_text_field((string) ($request['project_id'] ?? ($request['projectId'] ?? ''))),
        ];
        $source_execution_key = self::source_execution_key('standard', $platform, $actor_slug, $input, $mode, 'standard:' . (string) get_current_user_id() . ':' . (string) ($standard_dispatch_plan['project_id'] ?? ''));
        self::log_run_stage($request_id, $platform, 'source_execution_key_created', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key,
            'input_summary' => self::summarize_input($platform, $input, $request),
            'sanitized_actor_input_sample' => self::redact_sensitive_payload_for_preview($input),
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);
        self::log_run_stage($request_id, $platform, 'duplicate_checked', ['actor_slug' => $actor_slug, 'source_execution_key' => $source_execution_key, 'provider_call_attempted' => false]);
        try {
            self::maybe_return_duplicate_source_execution($source_execution_key, $request_id, $platform, $actor_slug);
        } catch (Throwable $duplicate_error) {
            self::log('duplicate_guard_degraded', $duplicate_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key,
                'stage' => 'duplicate_guard_throwable_degraded',
                'exception_class' => get_class($duplicate_error),
                'file' => $duplicate_error->getFile(),
                'line' => $duplicate_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            self::log_run_stage($request_id, $platform, 'duplicate_guard_degraded_continue_dispatch', [
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key,
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
        }
        self::log_run_stage($request_id, $platform, 'provider_cost_checked', ['actor_slug' => $actor_slug, 'source_execution_key' => $source_execution_key, 'provider_call_attempted' => false]);
        try {
            self::enforce_provider_cost_limit_before_dispatch($request_id, $platform, $actor_slug, 1, null);
        } catch (Throwable $cost_error) {
            self::log('provider_cost_guard_degraded', $cost_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key,
                'stage' => 'provider_cost_guard_throwable_degraded',
                'exception_class' => get_class($cost_error),
                'file' => $cost_error->getFile(),
                'line' => $cost_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            if (!current_user_can('manage_options')) {
                self::error('Provider cost guard failed before dispatch.', 402, $request_id, 'provider_cost_guard', [
                    'stage' => 'provider_cost_guard_throwable',
                    'error_category' => 'provider_cost_guard_blocked',
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                    'credits_reserved' => false,
                ]);
            }
        }
        try {
            $start_gate_key = self::acquire_start_gate(
                self::start_gate_key('standard', $platform, md5((string) wp_json_encode($standard_dispatch_plan))),
                $request_id,
                'ajax_rate_limit',
                ['platform' => $platform, 'actor_slug' => $actor_slug]
            );
        } catch (Throwable $gate_error) {
            self::log('start_gate_degraded', $gate_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key,
                'stage' => 'start_gate_throwable_degraded',
                'exception_class' => get_class($gate_error),
                'file' => $gate_error->getFile(),
                'line' => $gate_error->getLine(),
                'provider_call_attempted' => false,
                'provider_run_created' => false,
            ]);
            $start_gate_key = '';
            if (!current_user_can('manage_options')) {
                self::error('Start gate failed before dispatch.', 429, $request_id, 'ajax_rate_limit', [
                    'stage' => 'start_gate_throwable',
                    'error_category' => 'duplicate_dispatch_blocked',
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                    'credits_reserved' => false,
                ]);
            }
        }
        self::remember_active_run_context([
            'stage' => 'start_gate_acquired',
            'platform' => $platform,
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'start_gate_key' => $start_gate_key ?? '',
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);
        self::log('ajax_start_stage', 'Reserving usage before provider dispatch', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform' => $platform,
            'stage' => 'usage_reservation_started',
            'source_execution_key' => $source_execution_key ?? '',
            'start_gate_key' => $start_gate_key ?? '',
        ]);
        $usage_key = self::reserve_standard_usage_or_error($request_id, 'manual_run', 'usage_billing', ['platform' => $platform, 'type' => 'manual_run']);
        self::log_run_stage($request_id, $platform, 'usage_reserved', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'usage_key' => $usage_key,
            'credits_reserved' => true,
            'provider_call_attempted' => false,
            'provider_run_created' => false,
        ]);
        self::log('ajax_start_stage', 'Usage reserve passed', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform' => $platform,
            'stage' => 'usage_reserved',
            'usage_key' => $usage_key,
            'source_execution_key' => $source_execution_key ?? '',
        ]);
        try {
            $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : [];
            $url     = function_exists('ves_make_run_url') ? ves_make_run_url($actor_slug, $options) : '';
        } catch (Throwable $url_error) {
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, $url_error->getMessage());
            self::error('Source URL could not be prepared for dispatch.', 500, $request_id, 'ajax_config', [
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'stage' => 'provider_run_url_throwable',
                'error_category' => 'invalid_platform',
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => !empty($usage_key),
                'credits_refunded_or_voided' => !empty($usage_key),
            ]);
        }
        if ($url === '') {
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, 'Empty provider run URL.');
            self::error('Source URL could not be prepared for dispatch.', 500, $request_id, 'ajax_config', [
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'stage' => 'provider_run_url_missing',
                'error_category' => 'invalid_platform',
                'provider_call_attempted' => false,
                'provider_run_created' => false,
                'credits_reserved' => !empty($usage_key),
                'credits_refunded_or_voided' => !empty($usage_key),
            ]);
        }

        // v0.9.24.19: standard/manual scraper runs must use the same Apify
        // dispatch-slot guard as Trend Finder and Brand Audit. Otherwise the
        // settings for max active runs / heavy runs are silently bypassed.
        $dispatch_slot = '';
        if (method_exists('VES_Apify_Client', 'acquire_dispatch_slot')) {
            try {
                $dispatch_plan_hash_for_slot = md5((string) wp_json_encode(self::summarize_input($platform, is_array($input) ? $input : [], $request)));
                $dispatch_slot = VES_Apify_Client::acquire_dispatch_slot($actor_slug, (int) get_current_user_id(), [
                    'source' => 'standard_manual_run',
                    'request_id' => $request_id,
                    'platform' => $platform,
                    'dispatch_plan_hash' => $dispatch_plan_hash_for_slot,
                    'purpose' => 'primary',
                ]);
                if (is_wp_error($dispatch_slot)) {
                    self::release_start_gate($start_gate_key ?? '');
                    self::void_standard_usage($usage_key, $dispatch_slot->get_error_message());
                    $error_data = $dispatch_slot->get_error_data();
                    self::error($dispatch_slot->get_error_message(), 429, $request_id, 'actor_dispatch_slot_limit', [
                        'platform' => $platform,
                        'actor_slug' => $actor_slug,
                        'source_execution_key' => $source_execution_key ?? '',
                        'provider_state' => is_array($error_data) ? ($error_data['provider_state'] ?? '') : '',
                        'retry_after' => is_array($error_data) ? ($error_data['retry_after'] ?? '') : '',
                        'stage' => 'dispatch_slot_acquired',
                        'error_category' => 'dispatch_slot_limit',
                        'credits_reserved' => true,
                        'credits_refunded_or_voided' => true,
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                } else {
                    self::log_run_stage($request_id, $platform, 'dispatch_slot_acquired', [
                        'actor_slug' => $actor_slug,
                        'source_execution_key' => $source_execution_key ?? '',
                        'dispatch_slot_id' => $dispatch_slot,
                        'credits_reserved' => true,
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                }
            } catch (Throwable $slot_error) {
                self::log('dispatch_slot_degraded', $slot_error->getMessage(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                    'source_execution_key' => $source_execution_key ?? '',
                    'stage' => 'dispatch_slot_throwable_degraded',
                    'exception_class' => get_class($slot_error),
                    'file' => $slot_error->getFile(),
                    'line' => $slot_error->getLine(),
                    'provider_call_attempted' => false,
                    'provider_run_created' => false,
                    'credits_reserved' => !empty($usage_key),
                ]);
                $dispatch_slot = '';
                if (!current_user_can('manage_options')) {
                    self::release_start_gate($start_gate_key ?? '');
                    self::void_standard_usage($usage_key, $slot_error->getMessage());
                    self::error('Dispatch slot guard failed before dispatch.', 429, $request_id, 'actor_dispatch_slot_limit', [
                        'platform' => $platform,
                        'actor_slug' => $actor_slug,
                        'source_execution_key' => $source_execution_key ?? '',
                        'stage' => 'dispatch_slot_throwable',
                        'error_category' => 'dispatch_slot_limit',
                        'credits_reserved' => !empty($usage_key),
                        'credits_refunded_or_voided' => !empty($usage_key),
                        'provider_call_attempted' => false,
                        'provider_run_created' => false,
                    ]);
                }
            }
        }

        self::log_run_stage($request_id, $platform, 'provider_dispatch_attempted', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'dispatch_slot_id' => $dispatch_slot,
            'input_summary' => self::summarize_input($platform, $input, $request),
            'sanitized_actor_input_sample' => self::redact_sensitive_payload_for_preview($input),
            'provider_call_attempted' => true,
            'provider_run_created' => false,
            'credits_reserved' => true,
        ]);
        self::log('actor_dispatch', 'Sending request to provider', [
            'request_id' => $request_id,
            'public_support_code' => self::public_support_code($request_id),
            'platform'   => $platform,
            'actor_slug' => $actor_slug,
            'stage' => 'provider_dispatch_attempted',
            'source_execution_key' => $source_execution_key ?? '',
            'summary'    => self::summarize_input($platform, $input, $request),
        ]);

        try {
            $response = VES_Apify_Client::request('POST', $url, wp_json_encode($input));
        } catch (Throwable $dispatch_error) {
            $response = new WP_Error('apify_dispatch_throwable', 'Provider dispatch failed before returning a response.', [
                'status' => 500,
                'provider_state' => 'DISPATCH_THROWABLE',
                'technical_message' => $dispatch_error->getMessage(),
                'file' => $dispatch_error->getFile(),
                'line' => $dispatch_error->getLine(),
            ]);
            self::log('provider_dispatch_throwable', $dispatch_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'stage' => 'provider_dispatch_throwable',
                'exception_class' => get_class($dispatch_error),
                'file' => $dispatch_error->getFile(),
                'line' => $dispatch_error->getLine(),
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => !empty($usage_key),
            ]);
        }
        if (is_wp_error($response)) {
            if (!empty($dispatch_slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot((string) $dispatch_slot); }
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, $response->get_error_message());
            $error_data = $response->get_error_data();
            $error_data = is_array($error_data) ? $error_data : [];
            self::log_run_stage($request_id, $platform, 'provider_dispatch_failed', [
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'provider_http_status' => isset($error_data['status']) ? (int) $error_data['status'] : null,
                'provider_state' => sanitize_text_field((string) ($error_data['provider_state'] ?? '')),
                'error_category' => self::classify_error_category('actor_dispatch_error', $response->get_error_message(), (int) ($error_data['status'] ?? 500), array_merge($error_data, ['error_code' => $response->get_error_code()])),
                'error_code' => $response->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => true,
                'credits_refunded_or_voided' => true,
            ]);
            self::error($response->get_error_message(), (int) ($error_data['status'] ?? 500), $request_id, 'actor_dispatch_error', [
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'provider_http_status' => isset($error_data['status']) ? (int) $error_data['status'] : null,
                'provider_state' => sanitize_text_field((string) ($error_data['provider_state'] ?? '')),
                'error_code' => $response->get_error_code(),
                'stage' => 'provider_dispatch_failed',
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => true,
                'credits_refunded_or_voided' => true,
            ]);
        }

        $run_body = $response['body'];
        $started_run_id = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        if ($started_run_id === '') {
            if (!empty($dispatch_slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot((string) $dispatch_slot); }
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, 'Source run did not return a valid process identifier.');
            self::error('Source run did not return a valid process identifier.', 500, $request_id, 'actor_dispatch_error', [
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'stage' => 'provider_run_id_missing',
                'error_category' => 'provider_run_id_missing',
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => true,
                'credits_refunded_or_voided' => true,
            ]);
        }
        self::log_run_stage($request_id, $platform, 'provider_dispatch_succeeded', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'dispatch_slot_id' => $dispatch_slot,
            'provider_run_id' => $started_run_id,
            'provider_call_attempted' => true,
            'provider_run_created' => true,
            'credits_reserved' => true,
        ]);
        self::log_run_stage($request_id, $platform, 'provider_run_id_received', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'dispatch_slot_id' => $dispatch_slot,
            'provider_run_id' => $started_run_id,
            'provider_call_attempted' => true,
            'provider_run_created' => true,
            'credits_reserved' => true,
        ]);
        if (!empty($dispatch_slot) && method_exists('VES_Apify_Client', 'register_run_id_for_slot')) {
            VES_Apify_Client::register_run_id_for_slot((string) $dispatch_slot, $started_run_id);
        }
        self::remember_source_execution($source_execution_key ?? '', [
            'provider_run_id' => $started_run_id,
            'source_execution_key' => $source_execution_key ?? '',
            'dispatch_attempt_count' => 1,
            'last_dispatch_at' => time(),
            'status' => strtoupper((string) ($run_body['data']['status'] ?? '')),
            'platform' => $platform,
            'actor_slug' => $actor_slug,
        ]);
        self::remember_standard_run_owner($started_run_id, $platform, $usage_key, $actor_slug); // v0.9.24.65: store actor_slug for poll diagnostics
        $status   = $run_body['data']['status'] ?? '';
        if (in_array((string) $status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            if (!empty($dispatch_slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot((string) $dispatch_slot); }
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, 'El proveedor no completó correctamente el proceso.');
            self::error('The source run did not complete successfully.', 500, $request_id, 'actor_dispatch_error', [
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'provider_run_id' => $started_run_id,
                'stage' => 'provider_terminal_failure',
                'error_category' => 'provider_terminal_failure',
                'run_status' => $status,
                'provider_state' => $status,
                'provider_call_attempted' => true,
                'provider_run_created' => true,
                'credits_reserved' => true,
                'credits_refunded_or_voided' => true,
            ]);
        }
        if ((string) $status === 'SUCCEEDED' && !empty($dispatch_slot) && method_exists('VES_Apify_Client', 'release_dispatch_slot')) {
            VES_Apify_Client::release_dispatch_slot((string) $dispatch_slot);
        }
        self::log_run_stage($request_id, $platform, 'provider_terminal_status_checked', [
            'actor_slug' => $actor_slug,
            'source_execution_key' => $source_execution_key ?? '',
            'dispatch_slot_id' => $dispatch_slot,
            'provider_run_id' => $started_run_id,
            'provider_state' => $status,
            'provider_call_attempted' => true,
            'provider_run_created' => true,
            'credits_reserved' => true,
        ]);
        if ((string) $status === 'SUCCEEDED') {
            self::remember_source_execution($source_execution_key ?? '', [
                'status' => 'SUCCEEDED',
                'provider_cost_usd' => function_exists('ves_extract_apify_cost_usd') ? ves_extract_apify_cost_usd($run_body) : null,
            ]);
            self::post_standard_usage_or_error($usage_key, $request_id, 'usage_billing', 'Run manual confirmado');
        }
        $items    = null;
        $raw_count = 0;
        $fetched_dataset_count = 0;
        $filtered_count = 0;
        $primary_metric_label = self::get_primary_metric_label($platform);
        $has_like_metric = true;
        $first_raw_snapshot = null;
        $actor_error_message = '';
        $dropped_by_relevance = 0;
        $relevance_filter_used = false;
        $all_candidate_items = [];
        $tiktok_profile_fallback_used = false;
        $tiktok_profile_fallback_run_id = '';
        $tiktok_profile_fallback_message = '';
        $normalized_rows_count = 0;
        $unmapped_rows_count = 0;
        $normalizer_used = '';
        $no_result_marker_count = 0;
        $discarded_no_result_marker_count = 0;

        if ($status === 'SUCCEEDED' && !empty($run_body['data']['id'])) {
            self::log_run_stage($request_id, $platform, 'dataset_fetch_attempted', [
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'provider_run_id' => $started_run_id,
                'provider_call_attempted' => true,
                'provider_run_created' => true,
                'credits_reserved' => true,
            ]);
            $items = VES_Apify_Client::fetch_items($run_body['data']['id'], self::dataset_fetch_limit($platform, $request, $is_post_url_mode));
            if (is_wp_error($items)) {
                self::log('actor_items_error', $items->get_error_message(), [
                    'request_id' => $request_id,
                    'public_support_code' => self::public_support_code($request_id),
                    'platform' => $platform,
                    'stage' => 'dataset_fetch_failed',
                    'run_id' => $run_body['data']['id'] ?? '',
                    'provider_run_id' => $started_run_id,
                    'source_execution_key' => $source_execution_key ?? '',
                    'actor_slug' => $actor_slug,
                    'error_category' => 'dataset_fetch_failed',
                ]);
                self::error($items->get_error_message(), 500, $request_id, 'dataset_fetch_failed', [
                    'platform' => $platform,
                    'actor_slug' => $actor_slug,
                    'stage' => 'dataset_fetch_failed',
                    'error_category' => 'dataset_fetch_failed',
                    'provider_run_id' => $started_run_id,
                    'source_execution_key' => $source_execution_key ?? '',
                    'dispatch_slot_id' => $dispatch_slot,
                    'provider_call_attempted' => true,
                    'provider_run_created' => true,
                    'credits_reserved' => true,
                    'credits_refunded_or_voided' => false,
                ]);
            } else {
                self::log_run_stage($request_id, $platform, 'dataset_fetch_succeeded', [
                    'actor_slug' => $actor_slug,
                    'source_execution_key' => $source_execution_key ?? '',
                    'dispatch_slot_id' => $dispatch_slot,
                    'provider_run_id' => $started_run_id,
                    'raw_item_count' => is_array($items) ? count($items) : 0,
                    'provider_call_attempted' => true,
                    'provider_run_created' => true,
                    'credits_reserved' => true,
                ]);
                // Bug fix: if Apify returned hashtag/location metadata entities
                // (old search+searchType:hashtag path), extract their topPosts[]
                // so we get real post objects instead of directory entries.
                $pre_preprocess_count = is_array($items) ? count($items) : 0;
                $fetched_dataset_count = $pre_preprocess_count;
                // v0.9.30.6: discard successful-empty marker rows before any normalizer/AI pipeline.
                if ($platform === 'tiktok' && function_exists('ves_split_no_result_markers')) {
                    $no_result_split = ves_split_no_result_markers($items);
                    $items = $no_result_split['items'];
                    $no_result_marker_count = (int) ($no_result_split['no_result_marker_count'] ?? 0);
                    $discarded_no_result_marker_count = $no_result_marker_count;
                    if ($no_result_marker_count > 0) {
                        self::log('tiktok_no_result_markers', 'TikTok returned no-results marker rows', [
                            'request_id' => $request_id,
                            'marker_count' => $no_result_marker_count,
                            'raw_provider_returned_count' => $fetched_dataset_count,
                        ]);
                    }
                }
                // v0.9.24.12: TikTok profile-mode unwrap (mirrors Instagram preprocess).
                if ($platform === 'tiktok' && function_exists('ves_preprocess_tiktok_items')) {
                    $pre_tiktok_count = count($items);
                    $items = ves_preprocess_tiktok_items($items);
                    $post_tiktok_count = count($items);
                    // Always log for TikTok profile mode to aid debugging.
                    self::log('tiktok_preprocess', 'TikTok preprocess completed', [
                        'request_id'    => $request_id,
                        'items_in'      => $pre_tiktok_count,
                        'items_out'     => $post_tiktok_count,
                        'changed'       => $post_tiktok_count !== $pre_tiktok_count,
                        'is_profile_mode' => $is_profile_url_mode,
                        'first_keys'    => $pre_tiktok_count > 0 && isset($items[0]) && is_array($items[0]) ? implode(',', array_keys($items[0])) : 'none',
                    ]);
                }
                if ($platform === 'instagram' && function_exists('ves_preprocess_instagram_items')) {
                    $items = ves_preprocess_instagram_items($items);
                    $post_preprocess_count = is_array($items) ? count($items) : 0;
                    if ($post_preprocess_count !== $pre_preprocess_count) {
                        self::log('instagram_preprocess', 'Extracted topPosts from hashtag entities', [
                            'request_id'       => $request_id,
                            'entities_in'      => $pre_preprocess_count,
                            'posts_extracted'  => $post_preprocess_count,
                        ]);
                    } elseif ($post_preprocess_count > 0) {
                        // Items unchanged: either real posts already, or empty topPosts.
                        // Check if these still look like hashtag entities.
                        $first = $items[0] ?? [];
                        if (is_array($first) && (isset($first['searchSource']) || isset($first['postsPerDay']))) {
                            self::log('instagram_preprocess', 'Hashtag entities returned with no topPosts to extract', [
                                'request_id'  => $request_id,
                                'entity_count'=> $post_preprocess_count,
                                'hint'        => 'Instagram may be blocking hashtag page scraping. topPosts array was empty.',
                            ]);
                        }
                    }
                }
                if (function_exists('ves_extract_actor_error_message')) {
                    $actor_error_message = ves_extract_actor_error_message($items);
                    if ($actor_error_message !== '') {
                        self::log('actor_items_empty_or_private', $actor_error_message, [
                            'request_id' => $request_id,
                            'platform' => $platform,
                            'run_id' => $run_body['data']['id'] ?? '',
                        ]);
                    }
                }
                if (function_exists('ves_strip_actor_error_items')) {
                    $items = ves_strip_actor_error_items($items);
                }
                if (function_exists('ves_strip_unusable_items')) {
                    $items = ves_strip_unusable_items($platform, $items);
                }
                if (class_exists('VES_Source_Adapter_Registry')) {
                    $rows_before_normalize = is_array($items) ? count($items) : 0;
                    $raw_sample_for_mapper = ($rows_before_normalize > 0 && is_array($items[0] ?? null)) ? array_slice($items[0], 0, 20, true) : [];
                    self::log_run_stage($request_id, $platform, 'normalization_started', [
                        'actor_slug' => $actor_slug,
                        'source_execution_key' => $source_execution_key ?? '',
                        'provider_run_id' => $started_run_id,
                        'raw_item_count' => $rows_before_normalize,
                        'raw_payload_sample' => $raw_sample_for_mapper,
                        'provider_call_attempted' => true,
                        'provider_run_created' => true,
                        'credits_reserved' => true,
                    ]);
                    $items = VES_Source_Adapter_Registry::normalize_output($platform, $actor_slug, $items);
                    $normalized_rows_count = is_array($items) ? count($items) : 0;
                    $unmapped_rows_count = max(0, $rows_before_normalize - $normalized_rows_count);
                    $normalizer_used = sanitize_key((string) ($platform . '_canonical_normalizer'));
                    self::log_run_stage($request_id, $platform, 'normalization_completed', [
                        'actor_slug' => $actor_slug,
                        'source_execution_key' => $source_execution_key ?? '',
                        'provider_run_id' => $started_run_id,
                        'raw_item_count' => $rows_before_normalize,
                        'normalized_signal_count' => $normalized_rows_count,
                        'unmapped_rows_count' => $unmapped_rows_count,
                        'normalizer_used' => $normalizer_used,
                        'provider_call_attempted' => true,
                        'provider_run_created' => true,
                        'credits_reserved' => true,
                    ]);
                    if ($rows_before_normalize > 0 && $normalized_rows_count === 0 && class_exists('VES_Admin')) {
                        VES_Admin::record_diagnostic('output_normalizer_empty', 'Provider returned rows but source normalizer produced zero canonical rows.', [
                            'platform' => $platform,
                            'actor_slug' => $actor_slug,
                            'raw_rows' => $rows_before_normalize,
                            'sample_raw_row' => $raw_sample_for_mapper,
                            'detected_fields' => is_array($raw_sample_for_mapper) ? array_keys($raw_sample_for_mapper) : [],
                            'mapper_used' => $normalizer_used,
                        ]);
                    }
                }
                if ($platform === 'tiktok' && self::tiktok_should_try_profile_fallback($items, $request, $is_profile_url_mode, $is_mixed_url_mode, $is_post_url_mode)) {
                    $fallback = self::run_tiktok_profile_search_fallback($actor_slug, $request, $request_id);
                    if (!is_wp_error($fallback) && is_array($fallback)) {
                        $fallback_items = isset($fallback['items']) && is_array($fallback['items']) ? $fallback['items'] : [];
                        if (!empty($fallback_items)) {
                            $run_body = $fallback['run_body'];
                            $status = $run_body['data']['status'] ?? $status;
                            $items = $fallback_items;
                            if (class_exists('VES_Source_Adapter_Registry')) {
                                $rows_before_normalize = is_array($items) ? count($items) : 0;
                                $items = VES_Source_Adapter_Registry::normalize_output($platform, $actor_slug, $items);
                                $normalized_rows_count = is_array($items) ? count($items) : 0;
                                $unmapped_rows_count = max(0, $rows_before_normalize - $normalized_rows_count);
                                $normalizer_used = sanitize_key((string) ($platform . '_canonical_normalizer'));
                            }
                            $tiktok_profile_fallback_used = true;
                            $tiktok_profile_fallback_run_id = sanitize_text_field((string) ($fallback['run_id'] ?? ''));
                            $tiktok_profile_fallback_message = 'El perfil fue detectado, pero TikTok no devolvió su feed. Se usó fallback por búsqueda de autor.';
                        } else {
                            $tiktok_profile_fallback_message = 'El perfil fue detectado, pero ni el feed ni el fallback por autor devolvieron vídeos utilizables.';
                        }
                    } else {
                        $tiktok_profile_fallback_message = is_wp_error($fallback) ? $fallback->get_error_message() : 'El fallback por búsqueda de autor no devolvió vídeos.';
                    }
                }
                $raw_count = is_array($items) ? count($items) : 0;
                // v0.9.10.4: keep a safe candidate pool before hard post-filters.
                // If date/language/country filters remove everything, we can still
                // show closest available results with a clear warning instead of an
                // empty screen after the user has paid for a successful actor run.
                $relaxed_candidate_items = is_array($items) ? $items : [];
                $filters_relaxed = false;
                // Locked date filter: calendar date wins; otherwise convert the selected preset
                // (7d/30d/90d...) into a concrete cutoff and enforce it locally as well.
                // This prevents actor defaults/fallbacks from silently turning a 30d run into ALL_TIME.
                $date_from_iso = self::date_cutoff_iso_from_request($request);
                $dropped_by_date = 0;
                if (!$is_post_url_mode && $date_from_iso !== '' && function_exists('ves_filter_items_by_date_from')) {
                    list($items, $dropped_by_date) = ves_filter_items_by_date_from($items, $date_from_iso);
                }
                if ($raw_count > 0 && is_array($items[0])) {
                    $first_raw_snapshot = array_slice(array_map(static function ($v) {
                        if (is_string($v)) return function_exists('mb_substr') ? mb_substr($v, 0, 120, 'UTF-8') : substr($v, 0, 120);
                        if (is_scalar($v)) return $v;
                        return '(' . gettype($v) . ')';
                    }, $items[0]), 0, 15);
                }
                $items = ves_enrich_items_for_display($platform, $items, $request);
                $items = function_exists('ves_sort_items_by_user_priority') ? ves_sort_items_by_user_priority($items, $request, $platform) : $items;
                $primary_metric_label = self::get_primary_metric_label($platform, $items);
                $has_like_metric = function_exists('ves_items_have_filter_metric') ? ves_items_have_filter_metric($items, $platform) : true;
                $requested_country = function_exists('ves_sanitize_country_code')
                    ? ves_sanitize_country_code($request['country'] ?? '')
                    : '';
                // v0.9.9.4: strict language + country post-filter. The previous
                // ves_item_language() helper only checked explicit language fields
                // (which TikTok rarely populates), so filtering was effectively
                // skipped. The new helpers also detect language from caption text
                // and drop items whose author region clearly mismatches.
                $requested_language = function_exists('ves_sanitize_language_code')
                    ? ves_sanitize_language_code($request['language'] ?? '')
                    : '';
                $dropped_by_language = 0;
                $language_mismatch_count = 0;
                $country_mismatch_count = 0;
                $strict_language_filter = self::bool_request_flag($request, ['strictLanguageFilter', 'strict_language_filter']);
                $seo_language_preference_only = in_array($platform, ['semrush', 'moz', 'ahrefs'], true) && !$strict_language_filter;
                $youtube_locale_preference_only = ($platform === 'youtube' && !$strict_language_filter);
                if (!$seo_language_preference_only && !$youtube_locale_preference_only && !$is_post_url_mode && $requested_language !== '' && function_exists('ves_filter_items_by_language_strict')) {
                    list($items, $dropped_by_language) = ves_filter_items_by_language_strict($items, $requested_language, $requested_country);
                }
                $dropped_by_country = 0;
                if (!$youtube_locale_preference_only && !$is_post_url_mode && $requested_country !== '' && $requested_country !== 'None' && function_exists('ves_filter_items_by_country_strict')) {
                    list($items, $dropped_by_country) = ves_filter_items_by_country_strict($items, $requested_country);
                }
                if ($youtube_locale_preference_only && !$is_post_url_mode) {
                    $items = self::annotate_locale_adjacent_items($items, $requested_language, $requested_country, $language_mismatch_count, $country_mismatch_count);
                }
                if (function_exists('ves_dedupe_items_by_identity')) {
                    $items = ves_dedupe_items_by_identity($items);
                }
                // v0.9.24.53: metric threshold is a hard display gate, not only an AI-analysis gate.
                // Apply it before building best/secondary candidate sections so cards below
                // the requested minViews/minLikes cannot be promoted back into the UI.
                $dropped_by_metric = 0;
                $items = self::apply_discovery_metric_gate($items, $platform, $request, $dropped_by_metric);
                $all_candidate_items = function_exists('ves_dedupe_items_by_identity') ? ves_dedupe_items_by_identity($items) : array_values($items);
                if (function_exists('ves_filter_and_rank_items_by_search_relevance')) {
                    list($items, $dropped_by_relevance, $relevance_filter_used) = ves_filter_and_rank_items_by_search_relevance($items, $request, $platform);
                }
                // Safety pass: keep metric filter after relevance too in case future logic mutates items.
                $items = self::apply_discovery_metric_gate($items, $platform, $request, $unused_metric_drop);
                $items = function_exists('ves_sort_items_by_user_priority') ? ves_sort_items_by_user_priority($items, $request, $platform) : $items;
                // v0.9.9.7: slice down from over-fetched amount to user's actual requested limit.
                // We over-fetched (4× / min 20) so post-filters had headroom; now keep only the
                // most-relevant top N. Apify returns results in best-match order, so preserving
                // index order keeps the platform's relevance signal.
                $user_requested_limit = function_exists('ves_clean_int')
                    ? ves_clean_int($request['limit'] ?? null, 1, function_exists('ves_max_items') ? ves_max_items() : 200)
                    : null;
                $count_before_slice = is_array($items) ? count($items) : 0;
                $sliced_off = 0;
                if (!$is_post_url_mode && $user_requested_limit !== null && $count_before_slice > $user_requested_limit) {
                    if (function_exists('ves_sort_items_by_user_priority')) {
                        $items = ves_sort_items_by_user_priority($items, $request, $platform);
                    } elseif (function_exists('ves_sort_items_by_relevance_desc')) {
                        $items = ves_sort_items_by_relevance_desc($items);
                    }
                    $items = array_slice($items, 0, $user_requested_limit);
                    $sliced_off = $count_before_slice - $user_requested_limit;
                }
                $allow_relaxed_fallback = self::bool_request_flag($request, ['allowRelaxedFallback', 'allow_relaxed_fallback']);
                if (!$is_post_url_mode && $allow_relaxed_fallback && empty($items) && !empty($relaxed_candidate_items) && ((isset($dropped_by_date) && $dropped_by_date > 0) || (isset($dropped_by_language) && $dropped_by_language > 0) || (isset($dropped_by_country) && $dropped_by_country > 0))) {
                    list($relaxed_items, $filters_relaxed) = self::maybe_relax_empty_filtered_results($platform, $relaxed_candidate_items, $request, $user_requested_limit, $is_post_url_mode);
                    if (!empty($relaxed_items)) {
                        $items = $relaxed_items;
                    }
                }
                $filtered_count = is_array($items) ? count($items) : 0;
            }
        }

        self::log('actor_result', 'Run finished', [
            'request_id'         => $request_id,
            'platform'           => $platform,
            'actor_slug'         => $actor_slug,
            'status'             => $status,
            'run_id'             => $run_body['data']['id'] ?? '',
            'raw_items'          => $raw_count,
            'raw_provider_returned_count' => $fetched_dataset_count,
            'normalized_rows' => $normalized_rows_count,
            'unmapped_rows' => $unmapped_rows_count,
            'normalizer_used' => $normalizer_used,
            'items_after_filters'=> $filtered_count,
            'first_raw_snapshot' => $first_raw_snapshot,
            'your_filters'       => [
                'metricThreshold' => function_exists('ves_requested_min_metric') ? (int) ves_requested_min_metric($request) : (isset($request['minViews']) ? (int) $request['minViews'] : (isset($request['minLikes']) ? (int) $request['minLikes'] : 0)),
                'language' => isset($request['language']) ? sanitize_key((string) $request['language']) : '',
            ],
        ]);

        $no_result_markers_only = ($platform === 'tiktok' && (int) $fetched_dataset_count > 0 && (int) $no_result_marker_count > 0 && (int) $no_result_marker_count >= (int) $fetched_dataset_count && (int) $filtered_count === 0);
        $formatted = ves_format_run_response($platform, $run_body, $items);
        if ($no_result_markers_only) {
            $formatted['source_status'] = 'no_result_markers_only';
            $formatted['status_message'] = 'No usable social signals were found for this query. The source returned empty result markers instead of content items. Try broader keywords, another language, or a wider date range.';
            $formatted['evidence_quality'] = 'none';
            $formatted['generation_blocked'] = true;
            $formatted['analysis_blocked_reason'] = 'no_results_marker';
            $formatted['can_generate_insight'] = false;
            $formatted['can_generate_brief'] = false;
        }
        if (!$no_result_markers_only && $actor_error_message === '' && (string) $status === 'SUCCEEDED' && (int) $raw_count === 0) {
            $empty_context = self::contextual_empty_result_message($platform, is_array($input) ? $input : [], $request);
            if ($empty_context !== '') { $formatted['status_message'] = $empty_context; }
        }
        if (!empty($tiktok_profile_fallback_used)) {
            $formatted['tiktok_profile_fallback_used'] = true;
            $formatted['tiktok_profile_fallback_run_id'] = $tiktok_profile_fallback_run_id;
            $formatted['source_status'] = 'profile_feed_fallback_search';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $tiktok_profile_fallback_message);
        } elseif (!empty($tiktok_profile_fallback_message) && $platform === 'tiktok') {
            $formatted['source_status'] = 'profile_feed_empty';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $tiktok_profile_fallback_message);
        }
        self::attach_discovery_sections($formatted, $items, $all_candidate_items, $platform, self::secondary_items_limit($request), $request);
        $formatted['query_diagnostics'] = self::build_query_diagnostics($platform, is_array($input) ? $input : [], $all_candidate_items, $items, $request);
        if ($actor_error_message !== '' && empty($formatted['status_message'])) {
            $formatted['status_message'] = $actor_error_message;
        }
        if (empty($no_result_markers_only) && $actor_error_message === '' && $status === 'SUCCEEDED' && $raw_count > 0 && $filtered_count === 0) {
            $parts = [];
            if ($has_like_metric) {
                $parts[] = self::get_primary_metric_label($platform);
            }
            if (isset($dropped_by_date) && $dropped_by_date > 0) { $parts[] = 'Fecha'; }
            $parts[] = 'Idioma';
            if (isset($dropped_by_country) && $dropped_by_country > 0) { $parts[] = 'País'; }
            if (!empty($relevance_filter_used)) {
                $parts[] = 'Relevancia de búsqueda';
            }
            $formatted['status_message'] = sprintf(
                'Se encontraron %d resultado(s), pero ninguno coincidió plenamente con los filtros aplicados. Ajusta %s y vuelve a ejecutar.',
                $raw_count,
                '"' . implode('", "', $parts) . '"'
            );
        }
        $formatted['raw_items']      = $raw_count;
        $formatted['raw_provider_returned_count'] = (int) $fetched_dataset_count;
        $formatted['fetched_dataset_count'] = (int) $fetched_dataset_count;
        $formatted['normalized_candidate_count'] = (int) ($normalized_rows_count > 0 ? $normalized_rows_count : $raw_count);
        $formatted['normalized_rows'] = (int) $normalized_rows_count;
        $formatted['raw_item_count'] = (int) $fetched_dataset_count;
        $formatted['no_result_marker_count'] = (int) $no_result_marker_count;
        $formatted['normalized_signal_count'] = (int) $normalized_rows_count;
        $formatted['usable_signal_count'] = (int) $filtered_count;
        $formatted['discarded_signal_count'] = max(0, (int) $raw_count - (int) $filtered_count);
        $formatted['discarded_no_result_marker_count'] = (int) $discarded_no_result_marker_count;
        $formatted['displayed_rows'] = (int) $filtered_count;
        $formatted['useful_delivered_rows'] = (int) $filtered_count;
        $formatted['discarded_rows'] = max(0, (int) $raw_count - (int) $filtered_count);
        $formatted['unmapped_rows'] = (int) $unmapped_rows_count;
        $formatted['normalizer_used'] = $normalizer_used;
        $formatted['adapter_used'] = self::adapter_name_for_platform($platform, $actor_slug);
        $formatted['adapter_status'] = !empty($no_result_markers_only) ? 'no_result_markers_only' : (($fetched_dataset_count > 0 && (($normalized_rows_count > 0 ? $normalized_rows_count : $raw_count) === 0)) ? 'provider_rows_unusable_or_unmapped' : 'ok');
        $formatted['provider_run_id'] = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        $formatted['filtered_items'] = $filtered_count;

        // v0.9.27.0 — Phase 3: classify outcome via VES_Provider_Outcome (start path).
        if (class_exists('VES_Provider_Outcome')) {
            $ves_outcome_code = VES_Provider_Outcome::classify([
                'actor_status' => (string) $status,
                'http_status'  => 200,
                'raw_count'    => (int) $raw_count,
                'usable_count' => (int) $filtered_count,
                'error_code'   => $actor_error_message !== '' ? 'provider_error' : '',
            ]);
            $formatted['provider_outcome'] = $ves_outcome_code;
            $formatted['outcome_message']  = VES_Provider_Outcome::user_message($ves_outcome_code, [
                'platform'  => (string) $platform,
                'module'    => in_array($platform, ['semrush','moz','ahrefs'], true) ? 'seo'
                              : (in_array($platform, ['facebook_ads','google_ads'], true) ? 'ads'
                              : ($platform === 'linkedin' ? 'linkedin' : 'social')),
                'raw_count' => (int) $raw_count,
            ]);
        }
        if (!empty($no_result_markers_only)) {
            $formatted['provider_outcome'] = 'success_empty';
            $formatted['outcome_message'] = 'No usable social signals were found for this query. The source returned empty result markers instead of content items. Try broader keywords, another language, or a wider date range.';
        }
        $formatted['run_lifecycle_status'] = ((string) $status === 'SUCCEEDED') ? ($filtered_count > 0 ? 'completed' : ($raw_count > 0 ? 'completed_no_usable_results' : 'completed_no_usable_results')) : (in_array((string) $status, ['FAILED','ABORTED','TIMED-OUT','TIMEOUT'], true) ? 'failed' : 'provider_running');
        $formatted['dropped_by_date'] = isset($dropped_by_date) ? (int) $dropped_by_date : 0;
        $formatted['dropped_by_language'] = isset($dropped_by_language) ? (int) $dropped_by_language : 0;
        $formatted['dropped_by_country'] = isset($dropped_by_country) ? (int) $dropped_by_country : 0;
        $formatted['language_mismatch_count'] = isset($language_mismatch_count) ? (int) $language_mismatch_count : (int) $formatted['dropped_by_language'];
        $formatted['country_mismatch_count'] = isset($country_mismatch_count) ? (int) $country_mismatch_count : (int) $formatted['dropped_by_country'];
        $formatted['direct_count'] = max(0, (int) ($formatted['usable_signal_count'] ?? $filtered_count) - max((int) $formatted['language_mismatch_count'], (int) $formatted['country_mismatch_count']));
        $formatted['adjacent_count'] = max((int) $formatted['language_mismatch_count'], (int) $formatted['country_mismatch_count']);
        $formatted['direct_match_count'] = (int) $formatted['direct_count'];
        $formatted['adjacent_match_count'] = (int) $formatted['adjacent_count'];
        if ((int) $formatted['adjacent_count'] > 0 && (int) $formatted['direct_count'] === 0) {
            $formatted['evidence_quality'] = 'limited_adjacent';
            $formatted['can_generate_brief'] = false;
            $formatted['confident_recommendations_allowed'] = false;
            if (empty($formatted['status_message'])) {
                $formatted['status_message'] = 'Se encontraron resultados. Algunos no coinciden exactamente con el idioma o mercado seleccionado, por eso se marcaron como señales adyacentes.';
            }
        } elseif ((int) $formatted['adjacent_count'] > 0 && empty($formatted['status_message'])) {
            $formatted['status_message'] = 'Se encontraron resultados. Algunos no coinciden exactamente con el idioma o mercado seleccionado, por eso se marcaron como señales adyacentes.';
        }
        $formatted['dropped_by_metric'] = isset($dropped_by_metric) ? (int) $dropped_by_metric : 0;
        $formatted['dropped_by_relevance'] = (int) $dropped_by_relevance;
        $formatted['relevance_filter_used'] = !empty($relevance_filter_used);
        if (!empty($relevance_filter_used) && $dropped_by_relevance > 0 && $filtered_count > 0) {
            $rel_hint = sprintf('%d resultado(s) descartados por baja relevancia con la búsqueda.', (int) $dropped_by_relevance);
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $rel_hint);
        }
        $formatted['request_id']     = $request_id;
        $formatted['execution_plan'] = $execution_plan;
        $formatted['dispatch_plan_hash'] = md5((string) wp_json_encode(self::summarize_input($platform, is_array($input) ? $input : [], $request)));
        $formatted['locked_constraints'] = $execution_plan['locked_constraints'] ?? self::request_locked_constraints($platform, $request);

        // v0.9.9.2: tell the user how many they asked for vs. what we returned.
        $requested_limit = isset($request['limit']) ? (int) $request['limit'] : 0;
        if (!$is_post_url_mode && $requested_limit > 0) {
            $formatted['requested_limit'] = $requested_limit;
            // v0.9.9.7: surface the over-fetch transparency hint.
            if (function_exists('ves_compute_actor_limit')) {
                $actor_limit = ves_compute_actor_limit($requested_limit, $platform, $request);
                if ($actor_limit && $actor_limit > $requested_limit) {
                    $formatted['actor_limit'] = (int) $actor_limit;
                    $formatted['over_fetch_factor'] = function_exists('ves_over_fetch_factor') ? ves_over_fetch_factor($platform) : 4;
                }
            }
            if (isset($sliced_off) && $sliced_off > 0) {
                $formatted['sliced_off'] = (int) $sliced_off;
                $slice_hint = sprintf(
                    'Pedimos %d resultados al scraper para tener margen y te entregamos los %d prioritarios según tu orden elegido.',
                    isset($formatted['actor_limit']) ? (int) $formatted['actor_limit'] : ($requested_limit + (int) $sliced_off),
                    $requested_limit
                );
                $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $slice_hint);
            }
            if ($filtered_count > 0 && $filtered_count < $requested_limit) {
                $min_metric_active = function_exists('ves_requested_min_metric')
                    ? ves_requested_min_metric($request)
                    : (isset($request['minViews'])
                        ? (function_exists('ves_clean_int') ? ves_clean_int($request['minViews'], 0, null) : (int) $request['minViews'])
                        : (isset($request['minLikes']) ? (function_exists('ves_clean_int') ? ves_clean_int($request['minLikes'], 0, null) : (int) $request['minLikes']) : 0));
                if ($min_metric_active !== null && $min_metric_active > 0) {
                    $hint = sprintf(
                        'Se encontraron %d de los %d resultados solicitados. El filtro "Visualizaciones mínimas" (%d) eliminó muchos resultados — prueba a bajarlo o quitarlo para obtener más, o ejecuta de nuevo para recibir otro lote.',
                        $filtered_count,
                        $requested_limit,
                        (int) $min_metric_active
                    );
                } else {
                    $hint = sprintf(
                        'Se encontraron %d de los %d resultados solicitados. Si quieres más, prueba a ampliar la fecha "desde", quita filtros o aumenta el rango.',
                        $filtered_count,
                        $requested_limit
                    );
                }
                $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $hint);
            }
        }
        if (!empty($filters_relaxed)) {
            $formatted['filters_relaxed'] = true;
            $relaxed_hint = 'No había coincidencias exactas con los filtros de fecha/idioma/país; mostramos los resultados más cercanos disponibles. Revise los filtros antes de analizar.';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $relaxed_hint);
        }
        if (isset($dropped_by_date) && $dropped_by_date > 0) {
            $formatted['dropped_by_date'] = (int) $dropped_by_date;
            $date_hint = sprintf(
                '%d resultado(s) descartados por ser anteriores a %s.',
                (int) $dropped_by_date,
                isset($date_from_iso) ? $date_from_iso : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $date_hint);
        }
        // v0.9.9.4: language + country drop counts.
        if (isset($dropped_by_language) && $dropped_by_language > 0) {
            $formatted['dropped_by_language'] = (int) $dropped_by_language;
            $lang_hint = sprintf(
                '%d resultado(s) descartados por no coincidir con el idioma "%s".',
                (int) $dropped_by_language,
                isset($requested_language) ? $requested_language : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $lang_hint);
        }
        if (isset($dropped_by_country) && $dropped_by_country > 0) {
            $formatted['dropped_by_country'] = (int) $dropped_by_country;
            $country_hint = sprintf(
                '%d resultado(s) descartados por no coincidir con el país "%s".',
                (int) $dropped_by_country,
                isset($requested_country) ? $requested_country : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $country_hint);
        }
        if (isset($dropped_by_metric) && $dropped_by_metric > 0) {
            $formatted['dropped_by_metric'] = (int) $dropped_by_metric;
            $metric_hint = sprintf(
                '%d resultado(s) descartados por estar por debajo del mínimo de métrica (%d).',
                (int) $dropped_by_metric,
                (int) self::discovery_metric_threshold($request)
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $metric_hint);
        }

        // v0.9.24.4: continuation merge — combine accumulated items from previous
        // runs with this run's filtered results, dedupe, re-slice to requested limit.
        $min_metric_for_cont = function_exists('ves_requested_min_metric')
            ? ves_requested_min_metric($request)
            : (isset($request['minViews'])
                ? (function_exists('ves_clean_int') ? ves_clean_int($request['minViews'], 0, null) : (int) $request['minViews'])
                : (isset($request['minLikes']) ? (function_exists('ves_clean_int') ? ves_clean_int($request['minLikes'], 0, null) : (int) $request['minLikes']) : 0));
        $user_requested_limit_for_cont = isset($request['limit'])
            ? (function_exists('ves_clean_int') ? ves_clean_int($request['limit'], 1, 200) : (int) $request['limit'])
            : 20;
        $needs_more = false;
        if ($status === 'SUCCEEDED' && !$is_post_url_mode && $min_metric_for_cont > 0 && is_array($items)) {
            // Merge previously accumulated items.
            if (!empty($continuation_items)) {
                $merged = array_merge($continuation_items, $items);
                if (function_exists('ves_dedupe_items_by_identity')) {
                    $merged = ves_dedupe_items_by_identity($merged);
                }
                // Re-apply metric filter on merged pool to be safe.
                if (function_exists('ves_apply_requested_filters')) {
                    $merged = ves_apply_requested_filters($merged, $request);
                }
                if (function_exists('ves_sort_items_by_user_priority')) {
                    $merged = ves_sort_items_by_user_priority($merged, $request, $platform);
                }
                // Slice to final requested amount.
                if (count($merged) >= $user_requested_limit_for_cont) {
                    $items = array_slice($merged, 0, $user_requested_limit_for_cont);
                    // We have enough — clear the continuation transient.
                    if ($continuation_token !== '') {
                        self::clear_continuation_items($continuation_token);
                    }
                    $formatted = ves_format_run_response($platform, $run_body, $items);
                    if ((string) $status === 'SUCCEEDED' && empty($items)) {
                        $empty_context = self::contextual_empty_result_message($platform, is_array($input) ? $input : [], $request);
                        if ($empty_context !== '') { $formatted['status_message'] = $empty_context; }
                    }
                    self::attach_discovery_sections($formatted, $items, $all_candidate_items, $platform, self::secondary_items_limit($request), $request);
                } else {
                    // Still short — save merged pool and signal another run.
                    $new_token = self::search_fingerprint($platform, $request);
                    self::save_continuation_items($new_token, $merged);
                    $formatted['continuation_token'] = $new_token;
                    $formatted['needs_more_results'] = true;
                    $formatted['accumulated_count']  = count($merged);
                    $needs_more = true;
                    $items = $merged;
                }
                $filtered_count = count($items);
            } elseif (is_array($items) && count($items) < $user_requested_limit_for_cont) {
                // First run came up short — persist what we have and signal retry.
                $new_token = self::search_fingerprint($platform, $request);
                self::save_continuation_items($new_token, $items);
                $formatted['continuation_token'] = $new_token;
                $formatted['needs_more_results'] = true;
                $formatted['accumulated_count']  = count($items);
                $needs_more = true;
            }
        } elseif ($continuation_token !== '' && !$needs_more) {
            // Run finished with enough results — clean up.
            self::clear_continuation_items($continuation_token);
        }

        self::finalize_discovery_response_gates($formatted, $platform, $request);

        if ($status === 'SUCCEEDED' && class_exists('VES_Temp_Memory') && !$needs_more) {
            $formatted['memory_entry_id'] = VES_Temp_Memory::save_search($platform, $request, $formatted);
        }

        if ($status === 'SUCCEEDED' && !$needs_more) {
            $formatted = self::settle_standard_usage_by_delivery($usage_key, $request_id, 'usage_billing', $formatted, $platform, $request, $actor_slug);
        }

        // v0.9.31.3 Phase 10A: generate a structured report card when the run
        // produced display items. The renderer is a lightweight PHP layer
        // (templates/reports/) that works independently of the AI analysis.
        if ($status === 'SUCCEEDED' && !empty($formatted['items'])
            && function_exists('ves_render_report_template')
            && class_exists('VES_Report_Data_Builder')
            && class_exists('VES_Report_Template_Registry')) {
            try {
                $report = VES_Report_Data_Builder::from_run_payload([
                    'platform'     => $platform,
                    'items'        => $formatted['items'],
                    'insights'     => $formatted['analysis_structured']['insights'] ?? [],
                    'next_actions' => $formatted['analysis_structured']['next_actions'] ?? [],
                    'warnings'     => $formatted['discard_reasons'] ?? [],
                ]);
                $template_key = VES_Report_Template_Registry::choose($report);
                $formatted['report_html']     = ves_render_report_template($report, $template_key);
                $formatted['report_template'] = $template_key;
            } catch (Throwable $report_ex) {
                // v0.9.31.4: renderer failure must not break the success response;
                // omit report_html and surface an admin-only diagnostic flag.
                $formatted['report_render_failed'] = class_exists('VES_Admin') ? true : null;
                if (class_exists('VES_Admin')) {
                    VES_Admin::record_diagnostic('report_render_error', 'Report template render failed.', ['msg' => $report_ex->getMessage()]);
                }
            }
        }

        self::release_start_gate($start_gate_key ?? '');
        self::log_run_stage($request_id, $platform, 'response_rendered', ['provider_call_attempted' => true, 'provider_run_created' => true]);
        self::success($formatted);
    }

    public static function poll() {
        $request_id = self::request_id();
        self::register_ajax_shutdown_handler($request_id, 'ves_poll');
        try {
            self::poll_impl($request_id);
        } catch (Throwable $e) {
            self::fatal($e, $request_id, 'ajax_poll_fatal', ['action' => 'ves_poll']);
        }
    }

    private static function poll_impl($request_id) {

        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'ajax_auth', ['stage' => 'nonce_poll']);
        }
        if (!is_user_logged_in()) {
            self::error('No autorizado.', 403, $request_id, 'ajax_auth', ['stage' => 'login_required_poll']);
        }
        if (!ves_get_token()) {
            self::error('Servicio no disponible.', 500, $request_id, 'ajax_config', ['stage' => 'missing_backend_token_poll']);
        }

        // v0.9.24.33: poll() now uses the same normalized request surface as start().
        // This prevents double-unslash issues and keeps array fields intact when the UI
        // polls Semrush, LinkedIn, Reddit or other multi-value scraper modes.
        $request = self::normalized_post();

        $platform = sanitize_key((string) ($request['platform'] ?? ''));
        $run_id   = sanitize_text_field((string) ($request['runId'] ?? ''));
        $run_id   = self::resolve_public_run_id($run_id, $platform, $request_id, 'ajax_auth', 'public_run_token_poll');
        $is_post_url_mode = function_exists('ves_is_post_url_mode') ? ves_is_post_url_mode($request) : false;
        $is_profile_url_mode = function_exists('ves_is_profile_url_mode') ? ves_is_profile_url_mode($request) : false;
        $is_mixed_url_mode = function_exists('ves_is_mixed_url_mode') ? ves_is_mixed_url_mode($request) : false;
        $input = null; // deferred until SUCCEEDED
        $execution_plan = [];
        if (!in_array($platform, ['tiktok', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'moz', 'ahrefs'], true)) {
            self::error('Plataforma no válida.', 400, $request_id, 'ajax_validation', ['stage' => 'platform_poll']);
        }
        if (!$run_id || !preg_match('/^[A-Za-z0-9]+$/', $run_id) || strlen($run_id) > 64) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'ajax_validation', ['stage' => 'run_id_poll']);
        }
        self::deny_if_role_forbidden($request_id, 'ajax_auth', 'role_poll');
        self::require_standard_run_access($run_id, $platform, $request_id, 'ajax_auth', 'run_scope_poll');
        $standard_owner = get_transient(self::standard_run_owner_key($run_id));
        $standard_usage_key = is_array($standard_owner) ? sanitize_text_field((string) ($standard_owner['usage_key'] ?? '')) : '';
        // v0.9.24.67 BUG-A2 fix: retrieve run-owner transient before reading actor_slug.
        // v0.9.24.65 deferred input rebuild correctly, but read $standard_owner before assignment.
        $cached_actor_slug = is_array($standard_owner) ? sanitize_text_field((string) ($standard_owner['actor_slug'] ?? '')) : '';
        $actor_slug = $cached_actor_slug !== '' ? $cached_actor_slug : ves_get_actor_slug($platform, $request);

        $response = VES_Apify_Client::fetch_run($run_id, 20);
        if (is_wp_error($response)) {
            $poll_error_data = $response->get_error_data();
            $poll_error_data = is_array($poll_error_data) ? $poll_error_data : [];
            self::error($response->get_error_message(), (int) ($poll_error_data['status'] ?? 500), $request_id, 'actor_poll_error', array_merge($poll_error_data, [
                'platform' => $platform,
                'run_id' => $run_id,
                'stage' => 'provider_poll_failed',
                'error_code' => $response->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => true,
            ]));
        }

        $run_body = $response['body'];
        $status   = $run_body['data']['status'] ?? '';
        $items    = null;
        $raw_count = 0;
        $fetched_dataset_count = 0;
        $filtered_count = 0;
        $primary_metric_label = self::get_primary_metric_label($platform);
        $has_like_metric = true;
        $actor_error_message = '';
        $dropped_by_relevance = 0;
        $relevance_filter_used = false;
        $all_candidate_items = [];
        $tiktok_profile_fallback_used = false;
        $tiktok_profile_fallback_run_id = '';
        $tiktok_profile_fallback_message = '';
        $normalized_rows_count = 0;
        $unmapped_rows_count = 0;
        $normalizer_used = '';
        $no_result_marker_count = 0;
        $discarded_no_result_marker_count = 0;
        if ($status === 'SUCCEEDED') {
            // v0.9.24.65 BUG-A: rebuild input only on SUCCEEDED (for diagnostics).
            $input = ves_build_platform_input($platform, $request);
            $input = self::enforce_locked_actor_constraints($platform, $request, $input);
            $execution_plan = self::build_scrape_execution_plan($platform, $request, $input);
            $items = VES_Apify_Client::fetch_items($run_id, self::dataset_fetch_limit($platform, $request, $is_post_url_mode));
            if (is_wp_error($items)) {
                self::log('actor_items_error', $items->get_error_message(), [
                    'request_id' => $request_id,
                    'platform' => $platform,
                    'run_id' => $run_id,
                ]);
                $items = [];
            } else {
                $fetched_dataset_count = is_array($items) ? count($items) : 0;
                // v0.9.30.6: discard successful-empty marker rows before any normalizer/AI pipeline.
                if ($platform === 'tiktok' && function_exists('ves_split_no_result_markers')) {
                    $no_result_split = ves_split_no_result_markers($items);
                    $items = $no_result_split['items'];
                    $no_result_marker_count = (int) ($no_result_split['no_result_marker_count'] ?? 0);
                    $discarded_no_result_marker_count = $no_result_marker_count;
                    if ($no_result_marker_count > 0) {
                        self::log('tiktok_no_result_markers', 'TikTok returned no-results marker rows', [
                            'request_id' => $request_id,
                            'marker_count' => $no_result_marker_count,
                            'raw_provider_returned_count' => $fetched_dataset_count,
                        ]);
                    }
                }
                // Bug fix: extract topPosts from hashtag entities if needed.
                if ($platform === 'tiktok' && function_exists('ves_preprocess_tiktok_items')) {
                    $items = ves_preprocess_tiktok_items($items);
                }
                if ($platform === 'instagram' && function_exists('ves_preprocess_instagram_items')) {
                    $items = ves_preprocess_instagram_items($items);
                }
                if (function_exists('ves_extract_actor_error_message')) {
                    $actor_error_message = ves_extract_actor_error_message($items);
                    if ($actor_error_message !== '') {
                        self::log('actor_items_empty_or_private', $actor_error_message, [
                            'request_id' => $request_id,
                            'platform' => $platform,
                            'run_id' => $run_id,
                        ]);
                    }
                }
                if (function_exists('ves_strip_actor_error_items')) {
                    $items = ves_strip_actor_error_items($items);
                }
                if (function_exists('ves_strip_unusable_items')) {
                    $items = ves_strip_unusable_items($platform, $items);
                }
                if (class_exists('VES_Source_Adapter_Registry')) {
                    $rows_before_normalize = is_array($items) ? count($items) : 0;
                    $raw_sample_for_mapper = ($rows_before_normalize > 0 && is_array($items[0] ?? null)) ? array_slice($items[0], 0, 20, true) : [];
                    $items = VES_Source_Adapter_Registry::normalize_output($platform, $actor_slug, $items);
                    $normalized_rows_count = is_array($items) ? count($items) : 0;
                    $unmapped_rows_count = max(0, $rows_before_normalize - $normalized_rows_count);
                    $normalizer_used = sanitize_key((string) ($platform . '_canonical_normalizer'));
                    if ($rows_before_normalize > 0 && $normalized_rows_count === 0 && class_exists('VES_Admin')) {
                        VES_Admin::record_diagnostic('output_normalizer_empty', 'Provider returned rows but source normalizer produced zero canonical rows.', [
                            'platform' => $platform,
                            'actor_slug' => $actor_slug,
                            'raw_rows' => $rows_before_normalize,
                            'sample_raw_row' => $raw_sample_for_mapper,
                            'detected_fields' => is_array($raw_sample_for_mapper) ? array_keys($raw_sample_for_mapper) : [],
                            'mapper_used' => $normalizer_used,
                        ]);
                    }
                }
                if ($platform === 'tiktok' && self::tiktok_should_try_profile_fallback($items, $request, $is_profile_url_mode, $is_mixed_url_mode, $is_post_url_mode)) {
                    $fallback = self::run_tiktok_profile_search_fallback($actor_slug, $request, $request_id);
                    if (!is_wp_error($fallback) && is_array($fallback)) {
                        $fallback_items = isset($fallback['items']) && is_array($fallback['items']) ? $fallback['items'] : [];
                        if (!empty($fallback_items)) {
                            $run_body = $fallback['run_body'];
                            $status = $run_body['data']['status'] ?? $status;
                            $items = $fallback_items;
                            if (class_exists('VES_Source_Adapter_Registry')) {
                                $rows_before_normalize = is_array($items) ? count($items) : 0;
                                $items = VES_Source_Adapter_Registry::normalize_output($platform, $actor_slug, $items);
                                $normalized_rows_count = is_array($items) ? count($items) : 0;
                                $unmapped_rows_count = max(0, $rows_before_normalize - $normalized_rows_count);
                                $normalizer_used = sanitize_key((string) ($platform . '_canonical_normalizer'));
                            }
                            $tiktok_profile_fallback_used = true;
                            $tiktok_profile_fallback_run_id = sanitize_text_field((string) ($fallback['run_id'] ?? ''));
                            $tiktok_profile_fallback_message = 'El perfil fue detectado, pero TikTok no devolvió su feed. Se usó fallback por búsqueda de autor.';
                        } else {
                            $tiktok_profile_fallback_message = 'El perfil fue detectado, pero ni el feed ni el fallback por autor devolvieron vídeos utilizables.';
                        }
                    } else {
                        $tiktok_profile_fallback_message = is_wp_error($fallback) ? $fallback->get_error_message() : 'El fallback por búsqueda de autor no devolvió vídeos.';
                    }
                }
                $raw_count = is_array($items) ? count($items) : 0;
                // v0.9.10.4: keep a safe candidate pool before hard post-filters.
                // If date/language/country filters remove everything, we can still
                // show closest available results with a clear warning instead of an
                // empty screen after the user has paid for a successful actor run.
                $relaxed_candidate_items = is_array($items) ? $items : [];
                $filters_relaxed = false;
                // Locked date filter: calendar date wins; otherwise convert the selected preset
                // into a concrete cutoff and enforce it locally as well.
                $date_from_iso = self::date_cutoff_iso_from_request($request);
                $dropped_by_date = 0;
                if (!$is_post_url_mode && $date_from_iso !== '' && function_exists('ves_filter_items_by_date_from')) {
                    list($items, $dropped_by_date) = ves_filter_items_by_date_from($items, $date_from_iso);
                }
                $items = ves_enrich_items_for_display($platform, $items, $request);
                $items = function_exists('ves_sort_items_by_user_priority') ? ves_sort_items_by_user_priority($items, $request, $platform) : $items;
                $primary_metric_label = self::get_primary_metric_label($platform, $items);
                $has_like_metric = function_exists('ves_items_have_filter_metric') ? ves_items_have_filter_metric($items, $platform) : true;
                $requested_country = function_exists('ves_sanitize_country_code')
                    ? ves_sanitize_country_code($request['country'] ?? '')
                    : '';
                // v0.9.9.4: same strict language + country post-filter as start().
                $requested_language = function_exists('ves_sanitize_language_code')
                    ? ves_sanitize_language_code($request['language'] ?? '')
                    : '';
                $dropped_by_language = 0;
                $language_mismatch_count = 0;
                $country_mismatch_count = 0;
                $strict_language_filter = self::bool_request_flag($request, ['strictLanguageFilter', 'strict_language_filter']);
                $seo_language_preference_only = in_array($platform, ['semrush', 'moz', 'ahrefs'], true) && !$strict_language_filter;
                $youtube_locale_preference_only = ($platform === 'youtube' && !$strict_language_filter);
                if (!$seo_language_preference_only && !$youtube_locale_preference_only && !$is_post_url_mode && $requested_language !== '' && function_exists('ves_filter_items_by_language_strict')) {
                    list($items, $dropped_by_language) = ves_filter_items_by_language_strict($items, $requested_language, $requested_country);
                }
                $dropped_by_country = 0;
                if (!$youtube_locale_preference_only && !$is_post_url_mode && $requested_country !== '' && $requested_country !== 'None' && function_exists('ves_filter_items_by_country_strict')) {
                    list($items, $dropped_by_country) = ves_filter_items_by_country_strict($items, $requested_country);
                }
                if ($youtube_locale_preference_only && !$is_post_url_mode) {
                    $items = self::annotate_locale_adjacent_items($items, $requested_language, $requested_country, $language_mismatch_count, $country_mismatch_count);
                }
                if (function_exists('ves_dedupe_items_by_identity')) {
                    $items = ves_dedupe_items_by_identity($items);
                }
                // v0.9.24.53: metric threshold is a hard display gate, not only an AI-analysis gate.
                $dropped_by_metric = 0;
                $items = self::apply_discovery_metric_gate($items, $platform, $request, $dropped_by_metric);
                $all_candidate_items = function_exists('ves_dedupe_items_by_identity') ? ves_dedupe_items_by_identity($items) : array_values($items);
                if (function_exists('ves_filter_and_rank_items_by_search_relevance')) {
                    list($items, $dropped_by_relevance, $relevance_filter_used) = ves_filter_and_rank_items_by_search_relevance($items, $request, $platform);
                }
                // Safety pass: keep metric filter after relevance too in case future logic mutates items.
                $items = self::apply_discovery_metric_gate($items, $platform, $request, $unused_metric_drop);
                $items = function_exists('ves_sort_items_by_user_priority') ? ves_sort_items_by_user_priority($items, $request, $platform) : $items;
                // v0.9.9.7: slice down from over-fetched amount to user's actual requested limit.
                $user_requested_limit = function_exists('ves_clean_int')
                    ? ves_clean_int($request['limit'] ?? null, 1, function_exists('ves_max_items') ? ves_max_items() : 200)
                    : null;
                $count_before_slice = is_array($items) ? count($items) : 0;
                $sliced_off = 0;
                if (!$is_post_url_mode && $user_requested_limit !== null && $count_before_slice > $user_requested_limit) {
                    if (function_exists('ves_sort_items_by_user_priority')) {
                        $items = ves_sort_items_by_user_priority($items, $request, $platform);
                    } elseif (function_exists('ves_sort_items_by_relevance_desc')) {
                        $items = ves_sort_items_by_relevance_desc($items);
                    }
                    $items = array_slice($items, 0, $user_requested_limit);
                    $sliced_off = $count_before_slice - $user_requested_limit;
                }
                $allow_relaxed_fallback = self::bool_request_flag($request, ['allowRelaxedFallback', 'allow_relaxed_fallback']);
                if (!$is_post_url_mode && $allow_relaxed_fallback && empty($items) && !empty($relaxed_candidate_items) && ((isset($dropped_by_date) && $dropped_by_date > 0) || (isset($dropped_by_language) && $dropped_by_language > 0) || (isset($dropped_by_country) && $dropped_by_country > 0))) {
                    list($relaxed_items, $filters_relaxed) = self::maybe_relax_empty_filtered_results($platform, $relaxed_candidate_items, $request, $user_requested_limit, $is_post_url_mode);
                    if (!empty($relaxed_items)) {
                        $items = $relaxed_items;
                    }
                }
                $filtered_count = is_array($items) ? count($items) : 0;
            }
            self::log('actor_result', 'Polled run finished', [
                'request_id' => $request_id,
                'platform'   => $platform,
                'status'     => $status,
                'run_id'     => $run_id,
                'raw_items'  => $raw_count,
                'raw_provider_returned_count' => $fetched_dataset_count,
                'normalized_rows' => $normalized_rows_count,
                'unmapped_rows' => $unmapped_rows_count,
                'no_result_marker_count' => $no_result_marker_count,
                'discarded_no_result_marker_count' => $discarded_no_result_marker_count,
                'normalizer_used' => $normalizer_used,
                'items_after_filters' => $filtered_count,
            ]);
        }
        if ((string) $status === 'SUCCEEDED') {
            self::post_standard_usage_or_error($standard_usage_key, $request_id, 'usage_billing', 'Run manual confirmado');
        } elseif (in_array((string) $status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            self::void_standard_usage($standard_usage_key, 'Run manual no completado por el proveedor.');
        }


        $no_result_markers_only = ($platform === 'tiktok' && (int) $fetched_dataset_count > 0 && (int) $no_result_marker_count > 0 && (int) $no_result_marker_count >= (int) $fetched_dataset_count && (int) $filtered_count === 0);
        $formatted = ves_format_run_response($platform, $run_body, $items);
        if ($no_result_markers_only) {
            $formatted['source_status'] = 'no_result_markers_only';
            $formatted['status_message'] = 'No usable social signals were found for this query. The source returned empty result markers instead of content items. Try broader keywords, another language, or a wider date range.';
            $formatted['evidence_quality'] = 'none';
            $formatted['generation_blocked'] = true;
            $formatted['analysis_blocked_reason'] = 'no_results_marker';
            $formatted['can_generate_insight'] = false;
            $formatted['can_generate_brief'] = false;
        }
        if (!$no_result_markers_only && $actor_error_message === '' && (string) $status === 'SUCCEEDED' && (int) $raw_count === 0) {
            $empty_context = self::contextual_empty_result_message($platform, is_array($input) ? $input : [], $request);
            if ($empty_context !== '') { $formatted['status_message'] = $empty_context; }
        }
        if (!empty($tiktok_profile_fallback_used)) {
            $formatted['tiktok_profile_fallback_used'] = true;
            $formatted['tiktok_profile_fallback_run_id'] = $tiktok_profile_fallback_run_id;
            $formatted['source_status'] = 'profile_feed_fallback_search';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $tiktok_profile_fallback_message);
        } elseif (!empty($tiktok_profile_fallback_message) && $platform === 'tiktok') {
            $formatted['source_status'] = 'profile_feed_empty';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $tiktok_profile_fallback_message);
        }
        self::attach_discovery_sections($formatted, $items, $all_candidate_items, $platform, self::secondary_items_limit($request), $request);
        $formatted['query_diagnostics'] = self::build_query_diagnostics($platform, is_array($input) ? $input : [], $all_candidate_items, $items, $request);
        if ($actor_error_message !== '' && empty($formatted['status_message'])) {
            $formatted['status_message'] = $actor_error_message;
        }
        if (empty($no_result_markers_only) && $actor_error_message === '' && $status === 'SUCCEEDED' && $raw_count > 0 && $filtered_count === 0) {
            $parts = [];
            if ($has_like_metric) {
                $parts[] = self::get_primary_metric_label($platform);
            }
            if (isset($dropped_by_date) && $dropped_by_date > 0) { $parts[] = 'Fecha'; }
            $parts[] = 'Idioma';
            if (isset($dropped_by_country) && $dropped_by_country > 0) { $parts[] = 'País'; }
            if (!empty($relevance_filter_used)) {
                $parts[] = 'Relevancia de búsqueda';
            }
            $formatted['status_message'] = sprintf(
                'Se encontraron %d resultado(s), pero ninguno coincidió plenamente con los filtros aplicados. Ajusta %s y vuelve a ejecutar.',
                $raw_count,
                '"' . implode('", "', $parts) . '"'
            );
        }
        $formatted['raw_items']      = $raw_count;
        $formatted['raw_provider_returned_count'] = (int) $fetched_dataset_count;
        $formatted['fetched_dataset_count'] = (int) $fetched_dataset_count;
        $formatted['normalized_candidate_count'] = (int) ($normalized_rows_count > 0 ? $normalized_rows_count : $raw_count);
        $formatted['normalized_rows'] = (int) $normalized_rows_count;
        $formatted['raw_item_count'] = (int) $fetched_dataset_count;
        $formatted['no_result_marker_count'] = (int) $no_result_marker_count;
        $formatted['normalized_signal_count'] = (int) $normalized_rows_count;
        $formatted['usable_signal_count'] = (int) $filtered_count;
        $formatted['discarded_signal_count'] = max(0, (int) $raw_count - (int) $filtered_count);
        $formatted['discarded_no_result_marker_count'] = (int) $discarded_no_result_marker_count;
        $formatted['displayed_rows'] = (int) $filtered_count;
        $formatted['useful_delivered_rows'] = (int) $filtered_count;
        $formatted['discarded_rows'] = max(0, (int) $raw_count - (int) $filtered_count);
        $formatted['unmapped_rows'] = (int) $unmapped_rows_count;
        $formatted['normalizer_used'] = $normalizer_used;
        $formatted['adapter_used'] = self::adapter_name_for_platform($platform, $actor_slug);
        $formatted['adapter_status'] = !empty($no_result_markers_only) ? 'no_result_markers_only' : (($fetched_dataset_count > 0 && (($normalized_rows_count > 0 ? $normalized_rows_count : $raw_count) === 0)) ? 'provider_rows_unusable_or_unmapped' : 'ok');
        $formatted['provider_run_id'] = sanitize_text_field((string) ($run_body['data']['id'] ?? $run_id));
        $formatted['filtered_items'] = $filtered_count;

        // v0.9.27.0 — Phase 3: classify outcome via VES_Provider_Outcome (poll path).
        if (class_exists('VES_Provider_Outcome')) {
            $ves_outcome_code = VES_Provider_Outcome::classify([
                'actor_status' => (string) $status,
                'http_status'  => 200,
                'raw_count'    => (int) $raw_count,
                'usable_count' => (int) $filtered_count,
                'error_code'   => isset($actor_error_message) && $actor_error_message !== '' ? 'provider_error' : '',
            ]);
            $formatted['provider_outcome'] = $ves_outcome_code;
            $formatted['outcome_message']  = VES_Provider_Outcome::user_message($ves_outcome_code, [
                'platform'  => (string) $platform,
                'module'    => in_array($platform, ['semrush','moz','ahrefs'], true) ? 'seo'
                              : (in_array($platform, ['facebook_ads','google_ads'], true) ? 'ads'
                              : ($platform === 'linkedin' ? 'linkedin' : 'social')),
                'raw_count' => (int) $raw_count,
            ]);
        }
        if (!empty($no_result_markers_only)) {
            $formatted['provider_outcome'] = 'success_empty';
            $formatted['outcome_message'] = 'No usable social signals were found for this query. The source returned empty result markers instead of content items. Try broader keywords, another language, or a wider date range.';
        }
        $formatted['run_lifecycle_status'] = ((string) $status === 'SUCCEEDED') ? ($filtered_count > 0 ? 'completed' : ($raw_count > 0 ? 'completed_no_usable_results' : 'completed_no_usable_results')) : (in_array((string) $status, ['FAILED','ABORTED','TIMED-OUT','TIMEOUT'], true) ? 'failed' : 'provider_running');
        $formatted['dropped_by_date'] = isset($dropped_by_date) ? (int) $dropped_by_date : 0;
        $formatted['dropped_by_language'] = isset($dropped_by_language) ? (int) $dropped_by_language : 0;
        $formatted['dropped_by_country'] = isset($dropped_by_country) ? (int) $dropped_by_country : 0;
        $formatted['language_mismatch_count'] = isset($language_mismatch_count) ? (int) $language_mismatch_count : (int) $formatted['dropped_by_language'];
        $formatted['country_mismatch_count'] = isset($country_mismatch_count) ? (int) $country_mismatch_count : (int) $formatted['dropped_by_country'];
        $formatted['direct_count'] = max(0, (int) ($formatted['usable_signal_count'] ?? $filtered_count) - max((int) $formatted['language_mismatch_count'], (int) $formatted['country_mismatch_count']));
        $formatted['adjacent_count'] = max((int) $formatted['language_mismatch_count'], (int) $formatted['country_mismatch_count']);
        $formatted['direct_match_count'] = (int) $formatted['direct_count'];
        $formatted['adjacent_match_count'] = (int) $formatted['adjacent_count'];
        if ((int) $formatted['adjacent_count'] > 0 && (int) $formatted['direct_count'] === 0) {
            $formatted['evidence_quality'] = 'limited_adjacent';
            $formatted['can_generate_brief'] = false;
            $formatted['confident_recommendations_allowed'] = false;
            if (empty($formatted['status_message'])) {
                $formatted['status_message'] = 'Se encontraron resultados. Algunos no coinciden exactamente con el idioma o mercado seleccionado, por eso se marcaron como señales adyacentes.';
            }
        } elseif ((int) $formatted['adjacent_count'] > 0 && empty($formatted['status_message'])) {
            $formatted['status_message'] = 'Se encontraron resultados. Algunos no coinciden exactamente con el idioma o mercado seleccionado, por eso se marcaron como señales adyacentes.';
        }
        $formatted['dropped_by_metric'] = isset($dropped_by_metric) ? (int) $dropped_by_metric : 0;
        $formatted['dropped_by_relevance'] = (int) $dropped_by_relevance;
        $formatted['relevance_filter_used'] = !empty($relevance_filter_used);
        $formatted['request_id']     = $request_id;
        $formatted['execution_plan'] = $execution_plan;
        $formatted['locked_constraints'] = $execution_plan['locked_constraints'] ?? self::request_locked_constraints($platform, $request);
        $formatted['poll_checked']   = true;
        if (!empty($relevance_filter_used) && $dropped_by_relevance > 0 && $filtered_count > 0) {
            $rel_hint = sprintf('%d resultado(s) descartados por baja relevancia con la búsqueda.', (int) $dropped_by_relevance);
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $rel_hint);
        }
        if (isset($dropped_by_metric) && $dropped_by_metric > 0) {
            $metric_hint = sprintf(
                '%d resultado(s) descartados por estar por debajo del mínimo de métrica (%d).',
                (int) $dropped_by_metric,
                (int) self::discovery_metric_threshold($request)
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $metric_hint);
        }

        // v0.9.9.2: same "fewer than requested" + "dropped by date" hints as start().
        $requested_limit = isset($request['limit']) ? (int) $request['limit'] : 0;
        if (!$is_post_url_mode && $requested_limit > 0) {
            $formatted['requested_limit'] = $requested_limit;
            // v0.9.9.7: over-fetch hint.
            if (function_exists('ves_compute_actor_limit')) {
                $actor_limit = ves_compute_actor_limit($requested_limit, $platform, $request);
                if ($actor_limit && $actor_limit > $requested_limit) {
                    $formatted['actor_limit'] = (int) $actor_limit;
                    $formatted['over_fetch_factor'] = function_exists('ves_over_fetch_factor') ? ves_over_fetch_factor($platform) : 4;
                }
            }
            if (isset($sliced_off) && $sliced_off > 0) {
                $formatted['sliced_off'] = (int) $sliced_off;
                $slice_hint = sprintf(
                    'Pedimos %d resultados al scraper para tener margen y te entregamos los %d prioritarios según tu orden elegido.',
                    isset($formatted['actor_limit']) ? (int) $formatted['actor_limit'] : ($requested_limit + (int) $sliced_off),
                    $requested_limit
                );
                $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $slice_hint);
            }
            if ($filtered_count > 0 && $filtered_count < $requested_limit) {
                $min_metric_active = function_exists('ves_requested_min_metric')
                    ? ves_requested_min_metric($request)
                    : (isset($request['minViews'])
                        ? (function_exists('ves_clean_int') ? ves_clean_int($request['minViews'], 0, null) : (int) $request['minViews'])
                        : (isset($request['minLikes']) ? (function_exists('ves_clean_int') ? ves_clean_int($request['minLikes'], 0, null) : (int) $request['minLikes']) : 0));
                if ($min_metric_active !== null && $min_metric_active > 0) {
                    $hint = sprintf(
                        'Se encontraron %d de los %d resultados solicitados. El filtro "Visualizaciones mínimas" (%d) eliminó muchos resultados — prueba a bajarlo o quitarlo para obtener más, o ejecuta de nuevo para recibir otro lote.',
                        $filtered_count,
                        $requested_limit,
                        (int) $min_metric_active
                    );
                } else {
                    $hint = sprintf(
                        'Se encontraron %d de los %d resultados solicitados. Si quieres más, prueba a ampliar la fecha "desde", quita filtros o aumenta el rango.',
                        $filtered_count,
                        $requested_limit
                    );
                }
                $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $hint);
            }
        }
        if (!empty($filters_relaxed)) {
            $formatted['filters_relaxed'] = true;
            $relaxed_hint = 'No había coincidencias exactas con los filtros de fecha/idioma/país; mostramos los resultados más cercanos disponibles. Revise los filtros antes de analizar.';
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $relaxed_hint);
        }
        if (isset($dropped_by_date) && $dropped_by_date > 0) {
            $formatted['dropped_by_date'] = (int) $dropped_by_date;
            $date_hint = sprintf(
                '%d resultado(s) descartados por ser anteriores a %s.',
                (int) $dropped_by_date,
                isset($date_from_iso) ? $date_from_iso : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $date_hint);
        }
        // v0.9.9.4: language + country hints in poll() too.
        if (isset($dropped_by_language) && $dropped_by_language > 0) {
            $formatted['dropped_by_language'] = (int) $dropped_by_language;
            $lang_hint = sprintf(
                '%d resultado(s) descartados por no coincidir con el idioma "%s".',
                (int) $dropped_by_language,
                isset($requested_language) ? $requested_language : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $lang_hint);
        }
        if (isset($dropped_by_country) && $dropped_by_country > 0) {
            $formatted['dropped_by_country'] = (int) $dropped_by_country;
            $country_hint = sprintf(
                '%d resultado(s) descartados por no coincidir con el país "%s".',
                (int) $dropped_by_country,
                isset($requested_country) ? $requested_country : ''
            );
            $formatted['status_message'] = trim((empty($formatted['status_message']) ? '' : $formatted['status_message'] . ' · ') . $country_hint);
        }

        self::finalize_discovery_response_gates($formatted, $platform, $request);

        if ($status === 'SUCCEEDED' && class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::save_search($platform, $request, $formatted);
        }

        if ($status === 'SUCCEEDED') {
            $formatted = self::settle_standard_usage_by_delivery($standard_usage_key, $request_id, 'usage_billing', $formatted, $platform, $request, $actor_slug);
        }

        self::success($formatted);
    }

    public static function abort() {
        $request_id = self::request_id();

        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'ajax_auth', ['stage' => 'nonce_abort']);
        }
        if (!is_user_logged_in()) {
            self::error('No autorizado.', 403, $request_id, 'ajax_auth', ['stage' => 'login_required_abort']);
        }
        self::deny_if_role_forbidden($request_id, 'ajax_auth', 'role_abort');
        if (!ves_get_token()) {
            self::error('Servicio no disponible.', 500, $request_id, 'ajax_config', ['stage' => 'missing_backend_token_abort']);
        }
        $run_id = sanitize_text_field(wp_unslash($_POST['runId'] ?? ''));
        $run_id = self::resolve_public_run_id($run_id, '', $request_id, 'ajax_auth', 'public_run_token_abort');
        if (!$run_id || !preg_match('/^[A-Za-z0-9]+$/', $run_id) || strlen($run_id) > 64) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'ajax_validation', ['stage' => 'run_id_abort']);
        }
        self::require_standard_run_access($run_id, '', $request_id, 'ajax_auth', 'run_scope_abort');
        $standard_owner = get_transient(self::standard_run_owner_key($run_id));
        $standard_usage_key = is_array($standard_owner) ? sanitize_text_field((string) ($standard_owner['usage_key'] ?? '')) : '';

        $response = VES_Apify_Client::abort_run($run_id);
        if (is_wp_error($response)) {
            $abort_error_data = $response->get_error_data();
            $abort_error_data = is_array($abort_error_data) ? $abort_error_data : [];
            self::error($response->get_error_message(), (int) ($abort_error_data['status'] ?? 500), $request_id, 'actor_abort_error', array_merge($abort_error_data, [
                'run_id' => $run_id,
                'stage' => 'provider_abort_failed',
                'error_code' => $response->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => true,
            ]));
        }
        self::void_standard_usage($standard_usage_key, 'Run abortado por el usuario.');

        self::success(['status' => 'ABORTED', 'request_id' => $request_id]);
    }


    public static function memory_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'memory_auth', ['stage' => 'nonce_memory_list']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para ver la memoria.', 403, $request_id, 'memory_auth', ['stage' => 'login_required_memory_list']);
        }
        self::deny_if_role_forbidden($request_id, 'memory_auth', 'role_memory_list');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Memory no está disponible.', 500, $request_id, 'memory_config', ['stage' => 'memory_class_missing']);
        }
        $args = [
            'limit' => (int) (wp_unslash($_POST['limit'] ?? 60)),
            'entry_type' => sanitize_key((string) wp_unslash($_POST['entry_type'] ?? 'all')),
            'platform' => sanitize_key((string) wp_unslash($_POST['platform'] ?? '')),
            'q' => sanitize_text_field((string) wp_unslash($_POST['q'] ?? '')),
        ];
        $index = VES_Temp_Memory::get_memory_index($args);
        $index['request_id'] = $request_id;
        self::success($index);
    }

    public static function memory_get() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'memory_auth', ['stage' => 'nonce_memory_get']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para ver la memoria.', 403, $request_id, 'memory_auth', ['stage' => 'login_required_memory_get']);
        }
        self::deny_if_role_forbidden($request_id, 'memory_auth', 'role_memory_get');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Memory no está disponible.', 500, $request_id, 'memory_config', ['stage' => 'memory_class_missing']);
        }
        $entry_id = (int) (wp_unslash($_POST['entryId'] ?? 0));
        if ($entry_id <= 0) {
            self::error('Registro de memoria no válido.', 400, $request_id, 'memory_validation', ['stage' => 'invalid_entry_id']);
        }
        $entry = VES_Temp_Memory::get_memory_entry($entry_id);
        if (!$entry) {
            self::error('No se encontró este registro o no pertenece a tu cuenta.', 404, $request_id, 'memory_not_found', ['entry_id' => $entry_id]);
        }
        $entry['request_id'] = $request_id;
        self::success($entry);
    }


    private static function sanitize_output_language($raw) {
        $lang = sanitize_key((string) $raw);
        return in_array($lang, ['es', 'en', 'fa'], true) ? $lang : 'es';
    }


    private static function normalize_analysis_item_payload($item) {
        $item = is_array($item) ? $item : [];
        $raw_preview = [];
        if (!empty($item['raw_preview_payload']) && is_array($item['raw_preview_payload'])) {
            $raw_preview = $item['raw_preview_payload'];
        } elseif (!empty($item['selected_preview_payload']) && is_array($item['selected_preview_payload'])) {
            $raw_preview = $item['selected_preview_payload'];
        } elseif (!empty($item['raw']) && is_array($item['raw'])) {
            $raw_preview = $item['raw'];
        }
        $normalized = $item;
        unset($normalized['raw'], $normalized['raw_item'], $normalized['ves_normalized_payload']);
        if (!empty($raw_preview)) {
            $normalized['raw_preview_payload'] = self::strip_admin_diagnostics($raw_preview);
        }
        $metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics($normalized) : [];
        if (!empty($metrics)) {
            $duration = function_exists('ves_first_non_empty') ? ves_first_non_empty(
                function_exists('ves_arr_get') ? ves_arr_get($normalized, 'metrics.duration', '') : '',
                $normalized['duration'] ?? '',
                $normalized['ves_duration'] ?? ''
            ) : ($normalized['duration'] ?? '');
            $normalized['metrics'] = array_merge($metrics, ['duration' => $duration]);
            foreach (['views', 'likes', 'comments', 'shares', 'saves'] as $metric_key) {
                if ($metrics[$metric_key] !== null) {
                    $normalized['ves_' . $metric_key] = (int) $metrics[$metric_key];
                }
            }
            $normalized['ves_engagement_visible'] = !empty($metrics['engagement_visible']) ? 1 : 0;
        }
        return self::strip_admin_diagnostics($normalized);
    }

    private static function apply_preview_metric_floor($item, $preview_metrics, $preview_item = []) {
        $item = is_array($item) ? $item : [];
        $preview_metrics = is_array($preview_metrics) ? $preview_metrics : [];
        if (empty($preview_metrics) && function_exists('ves_normalize_social_metrics')) {
            $preview_metrics = ves_normalize_social_metrics($preview_item);
        }
        if (!empty($preview_item) && empty($item['raw_preview_payload'])) {
            $item['raw_preview_payload'] = self::strip_admin_diagnostics($preview_item);
        }
        $current_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics($item) : [];
        $conflicts = [];
        foreach (['views', 'likes', 'comments', 'shares', 'saves'] as $metric_key) {
            if (!array_key_exists($metric_key, $preview_metrics) || $preview_metrics[$metric_key] === null) { continue; }
            if (isset($current_metrics[$metric_key]) && $current_metrics[$metric_key] !== null && (int) $current_metrics[$metric_key] !== (int) $preview_metrics[$metric_key]) {
                $conflicts[$metric_key] = ['preview' => (int) $preview_metrics[$metric_key], 'enriched' => (int) $current_metrics[$metric_key]];
            }
            $item['metrics'][$metric_key] = (int) $preview_metrics[$metric_key];
            $item['ves_' . $metric_key] = (int) $preview_metrics[$metric_key];
        }
        $item['metrics']['engagement_visible'] = !empty($preview_metrics['engagement_visible']);
        $item['ves_engagement_visible'] = !empty($preview_metrics['engagement_visible']) ? 1 : 0;
        if (!empty($conflicts)) {
            $item['ves_metric_conflict_note'] = 'Preview metrics preserved over enrichment metrics for MVP analysis.';
            $item['ves_metric_conflicts'] = $conflicts;
        }
        return $item;
    }

    private static function item_has_inline_comments($item) {
        $item = is_array($item) ? $item : [];
        foreach (['commentsList', 'comments', 'topComments', 'latestComments'] as $key) {
            if (!empty($item[$key]) && is_array($item[$key])) { return true; }
        }
        return false;
    }

    private static function item_has_transcript($item) {
        $item = is_array($item) ? $item : [];
        $candidates = [
            $item['transcript'] ?? '', $item['ves_transcript'] ?? '', $item['captionText'] ?? '', $item['subtitleText'] ?? '',
            function_exists('ves_arr_get') ? ves_arr_get($item, 'videoMeta.transcriptionLink', '') : '',
        ];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') { return true; }
        }
        return false;
    }

    private static function selected_tiktok_post_url($item) {
        $item = is_array($item) ? $item : [];
        $url = function_exists('ves_first_non_empty') ? ves_first_non_empty(
            $item['webVideoUrl'] ?? '', $item['url'] ?? '', $item['ves_url'] ?? '', $item['link'] ?? '', $item['shareUrl'] ?? ''
        ) : ($item['webVideoUrl'] ?? ($item['url'] ?? ''));
        $url = trim((string) $url);
        if ($url === '' || !preg_match('~^https?://~i', $url) || stripos($url, 'tiktok.com') === false) { return ''; }
        return esc_url_raw($url);
    }

    private static function selected_tiktok_aweme_id($item, $url = '') {
        $item = is_array($item) ? $item : [];
        $candidates = [
            $item['aweme_id'] ?? '',
            $item['awemeId'] ?? '',
            $item['awemeID'] ?? '',
            $item['id'] ?? '',
            $item['video_id'] ?? '',
            $item['videoId'] ?? '',
            $item['itemId'] ?? '',
            function_exists('ves_arr_get') ? ves_arr_get($item, 'videoMeta.id', '') : '',
            function_exists('ves_arr_get') ? ves_arr_get($item, 'video.id', '') : '',
            function_exists('ves_arr_get') ? ves_arr_get($item, 'raw.aweme_id', '') : '',
            function_exists('ves_arr_get') ? ves_arr_get($item, 'raw.id', '') : '',
        ];
        foreach ($candidates as $candidate) {
            $candidate = preg_replace('/\D+/', '', (string) $candidate);
            if ($candidate !== '' && strlen($candidate) >= 10) {
                return sanitize_text_field($candidate);
            }
        }
        $url = trim((string) $url);
        if ($url !== '' && function_exists('ves_tiktok_video_id_from_url')) {
            $from_url = ves_tiktok_video_id_from_url($url);
            if ($from_url !== '') { return sanitize_text_field($from_url); }
        }
        return '';
    }

    private static function tiktok_enrichment_item_looks_like_comment($row) {
        $row = is_array($row) ? $row : [];
        if (empty($row)) { return false; }
        $has_comment_id = false;
        foreach (['cid', 'comment_id', 'commentId', 'reply_id', 'replyId'] as $key) {
            if (!empty($row[$key])) { $has_comment_id = true; break; }
        }
        $has_text = false;
        foreach (['text', 'comment', 'comment_text', 'commentText', 'reply_comment'] as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') { $has_text = true; break; }
        }
        $has_video_markers = !empty($row['aweme_id']) || !empty($row['awemeId']) || !empty($row['video']) || !empty($row['music']) || !empty($row['statistics']);
        return ($has_comment_id || $has_text) && !$has_video_markers;
    }

    private static function collect_tiktok_comments_from_enrichment_items($items) {
        $items = is_array($items) ? $items : [];
        $comments = [];
        foreach ($items as $row) {
            if (!is_array($row)) { continue; }
            foreach (['commentsList', 'comments', 'topComments', 'latestComments'] as $key) {
                if (!empty($row[$key]) && is_array($row[$key])) {
                    $comments = array_merge($comments, array_values($row[$key]));
                }
            }
            $nested_candidates = [];
            if (function_exists('ves_arr_get')) {
                $nested_candidates[] = ves_arr_get($row, 'data.comments', []);
                $nested_candidates[] = ves_arr_get($row, 'data.comment_list', []);
                $nested_candidates[] = ves_arr_get($row, 'comments.data', []);
            }
            foreach ($nested_candidates as $nested) {
                if (is_array($nested) && !empty($nested)) {
                    $comments = array_merge($comments, array_values($nested));
                }
            }
            if (self::tiktok_enrichment_item_looks_like_comment($row)) {
                $comments[] = $row;
            }
        }
        $out = [];
        $seen = [];
        foreach ($comments as $comment) {
            if (!is_array($comment)) { continue; }
            $key = md5(wp_json_encode($comment));
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $comment;
            if (count($out) >= 30) { break; }
        }
        return $out;
    }

    private static function pick_tiktok_post_enrichment_item($items, $aweme_id = '') {
        $items = is_array($items) ? $items : [];
        $aweme_id = preg_replace('/\D+/', '', (string) $aweme_id);
        foreach ($items as $row) {
            if (!is_array($row) || self::tiktok_enrichment_item_looks_like_comment($row)) { continue; }
            $row_id = self::selected_tiktok_aweme_id($row, '');
            if ($aweme_id !== '' && $row_id !== '' && $row_id === $aweme_id) { return $row; }
        }
        foreach ($items as $row) {
            if (!is_array($row) || self::tiktok_enrichment_item_looks_like_comment($row)) { continue; }
            if (!empty($row['aweme_id']) || !empty($row['awemeId']) || !empty($row['statistics']) || !empty($row['video']) || !empty($row['music'])) {
                return $row;
            }
        }
        return [];
    }

    private static function fetch_tiktok_comments_fallback($aweme_id, $request_id) {
        $aweme_id = preg_replace('/\D+/', '', (string) $aweme_id);
        if ($aweme_id === '' || !function_exists('ves_get_actor_slug') || !function_exists('ves_make_run_url') || !class_exists('VES_Apify_Client')) { return []; }
        $actor_slug = ves_get_actor_slug('tiktok_comments', ['mode' => 'selected_post_comments']);
        if (trim((string) $actor_slug) === '') { return []; }
        $input = [
            'listComments_awemeId' => $aweme_id,
            'listComments_count' => 30,
            'listComments_cursor' => 0,
        ];
        $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : ['waitForFinish' => 20];
        $url_run = ves_make_run_url($actor_slug, $options);
        self::log('tiktok_comments_fallback_dispatch', 'Fetching TikTok comments through comments fallback actor.', [
            'request_id' => $request_id,
            'actor_slug' => $actor_slug,
            'aweme_id_hash' => md5($aweme_id),
        ]);
        $response = VES_Apify_Client::request('POST', $url_run, wp_json_encode($input));
        if (is_wp_error($response)) { return []; }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $status = strtoupper((string) ($run_body['data']['status'] ?? ''));
        $run_id = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        if ($status !== 'SUCCEEDED' || $run_id === '') { return []; }
        $items = VES_Apify_Client::fetch_items($run_id, 40);
        if (is_wp_error($items) || !is_array($items) || empty($items)) { return []; }
        return self::collect_tiktok_comments_from_enrichment_items($items);
    }

    private static function fetch_apify_dataset_url_items($dataset_url, $limit = 30) {
        $dataset_url = trim((string) $dataset_url);
        if ($dataset_url === '') { return []; }
        $parts = wp_parse_url($dataset_url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host !== 'api.apify.com' || strpos($path, '/v2/datasets/') === false) { return []; }
        $url = add_query_arg([
            'format' => 'json',
            'clean' => 'true',
            'limit' => max(1, min(100, (int) $limit)),
        ], $dataset_url);
        $response = VES_Apify_Client::request('GET', $url);
        if (is_wp_error($response)) { return []; }
        return is_array($response['body'] ?? null) ? array_values($response['body']) : [];
    }

    private static function maybe_enrich_selected_item_for_analysis($platform, $item, $request_id) {
        $platform = sanitize_key((string) $platform);
        $item = is_array($item) ? $item : [];
        if ($platform !== 'tiktok') { return $item; }
        $preview_item = $item;
        $preview_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics($item) : [];
        $url = self::selected_tiktok_post_url($item);
        if ($url === '') { return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item); }
        if (self::item_has_inline_comments($item) && self::item_has_transcript($item)) {
            $item['ves_enrichment_status'] = 'already_enriched';
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        if (!function_exists('ves_get_actor_slug') || !function_exists('ves_make_run_url') || !class_exists('VES_Apify_Client')) {
            $item['ves_enrichment_status'] = 'skipped_no_provider_client';
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        $actor_slug = ves_get_actor_slug('tiktok_enrichment', ['mode' => 'selected_post_analysis']);
        if (trim((string) $actor_slug) === '') {
            $item['ves_enrichment_status'] = 'skipped_no_tiktok_actor';
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        $aweme_id = self::selected_tiktok_aweme_id($item, $url);
        if ($aweme_id === '') {
            $item['ves_enrichment_status'] = 'skipped_no_aweme_id';
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }

        // v0.9.24.49: scraptik/tiktok-api is endpoint-field based, not URL/list based.
        // Only send fields declared by the actor schema. Unsupported Clockworks fields
        // such as postURLs, shouldDownloadVideos, commentsPerPost or subtitle options
        // made the enrichment route unpredictable and could silently no-op.
        $input = [
            'post_awemeId' => $aweme_id,
            'listComments_awemeId' => $aweme_id,
            'listComments_count' => 30,
            'listComments_cursor' => 0,
        ];
        $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : ['waitForFinish' => 20];
        $url_run = ves_make_run_url($actor_slug, $options);
        self::log('tiktok_selected_enrichment_dispatch', 'Enriching selected TikTok post before AI analysis.', [
            'request_id' => $request_id,
            'actor_slug' => $actor_slug,
            'post_url_hash' => md5($url),
            'aweme_id_hash' => md5($aweme_id),
            'schema' => 'scraptik_endpoint_fields',
        ]);
        $response = VES_Apify_Client::request('POST', $url_run, wp_json_encode($input));
        if (is_wp_error($response)) {
            $item['ves_enrichment_status'] = 'failed_dispatch';
            $item['ves_enrichment_warning'] = $response->get_error_message();
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $status = strtoupper((string) ($run_body['data']['status'] ?? ''));
        $run_id = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        if ($status !== 'SUCCEEDED' || $run_id === '') {
            $item['ves_enrichment_status'] = 'not_ready_' . strtolower($status ?: 'unknown');
            $item['ves_enrichment_run_id'] = $run_id;
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        $enriched_items = VES_Apify_Client::fetch_items($run_id, 40);
        if (is_wp_error($enriched_items) || !is_array($enriched_items) || empty($enriched_items)) {
            $item['ves_enrichment_status'] = 'failed_fetch_items';
            $item['ves_enrichment_run_id'] = $run_id;
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        if (function_exists('ves_preprocess_tiktok_items')) { $enriched_items = ves_preprocess_tiktok_items($enriched_items); }
        if (function_exists('ves_strip_actor_error_items')) { $enriched_items = ves_strip_actor_error_items($enriched_items); }
        $enriched = self::pick_tiktok_post_enrichment_item($enriched_items, $aweme_id);
        $comments = self::collect_tiktok_comments_from_enrichment_items($enriched_items);
        if (!empty($enriched) || !empty($comments)) {
            // Raw actor fields win for post/profile metadata. Original normalized fields
            // remain available under ves_pre_enrichment_item for audit/debug.
            $merged = !empty($enriched) ? array_replace($item, $enriched) : $item;
            $merged['ves_pre_enrichment_item'] = $item;
            $merged['ves_tiktok_enrichment_items'] = $enriched_items;
            $merged['ves_enrichment_status'] = 'success';
            $merged['ves_enrichment_run_id'] = $run_id;
            $merged['ves_enrichment_schema'] = 'scraptik_endpoint_fields';
            if (!empty($comments)) {
                $merged['commentsList'] = $comments;
                $merged['ves_comments_enriched_from_endpoint'] = true;
            }
            $comments_dataset_url = function_exists('ves_first_non_empty') ? ves_first_non_empty(
                $merged['commentsDatasetUrl'] ?? '', $merged['commentsDatasetURL'] ?? '', $merged['commentsUrl'] ?? ''
            ) : ($merged['commentsDatasetUrl'] ?? '');
            if (!self::item_has_inline_comments($merged) && $comments_dataset_url !== '') {
                $dataset_comments = self::fetch_apify_dataset_url_items($comments_dataset_url, 30);
                if (!empty($dataset_comments)) {
                    $merged['commentsList'] = $dataset_comments;
                    $merged['ves_comments_enriched_from_dataset'] = true;
                }
            }
            if (!self::item_has_inline_comments($merged)) {
                $fallback_comments = self::fetch_tiktok_comments_fallback($aweme_id, $request_id);
                if (!empty($fallback_comments)) {
                    $merged['commentsList'] = $fallback_comments;
                    $merged['ves_comments_enriched_from_fallback_actor'] = true;
                }
            }
            $merged = self::apply_preview_metric_floor($merged, $preview_metrics, $preview_item);
            return $merged;
        }

        $fallback_comments = self::fetch_tiktok_comments_fallback($aweme_id, $request_id);
        if (!empty($fallback_comments)) {
            $item['commentsList'] = $fallback_comments;
            $item['ves_enrichment_status'] = 'comments_only_success';
            $item['ves_enrichment_run_id'] = $run_id;
            $item['ves_comments_enriched_from_fallback_actor'] = true;
            return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
        }
        $item['ves_enrichment_status'] = 'no_enriched_item';
        $item['ves_enrichment_run_id'] = $run_id;
        return self::apply_preview_metric_floor($item, $preview_metrics, $preview_item);
    }

    public static function ai_refine_input() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'ai_refine_auth', ['stage' => 'nonce']);
        }
        self::require_panel_access($request_id, 'ai_refine_auth', 'login_required');
        $request = wp_unslash($_POST);
        self::require_project_access_from_request($request_id, 'ai_refine_auth', 'project_scope', is_array($request) ? $request : []);

        $user_id = (int) get_current_user_id();
        $rate_key = 'ves_ai_refine_u_' . $user_id;
        $current = (int) get_transient($rate_key);
        if ($current >= 12) {
            self::error('Has usado demasiadas mejoras de texto seguidas. Espera un minuto.', 429, $request_id, 'ai_refine_rate', ['stage' => 'rate_limit']);
        }
        set_transient($rate_key, $current + 1, 60);

        $module = sanitize_key(wp_unslash($_POST['module'] ?? ''));
        $field = sanitize_key(wp_unslash($_POST['field'] ?? ''));
        $field_type = sanitize_key(wp_unslash($_POST['fieldType'] ?? $_POST['field_type'] ?? $field));
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
        $platform = sanitize_key(wp_unslash($_POST['platform'] ?? ''));
        $text = trim(sanitize_textarea_field(wp_unslash($_POST['text'] ?? '')));
        if ($text === '') {
            self::success([
                'improved_text' => '',
                'suggestions' => self::local_input_suggestions($module, $field_type, ''),
                'provider' => 'local',
            ]);
        }
        if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 1000 : strlen($text) > 2000) {
            self::error('El texto es demasiado largo para mejorar en este campo.', 400, $request_id, 'ai_refine_validation', ['stage' => 'length']);
        }

        $fallback = self::local_refined_input($text, $module, $field_type);
        if (!class_exists('VES_OpenAI_Client') || !method_exists('VES_OpenAI_Client', 'refine_input_text')) {
            self::success([
                'improved_text' => $fallback,
                'provider' => 'local',
                'suggestions' => self::local_input_suggestions($module, $field_type, $text),
            ]);
        }
        $res = VES_OpenAI_Client::refine_input_text($text, $module, $field_type, ['platform' => $platform, 'mode' => $mode, 'field_name' => $field]);
        if (is_wp_error($res)) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('openai_input_refine', $res->get_error_message(), [
                    'module' => $module,
                    'field' => $field,
                    'field_type' => $field_type,
                    'platform' => $platform,
                    'code' => $res->get_error_code(),
                ]);
            }
            self::success([
                'improved_text' => $fallback,
                'provider' => 'local_fallback',
                'suggestions' => self::local_input_suggestions($module, $field_type, $text),
            ]);
        }
        self::success(array_merge((array) $res, [
            'provider' => 'openai',
            'suggestions' => self::local_input_suggestions($module, $field_type, $text),
        ]));
    }

    private static function local_refined_input($text, $module = '', $field = '') {
        $text = trim(preg_replace('/\s+/', ' ', sanitize_textarea_field((string) $text)));
        $module = sanitize_key((string) $module);
        $field = sanitize_key((string) $field);
        if ($text === '') { return ''; }
        $short_terms = static function($value) {
            $value = trim((string) $value);
            $value = preg_replace('/[^\p{L}\p{N}\s#@._-]+/u', ' ', $value);
            $parts = preg_split('/[\s,;\/]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_unique(array_filter($parts, static function($p) { return strlen($p) > 1 && strlen($p) < 40; })));
            return implode(' ', array_slice($parts, 0, 4));
        };
        if (in_array($field, ['seo_keyword_seed','seedkeyword','seed_keyword','seedkeyword','seed'], true)) {
            $seed = $short_terms($text);
            return $seed !== '' ? $seed : $text;
        }
        if (in_array($field, ['hashtag','hashtags'], true)) {
            $tags = preg_split('/[\r\n,\s]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $out = [];
            foreach ($tags as $tag) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', ltrim($tag, '#')));
                if ($slug !== '' && strlen($slug) <= 40) { $out[] = '#' . $slug; }
            }
            return implode("\n", array_slice(array_values(array_unique($out)), 0, 8));
        }
        if (in_array($field, ['ads_target','adstarget','advertiser','domain','page_domain'], true)) {
            $value = trim(preg_replace('/\s+/u', ' ', $text));
            if (preg_match('~https?://[^\s]+~i', $value, $m)) { return esc_url_raw($m[0]); }
            if (preg_match('~\b[a-z0-9.-]+\.[a-z]{2,}\b~i', $value, $m)) { return strtolower($m[0]); }
            return $short_terms($value);
        }
        if (in_array($field, ['scraper_query','scraperqueryterms','queryterms','query_terms','reddit_query'], true)) {
            return $short_terms($text);
        }
        if (in_array($field, ['business_objective','businessobjective'], true)) {
            return 'Identify evidence-backed opportunities for ' . $text . ', prioritizing relevance, confidence, business value and next actions.';
        }
        if (in_array($field, ['search_context','searchcontext'], true)) {
            return 'Context: ' . $text . '. Use this as background for interpretation only, not as a provider search query.';
        }
        if (in_array($field, ['ai_analysis_goal','aianalysisgoal','analysisgoal'], true)) {
            return 'Analyze the collected evidence to ' . $text . '. Separate confirmed findings, weak hypotheses, validation needs and next actions.';
        }
        if (stripos($module, 'trend') !== false) {
            return $text . ' - find fresh, evidence-backed trend signals, separate weak hypotheses from validated opportunities, and recommend next validation steps.';
        }
        if (stripos($module, 'linkedin') !== false) {
            return $text . ' - focus on public LinkedIn content patterns, B2B positioning, audience signals and legally/provider-supported evidence only.';
        }
        if (stripos($module, 'brand') !== false) {
            return $text . ' - produce an evidence-led strategic audit with clear limitations, opportunities, recommendations and next data needs.';
        }
        return $text . ' - prioritize high-relevance public signals, explain why each result matters, and turn the evidence into actionable recommendations.';
    }

    private static function local_input_suggestions($module = '', $field = '', $text = '') {
        $module = sanitize_key((string) $module);
        $field = sanitize_key((string) $field);
        switch ($field) {
            case 'seo_keyword_seed':
            case 'seedkeyword':
            case 'seed_keyword':
                $items = ['seo tools', 'ai seo tools', 'herramientas seo', 'semrush alternatives', 'free ai image generator'];
                break;
            case 'hashtag':
            case 'hashtags':
                $items = ['#marketing', '#socialmedia', '#contentmarketing', '#brandstrategy'];
                break;
            case 'scraper_query':
            case 'scraperqueryterms':
            case 'queryterms':
            case 'query_terms':
                $items = ['AI marketing', 'marketing intelligence', 'creative testing', 'content trends'];
                break;
            case 'reddit_query':
                $items = ['AI marketing pain points', 'marketing automation objections', 'best AI marketing tools', 'agency workflow problems'];
                break;
            case 'ads_target':
            case 'adstarget':
            case 'advertiser':
            case 'domain':
                $items = ['example.com', 'competitor.com', 'https://www.facebook.com/brandpage', 'brand domain only'];
                break;
            case 'business_objective':
            case 'businessobjective':
                $items = ['Identify evidence-backed marketing opportunities in Spain and rank them by confidence.', 'Find public signals that can inspire campaign hooks, creative angles and content briefs.', 'Compare market evidence and turn the strongest signals into next actions.'];
                break;
            case 'search_context':
            case 'searchcontext':
                $items = ['Target market is Spain. Audience: marketing teams and agencies.', 'Use this as interpretation context only; provider queries must remain short.', 'Prioritize recent, public, evidence-backed signals and avoid unsupported claims.'];
                break;
            case 'ai_analysis_goal':
            case 'aianalysisgoal':
            case 'analysisgoal':
                $items = ['Explain visible patterns and call out weak evidence separately.', 'Rank findings by relevance, confidence, business value and next validation step.', 'Turn usable evidence into campaign hypotheses, not raw data summaries.'];
                break;
            case 'linkedin_role':
            case 'linkedin_company_filter':
                $items = ['Marketing Director in Spain', 'Founder or C-level at SaaS companies', 'B2B marketing teams, 11-200 employees'];
                break;
            default:
                if (stripos($module, 'seo') !== false || stripos($module, 'semrush') !== false) {
                    $items = ['Find low-competition commercial and informational keyword opportunities.', 'Cluster related demand into content briefs and AEO opportunities.', 'Prioritize by intent, search volume, difficulty, CPC and content gap.'];
                } elseif (stripos($module, 'trend') !== false) {
                    $items = ['Find emerging themes with business value, not just viral noise.', 'Rank trends by freshness, relevance, confidence and campaign opportunity.', 'Detect search-driven, social-driven and competitor-driven opportunities.'];
                } elseif (stripos($module, 'linkedin') !== false) {
                    $items = ['Analyze LinkedIn posts to find thought-leadership gaps and content angles.', 'Compare company/content activity and identify B2B positioning opportunities.', 'Find audience pain points visible in public LinkedIn posts.'];
                } else {
                    $items = ['Find high-relevance public examples and explain why they matter.', 'Compare selected evidence and generate actionable campaign recommendations.', 'Turn the strongest signals into a brief with testable angles.'];
                }
        }
        $items = array_values(array_unique(array_map(static function($x) {
            $x = trim(preg_replace('/\s+/', ' ', sanitize_text_field((string) $x)));
            if (function_exists('mb_strlen') && mb_strlen($x, 'UTF-8') > 95) { $x = mb_substr($x, 0, 92, 'UTF-8') . '...'; }
            elseif (strlen($x) > 120) { $x = substr($x, 0, 117) . '...'; }
            return $x;
        }, $items)));
        return $items;
    }

    public static function analyze_item() {
        $request_id = self::request_id();

        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'ajax_auth', ['stage' => 'nonce_analyze']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para usar esta función.', 403, $request_id, 'ajax_auth', ['stage' => 'login_required_analyze']);
        }
        self::deny_if_role_forbidden($request_id, 'ajax_auth', 'role_analyze');

        // v0.9.26.0 — Phase 1 feature gate for AI analysis.
        if (class_exists('VES_Access_Control')) {
            $user_id = get_current_user_id();
            if (!VES_Access_Control::user_can_access_feature($user_id, 'ai_analysis')) {
                $payload = VES_Access_Control::locked_error_payload('ai_analysis', 'features');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }

        $rate_key = 'ves_ai_' . (is_user_logged_in() ? 'u_' . get_current_user_id() : 'ip_' . md5(ves_get_ip()));
        $current  = (int) get_transient($rate_key);
        $window   = 60;
        $max_per_window = 6;
        if ($current >= $max_per_window) {
            self::error('Has realizado demasiados análisis seguidos. Espera un minuto.', 429, $request_id, 'ajax_rate_limit', ['stage' => 'analyze', 'rate_key' => $rate_key]);
        }
        set_transient($rate_key, $current + 1, $window);

        $platform = sanitize_key(wp_unslash($_POST['platform'] ?? ''));
        if (!in_array($platform, ['tiktok', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'moz', 'ahrefs', 'google_intel', 'trend_finder'], true)) {
            self::error('Plataforma no válida.', 400, $request_id, 'ajax_validation', ['stage' => 'platform_analyze']);
        }

        $item_json = wp_unslash($_POST['item'] ?? '');
        if (!$item_json) {
            self::error('Falta el contenido a analizar.', 400, $request_id, 'ajax_validation', ['stage' => 'missing_item']);
        }
        if (strlen($item_json) > 180000) {
            self::error('El contenido seleccionado es demasiado grande para analizarlo. Prueba con otro resultado o sin media pesada.', 400, $request_id, 'ajax_validation', ['stage' => 'oversize_item']);
        }

        $item = json_decode($item_json, true);
        if (!is_array($item)) {
            self::error('Formato del contenido no válido.', 400, $request_id, 'ajax_validation', ['stage' => 'invalid_item_json']);
        }
        $item = self::normalize_analysis_item_payload($item);
        $item = self::maybe_enrich_selected_item_for_analysis($platform, $item, $request_id);

        $focus = sanitize_textarea_field(wp_unslash($_POST['focusPrompt'] ?? ''));
        if (strlen($focus) > 500) {
            $focus = substr($focus, 0, 500);
        }
        $output_language = self::sanitize_output_language(wp_unslash($_POST['outputLanguage'] ?? $_POST['uiLanguage'] ?? 'es'));

        $usage_key = self::reserve_standard_usage_or_error($request_id, 'ai_analysis', 'usage_billing', ['platform' => $platform, 'type' => 'ai_analysis']);
        $context_args = [
            'platform' => $platform,
            'focus' => $focus,
            'topic' => ves_first_non_empty($item['title'] ?? '', $item['ves_title'] ?? '', $item['text'] ?? '', $item['ves_text'] ?? ''),
            'targets' => ves_first_non_empty($item['caption_or_text'] ?? '', $item['text'] ?? '', $item['ves_text'] ?? ''),
            'url' => ves_first_non_empty($item['url'] ?? '', $item['ves_url'] ?? ''),
        ];
        $context_pack = self::get_ai_context_pack($context_args);
        $project_context = is_array($context_pack) ? ($context_pack['project_context'] ?? null) : null;
        $context_args['brand_name'] = is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '';
        $context_args['workspace_knowledge_terms'] = self::project_context_memory_terms($project_context);
        $related_memory = is_array($context_pack['related_memory'] ?? null) ? $context_pack['related_memory'] : self::get_related_memory_context($context_args);
        $analysis = VES_OpenAI_Client::analyze_item($platform, $item, $focus, $related_memory, $project_context, $output_language);
        if (is_wp_error($analysis)) {
            self::void_standard_usage($usage_key, $analysis->get_error_message());
            self::error($analysis->get_error_message(), 500, $request_id, 'openai_analysis_error', ['platform' => $platform]);
        }
        if (!empty($analysis['fallback'])) {
            self::void_standard_usage($usage_key, 'OpenAI fallback local; no provider completion.');
        } else {
            self::post_standard_usage_or_error($usage_key, $request_id, 'usage_billing', 'Análisis AI confirmado');
        }

        $analysis['request_id'] = $request_id;
        $analysis['project_context_used'] = !empty($project_context);
        $analysis['related_reports_used'] = is_array($related_memory) ? count($related_memory) : 0;
        $analysis = self::attach_ai_context_meta($analysis, $context_pack);
        if (empty($analysis['fallback']) && class_exists('VES_AI_Context_Builder')) {
            $writeback = VES_AI_Context_Builder::writeback_after_analysis([
                'workspace_id' => $context_pack['workspace_id'] ?? 0,
                'user_id' => get_current_user_id(),
                'platform' => $platform,
                'source_id' => $request_id,
                'request_id' => $request_id,
                'title' => ves_first_non_empty($item['title'] ?? '', $item['ves_title'] ?? '', 'AI analysis: ' . $platform),
                'analysis' => $analysis['analysis'] ?? '',
                'focus' => $focus,
                'brand_name' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '',
                'used_context' => $analysis['used_context'] ?? '',
            ]);
            if (current_user_can('manage_options')) { $analysis['memory_writeback'] = $writeback; }
        }
        if (empty($analysis['fallback']) && class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::save_ai_analysis($platform, $item, $focus, $analysis);
        }
        self::log('openai_analysis_ok', 'AI analysis generated', [
            'request_id' => $request_id,
            'platform'   => $platform,
            'model'      => $analysis['model'] ?? '',
        ]);
        self::success($analysis);
    }

    public static function analyze_items() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'ajax_auth', ['stage' => 'nonce_analyze_batch']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para usar esta función.', 403, $request_id, 'ajax_auth', ['stage' => 'login_required_analyze_batch']);
        }
        self::deny_if_role_forbidden($request_id, 'ajax_auth', 'role_analyze_batch');

        // v0.9.26.0 — Phase 1 feature gate for multi-item / batch AI analysis.
        if (class_exists('VES_Access_Control')) {
            $user_id = get_current_user_id();
            if (!VES_Access_Control::user_can_access_feature($user_id, 'ai_batch_analysis')) {
                $payload = VES_Access_Control::locked_error_payload('ai_batch_analysis', 'features');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }

        $rate_key = 'ves_ai_batch_' . (is_user_logged_in() ? 'u_' . get_current_user_id() : 'ip_' . md5(ves_get_ip()));
        $current  = (int) get_transient($rate_key);
        if ($current >= 4) {
            self::error('Has realizado demasiados análisis comparativos seguidos. Espera un minuto.', 429, $request_id, 'ajax_rate_limit', ['stage' => 'analyze_batch']);
        }
        set_transient($rate_key, $current + 1, 60);

        $platform = sanitize_key(wp_unslash($_POST['platform'] ?? ''));
        if (!in_array($platform, ['tiktok', 'youtube', 'facebook', 'instagram', 'facebook_ads', 'google_ads', 'twitter', 'linkedin', 'reddit', 'pinterest', 'semrush', 'moz', 'ahrefs', 'google_intel', 'trend_finder'], true)) {
            self::error('Plataforma no válida.', 400, $request_id, 'ajax_validation', ['stage' => 'platform_analyze_batch']);
        }

        $items_json = wp_unslash($_POST['items'] ?? '');
        if (!$items_json) {
            self::error('Faltan los contenidos seleccionados.', 400, $request_id, 'ajax_validation', ['stage' => 'missing_items']);
        }
        if (strlen($items_json) > 160000) {
            self::error('La selección es demasiado grande. Selecciona menos resultados o reduce transcripciones.', 400, $request_id, 'ajax_validation', ['stage' => 'oversize_items']);
        }
        $items = json_decode($items_json, true);
        if (!is_array($items)) {
            self::error('Formato de selección no válido.', 400, $request_id, 'ajax_validation', ['stage' => 'invalid_items_json']);
        }
        $items = array_values(array_filter($items, 'is_array'));
        if (empty($items)) {
            self::error('Selecciona al menos un resultado para analizar.', 400, $request_id, 'ajax_validation', ['stage' => 'empty_items']);
        }
        $items = array_slice($items, 0, 12);
        $items = array_map([__CLASS__, 'normalize_analysis_item_payload'], $items);

        $focus = sanitize_textarea_field(wp_unslash($_POST['focusPrompt'] ?? ''));
        if (strlen($focus) > 500) { $focus = substr($focus, 0, 500); }
        $output_language = self::sanitize_output_language(wp_unslash($_POST['outputLanguage'] ?? $_POST['uiLanguage'] ?? 'es'));

        $topic_parts = [];
        foreach (array_slice($items, 0, 5) as $it) {
            $topic_parts[] = ves_first_non_empty($it['title'] ?? '', $it['ves_title'] ?? '', $it['caption_or_text'] ?? '', $it['text'] ?? '', $it['ves_text'] ?? '');
        }
        $topic = implode(' | ', array_filter($topic_parts));
        $context_args = ['platform' => $platform, 'focus' => $focus, 'topic' => $topic, 'targets' => $topic];
        $context_pack = self::get_ai_context_pack($context_args);
        $project_context = is_array($context_pack) ? ($context_pack['project_context'] ?? null) : null;
        $context_args['brand_name'] = is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '';
        $context_args['workspace_knowledge_terms'] = self::project_context_memory_terms($project_context);
        $related_memory = is_array($context_pack['related_memory'] ?? null) ? $context_pack['related_memory'] : self::get_related_memory_context($context_args);

        $usage_key = self::reserve_standard_usage_or_error($request_id, 'ai_analysis', 'usage_billing', ['platform' => $platform, 'type' => 'ai_batch_analysis', 'selected_items' => count($items)]);
        $analysis = VES_OpenAI_Client::analyze_items($platform, $items, $focus, $related_memory, $project_context, $output_language);
        if (is_wp_error($analysis)) {
            self::void_standard_usage($usage_key, $analysis->get_error_message());
            self::error($analysis->get_error_message(), 500, $request_id, 'openai_analysis_error', ['platform' => $platform, 'selected_items' => count($items)]);
        }
        if (!empty($analysis['fallback'])) {
            self::void_standard_usage($usage_key, 'OpenAI fallback local; no provider completion.');
        } else {
            self::post_standard_usage_or_error($usage_key, $request_id, 'usage_billing', 'Análisis AI comparativo confirmado');
        }
        $analysis['request_id'] = $request_id;
        $analysis['project_context_used'] = !empty($project_context);
        $analysis['related_reports_used'] = is_array($related_memory) ? count($related_memory) : 0;
        $analysis['selected_items'] = count($items);
        $analysis = self::attach_ai_context_meta($analysis, $context_pack);
        if (empty($analysis['fallback']) && class_exists('VES_AI_Context_Builder')) {
            $writeback = VES_AI_Context_Builder::writeback_after_analysis([
                'workspace_id' => $context_pack['workspace_id'] ?? 0,
                'user_id' => get_current_user_id(),
                'platform' => $platform . '_batch',
                'source_id' => $request_id,
                'request_id' => $request_id,
                'title' => 'Batch AI analysis: ' . $platform,
                'analysis' => $analysis['analysis'] ?? '',
                'focus' => $focus,
                'brand_name' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '',
                'used_context' => $analysis['used_context'] ?? '',
            ]);
            if (current_user_can('manage_options')) { $analysis['memory_writeback'] = $writeback; }
        }

        if (empty($analysis['fallback']) && class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::save_ai_analysis($platform . '_batch', ['title' => 'Batch analysis', 'items' => $items], $focus, $analysis);
            foreach ($items as $idx => $item) {
                $item_analysis = $analysis;
                $item_analysis['analysis'] = 'Parte de análisis comparativo #' . ($idx + 1) . "\n\n" . ($analysis['analysis'] ?? '');
                VES_Temp_Memory::save_ai_analysis($platform, $item, $focus, $item_analysis);
            }
        }
        self::log('openai_batch_analysis_ok', 'Batch AI analysis generated', ['request_id' => $request_id, 'platform' => $platform, 'items' => count($items), 'model' => $analysis['model'] ?? '']);
        self::success($analysis);
    }

    private static function trend_bundle_access_allowed($bundle) {
        if (!is_array($bundle)) {
            return false;
        }
        $bundle_user_id = (int) ($bundle['user_id'] ?? 0);
        if ($bundle_user_id > 0) {
            return is_user_logged_in() && get_current_user_id() === $bundle_user_id;
        }
        $session_key = class_exists('VES_Temp_Memory') ? VES_Temp_Memory::maybe_set_session_cookie() : '';
        return $session_key !== '' && hash_equals((string) ($bundle['session_key'] ?? ''), (string) $session_key);
    }

    private static function trend_usage_key($bundle_id) {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        return $bundle_id !== '' ? 'trend_run:' . $bundle_id : '';
    }

    private static function reserve_usage_or_error($bundle_id, $request_id, $event_type, $context = []) {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing')) {
            return true;
        }
        $usage_key = self::trend_usage_key($bundle_id);
        $reserved = VES_Usage_Billing::reserve_usage(get_current_user_id(), $event_type, $usage_key, '', array_merge((array) $context, [
            'usage_key' => $usage_key,
            'target_type' => 'trend_run',
            'target_id' => $bundle_id,
        ]));
        if (is_wp_error($reserved)) {
            self::error($reserved->get_error_message(), 402, $request_id, 'usage_billing', ['stage' => 'reserve_usage', 'event_type' => $event_type]);
        }
        return $usage_key;
    }

    private static function finalize_trend_usage($bundle_id, $run_status, $message = '') {
        if (!is_user_logged_in() || !class_exists('VES_Usage_Billing')) {
            return;
        }
        if (class_exists('VES_Usage_Reconciliation_Service')) {
            $bundle = self::trend_get_bundle($bundle_id);
            $final = is_array($bundle['final'] ?? null) ? $bundle['final'] : [];
            VES_Usage_Reconciliation_Service::finalize_for_bundle($bundle_id, $run_status, $message !== '' ? $message : 'Trend Finder finalizado', $final);
            return;
        }
        $usage_key = self::trend_usage_key($bundle_id);
        if ($usage_key === '') {
            return;
        }
        if (in_array((string) $run_status, ['completed', 'partial'], true)) {
            VES_Usage_Billing::post_reserved_usage($usage_key, $message !== '' ? $message : 'Trend Finder confirmado');
        } else {
            VES_Usage_Billing::void_reserved_usage($usage_key, $message !== '' ? $message : 'Trend Finder sin evidencia utilizable');
        }
    }

    private static function trend_bundle_transient_ttl() {
        return 2 * HOUR_IN_SECONDS;
    }

    private static function trend_make_bundle_id() {
        try {
            return 'vestr-' . strtolower(wp_generate_uuid4());
        } catch (Throwable $e) {
            return 'vestr-' . strtolower(wp_generate_password(24, false, false));
        }
    }

    private static function trend_get_bundle($bundle_id) {
        if (!$bundle_id || !function_exists('ves_trend_bundle_key')) {
            return null;
        }
        $bundle = get_transient(ves_trend_bundle_key($bundle_id));
        return is_array($bundle) ? $bundle : null;
    }

    private static function trend_save_bundle($bundle_id, $bundle) {
        if (!$bundle_id || !function_exists('ves_trend_bundle_key')) {
            return;
        }
        set_transient(ves_trend_bundle_key($bundle_id), $bundle, self::trend_bundle_transient_ttl());
    }

    private static function trend_start_single_source($source_key, $source, $request_id) {
        $actor_slug = trim((string) ($source['actor_slug'] ?? ''));
        $input = is_array($source['input'] ?? null) ? $source['input'] : [];
        if ($actor_slug === '') {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => '',
                'status' => 'CONFIG_ERROR',
                'dispatch_status' => 'error',
                'scrape_status' => 'CONFIG_ERROR',
                'parse_status' => 'not_started',
                'analytical_usability_status' => 'failed',
                'notes' => 'Actor slug no configurado.',
                'run_id' => '',
                'input' => $input,
                'attempts' => 1,
            ];
        }
        if (class_exists('VES_Source_Adapter_Registry')) {
            $validation = VES_Source_Adapter_Registry::validate_input($source_key, $actor_slug, $input);
            if (is_wp_error($validation)) {
                return [
                    'label' => $source['label'] ?? $source_key,
                    'actor_slug' => $actor_slug,
                    'status' => 'SKIPPED_INVALID_INPUT',
                    'dispatch_status' => 'skipped_invalid_input',
                    'scrape_status' => 'SKIPPED_INVALID_INPUT',
                    'parse_status' => 'not_started',
                    'analytical_usability_status' => 'failed',
                    'notes' => $validation->get_error_message(),
                    'run_id' => '',
                    'input' => $input,
                    'attempts' => 1,
                ];
            }
        }
        $user_id = !empty($source['user_id']) ? (int) $source['user_id'] : (int) get_current_user_id();
        $slot = method_exists('VES_Apify_Client', 'acquire_dispatch_slot') ? VES_Apify_Client::acquire_dispatch_slot($actor_slug, $user_id, ['source' => $source_key, 'request_id' => $request_id]) : '';
        if (is_wp_error($slot)) {
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'status' => 'WAITING_RETRY',
                'dispatch_status' => 'waiting_retry',
                'scrape_status' => 'WAITING_RETRY',
                'parse_status' => 'not_started',
                'analytical_usability_status' => 'unknown',
                'notes' => $slot->get_error_message(),
                'run_id' => '',
                'input' => $input,
                'attempts' => 1,
            ];
        }
        // v0.9.24.7: token is sent via Authorization header; do not include in URL options.
        $options = function_exists('ves_prepare_run_options') ? ves_prepare_run_options() : ['waitForFinish' => 0];
        $options['waitForFinish'] = 0;
        $url = function_exists('ves_make_run_url') ? ves_make_run_url($actor_slug, $options) : '';
        try {
            $response = VES_Apify_Client::request('POST', $url, wp_json_encode($input));
        } catch (Throwable $dispatch_error) {
            $response = new WP_Error('apify_dispatch_throwable', 'Provider dispatch failed before returning a response.', [
                'status' => 500,
                'provider_state' => 'DISPATCH_THROWABLE',
                'technical_message' => $dispatch_error->getMessage(),
                'file' => $dispatch_error->getFile(),
                'line' => $dispatch_error->getLine(),
            ]);
            self::log('provider_dispatch_throwable', $dispatch_error->getMessage(), [
                'request_id' => $request_id,
                'public_support_code' => self::public_support_code($request_id),
                'platform' => $platform,
                'actor_slug' => $actor_slug,
                'source_execution_key' => $source_execution_key ?? '',
                'dispatch_slot_id' => $dispatch_slot,
                'stage' => 'provider_dispatch_throwable',
                'exception_class' => get_class($dispatch_error),
                'file' => $dispatch_error->getFile(),
                'line' => $dispatch_error->getLine(),
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => !empty($usage_key),
            ]);
        }
        if (is_wp_error($response)) {
            if ($slot && method_exists('VES_Apify_Client', 'release_dispatch_slot')) { VES_Apify_Client::release_dispatch_slot($slot); }
            $provider_state = (string) ($response->get_error_data()['provider_state'] ?? 'DISPATCH_ERROR');
            $queued_memory = $response->get_error_code() === 'apify_memory_limit' || $provider_state === 'QUEUED_RETRY_MEMORY_LIMIT';
            return [
                'label' => $source['label'] ?? $source_key,
                'actor_slug' => $actor_slug,
                'status' => $queued_memory ? 'QUEUED_RETRY_MEMORY_LIMIT' : ($provider_state === 'WAITING_RETRY' ? 'WAITING_RETRY' : 'DISPATCH_ERROR'),
                'dispatch_status' => $queued_memory ? 'queued_retry_memory_limit' : ($provider_state === 'WAITING_RETRY' ? 'waiting_retry' : 'error'),
                'scrape_status' => $queued_memory ? 'QUEUED_RETRY_MEMORY_LIMIT' : ($provider_state === 'WAITING_RETRY' ? 'WAITING_RETRY' : 'DISPATCH_ERROR'),
                'parse_status' => 'not_started',
                'analytical_usability_status' => $queued_memory ? 'unknown' : 'failed',
                'notes' => $response->get_error_message(),
                'run_id' => '',
                'input' => $input,
                'attempts' => 1,
            ];
        }
        $run_body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $run_id = sanitize_text_field((string) ($run_body['data']['id'] ?? ''));
        if ($slot && $run_id !== '' && method_exists('VES_Apify_Client', 'register_run_id_for_slot')) { VES_Apify_Client::register_run_id_for_slot($slot, $run_id); }
        return [
            'label' => $source['label'] ?? $source_key,
            'actor_slug' => $actor_slug,
            'status' => sanitize_text_field((string) ($run_body['data']['status'] ?? 'RUNNING')),
            'dispatch_status' => 'ok',
            'scrape_status' => sanitize_text_field((string) ($run_body['data']['status'] ?? 'RUNNING')),
            'parse_status' => 'pending',
            'analytical_usability_status' => 'unknown',
            'notes' => '',
            'run_id' => $run_id,
            'apify_slot_id' => $slot, // v0.9.24.7: aligned with async service key name
            'input' => $input,
            'attempts' => 1,
        ];
    }

    private static function trend_source_terminal($status) {

        return in_array((string) $status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'CONFIG_ERROR', 'DISPATCH_ERROR', 'SKIPPED_INVALID_INPUT', 'STALE_NEEDS_RECOVERY'], true);
    }

    private static function trend_finalize_bundle($bundle, $request_id) {
        $request = $bundle['request'] ?? [];
        $sources = $bundle['sources'] ?? [];
        $ui_sources = [];

        foreach ($sources as $source_key => $source) {
            $status = (string) ($source['status'] ?? 'UNKNOWN');
            $raw_items = [];
            if ($status === 'SUCCEEDED' && !empty($source['run_id'])) {
                $raw_items = VES_Apify_Client::fetch_items($source['run_id'], 80);
                if (is_wp_error($raw_items)) {
                    $status = 'FAILED';
                    $source['status'] = 'FAILED';
                    $source['notes'] = $raw_items->get_error_message();
                    $raw_items = [];
                }
            }
            // v0.9.9.3: respect dateFromIso for Trend Finder too. Items with no
            // parseable timestamp are kept (we don't want to drop e.g. Google
            // Trends queries which often have no per-item date).
            $date_from_iso = (string) ($request['date_from'] ?? '');
            if ($date_from_iso !== '' && function_exists('ves_filter_items_by_date_from') && is_array($raw_items) && !empty($raw_items)) {
                list($raw_items, ) = ves_filter_items_by_date_from($raw_items, $date_from_iso);
            }
            if (class_exists('VES_Trend_Source_Evaluator')) {
                $ui_sources[$source_key] = VES_Trend_Source_Evaluator::evaluate_source($source_key, array_merge((array) $source, ['status' => $status]), $raw_items, $request);
            } else {
                $ui_sources[$source_key] = [
                    'label' => $source['label'] ?? $source_key,
                    'status' => $status,
                    'item_count' => is_array($raw_items) ? count($raw_items) : 0,
                    'notes' => $source['notes'] ?? '',
                    'highlights' => function_exists('ves_trend_extract_highlights') ? ves_trend_extract_highlights($source_key, $raw_items) : [],
                    'compact_items' => function_exists('ves_trend_compact_items_for_ai') ? ves_trend_compact_items_for_ai($source_key, $raw_items) : array_slice((array) $raw_items, 0, 20),
                    'analytical_usability_status' => $status === 'SUCCEEDED' ? 'usable' : 'failed',
                    'metric_completeness_score' => 0,
                    'geo_fit_score' => 0,
                    'language_fit_score' => 0,
                ];
            }
        }

        $context_args = [
            'platform' => 'trend_finder',
            'topic' => $request['topic'] ?? '',
            'focus' => $request['reason'] ?? '',
            'country' => $request['country'] ?? '',
            'reason' => $request['reason'] ?? '',
        ];
        $context_pack = self::get_ai_context_pack($context_args);
        $project_context = is_array($context_pack) ? ($context_pack['project_context'] ?? null) : null;
        $context_args['brand_name'] = is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '';
        $context_args['workspace_knowledge_terms'] = self::project_context_memory_terms($project_context);
        $related_memory = is_array($context_pack['related_memory'] ?? null) ? $context_pack['related_memory'] : self::get_related_memory_context($context_args);
        $source_summary = class_exists('VES_Trend_Source_Evaluator') ? VES_Trend_Source_Evaluator::summarize_run_sources($ui_sources) : [
            'total_sources' => count($ui_sources),
            'usable_sources' => count($ui_sources),
            'partial_sources' => 0,
            'failed_sources' => 0,
            'metric_average' => 0,
        ];
        $run_status = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_status($source_summary) : 'completed';
        $confidence_mode = class_exists('VES_Trend_Scoring') ? VES_Trend_Scoring::resolve_run_mode($source_summary) : 'validated';

        $analysis = VES_OpenAI_Client::analyze_trend_bundle($request, $ui_sources, $related_memory, $project_context);
        if (is_wp_error($analysis)) {
            return $analysis;
        }

        $analysis_structured = is_array($analysis['analysis_structured'] ?? null) ? $analysis['analysis_structured'] : null;
        $fallback_used = !empty($analysis['fallback_used']) || !empty($analysis_structured['fallback_used']) || (($analysis['confidence_mode'] ?? '') === 'diagnostic_fallback') || (($analysis_structured['confidence_mode'] ?? '') === 'diagnostic_fallback');
        if ($fallback_used) {
            $run_status = 'fallback_completed';
            $confidence_mode = 'diagnostic_fallback';
        }
        $direct_matches = (int) ($source_summary['direct_matches'] ?? 0);
        $usable_sources = (int) ($source_summary['usable_sources'] ?? 0);
        $validated_enough_for_brief = !$fallback_used && $confidence_mode === 'validated' && $usable_sources >= 2 && $direct_matches > 0;
        $insights = (!$fallback_used && class_exists('VES_Trend_Scoring')) ? VES_Trend_Scoring::build_insights_from_analysis($analysis_structured, $source_summary, $confidence_mode) : [];
        $brief_ready_insights = !$validated_enough_for_brief ? [] : array_map(static function ($insight) {
            return [
                'insight_id' => 0,
                'trend_name' => sanitize_text_field((string) ($insight['trend_name'] ?? 'Trend')),
                'fit_score' => $insight['fit_score'] ?? null,
                'evidence_score' => $insight['evidence_score'] ?? null,
                'confidence_score' => $insight['confidence_score'] ?? null,
                'actionability_score' => $insight['actionability_score'] ?? null,
                'priority_score' => $insight['priority_score'] ?? null,
                'status' => sanitize_key((string) ($insight['status'] ?? 'new')),
            ];
        }, $insights);

        $report = [
            'platform' => 'trend_finder',
            'status' => 'SUCCEEDED',
            'run_status' => $run_status,
            'confidence_mode' => $confidence_mode,
            'fallback_used' => $fallback_used,
            'validation_required' => !$validated_enough_for_brief,
            'can_generate_brief' => $validated_enough_for_brief,
            'memory_type' => $fallback_used ? 'fallback_diagnostic' : (($run_status === 'partial' || !$validated_enough_for_brief) ? 'partial_report' : 'validated_ai_report'),
            'report_status' => $fallback_used ? 'fallback_diagnostic' : ($validated_enough_for_brief ? 'validated_report' : 'validation_report'),
            'analysis' => $analysis['analysis'],
            'analysis_structured' => $analysis_structured,
            'analysis_format' => $analysis['format'] ?? 'text',
            'model' => $analysis['model'],
            'requested' => [
                'topic' => $request['topic'] ?? '',
                'country' => $request['country'] ?? 'None',
                'country_label' => function_exists('ves_trend_label_country') ? ves_trend_label_country($request['country'] ?? 'None') : ($request['country'] ?? 'None'),
                'language' => $request['language'] ?? '',
                'duration' => $request['duration'] ?? '7d',
                'duration_label' => function_exists('ves_trend_duration_label') ? ves_trend_duration_label($request['duration'] ?? '7d') : ($request['duration'] ?? '7d'),
                'date_from' => $request['date_from'] ?? '',
                'reason' => $request['reason'] ?? '',
            ],
            'sources' => $ui_sources,
            'evidence_coverage_summary' => is_array($source_summary['evidence_coverage_summary'] ?? null) ? $source_summary['evidence_coverage_summary'] : [
                'sources_queried' => (int) ($source_summary['total_sources'] ?? count($ui_sources)),
                'usable_records' => (int) ($source_summary['usable_records'] ?? 0),
                'direct_matches' => $direct_matches,
                'adjacent_matches' => (int) ($source_summary['adjacent_matches'] ?? 0),
                'discarded_or_noise_count' => (int) ($source_summary['discarded_or_noise_count'] ?? 0),
                'confidence_reason' => $validated_enough_for_brief ? 'validated_evidence' : 'insufficient_direct_evidence',
            ],
            'source_quality_breakdown' => is_array($source_summary['source_quality_breakdown'] ?? null) ? $source_summary['source_quality_breakdown'] : [],
            'successful_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'usable_sources' => (int) ($source_summary['usable_sources'] ?? 0),
            'partial_sources' => (int) ($source_summary['partial_sources'] ?? 0),
            'failed_sources' => (int) ($source_summary['failed_sources'] ?? 0),
            'total_sources' => (int) ($source_summary['total_sources'] ?? 0),
            'generated_at' => current_time('mysql'),
            'request_id' => $request_id,
            'bundle_id' => $bundle['bundle_id'] ?? '',
            'project_context_used' => !empty($project_context),
            'related_reports_used' => is_array($related_memory) ? count($related_memory) : 0,
            'used_context' => is_array($context_pack ?? null) ? sanitize_text_field((string) ($context_pack['used_context'] ?? 'Used context: current request only.')) : 'Used context: current request only.',
            'query_plan' => is_array($bundle['query_plan'] ?? null) ? $bundle['query_plan'] : [],
            'insights' => $insights,
            'brief_ready_insights' => $brief_ready_insights,
        ];
        if (class_exists('VES_AI_Context_Builder')) {
            $writeback = VES_AI_Context_Builder::writeback_after_analysis([
                'user_id' => get_current_user_id(),
                'workspace_id' => class_exists('VES_Memory_Records') ? VES_Memory_Records::workspace_id_for_user(get_current_user_id()) : get_current_user_id(),
                'platform' => 'trend_finder',
                'source_id' => $request_id,
                'request_id' => $request_id,
                'title' => 'Trend Finder analysis: ' . sanitize_text_field((string) ($request['topic'] ?? 'trend')),
                'analysis' => is_string($analysis['analysis'] ?? null) ? $analysis['analysis'] : wp_json_encode($analysis_structured, JSON_UNESCAPED_UNICODE),
                'focus' => sanitize_textarea_field((string) ($request['reason'] ?? '')),
                'used_context' => $report['used_context'] ?? '',
                'evidence_refs' => [],
            ]);
            if (current_user_can('manage_options')) {
                $report['memory_writeback'] = $writeback;
            }
        }
        if (current_user_can('manage_options') && is_array($context_pack['diagnostics'] ?? null)) {
            $report['memory_diagnostics'] = $context_pack['diagnostics'];
        }
        return $report;
    }

    public static function brand_audit_start() {
        $request_id = self::request_id();
        self::register_ajax_shutdown_handler($request_id, 'ves_brand_audit_start');
        try {
            self::brand_audit_start_impl($request_id);
        } catch (Throwable $e) {
            self::fatal($e, $request_id, 'brand_audit_start_fatal', ['action' => 'ves_brand_audit_start']);
        }
    }

    private static function brand_audit_start_impl($request_id) {
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'brand_audit_auth', ['stage' => 'nonce_start']);
        }
        self::require_panel_access($request_id, 'brand_audit_auth', 'brand_audit_start');
        if (self::production_mvp_enabled()) {
            self::mvp_disabled_ajax_response($request_id, 'brand_audit_mvp_gate', 'brand_deep_audit', [
                'stage' => 'production_mvp_gate',
                'platform' => 'brand_audit',
            ]);
        }

        // v0.9.26.0 — Phase 1 access gate for Brand Deep Audit.
        if (class_exists('VES_Access_Control')) {
            $user_id = get_current_user_id();
            if (!VES_Access_Control::user_can_access_module($user_id, 'brand-audit')) {
                $payload = VES_Access_Control::locked_error_payload('brand-audit', 'modules');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }

        $request = class_exists('VES_Brand_Audit') ? VES_Brand_Audit::sanitize_request($_POST) : [];
        if (empty($request['brand_name'])) {
            self::error('Debes indicar el nombre de la marca.', 400, $request_id, 'brand_audit_validation', ['stage' => 'brand_name']);
        }
        self::require_project_access_from_request($request_id, 'brand_audit_auth', 'project_scope_start', $request);

        $start_gate_key = self::acquire_start_gate(
            self::start_gate_key('brand_audit', 'brand_audit', md5(strtolower((string) ($request['brand_name'] ?? '')))),
            $request_id,
            'brand_audit_rate_limit',
            ['platform' => 'brand_audit']
        );

        try {
            $created = class_exists('VES_Brand_Audit_Execution_Service')
                ? VES_Brand_Audit_Execution_Service::create_brand_audit_run($request, ['trigger' => 'manual', 'request_id' => $request_id])
                : new WP_Error('brand_audit_missing', 'Brand Audit no está disponible.');
            if (is_wp_error($created)) {
                self::error($created->get_error_message(), (int) (($created->get_error_data()['status'] ?? 500)), $request_id, 'brand_audit_dispatch', ['stage' => 'create_run']);
            }

            $bundle_id = sanitize_text_field((string) ($created['bundle_id'] ?? ''));
            $live_bundle = ($bundle_id !== '' && class_exists('VES_Brand_Audit_Execution_Service')) ? VES_Brand_Audit_Execution_Service::start_queued_sources($bundle_id, (int) ($created['run_id'] ?? 0)) : null;
            $live_sources = is_array($live_bundle['sources'] ?? null) ? array_values($live_bundle['sources']) : (array) ($created['sources'] ?? []);
            $live_summary = is_array($live_bundle) && class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::summarize_bundle_for_ui($live_bundle) : null;

            // Count heavy sources using the explicit flag set by the query planner
            // rather than slug-matching, which over-counts non-social Google sources.
            $source_preview_data = is_array($created['source_preview'] ?? null) ? $created['source_preview'] : [];
            $provider_source_count = count($live_sources);
            $provider_heavy_count = 0;
            foreach ($live_sources as $provider_source) {
                if (!empty($provider_source['is_heavy_source'])) {
                    $provider_heavy_count++;
                } else {
                    // Fallback: treat social actor slugs as heavy when flag is absent.
                    $slug = strtolower((string) ($provider_source['actor_slug'] ?? $provider_source['slug'] ?? ''));
                    if (strpos($slug, 'tiktok') !== false || strpos($slug, 'youtube') !== false || strpos($slug, 'instagram') !== false) {
                        $provider_heavy_count++;
                    }
                }
            }

            $provider_estimated_cost = class_exists('VES_Config') ? VES_Config::apify_estimate_provider_cost($provider_source_count, $provider_heavy_count) : 0;
            $provider_warning_threshold = class_exists('VES_Config') ? VES_Config::apify_cost_warning_threshold_usd() : 0;
            $provider_cost_limit = class_exists('VES_Config') ? VES_Config::apify_max_estimated_cost_per_run() : 0;
            $provider_max_sources = class_exists('VES_Config') ? VES_Config::apify_max_sources_per_run() : 100;
            self::success([
                'platform' => 'brand_audit',
                'status' => 'RUNNING',
                'run_phase' => is_array($live_summary) ? ($live_summary['run_phase'] ?? 'collecting') : 'collecting',
                'phase_label' => is_array($live_summary) ? ($live_summary['phase_label'] ?? 'Collecting evidence') : 'Collecting evidence',
                'bundle_id' => $bundle_id,
                'run_id' => (int) ($created['run_id'] ?? 0),
                'progress' => is_array($live_summary) ? ($live_summary['progress'] ?? ['done' => 0, 'total' => count($live_sources)]) : [
                    'done' => 0,
                    'total' => count($live_sources),
                ],
                'query_plan' => $created['query_plan'] ?? [],
                'source_preview' => $created['source_preview'] ?? [],
                'provider_cost' => array_merge([
                    'estimated_usd' => $provider_estimated_cost,
                    'source_count' => $provider_source_count,
                    'heavy_source_count' => $provider_heavy_count,
                    'warning_threshold_usd' => $provider_warning_threshold,
                    'max_estimated_cost_per_run_usd' => $provider_cost_limit,
                    'max_sources_per_run' => $provider_max_sources,
                    'warning' => ($provider_warning_threshold > 0 && $provider_estimated_cost >= $provider_warning_threshold) || ($provider_max_sources > 0 && $provider_source_count > $provider_max_sources),
                    'limit_exceeded' => ($provider_cost_limit > 0 && $provider_estimated_cost > $provider_cost_limit) || ($provider_max_sources > 0 && $provider_source_count > $provider_max_sources),
                    'note' => 'Admin-only external provider estimate; separate from internal credits.',
                ], is_array($source_preview_data) ? $source_preview_data : []),
                'sources' => is_array($live_summary) ? ($live_summary['sources'] ?? []) : (class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::sources_for_ui($live_sources) : []),
                'request_id' => $request_id,
            ]);
        } finally {
            // Gate must release on every exit path: success, WP_Error early-return,
            // and any Throwable that slips past the is_wp_error() check above.
            self::release_start_gate($start_gate_key ?? '');
        }
    }

    public static function brand_audit_poll() {
        $request_id = self::request_id();
        self::register_ajax_shutdown_handler($request_id, 'ves_brand_audit_poll');
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'brand_audit_auth', ['stage' => 'nonce_poll']);
        }
        self::require_panel_access($request_id, 'brand_audit_auth', 'brand_audit_poll');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? ''));
        if ($bundle_id === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $bundle_id)) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'brand_audit_validation', ['stage' => 'bundle_id']);
        }
        $bundle = class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::get_bundle($bundle_id) : null;
        if (!$bundle) {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 404, $request_id, 'brand_audit_lookup', ['bundle_id' => $bundle_id]);
        }
        if (!VES_Brand_Audit_Execution_Service::bundle_access_allowed($bundle)) {
            self::error('No autorizado para consultar este proceso.', 403, $request_id, 'brand_audit_auth', ['stage' => 'bundle_scope']);
        }
        if (!empty($bundle['final']) && is_array($bundle['final'])) {
            $final = $bundle['final'];
            $final['request_id'] = $request_id;
            self::success($final);
        }

        try {
            VES_Brand_Audit_Execution_Service::tick_brand_audit_run($bundle_id);
        } catch (Throwable $e) {
            if (class_exists('VES_Admin')) {
                VES_Admin::record_diagnostic('brand_audit_tick_failed', $e->getMessage(), [
                    'bundle_id' => $bundle_id,
                    'request_id' => $request_id,
                ]);
            }
            // Do not drop the persistent task on a transient tick failure. The
            // frontend can recover through ves_brand_audit_active and continue.
            self::success([
                'platform' => 'brand_audit',
                'status' => 'RUNNING',
                'run_phase' => 'recovering',
                'phase_label' => 'Saved / retrying next check',
                'bundle_id' => $bundle_id,
                'progress' => ['done' => 0, 'total' => count((array) ($bundle['sources'] ?? []))],
                'sources' => class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::sources_for_ui((array) ($bundle['sources'] ?? [])) : [],
                'status_message' => 'Temporary Brand Audit tick error. The run is still saved and can be resumed.',
                'request_id' => $request_id,
            ]);
        }
        $bundle = VES_Brand_Audit_Execution_Service::get_bundle($bundle_id);
        if (!empty($bundle['final']) && is_array($bundle['final'])) {
            $final = $bundle['final'];
            $final['request_id'] = $request_id;
            self::success($final);
        }

        $done = 0;
        foreach ((array) ($bundle['sources'] ?? []) as $source) {
            if (in_array((string) ($source['status'] ?? ''), ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT', 'CONFIG_ERROR', 'DISPATCH_ERROR', 'PARTIAL'], true)) {
                $done++;
            }
        }
        $summary = class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::summarize_bundle_for_ui($bundle) : null;
        self::success([
            'platform' => 'brand_audit',
            'status' => 'RUNNING',
            'run_phase' => is_array($summary) ? ($summary['run_phase'] ?? 'collecting') : 'collecting',
            'phase_label' => is_array($summary) ? ($summary['phase_label'] ?? 'Collecting evidence') : 'Collecting evidence',
            'elapsed_seconds' => is_array($summary) ? (int) ($summary['elapsed_seconds'] ?? 0) : 0,
            'bundle_id' => $bundle_id,
            'progress' => is_array($summary) ? ($summary['progress'] ?? ['done' => $done, 'total' => count((array) ($bundle['sources'] ?? []))]) : [
                'done' => $done,
                'total' => count((array) ($bundle['sources'] ?? [])),
            ],
            'sources' => is_array($summary) ? ($summary['sources'] ?? []) : (class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::sources_for_ui((array) ($bundle['sources'] ?? [])) : []),
            'request_id' => $request_id,
        ]);
    }

    public static function brand_audit_active() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'brand_audit_auth', ['stage' => 'nonce_active']);
        }
        self::require_panel_access($request_id, 'brand_audit_auth', 'brand_audit_active');
        $runs = class_exists('VES_Brand_Audit_Execution_Service')
            ? VES_Brand_Audit_Execution_Service::get_active_runs_for_current_scope(8, true, true)
            : [];
        self::success([
            'platform' => 'brand_audit',
            'active_runs' => array_values((array) $runs),
            'request_id' => $request_id,
        ]);
    }
    private static function get_brand_audit_bundle_for_ajax($bundle_id, $request_id, $source = 'brand_audit_lookup', $stage = 'bundle') {
        $bundle_id = sanitize_text_field((string) $bundle_id);
        if ($bundle_id === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $bundle_id)) {
            self::error('Identificador de Brand Audit no válido.', 400, $request_id, $source, ['stage' => $stage . '_invalid_bundle']);
        }
        $bundle = class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::get_bundle($bundle_id) : null;
        if (!$bundle || !is_array($bundle)) {
            self::error('No se ha encontrado el Brand Audit solicitado.', 404, $request_id, $source, ['stage' => $stage . '_not_found', 'bundle_id' => $bundle_id]);
        }
        if (!VES_Brand_Audit_Execution_Service::bundle_access_allowed($bundle)) {
            self::error('No autorizado para acceder a este Brand Audit.', 403, $request_id, $source, ['stage' => $stage . '_scope', 'bundle_id' => $bundle_id]);
        }
        return $bundle;
    }

    public static function brand_audit_history() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'brand_audit_auth', ['stage' => 'nonce_history']);
        }
        self::require_panel_access($request_id, 'brand_audit_auth', 'brand_audit_history');
        $limit = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['limit'] ?? 12), 1, 30) : max(1, min(30, (int) ($_POST['limit'] ?? 12)));
        $rows = class_exists('VES_Brand_Audit_Run_Store') && method_exists('VES_Brand_Audit_Run_Store', 'get_history_for_current_scope')
            ? VES_Brand_Audit_Run_Store::get_history_for_current_scope($limit, 2160)
            : [];
        $history = [];
        foreach ((array) $rows as $row) {
            $bundle_id = sanitize_text_field((string) ($row['bundle_id'] ?? ''));
            if ($bundle_id === '') { continue; }
            $bundle = class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::get_bundle($bundle_id) : null;
            if (!$bundle || !VES_Brand_Audit_Execution_Service::bundle_access_allowed($bundle)) { continue; }
            $summary = VES_Brand_Audit_Execution_Service::summarize_bundle_for_ui($bundle);
            if (!is_array($summary)) { $summary = []; }
            $summary['status'] = sanitize_key((string) ($row['run_status'] ?? $summary['status'] ?? 'unknown'));
            $summary['memory_entry_id'] = (int) ($row['memory_entry_id'] ?? 0);
            $summary['completed_at'] = sanitize_text_field((string) ($row['completed_at'] ?? ''));
            $summary['evidence_count'] = class_exists('VES_Evidence_Store') ? (int) VES_Evidence_Store::count_by_run($bundle_id) : 0;
            $intelligence = is_array($bundle['final']['intelligence_records'] ?? null) ? $bundle['final']['intelligence_records'] : [];
            $summary['intelligence_counts'] = [
                'metrics' => class_exists('VES_Metric_Snapshots') ? VES_Metric_Snapshots::count_by_run($bundle_id) : count($intelligence['metrics']['records'] ?? []),
                'patterns' => class_exists('VES_Pattern_Candidates') ? VES_Pattern_Candidates::count_by_run($bundle_id) : count($intelligence['patterns']['records'] ?? []),
                'insights' => class_exists('VES_Insight_Records') ? VES_Insight_Records::count_by_run($bundle_id) : count($intelligence['insights']['records'] ?? []),
                'opportunities' => class_exists('VES_Opportunity_Records') ? VES_Opportunity_Records::count_by_run($bundle_id) : count($intelligence['opportunities']['records'] ?? []),
                'briefs' => class_exists('VES_Brief_Records') ? count(VES_Brief_Records::list_by_run($bundle_id, 200)) : 0,
            ];
            if (!empty($bundle['final']['memory_record_id'])) {
                $summary['memory_record_id'] = (int) $bundle['final']['memory_record_id'];
            }
            $history[] = $summary;
        }
        self::success([
            'platform' => 'brand_audit',
            'history' => array_values($history),
            'request_id' => $request_id,
        ]);
    }

    public static function evidence_summary() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'evidence_auth', ['stage' => 'nonce_summary']);
        }
        self::require_panel_access($request_id, 'evidence_auth', 'evidence_summary');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'evidence_auth', 'summary');
        if (!class_exists('VES_Evidence_Store')) {
            self::error('Evidence Store no está disponible.', 500, $request_id, 'evidence_config', ['stage' => 'class_missing']);
        }
        $summary = VES_Evidence_Store::summary_by_run($bundle_id);
        self::success(['summary' => $summary, 'request_id' => $request_id]);
    }

    public static function evidence_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'evidence_auth', ['stage' => 'nonce_list']);
        }
        self::require_panel_access($request_id, 'evidence_auth', 'evidence_list');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'evidence_auth', 'list');
        if (!class_exists('VES_Evidence_Store')) {
            self::error('Evidence Store no está disponible.', 500, $request_id, 'evidence_config', ['stage' => 'class_missing_list']);
        }
        $filters = [
            'platform' => sanitize_key((string) wp_unslash($_POST['platform'] ?? '')),
            'entity_type' => sanitize_key((string) wp_unslash($_POST['entityType'] ?? $_POST['entity_type'] ?? '')),
            'entity_name' => sanitize_text_field((string) wp_unslash($_POST['entityName'] ?? $_POST['entity_name'] ?? '')),
            'source_type' => sanitize_key((string) wp_unslash($_POST['sourceType'] ?? $_POST['source_type'] ?? '')),
            'source_status' => sanitize_key((string) wp_unslash($_POST['sourceStatus'] ?? $_POST['source_status'] ?? '')),
            'search' => substr(sanitize_text_field((string) wp_unslash($_POST['search'] ?? '')), 0, 140),
            'sort' => sanitize_key((string) wp_unslash($_POST['sort'] ?? 'created_desc')),
            'page' => function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['page'] ?? 1), 1, 9999) : max(1, (int) ($_POST['page'] ?? 1)),
            'per_page' => function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['perPage'] ?? $_POST['per_page'] ?? 12), 1, 50) : max(1, min(50, (int) ($_POST['perPage'] ?? $_POST['per_page'] ?? 12))),
        ];
        $payload = VES_Evidence_Store::list_items($bundle_id, $filters);
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function evidence_get() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'evidence_auth', ['stage' => 'nonce_get']);
        }
        self::require_panel_access($request_id, 'evidence_auth', 'evidence_get');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'evidence_auth', 'get');
        $id = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['evidenceId'] ?? $_POST['id'] ?? 0), 1, PHP_INT_MAX) : max(0, (int) ($_POST['evidenceId'] ?? $_POST['id'] ?? 0));
        if (!class_exists('VES_Evidence_Store')) {
            self::error('Evidence Store no está disponible.', 500, $request_id, 'evidence_config', ['stage' => 'class_missing_get']);
        }
        $item = VES_Evidence_Store::get_item($id, $bundle_id);
        if (!$item) {
            self::error('No se ha encontrado esa evidencia.', 404, $request_id, 'evidence_lookup', ['stage' => 'item_missing']);
        }
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event')) {
            VES_Usage_Billing::record_meter_event(get_current_user_id(), 'evidence_raw_view', 'evidence', (string) $id, 'Raw evidence viewed', [
                'bundle_id' => $bundle_id,
                'evidence_ref' => $item['evidence_ref'] ?? '',
            ]);
        }
        self::success(['item' => $item, 'request_id' => $request_id]);
    }

    private static function intelligence_filters_from_post($type = '') {
        $page = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['page'] ?? 1), 1, 9999) : max(1, (int) ($_POST['page'] ?? 1));
        $per_page = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['perPage'] ?? $_POST['per_page'] ?? 20), 1, 80) : max(1, min(80, (int) ($_POST['perPage'] ?? $_POST['per_page'] ?? 20)));
        $filters = ['page' => $page, 'per_page' => $per_page];
        foreach (['metric_key', 'metricKey', 'platform', 'entity_role', 'entityRole', 'pattern_type', 'patternType', 'insight_type', 'insightType', 'opportunity_type', 'opportunityType', 'status'] as $key) {
            if (isset($_POST[$key])) { $filters[$key] = sanitize_key((string) wp_unslash($_POST[$key])); }
        }
        if (!empty($filters['metricKey'])) { $filters['metric_key'] = $filters['metricKey']; }
        if (!empty($filters['entityRole'])) { $filters['entity_role'] = $filters['entityRole']; }
        if (!empty($filters['patternType'])) { $filters['pattern_type'] = $filters['patternType']; }
        if (!empty($filters['insightType'])) { $filters['insight_type'] = $filters['insightType']; }
        if (!empty($filters['opportunityType'])) { $filters['opportunity_type'] = $filters['opportunityType']; }
        return $filters;
    }

    public static function intelligence_summary() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_summary']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'intelligence_summary');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        $bundle = self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'summary_bundle');
        $metrics = class_exists('VES_Metric_Snapshots') ? VES_Metric_Snapshots::list_by_run($bundle_id, 12) : [];
        $patterns = class_exists('VES_Pattern_Candidates') ? VES_Pattern_Candidates::list_by_run($bundle_id, 12) : [];
        $insights = class_exists('VES_Insight_Records') ? VES_Insight_Records::list_by_run($bundle_id, 20) : [];
        $opportunities = class_exists('VES_Opportunity_Records') ? VES_Opportunity_Records::list_by_run($bundle_id, 20) : [];
        $metrics = array_values(is_array($metrics) ? $metrics : []);
        $patterns = array_values(is_array($patterns) ? $patterns : []);
        $insights = array_values(is_array($insights) ? $insights : []);
        $opportunities = array_values(is_array($opportunities) ? $opportunities : []);
        $unsupported_insights = 0;
        foreach ($insights as $row) { if (!empty($row['unsupported_by_evidence']) || ($row['evidence_validation_status'] ?? '') === 'unsupported') { $unsupported_insights++; } }
        $unsupported_opportunities = 0;
        foreach ($opportunities as $row) { if (!empty($row['unsupported_by_evidence']) || ($row['evidence_validation_status'] ?? '') === 'unsupported') { $unsupported_opportunities++; } }
        $coverage = class_exists('VES_Evidence_Store') ? VES_Evidence_Store::summary_by_run($bundle_id) : [];
        self::success([
            'summary' => [
                'metric_count' => class_exists('VES_Metric_Snapshots') && method_exists('VES_Metric_Snapshots', 'count_by_run') ? VES_Metric_Snapshots::count_by_run($bundle_id) : count(class_exists('VES_Metric_Snapshots') ? VES_Metric_Snapshots::list_by_run($bundle_id, 300) : []),
                'pattern_count' => class_exists('VES_Pattern_Candidates') && method_exists('VES_Pattern_Candidates', 'count_by_run') ? VES_Pattern_Candidates::count_by_run($bundle_id) : count(class_exists('VES_Pattern_Candidates') ? VES_Pattern_Candidates::list_by_run($bundle_id, 300) : []),
                'insight_count' => class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'count_by_run') ? VES_Insight_Records::count_by_run($bundle_id) : count(class_exists('VES_Insight_Records') ? VES_Insight_Records::list_by_run($bundle_id, 300) : []),
                'opportunity_count' => class_exists('VES_Opportunity_Records') && method_exists('VES_Opportunity_Records', 'count_by_run') ? VES_Opportunity_Records::count_by_run($bundle_id) : count(class_exists('VES_Opportunity_Records') ? VES_Opportunity_Records::list_by_run($bundle_id, 300) : []),
                'unsupported_insight_count' => $unsupported_insights,
                'unsupported_opportunity_count' => $unsupported_opportunities,
                'top_metrics' => $metrics,
                'top_patterns' => $patterns,
                'top_insights' => array_slice($insights, 0, 6),
                'top_opportunities' => array_slice($opportunities, 0, 6),
                'coverage_warnings' => $coverage['coverage_warnings'] ?? [],
                'run_status' => sanitize_key((string) ($bundle['status'] ?? '')),
            ],
            'request_id' => $request_id,
        ]);
    }

    public static function metric_snapshots_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_metrics']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'metric_snapshots_list');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'metrics_bundle');
        $payload = class_exists('VES_Metric_Snapshots') && method_exists('VES_Metric_Snapshots', 'list_by_run_paginated') ? VES_Metric_Snapshots::list_by_run_paginated($bundle_id, self::intelligence_filters_from_post('metrics')) : ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0];
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function pattern_candidates_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_patterns']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'pattern_candidates_list');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'patterns_bundle');
        $payload = class_exists('VES_Pattern_Candidates') && method_exists('VES_Pattern_Candidates', 'list_by_run_paginated') ? VES_Pattern_Candidates::list_by_run_paginated($bundle_id, self::intelligence_filters_from_post('patterns')) : ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0];
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function insight_records_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_insights']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'insight_records_list');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'insights_bundle');
        $payload = class_exists('VES_Insight_Records') && method_exists('VES_Insight_Records', 'list_by_run_paginated') ? VES_Insight_Records::list_by_run_paginated($bundle_id, self::intelligence_filters_from_post('insights')) : ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0];
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function opportunity_records_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_opportunities']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'opportunity_records_list');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'opportunities_bundle');
        $payload = class_exists('VES_Opportunity_Records') && method_exists('VES_Opportunity_Records', 'list_by_run_paginated') ? VES_Opportunity_Records::list_by_run_paginated($bundle_id, self::intelligence_filters_from_post('opportunities')) : ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0];
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function insight_update_status() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_insight_status']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'insight_update_status');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'insight_status_bundle');
        $id = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['insightId'] ?? $_POST['id'] ?? 0), 1, PHP_INT_MAX) : max(0, (int) ($_POST['insightId'] ?? $_POST['id'] ?? 0));
        $status = sanitize_key((string) wp_unslash($_POST['status'] ?? ''));
        if (!in_array($status, ['draft','accepted','rejected','saved','archived'], true)) {
            self::error('Estado de insight no permitido.', 400, $request_id, 'intelligence_update', ['stage' => 'insight_status_invalid']);
        }
        if (!class_exists('VES_Insight_Records') || !method_exists('VES_Insight_Records', 'update_status')) { self::error('Insight records no está disponible.', 500, $request_id, 'intelligence_config', ['stage' => 'insight_class']); }
        $previous_record = method_exists('VES_Insight_Records', 'get') ? VES_Insight_Records::get($id) : null;
        $updated = VES_Insight_Records::update_status($id, $bundle_id, $status, get_current_user_id());
        if (is_wp_error($updated)) { self::error($updated->get_error_message(), 403, $request_id, 'intelligence_update', ['stage' => 'insight_status']); }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::record(['workspace_id' => (int) ($updated['workspace_id'] ?? 0), 'user_id' => get_current_user_id(), 'run_id' => $bundle_id, 'entity_type' => 'insight', 'entity_id' => (string) $id, 'event_type' => 'insight_status_changed', 'previous_status' => (string) ($previous_record['status'] ?? ''), 'new_status' => $status, 'note' => 'Insight status updated.']);
        }
        self::success(['record' => $updated, 'request_id' => $request_id]);
    }

    public static function opportunity_update_status() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'intelligence_auth', ['stage' => 'nonce_opportunity_status']); }
        self::require_panel_access($request_id, 'intelligence_auth', 'opportunity_update_status');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? $_POST['runId'] ?? ''));
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'intelligence_auth', 'opportunity_status_bundle');
        $id = function_exists('ves_clean_int') ? ves_clean_int(wp_unslash($_POST['opportunityId'] ?? $_POST['id'] ?? 0), 1, PHP_INT_MAX) : max(0, (int) ($_POST['opportunityId'] ?? $_POST['id'] ?? 0));
        $status = sanitize_key((string) wp_unslash($_POST['status'] ?? ''));
        if (!in_array($status, ['candidate','shortlisted','approved','rejected','converted_to_brief','archived'], true)) {
            self::error('Estado de oportunidad no permitido.', 400, $request_id, 'intelligence_update', ['stage' => 'opportunity_status_invalid']);
        }
        if (!class_exists('VES_Opportunity_Records') || !method_exists('VES_Opportunity_Records', 'update_status')) { self::error('Opportunity records no está disponible.', 500, $request_id, 'intelligence_config', ['stage' => 'opportunity_class']); }
        $previous_record = method_exists('VES_Opportunity_Records', 'get') ? VES_Opportunity_Records::get($id) : null;
        $updated = VES_Opportunity_Records::update_status($id, $bundle_id, $status, get_current_user_id());
        if (is_wp_error($updated)) { self::error($updated->get_error_message(), 403, $request_id, 'intelligence_update', ['stage' => 'opportunity_status']); }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::record(['workspace_id' => (int) ($updated['workspace_id'] ?? 0), 'user_id' => get_current_user_id(), 'run_id' => $bundle_id, 'entity_type' => 'opportunity', 'entity_id' => (string) $id, 'event_type' => 'opportunity_status_changed', 'previous_status' => (string) ($previous_record['status'] ?? ''), 'new_status' => $status, 'note' => 'Opportunity status updated.']);
        }
        self::success(['record' => $updated, 'request_id' => $request_id]);
    }

    public static function schema_health_check() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'schema_auth', ['stage' => 'nonce']); }
        if (!current_user_can('manage_options')) { self::error('Solo administradores pueden revisar el esquema.', 403, $request_id, 'schema_auth', ['stage' => 'capability']); }
        $repair = self::post_bool('repair', false);
        $health = $repair && class_exists('VES_Brand_Audit_Backfill_Service') && method_exists('VES_Brand_Audit_Backfill_Service', 'repair_schema')
            ? VES_Brand_Audit_Backfill_Service::repair_schema()
            : (class_exists('VES_Brand_Audit_Backfill_Service') ? VES_Brand_Audit_Backfill_Service::schema_health() : []);
        self::success(['health' => $health, 'repair' => $repair, 'request_id' => $request_id]);
    }

    public static function schema_repair() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'schema_auth', ['stage' => 'nonce_repair']); }
        if (!current_user_can('manage_options')) { self::error('Solo administradores pueden reparar el esquema.', 403, $request_id, 'schema_auth', ['stage' => 'capability_repair']); }
        if (!class_exists('VES_Brand_Audit_Backfill_Service') || !method_exists('VES_Brand_Audit_Backfill_Service', 'repair_schema')) { self::error('Schema repair service unavailable.', 500, $request_id, 'schema_config', ['stage' => 'missing']); }
        $health = VES_Brand_Audit_Backfill_Service::repair_schema();
        self::success(['health' => $health, 'request_id' => $request_id]);
    }

    public static function evidence_refs_count() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'evidence_admin_auth', ['stage' => 'nonce_count']); }
        if (!current_user_can('manage_options')) { self::error('Solo administradores pueden revisar evidencia global.', 403, $request_id, 'evidence_admin_auth', ['stage' => 'capability_count']); }
        if (!class_exists('VES_Evidence_Store')) { self::error('Evidence Store no está disponible.', 500, $request_id, 'evidence_admin_config', ['stage' => 'missing']); }
        $run_id = self::post_bundle_id();
        $count = method_exists('VES_Evidence_Store', 'count_missing_or_invalid_evidence_refs') ? VES_Evidence_Store::count_missing_or_invalid_evidence_refs($run_id) : 0;
        self::success(['missing_or_invalid' => $count, 'run_id' => $run_id, 'request_id' => $request_id]);
    }

    public static function evidence_refs_repair() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'evidence_admin_auth', ['stage' => 'nonce_repair']); }
        if (!current_user_can('manage_options')) { self::error('Solo administradores pueden reparar evidencia global.', 403, $request_id, 'evidence_admin_auth', ['stage' => 'capability_repair']); }
        if (!class_exists('VES_Evidence_Store')) { self::error('Evidence Store no está disponible.', 500, $request_id, 'evidence_admin_config', ['stage' => 'missing']); }
        $run_id = self::post_bundle_id();
        $limit = self::post_int('limit', 500, 1, 2000);
        $result = $run_id !== '' && method_exists('VES_Evidence_Store', 'repair_evidence_refs_for_run')
            ? VES_Evidence_Store::repair_evidence_refs_for_run($run_id)
            : (method_exists('VES_Evidence_Store', 'repair_missing_evidence_refs') ? VES_Evidence_Store::repair_missing_evidence_refs($limit) : []);
        self::success(['result' => $result, 'run_id' => $run_id, 'request_id' => $request_id]);
    }

    public static function brand_audit_backfill_intelligence() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'backfill_auth', ['stage' => 'nonce']); }
        if (!current_user_can('manage_options')) { self::error('Solo administradores pueden ejecutar backfill.', 403, $request_id, 'backfill_auth', ['stage' => 'capability']); }
        if (!class_exists('VES_Brand_Audit_Backfill_Service')) { self::error('Backfill service no está disponible.', 500, $request_id, 'backfill_config', ['stage' => 'class_missing']); }
        $mode = self::post_enum('mode', ['single','recent'], 'single');
        $bundle_id = self::post_bundle_id();
        $limit = self::post_int('limit', 5, 1, 25);
        $force_unlock = self::post_bool('force_unlock', false, ['forceUnlock']);
        if ($force_unlock) {
            $scope = $mode === 'recent' ? 'recent' : 'run_' . md5($bundle_id);
            VES_Brand_Audit_Backfill_Service::force_unlock($scope);
        }
        $options = [
            'dry_run' => self::post_bool('dry_run', false, ['dryRun']),
            'rebuild' => self::post_bool('rebuild', false),
            'force_unlock' => $force_unlock,
            'operation_id' => self::post_text('operation_id', '', 80, ['operationId']),
        ];
        $result = $mode === 'recent'
            ? VES_Brand_Audit_Backfill_Service::backfill_recent($limit, $options)
            : VES_Brand_Audit_Backfill_Service::backfill_single($bundle_id, $options);
        if (is_wp_error($result)) { self::error($result->get_error_message(), 400, $request_id, 'backfill_failed', ['stage' => 'run']); }
        self::success(['result' => $result, 'request_id' => $request_id]);
    }

    public static function usage_estimate() {
        $request_id = self::request_id();
        self::register_ajax_shutdown_handler($request_id, 'ves_usage_estimate');
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'usage_auth', ['stage' => 'nonce_estimate']); }
        self::require_panel_access($request_id, 'usage_auth', 'usage_estimate');
        if (!class_exists('VES_Usage_Billing')) { self::error('Billing no está disponible.', 500, $request_id, 'usage_config', ['stage' => 'class_missing']); }
        // v0.9.24.64: added manual_run so the standard scraper form can show the
        // per-run credit cost before the user executes the run.
        $action_type = self::post_enum('action_type', ['brand_audit','assistant_question','brief_generation','manual_run','trend_run'], 'assistant_question', ['actionType']);
        $bundle_id = self::post_bundle_id();
        if ($bundle_id !== '') { self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'usage_auth', 'estimate_bundle'); }
        if ($action_type === 'manual_run') {
            $run_cost = class_exists('VES_Config') ? VES_Config::cost_manual_run() : 1.0;
            $ai_cost  = class_exists('VES_Config') ? VES_Config::cost_ai_analysis() : 1.0;
            $balance  = is_user_logged_in() && class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_balance(get_current_user_id()) : null;
            $estimate = [
                'estimated_credits' => $run_cost,
                'breakdown' => [
                    ['event_type' => 'manual_run', 'label' => 'Scraping run', 'credits' => $run_cost, 'optional' => false],
                    ['event_type' => 'ai_analysis', 'label' => 'AI analysis (per item, optional)', 'credits' => $ai_cost, 'optional' => true],
                ],
                'current_balance' => $balance,
                'can_run' => $balance === null || $balance >= $run_cost,
                'notes' => ['Cost covers one scraping run. AI item analysis is optional and charged separately.'],
            ];
        } elseif ($action_type === 'trend_run') {
            $trend_request = function_exists('ves_trend_sanitize_request') ? ves_trend_sanitize_request($_POST) : [];
            $built = class_exists('VES_Trend_Query_Planner') ? VES_Trend_Query_Planner::build($trend_request) : ['payloads' => [], 'query_plan' => []];
            $provider_cost = class_exists('VES_Config') ? VES_Config::estimate_trend_provider_cost((array) ($built['payloads'] ?? [])) : [];
            $desired = self::post_int('desiredFinalTrends', 5, 1, 100, ['desired_final_trends']);
            $base = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_cost('trend_run') : 4.0;
            $per_delivered = (float) apply_filters('ves_trend_estimate_credit_per_delivered_result', 0.5, $trend_request, $provider_cost);
            $estimated_credits = round($base + ($desired * $per_delivered), 2);
            $balance = is_user_logged_in() && class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_balance(get_current_user_id()) : null;
            $estimate = [
                'estimated_credits' => $estimated_credits,
                'breakdown' => [
                    ['event_type' => 'trend_run', 'label' => 'Base scan fee', 'credits' => $base, 'optional' => false],
                    ['event_type' => 'delivered_trends', 'label' => 'Estimated delivered trends', 'credits' => round($desired * $per_delivered, 2), 'optional' => false],
                    ['event_type' => 'ai_analysis', 'label' => 'AI interpretation/reporting', 'credits' => class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_cost('ai_analysis') : 1.0, 'optional' => true],
                ],
                'current_balance' => $balance,
                'can_run' => $balance === null || $balance >= $estimated_credits,
                'provider_cost' => $provider_cost,
                'query_plan' => $built['query_plan'] ?? [],
                'notes' => ['Estimate is preflight only. Final charge should settle down to delivered usable trends plus the base scan fee. If filters produce zero useful trends, only a minimal/base component should remain.'],
                'approximate' => true,
            ];
        } elseif ($action_type === 'brand_audit') {
            $estimate_request = [
                'audit_depth' => self::post_enum('audit_depth', ['light','standard','deep'], 'standard', ['depth','scope']),
                'country' => self::post_text('country', '', 120),
                'platforms' => self::post_text('platforms', '', 500),
                'competitor_count' => self::post_int('competitorCount', 0, 0, 20, ['competitor_count']),
                'quality_mode' => self::post_key('qualityMode', 'standard', ['quality_mode']),
                'limit' => self::post_int('limit', 25, 1, 500),
            ];
            $estimate = VES_Usage_Billing::estimate_brand_audit_cost($estimate_request);
            if (class_exists('VES_Brand_Audit') && class_exists('VES_Brand_Audit_Query_Planner')) {
                $preview_request = VES_Brand_Audit::sanitize_request($_POST);
                $planned_preview = VES_Brand_Audit_Query_Planner::build($preview_request);
                if (is_array($planned_preview['source_preview'] ?? null)) {
                    $estimate['source_preview'] = $planned_preview['source_preview'];
                    $estimate['provider_cost'] = [
                        'estimated_usd' => (float) ($planned_preview['source_preview']['estimated_external_provider_cost'] ?? 0),
                        'source_count' => (int) ($planned_preview['source_preview']['source_count'] ?? 0),
                        'heavy_source_count' => (int) ($planned_preview['source_preview']['heavy_source_count'] ?? 0),
                        'warning' => !empty($planned_preview['source_preview']['warning']),
                        'warning_message' => (string) ($planned_preview['source_preview']['warning'] ?? ''),
                    ];
                }
            }
        } elseif ($action_type === 'brief_generation') {
            $estimate = VES_Usage_Billing::estimate_brief_generation_cost(self::post_enum('briefType', ['campaign','content','social','search','creative'], 'campaign', ['brief_type']), ['bundle_id' => $bundle_id, 'with_ai' => true]);
        } else {
            $estimate = VES_Usage_Billing::estimate_assistant_question_cost([
                'has_evidence' => self::post_int('evidenceId', 0, 0, PHP_INT_MAX) > 0,
                'has_memory' => self::post_bool('hasMemory', $bundle_id !== ''),
                'has_brief' => self::post_int('briefId', 0, 0, PHP_INT_MAX, ['brief_id']) > 0,
            ]);
        }
        self::success(['estimate' => $estimate, 'request_id' => $request_id]);
    }

    public static function generate_brief_from_opportunity() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'brief_auth', ['stage' => 'nonce_generate']); }
        self::require_panel_access($request_id, 'brief_auth', 'generate_brief');
        $user_id = (int) get_current_user_id();
        $bundle_id = self::post_bundle_id();
        $bundle = self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'brief_auth', 'brief_bundle');
        $opportunity_id = self::post_int('opportunityId', 0, 1, PHP_INT_MAX, ['opportunity_id']);
        $brief_type = self::post_enum('briefType', ['campaign','content','social','search','creative'], 'campaign', ['brief_type']);
        $output_language = self::post_key('outputLanguage', '', ['output_language']);
        $force_new = self::post_bool('force_new', false, ['forceNew']) && current_user_can('manage_options');
        if (!class_exists('VES_Brief_Records')) { self::error('Brief records no está disponible.', 500, $request_id, 'brief_config', ['stage' => 'class_missing']); }
        if (!class_exists('VES_Opportunity_Records')) { self::error('Opportunity records no está disponible.', 500, $request_id, 'brief_config', ['stage' => 'opportunity_missing']); }
        $opportunity = VES_Opportunity_Records::get($opportunity_id);
        if (!$opportunity || (string) ($opportunity['run_id'] ?? '') !== $bundle_id) { self::error('Oportunidad no encontrada para este run.', 404, $request_id, 'brief_lookup', ['stage' => 'opportunity']); }
        if (!VES_Opportunity_Records::user_can_access_record($opportunity, $user_id)) { self::error('No autorizado para generar brief con esta oportunidad.', 403, $request_id, 'brief_auth', ['stage' => 'opportunity_scope']); }
        $status = sanitize_key((string) ($opportunity['status'] ?? 'candidate'));
        if (!in_array($status, ['shortlisted','approved'], true) && !current_user_can('manage_options')) { self::error('Primero shortlist o aprueba esta oportunidad para generar brief.', 400, $request_id, 'brief_validation', ['stage' => 'opportunity_status']); }
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        self::require_project_access_from_request($request_id, 'brief_auth', 'project_scope_generate', $request);
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($request, ['user_id' => $user_id]), $user_id) : $user_id;
        $memory_args = ['workspace_id' => $workspace_id, 'user_id' => $user_id, 'brand_name' => $request['brand_name'] ?? '', 'context_type' => 'brief_generation', 'research_goal' => $request['audit_goal'] ?? ''];
        $memory_pack = class_exists('VES_Memory_Records') ? VES_Memory_Records::build_prompt_pack($memory_args, 6) : [];
        $project_context = ($user_id > 0 && class_exists('VES_Project_Context') && method_exists('VES_Project_Context', 'build_ai_context_block')) ? VES_Project_Context::build_ai_context_block($user_id, $memory_args) : null;
        $brief = VES_Brief_Records::create_from_opportunity($opportunity_id, $bundle_id, $workspace_id, $user_id, ['brief_type' => $brief_type, 'output_language' => $output_language, 'brand_name' => $request['brand_name'] ?? '', 'memory_pack' => $memory_pack, 'project_context' => $project_context, 'force_new' => $force_new, 'request_id' => $request_id]);
        if (is_wp_error($brief)) { self::error($brief->get_error_message(), 400, $request_id, 'brief_failed', ['stage' => 'generate']); }
        self::success(['brief' => $brief, 'existing' => in_array((string) ($brief['idempotency_status'] ?? ''), ['existing_returned','existing_returned_after_lock'], true), 'request_id' => $request_id]);
    }

    public static function brief_records_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'brief_auth', ['stage' => 'nonce_list']); }
        self::require_panel_access($request_id, 'brief_auth', 'brief_list');
        $bundle_id = self::post_bundle_id();
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'brief_auth', 'brief_list_bundle');
        if (!class_exists('VES_Brief_Records')) { self::error('Brief records no está disponible.', 500, $request_id, 'brief_config', ['stage' => 'class_missing_list']); }
        $filters = array_merge(self::post_page_args(20, 80), [
            'status' => self::post_enum('status', VES_Brief_Records::allowed_statuses(), ''),
            'opportunity_id' => self::post_int('opportunityId', 0, 0, PHP_INT_MAX, ['opportunity_id']),
        ]);
        $payload = VES_Brief_Records::list_by_run_paginated($bundle_id, $filters);
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function brief_library_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'brief_library_auth', ['stage' => 'nonce_list']); }
        self::require_panel_access($request_id, 'brief_library_auth', 'brief_library_list');
        if (!class_exists('VES_Brief_Records')) { self::error('Brief records no está disponible.', 500, $request_id, 'brief_library_config', ['stage' => 'class_missing']); }
        $user_id = (int) get_current_user_id();
        $workspace_id = current_user_can('manage_options') ? self::post_int('workspace_id', $user_id, 0, PHP_INT_MAX, ['workspaceId']) : $user_id;
        $filters = array_merge(self::post_page_args(20, 80), [
            'status' => self::post_enum('status', VES_Brief_Records::allowed_statuses(), ''),
            'brief_type' => self::post_enum('briefType', VES_Brief_Records::allowed_types(), '', ['brief_type']),
            'workspace_id' => $workspace_id,
        ]);
        $payload = VES_Brief_Records::list_library($workspace_id, $user_id, $filters);
        $payload['request_id'] = $request_id;
        self::success($payload);
    }

    public static function brief_update_status() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'brief_auth', ['stage' => 'nonce_status']); }
        self::require_panel_access($request_id, 'brief_auth', 'brief_update_status');
        $bundle_id = self::post_bundle_id();
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'brief_auth', 'brief_status_bundle');
        $id = self::post_int('briefId', 0, 1, PHP_INT_MAX, ['brief_id','id']);
        if (!class_exists('VES_Brief_Records')) { self::error('Brief records no está disponible.', 500, $request_id, 'brief_config', ['stage' => 'class_missing_status']); }
        $status = self::post_enum('status', VES_Brief_Records::allowed_statuses(), '');
        if ($status === '') { self::error('Estado de brief no permitido.', 400, $request_id, 'brief_validation', ['stage' => 'status']); }
        $updated = VES_Brief_Records::update_status($id, $bundle_id, $status, get_current_user_id());
        if (is_wp_error($updated)) { self::error($updated->get_error_message(), 403, $request_id, 'brief_update', ['stage' => 'status']); }
        self::success(['record' => $updated, 'request_id' => $request_id]);
    }

    public static function workflow_events_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'workflow_auth', ['stage' => 'nonce_list']); }
        self::require_panel_access($request_id, 'workflow_auth', 'workflow_events_list');
        $bundle_id = self::post_bundle_id();
        self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'workflow_auth', 'workflow_bundle');
        $limit = self::post_int('limit', 30, 1, 100);
        $filters = [
            'entity_type' => self::post_key('entity_type', '', ['entityType']),
            'event_type' => self::post_key('event_type', '', ['eventType']),
            'since' => self::post_text('since', '', 40),
        ];
        $events = class_exists('VES_Workflow_Events') ? VES_Workflow_Events::list_by_run($bundle_id, $limit, $filters) : [];
        $events = array_values(is_array($events) ? $events : []);
        self::success(['events' => $events, 'request_id' => $request_id]);
    }

    public static function assistant_thread_messages() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) { self::error('Sesión caducada.', 403, $request_id, 'assistant_auth', ['stage' => 'nonce_thread_messages']); }
        self::require_panel_access($request_id, 'assistant_auth', 'assistant_thread_messages');
        $thread_id = self::post_int('threadId', 0, 1, PHP_INT_MAX, ['thread_id']);
        $bundle_id = self::post_bundle_id();
        $context_type = self::post_key('contextType', 'brand_audit', ['context_type']);
        $bundle = null;
        $workspace_id = 0;
        if ($bundle_id !== '') {
            $bundle = self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'assistant_auth', 'assistant_thread_bundle');
            $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
            $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($request, ['user_id' => get_current_user_id()]), get_current_user_id()) : get_current_user_id();
        }
        if (!class_exists('VES_Assistant_Threads') || !method_exists('VES_Assistant_Threads', 'user_can_access_thread')) { self::error('Assistant thread store no está disponible.', 500, $request_id, 'assistant_config', ['stage' => 'threads_missing']); }
        $can_access = method_exists('VES_Assistant_Threads', 'user_can_access_thread_context') && $bundle_id !== ''
            ? VES_Assistant_Threads::user_can_access_thread_context($thread_id, get_current_user_id(), $context_type, $bundle_id, $workspace_id)
            : VES_Assistant_Threads::user_can_access_thread($thread_id, get_current_user_id());
        if (!$can_access) { self::error('No autorizado para ver este hilo o el hilo no pertenece a este contexto.', 403, $request_id, 'assistant_auth', ['stage' => 'thread_scope']); }
        $messages = method_exists('VES_Assistant_Threads', 'get_recent_messages') ? VES_Assistant_Threads::get_recent_messages($thread_id, 12) : [];
        $messages = array_values(array_filter(array_map(static function($message) {
            $role = sanitize_key((string) ($message['role'] ?? ''));
            if (!in_array($role, ['user','assistant'], true)) { return null; }
            return [
                'id' => (int) ($message['id'] ?? 0),
                'thread_id' => (int) ($message['thread_id'] ?? 0),
                'role' => $role,
                'message' => sanitize_textarea_field((string) ($message['message'] ?? '')),
                'created_at' => sanitize_text_field((string) ($message['created_at'] ?? '')),
            ];
        }, (array) $messages)));
        self::success(['thread_id' => $thread_id, 'messages' => $messages, 'request_id' => $request_id]);
    }

    private static function truncate_for_title($value, $max = 80) {
        $value = trim(wp_strip_all_tags((string) $value));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max - 1) . '…' : $value;
    }

    public static function assistant_ask() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'assistant_auth', ['stage' => 'nonce']);
        }
        self::require_panel_access($request_id, 'assistant_auth', 'assistant_ask');
        $user_id = (int) get_current_user_id();
        $rate_key = 'ves_assistant_ask_' . $user_id;
        $hits = (int) get_transient($rate_key);
        if ($hits >= 12) {
            self::error('Demasiadas preguntas seguidas. Espera un minuto.', 429, $request_id, 'assistant_rate_limit', ['stage' => 'minute_limit']);
        }
        set_transient($rate_key, $hits + 1, 60);

        $question = self::post_textarea('question', '', 1600);
        if (trim($question) === '') {
            $assistant_label = class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant';
            self::error(sprintf(__('Escribe una pregunta para %s.', 'vietnam-estudio-social-scraper'), $assistant_label), 400, $request_id, 'assistant_validation', ['stage' => 'empty_question']);
        }
        if (strlen($question) > 1600) { $question = substr($question, 0, 1600); }
        $bundle_id = self::post_bundle_id();
        $context_type = self::post_key('contextType', 'brand_audit', ['context_type']);
        $requested_thread_id = self::post_int('threadId', 0, 0, PHP_INT_MAX, ['thread_id']);
        $evidence_id = self::post_int('evidenceId', 0, 0, PHP_INT_MAX);
        $insight_id = self::post_text('insightId', '', 120, ['insight_id']);
        $opportunity_id = self::post_text('opportunityId', '', 120, ['opportunity_id']);
        $brief_id = self::post_int('briefId', 0, 0, PHP_INT_MAX, ['brief_id']);
        $bundle = $bundle_id !== '' ? self::get_brand_audit_bundle_for_ajax($bundle_id, $request_id, 'assistant_auth', 'assistant_bundle') : null;
        if ($bundle_id === '' && $evidence_id > 0) {
            self::error('Selecciona un Brand Audit válido antes de consultar evidencia concreta.', 400, $request_id, 'assistant_validation', ['stage' => 'evidence_requires_run']);
        }
        $request = is_array($bundle['request'] ?? null) ? $bundle['request'] : [];
        self::require_project_access_from_request($request_id, 'assistant_auth', 'project_scope_ask', $request);
        $final = is_array($bundle['final'] ?? null) ? $bundle['final'] : [];
        $structured = is_array($final['analysis_structured'] ?? null) ? $final['analysis_structured'] : [];
        $brand_name = self::post_text('brandName', (string) ($request['brand_name'] ?? ($final['requested']['brand_name'] ?? '')), 180, ['brand_name']);
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(array_merge($request, ['user_id' => $user_id]), $user_id) : $user_id;
        $evidence_summary = ($bundle_id !== '' && class_exists('VES_Evidence_Store')) ? VES_Evidence_Store::summary_by_run($bundle_id) : [];
        $selected_evidence = ($evidence_id > 0 && class_exists('VES_Evidence_Store')) ? VES_Evidence_Store::get_item($evidence_id, $bundle_id) : null;
        if (is_array($selected_evidence) && isset($selected_evidence['raw_payload'])) { unset($selected_evidence['raw_payload']); }
        $selected_context = array_filter(['evidence_id' => $evidence_id, 'evidence' => $selected_evidence]);
        if (empty($selected_context) && $insight_id !== '' && class_exists('VES_Insight_Records')) {
            $insight_record_id = preg_match('/^(?:ins_)?(\d+)$/', $insight_id, $m) ? (int) $m[1] : 0;
            if ($insight_record_id > 0) {
                $record = VES_Insight_Records::get($insight_record_id);
                if ($record && (!method_exists('VES_Insight_Records', 'user_can_access_record') || VES_Insight_Records::user_can_access_record($record, $user_id)) && ($bundle_id === '' || (string) ($record['run_id'] ?? '') === $bundle_id)) {
                    $selected_context = ['type' => 'insight_record', 'insight_id' => $insight_id, 'item' => $record];
                }
            }
        }
        if (empty($selected_context) && $insight_id !== '' && is_array($structured['insights'] ?? null)) {
            foreach ((array) $structured['insights'] as $idx => $candidate) {
                if (!is_array($candidate)) { continue; }
                $candidate_id = (string) ($candidate['id'] ?? $candidate['title'] ?? $idx);
                if ($candidate_id === $insight_id || (string) $idx === $insight_id) {
                    $selected_context = ['type' => 'insight', 'insight_id' => $insight_id, 'item' => $candidate];
                    break;
                }
            }
        }
        $opportunity_source = [];
        if (!empty($structured['opportunities']) && is_array($structured['opportunities'])) { $opportunity_source = $structured['opportunities']; }
        elseif (!empty($structured['opportunity_territories']) && is_array($structured['opportunity_territories'])) { $opportunity_source = $structured['opportunity_territories']; }
        if (empty($selected_context) && $opportunity_id !== '' && class_exists('VES_Opportunity_Records')) {
            $opportunity_record_id = preg_match('/^(?:opp_)?(\d+)$/', $opportunity_id, $m) ? (int) $m[1] : 0;
            if ($opportunity_record_id > 0) {
                $record = VES_Opportunity_Records::get($opportunity_record_id);
                if ($record && (!method_exists('VES_Opportunity_Records', 'user_can_access_record') || VES_Opportunity_Records::user_can_access_record($record, $user_id)) && ($bundle_id === '' || (string) ($record['run_id'] ?? '') === $bundle_id)) {
                    $selected_context = ['type' => 'opportunity_record', 'opportunity_id' => $opportunity_id, 'item' => $record];
                }
            }
        }
        if (empty($selected_context) && $opportunity_id !== '' && !empty($opportunity_source)) {
            foreach ((array) $opportunity_source as $idx => $candidate) {
                if (!is_array($candidate)) { continue; }
                $candidate_id = (string) ($candidate['id'] ?? $candidate['title'] ?? $candidate['name'] ?? $idx);
                if ($candidate_id === $opportunity_id || (string) $idx === $opportunity_id) {
                    $selected_context = ['type' => 'opportunity', 'opportunity_id' => $opportunity_id, 'item' => $candidate];
                    break;
                }
            }
        }
        if (empty($selected_context) && $brief_id > 0 && class_exists('VES_Brief_Records')) {
            $brief_record = VES_Brief_Records::get($brief_id);
            if ($brief_record && (!method_exists('VES_Brief_Records', 'user_can_access_record') || VES_Brief_Records::user_can_access_record($brief_record, $user_id)) && ($bundle_id === '' || (string) ($brief_record['run_id'] ?? '') === $bundle_id)) {
                $selected_context = ['type' => 'brief_record', 'brief_id' => $brief_id, 'item' => [
                    'id' => (int) ($brief_record['id'] ?? 0),
                    'title' => (string) ($brief_record['title'] ?? ''),
                    'brief_type' => (string) ($brief_record['brief_type'] ?? ''),
                    'status' => (string) ($brief_record['status'] ?? ''),
                    'created_by' => (string) ($brief_record['created_by'] ?? ''),
                    'objective' => (string) ($brief_record['objective'] ?? ''),
                    'key_insight' => (string) ($brief_record['key_insight'] ?? ''),
                    'proposition' => (string) ($brief_record['proposition'] ?? ''),
                    'message_angles' => array_slice((array) ($brief_record['message_angles'] ?? []), 0, 8),
                    'hooks' => array_slice((array) ($brief_record['hooks'] ?? []), 0, 8),
                    'next_steps' => array_slice((array) ($brief_record['next_steps'] ?? []), 0, 8),
                    'evidence_refs' => array_slice((array) ($brief_record['evidence_refs'] ?? []), 0, 12),
                    'evidence_validation_status' => (string) ($brief_record['evidence_validation_status'] ?? ''),
                ]];
            }
        }
        $memory_args = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'brand_name' => $brand_name,
            'competitors' => $request['competitors'] ?? [],
            'country' => $request['country'] ?? '',
            'industry' => $request['industry'] ?? '',
            'research_goal' => $request['audit_goal'] ?? '',
            'context_type' => $context_type,
        ];
        $memory_pack = class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'build_prompt_pack') ? VES_Memory_Records::build_prompt_pack($memory_args, 8) : [];
        $project_context = ($user_id > 0 && class_exists('VES_Project_Context') && method_exists('VES_Project_Context', 'build_ai_context_block')) ? VES_Project_Context::build_ai_context_block($user_id, $memory_args) : null;
        $current_run_summary = is_array($bundle) && class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::summarize_bundle_for_ui($bundle) : [];
        $insight_records_for_context = ($bundle_id !== '' && class_exists('VES_Insight_Records')) ? VES_Insight_Records::prompt_pack($bundle_id, 8) : [];
        $opportunity_records_for_context = ($bundle_id !== '' && class_exists('VES_Opportunity_Records')) ? VES_Opportunity_Records::prompt_pack($bundle_id, 8) : [];
        $context_pack = [
            'workspace_id' => $workspace_id,
            'workspace_name' => class_exists('VES_Branding') ? VES_Branding::workspace_name() : 'Intelligence Workspace',
            'brand_name' => $brand_name,
            'country' => $request['country'] ?? '',
            'industry' => $request['industry'] ?? '',
            'user_goal' => $request['audit_goal'] ?? '',
            'context_type' => $context_type,
            'selected_context' => $selected_context,
            'selected_evidence_id' => $evidence_id,
            'selected_insight_id' => $insight_id,
            'selected_opportunity_id' => $opportunity_id,
            'selected_brief_id' => $brief_id,
            'current_run_summary' => $current_run_summary,
            'evidence_summary' => $evidence_summary,
            'relevant_evidence' => $selected_evidence ? [$selected_evidence] : [],
            'relevant_insights' => !empty($insight_records_for_context) ? $insight_records_for_context : array_slice((array) ($structured['insights'] ?? []), 0, 6),
            'relevant_opportunities' => !empty($opportunity_records_for_context) ? $opportunity_records_for_context : array_slice((array) (($structured['opportunities'] ?? []) ?: ($structured['opportunity_territories'] ?? [])), 0, 6),
            'known_limitations' => array_slice((array) ($structured['limitations'] ?? []), 0, 8),
            'memory_records' => $memory_pack['records'] ?? [],
        ];
        if (!class_exists('VES_OpenAI_Client') || !method_exists('VES_OpenAI_Client', 'answer_future_island_assistant')) {
            $assistant_label = class_exists('VES_Branding') ? VES_Branding::assistant_name() : 'AI Assistant';
            self::error(sprintf(__('%s no está disponible.', 'vietnam-estudio-social-scraper'), $assistant_label), 500, $request_id, 'assistant_config', ['stage' => 'client_missing']);
        }
        $thread_id = 0;
        if (class_exists('VES_Assistant_Threads')) {
            if ($requested_thread_id > 0) {
                $thread_ok = method_exists('VES_Assistant_Threads', 'user_can_access_thread_context')
                    ? VES_Assistant_Threads::user_can_access_thread_context($requested_thread_id, $user_id, $context_type, $bundle_id, $workspace_id)
                    : VES_Assistant_Threads::user_can_access_thread($requested_thread_id, $user_id);
                if (!$thread_ok) { self::error('El hilo no pertenece a este Brand Audit o no tienes acceso.', 403, $request_id, 'assistant_auth', ['stage' => 'thread_context']); }
                $thread_id = $requested_thread_id;
            } else {
                $thread_id = VES_Assistant_Threads::get_or_create_thread([
                    'workspace_id' => $workspace_id,
                    'user_id' => $user_id,
                    'brand_name' => $brand_name,
                    'context_type' => $context_type,
                    'context_id' => $bundle_id,
                    'title' => self::truncate_for_title($question, 120),
                ]);
            }
        }
        $recent_conversation = [];
        if ($thread_id > 0 && class_exists('VES_Assistant_Threads') && method_exists('VES_Assistant_Threads', 'get_recent_messages')) {
            $recent_conversation = VES_Assistant_Threads::get_recent_messages($thread_id, 8);
            $context_pack['recent_conversation'] = array_map(static function($message) {
                return [
                    'role' => sanitize_key((string) ($message['role'] ?? '')),
                    'message' => sanitize_textarea_field((string) ($message['message'] ?? '')),
                    'created_at' => sanitize_text_field((string) ($message['created_at'] ?? '')),
                ];
            }, (array) $recent_conversation);
        }
        if ($thread_id > 0 && class_exists('VES_Assistant_Threads')) {
            VES_Assistant_Threads::add_message($thread_id, 'user', $question, [], ['bundle_id' => $bundle_id, 'context_type' => $context_type, 'evidence_id' => $evidence_id, 'insight_id' => $insight_id, 'opportunity_id' => $opportunity_id, 'brief_id' => $brief_id]);
        }
        $usage_key = 'assistant:' . $user_id . ':' . $request_id;
        $usage_reserved = null;
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'reserve_usage')) {
            $usage_reserved = VES_Usage_Billing::reserve_usage($user_id, 'ai_analysis', $usage_key, 'Assistant answer', [
                'target_type' => 'assistant',
                'target_id' => $thread_id > 0 ? (string) $thread_id : $bundle_id,
                'bundle_id' => $bundle_id,
                'context_type' => $context_type,
                'has_evidence' => $selected_evidence ? 1 : 0,
                'has_brief' => $brief_id > 0 ? 1 : 0,
                'memory_records' => is_array($memory_pack['records'] ?? null) ? count($memory_pack['records']) : 0,
            ]);
            if (is_wp_error($usage_reserved)) {
                self::error($usage_reserved->get_error_message(), 402, $request_id, 'assistant_billing', ['stage' => 'reserve']);
            }
        }
        $result = VES_OpenAI_Client::answer_future_island_assistant($question, $context_pack, $project_context, $memory_pack);
        if (is_wp_error($result)) {
            if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'void_reserved_usage')) {
                VES_Usage_Billing::void_reserved_usage($usage_key, 'Assistant OpenAI error');
            }
            self::error($result->get_error_message(), 500, $request_id, 'assistant_openai', ['stage' => 'answer']);
        }
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'post_reserved_usage')) {
            VES_Usage_Billing::post_reserved_usage($usage_key, 'Assistant answer confirmed');
        }
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'record_meter_event')) {
            $meter_type = $selected_evidence ? 'assistant_answer_with_evidence' : (!empty($memory_pack['records']) ? 'assistant_answer_with_memory' : 'assistant_answer_light');
            VES_Usage_Billing::record_meter_event($user_id, $meter_type, 'assistant', $thread_id > 0 ? (string) $thread_id : $bundle_id, 'Assistant answer metered', [
                'bundle_id' => $bundle_id,
                'context_type' => $context_type,
                'memory_records' => is_array($memory_pack['records'] ?? null) ? count($memory_pack['records']) : 0,
            ]);
        }
        $answer = is_array($result['answer'] ?? null) ? $result['answer'] : [];
        if ($thread_id > 0 && class_exists('VES_Assistant_Threads')) {
            VES_Assistant_Threads::add_message($thread_id, 'assistant', (string) ($answer['direct_answer'] ?? ''), $answer, ['bundle_id' => $bundle_id, 'model' => $result['model'] ?? '']);
        }
        if (class_exists('VES_Memory_Records') && preg_match('/\b(strategy|decision|decide|recommend|next step|plan|campaign|brief|estrategia|decisi[oó]n|recomienda|plan)\b/i', $question)) {
            $memory_id = VES_Memory_Records::save_record([
                'workspace_id' => $workspace_id,
                'user_id' => $user_id,
                'brand_name' => $brand_name,
                'memory_type' => 'assistant_decision',
                'title' => 'Assistant decision - ' . self::truncate_for_title($question, 80),
                'summary' => (string) ($answer['recommended_next_step'] ?? $answer['direct_answer'] ?? ''),
                'content' => ['question' => $question, 'answer' => $answer, 'context_type' => $context_type, 'bundle_id' => $bundle_id],
                'source_type' => 'assistant',
                'source_id' => $thread_id > 0 ? (string) $thread_id : $bundle_id,
                'tags' => array_filter(['assistant', 'decision', 'brand_audit', $brand_name]),
                'importance_score' => 65,
                'dedupe' => true,
            ]);
            if (class_exists('VES_Workflow_Events')) {
                VES_Workflow_Events::record(['workspace_id' => $workspace_id, 'user_id' => $user_id, 'run_id' => $bundle_id, 'entity_type' => 'assistant', 'entity_id' => $thread_id > 0 ? (string) $thread_id : $bundle_id, 'event_type' => 'assistant_decision_saved', 'new_status' => 'saved', 'note' => 'Assistant decision saved to memory.', 'meta' => ['memory_id' => is_wp_error($memory_id) ? 0 : (int) $memory_id]]);
            }
        }
        self::success([
            'answer' => $answer,
            'thread_id' => $thread_id,
            'model' => sanitize_text_field((string) ($result['model'] ?? '')),
            'format' => sanitize_text_field((string) ($result['format'] ?? '')),
            'memory_records_used' => is_array($memory_pack['records'] ?? null) ? count($memory_pack['records']) : 0,
            'evidence_summary' => $evidence_summary,
            'recent_messages' => array_values(array_filter(array_map(static function($message) {
                $role = sanitize_key((string) ($message['role'] ?? ''));
                if (!in_array($role, ['user','assistant'], true)) { return null; }
                return [
                    'id' => (int) ($message['id'] ?? 0),
                    'thread_id' => (int) ($message['thread_id'] ?? 0),
                    'role' => $role,
                    'message' => sanitize_textarea_field((string) ($message['message'] ?? '')),
                    'created_at' => sanitize_text_field((string) ($message['created_at'] ?? '')),
                ];
            }, (array) $recent_conversation))),
            'request_id' => $request_id,
        ]);
    }

    public static function brand_audit_abort() {
        $request_id = self::request_id();
        self::register_ajax_shutdown_handler($request_id, 'ves_brand_audit_abort');
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'brand_audit_auth', ['stage' => 'nonce_abort']);
        }
        self::require_panel_access($request_id, 'brand_audit_auth', 'brand_audit_abort');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? ''));
        if ($bundle_id === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $bundle_id)) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'brand_audit_validation', ['stage' => 'bundle_id_abort']);
        }
        try {
            $bundle = class_exists('VES_Brand_Audit_Execution_Service') ? VES_Brand_Audit_Execution_Service::get_bundle($bundle_id) : null;
            if (!$bundle) {
                self::error('No se ha encontrado el proceso solicitado o ha expirado.', 404, $request_id, 'brand_audit_lookup', ['bundle_id' => $bundle_id]);
            }
            if (!VES_Brand_Audit_Execution_Service::bundle_access_allowed($bundle)) {
                self::error('No autorizado para abortar este proceso.', 403, $request_id, 'brand_audit_auth', ['stage' => 'bundle_scope_abort']);
            }
            VES_Brand_Audit_Execution_Service::abort_brand_audit_run($bundle_id, 'Abortado por el usuario.');
            self::success([
                'platform' => 'brand_audit',
                'status' => 'ABORTED',
                'request_id' => $request_id,
            ]);
        } catch (Throwable $e) {
            self::fatal($e, $request_id, 'brand_audit_abort_fatal', ['action' => 'ves_brand_audit_abort', 'bundle_id' => $bundle_id]);
        }
    }

    public static function trends_start() {

        $request_id = self::request_id();
        if (class_exists('VES_Deep_Trend_Addon_Bridge')) {
            VES_Deep_Trend_Addon_Bridge::send_deprecated_response([
                'action' => 'ves_trends_start',
                'request_id' => $request_id,
                'method' => sanitize_key((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            ]);
            return;
        }
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error(__('Sesión caducada.', 'ves'), 403, $request_id, 'trend_auth', ['stage' => 'nonce_start']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_start');
        if (!ves_get_token()) {
            self::error(__('Trend Finder no disponible: falta token backend.', 'ves'), 500, $request_id, 'trend_config', ['stage' => 'missing_backend_token']);
        }

        // v0.9.26.0 — Phase 1 access gate for Trend Finder.
        if (class_exists('VES_Access_Control')) {
            $user_id = get_current_user_id();
            if (!VES_Access_Control::user_can_access_module($user_id, 'trend')) {
                $payload = VES_Access_Control::locked_error_payload('trend', 'modules');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }

        // v0.9.30.21: clean stale slots from any previous aborted Trend Finder run
        // before checking concurrency limits. Each Trend Finder run uses multiple
        // heavy slots (one per source), and a failed run may not release them if the
        // shutdown handler fires before finalize. Slots have a 20-min max TTL (in
        // load_dispatch_slots) but an earlier cleanup prevents false "too busy" errors.
        if (class_exists('VES_Apify_Client') && method_exists('VES_Apify_Client', 'cleanup_stale_dispatch_slots')) {
            VES_Apify_Client::cleanup_stale_dispatch_slots();
        }

        $request = function_exists('ves_trend_sanitize_request') ? ves_trend_sanitize_request($_POST) : [];
        if (empty($request['topic'])) {
            self::error('Debes indicar una categoría o tema.', 400, $request_id, 'trend_validation', ['stage' => 'topic']);
        }
        self::require_project_access_from_request($request_id, 'trend_auth', 'project_scope_start', $request);
        $start_gate_key = self::acquire_start_gate(
            // v0.9.30.1: key the concurrency gate on the full normalized request
            // (topic + country + duration + reason + project), not topic alone.
            // The previous topic-only key both over-blocked distinct requests that
            // shared a topic and under-matched genuine duplicates that differed only
            // in casing/whitespace. ksort keeps the hash order-stable.
            self::start_gate_key('trend_finder', 'trend_finder', self::trend_request_fingerprint($request)),
            $request_id,
            'trend_rate_limit',
            ['platform' => 'trend_finder']
        );
        $created = class_exists('VES_Run_Execution_Service') ? VES_Run_Execution_Service::create_trend_run($request, [
            'trigger' => 'manual',
            'request_id' => $request_id,
            'allow_provider_over_budget' => self::post_bool('allowProviderOverBudget', false, ['allow_provider_over_budget']),
        ]) : new WP_Error('trend_queue_missing', 'La cola de Trend Finder no está disponible.');
        if (is_wp_error($created)) {
            self::release_start_gate($start_gate_key ?? '');
            $created_error_data = is_array($created->get_error_data()) ? $created->get_error_data() : [];
            self::error($created->get_error_message(), (int) ($created_error_data['status'] ?? 500), $request_id, 'trend_dispatch', array_merge($created_error_data, ['stage' => sanitize_key((string) ($created_error_data['stage'] ?? 'create_run'))]));
        }
        // v0.9.10.9: manual Trend Finder must dispatch Apify actors immediately;
        // do not rely only on Action Scheduler/WP-Cron, which may be delayed or disabled.
        // v0.9.30.21: wrap start_queued_sources in try/finally so the start gate is
        // always released even if an unhandled exception escapes start_queued_sources.
        $bundle_id = sanitize_text_field((string) ($created['bundle_id'] ?? ''));
        try {
            $live_bundle = ($bundle_id !== '' && class_exists('VES_Run_Execution_Service')) ? VES_Run_Execution_Service::start_queued_sources($bundle_id, (int) ($created['run_id'] ?? 0)) : null;
            $live_sources = is_array($live_bundle['sources'] ?? null) ? array_values($live_bundle['sources']) : (array) ($created['sources'] ?? []);
        } finally {
            self::release_start_gate($start_gate_key ?? '');
        }
        $provider_cost = is_array($created['provider_cost'] ?? null) ? $created['provider_cost'] : (class_exists('VES_Config') ? VES_Config::estimate_trend_provider_cost([]) : []);
        $provider_source_count = (int) ($provider_cost['source_count'] ?? count($live_sources));
        $provider_heavy_count = (int) ($provider_cost['heavy_source_count'] ?? 0);
        $provider_estimated_cost = (float) ($provider_cost['estimated_usd'] ?? 0);
        $provider_warning_threshold = (float) ($provider_cost['warning_threshold_usd'] ?? (class_exists('VES_Config') ? VES_Config::apify_cost_warning_threshold_usd() : 0));
        $provider_cost_limit = (float) ($provider_cost['max_estimated_cost_per_run_usd'] ?? (class_exists('VES_Config') ? VES_Config::max_external_provider_cost_per_run() : 0));
        $provider_max_sources = (int) ($provider_cost['max_sources_per_run'] ?? (class_exists('VES_Config') ? VES_Config::apify_max_sources_per_run() : 100));
        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'status' => 'RUNNING',
            'bundle_id' => $bundle_id,
            'progress' => [
                'done' => 0,
                'total' => count($live_sources),
            ],
            'query_plan' => $created['query_plan'] ?? [],
            'source_preview' => $created['source_preview'] ?? [],
            'provider_cost' => array_merge([
                'estimated_usd' => $provider_estimated_cost,
                'source_count' => $provider_source_count,
                'heavy_source_count' => $provider_heavy_count,
                'warning_threshold_usd' => $provider_warning_threshold,
                'max_estimated_cost_per_run_usd' => $provider_cost_limit,
                'max_sources_per_run' => $provider_max_sources,
                'warning' => ($provider_warning_threshold > 0 && $provider_estimated_cost >= $provider_warning_threshold) || ($provider_max_sources > 0 && $provider_source_count > $provider_max_sources),
                'limit_exceeded' => ($provider_cost_limit > 0 && $provider_estimated_cost > $provider_cost_limit) || ($provider_max_sources > 0 && $provider_source_count > $provider_max_sources),
                'note' => 'Admin-only external provider estimate; separate from internal credits.',
            ], is_array($provider_cost) ? $provider_cost : []),
            'sources' => array_map(static function ($source) {
                return [
                    'label' => $source['label'] ?? '',
                    'status' => $source['status'] ?? 'QUEUED',
                    'dispatch_status' => $source['dispatch_status'] ?? 'pending',
                    'run_id' => !empty($source['run_id']) ? 'created' : '',
                    'notes' => $source['notes'] ?? '',
                ];
            }, $live_sources),
            'request_id' => $request_id,
        ], 'ves_trends_start'));
    }

    public static function trends_poll() {
        $request_id = self::request_id();

        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_poll']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_poll');

        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? ''));
        if ($bundle_id === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $bundle_id)) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'trend_validation', ['stage' => 'bundle_id']);
        }

        $bundle = class_exists('VES_Run_Execution_Service') ? VES_Run_Execution_Service::get_bundle($bundle_id) : null;
        if (!$bundle) {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 404, $request_id, 'trend_lookup', ['bundle_id' => $bundle_id]);
        }
        if (!self::trend_bundle_access_allowed($bundle)) {
            self::error('No autorizado para consultar este proceso.', 403, $request_id, 'trend_auth', ['stage' => 'bundle_scope']);
        }

        if (!empty($bundle['final']) && is_array($bundle['final'])) {
            $final = $bundle['final'];
            $final['request_id'] = $request_id;
            self::success(self::deprecated_trend_success_payload($final, 'ves_trends_poll'));
        }

        // v0.9.10.9: each poll advances the run as a fallback runner. This keeps
        // Trend Finder working even when WP-Cron or Action Scheduler is delayed.
        if (class_exists('VES_Run_Execution_Service')) {
            VES_Run_Execution_Service::tick_trend_run($bundle_id);
            $bundle = VES_Run_Execution_Service::get_bundle($bundle_id);
        }
        if (!empty($bundle['final']) && is_array($bundle['final'])) {
            $final = $bundle['final'];
            $final['request_id'] = $request_id;
            self::success(self::deprecated_trend_success_payload($final, 'ves_trends_poll'));
        }
        $done = 0;
        foreach ((array) ($bundle['sources'] ?? []) as $source) {
            $status = (string) ($source['status'] ?? 'UNKNOWN');
            if (self::trend_source_terminal($status)) {
                $done++;
            }
        }
        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'status' => 'RUNNING',
            'bundle_id' => $bundle_id,
            'progress' => [
                'done' => $done,
                'total' => count((array) ($bundle['sources'] ?? [])),
            ],
            'sources' => array_map(static function ($source) {
                return [
                    'label' => $source['label'] ?? '',
                    'status' => $source['status'] ?? '',
                    'dispatch_status' => $source['dispatch_status'] ?? '',
                    'analytical_usability_status' => $source['analytical_usability_status'] ?? 'unknown',
                    'notes' => $source['notes'] ?? '',
                ];
            }, (array) ($bundle['sources'] ?? [])),
            'request_id' => $request_id,
        ], 'ves_trends_poll'));
    }

    public static function trends_abort() {

        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_abort']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_abort');
        $bundle_id = sanitize_text_field(wp_unslash($_POST['bundleId'] ?? ''));
        if ($bundle_id === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $bundle_id)) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'trend_validation', ['stage' => 'bundle_id_abort']);
        }
        $bundle = class_exists('VES_Run_Execution_Service') ? VES_Run_Execution_Service::get_bundle($bundle_id) : null;
        if (!$bundle) {
            self::error('No se ha encontrado el proceso solicitado o ha expirado.', 404, $request_id, 'trend_lookup', ['bundle_id' => $bundle_id]);
        }
        if (!self::trend_bundle_access_allowed($bundle)) {
            self::error('No autorizado para abortar este proceso.', 403, $request_id, 'trend_auth', ['stage' => 'bundle_scope_abort']);
        }
        VES_Run_Execution_Service::abort_trend_run($bundle_id, 'Abortado por el usuario.');
        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'status' => 'ABORTED',
            'requested' => [
                'topic' => $bundle['request']['topic'] ?? '',
            ],
            'request_id' => $request_id,
        ], 'ves_trends_abort'));
    }

    public static function trends_history() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_history']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_history');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Historial no disponible.', 500, $request_id, 'trend_history', ['stage' => 'memory_missing']);
        }

        $limit = max(1, min(20, (int) (wp_unslash($_POST['limit'] ?? 10))));
        $filters = [
            'topic' => sanitize_text_field((string) wp_unslash($_POST['trendCategory'] ?? $_POST['topic'] ?? '')),
            'country' => sanitize_text_field((string) wp_unslash($_POST['trendCountry'] ?? $_POST['country'] ?? '')),
            'duration' => sanitize_key((string) wp_unslash($_POST['trendTimeRange'] ?? $_POST['duration'] ?? '')),
        ];
        $history = VES_Temp_Memory::get_recent_trend_reports($limit, $filters);

        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'history' => $history,
            'request_id' => $request_id,
        ], 'ves_trends_history'));
    }

    public static function trends_dashboard() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_dashboard']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_dashboard');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Dashboard no disponible.', 500, $request_id, 'trend_dashboard', ['stage' => 'memory_missing']);
        }

        $dashboard_args = [
            'topic' => sanitize_text_field((string) wp_unslash($_POST['topic'] ?? $_POST['trendCategory'] ?? '')),
            'country' => sanitize_text_field((string) wp_unslash($_POST['country'] ?? $_POST['trendCountry'] ?? '')),
            'duration' => sanitize_key((string) wp_unslash($_POST['duration'] ?? $_POST['trendTimeRange'] ?? '')),
            'date_from' => sanitize_text_field((string) wp_unslash($_POST['dateFrom'] ?? $_POST['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) wp_unslash($_POST['dateTo'] ?? $_POST['date_to'] ?? '')),
            'limit' => max(1, min(100, (int) (wp_unslash($_POST['limit'] ?? 24)))),
        ];
        $dashboard = VES_Temp_Memory::get_trend_dashboard($dashboard_args);
        $memory_activity = method_exists('VES_Temp_Memory', 'get_recent_memory_activity')
            ? VES_Temp_Memory::get_recent_memory_activity(16, $dashboard_args)
            : [];

        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'dashboard' => $dashboard['items'] ?? [],
            'memory' => $memory_activity,
            'total' => (int) ($dashboard['total'] ?? 0),
            'filters' => $dashboard['filters'] ?? [],
            'request_id' => $request_id,
        ], 'ves_trends_dashboard'));
    }

    public static function trends_compare() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_compare']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_compare');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Comparación no disponible.', 500, $request_id, 'trend_compare', ['stage' => 'memory_missing']);
        }

        $report_a = (int) (wp_unslash($_POST['reportA'] ?? 0));
        $report_b = (int) (wp_unslash($_POST['reportB'] ?? 0));
        if ($report_a <= 0 || $report_b <= 0 || $report_a === $report_b) {
            self::error('Selecciona dos snapshots distintos para comparar.', 400, $request_id, 'trend_compare', ['stage' => 'invalid_selection']);
        }

        $comparison = VES_Temp_Memory::compare_trend_report_ids($report_a, $report_b);
        if (empty($comparison)) {
            self::error('No se pudo construir la comparación solicitada.', 404, $request_id, 'trend_compare', ['stage' => 'not_found']);
        }

        self::success(self::deprecated_trend_success_payload([
            'platform' => 'trend_finder',
            'comparison' => $comparison,
            'request_id' => $request_id,
        ], 'ves_trends_compare'));
    }

    private static function require_admin_ops($request_id, $stage = 'admin_ops') {
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'admin_ops_auth', ['stage' => $stage . '_nonce']);
        }
        if (!current_user_can('manage_options')) {
            self::error('Solo administradores pueden ejecutar esta acción.', 403, $request_id, 'admin_ops_auth', ['stage' => $stage . '_capability']);
        }
    }

    public static function trend_memory_repair_count() {
        $request_id = self::request_id();
        self::require_admin_ops($request_id, 'trend_memory_repair_count');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Memory store no disponible.', 500, $request_id, 'trend_memory_repair', ['stage' => 'memory_missing']);
        }
        $limit = isset($_POST['limit']) ? max(1, min(5000, (int) wp_unslash($_POST['limit']))) : 1000;
        self::success(self::deprecated_trend_success_payload(['repair_scan' => VES_Temp_Memory::count_corrupted_trend_memory($limit), 'request_id' => $request_id], 'ves_trend_memory_repair_count'));
    }

    public static function trend_memory_repair_dry_run() {
        $request_id = self::request_id();
        self::require_admin_ops($request_id, 'trend_memory_repair_dry_run');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Memory store no disponible.', 500, $request_id, 'trend_memory_repair', ['stage' => 'memory_missing']);
        }
        $limit = isset($_POST['limit']) ? max(1, min(1000, (int) wp_unslash($_POST['limit']))) : 200;
        self::success(self::deprecated_trend_success_payload(['repair_result' => VES_Temp_Memory::repair_corrupted_trend_memory($limit, true), 'request_id' => $request_id], 'ves_trend_memory_repair_dry_run'));
    }

    public static function trend_memory_repair_apply() {
        $request_id = self::request_id();
        self::require_admin_ops($request_id, 'trend_memory_repair_apply');
        if (!class_exists('VES_Temp_Memory')) {
            self::error('Memory store no disponible.', 500, $request_id, 'trend_memory_repair', ['stage' => 'memory_missing']);
        }
        $limit = isset($_POST['limit']) ? max(1, min(1000, (int) wp_unslash($_POST['limit']))) : 200;
        self::success(self::deprecated_trend_success_payload(['repair_result' => VES_Temp_Memory::repair_corrupted_trend_memory($limit, false), 'request_id' => $request_id], 'ves_trend_memory_repair_apply'));
    }

    public static function apify_slot_diagnostics() {
        $request_id = self::request_id();
        self::require_admin_ops($request_id, 'apify_slot_diagnostics');
        $diag = class_exists('VES_Apify_Client') && method_exists('VES_Apify_Client', 'dispatch_slot_diagnostics') ? VES_Apify_Client::dispatch_slot_diagnostics(true) : ['available' => false];
        self::success(['apify_slots' => $diag, 'request_id' => $request_id]);
    }

    public static function trends_brief_create() {
        $request_id = self::request_id();
        if (class_exists('VES_Deep_Trend_Addon_Bridge')) {
            VES_Deep_Trend_Addon_Bridge::send_deprecated_response([
                'action' => 'ves_trends_brief_create',
                'request_id' => $request_id,
                'method' => sanitize_key((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            ]);
            return;
        }
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error(__('Sesión caducada.', 'ves'), 403, $request_id, 'trend_auth', ['stage' => 'nonce_brief']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_brief');
        if (!class_exists('VES_Temp_Memory')) {
            self::error(__('Brief Studio no disponible.', 'ves'), 500, $request_id, 'trend_brief', ['stage' => 'memory_missing']);
        }

        $report_id = (int) (wp_unslash($_POST['reportId'] ?? 0));
        $insight_id = (int) (wp_unslash($_POST['insightId'] ?? 0));
        $trend_name = sanitize_text_field((string) (wp_unslash($_POST['trendName'] ?? '')));
        $brief_format = sanitize_text_field((string) (wp_unslash($_POST['briefFormat'] ?? 'TikTok / Reels')));
        $extra_context = sanitize_textarea_field((string) (wp_unslash($_POST['extraContext'] ?? '')));
        $insight = null;
        if ($insight_id > 0 && class_exists('VES_Trend_Insight_Store')) {
            $insight = VES_Trend_Insight_Store::get_insight_for_user($insight_id);
            if (!$insight) {
                self::error('No se ha encontrado el insight seleccionado.', 404, $request_id, 'trend_brief', ['stage' => 'insight_not_found']);
            }
            $trend_name = sanitize_text_field((string) ($insight['trend_name'] ?? $trend_name));
            $insight_report_id = (int) ($insight['memory_entry_id'] ?? 0);
            $report_id = $report_id > 0 ? $report_id : $insight_report_id;
            if ($insight_report_id > 0 && $report_id > 0 && $insight_report_id !== $report_id) {
                self::error('El insight seleccionado no pertenece al reporte indicado.', 403, $request_id, 'trend_brief', ['stage' => 'insight_report_mismatch']);
            }
            if ((float) ($insight['confidence_score'] ?? 0) < 4.5) {
                self::error('La señal todavía es demasiado débil para convertirla en brief. Necesita más evidencia.', 400, $request_id, 'trend_brief', ['stage' => 'low_confidence']);
            }
        }
        if ($report_id <= 0 || $trend_name === '') {
            self::error('Selecciona un trend válido para crear el brief.', 400, $request_id, 'trend_brief', ['stage' => 'missing_inputs']);
        }

        $row = VES_Temp_Memory::get_trend_report_by_id($report_id);
        if (!$row || empty($row['payload'])) {
            self::error('No se ha encontrado el reporte base para crear el brief.', 404, $request_id, 'trend_brief', ['stage' => 'report_not_found']);
        }
        $base_payload = is_array($row['payload']) ? $row['payload'] : [];
        $memory_status = class_exists('VES_Trend_Label_Guard') ? VES_Trend_Label_Guard::memory_status_for_payload($base_payload) : sanitize_key((string) ($base_payload['memory_status'] ?? 'validated'));
        $coverage = is_array($base_payload['evidence_coverage_summary'] ?? null) ? $base_payload['evidence_coverage_summary'] : [];
        $fallback_report = !empty($base_payload['fallback_used']) || (($base_payload['confidence_mode'] ?? '') === 'diagnostic_fallback') || (($base_payload['memory_type'] ?? '') === 'fallback_diagnostic') || (($base_payload['can_generate_brief'] ?? true) === false);
        $insufficient_evidence = $fallback_report || $memory_status === 'corrupted_legacy' || $memory_status === 'needs_repair' || (($base_payload['confidence_mode'] ?? '') !== 'validated') || (int) ($coverage['direct_matches'] ?? 0) <= 0;
        if ($fallback_report) {
            self::error('Este reporte es diagnóstico/fallback. Valida evidencia o relanza el análisis AI antes de generar un brief.', 400, $request_id, 'trend_brief', ['stage' => 'fallback_brief_blocked']);
        }
        if ($insufficient_evidence) {
            self::error('No brief can be created until validated insights exist.', 400, $request_id, 'trend_brief', ['stage' => 'insufficient_evidence_brief_blocked', 'memory_status' => $memory_status]);
        }

        $usage_key = '';
        if (is_user_logged_in() && class_exists('VES_Usage_Billing')) {
            $usage_key = 'trend_brief:' . $report_id . ':' . ($insight_id > 0 ? $insight_id : md5($trend_name));
            $reserved = VES_Usage_Billing::reserve_usage(get_current_user_id(), 'trend_brief', $usage_key, '', [
                'platform' => 'trend_finder',
                'report_id' => $report_id,
                'trend_name' => $trend_name,
                'target_type' => 'trend_brief',
                'target_id' => (string) $report_id,
            ]);
            if (is_wp_error($reserved)) {
                self::error($reserved->get_error_message(), 402, $request_id, 'usage_billing', ['stage' => 'reserve_brief']);
            }
        }

        $project_context = self::get_ai_project_context([
            'platform' => 'trend_finder',
            'topic' => $trend_name,
            'focus' => $brief_format . ' ' . $extra_context,
            'targets' => $row['payload']['requested']['topic'] ?? '',
            'country' => $row['payload']['requested']['country'] ?? '',
        ]);
        $related_memory = self::get_related_memory_context([
            'platform' => 'trend_finder',
            'topic' => $trend_name,
            'focus' => $brief_format . ' ' . $extra_context,
            'brand_name' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '',
            'primary_goal' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['primary_goal'] ?? '') : '',
            'website_url' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['website_url'] ?? '') : '',
            'markets' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['markets'] ?? '') : '',
            'competitors' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['competitors'] ?? '') : '',
            'workspace_knowledge_terms' => self::project_context_memory_terms($project_context),
        ]);
        $brief = VES_OpenAI_Client::generate_trend_brief($row['payload'], [
            'trend_name' => $trend_name,
            'brief_format' => $brief_format,
            'extra_context' => $extra_context,
        ], $related_memory, $project_context);
        if (is_wp_error($brief)) {
            if ($usage_key !== '' && class_exists('VES_Usage_Billing')) {
                VES_Usage_Billing::void_reserved_usage($usage_key, $brief->get_error_message());
            }
            self::error($brief->get_error_message(), 500, $request_id, 'trend_brief', ['stage' => 'generation']);
        }

        if ($usage_key !== '' && class_exists('VES_Usage_Billing')) {
            VES_Usage_Billing::post_reserved_usage($usage_key, 'Brief generado');
        }
        $brief_id = VES_Temp_Memory::save_trend_brief($report_id, $trend_name, $brief_format, $brief, [
            'insight_id' => $insight_id,
            'run_id' => sanitize_text_field((string) ($row['payload']['bundle_id'] ?? '')),
            'confidence_mode' => sanitize_key((string) ($row['payload']['confidence_mode'] ?? '')),
        ]);
        $briefs = VES_Temp_Memory::get_recent_trend_briefs($report_id, 8);
        self::success([
            'platform' => 'trend_finder',
            'brief_id' => (int) $brief_id,
            'brief' => [
                'id' => (int) $brief_id,
                'created_at' => current_time('mysql'),
                'trend_name' => $trend_name,
                'brief_format' => $brief_format,
                'brief_text' => $brief['brief_text'] ?? '',
                'brief_structured' => $brief['brief_structured'] ?? null,
                'model' => $brief['model'] ?? '',
                'analysis_format' => $brief['format'] ?? 'text',
            ],
            'briefs' => $briefs,
            'request_id' => $request_id,
        ]);
    }

    public static function trend_monitors_list() {

        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_monitor_list']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_monitor_list');
        if (!class_exists('VES_Monitor_Store')) {
            self::error('Monitor store no disponible.', 500, $request_id, 'trend_monitors', ['stage' => 'store_missing']);
        }
        self::success(self::deprecated_trend_success_payload([
            'monitors' => VES_Monitor_Store::get_monitors(),
            'request_id' => $request_id,
        ], 'ves_trend_monitors_list'));
    }

    public static function trend_monitors_save() {
        $request_id = self::request_id();
        if (class_exists('VES_Deep_Trend_Addon_Bridge')) {
            VES_Deep_Trend_Addon_Bridge::send_deprecated_response([
                'action' => 'ves_trend_monitors_save',
                'request_id' => $request_id,
                'method' => sanitize_key((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            ]);
            return;
        }
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error(__('Sesión caducada.', 'ves'), 403, $request_id, 'trend_auth', ['stage' => 'nonce_monitor_save']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_monitor_save');
        if (!class_exists('VES_Monitor_Store')) {
            self::error(__('Monitor store no disponible.', 'ves'), 500, $request_id, 'trend_monitors', ['stage' => 'store_missing']);
        }
        $monitor_id = sanitize_text_field((string) (wp_unslash($_POST['monitorId'] ?? '')));
        $existing_monitor = $monitor_id !== '' ? VES_Monitor_Store::get_monitor_any_owner($monitor_id) : null;
        if ($monitor_id !== '' && !$existing_monitor) {
            self::error('No se ha encontrado el monitor indicado.', 404, $request_id, 'trend_monitors', ['stage' => 'monitor_not_found_for_update']);
        }
        if ($existing_monitor && method_exists('VES_Monitor_Store', 'current_owner_can_access_monitor') && !VES_Monitor_Store::current_owner_can_access_monitor($monitor_id)) {
            self::error('No autorizado para modificar este monitor.', 403, $request_id, 'trend_monitors', ['stage' => 'monitor_owner_mismatch']);
        }
        $cadence = sanitize_key((string) (wp_unslash($_POST['cadence'] ?? 'manual')));
        if (is_user_logged_in() && class_exists('VES_Usage_Billing') && VES_Usage_Billing::would_exceed_monitor_limit(get_current_user_id(), $monitor_id, $cadence)) {
            $plan = VES_Usage_Billing::get_user_plan(get_current_user_id());
            self::error(sprintf('Tu plan %s ya alcanzó el máximo de monitores activos.', (string) ($plan['label'] ?? 'actual')), 400, $request_id, 'trend_monitors', ['stage' => 'monitor_limit_reached']);
        }
        $saved = VES_Monitor_Store::save_monitor([
            'id' => $monitor_id,
            'name' => sanitize_text_field((string) (wp_unslash($_POST['monitorName'] ?? ''))),
            'topic' => sanitize_text_field((string) wp_unslash($_POST['topic'] ?? $_POST['trendCategory'] ?? '')),
            'country' => sanitize_text_field((string) wp_unslash($_POST['country'] ?? $_POST['trendCountry'] ?? 'None')),
            'duration' => sanitize_key((string) wp_unslash($_POST['duration'] ?? $_POST['trendTimeRange'] ?? '7d')),
            'reason' => sanitize_textarea_field((string) wp_unslash($_POST['reason'] ?? $_POST['trendReason'] ?? '')),
            'cadence' => $cadence,
            'alert_threshold' => (int) (wp_unslash($_POST['alertThreshold'] ?? 7)),
        ]);
        if (is_wp_error($saved)) {
            self::error($saved->get_error_message(), 400, $request_id, 'trend_monitors', ['stage' => 'save_failed']);
        }
        self::success([
            'monitor' => $saved,
            'monitors' => VES_Monitor_Store::get_monitors(),
            'request_id' => $request_id,
        ]);
    }


    public static function project_context_get() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'project_context', ['stage' => 'nonce_get']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para editar tu contexto.', 403, $request_id, 'project_context', ['stage' => 'login_required_get']);
        }
        self::deny_if_role_forbidden($request_id, 'project_context', 'role_context_get');
        $context = class_exists('VES_Project_Context') ? VES_Project_Context::get_current_user_context() : [];
        self::success([
            'context' => $context,
            'request_id' => $request_id,
        ]);
    }

    public static function project_context_save() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'project_context', ['stage' => 'nonce_save']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para guardar tu contexto.', 403, $request_id, 'project_context', ['stage' => 'login_required_save']);
        }
        self::deny_if_role_forbidden($request_id, 'project_context', 'role_context_save');
        if (!class_exists('VES_Project_Context')) {
            self::error('Project context no disponible.', 500, $request_id, 'project_context', ['stage' => 'class_missing']);
        }
        $saved = VES_Project_Context::save_user_context(get_current_user_id(), [
            'brand_name' => sanitize_text_field((string) (wp_unslash($_POST['brand_name'] ?? ''))),
            'website_url' => esc_url_raw((string) (wp_unslash($_POST['website_url'] ?? ''))),
            'brand_summary' => sanitize_textarea_field((string) (wp_unslash($_POST['brand_summary'] ?? ''))),
            'products_services' => sanitize_textarea_field((string) (wp_unslash($_POST['products_services'] ?? ''))),
            'target_audience' => sanitize_textarea_field((string) (wp_unslash($_POST['target_audience'] ?? ''))),
            'primary_goal' => sanitize_textarea_field((string) (wp_unslash($_POST['primary_goal'] ?? ''))),
            'tone_of_voice' => sanitize_text_field((string) (wp_unslash($_POST['tone_of_voice'] ?? ''))),
            'markets' => sanitize_textarea_field((string) (wp_unslash($_POST['markets'] ?? ''))),
            'competitors' => sanitize_textarea_field((string) (wp_unslash($_POST['competitors'] ?? ''))),
            'notes' => sanitize_textarea_field((string) (wp_unslash($_POST['notes'] ?? ''))),
        ]);
        if (is_wp_error($saved)) {
            self::error($saved->get_error_message(), 400, $request_id, 'project_context', ['stage' => 'save_failed']);
        }
        self::success([
            'context' => $saved,
            'request_id' => $request_id,
        ]);
    }


    public static function workspace_knowledge_list() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'workspace_knowledge', ['stage' => 'nonce_list']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para ver tu workspace knowledge.', 403, $request_id, 'workspace_knowledge', ['stage' => 'login_required_list']);
        }
        self::deny_if_role_forbidden($request_id, 'workspace_knowledge', 'role_list');
        if (!class_exists('VES_Workspace_Knowledge')) {
            self::error('Workspace knowledge no disponible.', 500, $request_id, 'workspace_knowledge', ['stage' => 'class_missing']);
        }
        self::success([
            'items' => VES_Workspace_Knowledge::get_user_items(get_current_user_id()),
            'request_id' => $request_id,
        ]);
    }

    public static function workspace_knowledge_upload() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'workspace_knowledge', ['stage' => 'nonce_upload']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para subir conocimiento.', 403, $request_id, 'workspace_knowledge', ['stage' => 'login_required_upload']);
        }
        self::deny_if_role_forbidden($request_id, 'workspace_knowledge', 'role_upload');
        if (!class_exists('VES_Workspace_Knowledge')) {
            self::error('Workspace knowledge no disponible.', 500, $request_id, 'workspace_knowledge', ['stage' => 'class_missing']);
        }
        $title = sanitize_text_field((string) (wp_unslash($_POST['knowledge_title'] ?? '')));
        $item = VES_Workspace_Knowledge::add_uploaded_file(get_current_user_id(), 'knowledge_file', $title);
        if (is_wp_error($item)) {
            self::error($item->get_error_message(), 400, $request_id, 'workspace_knowledge', ['stage' => 'upload_failed']);
        }
        self::success([
            'item' => $item,
            'items' => VES_Workspace_Knowledge::get_user_items(get_current_user_id()),
            'request_id' => $request_id,
        ]);
    }

    public static function workspace_knowledge_ingest() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'workspace_knowledge', ['stage' => 'nonce_ingest']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para ingerir una URL.', 403, $request_id, 'workspace_knowledge', ['stage' => 'login_required_ingest']);
        }
        self::deny_if_role_forbidden($request_id, 'workspace_knowledge', 'role_ingest');
        if (!class_exists('VES_Workspace_Knowledge')) {
            self::error('Workspace knowledge no disponible.', 500, $request_id, 'workspace_knowledge', ['stage' => 'class_missing']);
        }
        $url = esc_url_raw((string) (wp_unslash($_POST['knowledge_url'] ?? '')));
        $title = sanitize_text_field((string) (wp_unslash($_POST['knowledge_url_title'] ?? '')));
        $item = VES_Workspace_Knowledge::ingest_url_profile(get_current_user_id(), $url, $title);
        if (is_wp_error($item)) {
            self::error($item->get_error_message(), 400, $request_id, 'workspace_knowledge', ['stage' => 'ingest_failed']);
        }
        self::success([
            'item' => $item,
            'items' => VES_Workspace_Knowledge::get_user_items(get_current_user_id()),
            'request_id' => $request_id,
        ]);
    }

    public static function workspace_knowledge_delete() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'workspace_knowledge', ['stage' => 'nonce_delete']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para borrar conocimiento.', 403, $request_id, 'workspace_knowledge', ['stage' => 'login_required_delete']);
        }
        self::deny_if_role_forbidden($request_id, 'workspace_knowledge', 'role_delete');
        if (!class_exists('VES_Workspace_Knowledge')) {
            self::error('Workspace knowledge no disponible.', 500, $request_id, 'workspace_knowledge', ['stage' => 'class_missing']);
        }
        $item_id = sanitize_key((string) (wp_unslash($_POST['knowledgeId'] ?? '')));
        $deleted = VES_Workspace_Knowledge::delete_item(get_current_user_id(), $item_id);
        if (is_wp_error($deleted)) {
            self::error($deleted->get_error_message(), 400, $request_id, 'workspace_knowledge', ['stage' => 'delete_failed']);
        }
        self::success([
            'items' => VES_Workspace_Knowledge::get_user_items(get_current_user_id()),
            'request_id' => $request_id,
        ]);
    }

    public static function usage_summary() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'usage_summary', ['stage' => 'nonce']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para consultar tu saldo.', 403, $request_id, 'usage_summary', ['stage' => 'login_required']);
        }
        self::deny_if_role_forbidden($request_id, 'usage_summary', 'role_usage_summary');
        self::success([
            'summary' => class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_summary(get_current_user_id()) : [],
            'html' => class_exists('VES_Usage_Billing') ? VES_Usage_Billing::render_account_summary(get_current_user_id()) : '',
            'request_id' => $request_id,
        ]);
    }

    public static function trend_monitors_delete() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'trend_auth', ['stage' => 'nonce_monitor_delete']);
        }
        self::require_panel_access($request_id, 'trend_auth', 'trend_monitor_delete');
        if (!class_exists('VES_Monitor_Store')) {
            self::error('Monitor store no disponible.', 500, $request_id, 'trend_monitors', ['stage' => 'store_missing']);
        }
        $monitor_id = sanitize_text_field((string) (wp_unslash($_POST['monitorId'] ?? '')));
        if ($monitor_id !== '' && method_exists('VES_Monitor_Store', 'current_owner_can_access_monitor') && !VES_Monitor_Store::current_owner_can_access_monitor($monitor_id)) {
            self::error('No autorizado para borrar este monitor.', 403, $request_id, 'trend_monitors', ['stage' => 'monitor_owner_mismatch_delete']);
        }
        if ($monitor_id === '' || !VES_Monitor_Store::delete_monitor($monitor_id)) {
            self::error('No se pudo borrar el monitor.', 404, $request_id, 'trend_monitors', ['stage' => 'delete_failed']);
        }
        self::success(self::deprecated_trend_success_payload([
            'monitors' => VES_Monitor_Store::get_monitors(),
            'request_id' => $request_id,
        ], 'ves_trend_monitors_delete'));
    }

    public static function google_start() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada. Refresca la página.', 403, $request_id, 'google_auth', ['stage' => 'nonce']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para usar Google Intelligence.', 403, $request_id, 'google_auth', ['stage' => 'login_required']);
        }
        self::deny_if_role_forbidden($request_id, 'google_auth', 'role_google_start');
        self::require_project_access_from_request($request_id, 'google_auth', 'project_scope_start', self::normalized_post());

        // v0.9.26.0 — Phase 1 access gate for Google Intelligence.
        if (class_exists('VES_Access_Control')) {
            $user_id = get_current_user_id();
            if (!VES_Access_Control::user_can_access_module($user_id, 'google')) {
                $payload = VES_Access_Control::locked_error_payload('google', 'modules');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }

        if (!class_exists('VES_Google_Intel')) {
            self::error('Google Intelligence no está disponible.', 500, $request_id, 'google_config', ['stage' => 'class_missing']);
        }
        $module_key = sanitize_key((string) (wp_unslash($_POST['googleModule'] ?? $_POST['module'] ?? 'search')));
        if (self::production_mvp_enabled() && in_array($module_key, self::production_mvp_disabled_google_modules(), true)) {
            self::mvp_disabled_ajax_response($request_id, 'google_mvp_gate', 'google_intelligence_advanced', [
                'stage' => 'production_mvp_gate',
                'google_module' => $module_key,
                'platform' => 'google_intel',
            ]);
        }
        $module = VES_Google_Intel::get_module($module_key);
        if (!$module) {
            self::error('Módulo de Google Intelligence no válido.', 400, $request_id, 'google_validation', ['stage' => 'module']);
        }
        if (!VES_Config::get_token()) {
            self::success(self::google_basic_diagnostic_payload($request_id, 'missing_apify_token', 'Google Intelligence Basic no está disponible con la configuración actual. Configura la API key de Apify para ejecutar Google Search.'));
        }
        if (class_exists('VES_Access_Control')) {
            $provider_map = [
                'search' => 'google_search',
                'images' => 'google_search',
                'news' => 'google_news',
                'trends' => 'google_trends',
                'google_trends' => 'google_trends',
            ];
            $provider_key = $provider_map[$module_key] ?? 'google_search';
            if (!VES_Access_Control::user_can_access_provider(get_current_user_id(), $provider_key)) {
                $payload = VES_Access_Control::locked_error_payload($provider_key, 'providers');
                $payload['request_id'] = $request_id;
                wp_send_json($payload, 403);
                exit;
            }
        }
        $input = VES_Google_Intel::build_input($module_key, wp_unslash($_POST));
        if (is_wp_error($input)) {
            self::error($input->get_error_message(), 400, $request_id, 'google_validation', ['stage' => 'build_input']);
        }

        $start_gate_key = self::acquire_start_gate(
            self::start_gate_key('google_intel', $module_key, md5((string) wp_json_encode($input))),
            $request_id,
            'google_rate_limit',
            ['module' => $module_key]
        );
        $usage_key = self::reserve_standard_usage_or_error($request_id, 'manual_run', 'google_usage', [
            'platform' => 'google_intel',
            'module' => $module_key,
            'type' => 'google_intelligence_scrape',
        ]);

        $run = VES_Google_Intel::run_actor($module_key, $input, 45);
        if (is_wp_error($run)) {
            self::release_start_gate($start_gate_key ?? '');
            self::void_standard_usage($usage_key, $run->get_error_message());
            if ($run->get_error_code() === 'ves_google_missing_token') {
                self::success(self::google_basic_diagnostic_payload($request_id, 'missing_apify_token', 'Google Intelligence Basic no está disponible con la configuración actual. Configura la API key de Apify para ejecutar Google Search.'));
            }
            $google_run_error_data = $run->get_error_data();
            $google_run_error_data = is_array($google_run_error_data) ? $google_run_error_data : [];
            self::error($run->get_error_message(), (int) ($google_run_error_data['status'] ?? 500), $request_id, 'google_actor_start', array_merge($google_run_error_data, [
                'stage' => 'run_actor',
                'module' => $module_key,
                'error_code' => $run->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => false,
                'credits_reserved' => !empty($usage_key),
                'credits_refunded_or_voided' => !empty($usage_key),
            ]));
        }
        $run_body = is_array($run['body'] ?? null) ? $run['body'] : [];
        $data = is_array($run_body['data'] ?? null) ? $run_body['data'] : [];
        $run_id = sanitize_text_field((string) ($data['id'] ?? ''));
        $status = (string) ($data['status'] ?? 'UNKNOWN');
        if ($run_id !== '') {
            self::remember_standard_run_owner($run_id, 'google_intel', $usage_key);
        }

        $items = [];
        if ($status === 'SUCCEEDED' && $run_id !== '') {
            $items = VES_Apify_Client::fetch_items($run_id, (int) ($module['fetch_limit'] ?? 50));
            if (is_wp_error($items)) { $items = []; }
            self::post_standard_usage_or_error($usage_key, $request_id, 'google_usage', 'Google Intelligence scrape confirmado');
            self::remember_standard_run_owner($run_id, 'google_intel', '');
        } elseif (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true)) {
            self::void_standard_usage($usage_key, 'Google Intelligence terminó sin resultados.');
        }

        $formatted = VES_Google_Intel::format_response($module_key, $module, $run_body, is_array($items) ? $items : [], $input);
        $formatted['request_id'] = $request_id;
        if ($status === 'SUCCEEDED' && class_exists('VES_Temp_Memory')) {
            $formatted['memory_entry_id'] = VES_Temp_Memory::save_search('google_intel', $_POST, $formatted);
        }
        self::release_start_gate($start_gate_key ?? '');
        self::log_run_stage($request_id, $platform, 'response_rendered', ['provider_call_attempted' => true, 'provider_run_created' => true]);
        self::success($formatted);
    }

    public static function google_poll() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'google_auth', ['stage' => 'nonce_poll']);
        }
        if (!is_user_logged_in()) {
            self::error('No autorizado.', 403, $request_id, 'google_auth', ['stage' => 'login_required_poll']);
        }
        self::deny_if_role_forbidden($request_id, 'google_auth', 'role_google_poll');
        if (!class_exists('VES_Google_Intel')) {
            self::error('Google Intelligence no está disponible.', 500, $request_id, 'google_config', ['stage' => 'class_missing_poll']);
        }
        $module_key = sanitize_key((string) (wp_unslash($_POST['googleModule'] ?? $_POST['module'] ?? 'search')));
        $run_id = sanitize_text_field((string) (wp_unslash($_POST['runId'] ?? '')));
        $run_id = self::resolve_public_run_id($run_id, 'google_intel', $request_id, 'google_auth', 'public_run_token_google_poll');
        if (!$run_id || !preg_match('/^[A-Za-z0-9]+$/', $run_id) || strlen($run_id) > 64) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'google_validation', ['stage' => 'run_id']);
        }
        self::require_standard_run_access($run_id, 'google_intel', $request_id, 'google_auth', 'run_scope_poll');
        $owner = get_transient(self::standard_run_owner_key($run_id));
        $usage_key = is_array($owner) ? sanitize_text_field((string) ($owner['usage_key'] ?? '')) : '';

        $formatted = VES_Google_Intel::fetch_response($module_key, $run_id, 20);
        if (is_wp_error($formatted)) {
            $google_poll_error_data = $formatted->get_error_data();
            $google_poll_error_data = is_array($google_poll_error_data) ? $google_poll_error_data : [];
            self::error($formatted->get_error_message(), (int) ($google_poll_error_data['status'] ?? 500), $request_id, 'google_actor_poll', array_merge($google_poll_error_data, [
                'stage' => 'fetch_response',
                'run_id' => $run_id,
                'error_code' => $formatted->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => true,
            ]));
        }
        $status = (string) ($formatted['status'] ?? 'UNKNOWN');
        if ($status === 'SUCCEEDED' && $usage_key !== '') {
            self::post_standard_usage_or_error($usage_key, $request_id, 'google_usage', 'Google Intelligence scrape confirmado');
            self::remember_standard_run_owner($run_id, 'google_intel', '');
            if (class_exists('VES_Temp_Memory')) {
                $formatted['memory_entry_id'] = VES_Temp_Memory::save_search('google_intel', $_POST, $formatted);
            }
        } elseif (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT', 'TIMEOUT'], true) && $usage_key !== '') {
            self::void_standard_usage($usage_key, 'Google Intelligence terminó sin completarse.');
            delete_transient(self::standard_run_owner_key($run_id));
        }
        $formatted['request_id'] = $request_id;
        self::success($formatted);
    }

    public static function google_analyze() {
        $request_id = self::request_id();
        if (!check_ajax_referer('ves_nonce', 'nonce', false)) {
            self::error('Sesión caducada.', 403, $request_id, 'google_auth', ['stage' => 'nonce_analyze']);
        }
        if (!is_user_logged_in()) {
            self::error('Debes iniciar sesión para analizar.', 403, $request_id, 'google_auth', ['stage' => 'login_required_analyze']);
        }
        self::deny_if_role_forbidden($request_id, 'google_auth', 'role_google_analyze');
        if (!class_exists('VES_Google_Intel')) {
            self::error('Google Intelligence no está disponible.', 500, $request_id, 'google_config', ['stage' => 'class_missing_analyze']);
        }

        $rate_key = 'ves_google_ai_' . (is_user_logged_in() ? 'u_' . get_current_user_id() : 'ip_' . md5(ves_get_ip()));
        $current = (int) get_transient($rate_key);
        if ($current >= 6) {
            self::error('Has realizado demasiados análisis seguidos. Espera un minuto.', 429, $request_id, 'google_rate_limit', ['stage' => 'analyze']);
        }
        set_transient($rate_key, $current + 1, 60);

        $module_key = sanitize_key((string) (wp_unslash($_POST['googleModule'] ?? $_POST['module'] ?? 'search')));
        $module = VES_Google_Intel::get_module($module_key);
        if (!$module) {
            self::error('Módulo de Google Intelligence no válido.', 400, $request_id, 'google_validation', ['stage' => 'module_analyze']);
        }
        $run_id = sanitize_text_field((string) (wp_unslash($_POST['runId'] ?? '')));
        $run_id = self::resolve_public_run_id($run_id, 'google_intel', $request_id, 'google_auth', 'public_run_token_google_analyze');
        if (!$run_id || !preg_match('/^[A-Za-z0-9]+$/', $run_id) || strlen($run_id) > 64) {
            self::error('Identificador de proceso no válido.', 400, $request_id, 'google_validation', ['stage' => 'run_id_analyze']);
        }
        self::require_standard_run_access($run_id, 'google_intel', $request_id, 'google_auth', 'run_scope_analyze');

        $items = VES_Apify_Client::fetch_items($run_id, (int) ($module['fetch_limit'] ?? 50));
        if (is_wp_error($items)) {
            $google_items_error_data = $items->get_error_data();
            $google_items_error_data = is_array($google_items_error_data) ? $google_items_error_data : [];
            self::error($items->get_error_message(), (int) ($google_items_error_data['status'] ?? 500), $request_id, 'google_actor_items', array_merge($google_items_error_data, [
                'stage' => 'fetch_items_analyze',
                'run_id' => $run_id,
                'error_code' => $items->get_error_code(),
                'provider_call_attempted' => true,
                'provider_run_created' => true,
            ]));
        }
        $analysis_items = VES_Google_Intel::prepare_analysis_items($module_key, is_array($items) ? $items : [], 20);
        if (empty($analysis_items)) {
            self::error('No hay resultados legibles para analizar.', 400, $request_id, 'google_validation', ['stage' => 'empty_analysis_items']);
        }

        $mode = sanitize_key((string) (wp_unslash($_POST['analysisMode'] ?? 'summary')));
        $focus = sanitize_textarea_field((string) (wp_unslash($_POST['googleFocus'] ?? '')));
        if (strlen($focus) > 500) { $focus = substr($focus, 0, 500); }
        $output_language = self::sanitize_output_language(wp_unslash($_POST['outputLanguage'] ?? $_POST['uiLanguage'] ?? 'es'));

        $usage_key = self::reserve_standard_usage_or_error($request_id, 'ai_analysis', 'google_usage', [
            'platform' => 'google_intel',
            'module' => $module_key,
            'type' => 'google_intelligence_analysis',
        ]);
        $context_args = [
            'platform' => 'google_intel',
            'focus' => $focus,
            'topic' => $module['label'] ?? $module_key,
        ];
        $context_pack = self::get_ai_context_pack($context_args);
        $project_context = is_array($context_pack) ? ($context_pack['project_context'] ?? null) : null;
        $context_args['brand_name'] = is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '';
        $context_args['workspace_knowledge_terms'] = self::project_context_memory_terms($project_context);
        $related_memory = is_array($context_pack['related_memory'] ?? null) ? $context_pack['related_memory'] : self::get_related_memory_context($context_args);

        $analysis = VES_OpenAI_Client::analyze_google_intelligence($module_key, $module['label'] ?? $module_key, $analysis_items, $mode, $focus, $project_context, $related_memory, $output_language);
        if (is_wp_error($analysis)) {
            self::void_standard_usage($usage_key, $analysis->get_error_message());
            self::error($analysis->get_error_message(), 500, $request_id, 'google_openai', ['stage' => 'analyze']);
        }
        // Do not charge for a local fallback (no real provider completion) — mirrors
        // analyze_item. Only a confirmed AI completion settles the reservation.
        if (!empty($analysis['fallback'])) {
            self::void_standard_usage($usage_key, 'OpenAI fallback local; no provider completion.');
        } else {
            self::post_standard_usage_or_error($usage_key, $request_id, 'google_usage', 'Google Intelligence AI confirmado');
        }
        $analysis['request_id'] = $request_id;
        $analysis['project_context_used'] = !empty($project_context);
        $analysis['related_reports_used'] = is_array($related_memory) ? count($related_memory) : 0;
        $analysis = self::attach_ai_context_meta($analysis, $context_pack);
        if (class_exists('VES_AI_Context_Builder')) {
            $writeback = VES_AI_Context_Builder::writeback_after_analysis([
                'workspace_id' => $context_pack['workspace_id'] ?? 0,
                'user_id' => get_current_user_id(),
                'platform' => 'google_intel',
                'source_id' => $run_id,
                'request_id' => $request_id,
                'title' => 'Google Intelligence analysis: ' . ($module['label'] ?? $module_key),
                'analysis' => $analysis['analysis'] ?? '',
                'focus' => $focus,
                'brand_name' => is_array($project_context['project_profile'] ?? null) ? ($project_context['project_profile']['brand_name'] ?? '') : '',
                'used_context' => $analysis['used_context'] ?? '',
            ]);
            if (current_user_can('manage_options')) { $analysis['memory_writeback'] = $writeback; }
        }
        if (class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::save_ai_analysis('google_intel', ['module' => $module_key, 'run_id' => $run_id, 'items' => $analysis_items], $focus, $analysis);
        }
        self::success($analysis);
    }

}
