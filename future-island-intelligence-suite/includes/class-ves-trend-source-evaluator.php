<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Trend_Source_Evaluator {

    public static function canonical_status($status) {
        $raw = strtolower(str_replace('-', '_', trim((string) $status)));
        $map = [
            'queued_retry_memory_limit' => 'running',
            'waiting_retry' => 'running',
            'queued' => 'running',
            'ready' => 'running',
            'running' => 'running',
            'succeeded' => 'succeeded',
            'success' => 'succeeded',
            'completed' => 'succeeded',
            'skipped' => 'skipped',
            'skipped_invalid_input' => 'skipped_invalid_input',
            'config_error' => 'skipped_invalid_input',
            'failed_contract' => 'failed_contract',
            'contract_error' => 'failed_contract',
            'dispatch_error' => 'failed_provider',
            'provider_busy' => 'provider_busy',
            'actor_busy' => 'provider_busy',
            'rate_limited' => 'provider_busy',
            'failed_provider' => 'failed_provider',
            'failed_empty' => 'failed_empty',
            'empty' => 'failed_empty',
            'failed_normalization' => 'failed_normalization',
            'failed_timeout' => 'failed_timeout',
            'timed_out' => 'failed_timeout',
            'timeout' => 'failed_timeout',
            'failed_access' => 'failed_access',
            'access_error' => 'failed_access',
            'aborted' => 'failed_unknown',
            'failed' => 'failed_unknown',
            'unknown' => 'failed_unknown',
        ];
        return $map[$raw] ?? $raw;
    }

    private static function source_status_from_canonical($canonical, $records_count, $valid_count, $analytical, $signal_ambient, $signal_format, $signal_pain, $direct_count_final) {
        if (in_array($canonical, ['skipped','skipped_invalid_input','provider_busy','failed_contract','failed_provider','failed_empty','failed_normalization','failed_timeout','failed_access','failed_unknown'], true)) {
            return $canonical;
        }
        if ($canonical === 'running' || $canonical === 'planned') { return $canonical; }
        if ($records_count <= 0) { return 'failed_empty'; }
        if ($valid_count <= 0) { return 'failed_normalization'; }
        if ($analytical === 'partial' && ($signal_ambient + $signal_format + $signal_pain) > 0 && $direct_count_final === 0) { return 'ambient_only'; }
        if ($analytical === 'partial') { return 'partial'; }
        if ($analytical !== 'usable') { return 'no_relevant_signal'; }
        return 'usable';
    }

    private static function clamp($value, $min = 0, $max = 1) {
        $value = (float) $value;
        if ($value < $min) { return (float) $min; }
        if ($value > $max) { return (float) $max; }
        return $value;
    }

    private static function text_blob($items) {
        return strtolower(wp_strip_all_tags(wp_json_encode(array_slice((array) $items, 0, 12), JSON_UNESCAPED_UNICODE)));
    }

    private static function topic_direct_match_count($items, $request) {
        $topic = strtolower(trim((string) ($request['topic'] ?? $request['query'] ?? $request['keyword'] ?? '')));
        $tokens = array_values(array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}#@]+/u', ' ', $topic))));
        if (empty($tokens)) { return 0; }
        $count = 0;
        foreach ((array) $items as $item) {
            $text = strtolower(wp_strip_all_tags(wp_json_encode($item, JSON_UNESCAPED_UNICODE)));
            $hits = 0;
            foreach ($tokens as $token) {
                if (strlen($token) >= 3 && strpos($text, $token) !== false) { $hits++; }
            }
            if ($hits >= max(1, min(2, count($tokens)))) { $count++; }
        }
        return $count;
    }

    private static function source_quality_class($source_key, $items, $request, $validation = []) {
        $source_key = sanitize_key((string) $source_key);
        $country = strtolower((string) ($request['country'] ?? $request['country_code'] ?? ''));
        $language = strtolower((string) ($request['language'] ?? $request['output_language'] ?? 'es'));
        $blob = self::text_blob($items);
        if ($blob === '' || $blob === '[]') { return 'unknown_empty'; }
        if (($validation['status'] ?? '') === 'insufficient_output') { return 'unknown_empty'; }
        if ($source_key === 'youtube_trending' && ($validation['evidence_role'] ?? '') === 'market_context') { return 'market_context'; }
        $spanish = (bool) preg_match('/\b(el|la|los|las|de|para|como|que|con|espa[ñn]a|madrid|barcelona|cerveza|marketing|digital)\b/u', $blob);
        $english = (bool) preg_match('/\b(the|and|for|with|how|beginner|income|marketing)\b/u', $blob);
        $spain = (strpos($blob, 'spain') !== false || strpos($blob, 'españa') !== false || strpos($blob, 'madrid') !== false || strpos($blob, 'barcelona') !== false || $country === 'es' || $country === 'spain' || $country === 'españa');
        $direct = (int) ($validation['direct_match_count'] ?? self::topic_direct_match_count($items, $request));
        if ($direct > 0 && $spain && ($spanish || $language === 'es')) { return 'direct_spain_spanish_match'; }
        if ($direct > 0) { return 'direct_topic_match'; }
        if ($spain && $spanish) { return 'spanish_market_context'; }
        if ($spanish && !$spain) { return 'spanish_non_spain'; }
        if ($spain && !$spanish) { return 'spain_non_spanish'; }
        if ($english && !$spanish) { return 'generic_english_noise'; }
        return 'unknown_relevance';
    }

    private static function metric_fields_for_source($source_key) {
        $source_key = sanitize_key((string) $source_key);
        if (strpos($source_key, 'youtube') !== false) {
            return ['views', 'viewCount', 'likes', 'likeCount', 'comments', 'commentCount', 'publishedAt'];
        }
        if (strpos($source_key, 'tiktok') !== false) {
            return ['playCount', 'diggCount', 'commentCount', 'shareCount', 'collectCount', 'createTime'];
        }
        if (strpos($source_key, 'instagram') !== false) {
            return ['likesCount', 'commentsCount', 'timestamp', 'videoViewCount', 'videoPlayCount'];
        }
        if (strpos($source_key, 'twitter') !== false || strpos($source_key, 'x_') !== false) {
            return ['retweetCount', 'replyCount', 'likeCount', 'quoteCount', 'viewCount', 'createdAt'];
        }
        if (strpos($source_key, 'reddit') !== false) {
            return ['score', 'upvoteRatio', 'numComments', 'createdUtc'];
        }
        if (strpos($source_key, 'pinterest') !== false) {
            return ['repinCount', 'commentCount', 'reactionCount', 'createdAt'];
        }
        if (strpos($source_key, 'facebook') !== false) {
            return ['likes', 'comments', 'shares', 'date', 'timestamp'];
        }
        return ['views', 'likes', 'comments', 'shares', 'engagement', 'date', 'timestamp', 'publishedAt'];
    }

    private static function metrics_score($source_key, $compact_items) {
        $compact_items = is_array($compact_items) ? $compact_items : [];
        if (empty($compact_items)) { return 0.0; }
        $checks = 0; $hits = 0;
        foreach ($compact_items as $item) {
            if (!is_array($item)) { continue; }
            if (strpos($source_key, 'google_trends') === 0) {
                $checks++;
                if (!empty($item['searchTerm']) || !empty($item['keyword']) || !empty($item['relatedQueriesTop']) || !empty($item['relatedQueriesRising']) || !empty($item['relatedTopicsTop']) || !empty($item['interestOverTime'])) { $hits++; }
                continue;
            }
            foreach (self::metric_fields_for_source($source_key) as $field) {
                $checks++;
                if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null) { $hits++; }
            }
        }
        return $checks > 0 ? self::clamp($hits / $checks) : 0.0;
    }

    private static function geo_fit_score($source_key, $request, $compact_items, $validation = []) {
        $country = sanitize_text_field((string) ($request['country'] ?? 'None'));
        if ($country === 'None' || $country === '') { return !empty($compact_items) ? 0.7 : 0.0; }
        if ($source_key === 'youtube_trending') {
            return (($validation['evidence_role'] ?? '') === 'topic_evidence') ? 0.8 : 0.45;
        }
        $blob = self::text_blob($compact_items);
        $label = function_exists('ves_trend_label_country') ? strtolower((string) ves_trend_label_country($country)) : strtolower($country);
        if ($blob !== '' && $label !== '' && strpos($blob, $label) !== false) { return 0.9; }
        return !empty($compact_items) ? 0.65 : 0.0;
    }

    private static function language_fit_score($source_key, $request, $compact_items, $validation = []) {
        $language = sanitize_key((string) ($request['language'] ?? ''));
        if ($language === '') { return !empty($compact_items) ? 0.7 : 0.0; }
        $blob = self::text_blob($compact_items);
        if ($blob === '') { return 0.0; }
        $markers = [
            'es' => '/\b(el|la|de|para|con|y|que|en|por|una|los|las|espa[ñn]a|madrid)\b/u',
            'en' => '/\b(the|and|for|with|you|this|that|from|how|why)\b/u',
            'pt' => '/\b(o|a|de|para|com|que|em|uma|os|as)\b/u',
        ];
        if (isset($markers[$language]) && preg_match($markers[$language], $blob)) { return 0.85; }
        return $source_key === 'youtube_trending' ? 0.5 : 0.55;
    }

    public static function evaluate_source($source_key, $source, $raw_items, $request) {
        $source_key = sanitize_key((string) $source_key);
        $status = sanitize_text_field((string) ($source['status'] ?? 'UNKNOWN'));
        $canonical_status = self::canonical_status($status);
        $notes = sanitize_text_field((string) ($source['notes'] ?? ''));
        $raw_items = is_array($raw_items) ? $raw_items : [];
        $actor_slug = sanitize_text_field((string) ($source['actor_slug'] ?? ''));
        $validation = class_exists('VES_Source_Adapter_Registry') ? VES_Source_Adapter_Registry::validate_output($source_key, $actor_slug, $raw_items, $request) : ['status' => 'usable', 'valid_items' => $raw_items, 'valid_count' => count($raw_items), 'rejected_count' => 0, 'evidence_role' => 'topic_evidence'];
        $valid_items = is_array($validation['valid_items'] ?? null) ? $validation['valid_items'] : $raw_items;
        $highlights = function_exists('ves_trend_extract_highlights') ? ves_trend_extract_highlights($source_key, $valid_items) : [];
        $compact_items = function_exists('ves_trend_compact_items_for_ai') ? ves_trend_compact_items_for_ai($source_key, $valid_items) : array_slice($valid_items, 0, 20);
        $records_count = count($raw_items);
        $valid_count = (int) ($validation['valid_count'] ?? count($valid_items));
        $metric_score = self::metrics_score($source_key, $compact_items);
        $geo_score = self::geo_fit_score($source_key, $request, $compact_items, $validation);
        $language_score = self::language_fit_score($source_key, $request, $compact_items, $validation);
        $usable_records_count = $valid_count;
        $parse_status = $records_count > 0 ? 'parsed' : ($canonical_status === 'succeeded' ? 'empty' : 'not_started');
        $analytical = 'usable';
        $exclusion_reason = '';
        if ($canonical_status !== 'succeeded') {
            $analytical = in_array($canonical_status, ['running', 'planned'], true) ? 'pending' : 'failed';
            $usable_records_count = 0;
            $exclusion_reason = $notes !== '' ? $notes : 'Source did not finish successfully.';
        } elseif (($validation['status'] ?? '') === 'insufficient_output') {
            $analytical = 'unusable';
            $usable_records_count = 0;
            $parse_status = 'insufficient_output';
            $exclusion_reason = (string) ($validation['reason'] ?? 'Source output failed actor-specific validation.');
        } elseif (($validation['status'] ?? '') === 'market_context') {
            $analytical = 'partial';
            $usable_records_count = min($valid_count, 5);
            $exclusion_reason = (string) ($validation['reason'] ?? 'Market context only; not topic-specific evidence.');
        } elseif ($valid_count <= 0) {
            $analytical = 'unusable';
            $usable_records_count = 0;
            $exclusion_reason = 'Source returned zero usable records.';
        } elseif ($metric_score < 0.25 || $geo_score < 0.4 || $language_score < 0.35) {
            $analytical = 'partial';
            $usable_records_count = max(0, min($valid_count, 5));
            if ($metric_score < 0.25) { $exclusion_reason = 'Weak metric completeness.'; }
            elseif ($geo_score < 0.4) { $exclusion_reason = 'Weak geo relevance for requested market.'; }
            else { $exclusion_reason = 'Weak language/local relevance.'; }
        }
        $quality = self::source_quality_class($source_key, $compact_items, $request, $validation);
        $source_family = sanitize_key((string) ($source['source_family'] ?? 'market_context'));
        $source_role = sanitize_key((string) ($source['source_role'] ?? 'source'));
        $trend_signals = class_exists('VES_Trend_Signal_Normalizer') ? VES_Trend_Signal_Normalizer::normalize_many($source_key, array_merge((array) $source, [
            'source_family' => $source_family,
            'source_role' => $source_role,
            'actor_slug' => $actor_slug,
        ]), $valid_items, $request) : [];
        $signal_direct = 0; $signal_adjacent = 0; $signal_ambient = 0; $signal_format = 0; $signal_pain = 0; $signal_noise = 0;
        foreach ($trend_signals as $signal) {
            $etype = sanitize_key((string) ($signal['evidence_type'] ?? 'noise'));
            if ($etype === 'direct') { $signal_direct++; }
            elseif ($etype === 'adjacent') { $signal_adjacent++; }
            elseif ($etype === 'ambient') { $signal_ambient++; }
            elseif ($etype === 'format_signal') { $signal_format++; }
            elseif ($etype === 'pain_point_signal') { $signal_pain++; }
            else { $signal_noise++; }
        }
        $direct_count_final = max((int) ($validation['direct_match_count'] ?? 0), $signal_direct);
        $adjacent_count_final = max((int) ($validation['adjacent_match_count'] ?? 0), $signal_adjacent);
        if ($analytical === 'usable' && $direct_count_final === 0 && $adjacent_count_final === 0 && ($signal_ambient + $signal_format + $signal_pain) > 0) {
            $analytical = 'partial';
            if ($exclusion_reason === '') { $exclusion_reason = 'Usable only as ambient, format, or discussion context; no direct topic evidence.'; }
        }
        $source_status = self::source_status_from_canonical($canonical_status, $records_count, $valid_count, $analytical, $signal_ambient, $signal_format, $signal_pain, $direct_count_final);
        if (($validation['status'] ?? '') === 'insufficient_output') { $source_status = 'failed_normalization'; }
        $provider_cost = isset($source['provider_cost_usd']) && is_numeric($source['provider_cost_usd']) ? (float) $source['provider_cost_usd'] : null;
        return [
            'source_key' => $source_key,
            'label' => sanitize_text_field((string) ($source['label'] ?? $source_key)),
            'actor_slug' => $actor_slug,
            'source_family' => $source_family,
            'source_role' => $source_role,
            'source_status' => $source_status,
            'dispatch_status' => !empty($source['run_id']) ? 'ok' : sanitize_key((string) ($source['dispatch_status'] ?? 'error')),
            'scrape_status' => $status,
            'parse_status' => $parse_status,
            'item_count' => $records_count,
            'records_count' => $records_count,
            'captured_rows' => $records_count,
            'normalized_rows' => $valid_count,
            'usable_rows' => $usable_records_count,
            'useful_rows' => $usable_records_count,
            'failed_rows' => max(0, $records_count - $valid_count),
            'usable_records_count' => $usable_records_count,
            'valid_records_count' => $valid_count,
            'rejected_records_count' => (int) ($validation['rejected_count'] ?? max(0, $records_count - $valid_count)),
            'direct_match_count' => $direct_count_final,
            'adjacent_match_count' => $adjacent_count_final,
            'direct_matches' => $direct_count_final,
            'adjacent_matches' => $adjacent_count_final,
            'ambient_signals' => $signal_ambient,
            'format_signals' => $signal_format,
            'pain_point_signals' => $signal_pain,
            'metric_completeness_score' => round($metric_score, 2),
            'geo_fit_score' => round($geo_score, 2),
            'language_fit_score' => round($language_score, 2),
            'analytical_usability_status' => $analytical,
            'evidence_role' => sanitize_key((string) ($validation['evidence_role'] ?? 'topic_evidence')),
            'source_quality_class' => $quality,
            'source_quality_classification' => $quality,
            'output_validation_status' => sanitize_key((string) ($validation['status'] ?? 'usable')),
            'exclusion_reason' => sanitize_text_field((string) $exclusion_reason),
            'notes' => $notes,
            'status' => $canonical_status,
            'highlights' => array_values(array_slice((array) $highlights, 0, 10)),
            'compact_items' => array_slice((array) $compact_items, 0, 20),
            'trend_signals' => array_slice($trend_signals, 0, 50),
            'discarded_rows' => (int) ($validation['rejected_count'] ?? max(0, $records_count - $valid_count)) + $signal_noise,
            'failure_reason' => sanitize_text_field((string) $exclusion_reason),
            'confidence_contribution' => round(($metric_score + $geo_score + $language_score) / 3, 3),
            'provider_cost' => $provider_cost,
            'credit_impact' => [
                'provider_attempted' => !in_array($canonical_status, ['skipped','skipped_invalid_input'], true),
                'useful_rows' => $usable_records_count,
                'should_refund_result_fee' => $usable_records_count <= 0,
            ],
            'input' => is_array($source['input'] ?? null) ? $source['input'] : [],
            'run_id' => sanitize_text_field((string) ($source['run_id'] ?? '')),
        ];
    }

    public static function summarize_run_sources($ui_sources) {
        $summary = [
            'total_sources' => 0,
            'usable_sources' => 0,
            'partial_sources' => 0,
            'failed_sources' => 0,
            'pending_sources' => 0,
            'metric_average' => 0.0,
            'usable_records' => 0,
            'direct_matches' => 0,
            'adjacent_matches' => 0,
            'ambient_signals' => 0,
            'format_signals' => 0,
            'pain_point_signals' => 0,
            'discarded_or_noise_count' => 0,
            'market_context_sources' => 0,
            'source_quality_breakdown' => [],
            'evidence_coverage_summary' => [],
            'usable_source_keys' => [],
            'only_google_trends_usable' => false,
            'provider_busy_sources' => 0,
        ];
        $metric_scores = [];
        foreach ((array) $ui_sources as $src) {
            $summary['total_sources']++;
            $state = sanitize_key((string) ($src['analytical_usability_status'] ?? 'unusable'));
            $src_status = sanitize_key((string) ($src['source_status'] ?? ''));
            if (in_array($src_status, ['usable'], true)) { $state = 'usable'; }
            elseif (in_array($src_status, ['partial','ambient_only'], true)) { $state = 'partial'; }
            elseif (in_array($src_status, ['planned','running'], true)) { $state = 'pending'; }
            elseif (in_array($src_status, ['skipped','skipped_invalid_input','provider_busy','failed_contract','failed_provider','failed_empty','failed_normalization','failed_timeout','failed_access','failed_unknown'], true)) { $state = 'failed'; }
            if ($state === 'usable') {
                $summary['usable_sources']++;
                $summary['usable_source_keys'][] = sanitize_key((string) ($src['source_key'] ?? $src['label'] ?? ''));
            }
            elseif ($state === 'partial') { $summary['partial_sources']++; }
            elseif ($state === 'pending') { $summary['pending_sources']++; }
            else { $summary['failed_sources']++; }
            if ($src_status === 'provider_busy' || sanitize_key((string) ($src['dispatch_status'] ?? '')) === 'provider_busy') { $summary['provider_busy_sources']++; }
            if (($src['evidence_role'] ?? '') === 'market_context') { $summary['market_context_sources']++; }
            $summary['usable_records'] += (int) ($src['usable_records_count'] ?? 0);
            $summary['direct_matches'] += (int) ($src['direct_match_count'] ?? 0);
            $summary['adjacent_matches'] += (int) ($src['adjacent_match_count'] ?? $src['adjacent_matches'] ?? 0);
            $summary['ambient_signals'] += (int) ($src['ambient_signals'] ?? 0);
            $summary['format_signals'] += (int) ($src['format_signals'] ?? 0);
            $summary['pain_point_signals'] += (int) ($src['pain_point_signals'] ?? 0);
            $summary['discarded_or_noise_count'] += (int) ($src['discarded_rows'] ?? $src['rejected_records_count'] ?? 0);
            $metric_scores[] = (float) ($src['metric_completeness_score'] ?? 0);
            $quality = sanitize_key((string) ($src['source_quality_classification'] ?? $src['source_quality_class'] ?? 'unknown_relevance'));
            if ($quality === '') { $quality = 'unknown_relevance'; }
            $summary['source_quality_breakdown'][$quality] = (int) ($summary['source_quality_breakdown'][$quality] ?? 0) + 1;
        }
        if (!empty($metric_scores)) { $summary['metric_average'] = round(array_sum($metric_scores) / count($metric_scores), 2); }
        $summary['usable_source_keys'] = array_values(array_unique(array_filter($summary['usable_source_keys'])));
        $summary['only_google_trends_usable'] = ($summary['usable_sources'] === 1 && (in_array('google_trends', $summary['usable_source_keys'], true) || in_array('google_trends_interest', $summary['usable_source_keys'], true)));
        $confidence_reason = 'No usable topic evidence was found.';
        if ($summary['direct_matches'] > 0 && $summary['usable_sources'] >= 2) {
            $confidence_reason = 'At least two usable sources and direct item matches are available.';
        } elseif ($summary['usable_sources'] > 0 || $summary['partial_sources'] > 0) {
            $confidence_reason = 'Some records are usable, but direct item matches or source coverage are limited.';
        }
        $summary['evidence_coverage_summary'] = [
            'sources_queried' => (int) $summary['total_sources'],
            'usable_sources' => (int) $summary['usable_sources'],
            'partial_sources' => (int) $summary['partial_sources'],
            'failed_sources' => (int) $summary['failed_sources'],
            'pending_sources' => (int) $summary['pending_sources'],
            'usable_records' => (int) $summary['usable_records'],
            'direct_matches' => (int) $summary['direct_matches'],
            'adjacent_matches' => (int) $summary['adjacent_matches'],
            'ambient_signals' => (int) $summary['ambient_signals'],
            'format_signals' => (int) $summary['format_signals'],
            'pain_point_signals' => (int) $summary['pain_point_signals'],
            'discarded_or_noise_count' => (int) $summary['discarded_or_noise_count'],
            'market_context_sources' => (int) $summary['market_context_sources'],
            'provider_busy_sources' => (int) $summary['provider_busy_sources'],
            'only_google_trends_usable' => !empty($summary['only_google_trends_usable']),
            'confidence_reason' => $confidence_reason,
        ];
        return $summary;
    }
}
