<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Apify_Client {
    private static function scrub_message($value) {
        $value = (string) $value;
        $value = preg_replace('/apify_api_[A-Za-z0-9]+/i', '[token]', $value);
        $value = preg_replace('/sk-[A-Za-z0-9_\-]+/i', '[token]', $value);
        $value = preg_replace('/[A-Za-z0-9_\-]{32,}/', '[token]', $value);
        $value = preg_replace('#https?://[^\s]+#i', '[url]', $value);
        return trim($value);
    }

    private static function record_diagnostic_safe($source, $message, $context = []) {
        if (!class_exists('VES_Admin')) { return; }
        try {
            VES_Admin::record_diagnostic($source, $message, is_array($context) ? $context : []);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Vietnam Estudio Social] provider diagnostic write failed: ' . $e->getMessage());
            }
        }
    }

    private static function should_retry($code) {
        return $code === 429 || ($code >= 500 && $code <= 599);
    }


    private static function slots_option_key() { return 'ves_apify_active_slots'; }
    private static function slots_lock_key() { return 'ves_apify_active_slots_lock'; }

    private static function acquire_slots_lock() {
        // v0.9.24.6: use set_transient (cache-backed, more atomic than add_option on Redis/Memcached).
        $token = md5((string) microtime(true) . '|' . wp_rand());
        $key = self::slots_lock_key();
        for ($i = 0; $i < 5; $i++) {
            // Try to acquire: set_transient won't overwrite an existing key.
            if (!get_transient($key)) {
                set_transient($key, ['token' => $token, 'expires' => time() + 15], 15);
                // Verify we actually own it.
                $stored = get_transient($key);
                if (is_array($stored) && hash_equals((string) ($stored['token'] ?? ''), $token)) {
                    return $token;
                }
            }
            usleep(50000);
        }
        return false;
    }

    private static function release_slots_lock($token) {
        if (!$token) { return; }
        $lock = get_transient(self::slots_lock_key());
        if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), (string) $token)) { delete_transient(self::slots_lock_key()); }
    }

    private static function actor_is_heavy($actor_slug) {
        return (bool) preg_match('/(tiktok|instagram|youtube|facebook|tweet|twitter|trend)/i', (string) $actor_slug);
    }

    private static function load_dispatch_slots() {
        $slots = get_option(self::slots_option_key(), []);
        $slots = is_array($slots) ? $slots : [];
        $now = time();
        foreach ($slots as $key => $slot) {
            $ts = (int) ($slot['ts'] ?? 0);
            if ($ts <= 0 || ($now - $ts) > 20 * MINUTE_IN_SECONDS) { unset($slots[$key]); }
        }
        return $slots;
    }

    private static function save_dispatch_slots($slots) {
        update_option(self::slots_option_key(), is_array($slots) ? $slots : [], false);
    }

    public static function clear_dispatch_slots() {
        $slots = get_option(self::slots_option_key(), []);
        $count = is_array($slots) ? count($slots) : 0;
        self::save_dispatch_slots([]);
        delete_transient(self::slots_lock_key());
        return $count;
    }

    public static function cleanup_stale_dispatch_slots() {
        $before = get_option(self::slots_option_key(), []);
        $before_count = is_array($before) ? count($before) : 0;
        $after = self::load_dispatch_slots();
        self::save_dispatch_slots($after);
        return max(0, $before_count - count($after));
    }

    public static function dispatch_slot_diagnostics($cleanup = false) {
        $removed = $cleanup ? self::cleanup_stale_dispatch_slots() : 0;
        $slots = self::load_dispatch_slots();
        $heavy = 0; $by_user = [];
        foreach ($slots as $slot) {
            if (!empty($slot['heavy'])) { $heavy++; }
            $uid = (string) ((int) ($slot['user_id'] ?? 0));
            $by_user[$uid] = (int) ($by_user[$uid] ?? 0) + 1;
        }
        return [
            'active_slots' => count($slots),
            'heavy_slots' => $heavy,
            'by_user' => $by_user,
            'stale_removed' => $removed,
            'limits' => [
                'max_total' => class_exists('VES_Config') ? VES_Config::apify_max_active_runs() : 6,
                'max_heavy' => class_exists('VES_Config') ? VES_Config::apify_max_heavy_runs() : 2,
                'max_per_user' => class_exists('VES_Config') ? VES_Config::apify_max_active_runs_per_user() : 2,
            ],
            'retry_states' => ['WAITING_RETRY', 'QUEUED_RETRY_MEMORY_LIMIT'],
            'provider_cost_guard' => class_exists('VES_Config') ? [
                'max_external_provider_cost_per_run' => VES_Config::max_external_provider_cost_per_run(),
                'apify_cost_warning_threshold_usd' => VES_Config::apify_cost_warning_threshold_usd(),
                'enable_youtube_trending_in_trend_finder' => VES_Config::trend_youtube_trending_enabled(),
                'enable_twitter_trends_in_trend_finder' => VES_Config::trend_twitter_trends_enabled(),
                'max_twitter_trend_items' => VES_Config::trend_max_twitter_trend_items(),
                'max_youtube_trending_cost' => VES_Config::trend_max_youtube_trending_cost(),
                'internal_credits_separate' => true,
            ] : [],
            'slots' => array_values($slots),
        ];
    }

    public static function acquire_dispatch_slot($actor_slug, $user_id = 0, $context = []) {
        $actor_slug = sanitize_text_field((string) $actor_slug);
        $user_id = (int) $user_id;
        $token = self::acquire_slots_lock();
        if (!$token) {
            return new WP_Error('apify_concurrency_lock_busy', 'Apify dispatch delayed: slot lock is busy.', ['provider_state' => 'WAITING_RETRY', 'retry_after' => 30]);
        }
        try {
            self::cleanup_stale_dispatch_slots();
            $slots = self::load_dispatch_slots();
            $total = count($slots);
            $user_total = 0;
            $heavy_total = 0;
            foreach ($slots as $slot) {
                if ($user_id > 0 && (int) ($slot['user_id'] ?? 0) === $user_id) { $user_total++; }
                if (!empty($slot['heavy'])) { $heavy_total++; }
            }
            $heavy = self::actor_is_heavy($actor_slug);
            $plan_hash = '';
            if (is_array($context) && !empty($context['dispatch_plan_hash'])) {
                $plan_hash = sanitize_text_field((string) $context['dispatch_plan_hash']);
            }
            if ($plan_hash !== '') {
                foreach ($slots as $slot) {
                    $slot_context = is_array($slot['context'] ?? null) ? $slot['context'] : [];
                    if ((int) ($slot['user_id'] ?? 0) === $user_id
                        && sanitize_text_field((string) ($slot['actor_slug'] ?? '')) === $actor_slug
                        && sanitize_text_field((string) ($slot_context['dispatch_plan_hash'] ?? '')) === $plan_hash) {
                        return new WP_Error('apify_duplicate_dispatch_plan', 'An identical dispatch plan is already running for this user and actor.', [
                            'provider_state' => 'DUPLICATE_DISPATCH_PLAN',
                            'retry_after' => 45,
                            'run_id' => sanitize_text_field((string) ($slot['run_id'] ?? '')),
                            'dispatch_plan_hash' => $plan_hash,
                        ]);
                    }
                }
            }
            if ($total >= VES_Config::apify_max_active_runs()) {
                return new WP_Error('apify_concurrency_limit', 'Apify dispatch delayed: global active-run limit reached.', ['provider_state' => 'WAITING_RETRY', 'retry_after' => 60, 'active_slots' => $total]);
            }
            if ($user_id > 0 && $user_total >= VES_Config::apify_max_active_runs_per_user()) {
                return new WP_Error('apify_concurrency_limit', 'Apify dispatch delayed: per-user active-run limit reached.', ['provider_state' => 'WAITING_RETRY', 'retry_after' => 60, 'active_slots' => $user_total]);
            }
            if ($heavy && $heavy_total >= VES_Config::apify_max_heavy_runs()) {
                return new WP_Error('apify_concurrency_limit', 'Apify dispatch delayed: heavy-source active-run limit reached.', ['provider_state' => 'WAITING_RETRY', 'retry_after' => 90, 'active_slots' => $heavy_total]);
            }
            $slot_id = 'slot_' . md5($actor_slug . '|' . $user_id . '|' . microtime(true) . '|' . wp_rand());
            $slots[$slot_id] = [
                'slot_id' => $slot_id,
                'actor_slug' => $actor_slug,
                'user_id' => $user_id,
                'heavy' => $heavy ? 1 : 0,
                'run_id' => '',
                'context' => is_array($context) ? array_slice($context, 0, 10, true) : [],
                'ts' => time(),
            ];
            self::save_dispatch_slots($slots);
            return $slot_id;
        } finally {
            self::release_slots_lock($token);
        }
    }

    public static function register_run_id_for_slot($slot_id, $run_id) {
        $slot_id = sanitize_text_field((string) $slot_id);
        $run_id = sanitize_text_field((string) $run_id);
        if ($slot_id === '' || $run_id === '') { return; }
        $slots = self::load_dispatch_slots();
        if (isset($slots[$slot_id])) {
            $slots[$slot_id]['run_id'] = $run_id;
            $slots[$slot_id]['ts'] = time();
            self::save_dispatch_slots($slots);
        }
    }

    public static function release_dispatch_slot($slot_id) {
        $slot_id = sanitize_text_field((string) $slot_id);
        if ($slot_id === '') { return; }
        $slots = self::load_dispatch_slots();
        unset($slots[$slot_id]);
        self::save_dispatch_slots($slots);
    }

    public static function release_dispatch_slot_for_run($run_id) {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') { return; }
        $slots = self::load_dispatch_slots();
        foreach ($slots as $key => $slot) {
            if (sanitize_text_field((string) ($slot['run_id'] ?? '')) === $run_id) { unset($slots[$key]); }
        }
        self::save_dispatch_slots($slots);
    }

    private static function classify_http_error($code, $raw, $json) {
        $message = strtolower((string) $raw . ' ' . (is_array($json) ? ($json['error']['message'] ?? $json['message'] ?? '') : ''));
        if (strpos($message, 'actor-memory-limit-exceeded') !== false || strpos($message, 'memory limit') !== false || strpos($message, 'exceed the memory') !== false) {
            return ['code' => 'apify_memory_limit', 'provider_state' => 'QUEUED_RETRY_MEMORY_LIMIT'];
        }
        if (strpos($message, 'rent a paid actor') !== false || strpos($message, 'free trial has expired') !== false || strpos($message, 'paid actor') !== false
            || strpos($message, 'requiere rental') !== false || strpos($message, 'requiere plan') !== false || strpos($message, 'rental/plan') !== false || strpos($message, 'rental plan') !== false || strpos($message, 'subscription required') !== false) {
            return ['code' => 'apify_actor_rental_required', 'provider_state' => 'CONFIGURATION_REQUIRED'];
        }
        if ((int) $code === 429) { return ['code' => 'apify_rate_limited', 'provider_state' => 'WAITING_RETRY']; }
        if ((int) $code >= 500) { return ['code' => 'apify_upstream_unavailable', 'provider_state' => 'WAITING_RETRY']; }
        // v0.9.31.1: a 400 means the actor rejected the input payload/schema. Mark it with a
        // distinct code + provider_state so the run pipeline classifies it as invalid input
        // (actionable by the user) rather than a generic, retry-looking HTTP error.
        if ((int) $code === 400) { return ['code' => 'apify_invalid_input', 'provider_state' => 'DISPATCH_INVALID_INPUT']; }
        return ['code' => 'ves_http_error', 'provider_state' => 'DISPATCH_ERROR'];
    }

    private static function http_timeout_seconds() {
        $timeout = 35;
        if (defined('VE_SOCIAL_APIFY_TIMEOUT')) {
            $timeout = (int) VE_SOCIAL_APIFY_TIMEOUT;
        }
        return max(10, min(55, (int) $timeout));
    }

    // Phase 9A.2 — hard charge-ceiling policy for paid actor dispatch.
    const MIN_CHARGE_CEILING_USD = 0.1;
    const MAX_CHARGE_CEILING_USD = 50.0;

    /**
     * Phase 9A.1 — explicit local-dev-only bypass of the dispatch gates. Default
     * OFF. Requires the scary constant/filter AND a local/dev siteurl; any attempt
     * to use it on a non-local site is refused and logged as a security event.
     */
    private static function unsafe_bypass_active() {
        $on = defined('VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY')
            && VES_ALLOW_UNSAFE_PROVIDER_DISPATCH_FOR_LOCAL_TESTS_ONLY;
        if (function_exists('apply_filters')) {
            $on = (bool) apply_filters('ves_allow_unsafe_provider_dispatch_for_local_tests_only', $on);
        }
        if (!$on) { return false; }
        $site = function_exists('get_option') ? strtolower((string) get_option('siteurl', '')) : '';
        $host = (string) (parse_url($site, PHP_URL_HOST) ?: '');
        $is_local = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || substr($host, -6) === '.local' || substr($host, -5) === '.test'
            || substr($host, -10) === '.localhost';
        if (!$is_local) {
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('unsafe_bypass_attempt', 'Unsafe provider dispatch bypass requested on a non-local site; refused.', ['host' => $host]);
            }
            return false;
        }
        return true;
    }

    /**
     * v0.1 RC + Phase 9A — hard dispatch gate, FAIL CLOSED. A run-start request
     * (POST …/v2/acts/{slug}/runs or run-sync, and the actor-tasks equivalent) is
     * refused before any HTTP when:
     *   - the allowlist registry is unavailable (9A.1: no registry, no dispatch),
     *   - the actor slug is not allowlisted,
     *   - the URL has no maxTotalChargeUsd ceiling and the actor is not explicitly
     *     registered as zero-cost (9A.2),
     *   - the ceiling is below the minimum floor or above the maximum allowed.
     * Read-style requests (fetch run/items/abort) are never gated. The only escape
     * hatch is the local-dev-only constant above (default false, non-local refused).
     * @return true|WP_Error
     */
    private static function enforce_run_dispatch_safety($method, $url) {
        if (strtoupper((string) $method) !== 'POST') { return true; }
        if (!preg_match('#https?://api\.apify\.com/v2/(acts|actor-tasks)/([^/?\#]+)/(runs|run-sync[^/?\#]*)#i', (string) $url, $m)) {
            return true;
        }
        $slug = (string) $m[2];
        if (self::unsafe_bypass_active()) {
            self::record_diagnostic_safe('apify_unsafe_bypass_used', 'Dispatch gates bypassed via local-tests-only constant (local site).', [
                'method' => 'POST', 'stage' => 'unsafe_local_bypass',
            ]);
            return true;
        }

        // 9A.1 — fail CLOSED when the allowlist cannot be evaluated.
        if (!class_exists('VES_Apify_Actor_Registry') || !method_exists('VES_Apify_Actor_Registry', 'is_allowed_slug')) {
            self::record_diagnostic_safe('apify_allowlist_unavailable', 'Run dispatch refused: actor allowlist registry unavailable (fail-closed).', [
                'method' => 'POST', 'stage' => 'actor_allowlist_gate_fail_closed',
            ]);
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('allowlist_unavailable', 'Provider dispatch blocked: allowlist registry unavailable.', ['stage' => 'fail_closed']);
            }
            return new WP_Error('ves_allowlist_unavailable', 'Provider dispatch is blocked because the actor allowlist service is unavailable. No paid run can start until it is restored.', [
                'status' => 0, 'provider_state' => 'CONFIGURATION_REQUIRED', 'stage' => 'actor_allowlist_gate_fail_closed',
            ]);
        }
        if (!VES_Apify_Actor_Registry::is_allowed_slug($slug)) {
            self::record_diagnostic_safe('apify_actor_not_allowlisted', 'Run dispatch refused: actor is not on the server-side allowlist.', [
                'method' => 'POST',
                'actor'  => VES_Apify_Actor_Registry::normalize_slug($slug),
                'stage'  => 'actor_allowlist_gate',
            ]);
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('provider_dispatch_blocked', 'Provider dispatch blocked: actor not allowlisted.', ['actor' => VES_Apify_Actor_Registry::normalize_slug($slug)]);
            }
            return new WP_Error('ves_actor_not_allowlisted', 'This data source actor is not allowlisted on the server. Ask an admin to register it in the actor registry before running it.', [
                'status' => 0, 'provider_state' => 'CONFIGURATION_REQUIRED', 'stage' => 'actor_allowlist_gate',
            ]);
        }

        // 9A.2 — hard ceiling enforcement.
        $ceiling = null;
        if (preg_match('/[?&]maxTotalChargeUsd=([0-9.]+)/', (string) $url, $cm)) { $ceiling = (float) $cm[1]; }
        if ($ceiling === null || $ceiling <= 0) {
            $zero_cost = method_exists('VES_Apify_Actor_Registry', 'is_zero_cost_slug') && VES_Apify_Actor_Registry::is_zero_cost_slug($slug);
            if ($zero_cost) {
                self::record_diagnostic_safe('apify_zero_cost_dispatch', 'Run-start without charge ceiling allowed: actor is registered zero-cost.', [
                    'method' => 'POST', 'actor' => VES_Apify_Actor_Registry::normalize_slug($slug), 'stage' => 'charge_ceiling_zero_cost',
                ]);
                return true;
            }
            self::record_diagnostic_safe('apify_charge_ceiling_missing', 'Run dispatch refused: run-start URL has no maxTotalChargeUsd ceiling (hard gate).', [
                'method' => 'POST', 'stage' => 'charge_ceiling_blocked',
            ]);
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('charge_ceiling_blocked', 'Provider dispatch blocked: missing charge ceiling.', ['actor' => VES_Apify_Actor_Registry::normalize_slug($slug)]);
            }
            return new WP_Error('ves_charge_ceiling_required', 'Provider dispatch is blocked: this paid run has no maxTotalChargeUsd cost ceiling. Configure the hard max charge in settings.', [
                'status' => 0, 'provider_state' => 'CONFIGURATION_REQUIRED', 'stage' => 'charge_ceiling_blocked',
            ]);
        }
        $max_allowed = self::MAX_CHARGE_CEILING_USD;
        if (function_exists('apply_filters')) { $max_allowed = (float) apply_filters('ves_apify_max_charge_ceiling_usd', $max_allowed); }
        if ($ceiling < self::MIN_CHARGE_CEILING_USD) {
            self::record_diagnostic_safe('apify_charge_ceiling_too_low', 'Run dispatch refused: charge ceiling below the minimum floor.', [
                'method' => 'POST', 'ceiling' => $ceiling, 'minimum' => self::MIN_CHARGE_CEILING_USD, 'stage' => 'charge_ceiling_blocked',
            ]);
            return new WP_Error('ves_charge_ceiling_too_low', 'Provider dispatch is blocked: the cost ceiling is below the supported minimum.', [
                'status' => 0, 'provider_state' => 'CONFIGURATION_REQUIRED', 'stage' => 'charge_ceiling_blocked',
            ]);
        }
        if ($max_allowed > 0 && $ceiling > $max_allowed) {
            self::record_diagnostic_safe('apify_charge_ceiling_too_high', 'Run dispatch refused: charge ceiling above the maximum allowed.', [
                'method' => 'POST', 'ceiling' => $ceiling, 'maximum' => $max_allowed, 'stage' => 'charge_ceiling_blocked',
            ]);
            if (class_exists('VES_Security_Event_Log')) {
                VES_Security_Event_Log::record('charge_ceiling_blocked', 'Provider dispatch blocked: ceiling above maximum.', ['ceiling' => $ceiling, 'maximum' => $max_allowed]);
            }
            return new WP_Error('ves_charge_ceiling_too_high', 'Provider dispatch is blocked: the cost ceiling exceeds the maximum allowed for this install.', [
                'status' => 0, 'provider_state' => 'CONFIGURATION_REQUIRED', 'stage' => 'charge_ceiling_blocked',
            ]);
        }
        return true;
    }

    public static function request($method, $url, $body = null, $attempt = 1) {
        // v0.1 RC: allowlist + ceiling gate runs first — a blocked actor must never
        // consume token config checks, retries, or provider budget.
        $dispatch_gate = self::enforce_run_dispatch_safety($method, $url);
        if (is_wp_error($dispatch_gate)) {
            return $dispatch_gate;
        }
        // v0.9.24.6: inject the Apify token via Authorization header rather
        // than as a URL ?token= query param, which leaks into access logs and
        // error messages. The URL token is still accepted by Apify as a
        // fallback, but we strip it here and prefer the header.
        $apify_token = class_exists('VES_Config') ? VES_Config::get_token() : '';
        // v0.9.30.19: fail fast and clearly when the backend provider token is
        // empty, instead of sending an empty Authorization header and getting a
        // confusing mid-flight 401. This keeps the category as a configuration
        // issue (missing_backend_token) rather than a provider_auth_error, and
        // never consumes provider rate budget for a request that cannot succeed.
        if (trim((string) $apify_token) === '') {
            self::record_diagnostic_safe('apify_missing_token', 'Backend provider token is empty; dispatch was not attempted.', [
                'method' => strtoupper($method),
                'stage' => 'missing_backend_token',
            ]);
            return new WP_Error('missing_backend_token', 'The data source is not fully configured yet. Ask an admin to add the backend provider token in settings.', [
                'status' => 0,
                'provider_state' => 'CONFIGURATION_REQUIRED',
                'stage' => 'missing_backend_token',
            ]);
        }
        $args = [
            'method'  => strtoupper($method),
            'timeout' => self::http_timeout_seconds(),
            'headers' => [
                'Accept'        => 'application/json',
                'User-Agent'    => 'VietnamEstudio-Scraper/1.1',
                'Authorization' => $apify_token !== '' ? 'Bearer ' . $apify_token : '',
            ],
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            self::record_diagnostic_safe('apify_transport', self::scrub_message($response->get_error_message()), [
                'method' => strtoupper($method),
                'attempt' => $attempt,
            ]);
            return new WP_Error('apify_transport', 'No se pudo contactar con Apify. La fuente se reintentará si el run sigue activo.', ['provider_state' => 'WAITING_RETRY', 'retry_after' => 60]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        // Deep-review hardening: a POST run-start is NOT retried on 5xx — the run
        // may already have started upstream, and re-POSTing could dispatch (and
        // charge) twice. 429 means the run was rate-limited away, so it stays
        // retryable; reads/aborts keep the original retry behavior.
        $is_run_start = strtoupper((string) $method) === 'POST'
            && preg_match('#https?://api\.apify\.com/v2/(acts|actor-tasks)/[^/?\#]+/(runs|run-sync[^/?\#]*)#i', (string) $url);
        if ($is_run_start && $code >= 500 && self::should_retry($code)) {
            self::record_diagnostic_safe('apify_run_start_no_retry', 'Run-start returned a 5xx; NOT retried to avoid a possible double dispatch/charge. Check the provider console before re-running.', [
                'method' => 'POST',
                'status' => $code,
            ]);
        }
        if (self::should_retry($code) && $attempt < 2 && !($is_run_start && $code >= 500)) {
            self::record_diagnostic_safe('apify_retry', 'Retrying upstream request after transient error.', [
                'method' => strtoupper($method),
                'status' => $code,
                'attempt' => $attempt,
            ]);
            usleep(600000);
            return self::request($method, $url, $body, $attempt + 1);
        }

        if ($code < 200 || $code >= 300) {
            $sanitized_raw = self::scrub_message(substr((string) $raw, 0, 1200));
            $public_message = 'El servicio ha rechazado la petición. Revisa los datos introducidos e inténtalo de nuevo.';

            self::record_diagnostic_safe('apify_http', $sanitized_raw !== '' ? $sanitized_raw : $public_message, [
                'status' => $code,
                'method' => strtoupper($method),
                'attempt' => $attempt,
            ]);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Vietnam Estudio Social] upstream error ' . $code . ': ' . $sanitized_raw);
            }

            if (is_user_logged_in() && current_user_can('manage_options') && VES_Config::admin_error_details()) {
                // Keep product-flow messages safe. Full sanitized upstream body is stored
                // in VES_Admin diagnostics above, not appended to user-visible errors.
                $public_message .= ' [HTTP ' . (int) $code . ']';
            }

            $classified = self::classify_http_error($code, $raw, $json);
            if ($classified['code'] === 'apify_actor_rental_required') {
                $public_message = 'Este actor de Apify requiere rental/plan activo antes de poder ejecutarse. Actívalo en Apify o cambia el actor slug en los ajustes.';
                // Raw provider rental/configuration body remains admin-diagnostic only.
            }
            return new WP_Error($classified['code'], $public_message, ['status' => $code, 'provider_state' => $classified['provider_state']]);
        }

        if (!is_array($json)) {
            $sanitized_raw = self::scrub_message(substr((string) $raw, 0, 800));
            self::record_diagnostic_safe('apify_invalid_json', $sanitized_raw !== '' ? $sanitized_raw : 'Empty or non-JSON upstream response.', [
                'method' => strtoupper($method),
                'status' => $code,
                'attempt' => $attempt,
            ]);
            return new WP_Error('ves_invalid_json', 'La fuente devolvió una respuesta no válida. Inténtalo otra vez en unos segundos.', ['status' => $code]);
        }

        return ['code' => $code, 'body' => $json, 'raw' => $raw];
    }

    public static function fetch_run($run_id, $wait_for_finish = 20) {
        // v0.9.8.0 hardening: rawurlencode the user-controlled $run_id so that
        // sanitize_text_field — which doesn't strip path separators — can't be
        // weaponised to alter the API path.
        $run_id_safe = rawurlencode((string) $run_id);
        // v0.9.24.6: token sent via Authorization header in request().
        $url = add_query_arg([
            'waitForFinish' => max(0, (int) $wait_for_finish),
        ], "https://api.apify.com/v2/actor-runs/{$run_id_safe}");

        $response = self::request('GET', $url);
        if (!is_wp_error($response) && is_array($response['body'] ?? null)) {
            // Guard: if Apify returns a body where "data" is not an object, ['status']
            // on a string would throw a PHP 8 TypeError. Coerce to an array first.
            $data = is_array($response['body']['data'] ?? null) ? $response['body']['data'] : [];
            $status = strtoupper((string) ($data['status'] ?? ''));
            if (in_array($status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMING-OUT'], true)) {
                self::release_dispatch_slot_for_run((string) $run_id);
            }
        }
        return $response;
    }

    public static function fetch_items($run_id, $limit = 150) {
        $run_id_safe = rawurlencode((string) $run_id);
        $url = add_query_arg([
            // v0.9.24.6: token via Authorization header
            'format' => 'json',
            'clean'  => 'true',
            'limit'  => max(1, min(VES_Config::max_items(), (int) $limit)),
        ], "https://api.apify.com/v2/actor-runs/{$run_id_safe}/dataset/items");

        $response = self::request('GET', $url);
        if (is_wp_error($response)) {
            return $response;
        }

        return is_array($response['body']) ? $response['body'] : [];
    }

    public static function abort_run($run_id) {
        $run_id_safe = rawurlencode((string) $run_id);
        // v0.9.24.6: token via Authorization header
        $url = "https://api.apify.com/v2/actor-runs/{$run_id_safe}/abort";
        return self::request('POST', $url);
    }
}
