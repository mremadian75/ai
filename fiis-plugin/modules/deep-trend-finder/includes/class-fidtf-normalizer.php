<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Normalizer {
    public static function normalize(string $source_key, array $raw_item): array {
        $source_key = sanitize_key($source_key);
        $text = self::first_string($raw_item, ['caption', 'Caption', 'caption_text', 'edge_media_to_caption', 'text', 'description', 'Description', 'content', 'body', 'selftext', 'snippet', 'summary', 'title', 'Title', 'headline', 'desc', 'shortDescription', 'video.desc', 'authorMeta.signature', 'term', 'keyword', 'query', 'formattedTraffic', 'traffic', 'relatedQueries.query']);
        $title = self::first_string($raw_item, ['title', 'Title', 'headline', 'name', 'fullName', 'video_title', 'term', 'keyword', 'query']);
        $url = esc_url_raw(self::first_string($raw_item, ['share_url', 'url', 'web_url', 'link', 'postPage', 'Post URL', 'post_page', 'postUrl', 'post_url', 'postURL', 'shareUrl', 'permalink', 'webVideoUrl', 'videoWebUrl', 'video.webVideoUrl', 'video.playAddr.url_list', 'video.url', 'video.playAddr', 'video.play_addr.url_list', 'video.play_addr', 'trends_url', 'trendsUrl', 'newsUrl', 'articleUrl']));
        $external_id = self::first_string($raw_item, ['aweme_id', 'id', 'ID', 'external_id', 'post_id', 'video_id', 'shortcode', 'shortCode', 'code', 'tweet_id', 'article_id', 'guid', 'video.id', 'parsedId', 'term', 'keyword', 'query']);
        if ($external_id === '' && $url !== '') {
            $external_id = md5($source_key . '|' . $url);
        }
        if ($external_id === '') {
            $external_id = md5($source_key . '|' . wp_json_encode($raw_item));
        }

        $caption = sanitize_textarea_field($text);
        $normalized = [
            'schema_version' => 'fidtf.item.v1.2',
            'source' => $source_key,
            'source_key' => $source_key,
            'external_id' => sanitize_text_field($external_id),
            'url' => $url,
            'author' => sanitize_text_field(self::extract_author($raw_item)),
            'title' => sanitize_text_field($title),
            'text' => $caption,
            'caption' => $caption,
            'caption_or_text' => $caption,
            'published_at' => self::normalize_datetime(self::first_string($raw_item, ['published_at', 'publishedAt', 'created_at', 'createdAt', 'createTimeISO', 'createTime', 'create_time', 'timestamp', 'date', 'time', 'taken_at_timestamp', 'uploadedAtFormatted', 'Uploaded At Formatted', 'uploaded_at_formatted', 'uploadedAt', 'Uploaded At', 'uploaded_at'])),
            'language' => sanitize_key(self::first_string($raw_item, ['language', 'lang', 'locale', 'textLanguage', 'text_language'])),
            'market' => sanitize_text_field(self::first_string($raw_item, ['market', 'country', 'region'])),
            'source_country' => sanitize_text_field(self::first_string($raw_item, ['locationCreated', 'location_created', 'locationMeta.countryCode', 'countryCode', 'country_code', 'country'])),
            'metrics' => self::extract_metrics($raw_item),
            'hashtags' => self::extract_hashtags($raw_item),
            'provider_query' => sanitize_text_field(self::first_string($raw_item, ['inputSource', 'input_source', 'searchQuery', 'search_query', 'search_keyword', 'keyword', 'query', 'metadata.keyword', 'metadata.query', 'searchTerm', 'search', 'provider_query'])),
            'media' => self::extract_media($raw_item, $source_key),
            'transcript' => self::extract_transcript($raw_item),
            'subtitles' => self::extract_subtitles($raw_item),
            'comments_sample' => self::extract_comments($raw_item),
            'trend_timeseries' => self::extract_trend_timeseries($raw_item),
            'raw' => $raw_item,
        ];

        $normalized = self::apply_source_overrides($source_key, $raw_item, $normalized);
        if ($source_key === 'google_trends') {
            $normalized = self::apply_google_trends_summary($normalized);
        }
        $normalized['evidence_quality'] = self::evidence_quality($normalized);
        return $normalized;
    }

    public static function normalize_many(string $source_key, array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = self::normalize($source_key, $item);
            }
        }
        return $out;
    }

    public static function parse_metric_value($value): ?float {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $compact = preg_replace('/\s+/u', '', $raw);
        if ($compact === null || $compact === '') {
            return null;
        }
        $compact = str_replace([',', '+'], '', $compact);
        if (preg_match('/^([-+]?\d+(?:\.\d+)?)([KMB])?$/i', $compact, $m)) {
            $number = (float) $m[1];
            $suffix = strtoupper($m[2] ?? '');
            if ($suffix === 'K') { $number *= 1000; }
            if ($suffix === 'M') { $number *= 1000000; }
            if ($suffix === 'B') { $number *= 1000000000; }
            return $number;
        }
        if (is_numeric($compact)) {
            return (float) $compact;
        }
        return null;
    }

    public static function evidence_quality(array $normalized): array {
        $metrics = (array) ($normalized['metrics'] ?? []);
        $media = (array) ($normalized['media'] ?? []);
        $comments = (array) ($normalized['comments_sample'] ?? []);
        $source_key = sanitize_key((string) ($normalized['source_key'] ?? ''));
        $has_text = !empty($normalized['caption_or_text']) || !empty($normalized['title']) || !empty($normalized['text']);
        $has_metrics = self::has_non_null_metric($metrics);
        $has_video_file = !empty($media['downloaded_video_path']);
        $has_audio = !empty($media['audio_path']);
        $has_transcript = !empty($normalized['transcript']) || !empty($normalized['subtitles']);
        $has_article = $source_key === 'google_news' && ($has_text || !empty($normalized['raw']['snippet']) || !empty($normalized['raw']['summary']));
        $has_trend_series = $source_key === 'google_trends' && !empty($normalized['trend_timeseries']);
        $is_social = in_array($source_key, ['tiktok','instagram','reddit'], true);
        $has_source_content = $has_text || $has_metrics || !empty($normalized['published_at']) || !empty($normalized['author']) || !empty($normalized['hashtags']) || !empty($comments);
        $provider_malformed_empty_item = $is_social && !empty($normalized['url']) && !$has_source_content;

        $score = 0;
        $score += !empty($normalized['url']) ? 20 : 0;
        $score += $has_text ? 30 : 0;
        $score += $has_metrics ? 20 : 0;
        $score += !empty($normalized['published_at']) ? 10 : 0;
        $score += !empty($normalized['author']) ? 10 : 0;
        $score += !empty($comments) ? 10 : 0;
        if ($provider_malformed_empty_item) { $score = min($score, 5); }

        return [
            'score' => min(100, $score),
            'caption_or_text' => $has_text,
            'metrics' => $has_metrics,
            'comments' => !empty($comments),
            'video_file' => $has_video_file,
            'video_frames' => false,
            'audio' => $has_audio,
            'transcript' => $has_transcript,
            'article_snippet' => $has_article,
            'trend_timeseries' => $has_trend_series,
            'has_url' => !empty($normalized['url']),
            'has_text' => $has_text,
            'has_metrics' => $has_metrics,
            'has_date' => !empty($normalized['published_at']),
            'has_transcript' => $has_transcript,
            'deep_video_analyzed' => false,
            'provider_malformed_empty_item' => $provider_malformed_empty_item,
            'limitations' => self::limitations($normalized, $has_video_file, $has_audio, $has_transcript, $has_trend_series),
        ];
    }

    private static function apply_source_overrides(string $source_key, array $raw_item, array $normalized): array {
        if ($source_key === 'tiktok') {
            $fallback = self::first_string($raw_item, ['desc', 'description', 'text', 'title', 'video_title']);
            $normalized['title'] = $normalized['title'] ?: sanitize_text_field($fallback);
            $normalized['caption_or_text'] = $normalized['caption_or_text'] ?: sanitize_textarea_field($fallback);
            $normalized['caption'] = $normalized['caption'] ?: $normalized['caption_or_text'];
            $normalized['text'] = $normalized['text'] ?: $normalized['caption_or_text'];
        }
        if ($source_key === 'instagram') {
            $caption = self::first_string($raw_item, ['caption_text', 'edge_media_to_caption', 'caption', 'alt']);
            $normalized['caption_or_text'] = $normalized['caption_or_text'] ?: sanitize_textarea_field($caption);
            $normalized['caption'] = $normalized['caption'] ?: $normalized['caption_or_text'];
            $normalized['text'] = $normalized['text'] ?: $normalized['caption_or_text'];
            $normalized['author'] = $normalized['author'] ?: sanitize_text_field(self::first_string($raw_item, ['ownerUsername', 'ownerFullName', 'username']));
        }
        if ($source_key === 'reddit') {
            $title = self::first_string($raw_item, ['title']);
            $body = self::first_string($raw_item, ['selftext', 'body', 'text']);
            $normalized['title'] = sanitize_text_field($title ?: $normalized['title']);
            $normalized['caption_or_text'] = sanitize_textarea_field(trim($body ?: $normalized['caption_or_text']));
            $normalized['text'] = $normalized['caption_or_text'];
            $normalized['author'] = $normalized['author'] ?: sanitize_text_field(self::first_string($raw_item, ['username', 'author', 'communityName']));
            $normalized['provider_query'] = $normalized['provider_query'] ?: sanitize_text_field(self::first_string($raw_item, ['search', 'searchTerm', 'metadata.keyword']));
        }
        if ($source_key === 'google_trends') {
            $term = self::first_string($raw_item, ['term', 'keyword', 'query', 'title']);
            if ($term !== '') {
                $normalized['title'] = $normalized['title'] ?: sanitize_text_field($term);
                $normalized['caption_or_text'] = $normalized['caption_or_text'] ?: sanitize_textarea_field($term);
                $normalized['text'] = $normalized['text'] ?: $normalized['caption_or_text'];
                $normalized['provider_query'] = $normalized['provider_query'] ?: sanitize_text_field($term);
            }
            if (!isset($normalized['metrics']['trend_interest']) || $normalized['metrics']['trend_interest'] === null) {
                $interest = self::first_numeric($raw_item, ['trend_volume', 'interest', 'value', 'trend_interest', 'search_interest']);
                foreach (['timeline_data', 'timelineData', 'interestOverTime', 'interest_over_time'] as $series_key) {
                    if ($interest === null && isset($raw_item[$series_key]) && is_array($raw_item[$series_key])) {
                        $values = self::numeric_values_from_trend_series((array) $raw_item[$series_key]);
                        if (!empty($values)) { $interest = max($values); }
                    }
                }
                if ($interest !== null) { $normalized['metrics']['trend_interest'] = $interest; }
            }
        }
        if ($source_key === 'google_news') {
            $description = self::first_string($raw_item, ['description', 'snippet', 'summary', 'content', 'text']);
            $normalized['caption_or_text'] = $normalized['caption_or_text'] ?: sanitize_textarea_field($description);
            $normalized['text'] = $normalized['text'] ?: $normalized['caption_or_text'];
            $normalized['author'] = $normalized['author'] ?: sanitize_text_field(self::first_string($raw_item, ['source', 'publisher', 'sourceName']));
            $normalized['provider_query'] = $normalized['provider_query'] ?: sanitize_text_field(self::first_string($raw_item, ['metadata.keyword', 'metadata.query', 'keyword', 'query']));
        }
        return $normalized;
    }

    private static function extract_author(array $item): string {
        foreach (['author', 'username', 'userName', 'uniqueId', 'unique_id', 'sec_uid', 'user', 'owner', 'ownerUsername', 'ownerFullName', 'channel.username', 'Channel Username', 'channel.name', 'Channel Name', 'channelUsername', 'channelName', 'channel', 'publisher', 'source', 'communityName', 'authorMeta', 'author.meta'] as $key) {
            if (!isset($item[$key])) { continue; }
            if (is_scalar($item[$key])) { return (string) $item[$key]; }
            if (is_array($item[$key])) {
                $candidate = self::first_string($item[$key], ['unique_id', 'uniqueId', 'sec_uid', 'secUid', 'username', 'name', 'display_name', 'nickname', 'nickName', 'title']);
                if ($candidate !== '') { return $candidate; }
            }
        }
        return '';
    }

    private static function extract_hashtags(array $item): array {
        $raw = $item['hashtags'] ?? $item['hashTags'] ?? $item['tags'] ?? $item['challengeInfoList'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/u', $raw) ?: [];
        }
        $out = [];
        foreach ((array) $raw as $tag) {
            if (is_array($tag)) {
                $tag = self::first_string($tag, ['name', 'title', 'hashtag_name', 'challengeName', 'text']);
            }
            if (!is_scalar($tag)) { continue; }
            $tag = trim((string) $tag);
            $tag = ltrim($tag, '#');
            if ($tag === '') { continue; }
            $out[] = sanitize_text_field($tag);
            if (count($out) >= 30) { break; }
        }
        return array_values(array_unique($out));
    }

    private static function extract_metrics(array $item): array {
        $map = [
            'views' => ['views', 'Views', 'viewCount', 'view_count', 'playCount', 'play_count', 'videoViewCount', 'videoPlayCount', 'playCount', 'viewCount', 'video_view_count', 'views_or_plays', 'stats.playCount', 'stats.play_count', 'statistics.playCount', 'statistics.play_count'],
            'likes' => ['likes', 'Likes', 'likeCount', 'like_count', 'likesCount', 'diggCount', 'digg_count', 'favorites', 'favoriteCount', 'upVotes', 'upvotes', 'ups', 'stats.diggCount', 'stats.digg_count', 'statistics.diggCount', 'statistics.digg_count'],
            'comments' => ['comments', 'Comments', 'commentCount', 'comment_count', 'commentsCount', 'numberOfComments', 'numComments', 'commentsCount', 'stats.commentCount', 'stats.comment_count', 'statistics.commentCount', 'statistics.comment_count'],
            'shares' => ['shares', 'Shares', 'shareCount', 'share_count', 'sharesCount', 'stats.shareCount', 'stats.share_count', 'statistics.shareCount', 'statistics.share_count'],
            'saves' => ['saves', 'Bookmarks', 'bookmarks', 'saveCount', 'save_count', 'collectCount', 'collect_count', 'saves_or_collects', 'bookmarks', 'bookmarkCount', 'stats.collectCount', 'stats.collect_count', 'statistics.collectCount', 'statistics.collect_count'],
            'score' => ['score', 'upvotes', 'upVotes', 'ups', 'points', 'rank'],
            'trend_interest' => ['trend_interest', 'search_interest', 'interest', 'value'],
            'trend_traffic' => ['trend_volume', 'formattedTraffic', 'traffic', 'approxTraffic'],
        ];
        $out = array_fill_keys(array_keys($map), null);
        foreach ($map as $name => $keys) {
            $out[$name] = self::first_numeric($item, $keys);
        }
        return $out;
    }

    private static function first_numeric(array $item, array $keys): ?float {
        $containers = [$item];
        foreach (['metrics', 'statistics', 'stats', 'counts', 'engagement', 'metadata'] as $container_key) {
            if (isset($item[$container_key]) && is_array($item[$container_key])) { $containers[] = $item[$container_key]; }
        }
        foreach ($containers as $container) {
            foreach ($keys as $key) {
                $value = self::value_for_key($container, (string) $key);
                if ($value !== null) {
                    $parsed = self::parse_metric_value($value);
                    if ($parsed !== null) { return $parsed; }
                }
            }
        }
        return null;
    }

    private static function extract_media(array $item, string $source_key): array {
        $thumbnail = esc_url_raw(self::first_string($item, ['thumbnail_url', 'thumbnail', 'thumbnailUrl', 'cover', 'coverUrl', 'displayUrl', 'image', 'image_url', 'imageUrl', 'image.src', 'images.0', 'images.0.url', 'images.0.src', 'media.0.url', 'media.0.src', 'video.thumbnail', 'video.cover.url_list', 'video.cover', 'video.dynamicCover', 'video.originCover', 'channel.avatar', 'Channel Avatar', 'avatar']));
        $video_url = esc_url_raw(self::first_string($item, ['video_url', 'videoUrl', 'video_url_https', 'playAddr', 'downloadAddr', 'videoDownloadUrl', 'mediaUrl', 'video.url', 'video.play_addr.url_list', 'video.playAddr.url_list', 'video.playAddr', 'video.downloadAddr']));
        $image_url = esc_url_raw(self::first_string($item, ['image_url', 'imageUrl', 'displayUrl', 'display_url', 'photo_url', 'thumbnail_url', 'image', 'image.src', 'images.0', 'images.0.url', 'images.0.src']));
        $explicit_media_url = esc_url_raw(self::first_string($item, ['media_url', 'mediaUrl', 'media_uri', 'video.mediaUrl']));
        $downloaded = sanitize_text_field(self::first_string($item, ['downloaded_video_path', 'video_path', 'local_video_path']));
        $audio = sanitize_text_field(self::first_string($item, ['audio_path', 'local_audio_path', 'music.playUrl']));
        $raw_type = sanitize_key(self::first_string($item, ['media_type', 'type']));
        $media_url = $video_url ?: ($explicit_media_url ?: $image_url);
        $type = self::media_type_for($source_key, $raw_type, $video_url, $image_url, $explicit_media_url, $thumbnail);

        $media = [
            'type' => $type,
            'thumbnail_url' => $thumbnail ?: null,
            'media_url' => $media_url ?: null,
            'downloaded_video_path' => $downloaded ?: null,
            'audio_path' => $audio ?: null,
        ];
        if (isset($item['media']) && is_array($item['media'])) {
            $media['provider_media'] = self::safe_compact_array($item['media']);
        }
        if (isset($item['video']) && is_array($item['video'])) {
            $media['provider_video'] = self::safe_compact_array($item['video']);
        }
        if (isset($item['music']) && is_array($item['music'])) {
            $media['music'] = self::safe_compact_array($item['music']);
        }
        return $media;
    }

    private static function media_type_for(string $source_key, string $raw_type, string $video_url, string $image_url, string $media_url, string $thumbnail): string {
        $allowed = ['video', 'image', 'text', 'article', 'trend', 'unknown'];
        if ($raw_type === 'photo') { $raw_type = 'image'; }
        if ($raw_type === 'reel' || $raw_type === 'short' || $raw_type === 'clip') { $raw_type = 'video'; }
        if (in_array($raw_type, $allowed, true)) {
            return $raw_type;
        }
        $source_key = sanitize_key($source_key);
        if ($source_key === 'google_news') { return 'article'; }
        if ($source_key === 'google_trends') { return 'trend'; }
        if ($source_key === 'reddit' || $source_key === 'ai_research') { return 'text'; }
        if ($video_url !== '' || ($media_url !== '' && preg_match('/\.(mp4|mov|webm)(\?|$)/i', $media_url))) { return 'video'; }
        if ($image_url !== '' || $thumbnail !== '' || $media_url !== '') { return 'image'; }
        return 'text';
    }

    private static function extract_transcript(array $item): array {
        $transcript = self::first_string($item, ['transcript', 'transcript_text', 'subtitle', 'captions']);
        if ($transcript === '') { return []; }
        return [
            'text' => sanitize_textarea_field($transcript),
            'source' => 'provider_payload',
            'deep_video_analyzed' => false,
            'provider_malformed_empty_item' => $provider_malformed_empty_item,
        ];
    }

    private static function extract_subtitles(array $item): array {
        $subtitles = $item['subtitles'] ?? $item['subtitle_lines'] ?? $item['subtitleInformation'] ?? $item['video']['subtitleInfos'] ?? [];
        $out = [];
        if (is_string($subtitles)) {
            $out[] = ['text' => sanitize_textarea_field($subtitles)];
        } elseif (is_array($subtitles)) {
            foreach ($subtitles as $row) {
                if (is_string($row)) { $out[] = ['text' => sanitize_textarea_field($row)]; }
                elseif (is_array($row)) {
                    $text = self::first_string($row, ['text', 'caption', 'value']);
                    if ($text !== '') { $out[] = ['text' => sanitize_textarea_field($text)]; }
                }
                if (count($out) >= 20) { break; }
            }
        }
        return $out;
    }

    private static function extract_comments(array $item): array {
        $comments = $item['comments'] ?? $item['top_comments'] ?? $item['comments_sample'] ?? [];
        if (isset($item['edge_media_to_comment']['edges']) && is_array($item['edge_media_to_comment']['edges'])) {
            $comments = $item['edge_media_to_comment']['edges'];
        }
        $out = [];
        foreach ((array) $comments as $comment) {
            if (is_string($comment)) {
                $out[] = sanitize_textarea_field($comment);
            } elseif (is_array($comment)) {
                $text = self::first_string($comment, ['text', 'body', 'comment']);
                if ($text === '' && isset($comment['node']) && is_array($comment['node'])) {
                    $text = self::first_string($comment['node'], ['text', 'body', 'comment']);
                }
                if ($text !== '') { $out[] = sanitize_textarea_field($text); }
            }
            if (count($out) >= 10) { break; }
        }
        return $out;
    }


    private static function apply_google_trends_summary(array $normalized): array {
        $series = (array) ($normalized['trend_timeseries'] ?? []);
        if (empty($series)) { return $normalized; }
        $values = [];
        foreach ($series as $row) {
            if (!is_array($row) || !array_key_exists('value', $row)) { continue; }
            $parsed = self::parse_metric_value($row['value']);
            if ($parsed !== null) { $values[] = $parsed; }
        }
        if (empty($values)) { return $normalized; }
        $first = (float) reset($values);
        $latest = (float) end($values);
        $peak = max($values);
        $average = array_sum($values) / max(1, count($values));
        $delta = $latest - $first;
        $metrics = (array) ($normalized['metrics'] ?? []);
        $metrics['trend_interest'] = $metrics['trend_interest'] ?? $peak;
        $metrics['trend_peak'] = $peak;
        $metrics['trend_latest'] = $latest;
        $metrics['trend_average'] = round($average, 2);
        $metrics['trend_delta'] = round($delta, 2);
        $metrics['trend_points'] = count($values);
        $metrics['trend_direction'] = $delta >= 5 ? 'rising' : ($delta <= -5 ? 'falling' : 'stable');
        $normalized['metrics'] = $metrics;
        if (empty($normalized['title']) && !empty($normalized['provider_query'])) {
            $normalized['title'] = sanitize_text_field((string) $normalized['provider_query']);
        }
        if (empty($normalized['caption_or_text'])) {
            $normalized['caption_or_text'] = sanitize_textarea_field(sprintf(
                'Google Trends interest for %s in %s: peak %s, latest %s, average %s, direction %s.',
                (string) ($normalized['provider_query'] ?? $normalized['title'] ?? 'keyword'),
                (string) ($normalized['market'] ?? $normalized['raw']['geo'] ?? ''),
                (string) $peak,
                (string) $latest,
                (string) round($average, 2),
                (string) $metrics['trend_direction']
            ));
            $normalized['text'] = $normalized['caption_or_text'];
            $normalized['caption'] = $normalized['caption_or_text'];
        }
        return $normalized;
    }

    private static function extract_trend_timeseries(array $item): array {
        foreach (['trend_timeseries', 'timeseries', 'timeline', 'interest_over_time', 'interestOverTime', 'timeline_data', 'timelineData', 'regional_data', 'regionalData'] as $key) {
            if (!empty($item[$key]) && is_array($item[$key])) {
                $series = (array) $item[$key];
                if (in_array($key, ['timeline_data', 'timelineData'], true)) {
                    $flattened = self::flatten_google_trends_timeline_data($series, (string) ($item['keyword'] ?? $item['query'] ?? ''));
                    if (!empty($flattened)) { return array_slice($flattened, 0, 120); }
                }
                return array_slice($series, 0, 120);
            }
        }
        return [];
    }

    private static function numeric_values_from_trend_series(array $series): array {
        $values = [];
        foreach ($series as $key => $row) {
            if (is_array($row)) {
                foreach (['value', 'interest', 'formattedValue', 'trend_interest', 'trend_volume', 'trend_volume_formatted'] as $metric_key) {
                    if (array_key_exists($metric_key, $row)) {
                        $parsed = self::parse_metric_value($row[$metric_key]);
                        if ($parsed !== null) { $values[] = $parsed; }
                    }
                }
                // Live Data Xplorer Google Trends keyword mode returns:
                // timeline_data: { keyword: { YYYY-MM-DD: number }, isPartial: { ... } }
                // Recurse into the keyword map but ignore boolean partial flags.
                $is_partial_map = strtolower((string) $key) === 'ispartial';
                if (!$is_partial_map) {
                    foreach (self::numeric_values_from_trend_series($row) as $nested_value) {
                        $values[] = $nested_value;
                    }
                }
            } else {
                if (is_bool($row)) { continue; }
                $parsed = self::parse_metric_value($row);
                if ($parsed !== null) { $values[] = $parsed; }
            }
        }
        return $values;
    }

    private static function flatten_google_trends_timeline_data(array $series, string $fallback_label = ''): array {
        $out = [];
        $partial = [];
        foreach ($series as $series_label => $rows) {
            if (strtolower((string) $series_label) === 'ispartial' && is_array($rows)) {
                $partial = $rows;
                continue;
            }
            if (!is_array($rows)) { continue; }
            foreach ($rows as $date => $value) {
                if (is_bool($value)) { continue; }
                $parsed = self::parse_metric_value($value);
                if ($parsed === null) { continue; }
                $out[] = [
                    'date' => sanitize_text_field((string) $date),
                    'value' => $parsed,
                    'series' => sanitize_text_field((string) ($series_label !== '' ? $series_label : $fallback_label)),
                    'is_partial' => !empty($partial[$date]),
                ];
            }
        }
        return $out;
    }

    private static function first_string(array $item, array $keys): string {
        foreach ($keys as $key) {
            $value = self::value_for_key($item, (string) $key);
            if ($value === null) { continue; }
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') { return $value; }
            }
            if (is_array($value)) {
                if (self::is_list_array($value)) {
                    foreach ($value as $entry) {
                        if (is_scalar($entry)) {
                            $entry = trim((string) $entry);
                            if ($entry !== '') { return $entry; }
                        }
                        if (is_array($entry)) {
                            $nested_entry = self::first_string($entry, ['url', 'text', 'value', 'title', 'name']);
                            if ($nested_entry !== '') { return $nested_entry; }
                        }
                    }
                }
                $nested = self::first_string($value, ['text', 'value', 'url', 'title', 'name', 'url_list']);
                if ($nested !== '') { return $nested; }
            }
        }
        return '';
    }



    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    private static function value_for_key(array $item, string $key) {
        if (array_key_exists($key, $item)) { return $item[$key]; }
        if (strpos($key, '.') === false) { return null; }
        $value = $item;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { return null; }
            $value = $value[$part];
        }
        return $value;
    }

    private static function normalize_datetime(string $value): ?string {
        $value = trim($value);
        if ($value === '') { return null; }
        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 20000000000) { $timestamp = (int) floor($timestamp / 1000); }
            return gmdate('Y-m-d H:i:s', $timestamp);
        }
        $timestamp = strtotime($value);
        if (!$timestamp) { return null; }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function safe_compact_array(array $value): array {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $out[sanitize_key((string) $key)] = sanitize_text_field((string) $item);
            }
        }
        return $out;
    }

    private static function has_non_null_metric(array $metrics): bool {
        foreach ($metrics as $value) {
            if ($value !== null && is_numeric($value)) { return true; }
        }
        return false;
    }

    private static function limitations(array $normalized, bool $has_video_file, bool $has_audio, bool $has_transcript, bool $has_trend_series): array {
        $limitations = [];
        $media = (array) ($normalized['media'] ?? []);
        $source_key = sanitize_key((string) ($normalized['source_key'] ?? ''));
        if (empty($normalized['url'])) { $limitations[] = 'Missing public URL.'; }

        // Live QA v0.3.36: avoid polluting non-video source cards with irrelevant
        // video/transcript limitations. Google Trends, Google News and Reddit are
        // not expected to provide frame/audio/transcript evidence in the first
        // place, so their limitations should describe their own evidence boundary.
        if ($source_key === 'google_trends') {
            if (!$has_trend_series) { $limitations[] = 'No Google Trends time series was available.'; }
            if (empty($normalized['metrics']) || !self::has_non_null_metric((array) $normalized['metrics'])) { $limitations[] = 'No trend intensity metric was available.'; }
            return array_values(array_unique($limitations));
        }
        if ($source_key === 'google_news') {
            if (empty($normalized['caption_or_text']) && empty($normalized['title'])) { $limitations[] = 'No article title or description was available.'; }
            $limitations[] = 'No engagement metrics are expected from Google News results.';
            return array_values(array_unique($limitations));
        }
        if ($source_key === 'reddit') {
            if (empty($normalized['metrics']) || !self::has_non_null_metric((array) $normalized['metrics'])) { $limitations[] = 'No Reddit engagement metrics were available.'; }
            if (empty($normalized['caption_or_text']) && empty($normalized['title'])) { $limitations[] = 'No Reddit post text/title was available.'; }
            return array_values(array_unique($limitations));
        }

        if (empty($normalized['metrics']) || !self::has_non_null_metric((array) $normalized['metrics'])) { $limitations[] = 'No engagement metrics were available.'; }
        if (!$has_transcript) {
            $limitations[] = 'No transcript was available.';
            $limitations[] = 'No transcript or deep video understanding was available.';
        }
        if (!$has_audio) { $limitations[] = 'No audio analysis was performed.'; }
        $limitations[] = 'No frame-by-frame visual analysis was performed.';
        if (!$has_video_file && !empty($media['media_url']) && ($media['type'] ?? '') === 'video') {
            $limitations[] = 'Video URL may be available, but the video file was not downloaded or analyzed.';
        } elseif (!$has_video_file) {
            $limitations[] = 'No downloaded video file was available.';
        }
        return array_values(array_unique($limitations));
    }
}
