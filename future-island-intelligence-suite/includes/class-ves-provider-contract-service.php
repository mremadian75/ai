<?php
if (!defined('ABSPATH')) { exit; }

/** Provider contract registry. Providers produce rows; WordPress validates/maps. */
final class VES_Provider_Contract_Service {
    private static $contracts = null;

    public static function contracts(): array {
        if (self::$contracts !== null) { return self::$contracts; }
        self::$contracts = [];
        foreach (self::default_contracts() as $contract) { self::register_contract($contract); }
        return self::$contracts;
    }

    public static function register_contract(array $contract): bool {
        $family = self::clean_key((string) ($contract['provider_family'] ?? ''), 60);
        $key = self::clean_key((string) ($contract['provider_key'] ?? ''), 80);
        if ($family === '' || $key === '') { return false; }
        $contract['provider_family'] = $family;
        $contract['provider_key'] = $key;
        $contract['schema_version'] = self::clean_text((string) ($contract['schema_version'] ?? 'v1'), 24);
        $contract['required_fields'] = self::clean_list($contract['required_fields'] ?? []);
        $contract['optional_fields'] = self::clean_list($contract['optional_fields'] ?? []);
        $contract['allowed_signal_types'] = self::clean_list($contract['allowed_signal_types'] ?? []);
        $contract['data_classification'] = self::classification((string) ($contract['data_classification'] ?? 'public'));
        $contract['supports_idempotency'] = !empty($contract['supports_idempotency']);
        $contract['supports_usage_estimate'] = !empty($contract['supports_usage_estimate']);
        $contract['token_redaction_required'] = array_key_exists('token_redaction_required', $contract) ? !empty($contract['token_redaction_required']) : true;
        $contract['enabled'] = array_key_exists('enabled', $contract) ? !empty($contract['enabled']) : true;
        self::$contracts[$family . ':' . $key] = $contract;
        return true;
    }

    public static function get_contract(string $family, string $provider_key) {
        $family = self::clean_key($family, 60);
        $provider_key = self::clean_key($provider_key, 80);
        $contracts = self::contracts();
        return $contracts[$family . ':' . $provider_key] ?? null;
    }

    public static function assert_contract(string $family, string $provider_key) {
        $contract = self::get_contract($family, $provider_key);
        if (!is_array($contract)) { return self::err('ves_provider_unknown', 'Unknown provider contract.'); }
        if (empty($contract['enabled'])) { return self::err('ves_provider_disabled', 'Provider contract is disabled.'); }
        return $contract;
    }

    public static function public_contracts(): array {
        $out = [];
        foreach (self::contracts() as $contract) {
            $out[] = VES_Secret_Redactor::redact([
                'provider_family' => $contract['provider_family'],
                'provider_key' => $contract['provider_key'],
                'schema_version' => $contract['schema_version'],
                'required_fields' => $contract['required_fields'],
                'optional_fields' => $contract['optional_fields'],
                'allowed_signal_types' => $contract['allowed_signal_types'],
                'data_classification' => $contract['data_classification'],
                'supports_idempotency' => $contract['supports_idempotency'],
                'supports_usage_estimate' => $contract['supports_usage_estimate'],
                'token_redaction_required' => $contract['token_redaction_required'],
                'enabled' => $contract['enabled'],
            ]);
        }
        return $out;
    }

    private static function default_contracts(): array {
        return [
            self::contract('social_signal', 'approved_social_provider', ['platform'], ['source_url','origin_ref','author_name','author_handle','title','text','caption','hashtags','published_at','metrics','language','market','provider_ref'], ['social_post','social_profile','social_topic','social_competitor']),
            self::contract('social_signal', 'manual_provider_row', ['platform'], ['source_url','origin_ref','title','text','caption','hashtags','published_at','metrics','language','market','provider_ref'], ['social_post','social_profile','social_topic']),
            self::contract('social_signal', 'disabled_social_provider', ['platform'], [], ['social_post'], false),
            self::contract('aeo_answer', 'approved_aeo_capture', ['prompt','answer'], ['brand_mentioned','mentioned_brand','brand_position','sentiment','competitor_mentions','citations','cited_sources','captured_at','provider_ref'], ['aeo_answer','ai_visibility']),
            self::contract('aeo_answer', 'manual_aeo_answer', ['prompt','answer'], ['brand_mentioned','mentioned_brand','brand_position','sentiment','competitor_mentions','citations','cited_sources','captured_at','provider_ref'], ['aeo_answer','ai_visibility']),
            self::contract('aeo_answer', 'disabled_aeo_capture', ['prompt','answer'], [], ['aeo_answer'], false),
            self::contract('creative_asset_analysis', 'manual_creative_analysis', ['summary'], ['asset_ref','source_url','platform','content_type','asset_type','hook_type','message_angle','cta_type','format','creative_style','audience_stage','funnel_stage','offer_type','emotional_territory','source_inspiration','evidence_refs','provider_ref'], ['creative_asset','creative_learning']),
            self::contract('search_signal', 'manual_search_signal', ['title'], ['source_url','snippet','rank','query','market','language','provider_ref'], ['search_result','search_signal']),
            self::contract('competitor_signal', 'manual_competitor_signal', ['competitor_name'], ['source_url','message','market','language','provider_ref'], ['competitor_message','competitor_signal']),
        ];
    }

    private static function contract(string $family, string $key, array $required, array $optional, array $signals, bool $enabled = true): array {
        return [
            'provider_family' => $family,
            'provider_key' => $key,
            'schema_version' => 'v1',
            'input_schema' => [],
            'output_schema' => [],
            'required_fields' => $required,
            'optional_fields' => $optional,
            'allowed_signal_types' => $signals,
            'data_classification' => 'public',
            'supports_idempotency' => true,
            'supports_usage_estimate' => true,
            'token_redaction_required' => true,
            'enabled' => $enabled,
        ];
    }

    public static function clean_key(string $s, int $max = 80): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function clean_list($list): array { $out = []; foreach ((array) $list as $v) { $k = self::clean_key((string) $v, 80); if ($k !== '') { $out[] = $k; } } return array_values(array_unique($out)); }
    private static function classification(string $v): string { $v = self::clean_key($v, 20); return in_array($v, ['public','private','sensitive'], true) ? $v : 'public'; }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message]; }
}
