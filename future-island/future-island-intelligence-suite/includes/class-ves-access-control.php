<?php
/**
 * VES_Access_Control — Subscription / module / feature / provider gating.
 *
 * v0.9.26.0 — Phase 1 keystone. Sits on top of the existing VES_Usage_Billing
 * plan structure (which already handles credit limits) and adds module,
 * feature, and provider access flags per plan.
 *
 * Storage model:
 *   wp_options: 'ves_plan_access_map'  (JSON-encoded array)
 *     {
 *       "free":    { "modules": {...}, "features": {...}, "providers": {...} },
 *       "starter": { ... },
 *       "pro":     { ... },
 *       ...
 *     }
 *
 *   wp_options: 'ves_locked_messages' (JSON-encoded array)
 *     {
 *       "default": "Disponible en planes superiores...",
 *       "<module_key>": "Módulo X requiere plan Pro..."
 *     }
 *
 *   user_meta:  'ves_access_override' (per-user overrides)
 *
 * State values for each module / feature / provider:
 *   - enabled                  → user can use it
 *   - disabled_hidden          → user does not see it
 *   - disabled_locked_visible  → user sees lock + upgrade CTA
 *   - admin_only               → only manage_options users see/use
 *   - coming_soon              → visible, disabled, "coming soon" label
 *
 * Server-side enforcement: AJAX endpoints can call
 *   VES_Access_Control::enforce_module_or_die($user_id, $module_key)
 * which returns a clean structured error if blocked, NEVER charging credits.
 */

if (!defined('ABSPATH')) { exit; }

final class VES_Access_Control {

    const OPT_PLAN_ACCESS    = 'ves_plan_access_map';
    const OPT_LOCKED_MSGS    = 'ves_locked_messages';
    const OPT_AUDIT_LOG      = 'ves_access_audit_log';
    const OPT_AUDIT_LIMIT    = 50;
    const USER_META_OVERRIDE = 'ves_access_override';

    // Canonical module keys. Must match data-page attributes in shortcode.php.
    const MODULES = [
        'social', 'linkedin', 'seo', 'trend', 'brand-audit', 'creative',
        'google', 'ads', 'memory', 'knowledge', 'usage', 'brief-library',
    ];

    // Canonical feature keys (cross-module capabilities).
    const FEATURES = [
        'ai_analysis', 'ai_batch_analysis', 'export_json', 'export_csv',
        'saved_reports', 'workspace_memory', 'advanced_filters',
        'provider_diagnostics', 'admin_debug_mode', 'brief_generation',
        'creative_generation', 'image_video_analysis', 'compare_reports',
    ];

    // Canonical provider keys. Must match adapter slugs.
    const PROVIDERS = [
        'tiktok', 'instagram', 'youtube', 'reddit', 'twitter', 'facebook',
        'pinterest', 'linkedin', 'google_search', 'google_trends',
        'google_news', 'google_ads', 'meta_ads', 'semrush', 'moz', 'ahrefs',
    ];


    public static function normalize_module_key($key) {
        $raw = strtolower(trim((string) $key));
        $raw = str_replace(['/', ' '], ['_', '_'], $raw);
        $raw = sanitize_key($raw);
        $map = [
            'social_media' => 'social',
            'seo_sem_aeo' => 'seo',
            'seo_sem' => 'seo',
            'trend_finder' => 'trend',
            'brand_deep_audit' => 'brand-audit',
            'brand_audit' => 'brand-audit',
            'creative_intelligence' => 'creative',
            'google_intelligence' => 'google',
            'ads_intelligence' => 'ads',
            'brief_library' => 'brief-library',
        ];
        return $map[$raw] ?? $raw;
    }

    public static function normalize_feature_key($key) {
        return sanitize_key((string) $key);
    }

    public static function normalize_provider_key($key) {
        $raw = strtolower(trim((string) $key));
        $raw = str_replace(['/', ' '], ['_', '_'], $raw);
        $raw = sanitize_key($raw);
        $map = [
            'x' => 'twitter',
            'twitter_x' => 'twitter',
            'xtwitter' => 'twitter',
            'twitterx' => 'twitter',
            'meta' => 'meta_ads',
            'facebook_ads' => 'meta_ads',
            'meta_ads_library' => 'meta_ads',
            'googleads' => 'google_ads',
            'google_ad' => 'google_ads',
            'google_trend' => 'google_trends',
            'google_searches' => 'google_search',
        ];
        return $map[$raw] ?? $raw;
    }

    private static function canonical_key_for_section($section, $key) {
        $section = sanitize_key((string) $section);
        if ($section === 'modules') {
            return self::normalize_module_key($key);
        }
        if ($section === 'providers') {
            return self::normalize_provider_key($key);
        }
        if ($section === 'features') {
            return self::normalize_feature_key($key);
        }
        return sanitize_key((string) $key);
    }

    const STATE_ENABLED       = 'enabled';
    const STATE_HIDDEN        = 'disabled_hidden';
    const STATE_LOCKED        = 'disabled_locked_visible';
    const STATE_ADMIN_ONLY    = 'admin_only';
    const STATE_COMING_SOON   = 'coming_soon';

    /**
     * Default module access per plan. Liberal-by-default: existing installs
     * should not lose access on upgrade. Admin can tighten via settings page.
     */
    private static function default_access_map() {
        $all_enabled = [];
        foreach (self::MODULES as $m)   { $all_enabled['modules'][$m]   = self::STATE_ENABLED; }
        foreach (self::FEATURES as $f)  { $all_enabled['features'][$f]  = self::STATE_ENABLED; }
        foreach (self::PROVIDERS as $p) { $all_enabled['providers'][$p] = self::STATE_ENABLED; }

        // Free plan: locked Brand Audit + Ads + advanced features, hidden admin debug.
        $free = $all_enabled;
        $free['modules']['brand-audit']             = self::STATE_LOCKED;
        $free['modules']['ads']                     = self::STATE_LOCKED;
        $free['modules']['creative']                = self::STATE_LOCKED;
        $free['features']['ai_batch_analysis']      = self::STATE_LOCKED;
        $free['features']['brief_generation']       = self::STATE_LOCKED;
        $free['features']['advanced_filters']       = self::STATE_LOCKED;
        $free['features']['provider_diagnostics']   = self::STATE_HIDDEN;
        $free['features']['admin_debug_mode']       = self::STATE_HIDDEN;

        // Public lead-gen tier: non-admins on the free plan may use ONLY the Social
        // module and ONLY the TikTok / YouTube / Instagram scrapers. Everything else
        // is locked (visible → drives the demo funnel) or, for other providers,
        // hidden so the social scraper cleanly offers just the three platforms.
        // Admins (manage_options) bypass all of this and remain unrestricted.
        foreach (['linkedin', 'seo', 'trend', 'google', 'memory', 'knowledge', 'brief-library'] as $m) {
            if (isset($free['modules'][$m])) { $free['modules'][$m] = self::STATE_LOCKED; }
        }
        $free['modules']['social'] = self::STATE_ENABLED; // the allowed workspace
        $free['modules']['usage']  = self::STATE_ENABLED; // so they can see their credits
        $free_allowed_providers = ['tiktok', 'youtube', 'instagram'];
        foreach (self::PROVIDERS as $p) {
            // Allowed three are usable; the rest are shown LOCKED (name + premium
            // badge) rather than hidden, so non-subscribers see what unlocks on upgrade.
            $free['providers'][$p] = in_array($p, $free_allowed_providers, true) ? self::STATE_ENABLED : self::STATE_LOCKED;
        }

        // Starter: locks creative generation and image/video deep analysis.
        $starter = $all_enabled;
        $starter['modules']['brand-audit']           = self::STATE_LOCKED;
        $starter['features']['creative_generation']  = self::STATE_LOCKED;
        $starter['features']['image_video_analysis'] = self::STATE_LOCKED;
        $starter['features']['admin_debug_mode']     = self::STATE_HIDDEN;

        // Pro: everything except admin debug.
        $pro = $all_enabled;
        $pro['features']['admin_debug_mode'] = self::STATE_ADMIN_ONLY;

        // Scale: everything; admin debug stays admin-only.
        $scale = $all_enabled;
        $scale['features']['admin_debug_mode'] = self::STATE_ADMIN_ONLY;

        return apply_filters('ves_default_access_map', [
            'free'    => $free,
            'starter' => $starter,
            'pro'     => $pro,
            'scale'   => $scale,
        ]);
    }

    public static function default_locked_messages() {
        return apply_filters('ves_default_locked_messages', [
            'default'      => 'Esta función está disponible en planes superiores. Actualiza tu plan para desbloquearla.',
            'brand-audit'  => 'Brand Deep Audit está disponible en planes Starter y superiores.',
            'creative'     => 'Creative Intelligence está disponible en plan Pro y superiores.',
            'ads'          => 'Ads Intelligence requiere un plan con acceso a Ads.',
            'trend'        => 'Trend Finder con fuentes avanzadas requiere un plan superior.',
            'ai_batch_analysis' => 'El análisis comparativo multi-ítem está disponible en planes superiores.',
            'brief_generation'  => 'La generación de briefs está disponible en planes superiores.',
            '_cta_text'         => 'Ver planes',
            '_cta_url'          => '#pricing',
        ]);
    }

    public static function get_access_map() {
        $stored = get_option(self::OPT_PLAN_ACCESS, null);
        if (!is_array($stored) || empty($stored)) {
            return self::default_access_map();
        }
        // Merge stored over defaults so newly added keys still apply, while preserving
        // limits, policies and future-safe custom sections from the option store.
        $defaults = self::default_access_map();
        $out = [];
        foreach ($defaults as $plan_slug => $sections) {
            $stored_sections = (isset($stored[$plan_slug]) && is_array($stored[$plan_slug])) ? $stored[$plan_slug] : [];
            $out[$plan_slug] = array_merge($sections, array_diff_key($stored_sections, array_flip(['modules','features','providers'])));
            foreach (['modules', 'features', 'providers'] as $k) {
                $out[$plan_slug][$k] = $sections[$k] ?? [];
                if (isset($stored_sections[$k]) && is_array($stored_sections[$k])) {
                    $out[$plan_slug][$k] = array_merge($out[$plan_slug][$k], $stored_sections[$k]);
                }
            }
        }
        // Stored-only plans (custom) pass through, including their limits/policies.
        foreach ($stored as $plan_slug => $sections) {
            if (!isset($out[$plan_slug]) && is_array($sections)) {
                $out[$plan_slug] = $sections;
            }
        }
        return apply_filters('ves_plan_access_map', $out);
    }

    public static function save_access_map($map) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('no_cap', 'Insufficient capability.');
        }
        if (!is_array($map)) { return new WP_Error('invalid', 'Invalid map.'); }
        $prev = get_option(self::OPT_PLAN_ACCESS, []);
        $prev = is_array($prev) ? $prev : [];
        // Start from the previous option so saving Plans & Access never deletes
        // Credits & Limits (limits), billing policies, or future-safe custom sections.
        $clean = $prev;
        $allowed_states = [self::STATE_ENABLED, self::STATE_HIDDEN, self::STATE_LOCKED, self::STATE_ADMIN_ONLY, self::STATE_COMING_SOON];
        foreach ($map as $plan_slug => $sections) {
            $plan_slug = sanitize_key((string) $plan_slug);
            if ($plan_slug === '' || !is_array($sections)) { continue; }
            if (!isset($clean[$plan_slug]) || !is_array($clean[$plan_slug])) { $clean[$plan_slug] = []; }
            foreach (['modules', 'features', 'providers'] as $section_key) {
                $clean[$plan_slug][$section_key] = [];
                if (!isset($sections[$section_key]) || !is_array($sections[$section_key])) { continue; }
                foreach ($sections[$section_key] as $item_key => $state) {
                    $item_key = self::canonical_key_for_section($section_key, $item_key);
                    $state = sanitize_key((string) $state);
                    if ($item_key === '' || !in_array($state, $allowed_states, true)) { continue; }
                    $clean[$plan_slug][$section_key][$item_key] = $state;
                }
            }
        }
        update_option(self::OPT_PLAN_ACCESS, $clean, false);
        // v0.9.27.0 — Phase 5: full previous/new diff in audit log instead of
        // just a list of changed plan slugs.
        $diff = [];
        $plan_slugs = array_unique(array_merge(array_keys(is_array($prev) ? $prev : []), array_keys($clean)));
        foreach ($plan_slugs as $slug) {
            $before = isset($prev[$slug]) ? $prev[$slug] : [];
            $after  = isset($clean[$slug]) ? $clean[$slug] : [];
            if ($before !== $after) {
                $diff[$slug] = ['previous' => $before, 'new' => $after];
            }
        }
        self::record_audit('access_map_saved', [
            'diff'          => $diff,
            'plans_touched' => array_keys($diff),
            'editor'        => get_current_user_id(),
            'preserved_sections' => ['limits','policies','custom'],
        ]);
        return true;
    }

    public static function get_locked_messages() {
        $stored = get_option(self::OPT_LOCKED_MSGS, null);
        if (!is_array($stored) || empty($stored)) {
            return self::default_locked_messages();
        }
        return array_merge(self::default_locked_messages(), $stored);
    }

    public static function save_locked_messages($msgs) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('no_cap', 'Insufficient capability.');
        }
        if (!is_array($msgs)) { return new WP_Error('invalid', 'Invalid messages.'); }
        $clean = [];
        foreach ($msgs as $k => $v) {
            $k = is_string($k) ? sanitize_key($k) : '';
            $v = is_string($v) ? wp_kses_post($v) : '';
            // The _cta_url key allows URL-only sanitization.
            if ($k === '_cta_url') { $v = esc_url_raw($v); }
            if ($k !== '' && $v !== '') { $clean[$k] = $v; }
        }
        update_option(self::OPT_LOCKED_MSGS, $clean, false);
        return true;
    }

    public static function current_user_plan_slug() {
        if (!is_user_logged_in()) { return 'free'; }
        if (class_exists('VES_Usage_Billing')) {
            return (string) VES_Usage_Billing::get_user_plan_slug(get_current_user_id());
        }
        return 'free';
    }

    public static function user_plan_slug($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return 'free'; }
        if (class_exists('VES_Usage_Billing')) {
            return (string) VES_Usage_Billing::get_user_plan_slug($user_id);
        }
        return 'free';
    }

    /**
     * Per-user overrides allow admin to grant/revoke individual access without
     * touching the plan-wide config. Override format:
     *   { "modules": {"ads": "enabled"}, "features": {"brief_generation": "enabled"} }
     */
    public static function get_user_override($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return []; }
        $raw = get_user_meta($user_id, self::USER_META_OVERRIDE, true);
        if (is_array($raw)) { return $raw; }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public static function set_user_override($user_id, $override, $reason = '') {
        if (!current_user_can('manage_options')) {
            return new WP_Error('no_cap', 'Insufficient capability.');
        }
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return new WP_Error('invalid_user', 'Invalid user.'); }

        // v0.9.27.0 — Phase 5: strict allowlist validation. Reject unknown sections,
        // unknown keys, and unknown states. Previously we accepted any structured
        // array, which let admins typo their way into nonsense states like
        // {"modules":{"adz":"on"}} that resolve_state() would silently treat as
        // "enabled" by default.
        $validation_errors = [];
        $clean = [];
        if (!is_array($override)) {
            return new WP_Error('invalid', 'Override must be an array.');
        }
        $allowed_sections = ['modules' => self::MODULES, 'features' => self::FEATURES, 'providers' => self::PROVIDERS];
        $allowed_states   = [self::STATE_ENABLED, self::STATE_HIDDEN, self::STATE_LOCKED, self::STATE_ADMIN_ONLY, self::STATE_COMING_SOON];
        // v0.9.28.1 — Bug #1 fix. The v0.9.28.0 admin docs and get_plan_limit()
        // claim per-user overrides accept `limits` and `policies` sections, but
        // the validator rejected them as unknown. Add allowlists for those two
        // sections so per-user credit/policy overrides actually save.
        $allowed_limit_keys = [
            'monthly_credits','signup_credits','max_credits_per_run',
            'max_daily_runs','max_monthly_runs','max_concurrent_runs',
            'max_ai_analyses_per_day','max_ai_analyses_per_month',
            'max_selected_items_per_analysis','max_provider_cost_per_run_usd',
            'default_internal_provider_request_count','max_internal_provider_request_count',
            'base_scan_fee','per_usable_result_fee','ai_analysis_fee',
            'batch_analysis_fee','creative_analysis_fee',
        ];
        $allowed_policy_keys = [
            'charge_on_success_empty','charge_on_all_filtered',
            'refund_failed_provider','failed_run_policy',
        ];
        $allowed_policy_values_per_key = [
            'charge_on_success_empty' => ['yes','no'],
            'charge_on_all_filtered'  => ['yes','no'],
            'refund_failed_provider'  => ['yes','no'],
            'failed_run_policy'       => ['refund_full','keep_base_fee','keep_all'],
        ];
        foreach ($override as $section_key => $section_val) {
            // expires_at is a special top-level key.
            if ($section_key === 'expires_at') {
                $ts = is_string($section_val) ? strtotime($section_val) : (int) $section_val;
                if ($ts > 0) {
                    $clean['expires_at'] = (int) $ts;
                } else {
                    $validation_errors[] = 'expires_at is not a valid date/timestamp.';
                }
                continue;
            }
            $section_key = sanitize_key((string) $section_key);
            // v0.9.28.1 — handle limits section: numeric values, allowlist of known keys.
            if ($section_key === 'limits') {
                if (!is_array($section_val)) {
                    $validation_errors[] = "Section 'limits' must be an object/array.";
                    continue;
                }
                foreach ($section_val as $k => $v) {
                    $k = sanitize_key((string) $k);
                    if (!in_array($k, $allowed_limit_keys, true)) {
                        $validation_errors[] = "Unknown limits key: '{$k}'. Allowed: " . implode(', ', $allowed_limit_keys);
                        continue;
                    }
                    if (!is_numeric($v)) {
                        $validation_errors[] = "limits.{$k} must be numeric, got: " . gettype($v);
                        continue;
                    }
                    $num = (float) $v;
                    if ($num < 0) {
                        $validation_errors[] = "limits.{$k} cannot be negative.";
                        continue;
                    }
                    // Float for the cost cap, int for everything else.
                    $clean['limits'][$k] = ($k === 'max_provider_cost_per_run_usd')
                        ? round($num, 2)
                        : (int) $num;
                }
                continue;
            }
            // v0.9.28.1 — handle policies section: enum allowlist per key.
            if ($section_key === 'policies') {
                if (!is_array($section_val)) {
                    $validation_errors[] = "Section 'policies' must be an object/array.";
                    continue;
                }
                foreach ($section_val as $k => $v) {
                    $k = sanitize_key((string) $k);
                    $v = sanitize_key((string) $v);
                    if (!in_array($k, $allowed_policy_keys, true)) {
                        $validation_errors[] = "Unknown policies key: '{$k}'. Allowed: " . implode(', ', $allowed_policy_keys);
                        continue;
                    }
                    $allowed_vals = $allowed_policy_values_per_key[$k] ?? [];
                    if (!in_array($v, $allowed_vals, true)) {
                        $validation_errors[] = "Unknown value for policies.{$k}: '{$v}'. Allowed: " . implode(', ', $allowed_vals);
                        continue;
                    }
                    $clean['policies'][$k] = $v;
                }
                continue;
            }
            if (!isset($allowed_sections[$section_key])) {
                $validation_errors[] = "Unknown section: '{$section_key}'. Allowed: modules, features, providers, limits, policies, expires_at.";
                continue;
            }
            if (!is_array($section_val)) {
                $validation_errors[] = "Section '{$section_key}' must be an object/array.";
                continue;
            }
            foreach ($section_val as $item_key => $state) {
                $item_key = self::canonical_key_for_section($section_key, $item_key);
                $state    = sanitize_key((string) $state);
                if (!in_array($item_key, $allowed_sections[$section_key], true)) {
                    $validation_errors[] = "Unknown {$section_key} key: '{$item_key}'.";
                    continue;
                }
                if (!in_array($state, $allowed_states, true)) {
                    $validation_errors[] = "Unknown state '{$state}' for {$section_key}.{$item_key}. Allowed: " . implode(', ', $allowed_states);
                    continue;
                }
                $clean[$section_key][$item_key] = $state;
            }
        }
        if (!empty($validation_errors)) {
            return new WP_Error('validation_errors', implode(' ', $validation_errors), ['errors' => $validation_errors]);
        }

        // Capture previous value for audit log "previous → new" diff.
        $previous = self::get_user_override($user_id);
        update_user_meta($user_id, self::USER_META_OVERRIDE, $clean);
        self::record_audit('user_override', [
            'target_user' => $user_id,
            'previous'    => $previous,
            'new'         => $clean,
            'reason'      => sanitize_text_field((string) $reason),
            'editor'      => get_current_user_id(),
        ]);
        return true;
    }

    /**
     * v0.9.27.0 — Phase 5: respect override expiry. If expires_at is set and
     * already past, the override is treated as expired and ignored.
     */
    private static function override_is_active($override) {
        if (!is_array($override) || empty($override)) { return false; }
        if (!isset($override['expires_at'])) { return true; }
        $exp = (int) $override['expires_at'];
        return $exp <= 0 || $exp > time();
    }

    /**
     * Compute the effective state for a given key.
     * Order of resolution: per-user override → plan map → default (enabled).
     */
    public static function resolve_state($user_id, $section, $key) {
        $user_id = (int) $user_id;
        $section = sanitize_key((string) $section);
        $key     = self::canonical_key_for_section($section, $key);
        if ($section === '' || $key === '') { return self::STATE_ENABLED; }

        // 1. Per-user override wins (if not expired).
        $override = self::get_user_override($user_id);
        if (self::override_is_active($override) && isset($override[$section][$key])) {
            $state = sanitize_key((string) $override[$section][$key]);
            if ($state !== '') { return $state; }
        }

        // 2. Plan map.
        $plan_slug = self::user_plan_slug($user_id);
        $map = self::get_access_map();
        if (isset($map[$plan_slug][$section][$key])) {
            return sanitize_key((string) $map[$plan_slug][$section][$key]);
        }

        // 3. Default: enabled.
        return self::STATE_ENABLED;
    }

    public static function user_can_access_module($user_id, $module_key) {
        $state = self::resolve_state($user_id, 'modules', $module_key);
        if ($state === self::STATE_ADMIN_ONLY) {
            return user_can($user_id, 'manage_options');
        }
        return $state === self::STATE_ENABLED;
    }

    public static function user_can_access_feature($user_id, $feature_key) {
        $state = self::resolve_state($user_id, 'features', $feature_key);
        if ($state === self::STATE_ADMIN_ONLY) {
            return user_can($user_id, 'manage_options');
        }
        return $state === self::STATE_ENABLED;
    }

    public static function user_can_access_provider($user_id, $provider_key) {
        $state = self::resolve_state($user_id, 'providers', $provider_key);
        if ($state === self::STATE_ADMIN_ONLY) {
            return user_can($user_id, 'manage_options');
        }
        return $state === self::STATE_ENABLED;
    }

    public static function get_locked_message($key) {
        $msgs = self::get_locked_messages();
        $key = sanitize_key((string) $key);
        if ($key !== '' && isset($msgs[$key])) { return $msgs[$key]; }
        return $msgs['default'] ?? 'Función no disponible.';
    }

    /**
     * Build the access map that gets localized to the frontend. Strips admin-only
     * concerns and only exposes what's relevant to the current user's UI state.
     */
    public static function build_frontend_access_map($user_id) {
        $user_id = (int) $user_id;
        $modules   = [];
        $features  = [];
        $providers = [];
        foreach (self::MODULES as $m) {
            $state = self::resolve_state($user_id, 'modules', $m);
            // Admin-only collapses to hidden for non-admins.
            if ($state === self::STATE_ADMIN_ONLY && !user_can($user_id, 'manage_options')) {
                $state = self::STATE_HIDDEN;
            }
            $modules[$m] = $state;
        }
        foreach (self::FEATURES as $f) {
            $state = self::resolve_state($user_id, 'features', $f);
            if ($state === self::STATE_ADMIN_ONLY && !user_can($user_id, 'manage_options')) {
                $state = self::STATE_HIDDEN;
            }
            $features[$f] = $state;
        }
        foreach (self::PROVIDERS as $p) {
            $state = self::resolve_state($user_id, 'providers', $p);
            if ($state === self::STATE_ADMIN_ONLY && !user_can($user_id, 'manage_options')) {
                $state = self::STATE_HIDDEN;
            }
            $providers[$p] = $state;
        }
        foreach ([
            'social_media' => 'social',
            'seo_sem_aeo' => 'seo',
            'trend_finder' => 'trend',
            'brand_deep_audit' => 'brand-audit',
            'creative_intelligence' => 'creative',
            'google_intelligence' => 'google',
            'ads_intelligence' => 'ads',
            'brief_library' => 'brief-library',
        ] as $alias => $canonical) {
            if (isset($modules[$canonical])) { $modules[$alias] = $modules[$canonical]; }
        }
        foreach (['x' => 'twitter', 'twitter_x' => 'twitter', 'twitter/x' => 'twitter', 'facebook_ads' => 'meta_ads'] as $alias => $canonical) {
            if (isset($providers[$canonical])) { $providers[$alias] = $providers[$canonical]; }
        }
        $msgs = self::get_locked_messages();
        return [
            'modules'        => $modules,
            'features'       => $features,
            'providers'      => $providers,
            'lockedMessages' => $msgs,
            'planSlug'       => self::user_plan_slug($user_id),
            'isAdmin'        => user_can($user_id, 'manage_options'),
        ];
    }

    /**
     * Standard JSON error structure for blocked AJAX runs.
     * NEVER charges credits — the caller's job is to make sure they didn't
     * reserve credits before calling this.
     */
    public static function locked_error_payload($key, $section = 'modules') {
        $section = sanitize_key((string) $section);
        $key = self::canonical_key_for_section($section, $key);
        $msg = self::get_locked_message($key);
        $msgs = self::get_locked_messages();
        return [
            'success'         => false,
            'code'            => 'feature_locked',
            'title'           => 'Función no disponible en tu plan',
            'message'         => $msg,
            'upgrade_url'     => $msgs['_cta_url']  ?? '#pricing',
            'cta_text'        => $msgs['_cta_text'] ?? 'Ver planes',
            'charge'          => 0,
            'credits_refunded'=> true,
            'section'         => $section,
            'key'             => $key,
        ];
    }

    /**
     * Convenience used inside AJAX handlers. Returns true if allowed; otherwise
     * sends the JSON error and exits.
     *
     * Usage in an AJAX handler:
     *   VES_Access_Control::enforce_module_or_die(get_current_user_id(), 'ads');
     */
    public static function enforce_module_or_die($user_id, $module_key) {
        if (self::user_can_access_module($user_id, $module_key)) { return true; }
        wp_send_json(self::locked_error_payload($module_key, 'modules'), 403);
        exit;
    }

    public static function enforce_feature_or_die($user_id, $feature_key) {
        if (self::user_can_access_feature($user_id, $feature_key)) { return true; }
        wp_send_json(self::locked_error_payload($feature_key, 'features'), 403);
        exit;
    }

    public static function enforce_provider_or_die($user_id, $provider_key) {
        if (self::user_can_access_provider($user_id, $provider_key)) { return true; }
        wp_send_json(self::locked_error_payload($provider_key, 'providers'), 403);
        exit;
    }

    private static function record_audit($event, $payload) {
        $log = get_option(self::OPT_AUDIT_LOG, []);
        if (!is_array($log)) { $log = []; }
        $log[] = [
            'event'     => sanitize_key((string) $event),
            'payload'   => $payload,
            'timestamp' => time(),
            'user_id'   => get_current_user_id(),
        ];
        $log = array_slice($log, -self::OPT_AUDIT_LIMIT);
        update_option(self::OPT_AUDIT_LOG, $log, false);
    }

    public static function get_audit_log() {
        $log = get_option(self::OPT_AUDIT_LOG, []);
        return is_array($log) ? $log : [];
    }

    /**
     * v0.9.28.0 — Phase 6: Plan limit reader. Resolution order:
     *   per-user override → plan-stored limit → VES_Usage_Billing plan default → null
     *
     * v0.9.28.1 — Bug #3 fix. Cast the return value on read based on key type.
     * The previous version returned whatever was in storage (often strings from
     * the option store), which made `if ($limit < 50)` rely on PHP type juggling.
     * Returns int by default, float for monetary fields, null when unset.
     */
    public static function get_plan_limit($user_id, $limit_key) {
        $user_id   = (int) $user_id;
        $limit_key = sanitize_key((string) $limit_key);
        if ($limit_key === '') { return null; }

        $raw = null;

        // 1. Per-user override (limits.<key>)
        $override = self::get_user_override($user_id);
        if (self::override_is_active($override) && isset($override['limits'][$limit_key])) {
            $raw = $override['limits'][$limit_key];
        }

        // 2. Plan-stored limit from access map.
        $plan_slug = self::user_plan_slug($user_id);
        if ($raw === null) {
            $stored = get_option(self::OPT_PLAN_ACCESS, []);
            if (is_array($stored) && isset($stored[$plan_slug]['limits'][$limit_key])) {
                $raw = $stored[$plan_slug]['limits'][$limit_key];
            }
        }

        // 3. VES_Usage_Billing plan defaults.
        if ($raw === null && class_exists('VES_Usage_Billing')) {
            $plans = VES_Usage_Billing::get_available_plans();
            if (isset($plans[$plan_slug][$limit_key])) {
                $raw = $plans[$plan_slug][$limit_key];
            }
        }

        if ($raw === null) { return null; }
        if (!is_numeric($raw)) { return null; }
        $float_keys = ['max_provider_cost_per_run_usd','base_scan_fee','per_usable_result_fee','ai_analysis_fee'];
        return in_array($limit_key, $float_keys, true) ? (float) $raw : (int) $raw;
    }

    /**
     * v0.9.28.0 — Phase 6: Plan policy reader (charge/refund/failed-run behaviors).
     */
    public static function get_plan_policy($user_id, $policy_key) {
        $user_id    = (int) $user_id;
        $policy_key = sanitize_key((string) $policy_key);
        if ($policy_key === '') { return null; }

        $override = self::get_user_override($user_id);
        if (self::override_is_active($override) && isset($override['policies'][$policy_key])) {
            return $override['policies'][$policy_key];
        }

        $plan_slug = self::user_plan_slug($user_id);
        $stored    = get_option(self::OPT_PLAN_ACCESS, []);
        if (is_array($stored) && isset($stored[$plan_slug]['policies'][$policy_key])) {
            return $stored[$plan_slug]['policies'][$policy_key];
        }

        return null;
    }
}
