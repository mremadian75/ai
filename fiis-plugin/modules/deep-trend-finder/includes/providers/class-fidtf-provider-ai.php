<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Provider_AI {
    public function source_key(): string { return 'ai_research'; }
    public function actor_id(): string { return 'ai_research_bridge'; }

    public function build_payload(array $source_plan, array $request): array {
        return FIDTF_Source_Dispatcher::build_canonical_payload('ai_research', $source_plan, $request);
    }

    public function dispatch(array $source_plan, array $request) {
        $payload = $this->build_payload($source_plan, $request);
        if (!FIDTF_Settings::ai_research_bridge_enabled()) {
            return new WP_Error(
                'ai_research_bridge_missing',
                'AI research bridge is disabled or not configured. No web research was claimed.',
                ['status' => 202, 'retryable' => false]
            );
        }

        $external = apply_filters('fidtf_ai_research_items', null, $payload, $source_plan, $request);
        if (is_array($external) || is_wp_error($external)) {
            return $external;
        }
        return new WP_Error(
            'ai_research_bridge_missing',
            'AI research bridge is enabled, but no bridge handled fidtf_ai_research_items. No web research was claimed.',
            ['status' => 202, 'retryable' => false]
        );
    }
}
