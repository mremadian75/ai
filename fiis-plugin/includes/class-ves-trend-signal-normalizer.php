<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Signal_Normalizer {
    private static function lower($text) { return function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text); }

    private static function read($row, $key) {
        if (!is_array($row)) { return null; }
        if (array_key_exists($key, $row)) { return $row[$key]; }
        if (strpos($key, '.') === false) { return null; }
        $value = $row;
        foreach (explode('.', $key) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) { $value = $value[$part]; }
            else { return null; }
        }
        return $value;
    }

    private static function text($row, $keys) {
        foreach ((array) $keys as $k) {
            $v = self::read($row, $k);
            if (is_array($v)) { $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE); }
            if ($v !== null && trim((string) $v) !== '') { return sanitize_text_field((string) $v); }
        }
        return '';
    }

    private static function num($row, $keys) {
        foreach ((array) $keys as $k) {
            $v = self::read($row, $k);
            if (is_string($v)) { $v = preg_replace('/[^0-9.\-]/', '', $v); }
            if ($v !== null && $v !== '' && is_numeric($v)) { return (float) $v; }
        }
        return null;
    }

    private static function url($row, $keys) {
        foreach ((array) $keys as $k) {
            $v = self::read($row, $k);
            if ($v !== null && trim((string) $v) !== '') { return esc_url_raw((string) $v); }
        }
        return '';
    }

    private static function token_terms($request) {
        $terms = [];
        foreach (['topic','query','category'] as $k) { if (!empty($request[$k])) { $terms[] = (string) $request[$k]; } }
        if (!empty($request['expanded_queries']) && is_array($request['expanded_queries'])) {
            foreach (['seed_terms','local_terms','question_terms','platform_terms','hashtag_candidates','news_terms','competitor_terms','language_variants'] as $group) {
                foreach ((array) ($request['expanded_queries'][$group] ?? []) as $term) { $terms[] = (string) $term; }
            }
        }
        $out = [];
        foreach ($terms as $term) {
            $term = trim(str_replace('#', ' ', self::lower($term)));
            if ($term === '') { continue; }
            $out[] = $term;
            foreach (preg_split('/\s+/u', $term) as $tok) {
                $tok = trim($tok);
                if (function_exists('mb_strlen') ? mb_strlen($tok, 'UTF-8') >= 3 : strlen($tok) >= 3) { $out[] = $tok; }
            }
        }
        return array_values(array_unique($out));
    }

    private static function negative_terms($request) {
        $terms = [];
        if (!empty($request['negative_terms']) && is_array($request['negative_terms'])) { $terms = $request['negative_terms']; }
        if (!empty($request['expanded_queries']['negative_terms']) && is_array($request['expanded_queries']['negative_terms'])) { $terms = array_merge($terms, $request['expanded_queries']['negative_terms']); }
        return array_values(array_unique(array_filter(array_map([__CLASS__, 'lower'], $terms))));
    }

    private static function match($haystack, $terms, $negative_terms = []) {
        $h = self::lower($haystack);
        foreach ((array) $negative_terms as $negative) {
            $negative = trim(self::lower($negative));
            if ($negative !== '' && strpos($h, $negative) !== false) { return [0.0, [], 'negative_term:' . $negative]; }
        }
        $matched = [];
        $score = 0.0;
        foreach ((array) $terms as $term) {
            $term = trim(self::lower($term));
            if ($term === '') { continue; }
            if (strpos($h, $term) !== false) {
                $matched[] = $term;
                $score += (strpos($term, ' ') !== false) ? 0.35 : 0.12;
            }
        }
        $score = min(1.0, $score);
        return [$score, array_values(array_unique($matched)), ''];
    }

    private static function metric_status($metrics) {
        $out = [];
        foreach ($metrics as $k => $v) { $out[$k] = $v === null ? 'missing' : 'observed'; }
        return $out;
    }

    private static function expected_metrics($source_key, $family) {
        $source_key = sanitize_key((string) $source_key);
        if (strpos($source_key, 'google_trends') === 0) { return ['search_interest','growth','rank']; }
        if (strpos($source_key, 'reddit') === 0) { return ['upvotes','comments']; }
        if (strpos($source_key, 'youtube') === 0) { return ['views','likes','comments']; }
        if (strpos($source_key, 'tiktok') === 0 || strpos($source_key, 'instagram') === 0) { return ['views','likes','comments','shares','saves']; }
        if (strpos($source_key, 'x_') === 0 || strpos($source_key, 'twitter') !== false) { return ['retweets','likes','comments']; }
        if ($family === 'search_demand') { return ['search_interest','rank']; }
        return ['views','likes','comments','rank'];
    }

    private static function metric_completeness($source_key, $family, $metrics) {
        $expected = self::expected_metrics($source_key, $family);
        $checks = 0; $hits = 0;
        foreach ($expected as $field) {
            $checks++;
            if (array_key_exists($field, $metrics) && $metrics[$field] !== null) { $hits++; }
        }
        return $checks > 0 ? round($hits / $checks, 3) : 0.0;
    }

    private static function metric_strength($source_key, $metrics) {
        $vals = [];
        foreach (['views','likes','comments','shares','saves','upvotes','retweets','search_interest','growth','velocity'] as $k) {
            if (isset($metrics[$k]) && is_numeric($metrics[$k])) { $vals[] = (float) $metrics[$k]; }
        }
        if (!$vals) { return 0.0; }
        $max = max($vals);
        if ($max <= 0) { return 0.0; }
        if (strpos($source_key, 'google_trends') === 0) { return min(1.0, $max / 100.0); }
        return min(1.0, log10($max + 1) / 6);
    }

    private static function freshness_score($published_at, $duration) {
        $duration = sanitize_key((string) $duration);
        $days = 30;
        if ($duration === '24h' || $duration === '1d') { $days = 1; }
        elseif (strpos($duration, '7') !== false) { $days = 7; }
        elseif (strpos($duration, '90') !== false) { $days = 90; }
        elseif (strpos($duration, '12') !== false || strpos($duration, 'year') !== false) { $days = 365; }
        $ts = $published_at ? strtotime((string) $published_at) : false;
        if (!$ts) { return 0.55; }
        $age_days = max(0, (time() - $ts) / DAY_IN_SECONDS);
        if ($age_days <= $days) { return 1.0; }
        return round(max(0.1, 1 - (($age_days - $days) / max(30, $days * 2))), 3);
    }

    private static function language_fit($text, $language) {
        $language = sanitize_key((string) $language);
        if ($language === '') { return 0.65; }
        $blob = self::lower($text);
        $markers = [
            'es' => '/\b(el|la|los|las|de|para|con|y|que|en|por|una|espa[ñn]a|madrid|como)\b/u',
            'en' => '/\b(the|and|for|with|you|this|that|from|how|why|best)\b/u',
        ];
        if (isset($markers[$language]) && preg_match($markers[$language], $blob)) { return 0.9; }
        return 0.55;
    }

    private static function geo_fit($text, $market) {
        $market = trim((string) $market);
        if ($market === '' || self::lower($market) === 'none') { return 0.65; }
        $blob = self::lower($text . ' ' . $market);
        $market_lc = self::lower($market);
        if (strpos($market_lc, 'spain') !== false || strpos($market_lc, 'espa') !== false || strtoupper($market) === 'ES') {
            return (strpos($blob, 'spain') !== false || strpos($blob, 'espa') !== false || strpos($blob, 'madrid') !== false || strpos($blob, 'barcelona') !== false) ? 0.9 : 0.65;
        }
        return strpos($blob, $market_lc) !== false ? 0.9 : 0.6;
    }

    private static function evidence_type($source_key, $family, $role, $semantic) {
        if ($semantic >= 0.55) { return 'direct'; }
        if ($semantic >= 0.25) { return 'adjacent'; }
        if (strpos($source_key, 'reddit') !== false || strpos($role, 'discussion') !== false) { return 'pain_point_signal'; }
        if (in_array($family, ['content_format','creative_context'], true)) { return 'format_signal'; }
        if (strpos($role, 'ambient') !== false || in_array($family, ['market_context','social_trend_feed'], true)) { return 'ambient'; }
        return 'noise';
    }

    public static function normalize_many($source_key, $source, $items, $request = []) {
        $source_key = sanitize_key((string) $source_key);
        $source = is_array($source) ? $source : [];
        $items = is_array($items) ? $items : [];
        $request = is_array($request) ? $request : [];
        $terms = self::token_terms($request);
        $negative = self::negative_terms($request);
        $out = [];
        $i = 0;
        foreach ($items as $row) {
            $row = is_array($row) ? $row : [];
            $title = self::text($row, ['trend_title','title','name','query','keyword','term','text','caption','headline']);
            $body = self::text($row, ['raw_text','description','desc','snippet','body','fullText','content','caption','text']);
            $combined = trim($title . ' ' . $body . ' ' . wp_json_encode(self::read($row, 'hashtags'), JSON_UNESCAPED_UNICODE));
            [$semantic, $matched, $negative_reason] = self::match($combined, $terms, $negative);
            $metrics = [
                'views' => self::num($row, ['views','viewCount','playCount','videoViewCount','stats.views']),
                'likes' => self::num($row, ['likes','likeCount','diggCount','likesCount','stats.likes']),
                'comments' => self::num($row, ['comments','commentCount','commentsCount','replyCount','numComments','stats.comments']),
                'shares' => self::num($row, ['shares','shareCount','reposts','stats.shares']),
                'saves' => self::num($row, ['saves','collectCount','bookmarks','stats.saves']),
                'upvotes' => self::num($row, ['upvotes','score','ups']),
                'retweets' => self::num($row, ['retweets','retweetCount','repostCount']),
                'search_interest' => self::num($row, ['search_interest','interest','value','traffic','searchVolume','avgMonthlySearches','extracted_value']),
                'growth' => self::num($row, ['growth','growth_rate','velocity','extracted_value']),
                'rank' => self::num($row, ['rank','position']),
                'velocity' => self::num($row, ['velocity','trend_velocity_score']),
            ];
            $family = sanitize_key((string) ($source['source_family'] ?? 'market_context'));
            $role = sanitize_key((string) ($source['source_role'] ?? 'source'));
            $evidence = $negative_reason !== '' ? 'noise' : self::evidence_type($source_key, $family, $role, $semantic);
            $published = self::text($row, ['publishedAt','posted_at','createTimeISO','createTime','timestamp','date','createdAt','createdUtc']);
            $freshness = self::freshness_score($published, $request['duration'] ?? $request['time_range'] ?? '30d');
            $geo = self::geo_fit($combined . ' ' . wp_json_encode($row, JSON_UNESCAPED_UNICODE), $request['market'] ?? $request['country'] ?? '');
            $lang = self::language_fit($combined, $request['language'] ?? '');
            $metric_comp = self::metric_completeness($source_key, $family, $metrics);
            $metric_strength = self::metric_strength($source_key, $metrics);
            $confidence = min(1.0, round(($semantic * 0.42) + ($freshness * 0.16) + ($metric_comp * 0.14) + ($metric_strength * 0.12) + ($geo * 0.08) + ($lang * 0.08), 3));
            $discard_reason = $evidence === 'noise' ? ($negative_reason ?: 'semantic_mismatch') : '';
            $signal = [
                'signal_id' => substr(md5($source_key . '|' . $i . '|' . $combined), 0, 16),
                'run_id' => sanitize_text_field((string) ($request['run_id'] ?? '')),
                'source_key' => $source_key,
                'source_family' => $family,
                'source_role' => $role,
                'platform' => sanitize_key((string) ($source['platform'] ?? $source['source_type'] ?? $source_key)),
                'actor_slug' => sanitize_text_field((string) ($source['actor_slug'] ?? '')),
                'raw_title' => $title,
                'raw_text' => $body,
                'canonical_topic' => sanitize_text_field((string) ($matched[0] ?? $request['topic'] ?? $title)),
                'matched_terms' => $matched,
                'url' => self::url($row, ['url','link','video_url','videoUrl','postUrl','webUrl','permalink']),
                'author_or_channel' => self::text($row, ['author','authorName','username','channelName','channelTitle','creator','subreddit']),
                'published_at' => $published,
                'captured_at' => gmdate('c'),
                'market' => sanitize_text_field((string) ($request['market'] ?? $request['country'] ?? '')),
                'language' => sanitize_key((string) ($request['language'] ?? '')),
                'metrics' => $metrics,
                'metric_status' => self::metric_status($metrics),
                'semantic_match_score' => $semantic,
                'freshness_score' => $freshness,
                'geo_fit_score' => $geo,
                'language_fit_score' => $lang,
                'metric_completeness_score' => $metric_comp,
                'metric_strength_score' => $metric_strength,
                'source_reliability_score' => isset($source['source_reliability_score']) ? (float) $source['source_reliability_score'] : 0.6,
                'trend_type' => sanitize_key((string) ($request['trend_type'] ?? 'general')),
                'evidence_type' => $evidence,
                'confidence' => $confidence,
                'discard_reason' => $discard_reason,
            ];
            if (function_exists('current_user_can') && current_user_can('manage_options')) { $signal['raw'] = $row; }
            $out[] = $signal;
            $i++;
        }
        return $out;
    }
}
