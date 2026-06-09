<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Source_Dispatcher {
    public static function provider_for(string $source_key) {
        switch (sanitize_key($source_key)) {
            case 'tiktok': return new FIDTF_Provider_TikTok();
            case 'instagram': return new FIDTF_Provider_Instagram();
            case 'reddit': return new FIDTF_Provider_Reddit();
            case 'google_trends': return new FIDTF_Provider_Google_Trends();
            case 'google_news': return new FIDTF_Provider_Google_News();
            case 'ai_research': return new FIDTF_Provider_AI();
            default:
                return new WP_Error('unknown_source', 'Unknown Deep Trend Finder source.');
        }
    }

    public static function dispatch_source(string $source_key, array $source_plan, array $request) {
        $provider = self::provider_for($source_key);
        if (is_wp_error($provider)) { return $provider; }
        return $provider->dispatch($source_plan, $request);
    }

    public static function payload_for(string $source_key, array $source_plan, array $request): array {
        $provider = self::provider_for($source_key);
        if (is_wp_error($provider)) { return []; }
        return $provider->build_payload($source_plan, $request);
    }

    public static function actor_for(string $source_key): string {
        $provider = self::provider_for($source_key);
        if (is_wp_error($provider)) { return ''; }
        return $provider->actor_id();
    }

    public static function build_canonical_payload(string $source_key, array $source_plan, array $request): array {
        $source_key = sanitize_key($source_key);
        $admin_limit = FIDTF_Settings::source_limit($source_key);
        $default_limit = $admin_limit;
        if ($source_key === 'tiktok') {
            $admin_limit = FIDTF_Settings::tiktok_max_limit();
            $default_limit = FIDTF_Settings::tiktok_default_limit();
        }
        $plan = self::sanitize_source_plan($source_key, $source_plan, $admin_limit, $default_limit);
        $max_items = (int) ($plan['max_items'] ?? $default_limit);
        $planner_mode = sanitize_key((string) ($request['planner_mode'] ?? 'local_fallback'));
        if ($planner_mode === '') { $planner_mode = 'local_fallback'; }

        $payload = [
            'source_key' => $source_key,
            'schema_version' => 'fidtf.source_payload.v1',
            'planner_mode' => $planner_mode,
            'request_context' => FIDTF_AI_Planner::request_context($request),
            'source_plan' => $plan,
            'limits' => [
                'max_items' => $max_items,
                'admin_limit' => $admin_limit,
            ],
            'provider_intent' => ($source_key === 'tiktok' && FIDTF_Settings::tiktok_dispatch_allowed()) ? 'live_collect' : 'planned_only',
            'live_dispatch_enabled' => ($source_key === 'tiktok') ? FIDTF_Settings::tiktok_dispatch_allowed() : false,
            'global_live_dispatch_enabled' => FIDTF_Settings::live_dispatch_enabled(),
            'tiktok_live_bridge_enabled' => FIDTF_Settings::tiktok_live_bridge_enabled(),
        ];

        // Backward-compatible shortcuts for v0.2.1 UI/tests. Provider bridge code
        // should treat source_plan/request_context/limits as the source of truth.
        foreach ($plan as $key => $value) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }
        $payload['max_items'] = $max_items;
        $payload['provider_payload'] = $plan;

        return $payload;
    }

    private static function sanitize_source_plan(string $source_key, array $source_plan, int $admin_limit, ?int $default_limit = null): array {
        $source_key = sanitize_key($source_key);
        $out = ['source_key' => $source_key];
        foreach (self::list_fields_for($source_key) as $field => $limit) {
            $list = self::clean_list($source_plan[$field] ?? [], (int) $limit);
            if (!empty($list)) { $out[$field] = $list; }
        }
        foreach (self::string_fields_for($source_key) as $field => $default) {
            $value = sanitize_text_field((string) ($source_plan[$field] ?? $default));
            if ($value !== '') { $out[$field] = $value; }
        }
        $notes = trim(sanitize_textarea_field((string) ($source_plan['notes'] ?? '')));
        if ($notes !== '') { $out['notes'] = $notes; }

        $limit = $default_limit !== null ? max(1, min($admin_limit, $default_limit)) : $admin_limit;
        if (isset($source_plan['max_items'])) {
            $limit = min($admin_limit, max(1, (int) $source_plan['max_items']));
        }
        $out['max_items'] = $limit;
        $sort = sanitize_text_field((string) ($source_plan['sort_strategy'] ?? self::default_sort_for($source_key)));
        $out['sort_strategy'] = $sort !== '' ? $sort : self::default_sort_for($source_key);
        return $out;
    }

    public static function public_plan_fields_for(string $source_key): array {
        return array_merge(array_keys(self::list_fields_for($source_key)), array_keys(self::string_fields_for($source_key)), ['max_items', 'sort_strategy', 'notes']);
    }

    private static function list_fields_for(string $source_key): array {
        $common = ['language_hints' => 20, 'market_hints' => 20];
        switch (sanitize_key($source_key)) {
            case 'tiktok':
                return array_merge(['queries' => 20, 'hashtags' => 20, 'creator_or_format_hints' => 20], $common);
            case 'instagram':
                return array_merge(['queries' => 20, 'hashtags' => 20, 'profile_or_topic_hints' => 20], $common);
            case 'reddit':
                return array_merge(['queries' => 20, 'subreddits' => 20], $common);
            case 'google_trends':
                return array_merge(['seed_keywords' => 20, 'related_query_hints' => 20], $common);
            case 'google_news':
                return ['queries' => 20, 'publisher_or_topic_hints' => 20];
            case 'ai_research':
                return ['research_questions' => 20, 'assumptions_to_test' => 20, 'market_questions' => 20, 'competitor_questions' => 20];
            default:
                return array_merge(['queries' => 20, 'hashtags' => 20], $common);
        }
    }

    private static function string_fields_for(string $source_key): array {
        switch (sanitize_key($source_key)) {
            case 'reddit': return ['time_range' => 'last_30_days'];
            case 'google_trends': return ['geo' => '', 'time_range' => 'last_30_days'];
            case 'google_news': return ['geo' => '', 'language' => '', 'time_range' => 'last_30_days'];
            default: return [];
        }
    }

    private static function default_sort_for(string $source_key): string {
        switch (sanitize_key($source_key)) {
            case 'tiktok': return 'relevance_then_engagement';
            case 'instagram': return 'hashtag_relevance_then_recency';
            case 'reddit': return 'relevance_week';
            case 'google_trends': return 'interest_and_related_queries';
            case 'google_news': return 'freshness_and_publisher_diversity';
            case 'ai_research': return 'evidence_framing';
            default: return 'relevance';
        }
    }

    private static function clean_list($value, int $limit): array {
        if (is_string($value)) { $value = preg_split('/\r\n|\r|\n|,/', $value); }
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim(wp_strip_all_tags((string) $item));
            if ($item !== '') { $out[] = sanitize_text_field($item); }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, $limit))));
    }
}
