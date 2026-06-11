<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Provider_TikTok extends FIDTF_Provider_Apify {
    protected $source_key = 'tiktok';

    public function build_payload(array $source_plan, array $request): array {
        return FIDTF_Source_Dispatcher::build_canonical_payload('tiktok', $source_plan, $request);
    }

    public function dispatch(array $source_plan, array $request) {
        $payload = $this->build_payload($source_plan, $request);

        $preflight = FIDTF_Settings::live_preflight_status(['tiktok']);
        if (empty($preflight['tiktok_live_ready'])) {
            $reason = sanitize_key((string) (($preflight['blocking_reasons'][0] ?? '') ?: 'tiktok_live_not_ready'));
            $error_code = self::error_code_for_blocking_reason($reason);
            $message = self::message_for_blocking_reason($reason);
            return new WP_Error(
                $error_code,
                $message,
                ['status' => 202, 'retryable' => false, 'dispatch_attempted' => false, 'blocking_reason' => $reason, 'preflight' => $preflight]
            );
        }

        $adapter = new FIDTF_TikTok_Live_Adapter();
        return $adapter->start($payload);
    }



    private static function error_code_for_blocking_reason(string $reason): string {
        $reason = sanitize_key($reason);
        if ($reason === 'global_live_disabled') { return 'live_dispatch_disabled'; }
        if (in_array($reason, ['core_apify_unavailable', 'core_apify_client_unavailable', 'core_apify_client_incomplete', 'direct_token_missing', 'external_filter_missing', 'provider_not_ready'], true)) {
            return 'tiktok_provider_unavailable';
        }
        return $reason;
    }

    private static function message_for_blocking_reason(string $reason): string {
        switch (sanitize_key($reason)) {
            case 'global_live_disabled':
                return 'Global live dispatch is disabled. TikTok will remain planned-only.';
            case 'tiktok_live_bridge_disabled':
                return 'TikTok live bridge is disabled. Enable TikTok bridge in add-on settings to start live TikTok collection.';
            case 'core_apify_unavailable':
            case 'core_apify_client_unavailable':
            case 'core_apify_client_incomplete':
                return 'Core Apify client is not ready. TikTok will remain planned-only.';
            case 'direct_token_missing':
                return 'Direct Apify token is missing. TikTok will remain planned-only.';
            case 'external_filter_missing':
                return 'External TikTok provider filter is missing. TikTok will remain planned-only.';
            default:
                return 'TikTok live provider is not ready. This run is planned only.';
        }
    }

    public static function build_actor_input_from_payload(array $canonical_payload): array {
        return self::build_discovery_actor_input_from_payload($canonical_payload);
    }

    public static function build_discovery_actor_input_from_payload(array $canonical_payload): array {
        $source_plan = (array) ($canonical_payload['source_plan'] ?? []);
        $context = (array) ($canonical_payload['request_context'] ?? []);
        $limits = (array) ($canonical_payload['limits'] ?? []);

        $plan_queries = self::clean_list((array) ($source_plan['queries'] ?? []), 30);
        $context_keywords = self::clean_list((array) ($context['keywords'] ?? []), 30);
        $queries = self::clean_list(array_merge($plan_queries, $context_keywords), 30);
        $hashtags = self::clean_hashtags((array) ($source_plan['hashtags'] ?? []), 30);
        $profiles = self::clean_profiles((array) ($source_plan['creator_or_format_hints'] ?? []), 30);
        $language = sanitize_key((string) ($context['language'] ?? ($source_plan['language_hints'][0] ?? '')));
        $market = sanitize_text_field((string) ($context['market'] ?? ($source_plan['market_hints'][0] ?? '')));
        $date_range = sanitize_key((string) ($context['date_range'] ?? ''));
        $requested_limit = $limits['max_items'] ?? ($source_plan['max_items'] ?? null);
        $max_items = FIDTF_Settings::clamp_tiktok_limit($requested_limit);
        $sort_strategy = sanitize_text_field((string) ($source_plan['sort_strategy'] ?? 'relevance_then_engagement'));
        $actor_id = FIDTF_Settings::tiktok_discovery_actor_id();

        $primary_query = self::select_scraptik_search_keyword($plan_queries, $context, $hashtags, $context_keywords);
        $region = self::normalize_scraptik_region($market, $language);

        if (FIDTF_Settings::is_tiktok_enrichment_actor($actor_id)) {
            return self::build_scraptik_discovery_fallback_input($primary_query, $max_items, $sort_strategy, $region, $date_range);
        }

        if (self::actor_uses_apidojo_schema($actor_id)) {
            return self::build_apidojo_input($primary_query, $queries, $hashtags, $profiles, $max_items, $sort_strategy, $region, $date_range);
        }

        return self::build_clockworks_input($primary_query, $queries, $hashtags, $profiles, $max_items, $sort_strategy, $region, $date_range);
    }

    public static function build_scraptik_discovery_fallback_input(string $primary_query, int $max_items, string $sort_strategy, string $region, string $date_range): array {
        $input = self::scraptik_endpoint_defaults($max_items);
        $input['searchPosts_keyword'] = $primary_query;
        $input['searchPosts_count'] = $max_items;
        $input['searchPosts_offset'] = 0;
        $input['searchPosts_region'] = $region;
        $input['searchPosts_publishTime'] = self::scraptik_publish_time($date_range);
        $input['searchPosts_sortType'] = self::scraptik_sort_type($sort_strategy);
        return $input;
    }

    public static function build_scraptik_input(string $aweme_id, int $comments_count = 30, int $cursor = 0): array {
        return self::build_enrichment_actor_input($aweme_id, $comments_count, $cursor);
    }

    public static function build_enrichment_actor_input(string $aweme_id, int $comments_count = 30, int $cursor = 0): array {
        $aweme_id = sanitize_text_field(trim($aweme_id));
        $comments_count = max(1, min(100, $comments_count));
        $input = self::scraptik_endpoint_defaults($comments_count);
        $input['post_awemeId'] = $aweme_id;
        $input['listComments_awemeId'] = $aweme_id;
        $input['listComments_count'] = $comments_count;
        $input['listComments_cursor'] = max(0, (int) $cursor);
        $input['searchPosts_keyword'] = '';
        return $input;
    }

    public static function extract_aweme_id(array $item): string {
        foreach (['aweme_id', 'awemeId', 'id', 'external_id', 'video.id', 'aweme_detail.aweme_id'] as $key) {
            $value = self::value_for_key($item, $key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return sanitize_text_field((string) $value);
            }
        }
        return '';
    }

    public static function merge_enrichment_payload(array $item, array $enrichment_rows): array {
        $comments = [];
        foreach ($enrichment_rows as $row) {
            if (!is_array($row)) { continue; }
            if (isset($row['aweme_detail']) && is_array($row['aweme_detail'])) {
                $item['aweme_detail'] = $row['aweme_detail'];
            }
            foreach (['comments', 'comment_list', 'listComments', 'data.comments'] as $key) {
                $value = self::value_for_key($row, $key);
                if (!is_array($value)) { continue; }
                foreach ($value as $comment) {
                    if (is_string($comment)) {
                        $comments[] = $comment;
                    } elseif (is_array($comment)) {
                        $text = self::value_for_key($comment, 'text');
                        if (!is_scalar($text) || trim((string) $text) === '') { $text = self::value_for_key($comment, 'comment'); }
                        if (is_scalar($text) && trim((string) $text) !== '') { $comments[] = (string) $text; }
                    }
                    if (count($comments) >= 30) { break 2; }
                }
            }
        }
        if (!empty($comments)) {
            $existing = isset($item['comments']) && is_array($item['comments']) ? $item['comments'] : [];
            $item['comments'] = array_values(array_unique(array_merge($existing, $comments)));
            $item['comments_sample'] = $item['comments'];
        }
        return $item;
    }

    public static function scraptik_endpoint_defaults(int $limit = 25): array {
        $limit = max(1, min(300, $limit));
        return [
            'profile_username' => '',
            'profile_userId' => '',
            'profile_secUserId' => '',
            'usernameToId_username' => '',
            'followers_userId' => '',
            'followers_secUserId' => '',
            'followers_count' => 10,
            'followers_maxTime' => 0,
            'following_userId' => '',
            'following_secUserId' => '',
            'following_count' => 10,
            'following_maxTime' => 0,
            'post_awemeId' => '',
            'userPosts_userId' => '',
            'userPosts_secUserId' => '',
            'userPosts_count' => 10,
            'userPosts_maxCursor' => '0',
            'music_id' => '',
            'musicPosts_musicId' => '',
            'musicPosts_count' => 18,
            'musicPosts_cursor' => 0,
            'challengePosts_cid' => '',
            'challengePosts_count' => 20,
            'challengePosts_cursor' => 0,
            'commentReplies_commentId' => '',
            'commentReplies_awemeId' => '',
            'commentReplies_count' => 10,
            'commentReplies_cursor' => 0,
            'listComments_awemeId' => '',
            'listComments_count' => 10,
            'listComments_cursor' => 0,
            'userLikes_userId' => '',
            'userLikes_count' => 10,
            'userLikes_maxCursor' => '0',
            'searchUsers_keyword' => '',
            'searchUsers_count' => 20,
            'searchUsers_cursor' => 0,
            'searchPosts_offset' => 0,
            'searchPosts_publishTime' => 0,
            'searchSounds_keyword' => '',
            'searchSounds_count' => 10,
            'searchSounds_cursor' => 0,
            'searchSounds_useFilters' => false,
            'searchSounds_filterBy' => 0,
            'searchSounds_sortType' => 0,
            'searchHashtags_keyword' => '',
            'searchHashtags_count' => 20,
            'searchHashtags_cursor' => 0,
            'searchLives_keyword' => '',
            'searchLives_count' => 20,
            'searchLives_offset' => 0,
            'videoWithoutWatermark_awemeId' => '',
            'searchPosts_count' => $limit,
        ];
    }


    public static function normalize_scraptik_region($market, $language = ''): string {
        $market_raw = trim((string) $market);
        $language_raw = trim((string) $language);
        $market_key = self::ascii_lower($market_raw);
        $market_key = preg_replace('/[^a-z]/', '', $market_key);
        $aliases = [
            'spain' => 'ES',
            'espana' => 'ES',
            'spanien' => 'ES',
            'spagna' => 'ES',
            'sp' => 'ES',
            'es' => 'ES',
            'usa' => 'US',
            'unitedstates' => 'US',
            'unitedstatesofamerica' => 'US',
            'uk' => 'GB',
            'unitedkingdom' => 'GB',
            'greatbritain' => 'GB',
            'england' => 'GB',
            'mexico' => 'MX',
        ];
        if ($market_key !== '' && isset($aliases[$market_key])) { return $aliases[$market_key]; }
        if ($market_key !== '' && strlen($market_key) === 2) { return strtoupper($market_key); }

        $lang_key = self::ascii_lower($language_raw);
        $lang_key = preg_replace('/[^a-z]/', '', $lang_key);
        if ($lang_key === 'es') { return 'ES'; }
        if ($lang_key === 'en') { return 'US'; }
        if (strlen($lang_key) === 2) { return strtoupper($lang_key); }
        return 'ES';
    }

    public static function select_scraptik_search_keyword(array $queries, array $context = [], array $hashtags = [], array $fallback_keywords = []): string {
        $brand = trim((string) ($context['brand'] ?? ''));
        $company = trim((string) ($context['company'] ?? ''));
        foreach ([$brand, $company] as $candidate) {
            $candidate = trim(wp_strip_all_tags((string) $candidate));
            if ($candidate !== '') { return sanitize_text_field($candidate); }
        }
        $ranked = [];
        foreach ($queries as $query) {
            $query = trim(wp_strip_all_tags((string) $query));
            if ($query === '') { continue; }
            $ranked[] = $query;
        }
        usort($ranked, static function($a, $b) {
            $score_a = FIDTF_Provider_TikTok::search_keyword_score((string) $a);
            $score_b = FIDTF_Provider_TikTok::search_keyword_score((string) $b);
            if ($score_a === $score_b) { return strlen((string) $a) <=> strlen((string) $b); }
            return $score_b <=> $score_a;
        });
        if (!empty($ranked)) { return sanitize_text_field($ranked[0]); }
        $fallback_ranked = [];
        foreach ($fallback_keywords as $query) {
            $query = trim(wp_strip_all_tags((string) $query));
            if ($query !== '') { $fallback_ranked[] = $query; }
        }
        usort($fallback_ranked, static function($a, $b) {
            $score_a = FIDTF_Provider_TikTok::search_keyword_score((string) $a);
            $score_b = FIDTF_Provider_TikTok::search_keyword_score((string) $b);
            if ($score_a === $score_b) { return strlen((string) $a) <=> strlen((string) $b); }
            return $score_b <=> $score_a;
        });
        if (!empty($fallback_ranked)) { return sanitize_text_field($fallback_ranked[0]); }
        $fallback = trim((string) ($hashtags[0] ?? ''));
        return sanitize_text_field($fallback);
    }

    private static function search_keyword_score(string $query): int {
        $q = trim(self::ascii_lower($query));
        if ($q === '') { return -100; }
        $words = preg_split('/\s+/', $q) ?: [];
        $score = 0;
        $generic = ['trend', 'trends', 'industry', 'market', 'business', 'opportunities', 'find', 'around'];
        foreach ($words as $word) {
            if (in_array($word, $generic, true)) { $score -= 2; }
        }
        if (count($words) === 1) { $score += 2; }
        if (count($words) > 3) { $score -= 3; }
        if (strlen($q) >= 4 && strlen($q) <= 24) { $score += 2; }
        if (preg_match('/^[a-z0-9_]+$/', $q)) { $score += 1; }
        return $score;
    }

    private static function value_for_key(array $item, string $key) {
        if (array_key_exists($key, $item)) { return $item[$key]; }
        if (strpos($key, '.') === false) { return null; }
        $value = $item;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { return null; }
            $value = $value[$part];
        }
        if (is_array($value) && self::is_list_array($value)) {
            return $value[0] ?? null;
        }
        return $value;
    }

    private static function ascii_lower(string $value): string {
        $value = strtr($value, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','Á'=>'a','À'=>'a','Ä'=>'a','Â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','É'=>'e','È'=>'e','Ë'=>'e','Ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','Í'=>'i','Ì'=>'i','Ï'=>'i','Î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','Ó'=>'o','Ò'=>'o','Ö'=>'o','Ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','Ú'=>'u','Ù'=>'u','Ü'=>'u','Û'=>'u','ñ'=>'n','Ñ'=>'n']);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function flatten_scraptik_dataset_items($dataset_rows): array {
        if (!is_array($dataset_rows)) { return []; }
        $rows = self::is_list_array($dataset_rows) ? $dataset_rows : [$dataset_rows];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            self::collect_scraptik_items($row, $out, true);
            if (count($out) >= 1000) { break; }
        }
        return array_slice($out, 0, 1000);
    }

    public static function provider_dataset_row_count($dataset_rows): int {
        if (!is_array($dataset_rows)) { return 0; }
        return self::is_list_array($dataset_rows) ? count($dataset_rows) : 1;
    }

    private static function collect_scraptik_items(array $row, array &$out, bool $is_dataset_row = false): void {
        $row = self::canonicalize_tiktok_row($row);
        if (isset($row['search_item_list']) && is_array($row['search_item_list'])) {
            self::collect_scraptik_list($row['search_item_list'], $out);
            return;
        }
        foreach (['aweme_list', 'data', 'items', 'results', 'videos', 'posts'] as $key) {
            if (!isset($row[$key]) || !is_array($row[$key])) { continue; }
            $value = $row[$key];
            if (self::is_list_array($value)) {
                self::collect_scraptik_list($value, $out);
            } else {
                self::collect_scraptik_items($value, $out, false);
            }
            if (count($out) >= 1000) { return; }
        }
        if (self::looks_like_tiktok_post($row)) {
            $out[] = self::unwrap_scraptik_item($row);
        }
    }

    private static function collect_scraptik_list(array $items, array &$out): void {
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $unwrapped = self::unwrap_scraptik_item($item);
            if (self::looks_like_tiktok_post($unwrapped)) {
                $out[] = $unwrapped;
            } else {
                self::collect_scraptik_items($unwrapped, $out, false);
            }
            if (count($out) >= 1000) { return; }
        }
    }

    private static function unwrap_scraptik_item(array $item): array {
        foreach (['aweme_info', 'aweme', 'item'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) { return self::canonicalize_tiktok_row($item[$key]); }
        }
        return self::canonicalize_tiktok_row($item);
    }

    /**
     * v0.3.20: Apidojo's table view displays labels like "ID", "Title" and
     * "Post URL" while JSON exports may contain either those display labels or
     * their lower/camel-case field names. Normalize both shapes before the post
     * detector runs, otherwise valid provider rows can be flattened to zero.
     */
    private static function canonicalize_tiktok_row(array $row): array {
        $aliases = [
            'ID' => 'id',
            'Id' => 'id',
            'Title' => 'title',
            'Description' => 'description',
            'Caption' => 'caption',
            'Views' => 'views',
            'Likes' => 'likes',
            'Comments' => 'comments',
            'Shares' => 'shares',
            'Bookmarks' => 'bookmarks',
            'Uploaded At' => 'uploadedAt',
            'Uploaded At Formatted' => 'uploadedAtFormatted',
            'Post URL' => 'postPage',
            'Post Url' => 'postPage',
            'Channel Name' => 'channel.name',
            'Channel Username' => 'channel.username',
            'Channel ID' => 'channel.id',
            'Channel Id' => 'channel.id',
            'Channel URL' => 'channel.url',
            'Channel Url' => 'channel.url',
            'Channel Avatar' => 'channel.avatar',
            'Channel Verified' => 'channel.verified',
            'Song ID' => 'song.id',
            'Song Id' => 'song.id',
            'Song Title' => 'song.title',
            'post_url' => 'postPage',
            'channel_name' => 'channel.name',
            'channel_username' => 'channel.username',
            'channel_id' => 'channel.id',
            'channel_url' => 'channel.url',
            'channel_avatar' => 'channel.avatar',
            'channel_verified' => 'channel.verified',
            'uploaded_at_formatted' => 'uploadedAtFormatted',
        ];
        foreach ($aliases as $from => $to) {
            if (!array_key_exists($from, $row) || $row[$from] === null || $row[$from] === '') { continue; }
            if (strpos($to, '.') === false) {
                if (!array_key_exists($to, $row) || $row[$to] === null || $row[$to] === '') { $row[$to] = $row[$from]; }
                continue;
            }
            $parts = explode('.', $to);
            $ref =& $row;
            foreach ($parts as $index => $part) {
                if ($index === count($parts) - 1) {
                    if (!isset($ref[$part]) || $ref[$part] === '') { $ref[$part] = $row[$from]; }
                    break;
                }
                if (!isset($ref[$part]) || !is_array($ref[$part])) { $ref[$part] = []; }
                $ref =& $ref[$part];
            }
            unset($ref);
        }
        return $row;
    }

    private static function looks_like_tiktok_post(array $item): bool {
        foreach (['aweme_id', 'id', 'ID', 'desc', 'description', 'caption', 'text', 'title', 'Title', 'share_url', 'url', 'web_url', 'postPage', 'Post URL', 'author', 'channel', 'Channel Username', 'statistics', 'stats', 'views', 'Views', 'video'] as $key) {
            if (array_key_exists($key, $item)) { return true; }
        }
        return false;
    }

    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    private static function scraptik_sort_type(string $sort_strategy): int {
        $sort_strategy = sanitize_key($sort_strategy);
        if (strpos($sort_strategy, 'recent') !== false || strpos($sort_strategy, 'fresh') !== false) { return 1; }
        if (strpos($sort_strategy, 'engagement') !== false || strpos($sort_strategy, 'popular') !== false) { return 0; }
        return 0;
    }

    public static function scraptik_publish_time(string $date_range): int {
        $range = sanitize_key($date_range);
        // Scraptik uses numeric publish time buckets. Keep 0 as the safest
        // default because Actor docs do not guarantee the same date constants as
        // other TikTok Actors; search freshness is scored downstream.
        if (in_array($range, ['last_24_hours', 'today', '24h'], true)) { return 1; }
        if (in_array($range, ['last_7_days', 'week', '7d'], true)) { return 7; }
        if (in_array($range, ['last_30_days', 'month', '30d'], true)) { return 30; }
        return 0;
    }

    public static function minimum_view_count(): int {
        return FIDTF_Settings::tiktok_min_views();
    }

    public static function actor_uses_clockworks_schema(string $actor_id): bool {
        $actor = self::ascii_lower($actor_id);
        return strpos($actor, 'clockworks/tiktok-scraper') !== false || strpos($actor, 'clockworks/free-tiktok-scraper') !== false;
    }

    public static function actor_uses_apidojo_schema(string $actor_id): bool {
        return strpos(self::ascii_lower($actor_id), 'apidojo/tiktok-scraper') !== false;
    }

    public static function validate_discovery_actor_input(string $actor_id, array $actor_input) {
        if (!self::actor_uses_apidojo_schema($actor_id)) { return true; }
        // apidojo/tiktok-scraper requires at least one of: keywords, startUrls.
        $has_keywords = self::has_non_empty_list_or_string($actor_input, 'keywords');
        $has_start_urls = self::has_non_empty_list_or_string($actor_input, 'startUrls');
        if ($has_keywords || $has_start_urls) { return true; }
        $message = 'Selected TikTok actor is apidojo/tiktok-scraper, but generated input does not contain a "keywords" or "startUrls" field.';
        return new WP_Error('invalid_apidojo_tiktok_input', $message, [
            'status' => 400,
            'retryable' => false,
            'dispatch_attempted' => false,
            'request_attempted' => false,
            'provider_mode_used' => 'tiktok_discovery',
            'outcome_reason' => 'invalid_actor_input',
            'discovery_actor_id' => sanitize_text_field($actor_id),
            'enrichment_actor_id' => FIDTF_Settings::tiktok_enrichment_actor_id(),
            'discovery_actor_input' => $actor_input,
        ]);
    }

    public static function build_clockworks_input(string $primary_query, array $queries, array $hashtags, array $profiles, int $max_items, string $sort_strategy, string $region, string $date_range): array {
        $post_urls = [];
        $text_queries = [];
        foreach (array_values(array_unique(array_filter(array_merge([$primary_query], $queries)))) as $query) {
            $query = trim((string) $query);
            if ($query === '') { continue; }
            if (preg_match('#^https?://#i', $query)) {
                $post_urls[] = esc_url_raw($query);
            } else {
                $text_queries[] = sanitize_text_field($query);
            }
        }
        // v0.3.16: Discovery runs must stay cheap and stable. Transcription
        // and video/cover downloads are expensive and cause memory-limit failures
        // on discovery runs. Mirror the main plugin's approach: keep downloads off
        // for keyword/hashtag discovery; apply view-count filter locally after
        // collection (same pattern as VES platform-input.php v0.9.24.15).
        // NOTE: do NOT pass shouldDownloadVideos or shouldDownloadCovers at all —
        // the clockworks actor rejects explicit false values in search mode with
        // HTTP 400. The main plugin omits them entirely and lets the actor default.
        $input = [
            'searchQueries' => array_values(array_unique($text_queries)),
            'hashtags' => array_values(array_unique($hashtags)),
            'profiles' => array_values(array_unique($profiles)),
            'postURLs' => array_values(array_unique($post_urls)),
            'resultsPerPage' => $max_items,
            'searchSection' => '/video',
            // v0.3.31 live QA: clockworks/tiktok-scraper now validates
            // videoSearchSorting as string enums: MOST_RELEVANT, MOST_LIKED, LATEST.
            // The older numeric values 0/1 are rejected by the live actor.
            'videoSearchSorting' => self::clockworks_video_search_sorting($sort_strategy),
            'proxyCountryCode' => $region !== '' ? $region : 'ES',
            // Subtitle/transcription: always off for discovery. The main plugin
            // (platform-input.php) uses NEVER_DOWNLOAD_SUBTITLES for all non-post-URL
            // modes and this is the safe, cheap default.
            'downloadSubtitlesOptions' => 'NEVER_DOWNLOAD_SUBTITLES',
        ];
        // v0.3.31: do not send the old numeric searchDatePosted field. The live
        // actor schema only documents videoSearchDateFilter for video search.
        $video_date_filter = self::clockworks_video_search_date_filter($date_range);
        if ($video_date_filter !== 'ALL_TIME') {
            $input['videoSearchDateFilter'] = $video_date_filter;
        }
        return array_filter($input, static function($value) {
            if (is_array($value)) { return !empty($value); }
            return $value !== null && $value !== '';
        });
    }

    /**
     * Maps a date_range slug to the current Clockworks videoSearchDateFilter enum.
     * This is the preferred date field in newer versions of clockworks/tiktok-scraper.
     */
    private static function clockworks_video_search_date_filter(string $date_range): string {
        $range = sanitize_key($date_range);
        if (in_array($range, ['last_24_hours', 'today', '24h', '1d'], true)) { return 'PAST_24_HOURS'; }
        if (in_array($range, ['last_7_days', 'week', '7d'], true)) { return 'PAST_WEEK'; }
        if (in_array($range, ['last_30_days', 'month', '30d', 'this_month'], true)) { return 'PAST_MONTH'; }
        if (in_array($range, ['last_90_days', 'quarter', '90d'], true)) { return 'LAST_3_MONTHS'; }
        if (in_array($range, ['last_180_days', '180d'], true)) { return 'LAST_6_MONTHS'; }
        return 'ALL_TIME';
    }

    public static function build_apidojo_input(string $primary_query, array $queries, array $hashtags, array $profiles, int $max_items, string $sort_strategy, string $region, string $date_range): array {
        $start_urls = [];
        $keywords = [];
        foreach (array_values(array_unique(array_filter(array_merge([$primary_query], $queries, $hashtags, $profiles)))) as $query) {
            $query = trim((string) $query);
            if ($query === '') { continue; }
            if (preg_match('#^https?://#i', $query)) {
                $start_urls[] = esc_url_raw($query);
                continue;
            }
            $clean = ltrim(wp_strip_all_tags($query), '#@');
            $clean = trim($clean);
            if ($clean !== '') { $keywords[] = sanitize_text_field($clean); }
        }
        // v0.3.18 — CRITICAL FIX. The apidojo/tiktok-scraper actor only accepts
        // these input fields: customMapFunction, dateRange, includeSearchKeywords,
        // location, maxItems, sortType, startUrls, keywords. The previous build
        // sent `searchKeywords` (NOT a recognized field) which caused the actor
        // to reject every run with HTTP 400. This now mirrors the main plugin's
        // VES platform-input.php apidojo contract exactly.
        $input = [
            'customMapFunction' => '(object) => { return {...object} }',
            'dateRange' => self::apidojo_date_range($date_range),
            'includeSearchKeywords' => false,
            'location' => $region !== '' ? strtoupper($region) : 'ES',
            'maxItems' => $max_items,
            'sortType' => self::apidojo_sort_type($sort_strategy),
        ];
        // Correct field name is `keywords`. Cap at 5 to keep the search focused
        // and cheap (same as the main plugin's array_slice($keywords, 0, 5)).
        $keywords = array_values(array_unique($keywords));
        if (!empty($keywords)) {
            $input['keywords'] = array_slice($keywords, 0, 5);
        }
        $start_urls = array_values(array_unique($start_urls));
        if (!empty($start_urls)) {
            $input['startUrls'] = $start_urls;
        }
        return array_filter($input, static function($value) {
            if (is_array($value)) { return !empty($value); }
            return $value !== null && $value !== '';
        });
    }

    private static function has_non_empty_list_or_string(array $input, string $key): bool {
        if (!array_key_exists($key, $input)) { return false; }
        $value = $input[$key];
        if (is_array($value)) { return count(array_filter($value, static function($item) { return trim((string) $item) !== ''; })) > 0; }
        return trim((string) $value) !== '';
    }

    /**
     * v0.3.18 — apidojo/tiktok-scraper dateRange enum.
     * Valid values ONLY: DEFAULT, YESTERDAY, THIS_WEEK, THIS_MONTH,
     * LAST_THREE_MONTHS, LAST_SIX_MONTHS. The previous LAST_24_HOURS / LAST_WEEK /
     * LAST_MONTH values were invalid and contributed to HTTP 400 rejections.
     * TikTok date buckets are coarse; the exact cutoff is enforced locally after
     * collection (same approach as the main plugin's ves_apidojo_tiktok_date_range).
     */
    private static function apidojo_date_range(string $date_range): string {
        $range = sanitize_key($date_range);
        if (in_array($range, ['last_24_hours', 'today', '24h', '1d', 'yesterday'], true)) { return 'YESTERDAY'; }
        if (in_array($range, ['last_7_days', 'week', '7d'], true)) { return 'THIS_WEEK'; }
        if (in_array($range, ['last_14_days', '14d'], true)) { return 'LAST_THREE_MONTHS'; }
        if (in_array($range, ['last_30_days', 'month', '30d', 'this_month'], true)) { return 'THIS_MONTH'; }
        if (in_array($range, ['last_60_days', '60d', 'last_90_days', 'quarter', '90d'], true)) { return 'LAST_THREE_MONTHS'; }
        if (in_array($range, ['last_180_days', '180d', 'last_365_days', '365d', 'year'], true)) { return 'LAST_SIX_MONTHS'; }
        return 'LAST_SIX_MONTHS';
    }

    /**
     * v0.3.18 — apidojo/tiktok-scraper sortType enum.
     * Valid values ONLY: RELEVANCE, DATE_POSTED, MOST_LIKED. The previous 'DATE'
     * value was invalid and could trigger HTTP 400 input-validation rejection.
     * NOTE: "relevance" is checked first because the default strategy
     * "relevance_then_engagement" contains both words — relevance is the
     * primary sort and must win.
     */
    private static function apidojo_sort_type(string $sort_strategy): string {
        $sort = sanitize_key($sort_strategy);
        if (strpos($sort, 'relevance') !== false) {
            return 'RELEVANCE';
        }
        if (strpos($sort, 'recent') !== false || strpos($sort, 'fresh') !== false || strpos($sort, 'latest') !== false || strpos($sort, 'date') !== false) {
            return 'DATE_POSTED';
        }
        if (strpos($sort, 'like') !== false || strpos($sort, 'digg') !== false || strpos($sort, 'top_metric') !== false || strpos($sort, 'engagement') !== false || strpos($sort, 'popular') !== false) {
            return 'MOST_LIKED';
        }
        return 'RELEVANCE';
    }

    private static function clockworks_video_search_sorting(string $sort_strategy): string {
        $sort = sanitize_key($sort_strategy);
        // Relevance must win for the default strategy `relevance_then_engagement`.
        if (strpos($sort, 'relevance') !== false || strpos($sort, 'relevant') !== false) { return 'MOST_RELEVANT'; }
        if (strpos($sort, 'recent') !== false || strpos($sort, 'fresh') !== false || strpos($sort, 'latest') !== false || strpos($sort, 'date') !== false) { return 'LATEST'; }
        if (strpos($sort, 'like') !== false || strpos($sort, 'digg') !== false || strpos($sort, 'engagement') !== false || strpos($sort, 'popular') !== false) { return 'MOST_LIKED'; }
        return 'MOST_RELEVANT';
    }

    private static function clean_list($value, int $limit): array {
        if (is_string($value)) { $value = preg_split('/\r\n|\r|\n|,/', $value); }
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item !== '') { $out[] = sanitize_text_field($item); }
            if (count($out) >= $limit) { break; }
        }
        return array_values(array_unique($out));
    }


    private static function clean_profiles($value, int $limit): array {
        $profiles = [];
        foreach (self::clean_list($value, $limit) as $profile) {
            $profile = trim((string) $profile);
            if ($profile === '' || preg_match('#^https?://#i', $profile)) { continue; }
            $profile = ltrim($profile, '@');
            if ($profile !== '') { $profiles[] = sanitize_text_field($profile); }
        }
        return array_values(array_unique($profiles));
    }

    private static function clean_hashtags($value, int $limit): array {
        $out = [];
        foreach (self::clean_list($value, $limit) as $tag) {
            $tag = ltrim($tag, '#');
            if ($tag !== '') { $out[] = $tag; }
        }
        return array_values(array_unique($out));
    }
}
