<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('ves_truthy')) {
    function ves_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'y', 'on', 'si', 'sí'], true);
    }
}

if (!function_exists('ves_first_non_empty')) {
    function ves_first_non_empty(...$values) {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return $value;
        }
        return '';
    }
}

if (!function_exists('ves_arr_get')) {
    function ves_arr_get($data, $path, $default = null) {
        if (!is_array($data) || !$path) {
            return $default;
        }
        $segments = is_array($path) ? $path : explode('.', (string) $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }
            if (is_array($current) && ctype_digit((string) $segment)) {
                $index = (int) $segment;
                if (array_key_exists($index, $current)) {
                    $current = $current[$index];
                    continue;
                }
            }
            return $default;
        }
        return $current;
    }
}

if (!function_exists('ves_normalize_number')) {
    function ves_normalize_number($value) {
        // v0.9.24.3: Apify occasionally returns -1 for unavailable metrics.
        // All display/filter metric values must be >= 0.
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }
        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return 0;
            }
            $raw = str_replace([',', ' '], ['', ''], $raw);
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([kmb])$/i', $raw, $m)) {
                $num = (float) $m[1];
                $mul = strtolower($m[2]);
                if ($mul === 'k') $num *= 1000;
                if ($mul === 'm') $num *= 1000000;
                if ($mul === 'b') $num *= 1000000000;
                return max(0, (int) round($num));
            }
            if (is_numeric($raw)) {
                return max(0, (int) round((float) $raw));
            }
        }
        return 0;
    }
}


if (!function_exists('ves_parse_social_metric_number')) {
    /**
     * Parse a public social metric without inventing a value.
     * Returns null when the value is missing/invalid; zero remains a valid metric.
     */
    function ves_parse_social_metric_number($value) {
        if ($value === null || $value === false || is_array($value) || is_object($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || (float) $value < 0) {
                return null;
            }
            return (int) round((float) $value);
        }
        if (!is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        $lower = strtolower($raw);
        if (in_array($lower, ['n/a', 'na', 'none', 'null', 'undefined', '-', '—', 'sin datos'], true)) {
            return null;
        }
        $raw = preg_replace('/[\s,]+/u', '', $raw);
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([kmb])$/i', $raw, $m)) {
            $num = (float) $m[1];
            $mul = strtolower($m[2]);
            if ($mul === 'k') { $num *= 1000; }
            if ($mul === 'm') { $num *= 1000000; }
            if ($mul === 'b') { $num *= 1000000000; }
            return (int) round(max(0, $num));
        }
        if (is_numeric($raw)) {
            $num = (float) $raw;
            return $num >= 0 ? (int) round($num) : null;
        }
        return null;
    }
}

if (!function_exists('ves_first_social_metric_value')) {
    function ves_first_social_metric_value($item, array $aliases) {
        $item = is_array($item) ? $item : [];
        $path_prefixes = [
            'metrics',
            'raw_preview_payload.metrics',
            'raw_preview_payload',
            'selected_preview_payload.metrics',
            'selected_preview_payload',
            'ves_pre_enrichment_item.metrics',
            'ves_pre_enrichment_item',
            '',
            'raw.metrics',
            'raw',
            'stats',
            'statistics',
            'video',
            'itemInfos.itemStruct.stats',
            'statsV2',
        ];
        foreach ($path_prefixes as $prefix) {
            foreach ($aliases as $alias) {
                $path = $prefix === '' ? $alias : $prefix . '.' . $alias;
                $value = ves_arr_get($item, $path, null);
                if ($value === null && $prefix === '' && array_key_exists($alias, $item)) {
                    $value = $item[$alias];
                }
                $parsed = ves_parse_social_metric_number($value);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        return null;
    }
}

if (!function_exists('ves_normalize_social_metrics')) {
    /**
     * Canonical source of truth for social metrics used by previews, selected-item
     * analysis, evidence packs, prompts, local fallbacks and reports.
     */
    function ves_normalize_social_metrics($item) {
        $item = is_array($item) ? $item : [];
        $map = [
            'views' => ['views', 'views_or_plays', 'plays', 'playCount', 'viewCount', 'videoViewCount', 'videoPlayCount', 'viewsCount', 'ves_views'],
            'likes' => ['likes', 'diggCount', 'likeCount', 'likesCount', 'favoriteCount', 'ves_likes'],
            'comments' => ['comments', 'commentCount', 'comment_count', 'commentsCount', 'replyCount', 'ves_comments'],
            'shares' => ['shares', 'shareCount', 'share_count', 'sharesCount', 'retweetCount', 'ves_shares'],
            'saves' => ['saves', 'saves_or_collects', 'collects', 'collectCount', 'bookmarks', 'bookmarkCount', 'saveCount', 'save_count', 'repinCount', 'ves_saves'],
        ];
        $out = [];
        foreach ($map as $canonical => $aliases) {
            $out[$canonical] = ves_first_social_metric_value($item, $aliases);
        }
        $out['engagement_visible'] = false;
        foreach (['views', 'likes', 'comments', 'shares', 'saves'] as $metric_key) {
            if ($out[$metric_key] !== null) {
                $out['engagement_visible'] = true;
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('ves_normalize_duration')) {
    function ves_normalize_duration($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $seconds = (int) round((float) $value);
            if ($seconds > 1000 * 60 * 10) {
                $seconds = (int) round($seconds / 1000);
            }
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs) : sprintf('%d:%02d', $minutes, $secs);
        }
        return trim((string) $value);
    }
}

if (!function_exists('ves_normalize_date')) {
    function ves_normalize_date($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 20000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > 0) {
                return gmdate('Y-m-d H:i', $timestamp) . ' UTC';
            }
        }
        return sanitize_text_field((string) $value);
    }
}


if (!function_exists('ves_clean_template_text')) {
    function ves_clean_template_text($value) {
        if ($value === null || $value === false) {
            return '';
        }
        $text = wp_strip_all_tags((string) $value);
        // Some actors return unrendered placeholders such as {{product.name}}.
        // These are not usable creative data and must not leak into cards or AI prompts.
        $text = preg_replace('/\{\{\s*[^}]+\s*\}\}/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $lower = strtolower($text);
        if (in_array($lower, ['null', 'undefined', 'none', 'n/a', 'na', '-', '—'], true)) {
            return '';
        }
        if (!preg_match('/[\p{L}\p{N}]/u', $text)) {
            return '';
        }
        return $text;
    }
}

if (!function_exists('ves_text_from_mixed')) {
    function ves_text_from_mixed($value) {
        if ($value === null || $value === false) {
            return '';
        }
        if (is_scalar($value)) {
            return ves_clean_template_text($value);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                $txt = ves_text_from_mixed($entry);
                if ($txt !== '') {
                    $parts[] = $txt;
                }
                if (count($parts) >= 4) {
                    break;
                }
            }
            return trim(implode(' · ', array_values(array_unique($parts))));
        }
        return '';
    }
}

if (!function_exists('ves_first_text_path')) {
    function ves_first_text_path($item, array $paths) {
        foreach ($paths as $path) {
            $txt = ves_text_from_mixed(ves_arr_get($item, $path, null));
            if ($txt !== '') {
                return $txt;
            }
        }
        return '';
    }
}

if (!function_exists('ves_find_url_recursive')) {
    function ves_find_url_recursive($value, array $key_hints = [], $depth = 0) {
        if ($depth > 6) {
            return '';
        }
        if (is_string($value)) {
            $candidate = trim($value);
            if (preg_match('~^https?://~i', $candidate)) {
                return $candidate;
            }
            return '';
        }
        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $key => $child) {
            $key_l = strtolower((string) $key);
            $key_match = empty($key_hints);
            foreach ($key_hints as $hint) {
                if (strpos($key_l, strtolower((string) $hint)) !== false) {
                    $key_match = true;
                    break;
                }
            }
            if ($key_match) {
                $found = ves_find_url_recursive($child, [], $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        foreach ($value as $child) {
            $found = ves_find_url_recursive($child, $key_hints, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }
}

if (!function_exists('ves_find_image_url_recursive')) {
    function ves_find_image_url_recursive($value, $depth = 0, $from_hint = false) {
        if ($depth > 7) {
            return '';
        }
        if (is_string($value)) {
            $candidate = trim($value);
            if (!preg_match('~^https?://~i', $candidate)) {
                return '';
            }
            if ($from_hint && preg_match('~\.(mp4|mov|m3u8)(\?|$)~i', $candidate)) {
                return '';
            }
            $is_image_like = preg_match('~(\.jpg|\.jpeg|\.png|\.webp|\.gif)(\?|$)|/safe_image\.php|scontent\.|fbcdn\.net|ytimg\.com|ggpht\.com|googleusercontent\.com|twimg\.com|cdninstagram\.com|tiktokcdn\.com|muscdn\.com~i', $candidate);
            if ($is_image_like || $from_hint) {
                return $candidate;
            }
            return '';
        }
        if (!is_array($value)) {
            return '';
        }
        $preferred_keys = [
            'image', 'img', 'thumbnail', 'thumb', 'picture', 'photo', 'poster', 'cover', 'avatar', 'creative', 'media',
            'original_image_url', 'resized_image_url', 'imageurl', 'image_url', 'thumbnailurl', 'thumbnail_url',
            'coverurl', 'cover_url', 'dynamiccover', 'thumbnaildynamic', 'origincover'
        ];
        foreach ($value as $key => $child) {
            $key_l = strtolower((string) $key);
            $is_hint = false;
            foreach ($preferred_keys as $hint) {
                if (strpos($key_l, $hint) !== false) {
                    $is_hint = true;
                    break;
                }
            }
            if ($is_hint) {
                $found = ves_find_image_url_recursive($child, $depth + 1, true);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        foreach ($value as $child) {
            $found = ves_find_image_url_recursive($child, $depth + 1, false);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }
}
if (!function_exists('ves_fb_ads_impressions_value')) {
    function ves_fb_ads_impressions_value($item) {
        $direct = ves_first_non_empty(
            ves_arr_get($item, 'impressions', null),
            ves_arr_get($item, 'impressionsCount', null),
            ves_arr_get($item, 'impression_count', null),
            ves_arr_get($item, 'impressions_lower_bound', null),
            ves_arr_get($item, 'impressions_with_index.impressions_text', null),
            ves_arr_get($item, 'impressions_with_index.lower_bound', null),
            ves_arr_get($item, 'impressions_with_index.lowerBound', null),
            ves_arr_get($item, 'impressions_with_index.min', null),
            ves_arr_get($item, 'impressions_with_index.0', null)
        );
        if ($direct !== '') {
            return ves_normalize_number($direct);
        }
        $obj = ves_arr_get($item, 'impressions_with_index', null);
        if (is_array($obj)) {
            foreach ($obj as $value) {
                $num = ves_normalize_number($value);
                if ($num > 0) {
                    return $num;
                }
            }
        }
        return 0;
    }
}

if (!function_exists('ves_is_actor_error_item')) {
    function ves_is_actor_error_item($item) {
        if (!is_array($item)) {
            return false;
        }
        $has_error = ves_first_text_path($item, ['error', 'errorDescription', 'error_description', 'requestErrorMessages.0', 'requestErrorMessages']) !== '';
        if (!$has_error) {
            return false;
        }
        // Actor error rows may include only the requested URL; that must not count as a usable result.
        $has_content = ves_first_text_path($item, [
            'title', 'name', 'text', 'fullText', 'caption', 'description', 'body',
            'ad_creative_bodies.0', 'ad_creative_bodies', 'adCreativeBody',
            'pageName', 'page_name', 'advertiserName', 'advertiser_name', 'ad_archive_id',
            'thumbnail', 'thumbnail_url', 'image', 'displayUrl', 'videoUrl',
            'snapshot.body.text', 'snapshot.cards.0.body', 'snapshot.cards.0.text',
            'snapshot.cards.0.title', 'snapshot.images.0.original_image_url'
        ]) !== '';
        $has_metric = ves_normalize_number(ves_first_non_empty(
            ves_arr_get($item, 'viewCount', null),
            ves_arr_get($item, 'playCount', null),
            ves_arr_get($item, 'likesCount', null),
            ves_arr_get($item, 'likeCount', null),
            ves_arr_get($item, 'commentCount', null),
            ves_arr_get($item, 'commentsCount', null),
            ves_arr_get($item, 'impressions', null),
            ves_arr_get($item, 'impressionsCount', null)
        )) > 0;
        return !$has_content && !$has_metric;
    }
}

if (!function_exists('ves_extract_actor_error_message')) {
    function ves_extract_actor_error_message($items) {
        if (!is_array($items)) {
            return '';
        }
        foreach ($items as $item) {
            if (!is_array($item) || !ves_is_actor_error_item($item)) {
                continue;
            }
            $err = ves_first_text_path($item, ['errorDescription', 'error_description', 'error', 'requestErrorMessages.0', 'requestErrorMessages']);
            if ($err !== '') {
                return sanitize_text_field(mb_substr($err, 0, 300));
            }
        }
        return '';
    }
}

if (!function_exists('ves_strip_actor_error_items')) {
    function ves_strip_actor_error_items($items) {
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter($items, static function ($item) {
            return !ves_is_actor_error_item($item);
        }));
    }
}

if (!function_exists('ves_item_has_display_payload')) {
    function ves_item_has_display_payload($platform, $item) {
        if (!is_array($item) || ves_is_actor_error_item($item)) {
            return false;
        }
        $content_paths = [
            'title', 'name', 'text', 'fullText', 'caption', 'description', 'body', 'message', 'postText', 'content',
            'shortCode', 'id', 'videoId', 'videoUrl', 'webVideoUrl', 'url', 'postUrl', 'permalink', 'facebookUrl',
            'ownerUsername', 'username', 'authorName', 'channelName', 'pageName', 'page_name', 'advertiserName',
            'fullName', 'headline', 'profileUrl', 'linkedinUrl', 'linkedInUrl', 'publicIdentifier', 'currentPosition', 'currentCompany', 'currentCompanyName', 'location',
            'communityName', 'parsedCommunityName', 'username', 'upVotes', 'numberOfComments', 'numberOfMembers', 'postKarma', 'commentKarma', 'dataType',
            'richSummary', 'seoTitle', 'seoDescription', 'altText', 'autoAltText', 'imageUrl', 'imageUrls.original', 'imageUrls.736x', 'videoUrl', 'pinner.username', 'pinner.name', 'user.username', 'user.name', 'saves', 'saveCount', 'repinCount',
            'agencyName', 'companyName', 'website', 'websiteUrl', 'domain', 'keyword', 'avgMonthlySearches', 'searchVolume', 'search_volume', 'volume', 'lowCPC', 'highCPC', 'competitionValue', 'intent', 'monetizationScore', 'domainRating', 'domainAuthority', 'pageAuthority', 'spamScore', 'organicTraffic', 'traffic', 'backlinks', 'services', 'industries', 'rating', 'reviewCount',
            'ad_archive_id', 'adArchiveId', 'archive_id', 'creativeId', 'advertiserId', 'adTransparencyUrl', 'previewUrls.0', 'format', 'creativeRegions.0', 'ad_creative_bodies.0', 'ad_creative_bodies',
            'adCreativeBody', 'ad_creative_link_titles.0', 'snapshot.body.text', 'snapshot.body.markup.__html',
            'snapshot.cards.0.body', 'snapshot.cards.0.text', 'snapshot.cards.0.title', 'snapshot.title',
            'snapshot.link_title', 'snapshot.link_description', 'displayUrl', 'thumbnailUrl', 'thumbnail_url',
            'image', 'videoMeta.coverUrl', 'videoMeta.originalCoverUrl', 'media.0.thumbnail',
            'media.0.image.uri', 'attachments.0.media.image.src', 'snapshot.images.0.original_image_url',
            'snapshot.images.0.resized_image_url', 'snapshot.videos.0.thumbnail_url'
        ];
        foreach ($content_paths as $path) {
            if (ves_first_text_path($item, [$path]) !== '') {
                return true;
            }
        }
        $metric_paths = [
            'viewCount','views','viewsCount','playCount','videoViewCount','videoPlayCount',
            'likes','likeCount','likesCount','diggCount','favoriteCount','commentCount','commentsCount',
            'comments','replyCount','numberOfComments','numberOfreplies','shareCount','shares','retweetCount','followersCount','postsCount','upVotes','numberOfMembers','postKarma','commentKarma','saves','saveCount','repinCount',
            'domainRating','domainAuthority','pageAuthority','spamScore','organicTraffic','traffic','backlinks','rating','reviewCount',
            'impressions','impressionsCount','impressions.lowerBound','regionStats.0.impressions.lowerBound','impressions_with_index.lower_bound'
        ];
        foreach ($metric_paths as $path) {
            if (ves_normalize_number(ves_arr_get($item, $path, null)) > 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ves_strip_unusable_items')) {
    function ves_strip_unusable_items($platform, $items) {
        if (!is_array($items)) {
            return [];
        }
        $clean = [];
        $platform_key = sanitize_key((string) $platform);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Instagram search can return hashtag/user/place directory rows. Those
            // rows may contain postsCount and therefore looked like valid content,
            // but they are not posts/reels and should not be rendered as results.
            // v0.9.24.13: TikTok profile containers may pass has_display_payload
            // via followersCount but are not real video posts. Skip them here;
            // ves_preprocess_tiktok_items should have unwrapped them already.
            if ($platform_key === 'tiktok') {
                // An item is a profile container (not a video) if it has
                // profile-level stats but no video URL or caption text.
                $has_video_identity = !empty($item['webVideoUrl']) || !empty($item['videoUrl'])
                    || (!empty($item['id']) && !empty($item['text']))
                    || (!empty($item['id']) && isset($item['diggCount']));
                // If not a video, check if it's a profile container with nested videos.
                if (!$has_video_identity) {
                    // Has nested video list → was not unwrapped → skip (don't render as card).
                    foreach (['videos', 'latestVideos', 'video_list', 'videoList', 'feed'] as $vk) {
                        if (!empty($item[$vk]) && is_array($item[$vk])) { continue 2; } // skip outer
                    }
                    // Also skip plain profile metadata objects with no recognizable content.
                    if (isset($item['authorMeta']['fans']) && !isset($item['text'])) { continue; }
                }
            }
            if ($platform_key === 'instagram' && function_exists('ves_is_instagram_hashtag_entity') && ves_is_instagram_hashtag_entity($item)) {
                $url = (string) ($item['url'] ?? $item['ves_url'] ?? '');
                $is_post_url = $url !== '' && preg_match('~instagram\.com/(?:p|reel|tv)/~i', $url);
                $has_real_post_identity = !empty($item['shortCode'])
                    || !empty($item['shortcode'])
                    || !empty($item['code'])
                    || $is_post_url
                    || (!empty($item['ownerUsername']) && (!empty($item['caption']) || !empty($item['displayUrl']) || !empty($item['ves_text'])));
                if (!$has_real_post_identity) {
                    continue;
                }
            }
            if (ves_item_has_display_payload($platform, $item)) {
                $clean[] = $item;
            }
        }
        return array_values($clean);
    }
}

if (!function_exists('ves_display_fallback_title')) {
    function ves_display_fallback_title($platform, $author = '', $id = '') {
        $platform = sanitize_key((string) $platform);
        $author = ves_clean_template_text($author);
        $id = ves_clean_template_text($id);
        switch ($platform) {
            case 'tiktok':       $label = 'TikTok video'; break;
            case 'youtube':      $label = 'YouTube video'; break;
            case 'facebook':     $label = 'Facebook post'; break;
            case 'instagram':    $label = 'Instagram result'; break;
            case 'facebook_ads': $label = $id !== '' ? 'Ad #' . $id : 'Facebook Ad'; break;
            case 'google_ads':   $label = $id !== '' ? 'Google Ad #' . $id : 'Google Ad'; break;
            case 'twitter':      $label = 'X post'; break;
            case 'linkedin':     $label = 'LinkedIn profile'; break;
            case 'reddit':       $label = 'Reddit result'; break;
            case 'pinterest':    $label = 'Pinterest pin'; break;
            case 'semrush':      $label = 'Semrush agency'; break;
            case 'moz':          $label = 'MOZ domain'; break;
            case 'ahrefs':       $label = 'Ahrefs result'; break;
            default:             $label = 'Result'; break;
        }
        return $author !== '' ? $author . ' — ' . $label : $label;
    }
}
if (!function_exists('ves_extract_text_from_plain_subtitles')) {
    function ves_extract_text_from_plain_subtitles($text) {
        $text = (string) $text;
        $text = preg_replace('/^WEBVTT.*$/mi', '', $text);
        $text = preg_replace('/^\d+$/m', '', $text);
        $text = preg_replace('/\d{2}:\d{2}:\d{2}[\.,]\d{3}\s+-->\s+\d{2}:\d{2}:\d{2}[\.,]\d{3}/', '', $text);
        $text = preg_replace('/\d{2}:\d{2}[\.,]\d{3}\s+-->\s+\d{2}:\d{2}[\.,]\d{3}/', '', $text);
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

if (!function_exists('ves_extract_text_from_json_payload')) {
    function ves_extract_text_from_json_payload($data) {
        if (is_string($data)) {
            return trim($data);
        }
        if (!is_array($data)) {
            return '';
        }
        $chunks = [];
        $keys = ['text', 'transcript', 'caption', 'value', 'utterance'];
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                $chunks[] = trim($data[$key]);
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $candidate = ves_extract_text_from_json_payload($value);
                if ($candidate !== '') {
                    $chunks[] = $candidate;
                }
            }
        }
        $chunks = array_values(array_unique(array_filter($chunks)));
        return trim(implode("\n", $chunks));
    }
}

if (!function_exists('ves_is_public_http_url')) {
    function ves_is_public_http_url($url) {
        $url = esc_url_raw((string) $url);
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) { return false; }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = trim($host, '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') { return false; }
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || substr($host, -6) === '.local' || substr($host, -9) === '.internal') { return false; }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved_v4 = function_exists('gethostbynamel') ? gethostbynamel($host) : false;
            if (is_array($resolved_v4)) { $ips = array_merge($ips, $resolved_v4); }
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, defined('DNS_AAAA') ? DNS_AAAA : 0);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!empty($record['ipv6'])) { $ips[] = (string) $record['ipv6']; }
                    }
                }
            }
        }

        foreach (array_values(array_unique(array_filter($ips))) as $ip) {
            $check_ip = $ip;
            if (preg_match('/^::ffff:([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$/i', $check_ip, $m)) {
                $check_ip = $m[1];
            }
            if (!filter_var($check_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('ves_remote_text_asset')) {
    function ves_remote_text_asset($url) {
        $url = esc_url_raw((string) $url);
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        // Deep-review fix: FAIL CLOSED — if the public-URL validator is not loaded
        // we refuse the fetch rather than skipping the SSRF guard.
        if (!function_exists('ves_is_public_http_url') || !ves_is_public_http_url($url)) {
            return '';
        }
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'text/plain, text/vtt, application/json, text/xml, application/xml;q=0.9, */*;q=0.1',
            ],
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return '';
        }
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (strpos($content_type, 'json') !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return ves_extract_text_from_json_payload($decoded);
            }
        }
        if (strpos($content_type, 'xml') !== false || preg_match('/\.xml($|\?)/i', $url)) {
            $body = wp_strip_all_tags($body, true);
            return ves_extract_text_from_plain_subtitles($body);
        }
        return ves_extract_text_from_plain_subtitles($body);
    }
}

if (!function_exists('ves_platform_metric_policy')) {
    /**
     * v0.9.10.1 — canonical ranking / threshold metric per platform.
     * TikTok + YouTube are judged by plays; Instagram, Facebook and X by likes.
     */
    function ves_platform_metric_policy($platform) {
        $platform = sanitize_key((string) $platform);
        if (in_array($platform, ['tiktok', 'youtube'], true)) {
            return [
                'filter_key'    => 'plays',
                'filter_label'  => 'Visualizaciones mínimas',
                'primary_key'   => 'plays',
                'primary_label' => 'Plays',
            ];
        }
        if (in_array($platform, ['instagram', 'facebook', 'twitter'], true)) {
            return [
                'filter_key'    => 'likes',
                'filter_label'  => 'Mínimo likes',
                'primary_key'   => 'likes',
                'primary_label' => 'Likes',
            ];
        }
        if ($platform === 'pinterest') {
            return [
                'filter_key'    => 'saves',
                'filter_label'  => 'Mínimo saves',
                'primary_key'   => 'saves',
                'primary_label' => 'Saves',
            ];
        }
        if ($platform === 'linkedin') {
            return [
                'filter_key'    => 'profiles',
                'filter_label'  => 'Mínimo perfiles',
                'primary_key'   => 'profiles',
                'primary_label' => 'Profiles',
            ];
        }
        if (in_array($platform, ['semrush', 'moz', 'ahrefs'], true)) {
            return [
                'filter_key'    => 'authority',
                'filter_label'  => 'Mínimo métrica SEO',
                'primary_key'   => 'authority',
                'primary_label' => $platform === 'semrush' ? 'Rating' : ($platform === 'moz' ? 'Authority' : 'SEO metric'),
            ];
        }
        if ($platform === 'reddit') {
            return [
                'filter_key'    => 'likes',
                'filter_label'  => 'Mínimo upvotes',
                'primary_key'   => 'likes',
                'primary_label' => 'Upvotes',
            ];
        }
        if (in_array($platform, ['facebook_ads', 'google_ads'], true)) {
            return [
                'filter_key'    => 'impressions',
                'filter_label'  => 'Mínimo impressions',
                'primary_key'   => 'impressions',
                'primary_label' => 'Impressions',
            ];
        }
        return [
            'filter_key'    => 'views',
            'filter_label'  => 'Visualizaciones mínimas',
            'primary_key'   => 'views',
            'primary_label' => 'Views',
        ];
    }
}

if (!function_exists('ves_metric_filter_label')) {
    function ves_metric_filter_label($platform) {
        $policy = ves_platform_metric_policy($platform);
        return (string) ($policy['filter_label'] ?? 'Visualizaciones mínimas');
    }
}

if (!function_exists('ves_item_filter_metric_value')) {
    function ves_item_filter_metric_value($item, $platform) {
        if (!is_array($item)) {
            return 0;
        }
        $policy = ves_platform_metric_policy($platform);
        $key = (string) ($policy['filter_key'] ?? 'views');
        if ($key === 'likes') {
            return ves_normalize_number(ves_first_non_empty(
                ves_arr_get($item, 'ves_primary_metric_key', '') === 'likes' ? ves_arr_get($item, 'ves_primary_metric_value', null) : null,
                ves_arr_get($item, 'ves_likes', null),
                ves_arr_get($item, 'likeCount', null),
                ves_arr_get($item, 'likesCount', null),
                ves_arr_get($item, 'likes', null),
                ves_arr_get($item, 'favoriteCount', null),
                ves_arr_get($item, 'reactionsCount', null),
                ves_arr_get($item, 'reactionCount', null),
                ves_arr_get($item, 'feedback.reaction_count', null)
            ));
        }
        if ($key === 'plays') {
            return ves_normalize_number(ves_first_non_empty(
                ves_arr_get($item, 'ves_primary_metric_key', '') === 'plays' ? ves_arr_get($item, 'ves_primary_metric_value', null) : null,
                ves_arr_get($item, 'ves_views', null),
                ves_arr_get($item, 'playCount', null),
                ves_arr_get($item, 'viewCount', null),
                ves_arr_get($item, 'views', null),
                ves_arr_get($item, 'viewsCount', null),
                ves_arr_get($item, 'videoViewCount', null),
                ves_arr_get($item, 'videoPlayCount', null),
                ves_arr_get($item, 'stats.playCount', null)
            ));
        }
        if ($key === 'impressions') {
            return ves_normalize_number(ves_first_non_empty(
                ves_arr_get($item, 'ves_primary_metric_value', null),
                ves_arr_get($item, 'impressions', null),
                ves_arr_get($item, 'impressionsCount', null),
                ves_arr_get($item, 'impressions_lower_bound', null),
                ves_arr_get($item, 'impressions_with_index.lower_bound', null),
                ves_arr_get($item, 'impressions_with_index.lowerBound', null),
                ves_arr_get($item, 'impressions_with_index.min', null)
            ));
        }
        return ves_normalize_number(ves_first_non_empty(
            ves_arr_get($item, 'ves_primary_metric_value', null),
            ves_arr_get($item, 'ves_views', null),
            ves_arr_get($item, 'viewCount', null),
            ves_arr_get($item, 'views', null),
            ves_arr_get($item, 'viewsCount', null)
        ));
    }
}

if (!function_exists('ves_item_has_filter_metric')) {
    function ves_item_has_filter_metric($item, $platform) {
        if (!is_array($item)) {
            return false;
        }
        $policy = ves_platform_metric_policy($platform);
        $key = (string) ($policy['filter_key'] ?? 'views');
        if ($key === 'likes') {
            // v0.9.31.3: when the normalizer explicitly marked both primary and like
            // metrics as unavailable (=0), the ves_likes aggregate is 0 because
            // ves_normalize_number always returns int — not because the count is zero.
            // Skip ves_likes and check only raw provider fields to avoid false positives
            // that discard items (e.g. Twitter tweets without viewCount/likeCount).
            if (array_key_exists('ves_has_primary_metric', $item) && !$item['ves_has_primary_metric']
                && array_key_exists('ves_has_like_metric', $item) && !$item['ves_has_like_metric']) {
                return ves_arr_get($item, 'likeCount', null) !== null
                    || ves_arr_get($item, 'likesCount', null) !== null
                    || ves_arr_get($item, 'likes', null) !== null
                    || ves_arr_get($item, 'diggCount', null) !== null
                    || ves_arr_get($item, 'favoriteCount', null) !== null
                    || ves_arr_get($item, 'reactionsCount', null) !== null
                    || ves_arr_get($item, 'reactionCount', null) !== null
                    || ves_arr_get($item, 'feedback.reaction_count', null) !== null;
            }
            return (!empty($item['ves_has_primary_metric']) && ves_arr_get($item, 'ves_primary_metric_key', '') === 'likes')
                || !empty($item['ves_has_like_metric'])
                || ves_arr_get($item, 'ves_likes', null) !== null
                || ves_arr_get($item, 'likeCount', null) !== null
                || ves_arr_get($item, 'likesCount', null) !== null
                || ves_arr_get($item, 'likes', null) !== null
                || ves_arr_get($item, 'favoriteCount', null) !== null
                || ves_arr_get($item, 'reactionsCount', null) !== null
                || ves_arr_get($item, 'reactionCount', null) !== null
                || ves_arr_get($item, 'feedback.reaction_count', null) !== null;
        }
        if ($key === 'plays') {
            return (!empty($item['ves_has_primary_metric']) && ves_arr_get($item, 'ves_primary_metric_key', '') === 'plays')
                || ves_arr_get($item, 'ves_views', null) !== null
                || ves_arr_get($item, 'playCount', null) !== null
                || ves_arr_get($item, 'viewCount', null) !== null
                || ves_arr_get($item, 'views', null) !== null
                || ves_arr_get($item, 'viewsCount', null) !== null
                || ves_arr_get($item, 'videoViewCount', null) !== null
                || ves_arr_get($item, 'videoPlayCount', null) !== null
                || ves_arr_get($item, 'stats.playCount', null) !== null;
        }
        if ($key === 'impressions') {
            return !empty($item['ves_has_primary_metric'])
                || ves_arr_get($item, 'impressions', null) !== null
                || ves_arr_get($item, 'impressionsCount', null) !== null
                || ves_arr_get($item, 'impressions_with_index', null) !== null;
        }
        return !empty($item['ves_has_primary_metric']) || ves_item_filter_metric_value($item, $platform) > 0;
    }
}

if (!function_exists('ves_items_have_filter_metric')) {
    function ves_items_have_filter_metric($items, $platform) {
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (is_array($item) && ves_item_has_filter_metric($item, $platform)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ves_sort_priority')) {
    function ves_sort_priority($src = []) {
        $priority = sanitize_key((string) ($src['sortPriority'] ?? 'relevance'));
        return in_array($priority, ['relevance', 'latest', 'top_metric', 'comments'], true) ? $priority : 'relevance';
    }
}

if (!function_exists('ves_item_timestamp_for_sort')) {
    function ves_item_timestamp_for_sort($item) {
        if (!is_array($item)) {
            return 0;
        }
        $candidates = [
            ves_arr_get($item, 'ves_date', ''),
            ves_arr_get($item, 'timestamp', ''),
            ves_arr_get($item, 'createdAt', ''),
            ves_arr_get($item, 'uploadedAt', ''),
            ves_arr_get($item, 'uploadedAtFormatted', ''),
            ves_arr_get($item, 'createTimeISO', ''),
            ves_arr_get($item, 'createTime', ''),
            ves_arr_get($item, 'date', ''),
            ves_arr_get($item, 'publishedAt', ''),
            ves_arr_get($item, 'uploadDate', ''),
            ves_arr_get($item, 'postedAt', ''),
            ves_arr_get($item, 'taken_at_timestamp', ''),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_numeric($candidate)) {
                $n = (int) $candidate;
                if ($n > 20000000000) {
                    $n = (int) floor($n / 1000);
                }
                return max(0, $n);
            }
            $ts = strtotime((string) $candidate);
            if ($ts !== false) {
                return (int) $ts;
            }
        }
        return 0;
    }
}

if (!function_exists('ves_item_comments_for_sort')) {
    function ves_item_comments_for_sort($item) {
        if (!is_array($item)) {
            return 0;
        }
        return ves_normalize_number(ves_first_non_empty(
            ves_arr_get($item, 'ves_comments', null),
            ves_arr_get($item, 'commentCount', null),
            ves_arr_get($item, 'commentsCount', null),
            ves_arr_get($item, 'comments', null),
            ves_arr_get($item, 'replyCount', null)
        ));
    }
}

if (!function_exists('ves_sort_items_by_user_priority')) {
    function ves_sort_items_by_user_priority($items, $src = [], $platform = '') {
        if (!is_array($items)) {
            return [];
        }
        $priority = ves_sort_priority($src);
        $platform = sanitize_key((string) ($platform ?: ($src['platform'] ?? '')));
        if ($priority === 'relevance') {
            return function_exists('ves_sort_items_by_relevance_desc') ? ves_sort_items_by_relevance_desc($items) : array_values($items);
        }
        $indexed = [];
        foreach (array_values($items) as $idx => $item) {
            $indexed[] = [$idx, $item];
        }
        usort($indexed, static function ($a, $b) use ($priority, $platform) {
            $ia = $a[0];
            $ib = $b[0];
            $x = is_array($a[1]) ? $a[1] : [];
            $y = is_array($b[1]) ? $b[1] : [];
            if ($priority === 'latest') {
                $cmp = ves_item_timestamp_for_sort($y) <=> ves_item_timestamp_for_sort($x);
                return $cmp !== 0 ? $cmp : ($ia <=> $ib);
            }
            if ($priority === 'top_metric') {
                $cmp = ves_item_filter_metric_value($y, $platform) <=> ves_item_filter_metric_value($x, $platform);
                return $cmp !== 0 ? $cmp : ($ia <=> $ib);
            }
            if ($priority === 'comments') {
                $cmp = ves_item_comments_for_sort($y) <=> ves_item_comments_for_sort($x);
                return $cmp !== 0 ? $cmp : ($ia <=> $ib);
            }
            return $ia <=> $ib;
        });
        return array_values(array_map(static function ($pair) { return $pair[1]; }, $indexed));
    }
}

if (!function_exists('ves_extract_transcript_text')) {
    function ves_extract_transcript_text($item, $context = []) {
        $direct = [
            ves_arr_get($item, 'ves_transcript', ''),
            ves_arr_get($item, 'transcript', ''),
            ves_arr_get($item, 'captionText', ''),
            ves_arr_get($item, 'caption_text', ''),
            ves_arr_get($item, 'subtitleText', ''),
        ];
        foreach ($direct as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }


        $remote_candidates = [
            ves_arr_get($item, 'videoMeta.transcriptionLink', ''),
            ves_arr_get($item, 'transcriptionLink', ''),
            ves_arr_get($item, 'transcriptUrl', ''),
            ves_arr_get($item, 'subtitleUrl', ''),
            ves_arr_get($item, 'subtitles', ''),
            ves_arr_get($item, 'videoMeta.subtitleLinks.0.downloadLink', ''),
            ves_arr_get($item, 'videoMeta.subtitleLinks.0.tiktokLink', ''),
            ves_arr_get($item, 'subtitles.0.url', ''),
        ];
        foreach ($remote_candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $text = ves_remote_text_asset($candidate);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }
}

if (!function_exists('ves_enrich_single_item_for_display')) {
    function ves_enrich_single_item_for_display($platform, $item, $context = []) {
        if (!is_array($item)) {
            return [];
        }

        $author = '';
        $title = '';
        $text = '';
        $url = '';
        $thumb = '';
        $views = 0;
        $likes = 0;
        $comments = 0;
        $shares = 0;
        $duration = '';
        $date = '';
        $primary_metric_value = 0;
        $metric_policy = ves_platform_metric_policy($platform);
        $primary_metric_label = (string) ($metric_policy['primary_label'] ?? 'Views');
        $primary_metric_key   = (string) ($metric_policy['primary_key'] ?? 'views');
        $has_primary_metric   = false;
        $has_like_metric      = false;

        switch ($platform) {
            case 'tiktok':
                $author = ves_first_non_empty(ves_arr_get($item, 'authorMeta.nickName', ''), ves_arr_get($item, 'authorMeta.name', ''), ves_arr_get($item, 'channel.name', ''), ves_arr_get($item, 'channel.username', ''));
                $text = ves_first_non_empty(ves_arr_get($item, 'text', ''), ves_arr_get($item, 'subtitle', ''), ves_arr_get($item, 'title', ''));
                $title = ves_first_non_empty(ves_arr_get($item, 'title', ''), mb_substr((string) $text, 0, 90));
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'webVideoUrl', ''), ves_arr_get($item, 'postPage', ''), ves_arr_get($item, 'submittedVideoUrl', ''));
                $thumb = ves_first_non_empty(
                    ves_arr_get($item, 'coverUrl', ''),
                    ves_arr_get($item, 'cover_url', ''),
                    ves_arr_get($item, 'thumbnailUrl', ''),
                    ves_arr_get($item, 'thumbnail_url', ''),
                    ves_arr_get($item, 'thumbnailDynamic', ''),
                    ves_arr_get($item, 'dynamicCover', ''),
                    ves_arr_get($item, 'originCover', ''),
                    ves_arr_get($item, 'videoMeta.coverUrl', ''),
                    ves_arr_get($item, 'videoMeta.originalCoverUrl', ''),
                    ves_arr_get($item, 'video.coverUrl', ''),
                    ves_arr_get($item, 'video.cover', ''),
                    ves_arr_get($item, 'video.thumbnailUrl', ''),
                    ves_arr_get($item, 'video.thumbnail', ''),
                    ves_arr_get($item, 'video.dynamicCover', ''),
                    ves_arr_get($item, 'video.originCover', ''),
                    ves_arr_get($item, 'channel.avatar', ''),
                    ves_arr_get($item, 'images.0.url', ''),
                    ves_find_image_url_recursive($item)
                );
                // v0.9.24.51: Apidojo returns flat `views/likes/comments/shares`;
                // Clockworks returns `playCount/diggCount/commentCount/shareCount`.
                // Prefer the exact actor fields before relying on cross-actor backfill,
                // because zero-valued normalized fields can mask raw values in the UI.
                $raw_views_tiktok = ves_first_non_empty(
                    ves_arr_get($item, 'views', null),
                    ves_arr_get($item, 'viewCount', null),
                    ves_arr_get($item, 'viewsCount', null),
                    ves_arr_get($item, 'playCount', null),
                    ves_arr_get($item, 'videoViewCount', null),
                    ves_arr_get($item, 'videoPlayCount', null),
                    ves_arr_get($item, 'stats.playCount', null),
                    ves_arr_get($item, 'stats.viewCount', null),
                    ves_arr_get($item, 'stats.views', null),
                    ves_arr_get($item, 'stats.videoViewCount', null),
                    ves_arr_get($item, 'video.playCount', null),
                    ves_arr_get($item, 'video.views', null),
                    ves_arr_get($item, 'itemInfos.itemStruct.stats.playCount', null),
                    ves_arr_get($item, 'statsV2.playCount', null)
                );
                $views = ves_normalize_number($raw_views_tiktok);
                $likes = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'likes', null), ves_arr_get($item, 'diggCount', null), ves_arr_get($item, 'likeCount', null), ves_arr_get($item, 'stats.diggCount', null), ves_arr_get($item, 'stats.likeCount', null)));
                $comments = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'comments', null), ves_arr_get($item, 'commentCount', null), ves_arr_get($item, 'commentsCount', null), ves_arr_get($item, 'stats.commentCount', null)));
                $shares = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'shares', null), ves_arr_get($item, 'shareCount', null), ves_arr_get($item, 'sharesCount', null), ves_arr_get($item, 'stats.shareCount', null)));
                $duration = ves_normalize_duration(ves_first_non_empty(ves_arr_get($item, 'videoMeta.duration', ''), ves_arr_get($item, 'video.duration', '')));
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'uploadedAt', ''), ves_arr_get($item, 'uploadedAtFormatted', ''), ves_arr_get($item, 'createTimeISO', ''), ves_arr_get($item, 'createTime', '')));
                break;

            case 'youtube':
                $author = ves_first_non_empty(ves_arr_get($item, 'channelName', ''), ves_arr_get($item, 'channelUsername', ''));
                $title = ves_first_non_empty(ves_arr_get($item, 'title', ''), ves_arr_get($item, 'channelName', 'Resultado'));
                $text = ves_first_non_empty(ves_arr_get($item, 'text', ''), ves_arr_get($item, 'translatedText', ''));
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'channelUrl', ''));
                $thumb = ves_first_non_empty(ves_arr_get($item, 'thumbnailUrl', ''), ves_arr_get($item, 'channelAvatarUrl', ''));
                $views = ves_normalize_number(ves_arr_get($item, 'viewCount', 0));
                $likes = ves_normalize_number(ves_arr_get($item, 'likes', 0));
                $comments = ves_normalize_number(ves_arr_get($item, 'commentsCount', 0));
                $shares = 0;
                $duration = ves_normalize_duration(ves_arr_get($item, 'duration', ''));
                $date = ves_normalize_date(ves_arr_get($item, 'date', ''));
                break;

            case 'facebook':
                $author = ves_first_non_empty(ves_arr_get($item, 'pageName', ''), ves_arr_get($item, 'user.name', ''));
                $text = ves_first_non_empty(ves_arr_get($item, 'text', ''), ves_arr_get($item, 'previewDescription', ''), ves_arr_get($item, 'title', ''));
                $title = mb_substr((string) $text, 0, 90);
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'facebookUrl', ''), ves_arr_get($item, 'link', ''));
                $thumb = ves_first_non_empty(
                    ves_arr_get($item, 'media.0.thumbnail', ''),
                    ves_arr_get($item, 'media.0.image.uri', ''),
                    ves_arr_get($item, 'media.0.photo_image.uri', ''),
                    ves_arr_get($item, 'user.profilePic', '')
                );
                $views = ves_normalize_number(ves_arr_get($item, 'viewsCount', 0));
                $likes = ves_normalize_number(ves_arr_get($item, 'likes', 0));
                $comments = ves_normalize_number(ves_arr_get($item, 'comments', 0));
                $shares = ves_normalize_number(ves_arr_get($item, 'shares', 0));
                $duration = ves_normalize_duration(ves_arr_get($item, 'media.0.playable_duration_in_ms', ''));
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'time', ''), ves_arr_get($item, 'timestamp', '')));
                break;

            case 'instagram':
                $author = ves_first_non_empty(ves_arr_get($item, 'ownerUsername', ''), ves_arr_get($item, 'ownerFullName', ''), ves_arr_get($item, 'username', ''));
                $text = ves_first_non_empty(ves_arr_get($item, 'caption', ''), ves_arr_get($item, 'firstComment', ''), ves_arr_get($item, 'biography', ''), ves_arr_get($item, 'name', ''));
                $title = mb_substr((string) $text, 0, 90);
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'inputUrl', ''), ves_arr_get($item, 'pageUrl', ''), ves_arr_get($item, 'permalink', ''));
                $shortcode = ves_first_non_empty(ves_arr_get($item, 'shortCode', ''), ves_arr_get($item, 'shortcode', ''), ves_arr_get($item, 'code', ''));
                if ($url === '' && $shortcode !== '') {
                    $url = 'https://www.instagram.com/p/' . rawurlencode((string) $shortcode) . '/';
                }
                $thumb = ves_first_non_empty(ves_arr_get($item, 'displayUrl', ''), ves_arr_get($item, 'display_url', ''), ves_arr_get($item, 'thumbnailUrl', ''), ves_arr_get($item, 'profilePicUrl', ''), ves_arr_get($item, 'images.0', ''), ves_arr_get($item, 'videoUrl', ''));
                $video_views_raw = ves_first_non_empty(ves_arr_get($item, 'videoViewCount', null), ves_arr_get($item, 'videoPlayCount', null), ves_arr_get($item, 'viewCount', null), ves_arr_get($item, 'viewsCount', null), ves_arr_get($item, 'views', null));
                $likes_raw = ves_first_non_empty(ves_arr_get($item, 'likesCount', null), ves_arr_get($item, 'likeCount', null), ves_arr_get($item, 'likes', null));
                $comments_raw = ves_first_non_empty(ves_arr_get($item, 'commentsCount', null), ves_arr_get($item, 'commentCount', null), ves_arr_get($item, 'comments', null));
                $shares_raw = ves_first_non_empty(ves_arr_get($item, 'sharesCount', null), ves_arr_get($item, 'shareCount', null), ves_arr_get($item, 'shares', null));
                $posts_count_raw = ves_first_non_empty(
                    ves_arr_get($item, 'postsCount', null),
                    ves_arr_get($item, 'posts', null),
                    ves_arr_get($item, 'metaData.postsCount', null),
                    ves_arr_get($item, 'metaData.posts', null)
                );
                $followers_raw = ves_first_non_empty(
                    ves_arr_get($item, 'followersCount', null),
                    ves_arr_get($item, 'metaData.followersCount', null)
                );
                $following_raw = ves_first_non_empty(
                    ves_arr_get($item, 'followsCount', null),
                    ves_arr_get($item, 'followingCount', null),
                    ves_arr_get($item, 'metaData.followsCount', null),
                    ves_arr_get($item, 'metaData.followingCount', null)
                );
                $views = ves_normalize_number($video_views_raw);
                $likes = ves_normalize_number($likes_raw);
                $comments = ves_normalize_number($comments_raw);
                $shares = ves_normalize_number($shares_raw);
                $duration = ves_normalize_duration(ves_arr_get($item, 'videoDuration', ''));
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'timestamp', ''), ves_arr_get($item, 'latestIgtvVideos.0.timestamp', '')));

                $primary_metric_value = $views;
                $primary_metric_label = 'Views';
                $primary_metric_key   = 'views';
                $has_primary_metric   = ($video_views_raw !== null);
                $has_like_metric      = ($likes_raw !== null);

                if (!$has_primary_metric && $posts_count_raw !== null) {
                    $primary_metric_value = ves_normalize_number($posts_count_raw);
                    $primary_metric_label = 'Posts';
                    $primary_metric_key   = 'posts';
                    $has_primary_metric   = true;
                } elseif (!$has_primary_metric && $followers_raw !== null) {
                    $primary_metric_value = ves_normalize_number($followers_raw);
                    $primary_metric_label = 'Followers';
                    $primary_metric_key   = 'followers';
                    $has_primary_metric   = true;
                } elseif (!$has_primary_metric && $following_raw !== null) {
                    $primary_metric_value = ves_normalize_number($following_raw);
                    $primary_metric_label = 'Following';
                    $primary_metric_key   = 'following';
                    $has_primary_metric   = true;
                }
                break;

            case 'reddit':
                $data_type = sanitize_key((string) ves_first_non_empty(ves_arr_get($item, 'dataType', ''), ves_arr_get($item, 'type', '')));
                $author = ves_first_non_empty(
                    ves_arr_get($item, 'username', ''),
                    ves_arr_get($item, 'author', ''),
                    ves_arr_get($item, 'userName', ''),
                    ves_arr_get($item, 'communityName', ''),
                    ves_arr_get($item, 'parsedCommunityName', '')
                );
                $title = ves_first_non_empty(
                    ves_arr_get($item, 'title', ''),
                    ves_arr_get($item, 'name', ''),
                    ves_arr_get($item, 'communityName', ''),
                    $data_type !== '' ? ucfirst($data_type) : 'Reddit result'
                );
                $text = ves_first_non_empty(
                    ves_arr_get($item, 'body', ''),
                    ves_arr_get($item, 'text', ''),
                    ves_arr_get($item, 'description', ''),
                    ves_arr_get($item, 'selftext', ''),
                    ves_arr_get($item, 'html', '')
                );
                $url = ves_first_non_empty(
                    ves_arr_get($item, 'url', ''),
                    ves_arr_get($item, 'postUrl', ''),
                    ves_arr_get($item, 'permalink', ''),
                    ves_arr_get($item, 'commentUrl', '')
                );
                $thumb = ves_first_non_empty(
                    ves_arr_get($item, 'thumbnail', ''),
                    ves_arr_get($item, 'headerImage', ''),
                    ves_arr_get($item, 'userIcon', ''),
                    ves_arr_get($item, 'image', ''),
                    ves_find_image_url_recursive($item)
                );
                $views = 0;
                $likes = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'upVotes', null), ves_arr_get($item, 'score', null), ves_arr_get($item, 'ups', null)));
                $comments = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'numberOfComments', null), ves_arr_get($item, 'numComments', null), ves_arr_get($item, 'commentsCount', null), ves_arr_get($item, 'numberOfreplies', null)));
                $shares = 0;
                $duration = '';
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'createdAt', ''), ves_arr_get($item, 'created_utc', ''), ves_arr_get($item, 'scrapedAt', '')));
                if ($data_type === 'community') {
                    $primary_metric_value = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'numberOfMembers', null), ves_arr_get($item, 'subscribers', null)));
                    $primary_metric_label = 'Members';
                    $primary_metric_key = 'members';
                    $has_primary_metric = $primary_metric_value > 0;
                } elseif ($data_type === 'user') {
                    $primary_metric_value = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'postKarma', null), ves_arr_get($item, 'commentKarma', null)));
                    $primary_metric_label = 'Karma';
                    $primary_metric_key = 'karma';
                    $has_primary_metric = $primary_metric_value > 0;
                } else {
                    $primary_metric_value = $likes;
                    $primary_metric_label = 'Upvotes';
                    $primary_metric_key = 'likes';
                    $has_primary_metric = true;
                    $has_like_metric = true;
                }
                break;

            case 'pinterest':
                $author = ves_first_non_empty(
                    ves_arr_get($item, 'pinner.full_name', ''),
                    ves_arr_get($item, 'pinner.fullName', ''),
                    ves_arr_get($item, 'pinner.username', ''),
                    ves_arr_get($item, 'pinner.name', ''),
                    ves_arr_get($item, 'owner.full_name', ''),
                    ves_arr_get($item, 'owner.fullName', ''),
                    ves_arr_get($item, 'owner.username', ''),
                    ves_arr_get($item, 'board.owner.full_name', ''),
                    ves_arr_get($item, 'board.owner.username', ''),
                    ves_arr_get($item, 'user.username', ''),
                    ves_arr_get($item, 'user.name', ''),
                    ves_arr_get($item, 'creator.username', ''),
                    ves_arr_get($item, 'creator.name', ''),
                    ves_arr_get($item, 'username', ''),
                    ves_arr_get($item, 'author', '')
                );
                $text = ves_first_non_empty(
                    ves_arr_get($item, 'description', ''),
                    ves_arr_get($item, 'richSummary', ''),
                    ves_arr_get($item, 'seoDescription', ''),
                    ves_arr_get($item, 'altText', ''),
                    ves_arr_get($item, 'autoAltText', '')
                );
                $title = ves_first_non_empty(
                    ves_arr_get($item, 'title', ''),
                    ves_arr_get($item, 'seoTitle', ''),
                    mb_substr((string) $text, 0, 90),
                    'Pinterest pin'
                );
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'pinUrl', ''), ves_arr_get($item, 'link', ''));
                $thumb = ves_first_non_empty(
                    ves_arr_get($item, 'imageUrl', ''),
                    ves_arr_get($item, 'imageUrls.original', ''),
                    ves_arr_get($item, 'imageUrls.736x', ''),
                    ves_arr_get($item, 'imageUrls.474x', ''),
                    ves_arr_get($item, 'imageUrls.236x', ''),
                    ves_arr_get($item, 'videoUrl', ''),
                    ves_find_image_url_recursive($item)
                );
                $views = 0;
                $likes = ves_normalize_number(ves_first_non_empty(
                    ves_arr_get($item, 'saves', null),
                    ves_arr_get($item, 'saveCount', null),
                    ves_arr_get($item, 'save_count', null),
                    ves_arr_get($item, 'repinCount', null),
                    ves_arr_get($item, 'repin_count', null),
                    ves_arr_get($item, 'aggregated_pin_data.repin_count', null),
                    ves_arr_get($item, 'likes', null),
                    ves_arr_get($item, 'likeCount', null),
                    ves_arr_get($item, 'like_count', null)
                ));
                $comments_list = ves_arr_get($item, 'commentsList', []);
                $comments = ves_normalize_number(ves_first_non_empty(
                    ves_arr_get($item, 'commentsCount', null),
                    ves_arr_get($item, 'commentCount', null),
                    ves_arr_get($item, 'comment_count', null),
                    ves_arr_get($item, 'aggregated_pin_data.comment_count', null),
                    is_array($comments_list) ? count($comments_list) : null
                ));
                $shares = ves_normalize_number(ves_first_non_empty(
                    ves_arr_get($item, 'repinCount', null),
                    ves_arr_get($item, 'repin_count', null),
                    ves_arr_get($item, 'aggregated_pin_data.repin_count', null),
                    ves_arr_get($item, 'shareCount', null),
                    ves_arr_get($item, 'share_count', null)
                ));
                $duration = ves_normalize_duration(ves_first_non_empty(ves_arr_get($item, 'videoDuration', ''), ves_arr_get($item, 'duration', '')));
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'createdAt', ''), ves_arr_get($item, 'created_at', ''), ves_arr_get($item, 'timestamp', '')));
                $primary_metric_value = $likes;
                $primary_metric_label = 'Saves';
                $primary_metric_key = 'saves';
                $has_primary_metric = $likes > 0;
                $has_like_metric = $likes > 0;
                break;

            case 'linkedin':
                $first = ves_first_non_empty(ves_arr_get($item, 'firstName', ''), ves_arr_get($item, 'first_name', ''));
                $last  = ves_first_non_empty(ves_arr_get($item, 'lastName', ''), ves_arr_get($item, 'last_name', ''));
                $author = ves_first_non_empty(ves_arr_get($item, 'fullName', ''), trim($first . ' ' . $last), ves_arr_get($item, 'name', ''));
                $title = ves_first_non_empty($author, ves_arr_get($item, 'title', 'LinkedIn profile'));
                $text = ves_first_non_empty(
                    ves_arr_get($item, 'headline', ''),
                    ves_arr_get($item, 'summary', ''),
                    ves_arr_get($item, 'about', ''),
                    ves_arr_get($item, 'currentPosition', ''),
                    ves_arr_get($item, 'currentCompany', ''),
                    ves_arr_get($item, 'currentCompanyName', ''),
                    ves_arr_get($item, 'location', '')
                );
                $url = ves_first_non_empty(ves_arr_get($item, 'profileUrl', ''), ves_arr_get($item, 'linkedinUrl', ''), ves_arr_get($item, 'linkedInUrl', ''), ves_arr_get($item, 'url', ''));
                if ($url === '') {
                    $public_id = ves_first_non_empty(ves_arr_get($item, 'publicIdentifier', ''), ves_arr_get($item, 'public_identifier', ''));
                    if ($public_id !== '') {
                        $url = 'https://www.linkedin.com/in/' . rawurlencode((string) $public_id) . '/';
                    }
                }
                $thumb = ves_first_non_empty(ves_arr_get($item, 'profilePicture', ''), ves_arr_get($item, 'profilePictureUrl', ''), ves_arr_get($item, 'profileImageUrl', ''), ves_arr_get($item, 'imageUrl', ''), ves_find_image_url_recursive($item));
                $views = 1;
                $primary_metric_value = 1;
                $primary_metric_label = 'Profiles';
                $primary_metric_key = 'profiles';
                $has_primary_metric = true;
                break;


            case 'semrush':
            case 'moz':
            case 'ahrefs':
                $keyword_value = ves_first_non_empty(ves_arr_get($item, 'keyword', ''), ves_arr_get($item, 'phrase', ''), ves_arr_get($item, 'query', ''));
                $author = ves_first_non_empty(ves_arr_get($item, 'name', ''), ves_arr_get($item, 'companyName', ''), ves_arr_get($item, 'agencyName', ''), ves_arr_get($item, 'domain', ''), ves_arr_get($item, 'url', ''));
                $title = ves_first_non_empty($keyword_value, $author, ves_arr_get($item, 'title', ''), $platform === 'semrush' ? 'Semrush result' : ($platform === 'moz' ? 'MOZ result' : 'Ahrefs result'));
                $text = ves_first_non_empty(
                    ves_arr_get($item, 'description', ''), ves_arr_get($item, 'summary', ''), ves_arr_get($item, 'services', ''), ves_arr_get($item, 'industries', ''), ves_arr_get($item, 'location', ''), ves_arr_get($item, 'country', ''), ves_arr_get($item, 'intent', ''), $keyword_value
                );
                $url = ves_first_non_empty(ves_arr_get($item, 'website', ''), ves_arr_get($item, 'websiteUrl', ''), ves_arr_get($item, 'url', ''), ves_arr_get($item, 'domain', ''));
                $thumb = ves_first_non_empty(ves_arr_get($item, 'logo', ''), ves_arr_get($item, 'image', ''), ves_find_image_url_recursive($item));
                $primary_metric_value = ves_normalize_number(ves_first_non_empty(
                    ves_arr_get($item, 'avgMonthlySearches', null), ves_arr_get($item, 'searchVolume', null), ves_arr_get($item, 'search_volume', null), ves_arr_get($item, 'volume', null), ves_arr_get($item, 'monthlySearchVolume', null), ves_arr_get($item, 'monetizationScore', null), ves_arr_get($item, 'domainRating', null), ves_arr_get($item, 'domain_rating', null), ves_arr_get($item, 'domainAuthority', null), ves_arr_get($item, 'domain_authority', null), ves_arr_get($item, 'pageAuthority', null), ves_arr_get($item, 'page_authority', null), ves_arr_get($item, 'authority', null), ves_arr_get($item, 'authority_score', null), ves_arr_get($item, 'rating', null), ves_arr_get($item, 'score', null), ves_arr_get($item, 'organicTraffic', null), ves_arr_get($item, 'organic_traffic', null), ves_arr_get($item, 'traffic', null), ves_arr_get($item, 'backlinks', null)
                ));
                $primary_metric_label = $platform === 'semrush' ? 'SEO metric' : ($platform === 'moz' ? 'Authority' : 'SEO metric');
                $primary_metric_key = 'authority';
                if ($platform === 'semrush') {
                    if (ves_first_non_empty(ves_arr_get($item, 'avgMonthlySearches', null), ves_arr_get($item, 'searchVolume', null), ves_arr_get($item, 'search_volume', null), ves_arr_get($item, 'volume', null), ves_arr_get($item, 'monthlySearchVolume', null)) !== '') {
                        $primary_metric_label = 'Search volume';
                        $primary_metric_key = 'search_volume';
                    } elseif (ves_first_non_empty(ves_arr_get($item, 'domainAuthority', null), ves_arr_get($item, 'domain_authority', null), ves_arr_get($item, 'moz_domain_authority', null), ves_arr_get($item, 'authority', null), ves_arr_get($item, 'authority_score', null), ves_arr_get($item, 'rating', null)) !== '') {
                        $primary_metric_label = 'Authority';
                        $primary_metric_key = 'authority';
                    } elseif (ves_first_non_empty(ves_arr_get($item, 'traffic', null), ves_arr_get($item, 'organicTraffic', null), ves_arr_get($item, 'organic_traffic', null)) !== '') {
                        $primary_metric_label = 'Traffic';
                        $primary_metric_key = 'traffic';
                    }
                }
                $has_primary_metric = $primary_metric_value > 0;
                $views = $primary_metric_value;
                $comments = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'reviewCount', null), ves_arr_get($item, 'reviews', null)));
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'createdAt', ''), ves_arr_get($item, 'updatedAt', ''), ves_arr_get($item, 'scrapedAt', '')));
                break;

            case 'facebook_ads':
                $archive_id = ves_first_text_path($item, ['ad_archive_id', 'adArchiveId', 'archive_id', 'id']);
                $author = ves_first_non_empty(
                    ves_first_text_path($item, ['pageName', 'page_name', 'advertiserName', 'advertiser_name', 'page.name', 'snapshot.page_name']),
                    'Facebook Ad'
                );
                $text = ves_first_non_empty(
                    ves_first_text_path($item, [
                        'ad_creative_bodies.0', 'ad_creative_bodies', 'adCreativeBody', 'body', 'adSnapshotBody',
                        'snapshot.body.text', 'snapshot.body.markup.__html', 'snapshot.cards.0.body', 'snapshot.cards.0.text',
                        'snapshot.link_description'
                    ])
                );
                $title_candidate = ves_first_non_empty(
                    ves_first_text_path($item, [
                        'ad_creative_link_titles.0', 'ad_creative_link_titles', 'adCreativeLinkTitle',
                        'title', 'snapshot.title', 'snapshot.cards.0.title', 'snapshot.link_title'
                    ]),
                    mb_substr((string) $text, 0, 90)
                );
                if ($title_candidate === '' || strtolower($title_candidate) === 'resultado') {
                    $title_candidate = trim((string) $author . ($archive_id !== '' ? ' — Ad #' . $archive_id : ' — Facebook Ad'));
                }
                $title = $title_candidate;
                $url = ves_first_non_empty(
                    ves_first_text_path($item, ['ad_library_url', 'adLibraryUrl', 'ad_snapshot_url', 'adSnapshotUrl', 'url']),
                    $archive_id !== '' ? 'https://www.facebook.com/ads/library/?id=' . rawurlencode($archive_id) : ''
                );
                $thumb = ves_first_non_empty(
                    ves_first_text_path($item, [
                        'image.0', 'images.0', 'thumbnail', 'thumbnail_url', 'snapshot.images.0.original_image_url',
                        'snapshot.images.0.resized_image_url', 'snapshot.cards.0.original_image_url', 'snapshot.cards.0.resized_image_url'
                    ]),
                    ves_find_image_url_recursive($item)
                );
                $views = ves_fb_ads_impressions_value($item);
                $likes = 0;
                $comments = 0;
                $shares = 0;
                $duration = '';
                $date = ves_normalize_date(ves_first_non_empty(
                    ves_first_text_path($item, ['ad_delivery_start_time', 'ad_creation_time', 'createdAt', 'start_date', 'start_date_formatted', 'end_date_formatted']),
                    ves_arr_get($item, 'end_date', '')
                ));
                if ($text === '') {
                    $meta_bits = [];
                    if ($archive_id !== '') $meta_bits[] = 'Archive ID: ' . $archive_id;
                    $end = ves_first_text_path($item, ['end_date_formatted']);
                    if ($end !== '') $meta_bits[] = 'End date: ' . $end;
                    $eligible = ves_first_text_path($item, ['is_aaa_eligible']);
                    if ($eligible !== '') $meta_bits[] = 'AAA eligible: ' . $eligible;
                    $text = implode(' · ', $meta_bits);
                }
                break;

            case 'google_ads':
                $archive_id = ves_first_text_path($item, ['creativeId', 'advertiserId', 'id']);
                $author = ves_first_non_empty(
                    ves_first_text_path($item, ['advertiserName', 'advertiser_name', 'advertiserId', 'domain']),
                    'Google Ad'
                );
                $headline_bits = [];
                $headline = ves_first_text_path($item, ['headline', 'headlines.0', 'title']);
                if ($headline !== '') $headline_bits[] = $headline;
                $format = ves_first_text_path($item, ['format', 'adFormatType']);
                if ($format !== '') $headline_bits[] = $format;
                $region = ves_first_text_path($item, ['creativeRegions.0', 'regionStats.0.regionName']);
                if ($region !== '') $headline_bits[] = $region;
                $text = ves_first_non_empty(
                    ves_first_text_path($item, ['description', 'text', 'adText', 'body', 'format']),
                    implode(' · ', array_filter($headline_bits))
                );
                $title = ves_first_non_empty(
                    ves_first_text_path($item, ['headline', 'headlines.0', 'title', 'format']),
                    trim((string) $author . ($archive_id !== '' ? ' — Google Ad #' . $archive_id : ' — Google Ad'))
                );
                $url = ves_first_non_empty(
                    ves_first_text_path($item, ['landingPageUrl', 'landingUrl', 'adTransparencyUrl', 'previewUrls.0', 'url']),
                    ''
                );
                $thumb = ves_first_non_empty(
                    ves_first_text_path($item, ['imageUrl', 'image', 'images.0', 'thumbnail', 'thumbnailUrl', 'previewImageUrl']),
                    ves_find_image_url_recursive($item)
                );
                $views = ves_normalize_number(ves_first_non_empty(
                    ves_arr_get($item, 'impressions.lowerBound', null),
                    ves_arr_get($item, 'regionStats.0.impressions.lowerBound', null),
                    ves_arr_get($item, 'impressions', null)
                ));
                $likes = 0;
                $comments = 0;
                $shares = 0;
                $duration = '';
                $date = ves_normalize_date(ves_first_non_empty(
                    ves_first_text_path($item, ['firstShown', 'lastShown', 'regionStats.0.firstShown', 'regionStats.0.lastShown', 'createdAt']),
                    ''
                ));
                break;

            case 'twitter':
                $author = ves_first_non_empty(ves_arr_get($item, 'author.userName', ''), ves_arr_get($item, 'author.username', ''), ves_arr_get($item, 'user.userName', ''), ves_arr_get($item, 'username', ''));
                $text = ves_first_non_empty(ves_arr_get($item, 'text', ''), ves_arr_get($item, 'fullText', ''), ves_arr_get($item, 'tweetText', ''));
                $title = mb_substr((string) $text, 0, 90);
                $url = ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'twitterUrl', ''), ves_arr_get($item, 'tweetUrl', ''));
                $thumb = ves_first_non_empty(ves_arr_get($item, 'author.profilePicture', ''), ves_arr_get($item, 'author.profilePictureUrl', ''), ves_arr_get($item, 'user.profilePicture', ''), ves_arr_get($item, 'media.0.media_url_https', ''));
                // v0.9.31.3: viewCount is often null on X/Twitter (not guaranteed by the API).
                // Use null defaults so ves_has_primary_metric can distinguish "unavailable"
                // from "zero"; also accept impressionCount/public_metrics aliases (API v2).
                $_tw_vc = ves_arr_get($item, 'viewCount', null);
                if ($_tw_vc === null) { $_tw_vc = ves_arr_get($item, 'impressionCount', null); }
                if ($_tw_vc === null) { $_tw_vc = ves_arr_get($item, 'public_metrics.impression_count', null); }
                $views = $_tw_vc !== null ? ves_normalize_number($_tw_vc) : 0;
                $likes = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'likeCount', 0), ves_arr_get($item, 'favoriteCount', 0)));
                $comments = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'replyCount', 0), ves_arr_get($item, 'replies', 0)));
                $shares = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'retweetCount', 0), ves_arr_get($item, 'retweets', 0)));
                $duration = '';
                // v0.9.31.3: also accept snake_case created_at (Twitter API v2 format).
                $date = ves_normalize_date(ves_first_non_empty(ves_arr_get($item, 'createdAt', ''), ves_arr_get($item, 'created_at', ''), ves_arr_get($item, 'date', '')));
                break;
        }

        // Cross-actor backfill: actors often change field names across versions.
        // Fill missing display fields from a wider set of common output paths.
        if ($author === '') {
            $author = ves_first_text_path($item, [
                'authorMeta.nickName','authorMeta.nickname','authorMeta.name','author.name','author.username','authorName','username',
                'channel.name','channel.username','channelName','channelTitle','channelUsername','uploader','pageName','page.name','user.name','from.name','profileName',
                'fullName','headline','currentCompanyName','currentCompany','location','communityName','parsedCommunityName','username',
                'ownerUsername','ownerFullName','user.username','user.fullName','advertiserName','advertiser_name','page_name','snapshot.page_name'
            ]);
        }
        if ($text === '') {
            $text = ves_first_text_path($item, [
                'caption','caption.text','subtitle','text','fullText','tweetText','message','description','previewDescription','body','selftext','postText','content','desc','biography','name','fullName','headline','summary','about','currentPosition','currentCompanyName','location',
                'ad_creative_bodies.0','ad_creative_bodies','adCreativeBody','adSnapshotBody','snapshot.body.text','snapshot.body.markup.__html','snapshot.cards.0.body','snapshot.cards.0.text','snapshot.link_description','snapshot.caption'
            ]);
        }
        if ($title === '' || strtolower((string) $title) === 'resultado') {
            $title = ves_first_non_empty(
                ves_first_text_path($item, ['title','name','ad_creative_link_titles.0','ad_creative_link_titles','adCreativeLinkTitle','snapshot.title','snapshot.cards.0.title','snapshot.link_title','snapshot.caption']),
                mb_substr((string) $text, 0, 90)
            );
        }
        if ($url === '') {
            $url = ves_first_text_path($item, ['postPage','webVideoUrl','url','videoUrl','watchUrl','facebookUrl','postUrl','commentUrl','permalink','pinUrl','link','topLevelUrl','inputUrl','pageUrl','profileUrl','linkedinUrl','linkedInUrl','communityUrl','website','websiteUrl','domain','ad_library_url','adLibraryUrl','ad_snapshot_url','adSnapshotUrl']);
            if ($url === '' && $platform === 'instagram') {
                $short = ves_first_text_path($item, ['shortCode','shortcode']);
                if ($short !== '') {
                    $url = 'https://www.instagram.com/p/' . rawurlencode($short) . '/';
                }
            }
        }
        if ($thumb === '') {
            $thumb = ves_first_non_empty(
                ves_first_text_path($item, [
                    'videoMeta.coverUrl','videoMeta.originalCoverUrl','video.cover','video.thumbnail','video.coverUrl','video.thumbnailUrl','video.dynamicCover','video.originCover','thumbnailUrl','thumbnail_url','thumbnail','headerImage','userIcon','displayUrl','profilePicUrl','channelAvatarUrl','channel.avatar','imageUrl','imageUrls.original','imageUrls.736x','image','images.0.url','images.0','covers.0','cover',
                    'media.0.thumbnail','media.0.image.uri','media.0.photo_image.uri','attachments.0.media.image.src','author.profilePicture','author.profilePictureUrl','user.profilePicture','profilePicture','profilePictureUrl','profileImageUrl','imageUrl',
                    'snapshot.images.0.original_image_url','snapshot.images.0.resized_image_url','snapshot.cards.0.original_image_url','snapshot.cards.0.resized_image_url','snapshot.videos.0.thumbnail_url','snapshot.videos.0.video_preview_image_url'
                ]),
                ves_find_image_url_recursive($item)
            );
        }
        if ($views <= 0) {
            $views = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'viewCount', null), ves_arr_get($item, 'views', null), ves_arr_get($item, 'viewsCount', null), ves_arr_get($item, 'playCount', null), ves_arr_get($item, 'stats.playCount', null), ves_arr_get($item, 'stats.viewCount', null), ves_arr_get($item, 'stats.views', null), ves_arr_get($item, 'stats.videoViewCount', null), ves_arr_get($item, 'video.playCount', null), ves_arr_get($item, 'video.views', null), ves_arr_get($item, 'itemInfos.itemStruct.stats.playCount', null), ves_arr_get($item, 'statsV2.playCount', null), ves_arr_get($item, 'videoViewCount', null), ves_arr_get($item, 'videoPlayCount', null), ves_arr_get($item, 'impressions', null), ves_arr_get($item, 'impressionsCount', null), ves_arr_get($item, 'upVotes', null), ves_arr_get($item, 'numberOfMembers', null), ves_arr_get($item, 'saves', null), ves_arr_get($item, 'saveCount', null), ves_arr_get($item, 'repinCount', null)));
        }
        if ($likes <= 0 && $platform !== 'facebook_ads') {
            $likes = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'likeCount', null), ves_arr_get($item, 'likesCount', null), ves_arr_get($item, 'likes', null), ves_arr_get($item, 'diggCount', null), ves_arr_get($item, 'favoriteCount', null), ves_arr_get($item, 'reactionsCount', null), ves_arr_get($item, 'reactionCount', null), ves_arr_get($item, 'feedback.reaction_count', null), ves_arr_get($item, 'upVotes', null), ves_arr_get($item, 'score', null), ves_arr_get($item, 'saves', null), ves_arr_get($item, 'saveCount', null)));
        }
        if ($comments <= 0 && $platform !== 'facebook_ads') {
            $comments = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'commentCount', null), ves_arr_get($item, 'commentsCount', null), ves_arr_get($item, 'comments', null), ves_arr_get($item, 'replyCount', null), ves_arr_get($item, 'feedback.comment_count', null)));
        }
        if ($shares <= 0 && $platform !== 'facebook_ads') {
            $shares = ves_normalize_number(ves_first_non_empty(ves_arr_get($item, 'shareCount', null), ves_arr_get($item, 'sharesCount', null), ves_arr_get($item, 'shares', null), ves_arr_get($item, 'retweetCount', null), ves_arr_get($item, 'feedback.share_count', null)));
        }
        $canonical_metrics = function_exists('ves_normalize_social_metrics') ? ves_normalize_social_metrics($item) : [];
        if (isset($canonical_metrics['views']) && $canonical_metrics['views'] !== null) { $views = (int) $canonical_metrics['views']; }
        if (isset($canonical_metrics['likes']) && $canonical_metrics['likes'] !== null) { $likes = (int) $canonical_metrics['likes']; }
        if (isset($canonical_metrics['comments']) && $canonical_metrics['comments'] !== null) { $comments = (int) $canonical_metrics['comments']; }
        if (isset($canonical_metrics['shares']) && $canonical_metrics['shares'] !== null) { $shares = (int) $canonical_metrics['shares']; }
        $saves = isset($canonical_metrics['saves']) && $canonical_metrics['saves'] !== null ? (int) $canonical_metrics['saves'] : null;
        $transcript = ves_extract_transcript_text($item, $context);

        $title = ves_clean_template_text($title);
        $author = ves_clean_template_text($author);
        $text = ves_clean_template_text($text);
        if ($title === '' || strtolower($title) === 'resultado') {
            $title = ves_display_fallback_title($platform, $author, isset($archive_id) ? $archive_id : ves_first_text_path($item, ['id', 'shortCode', 'videoId']));
        }
        $item['ves_title'] = sanitize_text_field((string) $title);
        $item['ves_author'] = sanitize_text_field((string) $author);
        $item['ves_text'] = sanitize_textarea_field((string) $text);
        $item['ves_url'] = esc_url_raw((string) $url);
        $item['ves_thumb'] = esc_url_raw((string) $thumb);
        $item['ves_views'] = $views;
        $item['ves_likes'] = $likes;
        $item['ves_comments'] = $comments;
        $item['ves_shares'] = $shares;
        $item['ves_saves'] = $saves !== null ? $saves : 0;
        $item['ves_social_metrics'] = $canonical_metrics;
        $item['ves_engagement_visible'] = !empty($canonical_metrics['engagement_visible']) ? 1 : 0;
        $computed_primary_metric_label = $primary_metric_label;
        $computed_primary_metric_key   = $primary_metric_key;
        $metric_policy = ves_platform_metric_policy($platform);
        $metric_key = (string) ($metric_policy['primary_key'] ?? 'views');
        $primary_metric_label = (string) ($metric_policy['primary_label'] ?? 'Views');
        $primary_metric_key   = $metric_key;
        if (in_array($platform, ['semrush', 'moz', 'ahrefs'], true) && $computed_primary_metric_key !== '') {
            $metric_key = (string) $computed_primary_metric_key;
            $primary_metric_key = (string) $computed_primary_metric_key;
            $primary_metric_label = (string) $computed_primary_metric_label;
        }

        if ($metric_key === 'likes') {
            $primary_metric_value = $likes;
            $has_primary_metric   = ($likes > 0)
                || (ves_arr_get($item, 'likeCount', null) !== null)
                || (ves_arr_get($item, 'likesCount', null) !== null)
                || (ves_arr_get($item, 'likes', null) !== null)
                || (ves_arr_get($item, 'diggCount', null) !== null)
                || (ves_arr_get($item, 'favoriteCount', null) !== null)
                || (ves_arr_get($item, 'reactionsCount', null) !== null)
                || (ves_arr_get($item, 'reactionCount', null) !== null)
                || (ves_arr_get($item, 'feedback.reaction_count', null) !== null);
            $has_like_metric = $has_like_metric || $has_primary_metric;
        } elseif ($metric_key === 'plays') {
            $primary_metric_value = $views;
            $has_primary_metric   = ($views > 0)
                || (ves_arr_get($item, 'playCount', null) !== null)
                || (ves_arr_get($item, 'viewCount', null) !== null)
                || (ves_arr_get($item, 'views', null) !== null)
                || (ves_arr_get($item, 'viewsCount', null) !== null)
                || (ves_arr_get($item, 'videoViewCount', null) !== null)
                || (ves_arr_get($item, 'videoPlayCount', null) !== null)
                || (ves_arr_get($item, 'stats.playCount', null) !== null)
                || (ves_arr_get($item, 'stats.viewCount', null) !== null)
                || (ves_arr_get($item, 'stats.views', null) !== null)
                || (ves_arr_get($item, 'stats.videoViewCount', null) !== null)
                || (ves_arr_get($item, 'video.playCount', null) !== null)
                || (ves_arr_get($item, 'video.views', null) !== null)
                || (ves_arr_get($item, 'itemInfos.itemStruct.stats.playCount', null) !== null)
                || (ves_arr_get($item, 'statsV2.playCount', null) !== null);
            $has_like_metric = $has_like_metric || ($likes > 0)
                || (ves_arr_get($item, 'likes', null) !== null)
                || (ves_arr_get($item, 'likesCount', null) !== null)
                || (ves_arr_get($item, 'diggCount', null) !== null)
                || (ves_arr_get($item, 'favoriteCount', null) !== null);
        } elseif ($metric_key === 'profiles') {
            $primary_metric_value = max(1, ves_normalize_number($primary_metric_value));
            $has_primary_metric = true;
            $has_like_metric = false;
        } elseif ($metric_key === 'saves') {
            // Pinterest has no stable view metric; the meaningful ranking metric is saves/repins.
            $primary_metric_value = $likes;
            $has_primary_metric = ($likes > 0)
                || (ves_arr_get($item, 'saves', null) !== null)
                || (ves_arr_get($item, 'saveCount', null) !== null)
                || (ves_arr_get($item, 'save_count', null) !== null)
                || (ves_arr_get($item, 'repinCount', null) !== null)
                || (ves_arr_get($item, 'repin_count', null) !== null)
                || (ves_arr_get($item, 'aggregated_pin_data.repin_count', null) !== null);
            $has_like_metric = $has_like_metric || $has_primary_metric;
        } elseif (in_array($platform, ['semrush', 'moz', 'ahrefs'], true)) {
            // SEO tools expose heterogeneous metric names. Preserve the platform-specific
            // primary metric computed in the switch above instead of overwriting it with views.
            $primary_metric_value = ves_normalize_number($primary_metric_value);
            $has_primary_metric = ($primary_metric_value > 0)
                || (ves_arr_get($item, 'domainRating', null) !== null)
                || (ves_arr_get($item, 'domain_rating', null) !== null)
                || (ves_arr_get($item, 'domainAuthority', null) !== null)
                || (ves_arr_get($item, 'domain_authority', null) !== null)
                || (ves_arr_get($item, 'moz_domain_authority', null) !== null)
                || (ves_arr_get($item, 'authority', null) !== null)
                || (ves_arr_get($item, 'authority_score', null) !== null)
                || (ves_arr_get($item, 'rating', null) !== null)
                || (ves_arr_get($item, 'avgMonthlySearches', null) !== null)
                || (ves_arr_get($item, 'searchVolume', null) !== null)
                || (ves_arr_get($item, 'search_volume', null) !== null)
                || (ves_arr_get($item, 'volume', null) !== null)
                || (ves_arr_get($item, 'lowCPC', null) !== null)
                || (ves_arr_get($item, 'highCPC', null) !== null)
                || (ves_arr_get($item, 'monetizationScore', null) !== null)
                || (ves_arr_get($item, 'traffic', null) !== null)
                || (ves_arr_get($item, 'organicTraffic', null) !== null)
                || (ves_arr_get($item, 'organic_traffic', null) !== null)
                || (ves_arr_get($item, 'backlinks', null) !== null);
            $views = $primary_metric_value;
        } elseif (in_array($platform, ['facebook_ads', 'google_ads'], true)) {
            $primary_metric_value = $views;
            $has_primary_metric   = ($views > 0)
                || (ves_arr_get($item, 'impressions', null) !== null)
                || (ves_arr_get($item, 'impressionsCount', null) !== null)
                || (ves_arr_get($item, 'impressions_with_index', null) !== null)
                || (ves_arr_get($item, 'impressions.lowerBound', null) !== null)
                || (ves_arr_get($item, 'regionStats.0.impressions.lowerBound', null) !== null);
            $has_like_metric      = false;
        } else {
            $primary_metric_value = $views;
            $has_primary_metric   = ($views > 0)
                || (ves_arr_get($item, 'viewCount', null) !== null)
                || (ves_arr_get($item, 'views', null) !== null)
                || (ves_arr_get($item, 'viewsCount', null) !== null)
                || (ves_arr_get($item, 'impressions', null) !== null);
        }

        $item['ves_duration'] = sanitize_text_field((string) $duration);
        $item['ves_date'] = sanitize_text_field((string) $date);
        $item['ves_transcript'] = is_string($transcript) ? trim($transcript) : '';
        $item['ves_primary_metric_value'] = ves_normalize_number($primary_metric_value);
        $item['ves_primary_metric_label'] = sanitize_text_field((string) $primary_metric_label);
        $item['ves_primary_metric_key']   = sanitize_key((string) $primary_metric_key);
        $item['ves_has_primary_metric']   = $has_primary_metric ? 1 : 0;
        $item['ves_has_like_metric']      = $has_like_metric ? 1 : 0;
        $item['ves_metric_unknown']       = (!$has_primary_metric && ves_normalize_number($primary_metric_value) <= 0) ? 1 : 0;
        $item['ves_metric_zero']          = ($has_primary_metric && ves_normalize_number($primary_metric_value) <= 0) ? 1 : 0;

        return $item;
    }
}

if (!function_exists('ves_enrich_items_for_display')) {
    function ves_enrich_items_for_display($platform, $items, $context = []) {
        if (!is_array($items)) {
            return [];
        }
        $items = function_exists('ves_strip_unusable_items') ? ves_strip_unusable_items($platform, $items) : $items;
        $enriched = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = ves_enrich_single_item_for_display($platform, $item, $context);
            if (!is_array($row)) {
                continue;
            }
            $has_textual = trim((string) ($row['ves_title'] ?? '')) !== '' && strtolower(trim((string) ($row['ves_title'] ?? ''))) !== 'resultado';
            $has_payload = !empty($row['ves_thumb']) || !empty($row['ves_text']) || !empty($row['ves_author']) || !empty($row['ves_has_primary_metric']) || !empty($row['ves_url']);
            if (!$has_textual && !$has_payload) {
                continue;
            }
            $enriched[] = $row;
        }
        return $enriched;
    }
}

if (!function_exists('ves_apply_requested_filters')) {
    function ves_apply_requested_filters($items, $src = []) {
        if (!is_array($items)) {
            return [];
        }
        $platform  = sanitize_key((string) ($src['platform'] ?? ''));
        if (function_exists('ves_is_post_url_mode') && ves_is_post_url_mode($src)) {
            return array_values($items);
        }
        $min_metric = function_exists('ves_requested_min_metric') ? ves_requested_min_metric($src) : ves_clean_int($src['minViews'] ?? ($src['minLikes'] ?? null), 0, null);

        // v0.9.10.1: the UI still posts minLikes for compatibility, but its meaning
        // is now platform-aware: TikTok/YouTube = plays; Instagram/Facebook/X = likes.
        // v0.9.10.3: this threshold is intentionally ignored for exact post URL mode.

        $strict_metric_filter = !empty($src['strictMetricFilter']) || !empty($src['strict_metric_filter']);
        $filtered = [];
        foreach ($items as $item) {
            if ($min_metric !== null && $min_metric > 0) {
                $metric_value = ves_item_filter_metric_value($item, $platform);
                $has_metric = ves_item_has_filter_metric($item, $platform);
                if (!$has_metric) {
                    // Missing provider metrics are unknown, not zero. In normal mode keep
                    // the item and let the UI/admin diagnostics show metric_unknown.
                    if ($strict_metric_filter) {
                        $item['ves_discard_reason'] = 'missing_metric';
                        continue;
                    }
                } elseif ($metric_value < $min_metric) {
                    $item['ves_discard_reason'] = 'below_min_views';
                    continue;
                }
            }
            $filtered[] = $item;
        }
        return $filtered;
    }
}



if (!function_exists('ves_search_normalize_text')) {
    /**
     * v0.9.9.9 — Normalize text for relevance checks without changing display text.
     */
    function ves_search_normalize_text($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}

if (!function_exists('ves_search_tokenize')) {
    function ves_search_tokenize($text) {
        $text = ves_search_normalize_text($text);
        if ($text === '') {
            return [];
        }
        $stop = [
            'the','and','for','with','from','this','that','como','para','con','por','los','las','una','unos','unas','del','que','este','esta','estos','estas','spanish','espanol','español','espana','españa','spain'
        ];
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text);
        $out = [];
        foreach ((array) $tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len < 2) {
                continue;
            }
            if ($len <= 3 && in_array($token, $stop, true)) {
                continue;
            }
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('ves_requested_search_context')) {
    /**
     * Build the relevance context from the submitted search targets + optional search context.
     */
    function ves_requested_search_context($src = []) {
        $targets = (string) ($src['targets'] ?? '');
        // Accept both the newer `context` field used by the scraper form and
        // legacy `searchContext`. Previously profile/URL runs with an empty
        // context could still be re-scored as semantic noise during display.
        $context_raw = $src['searchContext'] ?? ($src['context'] ?? '');
        $context = sanitize_textarea_field((string) $context_raw);
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($src['country'] ?? '') : '';
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($src['language'] ?? '') : '';

        $phrases = [];
        foreach ((array) preg_split('/\r\n|\r|\n/', $targets) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('~^https?://~i', $line)) {
                continue;
            }
            $line = ltrim($line, "#@ \t\n\r\0\x0B");
            $line = sanitize_text_field($line);
            if ($line !== '') {
                $phrases[] = $line;
            }
        }
        $phrases = array_values(array_unique($phrases));

        $tokens = [];
        foreach ($phrases as $phrase) {
            $tokens = array_merge($tokens, ves_search_tokenize($phrase));
        }
        $tokens = array_values(array_unique($tokens));

        $context_tokens = ves_search_tokenize($context);
        if ($country === 'ES') {
            $context_tokens = array_merge($context_tokens, ['espana', 'españa', 'spain', 'madrid', 'barcelona']);
        }
        if ($language === 'es') {
            $context_tokens = array_merge($context_tokens, ['espanol', 'español', 'castellano']);
        }
        $context_tokens = array_values(array_unique($context_tokens));

        $strict_brand_context = false;
        if ($context !== '' && count($tokens) <= 2) {
            $strict_brand_context = true;
        }

        return [
            'phrases' => $phrases,
            'tokens' => $tokens,
            'context_tokens' => $context_tokens,
            'country' => $country,
            'language' => $language,
            'strict_brand_context' => $strict_brand_context,
        ];
    }
}

if (!function_exists('ves_context_support_tokens')) {
    /**
     * v0.9.10.0 — Context tokens that must support ambiguous brand queries.
     * Generic words like "marca" or broad geo/language terms are not enough.
     */
    function ves_context_support_tokens($ctx = []) {
        $tokens = (array) ($ctx['context_tokens'] ?? []);
        $target_tokens = (array) ($ctx['tokens'] ?? []);
        $stop = ['marca','brand','empresa','company','negocio','business','campana','campaign','sobre','para','con','los','las','una','uno','del','the','and','espana','españa','spain','espanol','español','castellano','san'];
        $out = [];
        foreach ($tokens as $token) {
            $token = ves_search_normalize_text((string) $token);
            if ($token === '' || in_array($token, $target_tokens, true) || in_array($token, $stop, true)) {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len < 4 && !in_array($token, ['bar'], true)) {
                continue;
            }
            $out[] = $token;
        }
        $ctx_blob = implode(' ', $tokens);
        if (preg_match('/\b(cerveza|beer|lager|bares|bar|canas|cañas|futbol|football|madrid)\b/u', $ctx_blob)) {
            $out = array_merge($out, ['cerveza','beer','lager','bar','bares','canas','cañas','futbol','football','madrid']);
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('ves_false_friend_tokens_for_search')) {
    function ves_false_friend_tokens_for_search($ctx = []) {
        $tokens = array_map('ves_search_normalize_text', (array) ($ctx['tokens'] ?? []));
        return [];
    }
}

if (!function_exists('ves_search_has_exact_term')) {
    function ves_search_has_exact_term($haystack, $needle) {
        $haystack = ves_search_normalize_text($haystack);
        $needle = ves_search_normalize_text($needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }
        return (bool) preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}_])/u', $haystack);
    }
}

if (!function_exists('ves_search_has_substring_inside_larger_token')) {
    function ves_search_has_substring_inside_larger_token($haystack, $needle) {
        $haystack = ves_search_normalize_text($haystack);
        $needle = ves_search_normalize_text($needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }
        if (ves_search_has_exact_term($haystack, $needle)) {
            return false;
        }
        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('ves_item_content_blob_for_relevance')) {
    function ves_item_content_blob_for_relevance($platform, $item) {
        if (!is_array($item)) {
            return '';
        }
        $platform = sanitize_key((string) $platform);
        $keys = [
            'ves_text', 'ves_transcript', 'text', 'caption', 'description', 'desc', 'transcript', 'subtitleText', 'captionText', 'message', 'body', 'content',
            'videoMeta.text', 'videoMeta.title', 'musicMeta.musicName', 'musicMeta.musicAuthor', 'snippet.description', 'hashtags.0', 'hashtags.1', 'hashtags.2', 'hashtags.3', 'hashtags.4', 'tags.0', 'tags.1', 'tags.2', 'imageAlt', 'alt', 'accessibilityCaption', 'ad_creative_bodies.0', 'snapshot.body.text', 'snapshot.cards.0.body'
        ];
        if ($platform !== 'tiktok') {
            $keys[] = 'ves_title';
            $keys[] = 'title';
            $keys[] = 'name';
            $keys[] = 'snippet.title';
        } else {
            // For TikTok, enriched ves_title often becomes an author-based fallback
            // such as "Brand channel ... TikTok video". Counting that as content is
            // the root cause of false positives for brand searches.
            $keys[] = 'title';
        }
        $parts = [];
        foreach ($keys as $key) {
            $v = function_exists('ves_first_text_path') ? ves_first_text_path($item, [$key]) : '';
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return trim(implode(' ', array_unique($parts)));
    }
}

if (!function_exists('ves_item_author_blob_for_relevance')) {
    function ves_item_author_blob_for_relevance($item) {
        if (!is_array($item)) {
            return '';
        }
        $keys = ['ves_author','authorMeta.nickName','authorMeta.nickname','authorMeta.name','authorMeta.uniqueId','author.username','author.name','authorName','username','channelName','pageName','ownerUsername','ownerFullName'];
        $parts = [];
        foreach ($keys as $key) {
            $v = function_exists('ves_first_text_path') ? ves_first_text_path($item, [$key]) : '';
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return trim(implode(' ', array_unique($parts)));
    }
}

if (!function_exists('ves_item_url_blob_for_relevance')) {
    function ves_item_url_blob_for_relevance($item) {
        if (!is_array($item)) {
            return '';
        }
        $keys = ['ves_url','url','webVideoUrl','videoUrl','postUrl','permalink','link','submittedVideoUrl','shortCode','id','videoId'];
        $parts = [];
        foreach ($keys as $key) {
            $v = function_exists('ves_first_text_path') ? ves_first_text_path($item, [$key]) : '';
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return trim(implode(' ', array_unique($parts)));
    }
}


if (!function_exists('ves_canonical_brand_tokens_for_context')) {
    /**
     * v0.9.23.0 — canonical brand matching for strict social search.
     * Broad support terms such as Madrid/España/cerveza cannot create a direct
     * match unless the canonical brand token is present as a real token.
     */
    function ves_canonical_brand_tokens_for_context($ctx = []) {
        $tokens = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['tokens'] ?? []))));
        $phrases = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['phrases'] ?? []))));
        $base = array_values(array_unique(array_merge($tokens, $phrases)));
        $out = [];
        foreach ($base as $token) {
            $token = trim((string) $token);
            if ($token === '') { continue; }
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len >= 3 && !in_array($token, ['marca','brand','cerveza','beer','lager','spain','espana','españa','madrid','barcelona'], true)) {
                $out[] = $token;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('ves_brand_support_tokens_for_context')) {
    function ves_brand_support_tokens_for_context($ctx = []) {
        $tokens = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['tokens'] ?? []))));
        $support = ves_context_support_tokens($ctx);
        return array_values(array_unique(array_filter($support)));
    }
}


if (!function_exists('ves_context_requires_exact_entity')) {
    function ves_context_requires_exact_entity($ctx = []) {
        $phrases = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['phrases'] ?? []))));
        foreach ($phrases as $phrase) {
            $parts = array_values(array_filter(explode(' ', $phrase)));
            if (count($parts) < 2) { continue; }
            foreach ($parts as $part) {
                $len = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
                if ($len <= 3 || in_array($part, ['san','santa','santo','de','del','la','el','the','and'], true)) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('ves_blob_has_required_exact_entity')) {
    function ves_blob_has_required_exact_entity($content, $author = '', $url = '', $ctx = []) {
        $all = ves_search_normalize_text(trim((string) $content . ' ' . (string) $author . ' ' . (string) $url));
        if ($all === '') { return false; }
        $compact = preg_replace('/[^a-z0-9]+/u', '', $all);
        $phrases = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['phrases'] ?? []))));
        foreach ($phrases as $phrase) {
            $parts = array_values(array_filter(explode(' ', $phrase)));
            if (count($parts) < 2) { continue; }
            if (ves_search_has_exact_term($all, $phrase)) { return true; }
            $phrase_compact = preg_replace('/[^a-z0-9]+/u', '', $phrase);
            if ($phrase_compact !== '' && strpos($compact, $phrase_compact) !== false) { return true; }
        }
        return false;
    }
}

if (!function_exists('ves_blob_has_ambiguous_secondary_token_without_required_entity')) {
    function ves_blob_has_ambiguous_secondary_token_without_required_entity($content, $author = '', $url = '', $ctx = []) {
        if (ves_blob_has_required_exact_entity($content, $author, $url, $ctx)) { return false; }
        $all = ves_search_normalize_text(trim((string) $content . ' ' . (string) $author . ' ' . (string) $url));
        if ($all === '') { return false; }
        $phrases = array_values(array_filter(array_map('ves_search_normalize_text', (array) ($ctx['phrases'] ?? []))));
        foreach ($phrases as $phrase) {
            $parts = array_values(array_filter(explode(' ', $phrase)));
            if (count($parts) < 2) { continue; }
            $hits = 0;
            foreach ($parts as $part) {
                $len = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
                if ($len <= 3 || in_array($part, ['san','santa','santo','de','del','la','el','the','and'], true)) { continue; }
                if (ves_search_has_exact_term($all, $part)) { $hits++; }
            }
            if ($hits > 0) { return true; }
        }
        return false;
    }
}


if (!function_exists('ves_canonical_brand_match_state')) {
    function ves_canonical_brand_match_state($content, $author, $url, $ctx = []) {
        $tokens = ves_canonical_brand_tokens_for_context($ctx);
        $state = [
            'any' => false,
            'content' => false,
            'author_or_url' => false,
            'tokens' => [],
            'false_friend' => false,
            'false_friend_tokens' => [],
        ];
        foreach ($tokens as $token) {
            if ($token === '') { continue; }
            $content_hit = ves_search_has_exact_term($content, $token) || ves_search_has_exact_term($content, '#' . $token);
            $secondary_hit = ves_search_has_exact_term($author, $token) || ves_search_has_exact_term($url, $token);
            if ($content_hit || $secondary_hit) {
                $state['any'] = true;
                $state['content'] = $state['content'] || $content_hit;
                $state['author_or_url'] = $state['author_or_url'] || (!$content_hit && $secondary_hit);
                $state['tokens'][] = $token;
            }
        }
        $state['tokens'] = array_values(array_unique($state['tokens']));
        $state['false_friend_tokens'] = array_values(array_unique($state['false_friend_tokens']));
        return $state;
    }
}

if (!function_exists('ves_detect_competitor_authorship')) {
    /**
     * v0.9.31.5 BUG-02: detect the "incidental tag match" case — the queried
     * brand appears only in content (hashtag/caption) while the author/URL
     * carries a recognisable DIFFERENT brand-like token. Heuristic, no
     * hardcoded competitor list:
     *   1. The queried brand must already be matched in content but NOT in
     *      author/url (otherwise the post is plausibly the brand's own).
     *   2. The author blob must contain at least one substantive token
     *      (>=4 chars, not in the language stopword list) that is NOT one
     *      of the queried brand tokens or canonical brand tokens.
     * Returns true when both conditions hold. Caller is expected to
     * downgrade classification and flag the item as `incidental_tag_match`.
     */
    function ves_detect_competitor_authorship($content, $author, $url, $ctx = [], $brand_state = null) {
        $author = (string) $author;
        if ($author === '' && (string) $url === '') {
            return false;
        }
        if (!is_array($brand_state)) {
            $brand_state = function_exists('ves_canonical_brand_match_state')
                ? ves_canonical_brand_match_state((string) $content, $author, (string) $url, $ctx)
                : ['content' => false, 'author_or_url' => true];
        }
        // Only suspicious when brand is in content but NOT in author/url.
        if (empty($brand_state['content']) || !empty($brand_state['author_or_url'])) {
            return false;
        }
        $brand_tokens = [];
        if (function_exists('ves_canonical_brand_tokens_for_context')) {
            foreach (ves_canonical_brand_tokens_for_context($ctx) as $token) {
                $token = ves_search_normalize_text((string) $token);
                if ($token !== '') { $brand_tokens[$token] = true; }
            }
        }
        foreach ((array) ($ctx['tokens'] ?? []) as $token) {
            $token = ves_search_normalize_text((string) $token);
            if ($token !== '') { $brand_tokens[$token] = true; }
        }
        $author_tokens = function_exists('ves_search_tokenize') ? ves_search_tokenize($author . ' ' . $url) : [];
        foreach ($author_tokens as $token) {
            $token = ves_search_normalize_text((string) $token);
            if ($token === '') { continue; }
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len < 4) { continue; }
            if (isset($brand_tokens[$token])) { continue; }
            // A substantive, non-brand author token survived — competitor signal.
            return true;
        }
        return false;
    }
}

if (!function_exists('ves_score_item_search_relevance')) {
    function ves_score_item_search_relevance($item, $ctx, $platform = '') {
        if (!is_array($item) || !is_array($ctx)) {
            return [0.0, []];
        }
        $content = ves_item_content_blob_for_relevance($platform, $item);
        $author  = ves_item_author_blob_for_relevance($item);
        $url     = ves_item_url_blob_for_relevance($item);
        $all_blob = trim($content . ' ' . $author . ' ' . $url);
        $score = 0.0;
        $reasons = [];
        $strong_content_hit = false;
        $target_hit = false;
        $target_hit_in_content = false;
        $support_hits = 0;
        $strict_brand_context = !empty($ctx['strict_brand_context']);
        $content_norm_for_negative = ves_search_normalize_text($content);
        $negative_brand_context = false;
        $canonical_brand_state = function_exists('ves_canonical_brand_match_state') ? ves_canonical_brand_match_state($content, $author, $url, $ctx) : ['any' => false, 'content' => false, 'author_or_url' => false, 'tokens' => [], 'false_friend' => false, 'false_friend_tokens' => []];
        $requires_exact_entity = function_exists('ves_context_requires_exact_entity') ? ves_context_requires_exact_entity($ctx) : false;
        $has_exact_entity = function_exists('ves_blob_has_required_exact_entity') ? ves_blob_has_required_exact_entity($content, $author, $url, $ctx) : false;
        $ambiguous_secondary_without_entity = function_exists('ves_blob_has_ambiguous_secondary_token_without_required_entity') ? ves_blob_has_ambiguous_secondary_token_without_required_entity($content, $author, $url, $ctx) : false;
        if ($requires_exact_entity && $has_exact_entity) {
            $score += 0.6;
            $reasons[] = 'required_entity_present';
        }

        if (!empty($canonical_brand_state['content'])) {
            $score += 4.0;
            $target_hit = true;
            $target_hit_in_content = true;
            $strong_content_hit = true;
            $reasons[] = 'canonical_brand_content:' . implode('|', array_slice((array) ($canonical_brand_state['tokens'] ?? []), 0, 3));
        } elseif (!empty($canonical_brand_state['author_or_url'])) {
            $score += 1.0;
            $target_hit = true;
            $reasons[] = 'canonical_brand_secondary:' . implode('|', array_slice((array) ($canonical_brand_state['tokens'] ?? []), 0, 3));
        }

        foreach ((array) ($ctx['phrases'] ?? []) as $phrase) {
            if ($phrase === '') {
                continue;
            }
            if (ves_search_has_exact_term($content, $phrase)) {
                $score += 8.0;
                $strong_content_hit = true;
                $target_hit = true;
                $target_hit_in_content = true;
                $reasons[] = 'phrase_content:' . $phrase;
            } elseif (ves_search_has_exact_term($url, $phrase)) {
                $score += 3.0;
                $target_hit = true;
                $reasons[] = 'phrase_url:' . $phrase;
            } elseif (ves_search_has_exact_term($author, $phrase)) {
                $score += 1.2;
                $target_hit = true;
                $reasons[] = 'phrase_author:' . $phrase;
            } elseif (ves_search_has_substring_inside_larger_token($all_blob, $phrase)) {
                $score += 0.3;
                $reasons[] = 'weak_substring:' . $phrase;
            }
        }

        foreach ((array) ($ctx['tokens'] ?? []) as $token) {
            if ($token === '') {
                continue;
            }
            if ($requires_exact_entity && in_array((string) ves_search_normalize_text($token), ['san','miguel'], true)) {
                continue;
            }
            if (ves_search_has_exact_term($content, '#' . $token) || ves_search_has_exact_term($content, $token)) {
                $score += 5.0;
                $strong_content_hit = true;
                $target_hit = true;
                $target_hit_in_content = true;
                $reasons[] = 'token_content:' . $token;
            } elseif (ves_search_has_exact_term($url, $token)) {
                $score += 2.0;
                $target_hit = true;
                $reasons[] = 'token_url:' . $token;
            } elseif (ves_search_has_exact_term($author, $token)) {
                $score += 0.7;
                $target_hit = true;
                $reasons[] = 'token_author:' . $token;
            } elseif (ves_search_has_substring_inside_larger_token($all_blob, $token)) {
                $score += 0.2;
                $reasons[] = 'weak_token_substring:' . $token;
            }
        }

        $context_score_stop = ['de','en','el','la','los','las','un','una','uno','y','o','a','al','del','para','con','san','marca','brand','espana','españa','spain','espanol','español','castellano'];
        foreach (array_slice((array) ($ctx['context_tokens'] ?? []), 0, 20) as $token) {
            if ($token === '') {
                continue;
            }
            $token_len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($token_len < 3 || in_array($token, $context_score_stop, true) || in_array($token, (array) ($ctx['tokens'] ?? []), true)) {
                continue;
            }
            if (ves_search_has_exact_term($content, $token)) {
                $score += 1.0;
                $reasons[] = 'context_content:' . $token;
            } elseif (ves_search_has_exact_term($author . ' ' . $url, $token)) {
                $score += 0.35;
                $reasons[] = 'context_secondary:' . $token;
            }
        }

        foreach ((function_exists('ves_brand_support_tokens_for_context') ? ves_brand_support_tokens_for_context($ctx) : ves_context_support_tokens($ctx)) as $token) {
            if (ves_search_has_exact_term($content, $token) || ves_search_has_exact_term($content, '#' . $token)) {
                $support_hits++;
                $score += 2.0;
                $reasons[] = 'support_content:' . $token;
            } elseif (ves_search_has_exact_term($author . ' ' . $url, $token)) {
                $support_hits++;
                $score += 0.7;
                $reasons[] = 'support_secondary:' . $token;
            }
        }

        // v0.9.24.65 BUG-H fix: only boost beverage signals when the search context
        // itself references drinks/bars/food. Firing this globally caused non-beverage
        // brand searches (Spotify, Nike, etc.) to get false support_beverage_hint boosts
        // from unrelated posts containing 'bar', beer emojis, or tapas.
        $ctx_blob_lower = ves_search_normalize_text(implode(' ', array_merge(
            (array) ($ctx['tokens'] ?? []),
            (array) ($ctx['phrases'] ?? []),
            (array) ($ctx['context_tokens'] ?? [])
        )));
        $context_is_beverage_adjacent = (bool) preg_match(
            '/\b(cerveza|beer|lager|vino|wine|bares|tapas|cana|caña|bebida|drink|alcohol|pub|brewery|bodega|estrella|damm|cruzcampo|moritz|alhambra|heineken|corona|amstel|carlsberg)\b/u',
            $ctx_blob_lower
        );
        if ($context_is_beverage_adjacent && preg_match('/🍺|🍻|\b(cana|caña|canas|cañas|botella|botellin|botellín|birra|bar|bares|tapas|terrazas?)\b/ui', $content)) {
            $support_hits++;
            $score += 1.8;
            $reasons[] = 'support_beverage_hint';
        }

        $false_friend_hits = 0;
        foreach (ves_false_friend_tokens_for_search($ctx) as $bad) {
            if (ves_search_has_exact_term($content, $bad) || ves_search_has_exact_term($author, $bad)) {
                $false_friend_hits++;
                $score -= 3.5;
                $reasons[] = 'negative_false_friend:' . $bad;
            }
        }

        if (!empty($canonical_brand_state['false_friend'])) {
            $score = min($score, 0.8);
            $reasons[] = 'false_friend:' . implode('|', array_slice((array) ($canonical_brand_state['false_friend_tokens'] ?? []), 0, 3));
        }

        if ($requires_exact_entity && !$has_exact_entity) {
            // v0.9.24.63: some exact actor queries can still return
            // false friends, surnames, stores and places. These must never score
            // as brand/category matches without a real canonical entity.
            $score = min($score, $ambiguous_secondary_without_entity ? 0.9 : 1.8);
            $strong_content_hit = false;
            $target_hit_in_content = false;
            $reasons[] = $ambiguous_secondary_without_entity ? 'ambiguous_secondary_without_entity' : 'missing_required_entity';
        }

        if ($strict_brand_context && empty($canonical_brand_state['any'])) {
            $score = min($score, 1.6);
            $reasons[] = 'missing_semantic_match';
        }

        if ($strict_brand_context && $target_hit && $support_hits <= 0) {
            $score = min($score, !empty($canonical_brand_state['content']) ? 5.2 : 3.2);
            $reasons[] = 'strict_context_no_support';
        }

        if ($strict_brand_context && !empty($canonical_brand_state['author_or_url']) && empty($canonical_brand_state['content']) && $support_hits <= 0) {
            $score = min($score, 2.4);
            $reasons[] = 'brand_only_secondary';
        }

        if ($false_friend_hits > 0 && $support_hits <= 0) {
            $score = min($score, 1.0);
            $reasons[] = 'false_friend_without_brand_support';
        }

        if ($negative_brand_context) {
            $score = min($score, 2.6);
            $strong_content_hit = false;
            $reasons[] = 'negative_brand_context';
        }

        if (!$strong_content_hit && $score < 4.0) {
            $score = min($score, 2.0);
        }

        // v0.9.24.65 BUG-I fix: only apply metric boost when there is a genuine
        // content match (strong_content_hit). Applying it at score>=4.0 regardless
        // allowed high-metric but weakly-relevant items (e.g. score=4.0 from a
        // single 'bar' support hit) to be boosted above classification thresholds.
        if ($score >= 4.0 && $strong_content_hit) {
            $likes = function_exists('ves_normalize_number') ? ves_normalize_number($item['ves_likes'] ?? 0) : 0;
            $views = function_exists('ves_normalize_number') ? ves_normalize_number($item['ves_views'] ?? 0) : 0;
            if ($likes > 0) {
                $score += min(1.5, log10($likes + 1) / 2);
            }
            if ($views > 0) {
                $score += min(1.0, log10($views + 1) / 4);
            }
        }

        if (!$target_hit_in_content && $strict_brand_context && $support_hits <= 0) {
            $score = min($score, 2.0);
            $reasons[] = 'no_content_target_for_strict_context';
        }

        return [round(max(0, $score), 3), array_values(array_unique($reasons))];
    }
}


if (!function_exists('ves_classify_social_item_relevance')) {
    function ves_classify_social_item_relevance($item, $ctx, $platform = '', $score = 0.0, $reasons = []) {
        $content = function_exists('ves_item_content_blob_for_relevance') ? ves_item_content_blob_for_relevance($platform, $item) : wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        $author = function_exists('ves_item_author_blob_for_relevance') ? ves_item_author_blob_for_relevance($item) : '';
        $url = function_exists('ves_item_url_blob_for_relevance') ? ves_item_url_blob_for_relevance($item) : '';
        $content_norm = function_exists('remove_accents') ? remove_accents($content) : $content;
        $content_norm = function_exists('mb_strtolower') ? mb_strtolower($content_norm, 'UTF-8') : strtolower($content_norm);
        $country = function_exists('ves_sanitize_country_code') ? ves_sanitize_country_code($ctx['country'] ?? '') : sanitize_text_field((string) ($ctx['country'] ?? ''));
        $language = function_exists('ves_sanitize_language_code') ? ves_sanitize_language_code($ctx['language'] ?? '') : sanitize_key((string) ($ctx['language'] ?? ''));
        $reasons = (array) $reasons;
        if (!empty(array_filter($reasons, static fn($reason) => strpos((string) $reason, 'false_friend') !== false || strpos((string) $reason, 'negative_false_friend:') === 0))) {
            return 'false_friend';
        }
        if ($language === 'es' && preg_match('/\b(the|and|for|with|side hustle|income|beginner|clock in)\b/u', $content_norm) && !preg_match('/\b(el|la|los|las|de|para|con|que|espa[nñ]a|madrid|cerveza)\b/u', $content_norm)) {
            return 'wrong_language';
        }
        if (in_array(strtolower((string) $country), ['es','spain','espana','españa'], true) && preg_match('/\b(brazil|brasil|usa|united states|peru|argentina|mexico|gb|uk)\b/u', $content_norm) && !preg_match('/\b(spain|espana|españa|madrid|barcelona|valencia|sevilla)\b/u', $content_norm)) {
            return 'wrong_market';
        }
        if (!empty(array_filter($reasons, static fn($reason) => strpos((string) $reason, 'negative_brand_context') !== false))) {
            return 'weak_match';
        }
        if (!empty(array_filter($reasons, static fn($reason) => strpos((string) $reason, 'missing_semantic_match') !== false || strpos((string) $reason, 'missing_required_entity') !== false || strpos((string) $reason, 'ambiguous_secondary_without_entity') !== false))) {
            return 'missing_semantic_match';
        }
        $has_phrase = !empty(array_filter($reasons, static function($reason) { return strpos((string) $reason, 'phrase_content:') === 0 || strpos((string) $reason, 'token_content:') === 0 || strpos((string) $reason, 'canonical_brand_content:') === 0; }));
        $has_support = !empty(array_filter($reasons, static function($reason) { return strpos((string) $reason, 'support_') === 0 || strpos((string) $reason, 'context_content:') === 0 || strpos((string) $reason, 'support_beverage_hint') === 0; }));
        $brand_state = function_exists('ves_canonical_brand_match_state') ? ves_canonical_brand_match_state($content, $author, $url, $ctx) : ['any' => false, 'content' => false, 'author_or_url' => false];
        $strict = !empty($ctx['strict_brand_context']);
        if ($strict && empty($brand_state['any'])) {
            return $score >= 3.0 ? 'adjacent_context' : 'missing_semantic_match';
        }
        // v0.9.31.5 BUG-02: competitor downgrade. When the queried brand is
        // present only in content (hashtag/caption) and the author/URL signals
        // a different brand, demote the bucket so competitor posts don't ride
        // the brand-match path. We compute the base class first and then
        // apply the downgrade so the heuristic doesn't affect already-weak
        // classes (weak_match/noise/etc.).
        $base_class = null;
        if (!empty($brand_state['content']) && ($score >= 7.0 || $has_support)) { $base_class = 'direct_brand_match'; }
        elseif (!empty($brand_state['content']) && $score >= 5.0) { $base_class = 'category_relevant'; }
        elseif (!empty($brand_state['author_or_url']) && !$has_support) { $base_class = $score >= 3.0 ? 'weak_match' : 'noise'; }
        elseif ($score >= 8.0 && $has_phrase) { $base_class = 'direct_brand_match'; }
        elseif ($score >= 6.0 && ($has_phrase || $has_support)) { $base_class = 'category_relevant'; }
        elseif ($score >= 4.0) { $base_class = 'adjacent_context'; }
        elseif ($score >= 2.0) { $base_class = 'weak_match'; }
        else { $base_class = 'noise'; }
        if (($base_class === 'direct_brand_match' || $base_class === 'category_relevant')
            && function_exists('ves_detect_competitor_authorship')
            && ves_detect_competitor_authorship($content, $author, $url, $ctx, $brand_state)) {
            $base_class = $base_class === 'direct_brand_match' ? 'category_relevant' : 'weak_match';
        }
        return $base_class;
    }
}

if (!function_exists('ves_social_relevance_label')) {
    // v0.9.30.21: optional $ctx param — when not in strict brand mode,
    // 'missing_semantic_match' means weak topic relevance, not a brand miss.
    function ves_social_relevance_label($class, $ctx = []) {
        $map = [
            'direct_brand_match'      => 'Coincidencia directa de marca/tema',
            'category_relevant'       => 'Relevante para categoría',
            'adjacent_context'        => 'Contexto adyacente',
            'weak_match'              => 'Coincidencia débil',
            'noise'                   => 'Ruido / no usable',
            'wrong_language'          => 'Idioma no solicitado',
            'wrong_market'            => 'Mercado no solicitado',
            'false_friend'            => 'Falso positivo / false friend',
            'below_metric_threshold'  => 'Por debajo del umbral de métrica',
            'missing_semantic_match'  => 'Sin coincidencia semántica de marca',
        ];
        if ($class === 'missing_semantic_match' && empty($ctx['strict_brand_context'])) {
            return 'Coincidencia débil de tema';
        }
        return $map[$class] ?? 'Relevancia no determinada';
    }
}

if (!function_exists('ves_filter_and_rank_items_by_search_relevance')) {
    /**
     * v0.9.9.9 — Brand/search relevance gate applied after enrichment and before slicing.
     * Returns [ranked_items, dropped_count, used].
     */
    function ves_filter_and_rank_items_by_search_relevance($items, $src = [], $platform = '') {
        if (!is_array($items) || empty($items)) {
            return [[], 0, false];
        }
        $mode = sanitize_key((string) ($src['mode'] ?? 'search'));
        if ($mode === 'urls' || (function_exists('ves_is_any_url_mode') && ves_is_any_url_mode($src))) {
            return [$items, 0, false];
        }
        $ctx = ves_requested_search_context($src);
        if (empty($ctx['phrases']) && empty($ctx['tokens'])) {
            return [$items, 0, false];
        }
        $ranked = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            list($score, $reasons) = ves_score_item_search_relevance($item, $ctx, $platform);
            $item['ves_relevance_score'] = $score;
            $item['ves_relevance_reasons'] = $reasons;
            $rel_class = function_exists('ves_classify_social_item_relevance') ? ves_classify_social_item_relevance($item, $ctx, $platform, $score, $reasons) : 'weak_match';
            $item['ves_relevance_class'] = $rel_class;
            $item['ves_relevance_label'] = function_exists('ves_social_relevance_label') ? ves_social_relevance_label($rel_class, $ctx) : $rel_class;
            $item['ves_relevance_explanation'] = $item['ves_relevance_label'] . (!empty($reasons) ? ' · ' . implode(', ', array_slice($reasons, 0, 3)) : '');
            // v0.9.31.5 BUG-02: surface the incidental-tag-match flag so the
            // frontend can render a small badge ("Mención de hashtag — no
            // contenido de marca"). The downgrade itself already happened
            // inside ves_classify_social_item_relevance.
            if (function_exists('ves_detect_competitor_authorship')) {
                $content_blob = function_exists('ves_item_content_blob_for_relevance') ? ves_item_content_blob_for_relevance($platform, $item) : '';
                $author_blob = function_exists('ves_item_author_blob_for_relevance') ? ves_item_author_blob_for_relevance($item) : '';
                $url_blob = function_exists('ves_item_url_blob_for_relevance') ? ves_item_url_blob_for_relevance($item) : '';
                if (ves_detect_competitor_authorship($content_blob, $author_blob, $url_blob, $ctx)) {
                    $item['ves_relevance_flag'] = 'incidental_tag_match';
                }
            }
            $ranked[] = ['idx' => $idx, 'score' => $score, 'item' => $item];
        }
        $threshold = !empty($ctx['strict_brand_context']) ? 6.0 : (count((array) ($ctx['tokens'] ?? [])) <= 1 ? 4.0 : 5.0);
        $kept = array_values(array_filter($ranked, static function ($row) use ($threshold) {
            return (float) ($row['score'] ?? 0) >= $threshold;
        }));
        if (empty($kept)) {
            return [[], count($ranked), true];
        }
        // v0.9.31.6 BUG-G: apply an effective-score penalty to items flagged
        // 'incidental_tag_match' so competitor posts where the queried brand
        // appears only in a hashtag rank below genuine matches. The penalty
        // is applied ONLY at sort time — the displayed ves_relevance_score
        // and the kept-threshold above are not affected, so flagged items
        // remain visible (with their badge) rather than being filtered out.
        $flag_penalty = 1.5;
        usort($kept, static function ($a, $b) use ($platform, $flag_penalty) {
            $flag_a = (isset($a['item']['ves_relevance_flag']) && $a['item']['ves_relevance_flag'] === 'incidental_tag_match') ? $flag_penalty : 0.0;
            $flag_b = (isset($b['item']['ves_relevance_flag']) && $b['item']['ves_relevance_flag'] === 'incidental_tag_match') ? $flag_penalty : 0.0;
            $eff_a = ((float) $a['score']) - $flag_a;
            $eff_b = ((float) $b['score']) - $flag_b;
            $score_cmp = $eff_b <=> $eff_a;
            if ($score_cmp !== 0) {
                return $score_cmp;
            }
            $am = function_exists('ves_item_filter_metric_value') ? ves_item_filter_metric_value($a['item'], $platform) : 0;
            $bm = function_exists('ves_item_filter_metric_value') ? ves_item_filter_metric_value($b['item'], $platform) : 0;
            $metric_cmp = $bm <=> $am;
            if ($metric_cmp !== 0) {
                return $metric_cmp;
            }
            return ((int) $a['idx']) <=> ((int) $b['idx']);
        });
        return [array_values(array_map(static fn($row) => $row['item'], $kept)), max(0, count($ranked) - count($kept)), true];
    }
}

if (!function_exists('ves_canonicalize_url_for_identity')) {
    /**
     * v1.1.0 (Phase 27B) — Canonicalize a URL for dedup IDENTITY only.
     *
     * Collapses obvious, safe URL variants so they resolve to one identity:
     *   - http vs https (scheme dropped)
     *   - trailing slash
     *   - fragment / hash (#...)
     *   - common tracking query params (utm_source/medium/campaign/term/content,
     *     fbclid, gclid); all other query params are preserved as-is.
     *
     * Returns '' when the value is not URL-like (e.g. an id/shortCode without a
     * host), so the caller falls back to its existing text-identity behavior.
     * Scope is strictly dedup identity: this does NOT alter stored URLs, display,
     * scoring, relevance, normalization, or evidence. It deliberately does NOT do
     * fuzzy/semantic or cross-source dedup.
     */
    function ves_canonicalize_url_for_identity($url) {
        $url = trim((string) $url);
        if ($url === '' || strpos($url, '://') === false) {
            return '';
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        // Phase 27B correction: preserve non-default ports so genuinely distinct
        // endpoints stay distinct; only drop the HTTP/HTTPS defaults (80/443).
        if (isset($parts['port']) && $parts['port'] !== '' && $parts['port'] !== null) {
            $port = (int) $parts['port'];
            if ($port !== 80 && $port !== 443) {
                $host .= ':' . $port;
            }
        }
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path === '/') {
            $path = '';
        } elseif ($path !== '') {
            $path = rtrim($path, '/');
        }
        $query = '';
        if (!empty($parts['query'])) {
            $tracking = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','fbclid','gclid'];
            $kept = [];
            foreach (explode('&', (string) $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $name = strtolower(explode('=', $pair, 2)[0]);
                if (in_array($name, $tracking, true)) {
                    continue;
                }
                $kept[] = $pair;
            }
            if (!empty($kept)) {
                $query = '?' . implode('&', $kept);
            }
        }
        return $host . $path . $query;
    }
}
if (!function_exists('ves_dedupe_items_by_identity')) {
    function ves_dedupe_items_by_identity($items) {
        if (!is_array($items)) {
            return [];
        }
        $seen = [];
        $out = [];
        $keys = ['ves_url','url','webVideoUrl','videoUrl','postUrl','permalink','link','id','videoId','shortCode'];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $identity = '';
            foreach ($keys as $key) {
                $v = function_exists('ves_first_text_path') ? ves_first_text_path($item, [$key]) : '';
                if ($v !== '') {
                    // Phase 27B: collapse safe URL variants before identity match;
                    // non-URL values (ids/shortcodes) fall back to text identity.
                    $canonical = function_exists('ves_canonicalize_url_for_identity') ? ves_canonicalize_url_for_identity($v) : '';
                    $identity = $canonical !== '' ? $canonical : ves_search_normalize_text($v);
                    break;
                }
            }
            if ($identity === '') {
                $identity = md5(wp_json_encode($item));
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $out[] = $item;
        }
        return array_values($out);
    }
}
if (!function_exists('ves_detect_primary_metric_label')) {
    function ves_detect_primary_metric_label($items, $platform = '') {
        if (is_array($items)) {
            foreach ($items as $item) {
                $label = trim((string) ves_arr_get($item, 'ves_primary_metric_label', ''));
                if ($label !== '') {
                    return $label;
                }
            }
        }
        $policy = function_exists('ves_platform_metric_policy') ? ves_platform_metric_policy($platform) : [];
        return (string) ($policy['primary_label'] ?? 'Views');
    }
}

if (!function_exists('ves_items_have_like_metric')) {
    function ves_items_have_like_metric($items) {
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['ves_has_like_metric'])) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ves_extract_openai_output_text')) {
    function ves_extract_openai_output_text($payload) {
        if (!is_array($payload)) {
            return '';
        }
        $chunks = [];
        if (!empty($payload['output_text']) && is_string($payload['output_text'])) {
            $chunks[] = trim($payload['output_text']);
        }
        foreach (($payload['output'] ?? []) as $output_item) {
            if (!is_array($output_item)) {
                continue;
            }
            if (!empty($output_item['text']) && is_string($output_item['text'])) {
                $chunks[] = trim($output_item['text']);
            }
            foreach (($output_item['content'] ?? []) as $content_item) {
                if (!is_array($content_item)) {
                    if (is_string($content_item)) {
                        $chunks[] = trim($content_item);
                    }
                    continue;
                }
                if (!empty($content_item['text']) && is_string($content_item['text'])) {
                    $chunks[] = trim($content_item['text']);
                }
                if (!empty($content_item['content']) && is_string($content_item['content'])) {
                    $chunks[] = trim($content_item['content']);
                }
                if (!empty($content_item['text']) && is_array($content_item['text']) && !empty($content_item['text']['value']) && is_string($content_item['text']['value'])) {
                    $chunks[] = trim($content_item['text']['value']);
                }
            }
        }
        if (empty($chunks) && !empty($payload['choices'][0]['message']['content']) && is_string($payload['choices'][0]['message']['content'])) {
            $chunks[] = trim($payload['choices'][0]['message']['content']);
        }
        return trim(implode("\n\n", array_filter($chunks)));
    }
}

if (!function_exists('ves_request_item_analysis')) {
    function ves_request_item_analysis($platform, $item, $focus = '', $output_language = 'es') {
        $api_key = ves_get_openai_api_key();
        if ($api_key === '') {
            return new WP_Error('missing_api_key', 'Falta configurar VE_SOCIAL_OPENAI_API_KEY para usar el análisis AI.');
        }

        $model = ves_get_openai_model();
        $focus = trim((string) $focus);
        $payload_item = [
            'platform' => sanitize_key((string) $platform),
            'title' => ves_first_non_empty(ves_arr_get($item, 'title', ''), ves_arr_get($item, 'ves_title', '')),
            'author' => ves_first_non_empty(ves_arr_get($item, 'author', ''), ves_arr_get($item, 'ves_author', '')),
            'url' => ves_first_non_empty(ves_arr_get($item, 'url', ''), ves_arr_get($item, 'ves_url', '')),
            'published_at' => ves_first_non_empty(ves_arr_get($item, 'published_at', ''), ves_arr_get($item, 'date', ''), ves_arr_get($item, 'ves_date', '')),
            'caption_or_text' => ves_first_non_empty(ves_arr_get($item, 'caption_or_text', ''), ves_arr_get($item, 'text', ''), ves_arr_get($item, 'ves_text', '')),
            'transcript' => ves_first_non_empty(ves_arr_get($item, 'transcript', ''), ves_arr_get($item, 'ves_transcript', '')),
            'metrics' => [
                'views' => ves_normalize_number(ves_arr_get($item, 'metrics.views', ves_arr_get($item, 'ves_views', 0))),
                'likes' => ves_normalize_number(ves_arr_get($item, 'metrics.likes', ves_arr_get($item, 'ves_likes', 0))),
                'comments' => ves_normalize_number(ves_arr_get($item, 'metrics.comments', ves_arr_get($item, 'ves_comments', 0))),
                'shares' => ves_normalize_number(ves_arr_get($item, 'metrics.shares', ves_arr_get($item, 'ves_shares', 0))),
                'duration' => ves_first_non_empty(ves_arr_get($item, 'metrics.duration', ''), ves_arr_get($item, 'ves_duration', '')),
            ],
            'raw' => is_array(ves_arr_get($item, 'raw', null)) ? ves_arr_get($item, 'raw', []) : $item,
        ];

        $output_language = sanitize_key((string) $output_language);
        $output_language = in_array($output_language, ['es','en','fa'], true) ? $output_language : 'es';
        $language_map = ['es' => 'Spanish', 'en' => 'English', 'fa' => 'Persian (Farsi)'];
        $instructions = 'Eres un analista senior de contenido social y growth. Respond entirely in ' . ($language_map[$output_language] ?? 'Spanish') . '. Analiza SOLO el contenido recibido. Devuelve una evaluación útil para marketing con estas secciones: 1) Resumen ejecutivo, 2) Hook y retención, 3) Mensaje y propuesta de valor, 4) Señales de rendimiento según métricas, 5) Recomendaciones accionables, 6) Ideas de test A/B. Sé concreto y evita relleno.';
        if ($focus !== '') {
            $instructions .= ' Prioriza especialmente este foco: ' . $focus . '.';
        }

        $request_body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => "Contenido a analizar:\n" . wp_json_encode($payload_item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'max_output_tokens' => 1600,
            'store' => false,
        ];

        $ves_ai_t0 = microtime(true); // Phase 1 cost-spine timing.
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($request_body),
        ]);

        if (is_wp_error($response)) {
            if (class_exists('VES_AI_Usage_Tracker')) {
                VES_AI_Usage_Tracker::record_openai_call([
                    'module' => 'social_analysis', 'operation_type' => 'analyze_item_legacy',
                    'model' => $model, 'started_at' => $ves_ai_t0, 'response' => $response,
                ]);
            }
            return new WP_Error('request_timeout', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (class_exists('VES_AI_Usage_Tracker')) {
            $ves_ai_ok = ($code >= 200 && $code < 300 && is_array($json));
            VES_AI_Usage_Tracker::record_openai_call([
                'module' => 'social_analysis', 'operation_type' => 'analyze_item_legacy',
                'model' => $model, 'started_at' => $ves_ai_t0,
                'response' => is_array($json) ? $json : null,
                'status' => $ves_ai_ok ? 'completed' : 'failed',
                'error_code' => $ves_ai_ok ? '' : ('http_' . (int) $code),
            ]);
        }

        if ($code === 401 || $code === 403) {
            return new WP_Error('invalid_api_key', 'La clave de OpenAI no es válida o no tiene permisos para este modelo.');
        }
        if ($code === 429) {
            return new WP_Error('rate_limited', 'OpenAI ha aplicado rate limit. Inténtalo de nuevo en unos minutos.');
        }
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            return new WP_Error('upstream_unavailable', 'No se pudo generar el análisis AI en este momento.');
        }

        $analysis = ves_extract_openai_output_text($json);
        if ($analysis === '') {
            return new WP_Error('invalid_response', 'La respuesta del modelo ha llegado vacía.');
        }

        return [
            'analysis' => $analysis,
            'model' => $model,
        ];
    }
}


// v0.9.30.4 — TikTok no-results marker detection.
// Some provider runs succeed but return marker rows such as { noResults: true }
// or { title: "No Results" }. These rows are not evidence and must never enter
// normalization, insight generation, or the user-visible result list.
if (!function_exists('ves_tiktok_item_is_no_results_marker')) {
    function ves_tiktok_item_is_no_results_marker($item) {
        if (!is_array($item) || empty($item)) { return false; }

        $has_real_content = false;
        $content_keys = ['webVideoUrl','videoUrl','shareUrl','postPage','postUrl','url','text','fullText','caption','description','body','message','authorName','username','uniqueId'];
        foreach ($content_keys as $key) {
            if (!array_key_exists($key, $item) || is_array($item[$key])) { continue; }
            $value = trim((string) $item[$key]);
            if ($value === '') { continue; }
            $norm = strtolower(preg_replace('/[\s_\-]+/', '', $value));
            if (!in_array($norm, ['noresult','noresults','noresultsfound'], true)) {
                $has_real_content = true;
                break;
            }
        }
        foreach (['authorMeta','author','user','video','musicMeta','authorStats'] as $key) {
            if (!empty($item[$key]) && is_array($item[$key])) {
                $has_real_content = true;
                break;
            }
        }
        foreach (['playCount','viewCount','views','diggCount','likeCount','likes','commentCount','comments','shareCount','shares','collectCount','saves'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key]) && (float) $item[$key] > 0) {
                $has_real_content = true;
                break;
            }
        }
        if ($has_real_content) { return false; }

        foreach (['noResults','no_results','noResult','no_results_marker','isNoResults'] as $key) {
            if (!array_key_exists($key, $item)) { continue; }
            $value = $item[$key];
            if ($value === true || $value === 1 || $value === '1' || $value === '' || $value === null || strtolower(trim((string) $value)) === 'true') {
                return true;
            }
        }

        foreach (['title','label','name','message','status','reason'] as $key) {
            if (!isset($item[$key]) || is_array($item[$key])) { continue; }
            $value = strtolower(trim((string) $item[$key]));
            $norm = preg_replace('/[\s_\-]+/', '', $value);
            if ($norm === 'noresults' || $norm === 'noresult' || strpos($value, 'no results') !== false || strpos($value, 'no usable results') !== false) {
                return true;
            }
        }

        $keys = array_map('strtolower', array_keys($item));
        $markerish = array_intersect($keys, ['noresults','no_results','noresult','query','searchquery','keyword','title','label','name']);
        $allowed = ['noresults','no_results','noresult','query','searchquery','keyword','title','label','name','searchsource','input','requestid'];
        return !empty($markerish) && count(array_diff($keys, $allowed)) === 0 && (in_array('noresults', $keys, true) || in_array('no_results', $keys, true) || in_array('noresult', $keys, true));
    }
}

if (!function_exists('ves_mark_no_results_marker')) {
    function ves_mark_no_results_marker($item) {
        $item = is_array($item) ? $item : [];
        $item['discard_reason'] = 'no_results_marker';
        $item['ves_discard_reason'] = 'no_results_marker';
        $item['evidence_quality'] = 'none';
        $item['usable'] = false;
        $item['ves_usable'] = false;
        return $item;
    }
}

if (!function_exists('ves_split_no_result_markers')) {
    function ves_split_no_result_markers($items) {
        $items = is_array($items) ? $items : [];
        $usable = [];
        $markers = [];
        foreach ($items as $item) {
            if (ves_tiktok_item_is_no_results_marker($item)) {
                $markers[] = ves_mark_no_results_marker($item);
                continue;
            }
            $usable[] = $item;
        }
        return [
            'items' => array_values($usable),
            'markers' => array_values($markers),
            'no_result_marker_count' => count($markers),
        ];
    }
}

// ─── Instagram hashtag metadata preprocessing ────────────────────────────────
// When the Apify search+searchType:hashtag path is used (old path or fallback),
// the actor returns hashtag DIRECTORY entities (with fields like searchSource,
// postsPerDay, topPosts) instead of actual post objects.
// This function detects that case and extracts the real posts from topPosts[].
if (!function_exists('ves_preprocess_tiktok_items')) {
    /**
     * v0.9.24.13 — TikTok profile-mode fix (rewritten).
     *
     * When clockworks/tiktok-scraper runs in `profiles` mode, the actor returns
     * one dataset item per PROFILE. That item is a profile container that looks like:
     *   { id: "123", authorMeta: { uniqueId, fans, ... }, videos: [ {id, text, ...}, ... ] }
     *
     * The previous version failed because it required !isset($item['id']) to detect
     * profile containers — but TikTok profile objects DO have an `id` field.
     *
     * This rewrite uses a simpler heuristic: an item is a "profile container" if:
     *   1. It has a nested array under any known video-list key (videos, latestVideos, etc.)
     *   2. AND those nested items look like video posts (have `id` or `text` or `webVideoUrl`)
     *   3. AND the outer item itself does NOT look like a video post
     *
     * Falls back to returning items unchanged if the heuristic doesn't trigger.
     */
    function ves_preprocess_tiktok_items($items) {
        if (!is_array($items) || empty($items)) { return $items; }

        // Keys the clockworks/tiktok-scraper may use for nested video/post lists.
        // 'posts' is the ACTUAL key used by clockworks/tiktok-scraper in profiles mode
        // when profileScrapeSections=["videos"] — confirmed from Apify console Output tab.
        $video_list_keys = ['posts', 'videos', 'latestVideos', 'video_list', 'videoList',
                            'topVideos', 'profileVideos', 'results', 'data', 'feed', 'feedItems', 'items'];

        // Fingerprint of a real TikTok VIDEO item (not a profile container).
        $looks_like_video = static function ($item) {
            return is_array($item) && (
                !empty($item['webVideoUrl'])
                || !empty($item['videoUrl'])
                || (!empty($item['id']) && !empty($item['text']))
                || (!empty($item['id']) && isset($item['diggCount']))
                || (!empty($item['id']) && isset($item['playCount']))
            );
        };

        $unwrapped = [];
        $any_unwrapped = false;

        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            if (function_exists('ves_tiktok_item_is_no_results_marker') && ves_tiktok_item_is_no_results_marker($item)) {
                $any_unwrapped = true;
                continue;
            }

            // If this item already looks like a video post, keep as-is.
            if ($looks_like_video($item)) {
                $unwrapped[] = $item;
                continue;
            }

            // Try to extract a nested video list from profile containers.
            $author_meta = $item['authorMeta'] ?? (is_array($item['author'] ?? null) ? $item['author'] : []);
            $extracted = false;

            foreach ($video_list_keys as $key) {
                if (empty($item[$key]) || !is_array($item[$key])) { continue; }
                // Validate that the nested array items are arrays (could be posts/videos).
                // Be lenient: clockworks/tiktok-scraper nested posts may not have all
                // fields filled in the first item. Just require it to be an array.
                $first_nested = reset($item[$key]);
                if (!is_array($first_nested)) { continue; }

                foreach ($item[$key] as $video) {
                    if (!is_array($video)) { continue; }
                    // Inject parent's authorMeta if the video is missing it.
                    if (empty($video['authorMeta']) && !empty($author_meta)) {
                        $video['authorMeta'] = $author_meta;
                    }
                    $unwrapped[] = $video;
                }
                $extracted = true;
                $any_unwrapped = true;
                break; // Use the first matching key only.
            }

            // If no nested videos found, keep the item as-is (may be stripped later).
            if (!$extracted) {
                $unwrapped[] = $item;
            }
        }

        return ($any_unwrapped || count($unwrapped) !== count($items)) ? array_values($unwrapped) : $items;
    }
}

if (!function_exists('ves_preprocess_instagram_items')) {
    function ves_preprocess_instagram_items($items) {
        if (!is_array($items) || empty($items)) {
            return $items;
        }

        // v0.9.10.5: Instagram actors may return hashtag/profile entity
        // containers instead of real post rows. The older implementation only
        // unpacked topPosts, so latestPosts/profile media could reach the UI as
        // fake results and then fail metric/date filters. Unpack the common
        // post arrays first, then dedupe by shortCode/id/url.
        $entity_count = 0;
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $is_entity = (
                (isset($item['searchSource']) || isset($item['postsPerDay']) || array_key_exists('topPosts', $item) || array_key_exists('latestPosts', $item) || array_key_exists('edge_owner_to_timeline_media', $item))
                && !isset($item['shortCode'])
                && !isset($item['ownerUsername'])
            );
            if ($is_entity) { $entity_count++; }
        }

        if ($entity_count === 0 || $entity_count < ceil(count($items) / 2)) {
            return $items;
        }

        $posts = [];
        $push_post = static function ($post) use (&$posts) {
            if (!is_array($post) || empty($post)) {
                return;
            }
            if (isset($post['node']) && is_array($post['node'])) {
                $post = $post['node'];
            }
            $looks_like_post = !empty($post['shortCode']) || !empty($post['shortcode']) || !empty($post['id']) || !empty($post['url']) || !empty($post['displayUrl']) || !empty($post['ownerUsername']) || !empty($post['caption']);
            if ($looks_like_post) {
                $posts[] = $post;
            }
        };

        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            foreach (['topPosts', 'latestPosts', 'posts', 'items'] as $key) {
                if (!isset($item[$key]) || !is_array($item[$key])) {
                    continue;
                }
                foreach ($item[$key] as $post) {
                    $push_post($post);
                }
            }
            if (isset($item['edge_owner_to_timeline_media']['edges']) && is_array($item['edge_owner_to_timeline_media']['edges'])) {
                foreach ($item['edge_owner_to_timeline_media']['edges'] as $edge) {
                    $push_post($edge);
                }
            }
        }

        if (!empty($posts)) {
            $seen = [];
            $unique = [];
            foreach ($posts as $post) {
                $key = $post['shortCode'] ?? $post['shortcode'] ?? $post['id'] ?? $post['url'] ?? $post['displayUrl'] ?? null;
                if ($key !== null) {
                    $key = (string) $key;
                    if (isset($seen[$key])) { continue; }
                    $seen[$key] = true;
                }
                $unique[] = $post;
            }
            return $unique;
        }

        // v0.9.16.9: If the actor returned only hashtag/profile/place directory
        // entities and no embedded posts, do not let those metadata rows pass as
        // fake content results. The UI should show a clean empty-result hint instead
        // of names like directory entries with postsCount as if they were posts.
        return [];
    }
}

if (!function_exists('ves_is_instagram_hashtag_entity')) {
    /**
     * Returns true if an already-enriched item appears to be a hashtag/location
     * metadata entity rather than an actual Instagram post.
     * Used by ves_apply_requested_filters to bypass metric filters for these items.
     */
    function ves_is_instagram_hashtag_entity($item) {
        if (!is_array($item)) { return false; }
        // Has metadata fields present in hashtag entities
        $has_entity_fields = (
            isset($item['searchSource']) ||
            isset($item['postsPerDay']) ||
            array_key_exists('topPosts', $item) ||
            array_key_exists('latestPosts', $item) ||
            array_key_exists('edge_owner_to_timeline_media', $item)
        );
        $has_post_fields = (
            !empty($item['shortCode']) ||
            !empty($item['ownerUsername']) ||
            !empty($item['caption'])
        );
        return $has_entity_fields && !$has_post_fields;
    }
}
