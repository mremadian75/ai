<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Generation_Context_Resolver — Phase 6B-A (+ corrective).
 * Disabled-by-default, trusted-only Brand Context envelope for future generation.
 * No AI call, no mutation, no charge. Read-time redaction. PHP 7.4 compatible.
 */
final class VES_Generation_Context_Resolver {

    const OPTION = 'ves_brand_context_generation_enabled';
    const USE_CASES = ['insight_generation', 'brief_generation', 'draft_generation', 'qa_check'];

    const SECTION_MAP = [
        'voice_rule' => 'voice_rules', 'forbidden_claim' => 'forbidden_claims', 'preferred_term' => 'preferred_terms',
        'avoided_term' => 'avoided_terms', 'audience_fact' => 'audience_facts', 'positioning_note' => 'positioning_notes',
    ];

    public static function policy() {
        return [
            'memory_is_not_evidence' => true,
            'do_not_make_factual_market_claims_from_memory_only' => true,
            'use_memory_for_tone_constraints_and_preferences' => true,
        ];
    }

    public static function is_enabled() {
        $enabled = false;
        if (function_exists('get_option')) { $enabled = (bool) get_option(self::OPTION, false); }
        if (function_exists('apply_filters')) { $enabled = (bool) apply_filters(self::OPTION, $enabled); }
        return $enabled;
    }

    private static function valid_use_case($uc) { return in_array((string) $uc, self::USE_CASES, true); }

    private static function package_args($workspace_id, $use_case, array $args) {
        $out = ['workspace_id' => (int) $workspace_id, 'use_case' => (string) $use_case];
        if (isset($args['max_items']) && (int) $args['max_items'] > 0) { $out['max_items'] = (int) $args['max_items']; }
        if (isset($args['max_chars']) && (int) $args['max_chars'] > 0) { $out['max_chars'] = (int) $args['max_chars']; }
        return $out;
    }

    public static function resolve(array $args = []) {
        $use_case = is_scalar($args['use_case'] ?? null) ? (string) $args['use_case'] : 'brief_generation';
        $workspace_id = (int) ($args['workspace_id'] ?? 0);
        $enabled = self::is_enabled();
        $envelope = [
            'enabled' => $enabled, 'workspace_id' => $workspace_id, 'use_case' => $use_case,
            'context_package' => ['items' => [], 'limits' => []], 'applied' => false, 'reason' => '',
            'warnings' => ['memory_is_not_evidence'],
        ];
        if (!self::valid_use_case($use_case)) { $envelope['reason'] = 'invalid_use_case'; return $envelope; }
        if ($workspace_id <= 0) { $envelope['reason'] = 'no_workspace'; return $envelope; }
        if (!$enabled) { $envelope['reason'] = 'feature_disabled'; return $envelope; }
        if (!class_exists('VES_Brand_Context_Service')) { $envelope['reason'] = 'service_unavailable'; return $envelope; }

        $pkg = VES_Brand_Context_Service::build_context_package(self::package_args($workspace_id, $use_case, $args));
        $items = self::sanitize_context_items((array) ($pkg['items'] ?? []));
        $envelope['context_package'] = ['items' => $items, 'limits' => (array) ($pkg['limits'] ?? [])];
        if (empty($items)) { $envelope['reason'] = 'no_trusted_context'; $envelope['applied'] = false; return $envelope; }
        $envelope['applied'] = true;
        $envelope['reason'] = 'context_available';
        return $envelope;
    }

    /** Redact summaries on every context item while keeping traceability fields. */
    public static function sanitize_context_items($items) {
        $out = [];
        foreach ((array) $items as $it) {
            if (!is_array($it)) { continue; }
            if (isset($it['summary'])) { $it['summary'] = self::redact((string) $it['summary']); }
            $out[] = $it;
        }
        return $out;
    }

    public static function build_brand_context_section($workspace_id, $use_case, array $args = []) {
        $envelope = self::resolve(array_merge($args, ['workspace_id' => (int) $workspace_id, 'use_case' => (string) $use_case]));
        return self::section_from_items($envelope['applied'] ? $envelope['context_package']['items'] : []);
    }

    public static function section_from_items($items) {
        $section = ['voice_rules' => [], 'forbidden_claims' => [], 'preferred_terms' => [], 'avoided_terms' => [], 'audience_facts' => [], 'positioning_notes' => [], 'other_context' => []];
        foreach ((array) $items as $it) {
            $type = (string) ($it['record_type'] ?? '');
            $bucket = isset(self::SECTION_MAP[$type]) ? self::SECTION_MAP[$type] : 'other_context';
            $section[$bucket][] = [
                'memory_id' => (int) ($it['memory_id'] ?? 0), 'record_type' => $type,
                'summary' => self::redact((string) ($it['summary'] ?? '')),
                'importance_score' => (float) ($it['importance_score'] ?? 0),
                'source_target_type' => (string) ($it['source_target_type'] ?? ''),
                'source_target_id' => (int) ($it['source_target_id'] ?? 0),
            ];
        }
        return ['brand_context' => $section, 'policy' => self::policy()];
    }

    private static function redact($text) {
        if (class_exists('VES_Brand_Context_Service') && method_exists('VES_Brand_Context_Service', 'redact_sensitive_text')) {
            return VES_Brand_Context_Service::redact_sensitive_text($text);
        }
        return preg_replace('/\bsk-[A-Za-z0-9_\-]{8,}|\bBearer\s+[A-Za-z0-9._\-]{8,}/i', '[redacted]', (string) $text);
    }

    public static function preview(array $args = []) {
        $use_case = is_scalar($args['use_case'] ?? null) ? (string) $args['use_case'] : 'brief_generation';
        $workspace_id = (int) ($args['workspace_id'] ?? 0);
        $enabled = self::is_enabled();
        $out = ['enabled' => $enabled, 'workspace_id' => $workspace_id, 'use_case' => $use_case, 'reason' => '',
            'would_apply' => false, 'items_included' => 0, 'items_excluded' => [], 'total_chars' => 0,
            'limits' => ['max_items' => 0, 'max_chars' => 0], 'items' => [], 'warnings' => ['memory_is_not_evidence'], 'policy' => self::policy()];
        if (!self::valid_use_case($use_case)) { $out['reason'] = 'invalid_use_case'; return $out; }
        if ($workspace_id <= 0) { $out['reason'] = 'no_workspace'; return $out; }
        if (!class_exists('VES_Brand_Context_Service')) { $out['reason'] = 'service_unavailable'; return $out; }
        $pkg = VES_Brand_Context_Service::build_context_package(self::package_args($workspace_id, $use_case, $args));
        $out['items_included'] = (int) ($pkg['item_count'] ?? 0);
        $out['items_excluded'] = (array) ($pkg['excluded'] ?? []);
        $out['total_chars'] = (int) ($pkg['char_count'] ?? 0);
        $out['limits'] = (array) ($pkg['limits'] ?? []);
        foreach ((array) ($pkg['items'] ?? []) as $it) {
            $out['items'][] = ['memory_id' => (int) ($it['memory_id'] ?? 0), 'record_type' => (string) ($it['record_type'] ?? ''),
                'importance_score' => (float) ($it['importance_score'] ?? 0), 'source_target_type' => (string) ($it['source_target_type'] ?? ''), 'source_target_id' => (int) ($it['source_target_id'] ?? 0)];
        }
        $out['would_apply'] = $enabled && $out['items_included'] > 0;
        $out['reason'] = !$enabled ? 'feature_disabled' : ($out['items_included'] > 0 ? 'context_available' : 'no_trusted_context');
        if (class_exists('VES_Log') && method_exists('VES_Log', 'info')) {
            VES_Log::info('brand_context', 'Generation context preview (dry-run)', ['workspace_id' => $workspace_id, 'use_case' => $use_case, 'enabled' => $enabled ? 1 : 0, 'items' => $out['items_included']]);
        }
        return $out;
    }
}
