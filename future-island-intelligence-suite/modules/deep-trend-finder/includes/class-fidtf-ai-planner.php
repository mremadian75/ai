<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_AI_Planner {
    public static function build_plan(array $request): array {
        $request = FIDTF_Run_Service::sanitize_request($request);
        $fallback = self::fallback_plan($request);

        if (!FIDTF_Settings::ai_planner_bridge_enabled()) {
            $fallback['planner_mode'] = 'local_fallback';
            $fallback['planner_notice'] = 'AI planner bridge is disabled. Local fallback planner was used.';
            $fallback['request_context'] = self::request_context($request);
            return $fallback;
        }

        /**
         * v0.2.0: explicit AI planner bridge hook. This may call a configured
         * model bridge, so it is never executed unless enable_ai_planner_bridge
         * is true. The bridge must return a source-plan array or WP_Error.
         */
        $external = apply_filters('fidtf_ai_planner_result', null, $request, $fallback);
        if (is_wp_error($external)) {
            return self::fallback_after_bridge_error($fallback, $request, $external);
        }
        if (is_array($external)) {
            if (isset($external['plan']) && is_array($external['plan'])) {
                $plan_wrapper = $external;
                $external = (array) $external['plan'];
                foreach (['model_label', 'raw_text', 'validation_notes', 'planner_notice'] as $meta_key) {
                    if (!empty($plan_wrapper[$meta_key]) && empty($external[$meta_key])) {
                        $external[$meta_key] = $plan_wrapper[$meta_key];
                    }
                }
            }
            $validated = self::validate_plan($external, $request);
            if (!is_wp_error($validated)) {
                $validated['planner_mode'] = 'external_model_bridge';
                $validated['planner_notice'] = 'AI planner bridge returned a validated source plan. No scraping or research has been executed yet.';
                return $validated;
            }
            return self::fallback_after_bridge_error($fallback, $request, $validated, $external);
        }

        $error = new WP_Error('ai_planner_unavailable', 'No configured AI planner client was available.');
        return self::fallback_after_bridge_error($fallback, $request, $error);
    }

    private static function fallback_after_bridge_error(array $fallback, array $request, WP_Error $error, array $external = []): array {
        $fallback['planner_mode'] = 'local_fallback_after_invalid_ai';
        $fallback['planner_notice'] = 'AI planner bridge was enabled but did not return a valid source plan. Local fallback planner was used.';
        $fallback['validation_error'] = sanitize_key((string) $error->get_error_code());
        $fallback['validation_notes'] = [sanitize_text_field((string) $error->get_error_message())];
        $data = (array) $error->get_error_data();
        if (!empty($data['diagnostic'])) {
            $fallback['bridge_diagnostic'] = sanitize_text_field((string) $data['diagnostic']);
        }
        if (!empty($data['raw_text'])) {
            $fallback['raw_text'] = substr(sanitize_textarea_field((string) $data['raw_text']), 0, 6000);
        } elseif (!empty($external['raw_text'])) {
            $fallback['raw_text'] = substr(sanitize_textarea_field((string) $external['raw_text']), 0, 6000);
        }
        $fallback['request_context'] = self::request_context($request);
        return $fallback;
    }

    public static function validate_plan(array $plan, array $request = []) {
        $sources = [];
        $allowed_sources = self::selected_channels($request);
        foreach ((array) ($plan['sources'] ?? []) as $source_key => $source_plan) {
            if (is_int($source_key) && is_array($source_plan)) {
                $source_key = $source_plan['source_key'] ?? '';
            }
            $source_key = sanitize_key((string) $source_key);
            if ($source_key === '' || !is_array($source_plan)) {
                continue;
            }
            if (!in_array($source_key, $allowed_sources, true) || !FIDTF_Settings::source_enabled($source_key)) {
                continue;
            }
            $sources[$source_key] = self::sanitize_source_plan($source_key, $source_plan);
        }
        if (empty($sources)) {
            return new WP_Error('invalid_planner_json', 'Planner output did not include source plans.');
        }
        $out = [
            'schema_version' => 'fidtf.plan.v1',
            'planner_mode' => sanitize_key((string) ($plan['planner_mode'] ?? 'external')),
            'brief_summary' => sanitize_textarea_field((string) ($plan['brief_summary'] ?? ($request['user_brief'] ?? ''))),
            'sources' => $sources,
            'limitations' => array_values(array_map('sanitize_text_field', (array) ($plan['limitations'] ?? []))),
            'validation_notes' => array_values(array_map('sanitize_text_field', (array) ($plan['validation_notes'] ?? []))),
            'request_context' => self::merge_request_context($request, is_array($plan['request_context'] ?? null) ? (array) $plan['request_context'] : []),
        ];
        if (!empty($plan['model_label'])) {
            $out['model_label'] = sanitize_text_field((string) $plan['model_label']);
        }
        if (!empty($plan['raw_text'])) {
            $out['raw_text'] = substr(sanitize_textarea_field((string) $plan['raw_text']), 0, 6000);
        }
        if (!empty($plan['planner_notice'])) {
            $out['planner_notice'] = sanitize_text_field((string) $plan['planner_notice']);
        }
        return $out;
    }

    private static function sanitize_source_plan(string $source_key, array $source_plan): array {
        $source_key = sanitize_key($source_key);
        $spec = self::source_plan_spec($source_key);
        $out = ['source_key' => $source_key];

        foreach ((array) ($spec['list_fields'] ?? []) as $field => $limit) {
            $list = self::clean_list($source_plan[$field] ?? [], (int) $limit);
            if (!empty($list)) {
                $out[$field] = $list;
            }
        }

        foreach ((array) ($spec['string_fields'] ?? []) as $field => $default) {
            $value = sanitize_text_field((string) ($source_plan[$field] ?? $default));
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        $notes = trim(sanitize_textarea_field((string) ($source_plan['notes'] ?? '')));
        if ($notes !== '') {
            $out['notes'] = $notes;
        }

        $limit = FIDTF_Settings::source_limit($source_key);
        if (isset($source_plan['max_items'])) {
            $limit = min($limit, max(1, (int) $source_plan['max_items']));
        }
        $out['max_items'] = $limit;

        $sort = sanitize_text_field((string) ($source_plan['sort_strategy'] ?? ($spec['default_sort'] ?? 'relevance')));
        $out['sort_strategy'] = $sort !== '' ? $sort : (string) ($spec['default_sort'] ?? 'relevance');

        return $out;
    }

    private static function source_plan_spec(string $source_key): array {
        $source_key = sanitize_key($source_key);
        $common = [
            'language_hints' => 20,
            'market_hints' => 20,
        ];
        $specs = [
            'tiktok' => [
                'list_fields' => array_merge([
                    'queries' => 20,
                    'hashtags' => 20,
                    'creator_or_format_hints' => 20,
                ], $common),
                'string_fields' => [],
                'default_sort' => 'relevance_then_engagement',
            ],
            'instagram' => [
                'list_fields' => array_merge([
                    'queries' => 20,
                    'hashtags' => 20,
                    'profile_or_topic_hints' => 20,
                ], $common),
                'string_fields' => [],
                'default_sort' => 'hashtag_relevance_then_recency',
            ],
            'reddit' => [
                'list_fields' => array_merge([
                    'queries' => 20,
                    'subreddits' => 20,
                ], $common),
                'string_fields' => ['time_range' => 'last_30_days'],
                'default_sort' => 'relevance_week',
            ],
            'google_trends' => [
                'list_fields' => array_merge([
                    'seed_keywords' => 20,
                    'related_query_hints' => 20,
                ], $common),
                'string_fields' => ['geo' => '', 'time_range' => 'last_30_days'],
                'default_sort' => 'interest_and_related_queries',
            ],
            'google_news' => [
                'list_fields' => [
                    'queries' => 20,
                    'publisher_or_topic_hints' => 20,
                ],
                'string_fields' => ['geo' => '', 'language' => '', 'time_range' => 'last_30_days'],
                'default_sort' => 'freshness_and_publisher_diversity',
            ],
            'ai_research' => [
                'list_fields' => [
                    'research_questions' => 20,
                    'assumptions_to_test' => 20,
                    'market_questions' => 20,
                    'competitor_questions' => 20,
                ],
                'string_fields' => [],
                'default_sort' => 'evidence_framing',
            ],
        ];
        return $specs[$source_key] ?? [
            'list_fields' => array_merge(['queries' => 20, 'hashtags' => 20], $common),
            'string_fields' => [],
            'default_sort' => 'relevance',
        ];
    }

    private static function clean_list($value, int $limit = 20): array {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n|,/', $value);
        }
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item !== '') { $out[] = sanitize_text_field($item); }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, $limit))));
    }

    private static function fallback_plan(array $request): array {
        $keywords = array_values(array_filter((array) ($request['keywords'] ?? [])));
        $brief = trim((string) ($request['user_brief'] ?? ''));
        $objective = trim((string) ($request['objective'] ?? ''));
        $market = trim((string) ($request['market'] ?? ''));
        $language = trim((string) ($request['language'] ?? 'es'));
        $base_terms = $keywords;
        if ($brief !== '') { $base_terms[] = self::short_phrase($brief); }
        if ($objective !== '') { $base_terms[] = self::short_phrase($objective); }
        if (!empty($request['brand'])) { $base_terms[] = (string) $request['brand']; }
        foreach ((array) ($request['competitors'] ?? []) as $competitor) { $base_terms[] = (string) $competitor; }
        $base_terms = array_values(array_unique(array_filter($base_terms)));
        if (empty($base_terms)) { $base_terms = ['trend']; }
        $hashtags = [];
        foreach ($base_terms as $term) {
            $tag = preg_replace('/[^\p{L}\p{N}_]+/u', '', str_replace(' ', '', $term));
            if ($tag !== '') { $hashtags[] = ltrim($tag, '#'); }
        }
        $channels = self::selected_channels($request);
        $sources = [];
        foreach ($channels as $channel) {
            if (!FIDTF_Settings::source_enabled($channel)) { continue; }
            $sources[$channel] = self::source_fallback($channel, $base_terms, $hashtags, $market, $language, $request);
        }
        return [
            'schema_version' => 'fidtf.plan.v1',
            'brief_summary' => trim($brief . ($objective !== '' ? ' / Objective: ' . $objective : '')),
            'sources' => $sources,
            'limitations' => ['This is a local fallback plan. It expands keywords conservatively and does not claim live web research.'],
            'request_context' => self::request_context($request),
        ];
    }

    private static function merge_request_context(array $request, array $external_context): array {
        $base = self::request_context($request);
        $external = self::request_context($external_context);
        foreach ($external as $key => $value) {
            if (!array_key_exists($key, $external_context)) { continue; }
            if (is_array($value)) {
                if (!empty($value)) { $base[$key] = $value; }
            } elseif ($value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function request_context(array $request): array {
        return [
            'user_brief' => sanitize_textarea_field((string) ($request['user_brief'] ?? '')),
            'objective' => sanitize_textarea_field((string) ($request['objective'] ?? '')),
            'market' => sanitize_text_field((string) ($request['market'] ?? '')),
            'language' => sanitize_key((string) ($request['language'] ?? '')),
            'date_range' => sanitize_key((string) ($request['date_range'] ?? 'last_30_days')),
            'keywords' => self::clean_list($request['keywords'] ?? [], 30),
            'brand' => sanitize_text_field((string) ($request['brand'] ?? $request['company'] ?? '')),
            'company' => sanitize_text_field((string) ($request['company'] ?? $request['brand'] ?? '')),
            'competitors' => self::clean_list($request['competitors'] ?? [], 20),
            'audience' => sanitize_text_field((string) ($request['audience'] ?? '')),
            'exclude_terms' => self::clean_list($request['exclude_terms'] ?? [], 30),
            'channels' => self::selected_channels($request),
        ];
    }

    private static function selected_channels(array $request): array {
        $defaults = ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'];
        $channels = (array) ($request['channels'] ?? $defaults);
        $out = [];
        foreach ($channels as $channel) {
            $channel = sanitize_key((string) $channel);
            if (in_array($channel, $defaults, true)) { $out[] = $channel; }
        }
        return array_values(array_unique($out));
    }

    private static function source_fallback(string $source, array $terms, array $hashtags, string $market, string $language, array $request): array {
        $limit = FIDTF_Settings::source_limit($source);
        $source = sanitize_key($source);
        switch ($source) {
            case 'tiktok':
                return [
                    'source_key' => $source,
                    'queries' => array_slice($terms, 0, 4),
                    'hashtags' => array_slice($hashtags, 0, 8),
                    'creator_or_format_hints' => ['short-form examples', 'creator POV', 'street interview', 'travel diary'],
                    'language_hints' => [$language],
                    'market_hints' => [$market],
                    'max_items' => $limit,
                    'sort_strategy' => 'relevance_then_engagement',
                    'notes' => 'Use metadata, captions, hashtags and metrics only unless provider returns transcript/video fields.',
                ];
            case 'instagram':
                return [
                    'source_key' => $source,
                    'queries' => array_slice($terms, 0, 3),
                    'hashtags' => array_slice($hashtags, 0, 10),
                    'profile_or_topic_hints' => array_slice($terms, 0, 4),
                    'language_hints' => [$language],
                    'market_hints' => [$market],
                    'max_items' => $limit,
                    'sort_strategy' => 'hashtag_relevance_then_recency',
                    'notes' => 'Avoid treating business objectives as hashtags.',
                ];
            case 'reddit':
                return [
                    'source_key' => $source,
                    'queries' => array_slice(array_merge($terms, self::question_variants($terms)), 0, 8),
                    'subreddits' => [],
                    'time_range' => (string) ($request['date_range'] ?? 'last_30_days'),
                    'language_hints' => [$language],
                    'market_hints' => [$market],
                    'max_items' => $limit,
                    'sort_strategy' => 'relevance_week',
                    'notes' => 'Discussion and pain-point validation source.',
                ];
            case 'google_trends':
                return [
                    'source_key' => $source,
                    'seed_keywords' => array_slice($terms, 0, 5),
                    'related_query_hints' => [],
                    'geo' => self::geo_hint($market),
                    'time_range' => (string) ($request['date_range'] ?? 'last_30_days'),
                    'language_hints' => [$language],
                    'market_hints' => [$market],
                    'max_items' => $limit,
                    'sort_strategy' => 'interest_and_related_queries',
                    'notes' => 'Search-interest baseline; not proof of social virality by itself.',
                ];
            case 'google_news':
                return [
                    'source_key' => $source,
                    'queries' => array_slice($terms, 0, 6),
                    'publisher_or_topic_hints' => array_slice($terms, 0, 4),
                    'geo' => self::geo_hint($market),
                    'language' => $language,
                    'time_range' => (string) ($request['date_range'] ?? 'last_30_days'),
                    'max_items' => $limit,
                    'sort_strategy' => 'freshness_and_publisher_diversity',
                    'notes' => 'News freshness and market context.',
                ];
            case 'ai_research':
            default:
                return [
                    'source_key' => 'ai_research',
                    'queries' => [],
                    'research_questions' => array_slice(self::research_questions($terms, $market), 0, 8),
                    'assumptions_to_test' => ['The requested trend has enough public signals to justify a deeper source run.'],
                    'market_questions' => ['Which public market signals are visible in ' . ($market ?: 'the selected market') . '?'],
                    'competitor_questions' => self::competitor_questions((array) ($request['competitors'] ?? [])),
                    'max_items' => $limit,
                    'sort_strategy' => 'evidence_framing',
                    'notes' => 'AI web research is only available if a configured model bridge supports browsing/research.',
                ];
        }
    }

    private static function short_phrase(string $text): string {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        if (function_exists('mb_substr')) { return mb_substr($text, 0, 80, 'UTF-8'); }
        return substr($text, 0, 80);
    }

    private static function question_variants(array $terms): array {
        $out = [];
        foreach (array_slice($terms, 0, 4) as $term) {
            $out[] = 'opinions about ' . $term;
            $out[] = 'problems with ' . $term;
        }
        return $out;
    }

    private static function geo_hint(string $market): string {
        $market = strtolower(trim($market));
        $map = [
            'spain' => 'ES',
            'españa' => 'ES',
            'madrid' => 'ES',
            'mexico' => 'MX',
            'méxico' => 'MX',
            'colombia' => 'CO',
            'argentina' => 'AR',
        ];
        return $map[$market] ?? '';
    }

    private static function competitor_questions(array $competitors): array {
        $out = [];
        foreach (array_slice($competitors, 0, 5) as $competitor) {
            $competitor = sanitize_text_field((string) $competitor);
            if ($competitor !== '') {
                $out[] = 'What public signals show how ' . $competitor . ' is framing this topic?';
            }
        }
        return $out;
    }

    private static function research_questions(array $terms, string $market): array {
        $seed = implode(', ', array_slice($terms, 0, 4));
        return [
            'What public signals suggest that ' . $seed . ' is emerging or declining in ' . ($market ?: 'the selected market') . '?',
            'Which audience needs, objections, or cultural tensions are connected to ' . $seed . '?',
            'Which content formats or campaign angles are repeatedly associated with ' . $seed . '?',
            'What evidence would validate or reject this trend before campaign production?',
        ];
    }
}
