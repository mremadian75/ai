<?php
if (!defined('ABSPATH')) { exit; }

abstract class FIDTF_Provider_Apify {
    protected $source_key = '';

    public function source_key(): string {
        return sanitize_key($this->source_key);
    }

    public function actor_id(): string {
        return FIDTF_Settings::actor_for($this->source_key());
    }

    public function build_payload(array $source_plan, array $request): array {
        return FIDTF_Source_Dispatcher::build_canonical_payload($this->source_key(), $source_plan, $request);
    }

    protected static function payload_fields_for(string $source_key): array {
        switch (sanitize_key($source_key)) {
            case 'tiktok':
                return ['queries', 'hashtags', 'creator_or_format_hints', 'language_hints', 'market_hints'];
            case 'instagram':
                return ['queries', 'hashtags', 'profile_or_topic_hints', 'language_hints', 'market_hints'];
            case 'reddit':
                return ['queries', 'subreddits', 'time_range', 'language_hints', 'market_hints'];
            case 'google_trends':
                return ['seed_keywords', 'related_query_hints', 'geo', 'time_range', 'language_hints', 'market_hints'];
            case 'google_news':
                return ['queries', 'publisher_or_topic_hints', 'geo', 'language', 'time_range'];
            default:
                return ['queries', 'hashtags', 'language_hints', 'market_hints'];
        }
    }

    public function dispatch(array $source_plan, array $request) {
        $source_key = $this->source_key();
        if (!FIDTF_Settings::live_dispatch_enabled()) {
            return new WP_Error(
                'live_dispatch_disabled',
                'Deep Trend Finder live provider dispatch is disabled. The run was planned but no external scrape was started.',
                ['status' => 202, 'retryable' => false, 'request_attempted' => false]
            );
        }
        if (!FIDTF_Settings::source_live_ready($source_key)) {
            return new WP_Error(
                'source_live_bridge_disabled',
                ucfirst(str_replace('_', ' ', $source_key)) . ' live bridge is disabled or provider is not ready.',
                ['status' => 202, 'retryable' => false, 'request_attempted' => false]
            );
        }
        $payload = $this->build_payload($source_plan, $request);
        $adapter = new FIDTF_Generic_Apify_Live_Adapter($source_key);
        return $adapter->start($payload);
    }
}
