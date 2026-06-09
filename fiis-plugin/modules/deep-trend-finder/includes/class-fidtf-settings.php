<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Settings {
    const OPTION = 'fidtf_settings';

    public static function defaults(): array {
        return [
            'enabled_sources' => ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'],
            'source_limits' => [
                'tiktok' => 50,
                'instagram' => 50,
                'reddit' => 100,
                'google_trends' => 100,
                'google_news' => 100,
                'ai_research' => 12,
            ],
            'source_max_caps' => [
                'tiktok' => 300,
                'instagram' => 300,
                'reddit' => 200,
                'google_trends' => 300,
                'google_news' => 200,
                'ai_research' => 20,
            ],
            'actor_map' => [
                'tiktok' => 'clockworks/tiktok-scraper',
                'instagram' => 'apify/instagram-scraper',
                'reddit' => 'trudax/reddit-scraper-lite',
                'google_trends' => 'data_xplorer/google-trends-fast-scraper',
                'google_news' => 'data_xplorer/google-news-scraper-fast',
            ],
            'planner_model_alias' => 'primary_reasoning_model',
            'relevance_model_alias' => 'primary_filtering_model',
            'synthesis_model_alias' => 'primary_report_model',
            'max_relevance_batch_size' => 40,
            'enable_deep_video' => false,
            'enable_live_dispatch' => false,
            'enable_ai_planner_bridge' => false,
            'enable_ai_research_bridge' => false,
            'enable_tiktok_live_bridge' => false,
            'enable_multi_source_live_bridge' => true,
            'tiktok_default_limit' => 25,
            'tiktok_max_limit' => 300,
            'tiktok_min_views' => 10000,
            'tiktok_actor_id' => 'scraptik/tiktok-api', // legacy v0.3.11 setting; migrated to discovery/enrichment below.
            'tiktok_discovery_actor_id' => 'clockworks/tiktok-scraper',
            'tiktok_enrichment_actor_id' => 'scraptik/tiktok-api',
            'tiktok_polling_enabled' => true,
            'tiktok_provider_mode' => 'core_apify_client',
            'tiktok_apify_token' => '',
            'enable_credit_reservation' => false,
            'debug_mode' => false,
            'retention_days' => 30,
            'credit_multipliers' => [
                'planning' => 1.0,
                'source_job' => 1.0,
                'relevance_batch' => 1.0,
                'synthesis' => 1.0,
                'deep_video' => 8.0,
            ],
        ];
    }

    public static function ensure_defaults(): void {
        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
    }

    /**
     * v0.3.18 — one-time settings migration.
     * Existing installs persisted the old tiktok_min_views default (20000).
     * 20000 was never a deliberate user choice; it was simply the previous
     * default. Migrate it to the new 10000 default exactly once. The migration
     * flag prevents re-running, so a later deliberate change to any value
     * (including 20000) is never overwritten.
     */
    public static function maybe_migrate(): void {
        if (!function_exists('get_option') || !function_exists('update_option')) { return; }
        $migration_flag = 'fidtf_settings_migrated_min_views_10k';
        if (get_option($migration_flag, '') === 'done') { return; }
        $stored = get_option(self::OPTION, false);
        if (is_array($stored)) {
            if ((int) ($stored['tiktok_min_views'] ?? 0) === 20000) {
                $stored['tiktok_min_views'] = 10000;
                update_option(self::OPTION, $stored, false);
            }
        }
        update_option($migration_flag, 'done', false);
    }

    public static function get(): array {
        $stored = get_option(self::OPTION, []);
        return self::merge_defaults(is_array($stored) ? $stored : []);
    }

    private static function merge_defaults(array $stored): array {
        $defaults = self::defaults();
        $had_discovery = array_key_exists('tiktok_discovery_actor_id', $stored);
        $legacy_actor = sanitize_text_field((string) ($stored['tiktok_actor_id'] ?? ''));
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $stored)) {
                $stored[$key] = $value;
            } elseif (is_array($value) && is_array($stored[$key])) {
                // Numeric/list arrays must not be merged with defaults because that
                // duplicates frontend source cards after repeated saves. Associative
                // maps keep defaults for missing keys only.
                if (self::is_list_array($value)) {
                    $stored[$key] = self::dedupe_source_list($stored[$key], $defaults['enabled_sources'] ?? []);
                } else {
                    $stored[$key] = array_merge($value, $stored[$key]);
                }
            }
        }
        $stored['enabled_sources'] = self::dedupe_source_list((array) ($stored['enabled_sources'] ?? []), (array) ($defaults['enabled_sources'] ?? []));
        if (!$had_discovery && $legacy_actor !== '' && !self::is_tiktok_enrichment_actor($legacy_actor)) {
            $stored['tiktok_discovery_actor_id'] = $legacy_actor;
        }
        if (!empty($stored['tiktok_discovery_actor_id']) && self::is_tiktok_enrichment_actor((string) $stored['tiktok_discovery_actor_id'])) {
            $stored['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
        }
        if ($legacy_actor !== '' && self::is_tiktok_enrichment_actor($legacy_actor) && empty($stored['tiktok_enrichment_actor_id'])) {
            $stored['tiktok_enrichment_actor_id'] = $legacy_actor;
        }
        $stored['actor_map']['tiktok'] = $stored['tiktok_discovery_actor_id'];
        return $stored;
    }

    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) { return false; }
            $expected++;
        }
        return true;
    }

    public static function ordered_source_keys(): array {
        return ['tiktok', 'instagram', 'reddit', 'google_trends', 'google_news', 'ai_research'];
    }

    public static function dedupe_source_list(array $sources, array $fallback = []): array {
        $allowed = self::ordered_source_keys();
        $seen = [];
        foreach ($sources as $source) {
            $source = sanitize_key((string) $source);
            if ($source !== '' && in_array($source, $allowed, true)) { $seen[$source] = true; }
        }
        if (empty($seen)) {
            foreach ($fallback as $source) {
                $source = sanitize_key((string) $source);
                if ($source !== '' && in_array($source, $allowed, true)) { $seen[$source] = true; }
            }
        }
        $ordered = [];
        foreach ($allowed as $source) {
            if (!empty($seen[$source])) { $ordered[] = $source; }
        }
        return $ordered;
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $out = self::get();
        $known_sources = array_keys($defaults['source_limits']);

        $enabled = [];
        foreach ((array) ($input['enabled_sources'] ?? []) as $source) {
            $source = sanitize_key((string) $source);
            if (in_array($source, $known_sources, true)) { $enabled[] = $source; }
        }
        $out['enabled_sources'] = self::dedupe_source_list($enabled, $defaults['enabled_sources']);

        foreach ($known_sources as $source) {
            $cap = (int) ($defaults['source_max_caps'][$source] ?? 100);
            $val = (int) ($input['source_limits'][$source] ?? $out['source_limits'][$source] ?? $defaults['source_limits'][$source]);
            $out['source_limits'][$source] = max(1, min($cap, $val));
        }

        foreach ((array) ($input['actor_map'] ?? []) as $source => $actor) {
            $source = sanitize_key((string) $source);
            if (!isset($defaults['actor_map'][$source])) { continue; }
            $actor = sanitize_text_field((string) $actor);
            if ($source === 'tiktok') {
                if ($actor !== '' && self::is_tiktok_enrichment_actor($actor)) {
                    $out['tiktok_enrichment_actor_id'] = $actor;
                } elseif ($actor !== '') {
                    $out['tiktok_discovery_actor_id'] = $actor;
                }
                continue;
            }
            $out['actor_map'][$source] = $actor;
        }

        // v0.3.12: tiktok_actor_id is legacy and ambiguous. If an old install
        // stored Scraptik there, keep it as enrichment and never use it as the
        // default discovery actor.
        if (isset($input['tiktok_actor_id'])) {
            $legacy_actor = sanitize_text_field((string) $input['tiktok_actor_id']);
            if ($legacy_actor !== '' && self::is_tiktok_enrichment_actor($legacy_actor)) {
                $out['tiktok_enrichment_actor_id'] = $legacy_actor;
            } elseif ($legacy_actor !== '') {
                $out['tiktok_discovery_actor_id'] = $legacy_actor;
            }
        }
        if (isset($input['tiktok_discovery_actor_id'])) {
            $candidate = sanitize_text_field((string) $input['tiktok_discovery_actor_id']);
            if ($candidate !== '' && !self::is_tiktok_enrichment_actor($candidate)) {
                $out['tiktok_discovery_actor_id'] = $candidate;
            }
        }
        if (isset($input['tiktok_enrichment_actor_id'])) {
            $candidate = sanitize_text_field((string) $input['tiktok_enrichment_actor_id']);
            if ($candidate !== '') {
                $out['tiktok_enrichment_actor_id'] = $candidate;
            }
        }
        if (empty($out['tiktok_discovery_actor_id']) || self::is_tiktok_enrichment_actor((string) $out['tiktok_discovery_actor_id'])) {
            $out['tiktok_discovery_actor_id'] = 'clockworks/tiktok-scraper';
        }
        if (empty($out['tiktok_enrichment_actor_id'])) {
            $out['tiktok_enrichment_actor_id'] = 'scraptik/tiktok-api';
        }
        $out['tiktok_actor_id'] = $out['tiktok_discovery_actor_id'];
        $out['actor_map']['tiktok'] = $out['tiktok_discovery_actor_id'];
        $default_tiktok_limit = max(1, min(300, (int) ($input['tiktok_default_limit'] ?? $out['tiktok_default_limit'] ?? $defaults['tiktok_default_limit'])));
        $tiktok_max_limit = max(1, min(300, (int) ($input['tiktok_max_limit'] ?? $out['tiktok_max_limit'] ?? $defaults['tiktok_max_limit'])));
        if ($default_tiktok_limit > $tiktok_max_limit) { $default_tiktok_limit = $tiktok_max_limit; }
        $out['tiktok_default_limit'] = $default_tiktok_limit;
        $out['tiktok_max_limit'] = $tiktok_max_limit;
        $out['source_limits']['tiktok'] = $default_tiktok_limit;
        $out['source_max_caps']['tiktok'] = $tiktok_max_limit;

        $mode = sanitize_key((string) ($input['tiktok_provider_mode'] ?? $out['tiktok_provider_mode'] ?? $defaults['tiktok_provider_mode']));
        $allowed_modes = ['external_filter', 'core_apify_client', 'apify_bridge'];
        $out['tiktok_provider_mode'] = in_array($mode, $allowed_modes, true) ? $mode : 'core_apify_client';
        if (array_key_exists('tiktok_apify_token', $input)) {
            $token = trim((string) $input['tiktok_apify_token']);
            if ($token !== '') {
                $out['tiktok_apify_token'] = sanitize_text_field($token);
            }
        }

        foreach (['planner_model_alias', 'relevance_model_alias', 'synthesis_model_alias'] as $alias_key) {
            if (isset($input[$alias_key])) {
                $out[$alias_key] = sanitize_key((string) $input[$alias_key]);
            }
        }

        $out['max_relevance_batch_size'] = max(5, min(100, (int) ($input['max_relevance_batch_size'] ?? $out['max_relevance_batch_size'])));
        $out['enable_deep_video'] = !empty($input['enable_deep_video']);
        $out['enable_live_dispatch'] = !empty($input['enable_live_dispatch']);
        $out['enable_ai_planner_bridge'] = !empty($input['enable_ai_planner_bridge']);
        $out['enable_ai_research_bridge'] = !empty($input['enable_ai_research_bridge']);
        $out['enable_tiktok_live_bridge'] = !empty($input['enable_tiktok_live_bridge']);
        $out['enable_multi_source_live_bridge'] = !empty($input['enable_multi_source_live_bridge']);
        $out['tiktok_polling_enabled'] = !empty($input['tiktok_polling_enabled']);
        $out['enable_credit_reservation'] = !empty($input['enable_credit_reservation']);
        $out['debug_mode'] = !empty($input['debug_mode']);
        $out['retention_days'] = max(1, min(365, (int) ($input['retention_days'] ?? $out['retention_days'])));
        return $out;
    }

    public static function source_enabled(string $source): bool {
        $settings = self::get();
        return in_array(sanitize_key($source), (array) $settings['enabled_sources'], true);
    }

    public static function source_limit(string $source): int {
        $source = sanitize_key($source);
        $settings = self::get();
        $defaults = self::defaults();
        $limit = (int) ($settings['source_limits'][$source] ?? $defaults['source_limits'][$source] ?? 50);
        $cap = (int) ($settings['source_max_caps'][$source] ?? $defaults['source_max_caps'][$source] ?? 100);
        return max(1, min($cap, $limit));
    }

    public static function actor_for(string $source): string {
        $source = sanitize_key($source);
        $settings = self::get();
        if ($source === 'tiktok') {
            return self::tiktok_discovery_actor_id();
        }
        return sanitize_text_field((string) ($settings['actor_map'][$source] ?? ''));
    }

    public static function live_dispatch_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_live_dispatch']);
    }


    public static function multi_source_live_bridge_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_multi_source_live_bridge']);
    }

    public static function generic_apify_provider_ready(): bool {
        $core = self::core_apify_client_status();
        return !empty($core['complete']) || self::tiktok_apify_token() !== '';
    }

    public static function source_live_ready(string $source): bool {
        $source = sanitize_key($source);
        if ($source === 'tiktok') { return self::tiktok_dispatch_allowed(); }
        if ($source === 'ai_research') { return self::ai_research_bridge_enabled(); }
        if (!self::source_enabled($source) || !self::live_dispatch_enabled() || !self::multi_source_live_bridge_enabled()) { return false; }
        if (self::actor_for($source) === '') { return false; }
        return self::generic_apify_provider_ready();
    }

    public static function tiktok_live_bridge_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_tiktok_live_bridge']);
    }

    public static function tiktok_dispatch_allowed(): bool {
        return self::live_dispatch_enabled() && self::tiktok_live_bridge_enabled();
    }

    public static function tiktok_default_limit(): int {
        $settings = self::get();
        $limit = (int) ($settings['tiktok_default_limit'] ?? 25);
        return max(1, min(self::tiktok_max_limit(), $limit));
    }

    public static function tiktok_max_limit(): int {
        $settings = self::get();
        return max(1, min(300, (int) ($settings['tiktok_max_limit'] ?? 300)));
    }

    public static function tiktok_min_views(): int {
        $settings = self::get();
        return max(0, min(100000000, (int) ($settings['tiktok_min_views'] ?? 20000)));
    }

    public static function clamp_tiktok_limit($requested = null): int {
        $default = self::tiktok_default_limit();
        $max = self::tiktok_max_limit();
        $limit = $requested === null ? $default : (int) $requested;
        if ($limit <= 0) { $limit = $default; }
        return max(1, min($max, $limit));
    }

    public static function tiktok_actor_id(): string {
        return self::tiktok_discovery_actor_id();
    }

    public static function tiktok_discovery_actor_id(): string {
        $settings = self::get();
        $actor = sanitize_text_field((string) ($settings['tiktok_discovery_actor_id'] ?? ''));
        if ($actor === '') {
            $legacy = sanitize_text_field((string) ($settings['tiktok_actor_id'] ?? ''));
            $mapped = sanitize_text_field((string) ($settings['actor_map']['tiktok'] ?? ''));
            if ($legacy !== '' && !self::is_tiktok_enrichment_actor($legacy)) {
                $actor = $legacy;
            } elseif ($mapped !== '' && !self::is_tiktok_enrichment_actor($mapped)) {
                $actor = $mapped;
            }
        }
        if ($actor === '' || self::is_tiktok_enrichment_actor($actor)) {
            return 'clockworks/tiktok-scraper';
        }
        return $actor;
    }

    public static function tiktok_enrichment_actor_id(): string {
        $settings = self::get();
        $actor = sanitize_text_field((string) ($settings['tiktok_enrichment_actor_id'] ?? ''));
        if ($actor === '') {
            $legacy = sanitize_text_field((string) ($settings['tiktok_actor_id'] ?? ''));
            if ($legacy !== '' && self::is_tiktok_enrichment_actor($legacy)) {
                $actor = $legacy;
            }
        }
        // v0.3.18: the enrichment input builder (build_enrichment_actor_input /
        // scraptik_endpoint_defaults) produces scraptik-format input only
        // (post_awemeId, listComments_awemeId, etc). A non-scraptik actor cannot
        // consume that input and would reject every enrichment run with HTTP 400.
        // Fall back to the default scraptik actor when a non-scraptik actor is
        // configured here, so enrichment stays functional.
        if ($actor === '' || !self::is_tiktok_enrichment_actor($actor)) {
            return 'scraptik/tiktok-api';
        }
        return $actor;
    }

    public static function tiktok_actor_role(string $actor_id): string {
        return self::is_tiktok_enrichment_actor($actor_id) ? 'tiktok_enrichment' : 'tiktok_discovery';
    }

    public static function is_tiktok_enrichment_actor(string $actor_id): bool {
        $actor = strtolower(trim($actor_id));
        return $actor === 'scraptik/tiktok-api' || strpos($actor, 'scraptik/') === 0;
    }

    public static function tiktok_provider_mode(): string {
        $settings = self::get();
        $mode = sanitize_key((string) ($settings['tiktok_provider_mode'] ?? 'core_apify_client'));
        return in_array($mode, ['external_filter', 'core_apify_client', 'apify_bridge'], true) ? $mode : 'core_apify_client';
    }

    public static function tiktok_effective_provider_mode(): string {
        $configured = self::tiktok_provider_mode();
        $core = self::core_apify_client_status();
        $has_token = self::tiktok_apify_token() !== '';
        $external_available = self::external_filter_available();

        if ($configured === 'core_apify_client' && !empty($core['complete'])) { return 'core_apify_client'; }
        if ($configured === 'apify_bridge' && $has_token) { return 'apify_bridge'; }
        if ($configured === 'external_filter' && $external_available) { return 'external_filter'; }

        // v0.3.8: production-safe fallback. The add-on should behave like the
        // mother plugin whenever Core is active, instead of silently blocking
        // dispatch because the stored mode is still the old external_filter
        // default from earlier add-on versions.
        if (!empty($core['complete'])) { return 'core_apify_client'; }
        if ($has_token) { return 'apify_bridge'; }
        if ($external_available) { return 'external_filter'; }
        return $configured;
    }

    public static function tiktok_polling_enabled(): bool {
        $settings = self::get();
        return !empty($settings['tiktok_polling_enabled']);
    }

    public static function tiktok_apify_token(): string {
        if (defined('FIDTF_TIKTOK_APIFY_TOKEN') && FIDTF_TIKTOK_APIFY_TOKEN) {
            return trim((string) FIDTF_TIKTOK_APIFY_TOKEN);
        }
        $settings = self::get();
        return trim((string) ($settings['tiktok_apify_token'] ?? ''));
    }

    public static function external_filter_available(): bool {
        if (function_exists('has_filter')) {
            return (bool) has_filter('fidtf_tiktok_live_dispatch_result') || (bool) has_filter('fidtf_dispatch_source_job');
        }
        $handlers = isset($GLOBALS['fidtf_filter_handlers']) && is_array($GLOBALS['fidtf_filter_handlers']) ? $GLOBALS['fidtf_filter_handlers'] : [];
        return !empty($handlers['fidtf_tiktok_live_dispatch_result']) || !empty($handlers['fidtf_dispatch_source_job']);
    }

    public static function core_apify_client_status(): array {
        if (class_exists('FIDTF_Core_Apify_Client_Adapter')) {
            $diag = FIDTF_Core_Apify_Client_Adapter::diagnostics();
            // v0.3.16: Also verify the main plugin's Apify token is set.
            // The class + methods existing does not guarantee a token is
            // configured. Without a token VES_Apify_Client::request() returns
            // missing_backend_token, which the preflight check would miss.
            if (!empty($diag['complete']) && class_exists('VES_Config') && method_exists('VES_Config', 'get_token')) {
                $token_set = trim((string) VES_Config::get_token()) !== '';
                if (!$token_set) {
                    $diag['complete'] = false;
                    $diag['token_set'] = false;
                    $diag['missing_methods'] = array_merge((array) ($diag['missing_methods'] ?? []), ['backend_token_missing']);
                } else {
                    $diag['token_set'] = true;
                }
            } elseif (!empty($diag['complete'])) {
                // Unit-test / adapter-only environments may mock VES_Apify_Client
                // without loading the full Core VES_Config class. In production the
                // Core plugin provides VES_Config and the token check above remains
                // authoritative.
                $diag['token_set'] = null;
            }
            return $diag;
        }
        $class_exists = class_exists('VES_Apify_Client');
        $methods = [];
        $missing = [];
        foreach (['request', 'fetch_run', 'fetch_items'] as $method) {
            $ok = $class_exists && method_exists('VES_Apify_Client', $method);
            $methods[$method] = $ok;
            if (!$ok) { $missing[] = $method; }
        }
        $missing[] = 'FIDTF_Core_Apify_Client_Adapter';
        return [
            'core_active' => defined('VES_PLUGIN_VERSION') || class_exists('VES_Plugin') || class_exists('VES_Config') || $class_exists,
            'ves_apify_client_available' => $class_exists,
            'adapter_available' => false,
            'methods' => $methods,
            'missing_methods' => array_values(array_unique($missing)),
            'complete' => false,
            'token_set' => false,
        ];
    }

    public static function recommended_tiktok_provider_mode(): string {
        $core = self::core_apify_client_status();
        if (!empty($core['complete'])) { return 'core_apify_client'; }
        if (self::tiktok_apify_token() !== '') { return 'apify_bridge'; }
        if (self::external_filter_available()) { return 'external_filter'; }
        return 'not_configured';
    }

    public static function tiktok_provider_config_status(): array {
        $configured_mode = self::tiktok_provider_mode();
        $mode = self::tiktok_effective_provider_mode();
        $has_token = self::tiktok_apify_token() !== '';
        $core = self::core_apify_client_status();
        $external_available = self::external_filter_available();
        $provider_ready = ($mode === 'core_apify_client' && !empty($core['complete']))
            || ($mode === 'external_filter' && $external_available)
            || ($mode === 'apify_bridge' && $has_token);
        $warnings = [];
        if ($configured_mode === 'external_filter' && !$external_available && $mode !== 'external_filter') {
            $warnings[] = 'External filter mode has no handler; falling back to the available provider mode.';
        } elseif ($mode === 'external_filter' && !$external_available) {
            $warnings[] = 'External filter mode requires a registered provider bridge handler. No handler was detected.';
        }
        if ($mode === 'core_apify_client' && empty($core['complete'])) {
            $warnings[] = 'Core Apify client is not available or missing required methods.';
        }
        if ($mode === 'apify_bridge') {
            $warnings[] = 'Direct Apify mode bypasses Core concurrency/cost guards and should be used only for QA/debug unless approved.';
            if (!$has_token) {
                $warnings[] = 'Direct Apify token is missing. Direct mode cannot start a TikTok provider run.';
            }
        }
        if (self::live_dispatch_enabled() && !self::tiktok_live_bridge_enabled()) {
            $warnings[] = 'Live dispatch is enabled globally, but TikTok bridge is still disabled.';
        }
        if (self::tiktok_live_bridge_enabled() && !$provider_ready) {
            $warnings[] = 'TikTok bridge is enabled, but no usable TikTok provider is configured.';
        }
        return [
            'mode' => $mode,
            'configured_mode' => $configured_mode,
            'effective_mode' => $mode,
            'actor_id' => self::tiktok_discovery_actor_id(),
            'discovery_actor_id' => self::tiktok_discovery_actor_id(),
            'enrichment_actor_id' => self::tiktok_enrichment_actor_id(),
            'has_token' => $has_token,
            'direct_token_available' => $has_token,
            'external_filter_available' => $external_available,
            'core_active' => !empty($core['core_active']),
            'ves_apify_client_available' => !empty($core['ves_apify_client_available']),
            'core_methods' => (array) ($core['methods'] ?? []),
            'core_missing_methods' => (array) ($core['missing_methods'] ?? []),
            'core_complete' => !empty($core['complete']),
            'recommended_provider_mode' => self::recommended_tiktok_provider_mode(),
            'tiktok_bridge_enabled' => self::tiktok_live_bridge_enabled(),
            'live_dispatch_enabled' => self::live_dispatch_enabled(),
            'configured' => $provider_ready,
            'provider_ready' => $provider_ready,
            'warnings' => $warnings,
        ];
    }

    private static function sanitize_requested_sources(array $channels = []): array {
        $settings = self::get();
        $candidate = !empty($channels) ? $channels : (array) ($settings['enabled_sources'] ?? []);
        $out = [];
        foreach ((array) $candidate as $source) {
            $source = sanitize_key((string) $source);
            if ($source !== '' && self::source_enabled($source)) { $out[] = $source; }
        }
        if (empty($out) && self::source_enabled('tiktok')) { $out[] = 'tiktok'; }
        return self::dedupe_source_list($out, ['tiktok']);
    }

    public static function live_preflight_status(array $channels = []): array {
        $requested = self::sanitize_requested_sources($channels);
        $status = self::tiktok_provider_config_status();
        $mode = sanitize_key((string) ($status['mode'] ?? self::tiktok_provider_mode()));
        $global = self::live_dispatch_enabled();
        $bridge = self::tiktok_live_bridge_enabled();
        $provider_ready = !empty($status['provider_ready']) || !empty($status['configured']);
        $generic_ready = self::generic_apify_provider_ready();
        $multi_bridge = self::multi_source_live_bridge_enabled();
        $blocking = [];
        if (!$global) { $blocking[] = 'global_live_disabled'; }
        if (in_array('tiktok', $requested, true)) {
            if (!$bridge) { $blocking[] = 'tiktok_live_bridge_disabled'; }
            if (!$provider_ready) {
                if ($mode === 'core_apify_client') { $blocking[] = 'core_apify_unavailable'; }
                elseif ($mode === 'apify_bridge') { $blocking[] = 'direct_token_missing'; }
                elseif ($mode === 'external_filter') { $blocking[] = 'external_filter_missing'; }
                else { $blocking[] = 'provider_not_ready'; }
            }
        }
        $tiktok_ready = $global && $bridge && $provider_ready;
        $live_sources = [];
        $planned_sources = [];
        foreach ($requested as $source) {
            if ($source === 'tiktok' && $tiktok_ready) {
                $live_sources[] = 'tiktok';
            } elseif ($source !== 'ai_research' && $source !== 'tiktok' && $global && $multi_bridge && $generic_ready && self::actor_for($source) !== '') {
                $live_sources[] = $source;
            } elseif ($source === 'ai_research' && self::ai_research_bridge_enabled()) {
                $live_sources[] = $source;
            } else {
                $planned_sources[] = $source;
            }
        }
        if (!$multi_bridge) {
            foreach ($requested as $source) {
                if ($source !== 'tiktok' && $source !== 'ai_research') { $blocking[] = 'multi_source_live_bridge_disabled'; break; }
            }
        } elseif ($global && !$generic_ready) {
            foreach ($requested as $source) {
                if ($source !== 'tiktok' && $source !== 'ai_research') { $blocking[] = 'generic_apify_unavailable'; break; }
            }
        }
        $ai_runtime = class_exists('FIDTF_AI_Bridge') ? FIDTF_AI_Bridge::openai_runtime_status() : ['configured' => false, 'transport_ready' => false, 'can_live_test' => false];
        return [
            'global_live_dispatch_enabled' => $global,
            'tiktok_live_bridge_enabled' => $bridge,
            'multi_source_live_bridge_enabled' => $multi_bridge,
            'generic_apify_ready' => $generic_ready,
            'tiktok_provider_mode' => $mode,
            'core_apify_available' => !empty($status['ves_apify_client_available']),
            'core_apify_methods_available' => !empty($status['core_complete']),
            'direct_token_available' => !empty($status['direct_token_available']),
            'external_filter_available' => !empty($status['external_filter_available']),
            'tiktok_live_ready' => $tiktok_ready,
            'planned_only_sources' => array_values(array_unique($planned_sources)),
            'live_sources' => array_values(array_unique($live_sources)),
            'blocking_reasons' => array_values(array_unique($blocking)),
            'warnings' => (array) ($status['warnings'] ?? []),
            'openai_runtime' => $ai_runtime,
        ];
    }

    public static function tiktok_live_ready(array $channels = ['tiktok']): bool {
        $preflight = self::live_preflight_status($channels);
        return !empty($preflight['tiktok_live_ready']);
    }

    public static function ai_planner_bridge_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_ai_planner_bridge']);
    }

    public static function planner_model_alias(): string {
        $settings = self::get();
        return sanitize_key((string) ($settings['planner_model_alias'] ?? ''));
    }

    public static function ai_research_bridge_enabled(): bool {
        $settings = self::get();
        return self::live_dispatch_enabled() && !empty($settings['enable_ai_research_bridge']);
    }

    public static function credit_reservation_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_credit_reservation']);
    }

    public static function debug_enabled(): bool {
        $settings = self::get();
        return !empty($settings['debug_mode']);
    }

    public static function deep_video_enabled(): bool {
        $settings = self::get();
        return !empty($settings['enable_deep_video']) && defined('FI_DTF_ENABLE_DEEP_VIDEO') && FI_DTF_ENABLE_DEEP_VIDEO;
    }
}

