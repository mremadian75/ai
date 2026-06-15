<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Usage_Billing {
    const TABLE_SLUG = 'ves_usage_events';
    const TOPUP_TABLE_SLUG = 'ves_topup_requests';
    const DB_VERSION = '5';
    const BALANCE_META_KEY = 'ves_credit_balance';
    const PLAN_META_KEY = 'ves_plan_slug';
    const CUSTOMER_ROLE = 'ves_customer';

    public static function register_hooks() {
        add_action('user_register', [__CLASS__, 'handle_new_user']);
        add_action('show_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('edit_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_profile_fields']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_profile_fields']);
        add_action('admin_post_ves_request_topup', [__CLASS__, 'handle_topup_request']);
        add_action('admin_post_ves_approve_topup', [__CLASS__, 'handle_approve_topup']);
        add_action('admin_post_ves_reject_topup', [__CLASS__, 'handle_reject_topup']);
    }

    public static function activate() {
        self::create_tables();
        self::ensure_customer_role();
        self::ensure_admin_role_access();
    }

    public static function ensure_setup() {
        self::create_tables();
        self::ensure_customer_role();
        self::ensure_admin_role_access();
    }

    public static function handle_new_user($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        self::assign_registration_role($user_id);
        self::ensure_user_plan($user_id, true);
        self::ensure_user_balance($user_id, true);
    }

    public static function ensure_customer_role() {
        $caps = [ 'read' => true ];
        $role = get_role(self::CUSTOMER_ROLE);
        if (!$role) {
            add_role(self::CUSTOMER_ROLE, 'VE SaaS Customer', $caps);
            return;
        }
        foreach ($caps as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            }
        }
    }

    public static function ensure_admin_role_access() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('read');
        }
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function topup_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TOPUP_TABLE_SLUG;
    }

    public static function create_tables() {
        $version_option = 'ves_usage_events_db_version';
        if (get_option($version_option) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $usage_table = self::table_name();
        $sql_usage = "CREATE TABLE {$usage_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            project_id bigint(20) unsigned NOT NULL DEFAULT 0,
            provider varchar(40) NULL,
            model varchar(80) NULL,
            module varchar(80) NULL,
            run_id varchar(80) NULL,
            event_type varchar(40) NOT NULL,
            credits_delta decimal(10,2) NOT NULL DEFAULT 0,
            balance_after decimal(10,2) NOT NULL DEFAULT 0,
            reserved_credits decimal(10,2) NOT NULL DEFAULT 0,
            provider_estimated_cost decimal(10,4) NOT NULL DEFAULT 0,
            provider_actual_cost decimal(10,4) NOT NULL DEFAULT 0,
            requested_results int unsigned NOT NULL DEFAULT 0,
            raw_provider_rows int unsigned NOT NULL DEFAULT 0,
            normalized_rows int unsigned NOT NULL DEFAULT 0,
            displayed_rows int unsigned NOT NULL DEFAULT 0,
            useful_delivered_rows int unsigned NOT NULL DEFAULT 0,
            discarded_rows int unsigned NOT NULL DEFAULT 0,
            charged_credits decimal(10,2) NOT NULL DEFAULT 0,
            refunded_credits decimal(10,2) NOT NULL DEFAULT 0,
            run_status varchar(40) NULL,
            failure_reason varchar(190) NULL,
            actor_id varchar(120) NULL,
            apify_run_id varchar(120) NULL,
            status varchar(20) NOT NULL DEFAULT 'success',
            usage_key varchar(100) NULL,
            related_event_id bigint(20) unsigned NULL,
            target_type varchar(40) NULL,
            target_id varchar(80) NULL,
            message varchar(190) NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY workspace_id (workspace_id),
            KEY project_id (project_id),
            KEY provider (provider),
            KEY module (module),
            KEY run_id (run_id),
            KEY event_type (event_type),
            KEY status (status),
            KEY run_status (run_status),
            KEY actor_id (actor_id),
            KEY apify_run_id (apify_run_id),
            UNIQUE KEY usage_key (usage_key),
            KEY related_event_id (related_event_id),
            KEY target_type (target_type),
            KEY target_id (target_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $topup_table = self::topup_table_name();
        $sql_topup = "CREATE TABLE {$topup_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            pack_id varchar(80) NOT NULL,
            pack_label varchar(120) NOT NULL,
            credits decimal(10,2) NOT NULL DEFAULT 0,
            price_label varchar(80) NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            user_note text NULL,
            admin_note text NULL,
            requested_at datetime NOT NULL,
            decided_at datetime NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY requested_at (requested_at)
        ) {$charset_collate};";

        dbDelta($sql_usage);
        dbDelta($sql_topup);
        update_option($version_option, self::DB_VERSION, false);
    }

    public static function assign_registration_role($user_id) {
        $user = get_userdata((int) $user_id);
        if (!$user instanceof WP_User) {
            return;
        }
        $role = VES_Config::registration_role();
        if ($role && get_role($role)) {
            $user->set_role($role);
        }
    }

    public static function allowed_roles() {
        $roles = VES_Config::panel_allowed_roles();
        return !empty($roles) ? $roles : ['administrator', self::CUSTOMER_ROLE];
    }

    public static function can_access_panel($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : (is_user_logged_in() ? get_current_user_id() : 0);
        if ($user_id <= 0) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return false;
        }
        $allowed = self::allowed_roles();
        foreach ((array) $user->roles as $role) {
            if (in_array((string) $role, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    public static function render_access_denied() {
        $logout_url = class_exists('VES_Auth') ? wp_logout_url(VES_Auth::get_page_url('login')) : wp_logout_url(home_url('/'));
        return '<div class="ves-auth-shell"><div class="ves-auth-card"><h2 class="ves-auth-title">Acceso restringido</h2><p class="ves-auth-sub">Tu cuenta no tiene permisos para acceder al panel del SaaS. Contacta con un administrador para activar tu acceso o ajustar tu rol.</p><div class="ves-auth-actions"><a class="ves-btn ves-btn-primary" href="' . esc_url($logout_url) . '">Salir</a></div></div></div>';
    }

    public static function default_signup_credits() {
        return max(0, (float) VES_Config::default_signup_credits());
    }

    public static function get_available_plans() {
        $plans = [
            'free' => [
                'slug' => 'free',
                'label' => 'Free',
                'description' => 'Prueba pública: 10 análisis sociales gratuitos (TikTok/YouTube/Instagram). Reserva una demo para acceso ilimitado.',
                // Public lead-gen trial: a one-time allotment of ~10 social analyses
                // (1 heavy social request = manual_run·1.5 + ai_analysis = 2.5 credits;
                // 10 × 2.5 = 25). No monthly refill — when it runs out, the demo CTA appears.
                'signup_credits' => 25,
                'monthly_credits' => 0,
                'max_active_monitors' => 1,
                'limits' => [
                    'manual_run' => 10,
                    'ai_analysis' => 10,
                    'trend_run' => 0,
                    'trend_brief' => 0,
                    'background_monitor' => 0,
                    'brand_audit_light' => 0,
                    'brand_audit_standard' => 0,
                    'brand_audit_deep' => 0,
                    'creative_asset_analysis' => 0,
                ],
            ],
            'starter' => [
                'slug' => 'starter',
                'label' => 'Starter',
                'description' => 'Plan equilibrado para uso operativo real.',
                'signup_credits' => 100,
                'monthly_credits' => 100,
                'max_active_monitors' => 3,
                'limits' => [
                    'manual_run' => 120,
                    'ai_analysis' => 60,
                    'trend_run' => 20,
                    'trend_brief' => 15,
                    'background_monitor' => 90,
                    'brand_audit_light' => 20,
                    'brand_audit_standard' => 10,
                    'brand_audit_deep' => 3,
                    'creative_asset_analysis' => 20,
                ],
            ],
            'pro' => [
                'slug' => 'pro',
                'label' => 'Pro',
                'description' => 'Más volumen, más monitoreo y más briefs.',
                'signup_credits' => 300,
                'monthly_credits' => 300,
                'max_active_monitors' => 10,
                'limits' => [
                    'manual_run' => 450,
                    'ai_analysis' => 250,
                    'trend_run' => 80,
                    'trend_brief' => 60,
                    'background_monitor' => 360,
                    'brand_audit_light' => 80,
                    'brand_audit_standard' => 40,
                    'brand_audit_deep' => 15,
                    'creative_asset_analysis' => 80,
                ],
            ],
            'scale' => [
                'slug' => 'scale',
                'label' => 'Scale',
                'description' => 'Diseñado para uso intensivo y equipos más ambiciosos.',
                'signup_credits' => 1000,
                'monthly_credits' => 1000,
                'max_active_monitors' => 30,
                'limits' => [
                    'manual_run' => 1500,
                    'ai_analysis' => 1000,
                    'trend_run' => 250,
                    'trend_brief' => 200,
                    'background_monitor' => 1200,
                    'brand_audit_light' => 250,
                    'brand_audit_standard' => 150,
                    'brand_audit_deep' => 60,
                    'creative_asset_analysis' => 250,
                ],
            ],
        ];
        return apply_filters('ves_available_plans', $plans);
    }

    public static function get_plan_options() {
        $out = [];
        foreach (self::get_available_plans() as $slug => $plan) {
            $out[$slug] = (string) ($plan['label'] ?? $slug);
        }
        return $out;
    }

    public static function get_default_plan_slug() {
        $default = sanitize_key((string) VES_Config::default_plan_slug());
        $plans = self::get_available_plans();
        if ($default !== '' && isset($plans[$default])) {
            return $default;
        }
        return 'starter';
    }

    public static function get_plan($slug = '') {
        $plans = self::get_available_plans();
        $slug = sanitize_key((string) $slug);
        if ($slug !== '' && isset($plans[$slug])) {
            return $plans[$slug];
        }
        $default = self::get_default_plan_slug();
        return $plans[$default] ?? reset($plans);
    }

    public static function ensure_user_plan($user_id, $force = false) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return self::get_default_plan_slug();
        }
        $existing = sanitize_key((string) get_user_meta($user_id, self::PLAN_META_KEY, true));
        $plans = self::get_available_plans();
        if (!$force && $existing !== '' && isset($plans[$existing])) {
            return $existing;
        }
        $plan_slug = self::get_default_plan_slug();
        update_user_meta($user_id, self::PLAN_META_KEY, $plan_slug);
        return $plan_slug;
    }

    public static function get_user_plan_slug($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return self::get_default_plan_slug();
        }
        $slug = sanitize_key((string) get_user_meta($user_id, self::PLAN_META_KEY, true));
        $plans = self::get_available_plans();
        if ($slug === '' || !isset($plans[$slug])) {
            $slug = self::ensure_user_plan($user_id, false);
        }
        return $slug;
    }

    public static function set_user_plan($user_id, $slug, $record = true) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario no válido.');
        }
        $plan = self::get_plan($slug);
        $slug = sanitize_key((string) ($plan['slug'] ?? self::get_default_plan_slug()));
        $old = self::get_user_plan_slug($user_id);
        update_user_meta($user_id, self::PLAN_META_KEY, $slug);
        if ($record && $old !== $slug) {
            self::record_event($user_id, 'plan_change', 0, self::get_balance($user_id), 'success', 'Cambio de plan', [
                'from' => $old,
                'to' => $slug,
                'edited_by' => get_current_user_id(),
            ]);
        }
        return $slug;
    }

    public static function get_user_plan($user_id) {
        $user_id = (int) $user_id;
        $slug = self::get_user_plan_slug($user_id);
        $plan = self::get_plan($slug);
        // Overlay admin-configured Credits & Limits / policies stored in the
        // access map. This keeps billing runtime aligned with the admin UI.
        if (class_exists('VES_Access_Control')) {
            $stored = get_option(VES_Access_Control::OPT_PLAN_ACCESS, []);
            if (is_array($stored) && isset($stored[$slug]) && is_array($stored[$slug])) {
                if (isset($stored[$slug]['limits']) && is_array($stored[$slug]['limits'])) {
                    $plan['limits'] = array_merge(is_array($plan['limits'] ?? null) ? $plan['limits'] : [], $stored[$slug]['limits']);
                    foreach (['monthly_credits','signup_credits','max_active_monitors'] as $k) {
                        if (isset($stored[$slug]['limits'][$k]) && is_numeric($stored[$slug]['limits'][$k])) { $plan[$k] = 0 + $stored[$slug]['limits'][$k]; }
                    }
                }
                if (isset($stored[$slug]['policies']) && is_array($stored[$slug]['policies'])) {
                    $plan['policies'] = array_merge(is_array($plan['policies'] ?? null) ? $plan['policies'] : [], $stored[$slug]['policies']);
                }
            }
        }
        return $plan;
    }

    public static function get_credit_packs() {
        $packs = [
            'pack_50' => [ 'id' => 'pack_50', 'label' => 'Pack 50', 'credits' => 50, 'price_label' => '€15', 'description' => 'Recarga ligera para seguir operando.', 'status' => 'active' ],
            'pack_100' => [ 'id' => 'pack_100', 'label' => 'Pack 100', 'credits' => 100, 'price_label' => '€25', 'description' => 'El pack más equilibrado para uso continuo.', 'status' => 'active' ],
            'pack_250' => [ 'id' => 'pack_250', 'label' => 'Pack 250', 'credits' => 250, 'price_label' => '€55', 'description' => 'Mejor relación volumen / fricción manual.', 'status' => 'active' ],
            'pack_500' => [ 'id' => 'pack_500', 'label' => 'Pack 500', 'credits' => 500, 'price_label' => '€95', 'description' => 'Pensado para equipos con más runs.', 'status' => 'active' ],
        ];
        return apply_filters('ves_credit_packs', $packs);
    }

    public static function get_credit_pack($pack_id) {
        $pack_id = sanitize_key((string) $pack_id);
        $packs = self::get_credit_packs();
        return $packs[$pack_id] ?? null;
    }

    public static function ensure_user_balance($user_id, $force_award = false) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0.0;
        }
        $existing = get_user_meta($user_id, self::BALANCE_META_KEY, true);
        if ($existing === '' || $existing === null || $force_award) {
            $plan = self::get_user_plan($user_id);
            $balance = self::default_signup_credits();
            if ($balance <= 0 && isset($plan['signup_credits'])) {
                $balance = (float) $plan['signup_credits'];
            }
            update_user_meta($user_id, self::BALANCE_META_KEY, $balance);
            if ($force_award || $existing === '' || $existing === null) {
                self::record_event($user_id, 'signup_credit', $balance, $balance, 'success', 'Créditos iniciales asignados', [
                    'source' => $force_award ? 'registration' : 'backfill',
                    'plan' => sanitize_key((string) ($plan['slug'] ?? '')),
                ]);
            }
            return $balance;
        }
        return round((float) $existing, 2);
    }

    /**
     * v0.9.9.6 — Unlimited credits for admins.
     * Returns true when the given user is an admin (manage_options) AND
     * `define('VES_UNLIMITED_FOR_ADMINS', true)` is in wp-config.php OR the
     * `ves_unlimited_for_admins` filter returns true. Defaults to true so the
     * site owner can always test. Set the constant to false (or filter to false)
     * to revert to normal billing for admins.
     *
     * When this returns true:
     *   - get_balance() returns a sentinel float (PHP_FLOAT_MAX) so UI shows "∞"
     *   - reserve_usage() / charge_user_once() / enforce_plan_limit() short-circuit
     *   - the user's stored balance is never touched
     */
    public static function is_unlimited_user($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : (is_user_logged_in() ? get_current_user_id() : 0);
        if ($user_id <= 0) {
            return false;
        }
        if (!user_can($user_id, 'manage_options')) {
            return false;
        }
        $enabled = defined('VES_UNLIMITED_FOR_ADMINS') ? (bool) VES_UNLIMITED_FOR_ADMINS : true;
        return (bool) apply_filters('ves_unlimited_for_admins', $enabled, $user_id);
    }

    public static function get_balance($user_id) {
        if (self::is_unlimited_user($user_id)) {
            // Sentinel — large enough to never block, small enough to stay within
            // float precision. UI inspects is_unlimited_user() and renders "∞".
            return 999999999.0;
        }
        return self::ensure_user_balance((int) $user_id, false);
    }

    /** Per-request registry of held GET_LOCK names, keyed by user id. */
    private static $held_db_locks = [];

    /** Per-request reentrancy depth for the per-user credit lock, keyed by user id. */
    private static $lock_depth = [];

    private static function credit_lock_key($user_id) {
        $user_id = absint($user_id);
        return $user_id > 0 ? 'ves_credit_lock_' . $user_id : '';
    }

    /**
     * Build a MySQL advisory-lock name that is unique per install + user.
     *
     * GET_LOCK names are server-global, so we namespace by db name + table
     * prefix to avoid cross-site collisions on shared MySQL hosts. MySQL 5.7+
     * caps lock names at 64 chars; we md5 the seed and prefix to stay well under.
     */
    private static function db_lock_name($user_id) {
        global $wpdb;
        $seed = '';
        if (is_object($wpdb)) {
            $seed = (string) ($wpdb->dbname ?? '') . '|' . (string) ($wpdb->prefix ?? '');
        }
        $seed .= '|' . (int) $user_id;
        return 'vescl_' . substr(md5($seed), 0, 50);
    }

    /**
     * Acquire a real cross-process mutex for a user's credit balance.
     *
     * v0.9.25: replaces the transient-based advisory lock (which could not be
     * made atomic on object-cache hosts) with MySQL GET_LOCK — a genuine
     * blocking mutex shared by every PHP worker on the same database. The lock
     * auto-releases if the DB connection drops, so a crashed worker can never
     * wedge a user's balance. We still degrade-to-proceed when the lock can't be
     * acquired (timeout or GET_LOCK unavailable) because same-key double charges
     * are independently prevented by the usage_key dedup in reserve_usage(), and
     * we never want an infra hiccup to hard-block every non-admin reservation.
     */
    private static function acquire_credit_lock($user_id, $timeout_ms = 3000) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return true;
        }

        // Reentrant: if this request already holds the lock for this user, bump
        // the depth instead of issuing a second GET_LOCK. MySQL GET_LOCK on the
        // same name in one connection requires a matching extra RELEASE_LOCK, so
        // a nested billing call (e.g. a settle/void closure that calls
        // adjust_user_credits) must reuse the held lock — otherwise the inner
        // release would free the lock the outer scope still relies on.
        if (!empty(self::$lock_depth[$user_id])) {
            self::$lock_depth[$user_id]++;
            return true;
        }

        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            $name = self::db_lock_name($user_id);
            // GET_LOCK timeout is whole seconds; round up from the ms budget.
            $timeout_s = max(1, (int) ceil(max(250, (int) $timeout_ms) / 1000));
            $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout_s));

            // GET_LOCK returns: '1' = acquired, '0' = timed out (held elsewhere),
            // NULL = error/killed. Only '1' is a true acquisition.
            if ($got === '1' || $got === 1) {
                self::$held_db_locks[$user_id] = $name;
                self::$lock_depth[$user_id] = 1;
                return true;
            }

            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_Usage_Billing] GET_LOCK not acquired for user ' . $user_id
                    . ' (result=' . var_export($got, true) . '); proceeding without advisory lock.');
            }
            return (bool) apply_filters('ves_credit_lock_degrade_to_proceed', true, $user_id);
        }

        // No usable $wpdb (e.g. very early boot or tests) — proceed; usage_key
        // dedup still protects against duplicate charges for the same key.
        return (bool) apply_filters('ves_credit_lock_degrade_to_proceed', true, $user_id);
    }

    private static function release_credit_lock($user_id) {
        $user_id = (int) $user_id;
        global $wpdb;

        // Reentrant unwind: only the outermost release frees the MySQL lock.
        if (!empty(self::$lock_depth[$user_id])) {
            self::$lock_depth[$user_id]--;
            if (self::$lock_depth[$user_id] > 0) {
                return; // still inside an outer locked scope — keep holding
            }
            unset(self::$lock_depth[$user_id]);
        }

        if (isset(self::$held_db_locks[$user_id])) {
            $name = self::$held_db_locks[$user_id];
            unset(self::$held_db_locks[$user_id]);
            if (is_object($wpdb) && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }

        // Best-effort cleanup of any legacy transient lock from older versions.
        $key = self::credit_lock_key($user_id);
        if ($key !== '' && function_exists('delete_transient')) {
            delete_transient($key);
        }
    }

    /**
     * Public reentrant per-user critical section. Lets billing-adjacent code
     * (e.g. the Stripe layer's idempotent grant) run a check-then-write block
     * under the SAME per-user mutex as the ledger, so concurrent operations on a
     * user's balance serialize. Safe to nest with the internal billing methods
     * because the lock is reentrant.
     */
    public static function with_user_lock($user_id, $callback) {
        return self::with_credit_lock((int) $user_id, $callback);
    }

    private static function with_credit_lock($user_id, $callback) {
        $user_id = (int) $user_id;
        if (!self::acquire_credit_lock($user_id)) {
            return new WP_Error('credit_lock_timeout', 'No se pudo bloquear el saldo para esta operación. Inténtalo de nuevo.');
        }

        try {
            return call_user_func($callback);
        } catch (Throwable $e) {
            return new WP_Error('credit_lock_exception', $e->getMessage());
        } finally {
            self::release_credit_lock($user_id);
        }
    }

    public static function get_cost($event_type) {
        $event_type = sanitize_key((string) $event_type);

        $defaults = [
            'manual_run' => 1.0,
            'ai_analysis' => 1.0,
            'trend_run' => 4.0,
            'trend_brief' => 1.0,
            'background_monitor' => 4.0,
            'brand_audit_light' => 8.0,
            'brand_audit_standard' => 18.0,
            'brand_audit_deep' => 35.0,
            'creative_asset_analysis' => 8.0,
            // Metering-only events for Phase 2 intelligence layers. Costs default to 0
            // until billing strategy is approved, but events are recorded for auditability.
            'brand_audit_final_ai_analysis' => 0.0,
            'evidence_items_stored' => 0.0,
            'metric_snapshots_created' => 0.0,
            'pattern_candidates_created' => 0.0,
            'insight_records_created' => 0.0,
            'opportunity_records_created' => 0.0,
            'memory_record_created' => 0.0,
            'assistant_answer_light' => 0.0,
            'assistant_answer_with_evidence' => 0.0,
            'assistant_answer_with_memory' => 0.0,
            'evidence_raw_view' => 0.0,
            'brand_audit_intelligence_backfilled' => 0.0,
            'brief_record_created' => 0.0,
            'brief_generated_with_ai' => 0.0,
            'brief_generated_local_fallback' => 0.0,
            'brief_generation_reserved' => 0.0,
            'brief_generation_posted' => 0.0,
            'brief_generation_voided' => 0.0,
            'approved_output_usage_event' => 0.0,
            'outcome_recorded' => 0.0,
            'pattern_observation_created' => 0.0,
            'manual_intake_completed' => 0.0,
            'canonical_run_completed' => 0.0,
        ];

        if (method_exists(__CLASS__, 'get_metered_event_costs')) {
            $defaults = array_merge($defaults, self::get_metered_event_costs());
        }

        if (!isset($defaults[$event_type])) {
            return 0.0;
        }

        $method = 'cost_' . $event_type;
        if (class_exists('VES_Config') && method_exists('VES_Config', $method)) {
            return round((float) call_user_func(['VES_Config', $method]), 2);
        }

        $settings = class_exists('VES_Config') ? VES_Config::get_settings() : [];
        $option_key = 'cost_' . $event_type;
        $cost = round(max(0, (float) ($settings[$option_key] ?? $defaults[$event_type])), 2);
        if (class_exists('VES_Access_Control') && is_user_logged_in()) {
            $user_id = get_current_user_id();
            if ($event_type === 'manual_run') {
                $admin_cost = VES_Access_Control::get_plan_limit($user_id, 'base_scan_fee');
                if ($admin_cost !== null) { $cost = round(max(0, (float) $admin_cost), 2); }
            } elseif ($event_type === 'ai_analysis') {
                $admin_cost = VES_Access_Control::get_plan_limit($user_id, 'ai_analysis_fee');
                if ($admin_cost !== null) { $cost = round(max(0, (float) $admin_cost), 2); }
            }
        }
        return round(max(0, (float) apply_filters('ves_usage_event_cost', $cost, $event_type, $defaults)), 2);
    }


    public static function estimate_for_run($platform = '', $desired_results = 0) {
        $platform = sanitize_key((string) $platform);
        $desired_results = max(0, (int) $desired_results);
        $base = (float) self::get_cost('manual_run');
        $ai = (float) self::get_cost('ai_analysis');
        $is_heavy = in_array($platform, ['instagram', 'tiktok', 'youtube', 'facebook', 'facebook_ads', 'linkedin'], true);
        $scan_multiplier = $is_heavy ? 1.5 : 1.0;
        $result_estimate = $desired_results > 0 ? min(10, $desired_results) * 0.05 : 0.0;
        return round(max(0, ($base * $scan_multiplier) + $ai + $result_estimate), 2);
    }

    public static function get_metered_event_costs() {
        $defaults = [
            'brand_audit_final_ai_analysis' => 0.0,
            'evidence_items_stored' => 0.0,
            'metric_snapshots_created' => 0.0,
            'pattern_candidates_created' => 0.0,
            'insight_records_created' => 0.0,
            'opportunity_records_created' => 0.0,
            'memory_record_created' => 0.0,
            'assistant_answer_light' => 0.0,
            'assistant_answer_with_evidence' => 0.0,
            'assistant_answer_with_memory' => 0.0,
            'evidence_raw_view' => 0.0,
            'brand_audit_intelligence_backfilled' => 0.0,
            'brief_record_created' => 0.0,
            'brief_generated_with_ai' => 0.0,
            'brief_generated_local_fallback' => 0.0,
            'brief_generation_reserved' => 0.0,
            'brief_generation_posted' => 0.0,
            'brief_generation_voided' => 0.0,
            'approved_output_usage_event' => 0.0,
            'outcome_recorded' => 0.0,
            'pattern_observation_created' => 0.0,
            'manual_intake_completed' => 0.0,
            'canonical_run_completed' => 0.0,
        ];
        $settings = class_exists('VES_Config') ? VES_Config::get_settings() : [];
        foreach ($defaults as $key => $value) {
            $option_key = 'cost_' . $key;
            if (isset($settings[$option_key])) {
                $defaults[$key] = max(0, round((float) $settings[$option_key], 2));
            }
        }
        return apply_filters('ves_metered_event_costs', $defaults);
    }



    public static function estimate_brand_audit_cost($request) {
        $request = is_array($request) ? $request : [];
        $depth = sanitize_key((string) ($request['audit_depth'] ?? $request['depth'] ?? $request['scope'] ?? 'standard'));
        $event = $depth === 'deep' ? 'brand_audit_deep' : ($depth === 'light' ? 'brand_audit_light' : 'brand_audit_standard');
        $base = self::get_cost($event);
        $final_ai = self::get_cost('brand_audit_final_ai_analysis');
        $normalization = self::get_cost('evidence_items_stored');
        $memory = self::get_cost('memory_record_created');
        $assistant_optional = self::get_cost('assistant_answer_with_memory') + self::get_cost('ai_analysis');
        $brief_optional = self::get_cost('brief_generated_with_ai') + self::get_cost('brief_record_created');
        $platforms = $request['platforms'] ?? [];
        if (is_string($platforms)) { $platforms = array_filter(array_map('trim', preg_split('/[,|\s]+/', $platforms))); }
        $platform_count = max(1, count((array) $platforms));
        $competitor_count = max(0, (int) ($request['competitor_count'] ?? $request['competitors_count'] ?? 0));
        if ($competitor_count <= 0 && !empty($request['competitors']) && is_array($request['competitors'])) { $competitor_count = count($request['competitors']); }
        $limit = max(1, min(500, (int) ($request['limit'] ?? 25)));
        $depth_multiplier = $depth === 'deep' ? 1.6 : ($depth === 'light' ? 0.65 : 1.0);
        $source_collection = round($base * $depth_multiplier + max(0, $platform_count - 1) * 0.75 + $competitor_count * 0.5 + min(3, $limit / 100), 2);
        $estimated = round($source_collection + $normalization + $final_ai + $memory, 2);
        $rules = apply_filters('ves_brand_audit_credit_estimate_rules', [
            'depth_multiplier' => $depth_multiplier,
            'platform_count' => $platform_count,
            'competitor_count' => $competitor_count,
            'limit' => $limit,
        ], $request);
        return [
            'estimated_credits' => (float) apply_filters('ves_brand_audit_estimated_credits', $estimated, $request, $rules),
            'breakdown' => [
                ['label' => 'Source collection', 'event_type' => $event, 'credits' => $source_collection],
                ['label' => 'Evidence normalization/storage', 'event_type' => 'evidence_items_stored', 'credits' => $normalization],
                ['label' => 'Final AI analysis', 'event_type' => 'brand_audit_final_ai_analysis', 'credits' => $final_ai],
                ['label' => 'Memory context/write', 'event_type' => 'memory_record_created', 'credits' => $memory],
                ['label' => 'Assistant optional follow-up', 'event_type' => 'assistant_optional', 'credits' => $assistant_optional, 'optional' => true],
                ['label' => 'Brief optional', 'event_type' => 'brief_optional', 'credits' => $brief_optional, 'optional' => true],
            ],
            'notes' => ['Estimate is approximate. Final source success/failure, item volume, and AI fallback behavior may change final usage.'],
            'refundable_parts' => ['failed_source_collection', 'cancelled_before_ai'],
            'approximate' => true,
            'rules' => $rules,
        ];
    }

    public static function estimate_brief_generation_cost($brief_type, $context = []) {
        $brief_type = sanitize_key((string) $brief_type);
        $context = is_array($context) ? $context : [];
        $ai_cost = self::get_cost('brief_generated_with_ai');
        $record_cost = self::get_cost('brief_record_created');
        $fallback_cost = self::get_cost('brief_generated_local_fallback');
        $rules = apply_filters('ves_brief_generation_credit_estimate_rules', ['brief_type' => $brief_type ?: 'campaign', 'fallback_cost' => $fallback_cost], $context);
        return [
            'estimated_credits' => round($ai_cost + $record_cost, 2),
            'breakdown' => [
                ['label' => 'Brief generation with AI', 'event_type' => 'brief_generated_with_ai', 'credits' => $ai_cost],
                ['label' => 'Brief record storage', 'event_type' => 'brief_record_created', 'credits' => $record_cost],
                ['label' => 'Local fallback if OpenAI unavailable', 'event_type' => 'brief_generated_local_fallback', 'credits' => $fallback_cost, 'conditional' => true],
            ],
            'notes' => ['Brief generation is idempotent by default: an existing draft/reviewed brief is returned without a duplicate charge unless force_new is explicitly used by an admin.'],
            'refundable_parts' => ['OpenAI failure without saved fallback voids reservation'],
            'brief_type' => $brief_type ?: 'campaign',
            'approximate' => true,
            'rules' => $rules,
        ];
    }

    public static function estimate_assistant_question_cost($context = []) {
        $context = is_array($context) ? $context : [];
        $has_evidence = !empty($context['has_evidence']);
        $has_memory = !empty($context['has_memory']);
        $has_brief = !empty($context['has_brief']);
        $event = $has_evidence ? 'assistant_answer_with_evidence' : ($has_memory || $has_brief ? 'assistant_answer_with_memory' : 'assistant_answer_light');
        $analysis = self::get_cost('ai_analysis');
        $meter = self::get_cost($event);
        $rules = apply_filters('ves_assistant_credit_estimate_rules', ['event_type' => $event, 'has_evidence' => $has_evidence, 'has_memory' => $has_memory, 'has_brief' => $has_brief], $context);
        return [
            'estimated_credits' => round($analysis + $meter, 2),
            'breakdown' => [
                ['label' => 'Assistant AI analysis', 'event_type' => 'ai_analysis', 'credits' => $analysis],
                ['label' => 'Assistant metered context', 'event_type' => $event, 'credits' => $meter],
            ],
            'notes' => ['Assistant estimates vary by selected evidence, brief, memory, and context size.'],
            'refundable_parts' => ['failed OpenAI calls void the reserved ai_analysis usage'],
            'approximate' => true,
            'rules' => $rules,
        ];
    }


    public static function get_current_period_usage($user_id, $include_pending = false) {
        global $wpdb;
        $user_id = (int) $user_id;
        $counts = [
            'manual_run' => 0,
            'ai_analysis' => 0,
            'trend_run' => 0,
            'trend_brief' => 0,
            'background_monitor' => 0,
            'brand_audit_light' => 0,
            'brand_audit_standard' => 0,
            'brand_audit_deep' => 0,
            'creative_asset_analysis' => 0,
            'brand_audit_final_ai_analysis' => 0,
            'evidence_items_stored' => 0,
            'metric_snapshots_created' => 0,
            'pattern_candidates_created' => 0,
            'insight_records_created' => 0,
            'opportunity_records_created' => 0,
            'memory_record_created' => 0,
            'assistant_answer_light' => 0,
            'assistant_answer_with_evidence' => 0,
            'assistant_answer_with_memory' => 0,
            'evidence_raw_view' => 0,
        ];
        if ($user_id <= 0) {
            return $counts;
        }
        $start = gmdate('Y-m-01 00:00:00', current_time('timestamp', true));
        $allowed_statuses = $include_pending ? "'pending','success','posted'" : "'success','posted'";
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) AS total FROM " . self::table_name() . " WHERE user_id = %d AND status IN ({$allowed_statuses}) AND created_at >= %s GROUP BY event_type",
            $user_id,
            $start
        ), ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $type = sanitize_key((string) ($row['event_type'] ?? ''));
                if (array_key_exists($type, $counts)) {
                    $counts[$type] = (int) ($row['total'] ?? 0);
                }
            }
        }
        return $counts;
    }

    /**
     * Total credits spent by a user over the last N days (read-only). Powers the
     * dashboard's burn-rate and projected-runout figures. Only settled debits
     * (success/posted, credits_delta < 0) count, so pending reservations and
     * refunds don't skew the burn estimate.
     */
    public static function get_spend_in_days($user_id, $days = 7) {
        global $wpdb;
        $user_id = (int) $user_id;
        $days = max(1, (int) $days);
        if ($user_id <= 0 || !is_object($wpdb)) { return 0.0; }
        $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $since = gmdate('Y-m-d H:i:s', time() - $days * $day_seconds);
        $spent = $wpdb->get_var($wpdb->prepare(
            "SELECT ABS(COALESCE(SUM(credits_delta),0)) FROM " . self::table_name()
            . " WHERE user_id = %d AND credits_delta < 0 AND status IN ('success','posted') AND created_at >= %s",
            $user_id,
            $since
        ));
        return round((float) $spent, 2);
    }

    public static function get_plan_limits_for_user($user_id) {
        $user_id = (int) $user_id;
        $plan = self::get_user_plan($user_id);
        $limits = is_array($plan['limits'] ?? null) ? $plan['limits'] : [];
        if (class_exists('VES_Access_Control')) {
            $keys = ['monthly_credits','signup_credits','max_credits_per_run','max_daily_runs','max_monthly_runs','max_concurrent_runs','max_ai_analyses','max_ai_analyses_per_day','max_selected_items_per_analysis','max_provider_cost_per_run_usd','base_scan_fee','per_usable_result_fee','ai_analysis_fee'];
            foreach ($keys as $k) {
                $v = VES_Access_Control::get_plan_limit($user_id, $k);
                if ($v !== null) { $limits[$k] = $v; }
            }
        }
        return $limits;
    }

    public static function get_active_monitor_count_for_user($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !class_exists('VES_Monitor_Store')) {
            return 0;
        }
        $monitors = method_exists('VES_Monitor_Store', 'get_monitors_for_user') ? VES_Monitor_Store::get_monitors_for_user($user_id) : VES_Monitor_Store::get_monitors();
        $count = 0;
        foreach ((array) $monitors as $monitor) {
            $cadence = sanitize_key((string) ($monitor['cadence'] ?? 'manual'));
            $status = sanitize_key((string) ($monitor['last_status'] ?? 'idle'));
            if ($cadence !== 'manual' && $status !== 'paused') {
                $count++;
            }
        }
        return $count;
    }

    public static function would_exceed_monitor_limit($user_id, $monitor_id = '', $new_cadence = 'manual') {
        $user_id = (int) $user_id;
        $new_cadence = sanitize_key((string) $new_cadence);
        if ($user_id <= 0 || $new_cadence === 'manual') {
            return false;
        }
        $plan = self::get_user_plan($user_id);
        $max = max(0, (int) ($plan['max_active_monitors'] ?? 0));
        if ($max <= 0 || !class_exists('VES_Monitor_Store')) {
            return false;
        }
        $current = 0;
        $monitor_id = sanitize_text_field((string) $monitor_id);
        foreach ((array) (method_exists('VES_Monitor_Store', 'get_monitors_for_user') ? VES_Monitor_Store::get_monitors_for_user($user_id) : VES_Monitor_Store::get_monitors()) as $monitor) {
            $cadence = sanitize_key((string) ($monitor['cadence'] ?? 'manual'));
            $status = sanitize_key((string) ($monitor['last_status'] ?? 'idle'));
            if ($cadence === 'manual' || $status === 'paused') {
                continue;
            }
            if ($monitor_id !== '' && (string) ($monitor['id'] ?? '') === $monitor_id) {
                continue;
            }
            $current++;
        }
        return $current >= $max;
    }

    private static function count_usage_since($user_id, $event_types, $since_gmt, $include_pending = true) {
        global $wpdb;
        $user_id = (int) $user_id;
        $event_types = array_values(array_filter(array_map('sanitize_key', (array) $event_types)));
        if ($user_id <= 0 || empty($event_types)) { return 0; }
        $placeholders = implode(',', array_fill(0, count($event_types), '%s'));
        $statuses = $include_pending ? "'pending','success','posted'" : "'success','posted'";
        $params = array_merge([$user_id], $event_types, [$since_gmt]);
        $sql = "SELECT COUNT(*) FROM " . self::table_name() . " WHERE user_id = %d AND event_type IN ({$placeholders}) AND status IN ({$statuses}) AND created_at >= %s";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    public static function enforce_plan_limit($user_id, $event_type) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return true;
        }
        // v0.9.9.6: unlimited admins are not subject to plan-tier monthly caps.
        if (self::is_unlimited_user($user_id)) {
            return true;
        }
        $limits = self::get_plan_limits_for_user($user_id);
        $run_events = ['manual_run','trend_run','brand_audit_light','brand_audit_standard','brand_audit_deep','creative_asset_analysis'];
        if (in_array($event_type, $run_events, true)) {
            $daily_limit = isset($limits['max_daily_runs']) ? (int) $limits['max_daily_runs'] : 0;
            if ($daily_limit > 0) {
                $used_today = self::count_usage_since($user_id, $run_events, gmdate('Y-m-d 00:00:00', current_time('timestamp', true)), true);
                if ($used_today >= $daily_limit) {
                    self::record_event($user_id, $event_type, 0, self::get_balance($user_id), 'denied', 'Límite diario del plan alcanzado', ['limit' => $daily_limit, 'used' => $used_today]);
                    return new WP_Error('plan_daily_limit_reached', sprintf('Has alcanzado el límite diario de ejecuciones de tu plan (%1$d/%2$d).', $used_today, $daily_limit));
                }
            }
            $monthly_limit = isset($limits['max_monthly_runs']) ? (int) $limits['max_monthly_runs'] : 0;
            if ($monthly_limit > 0) {
                $used_month = self::count_usage_since($user_id, $run_events, gmdate('Y-m-01 00:00:00', current_time('timestamp', true)), true);
                if ($used_month >= $monthly_limit) {
                    self::record_event($user_id, $event_type, 0, self::get_balance($user_id), 'denied', 'Límite mensual de ejecuciones alcanzado', ['limit' => $monthly_limit, 'used' => $used_month]);
                    return new WP_Error('plan_monthly_runs_reached', sprintf('Has alcanzado el límite mensual de ejecuciones de tu plan (%1$d/%2$d).', $used_month, $monthly_limit));
                }
            }
        }
        if ($event_type === 'ai_analysis' && isset($limits['max_ai_analyses'])) {
            $max_ai = (int) $limits['max_ai_analyses'];
            if ($max_ai > 0) {
                $used_ai = self::count_usage_since($user_id, ['ai_analysis'], gmdate('Y-m-01 00:00:00', current_time('timestamp', true)), true);
                if ($used_ai >= $max_ai) { return new WP_Error('plan_ai_limit_reached', sprintf('Has alcanzado el límite mensual de análisis AI (%1$d/%2$d).', $used_ai, $max_ai)); }
            }
        }
        if (!array_key_exists($event_type, $limits)) {
            return true;
        }
        $limit = max(0, (int) $limits[$event_type]);
        if ($limit <= 0) {
            return new WP_Error('plan_limit_reached', 'Tu plan no incluye más ejecuciones de este tipo.');
        }
        $usage = self::get_current_period_usage($user_id, true);
        $used = (int) ($usage[$event_type] ?? 0);
        if ($used >= $limit) {
            $plan = self::get_user_plan($user_id);
            self::record_event($user_id, $event_type, 0, self::get_balance($user_id), 'denied', 'Límite mensual del plan alcanzado', [
                'plan' => sanitize_key((string) ($plan['slug'] ?? '')),
                'limit' => $limit,
                'used' => $used,
            ]);
            return new WP_Error('plan_limit_reached', sprintf('Has alcanzado el límite mensual de %1$s para tu plan %2$s (%3$d/%4$d).', $event_type, (string) ($plan['label'] ?? 'actual'), $used, $limit));
        }
        return true;
    }


    private static function get_event_by_usage_key($usage_key) {
        global $wpdb;
        $usage_key = sanitize_text_field((string) $usage_key);
        if ($usage_key === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE usage_key = %s LIMIT 1', $usage_key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function reserve_usage($user_id, $event_type, $usage_key, $message = '', $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['charged' => 0.0, 'balance_after' => null, 'user_id' => 0, 'usage_key' => sanitize_text_field((string) $usage_key), 'status' => 'guest'];
        }
        // v0.9.9.6: admins with unlimited credits skip the lock + DB write
        // entirely. We still record the event so admins can audit usage.
        if (self::is_unlimited_user($user_id)) {
            $usage_key = sanitize_text_field((string) $usage_key);
            $cost = self::get_cost($event_type);
            $event_id = self::record_event($user_id, $event_type, 0, 999999999.0, 'pending', $message !== '' ? $message : 'Uso (admin ilimitado)', is_array($context) ? array_merge($context, ['unlimited' => 1, 'would_have_charged' => $cost]) : ['unlimited' => 1, 'would_have_charged' => $cost], $usage_key, 0, is_array($context) ? (string) ($context['target_type'] ?? '') : '', is_array($context) ? (string) ($context['target_id'] ?? '') : '');
            // v0.9.30.20: if the DB write failed (missing table, etc.) return WP_Error so
            // the caller can degrade gracefully instead of storing a phantom usage_key that
            // causes a "No existe reserva" failure at settlement time.
            if (!$event_id) {
                return new WP_Error('usage_event_failed', 'No se pudo registrar la reserva de créditos (admin ilimitado).');
            }
            return ['charged' => 0.0, 'balance_after' => 999999999.0, 'user_id' => $user_id, 'usage_key' => $usage_key, 'status' => 'pending', 'event_id' => (int) $event_id, 'unlimited' => true];
        }

        return self::with_credit_lock($user_id, static function () use ($user_id, $event_type, $usage_key, $message, $context) {
            $usage_key = sanitize_text_field((string) $usage_key);
            if ($usage_key === '') {
                return new WP_Error('missing_usage_key', 'Falta usage key para reservar crédito.');
            }

            $existing = self::get_event_by_usage_key($usage_key);
            if ($existing) {
                $existing_user_id = (int) ($existing['user_id'] ?? 0);
                if ($existing_user_id > 0 && $existing_user_id !== $user_id) {
                    return new WP_Error('usage_key_owner_mismatch', 'Esta reserva de uso pertenece a otro usuario.');
                }
                return [
                    'charged' => abs((float) ($existing['credits_delta'] ?? 0)),
                    'balance_after' => isset($existing['balance_after']) ? (float) $existing['balance_after'] : null,
                    'user_id' => $user_id,
                    'usage_key' => $usage_key,
                    'status' => sanitize_key((string) ($existing['status'] ?? 'pending')),
                    'event_id' => (int) ($existing['id'] ?? 0),
                ];
            }

            $plan_check = self::enforce_plan_limit($user_id, $event_type);
            if (is_wp_error($plan_check)) {
                return $plan_check;
            }

            $cost = self::get_cost($event_type);
            $balance = self::get_balance($user_id);
            if ($cost > 0 && $balance < $cost) {
                self::record_event($user_id, $event_type, 0, $balance, 'denied', 'Créditos insuficientes', array_merge(is_array($context) ? $context : [], [
                    'required' => $cost,
                    'usage_key' => $usage_key,
                ]), $usage_key, 0, '', '');
                return new WP_Error('insufficient_credits', sprintf('No tienes créditos suficientes. Necesitas %.2f y tu saldo actual es %.2f.', $cost, $balance));
            }

            $new_balance = $balance;
            $delta = 0.0;
            if ($cost > 0) {
                $new_balance = round($balance - $cost, 2);
                update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
                $delta = 0 - $cost;
            }

            $event_id = self::record_event($user_id, $event_type, $delta, $new_balance, 'pending', $message !== '' ? $message : 'Reserva de uso', $context, $usage_key, 0, is_array($context) ? (string) ($context['target_type'] ?? '') : '', is_array($context) ? (string) ($context['target_id'] ?? '') : '');
            if (!$event_id) {
                if ($delta < 0) {
                    update_user_meta($user_id, self::BALANCE_META_KEY, $balance);
                }
                return new WP_Error('usage_event_failed', 'No se pudo registrar la reserva de créditos.');
            }

            return ['charged' => $cost, 'balance_after' => $new_balance, 'user_id' => $user_id, 'usage_key' => $usage_key, 'status' => 'pending', 'event_id' => $event_id];
        });
    }

    public static function post_reserved_usage($usage_key, $message = '') {
        global $wpdb;
        $event = self::get_event_by_usage_key($usage_key);
        if (!$event) {
            return new WP_Error('missing_usage_reservation', 'No existe reserva para este usage key.');
        }
        $status = sanitize_key((string) ($event['status'] ?? 'pending'));
        if (in_array($status, ['posted','success'], true)) {
            return true;
        }
        if (in_array($status, ['void','reversed'], true)) {
            return new WP_Error('usage_finalized', 'La reserva ya fue anulada o revertida.');
        }
        return false !== $wpdb->update(self::table_name(), [
            'status' => 'posted',
            'message' => sanitize_text_field((string) ($message !== '' ? $message : ($event['message'] ?? 'Uso confirmado'))),
        ], ['id' => (int) $event['id']], ['%s','%s'], ['%d']);
    }

    public static function settle_reserved_usage($usage_key, $final_cost, $message = '', $context = []) {
        $usage_key = sanitize_text_field((string) $usage_key);
        $final_cost = max(0.0, round((float) $final_cost, 2));
        $context = is_array($context) ? $context : [];
        $event = self::get_event_by_usage_key($usage_key);
        if (!$event) {
            return new WP_Error('missing_usage_reservation', 'No existe reserva para este usage key.');
        }
        $user_id = (int) ($event['user_id'] ?? 0);
        if ($user_id <= 0) {
            return new WP_Error('invalid_usage_user', 'Reserva de uso sin usuario válido.');
        }
        return self::with_credit_lock($user_id, static function () use ($usage_key, $final_cost, $message, $context) {
            global $wpdb;
            $event = self::get_event_by_usage_key($usage_key);
            if (!$event) {
                return new WP_Error('missing_usage_reservation', 'No existe reserva para este usage key.');
            }
            $status = sanitize_key((string) ($event['status'] ?? 'pending'));
            if (in_array($status, ['void','reversed'], true)) {
                return new WP_Error('usage_finalized', 'La reserva ya fue anulada o revertida.');
            }
            // Idempotency: a finalize/settle can be enqueued or retried more than once
            // (Action Scheduler retry + manual retry). If this reservation was already
            // settled, return the prior outcome instead of re-running the refund math
            // (which would overwrite charged/refunded and could double-refund).
            if ($status === 'posted') {
                return [
                    'status' => 'posted',
                    'usage_key' => $usage_key,
                    'user_id' => (int) ($event['user_id'] ?? 0),
                    'charged' => abs((float) ($event['charged_credits'] ?? $event['credits_delta'] ?? 0)),
                    'balance_after' => isset($event['balance_after']) ? (float) $event['balance_after'] : null,
                    'idempotent' => true,
                ];
            }
            $user_id = (int) ($event['user_id'] ?? 0);
            $original_charged = abs((float) ($event['credits_delta'] ?? 0));
            $old_context = [];
            if (!empty($event['context_json'])) {
                $decoded = json_decode((string) $event['context_json'], true);
                if (is_array($decoded)) { $old_context = $decoded; }
            }
            $merged_context = array_merge($old_context, $context, [
                'credits_reserved' => $original_charged,
                'reserved_credits' => $original_charged,
                'credits_charged_final' => $final_cost,
                'charged_credits' => $final_cost,
                'credits_refunded_or_voided' => max(0, round($original_charged - $final_cost, 2)),
                'refunded_credits' => max(0, round($original_charged - $final_cost, 2)),
                'settled_by' => 'delivered_results',
            ]);
            $provider_actual = (float) ($merged_context['provider_actual_cost'] ?? $merged_context['provider_actual_cost_usd'] ?? $merged_context['provider_cost_usd'] ?? $merged_context['total_charge_usd'] ?? $merged_context['provider_cost'] ?? 0);
            $ledger_context_updates = [
                'reserved_credits' => round($original_charged, 2),
                'provider_estimated_cost' => round((float) ($merged_context['provider_estimated_cost'] ?? $merged_context['provider_estimated_cost_usd'] ?? $merged_context['estimated_provider_cost_usd'] ?? 0), 4),
                'provider_actual_cost' => round($provider_actual, 4),
                'requested_results' => max(0, (int) ($merged_context['requested_results'] ?? $merged_context['desired_final_results'] ?? $merged_context['limit'] ?? 0)),
                'raw_provider_rows' => max(0, (int) ($merged_context['raw_provider_rows'] ?? $merged_context['raw_provider_returned_count'] ?? $merged_context['raw_items'] ?? $merged_context['provider_rows'] ?? 0)),
                'normalized_rows' => max(0, (int) ($merged_context['normalized_rows'] ?? 0)),
                'displayed_rows' => max(0, (int) ($merged_context['displayed_rows'] ?? $merged_context['final_delivered_count'] ?? $merged_context['filtered_items'] ?? 0)),
                'useful_delivered_rows' => max(0, (int) ($merged_context['useful_delivered_rows'] ?? $merged_context['final_delivered_count'] ?? $merged_context['filtered_items'] ?? 0)),
                'discarded_rows' => max(0, (int) ($merged_context['discarded_rows'] ?? $merged_context['discarded_count'] ?? 0)),
                'run_status' => sanitize_key((string) ($merged_context['run_status'] ?? $merged_context['status'] ?? '')) ?: null,
                'failure_reason' => !empty($merged_context['failure_reason']) ? sanitize_text_field((string) $merged_context['failure_reason']) : null,
                'actor_id' => !empty($merged_context['actor_id']) ? sanitize_text_field((string) $merged_context['actor_id']) : (!empty($merged_context['actor_slug']) ? sanitize_text_field((string) $merged_context['actor_slug']) : null),
                'apify_run_id' => !empty($merged_context['apify_run_id']) ? sanitize_text_field((string) $merged_context['apify_run_id']) : (!empty($merged_context['provider_run_id']) ? sanitize_text_field((string) $merged_context['provider_run_id']) : null),
            ];

            if (!empty($old_context['unlimited']) || self::is_unlimited_user($user_id)) {
                $merged_context['would_have_charged_final'] = $final_cost;
                $update_data = array_merge([
                    'status' => 'posted',
                    'charged_credits' => 0,
                    'refunded_credits' => 0,
                    'message' => sanitize_text_field((string) ($message !== '' ? $message : 'Uso confirmado por resultados entregados')),
                    'context_json' => wp_json_encode($merged_context),
                ], $ledger_context_updates);
                $update_formats = array_map(static function($v) { return is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s'); }, array_values($update_data));
                $wpdb->update(self::table_name(), $update_data, ['id' => (int) $event['id']], $update_formats, ['%d']);
                return ['status' => 'posted', 'charged' => 0.0, 'reserved' => 0.0, 'refunded' => 0.0, 'unlimited' => true];
            }

            $balance = self::get_balance($user_id);
            $final_delta = $final_cost > 0 ? (0 - $final_cost) : 0.0;
            $refund = 0.0;
            $extra = 0.0;
            $new_balance = $balance;
            if ($final_cost < $original_charged) {
                $refund = round($original_charged - $final_cost, 2);
                $new_balance = round($balance + $refund, 2);
                update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
                self::record_event($user_id, 'usage_refund', $refund, $new_balance, 'reversed', 'Refund after delivered-result settlement', [
                    'settled_usage_key' => $usage_key,
                    'original_reserved' => $original_charged,
                    'final_charge' => $final_cost,
                ], $usage_key . ':refund:' . time(), (int) ($event['id'] ?? 0), sanitize_text_field((string) ($event['target_type'] ?? '')), sanitize_text_field((string) ($event['target_id'] ?? '')));
            } elseif ($final_cost > $original_charged) {
                $extra = round($final_cost - $original_charged, 2);
                if ($balance < $extra) {
                    return new WP_Error('insufficient_credits_settlement', sprintf('No tienes créditos suficientes para completar el cargo final. Necesitas %.2f créditos adicionales.', $extra));
                }
                $new_balance = round($balance - $extra, 2);
                update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
                self::record_event($user_id, sanitize_key((string) ($event['event_type'] ?? 'manual_run')), 0 - $extra, $new_balance, 'posted', 'Additional charge after delivered-result settlement', [
                    'settled_usage_key' => $usage_key,
                    'original_reserved' => $original_charged,
                    'final_charge' => $final_cost,
                ], $usage_key . ':extra:' . time(), (int) ($event['id'] ?? 0), sanitize_text_field((string) ($event['target_type'] ?? '')), sanitize_text_field((string) ($event['target_id'] ?? '')));
            }

            $update_data = array_merge([
                'credits_delta' => $final_delta,
                'balance_after' => $new_balance,
                'status' => 'posted',
                'charged_credits' => $final_cost,
                'refunded_credits' => $refund,
                'message' => sanitize_text_field((string) ($message !== '' ? $message : 'Uso confirmado por resultados entregados')),
                'context_json' => wp_json_encode($merged_context),
            ], $ledger_context_updates);
            $update_formats = array_map(static function($v) { return is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s'); }, array_values($update_data));
            $updated = $wpdb->update(self::table_name(), $update_data, ['id' => (int) $event['id']], $update_formats, ['%d']);
            if (false === $updated) {
                return new WP_Error('usage_settlement_failed', 'No se pudo ajustar el cargo final de créditos.');
            }
            return [
                'status' => 'posted',
                'reserved' => $original_charged,
                'charged' => $final_cost,
                'refunded' => $refund,
                'extra_charged' => $extra,
                'balance_after' => $new_balance,
            ];
        });
    }

    public static function void_reserved_usage($usage_key, $reason = '') {
        $event = self::get_event_by_usage_key($usage_key);
        if (!$event) {
            return false;
        }

        $user_id = (int) ($event['user_id'] ?? 0);
        return self::with_credit_lock($user_id, static function () use ($usage_key, $reason) {
            global $wpdb;
            $event = self::get_event_by_usage_key($usage_key);
            if (!$event) {
                return false;
            }

            $status = sanitize_key((string) ($event['status'] ?? 'pending'));
            if (in_array($status, ['void','reversed'], true)) {
                return true;
            }
            if ($status === 'posted') {
                $delta = (float) ($event['credits_delta'] ?? 0);
                $user_id = (int) ($event['user_id'] ?? 0);
                if ($user_id > 0 && $delta < 0) {
                    $balance = self::get_balance($user_id);
                    $new_balance = round($balance + abs($delta), 2);
                    update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
                    self::record_event($user_id, sanitize_key((string) ($event['event_type'] ?? 'usage_refund')), abs($delta), $new_balance, 'reversed', $reason !== '' ? $reason : 'Créditos revertidos', [
                        'reversed_usage_key' => sanitize_text_field((string) $usage_key),
                    ], sanitize_text_field((string) $usage_key) . ':refund', (int) ($event['id'] ?? 0), sanitize_text_field((string) ($event['target_type'] ?? '')), sanitize_text_field((string) ($event['target_id'] ?? '')));
                }

                return false !== $wpdb->update(self::table_name(), [
                    'status' => 'reversed',
                    'message' => sanitize_text_field((string) ($reason !== '' ? $reason : 'Uso revertido')),
                ], ['id' => (int) $event['id']], ['%s','%s'], ['%d']);
            }

            $delta = (float) ($event['credits_delta'] ?? 0);
            $user_id = (int) ($event['user_id'] ?? 0);
            if ($delta < 0 && $user_id > 0) {
                $balance = self::get_balance($user_id);
                update_user_meta($user_id, self::BALANCE_META_KEY, round($balance + abs($delta), 2));
            }

            return false !== $wpdb->update(self::table_name(), [
                'status' => 'void',
                'message' => sanitize_text_field((string) ($reason !== '' ? $reason : 'Reserva anulada')),
            ], ['id' => (int) $event['id']], ['%s','%s'], ['%d']);
        });
    }

    public static function reverse_usage_by_key($usage_key, $reason = '') {
        $event = self::get_event_by_usage_key($usage_key);
        if (!$event) {
            return false;
        }

        $user_id = (int) ($event['user_id'] ?? 0);
        return self::with_credit_lock($user_id, static function () use ($usage_key, $reason) {
            $event = self::get_event_by_usage_key($usage_key);
            if (!$event) {
                return false;
            }

            $status = sanitize_key((string) ($event['status'] ?? 'pending'));
            if ($status === 'reversed') {
                return true;
            }

            $user_id = (int) ($event['user_id'] ?? 0);
            $delta = (float) ($event['credits_delta'] ?? 0);
            if ($user_id > 0 && $delta < 0) {
                $balance = self::get_balance($user_id);
                $new_balance = round($balance + abs($delta), 2);
                update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
                self::record_event($user_id, sanitize_key((string) ($event['event_type'] ?? 'usage_refund')), abs($delta), $new_balance, 'reversed', $reason !== '' ? $reason : 'Créditos revertidos', [
                    'reversed_usage_key' => sanitize_text_field((string) $usage_key),
                ], sanitize_text_field((string) $usage_key) . ':refund', (int) ($event['id'] ?? 0), sanitize_text_field((string) ($event['target_type'] ?? '')), sanitize_text_field((string) ($event['target_id'] ?? '')));
            }

            global $wpdb;
            return false !== $wpdb->update(self::table_name(), [
                'status' => 'reversed',
                'message' => sanitize_text_field((string) ($reason !== '' ? $reason : 'Uso revertido')),
            ], ['id' => (int) $event['id']], ['%s','%s'], ['%d']);
        });
    }

    public static function charge_user_once($user_id, $event_type, $usage_key, $message = '', $context = []) {
        $reserved = self::reserve_usage($user_id, $event_type, $usage_key, $message, $context);
        if (is_wp_error($reserved)) {
            return $reserved;
        }
        if (in_array((string) ($reserved['status'] ?? ''), ['posted','success'], true)) {
            return $reserved;
        }
        $posted = self::post_reserved_usage($usage_key, $message !== '' ? $message : 'Uso confirmado');
        if (is_wp_error($posted)) {
            return $posted;
        }
        if (false === $posted) {
            return new WP_Error('usage_post_failed', 'No se pudo confirmar el cargo de créditos.');
        }
        $reserved['status'] = 'posted';
        return $reserved;
    }

    public static function charge_current_user($event_type, $message = '', $context = []) {
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        return self::charge_user($user_id, $event_type, $message, $context);
    }

    public static function charge_user($user_id, $event_type, $message = '', $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['charged' => 0.0, 'balance_after' => null, 'user_id' => 0];
        }
        // v0.9.9.6: unlimited admins log a free event and skip lock+debit.
        if (self::is_unlimited_user($user_id)) {
            $cost = self::get_cost($event_type);
            $event_id = self::record_event($user_id, $event_type, 0, 999999999.0, 'success', $message !== '' ? $message : 'Uso (admin ilimitado)', is_array($context) ? array_merge($context, ['unlimited' => 1, 'would_have_charged' => $cost]) : ['unlimited' => 1, 'would_have_charged' => $cost], '', 0, is_array($context) ? (string) ($context['target_type'] ?? '') : '', is_array($context) ? (string) ($context['target_id'] ?? '') : '');
            return ['charged' => 0.0, 'balance_after' => 999999999.0, 'user_id' => $user_id, 'event_id' => (int) $event_id, 'unlimited' => true];
        }
        $usage_key = '';
        if (is_array($context) && !empty($context['usage_key'])) {
            $usage_key = sanitize_text_field((string) $context['usage_key']);
        }
        if ($usage_key === '') {
            $usage_key = 'legacy:' . sanitize_key((string) $event_type) . ':' . $user_id . ':' . md5(wp_json_encode([$message, $context, microtime(true)]));
        }
        return self::charge_user_once($user_id, $event_type, $usage_key, $message, $context);
    }

    public static function adjust_user_credits($user_id, $amount, $message = 'Ajuste manual', $context = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario no válido.');
        }

        return self::with_credit_lock($user_id, static function () use ($user_id, $amount, $message, $context) {
            $balance = self::get_balance($user_id);
            $new_balance = round($balance + (float) $amount, 2);
            if ($new_balance < 0) {
                $new_balance = 0;
            }
            update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
            self::record_event($user_id, 'admin_adjustment', (float) $amount, $new_balance, 'success', $message, $context);
            return $new_balance;
        });
    }

    public static function record_meter_event($user_id, $event_type, $target_type = '', $target_id = '', $message = '', $context = [], $usage_key = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return 0; }
        $context = is_array($context) ? $context : [];
        $event_type = sanitize_key((string) $event_type);
        $target_type = sanitize_key((string) $target_type);
        $target_id = sanitize_text_field((string) $target_id);
        if ($usage_key === '') {
            $stable_context = $context;
            unset($stable_context['raw_payload'], $stable_context['prompt'], $stable_context['response']);
            $usage_key = 'meter:' . $event_type . ':' . $user_id . ':' . md5(wp_json_encode([$target_type, $target_id, $stable_context]));
        }
        $cost = self::get_cost($event_type);
        if ($cost > 0) {
            return self::charge_user_once($user_id, $event_type, sanitize_text_field((string) $usage_key), $message !== '' ? $message : 'Metered SaaS event', array_merge($context, ['meter_only' => 0, 'configured_meter_cost' => $cost, 'target_type' => $target_type, 'target_id' => $target_id]));
        }
        return self::record_event(
            $user_id,
            $event_type,
            0,
            self::get_balance($user_id),
            'success',
            $message !== '' ? $message : 'Metered SaaS event',
            array_merge($context, ['meter_only' => 1, 'configured_meter_cost' => $cost]),
            sanitize_text_field((string) $usage_key),
            0,
            $target_type,
            $target_id
        );
    }

    private static function record_event($user_id, $event_type, $credits_delta, $balance_after, $status, $message, $context = [], $usage_key = '', $related_event_id = 0, $target_type = '', $target_id = '') {
        global $wpdb;
        $context = is_array($context) ? $context : [];
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? $context['workspaceId'] ?? 0));
        if ($workspace_id <= 0 && class_exists('VES_Memory_Records')) {
            $workspace_id = (int) VES_Memory_Records::infer_workspace_id($context, (int) $user_id);
        }
        $project_id = max(0, (int) ($context['project_id'] ?? $context['projectId'] ?? 0));
        if ($project_id <= 0 && class_exists('VES_Projects')) {
            $project_id = (int) VES_Projects::resolve_project_from_request($context, (int) $user_id, $workspace_id);
        }
        $provider = sanitize_key((string) ($context['provider'] ?? $context['provider_name'] ?? ''));
        $model = sanitize_text_field((string) ($context['model'] ?? $context['ai_model'] ?? ''));
        $module = sanitize_key((string) ($context['module'] ?? $context['run_type'] ?? $context['target_type'] ?? $target_type));
        $run_id = sanitize_text_field((string) ($context['run_id'] ?? $context['bundle_id'] ?? $context['runId'] ?? ($target_type === 'run' ? $target_id : '')));
        $ves_ok = $wpdb->insert(
            self::table_name(),
            $ves_row = [
                'user_id' => (int) $user_id,
                'workspace_id' => $workspace_id,
                'project_id' => $project_id,
                'provider' => $provider !== '' ? $provider : null,
                'model' => $model !== '' ? $model : null,
                'module' => $module !== '' ? $module : null,
                'run_id' => $run_id !== '' ? $run_id : null,
                'reserved_credits' => round((float) ($context['reserved_credits'] ?? $context['credits_reserved'] ?? abs((float) $credits_delta)), 2),
                'provider_estimated_cost' => round((float) ($context['provider_estimated_cost'] ?? $context['provider_estimated_cost_usd'] ?? $context['estimated_provider_cost_usd'] ?? 0), 4),
                'provider_actual_cost' => round((float) ($context['provider_actual_cost'] ?? $context['provider_actual_cost_usd'] ?? $context['provider_cost'] ?? 0), 4),
                'requested_results' => max(0, (int) ($context['requested_results'] ?? $context['desired_final_results'] ?? $context['limit'] ?? 0)),
                'raw_provider_rows' => max(0, (int) ($context['raw_provider_rows'] ?? $context['raw_items'] ?? 0)),
                'normalized_rows' => max(0, (int) ($context['normalized_rows'] ?? 0)),
                'displayed_rows' => max(0, (int) ($context['displayed_rows'] ?? $context['filtered_items'] ?? 0)),
                'useful_delivered_rows' => max(0, (int) ($context['useful_delivered_rows'] ?? $context['filtered_items'] ?? 0)),
                'discarded_rows' => max(0, (int) ($context['discarded_rows'] ?? 0)),
                'charged_credits' => round((float) ($context['charged_credits'] ?? $context['credits_charged_final'] ?? max(0, abs((float) $credits_delta))), 2),
                'refunded_credits' => round((float) ($context['refunded_credits'] ?? $context['credits_refunded_or_voided'] ?? 0), 2),
                'run_status' => sanitize_key((string) ($context['run_status'] ?? $context['status'] ?? '')) ?: null,
                'failure_reason' => !empty($context['failure_reason']) ? sanitize_text_field((string) $context['failure_reason']) : null,
                'actor_id' => !empty($context['actor_id']) ? sanitize_text_field((string) $context['actor_id']) : (!empty($context['actor_slug']) ? sanitize_text_field((string) $context['actor_slug']) : null),
                'apify_run_id' => !empty($context['apify_run_id']) ? sanitize_text_field((string) $context['apify_run_id']) : (!empty($context['provider_run_id']) ? sanitize_text_field((string) $context['provider_run_id']) : null),
                'event_type' => sanitize_key((string) $event_type),
                'credits_delta' => round((float) $credits_delta, 2),
                'balance_after' => round((float) $balance_after, 2),
                'status' => sanitize_key((string) $status),
                'usage_key' => $usage_key !== '' ? sanitize_text_field((string) $usage_key) : null,
                'related_event_id' => $related_event_id > 0 ? (int) $related_event_id : null,
                'target_type' => $target_type !== '' ? sanitize_key((string) $target_type) : null,
                'target_id' => $target_id !== '' ? sanitize_text_field((string) $target_id) : null,
                'message' => sanitize_text_field((string) $message),
                'context_json' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql', true),
            ],
            $ves_fmt = ['%d','%d','%d','%s','%s','%s','%s','%f','%f','%f','%d','%d','%d','%d','%d','%d','%f','%f','%s','%s','%s','%s','%s','%f','%f','%s','%s','%d','%s','%s','%s','%s','%s']
        );
        if ($ves_ok === false && method_exists(__CLASS__, 'create_tables')) {
            // Self-heal: the ves_usage_events table is missing or outdated on this
            // host, which made record_event() fail and blocked every non-admin
            // reserve_usage() (usage_event_failed). Recreate the table and retry once
            // so reservations work instead of hard-failing.
            self::create_tables();
            $wpdb->insert(self::table_name(), $ves_row, $ves_fmt);
        }
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public static function get_recent_events($user_id, $limit = 12) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $limit = max(1, min(50, (int) $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d',
                $user_id,
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? array_map([__CLASS__, 'normalize_event_row'], $rows) : [];
    }

    public static function get_summary($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [
                'user_id' => 0,
                'balance' => 0,
                'lifetime_spent' => 0,
                'events_count' => 0,
                'recent_events' => [],
            ];
        }
        $balance = self::get_balance($user_id);
        $spent = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT ABS(COALESCE(SUM(credits_delta),0)) FROM " . self::table_name() . " WHERE user_id = %d AND credits_delta < 0 AND status IN ('success','posted')",
            $user_id
        ));
        $events_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE user_id = %d',
            $user_id
        ));
        $denied_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE user_id = %d AND status = %s',
            $user_id,
            'denied'
        ));
        $plan = self::get_user_plan($user_id);
        $usage = self::get_current_period_usage($user_id);
        $limits = self::get_plan_limits_for_user($user_id);
        return [
            'user_id' => $user_id,
            'balance' => round($balance, 2),
            'lifetime_spent' => round($spent, 2),
            'events_count' => $events_count,
            'denied_count' => $denied_count,
            'plan' => $plan,
            'period_usage' => $usage,
            'period_limits' => $limits,
            'active_monitor_count' => self::get_active_monitor_count_for_user($user_id),
            'max_active_monitors' => max(0, (int) ($plan['max_active_monitors'] ?? 0)),
            'credit_packs' => array_values(self::get_credit_packs()),
            'topup_requests' => self::get_user_topup_requests($user_id, 8),
            'recent_events' => self::get_recent_events($user_id, 10),
        ];
    }

    private static function normalize_event_row($row) {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'event_type' => sanitize_key((string) ($row['event_type'] ?? '')),
            'credits_delta' => round((float) ($row['credits_delta'] ?? 0), 2),
            'balance_after' => round((float) ($row['balance_after'] ?? 0), 2),
            'status' => sanitize_key((string) ($row['status'] ?? 'success')),
            'message' => sanitize_text_field((string) ($row['message'] ?? '')),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
        ];
    }

    public static function create_topup_request($user_id, $pack_id, $user_note = '') {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Debes iniciar sesión para solicitar una recarga.');
        }
        $pack = self::get_credit_pack($pack_id);
        if (!$pack || (($pack['status'] ?? 'inactive') !== 'active')) {
            return new WP_Error('invalid_pack', 'El pack solicitado no está disponible.');
        }
        $pending = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::topup_table_name() . ' WHERE user_id = %d AND status = %s',
            $user_id,
            'pending'
        ));
        if ($pending >= 5) {
            return new WP_Error('too_many_pending', 'Ya tienes demasiadas solicitudes pendientes. Espera a que se resuelvan.');
        }
        $wpdb->insert(
            self::topup_table_name(),
            [
                'user_id' => $user_id,
                'pack_id' => sanitize_key((string) $pack['id']),
                'pack_label' => sanitize_text_field((string) $pack['label']),
                'credits' => round((float) $pack['credits'], 2),
                'price_label' => sanitize_text_field((string) ($pack['price_label'] ?? '')),
                'status' => 'pending',
                'user_note' => sanitize_textarea_field((string) $user_note),
                'admin_note' => '',
                'requested_at' => current_time('mysql', true),
                'decided_at' => null,
                'approved_by' => 0,
            ],
            ['%d','%s','%s','%f','%s','%s','%s','%s','%s','%s','%d']
        );
        $request_id = (int) $wpdb->insert_id;
        self::record_event($user_id, 'topup_request', 0, self::get_balance($user_id), 'success', 'Solicitud de recarga enviada', [
            'request_id' => $request_id,
            'pack_id' => sanitize_key((string) $pack['id']),
            'credits' => (float) $pack['credits'],
        ]);
        return $request_id;
    }

    public static function get_user_topup_requests($user_id, $limit = 10) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }
        $limit = max(1, min(50, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::topup_table_name() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            $user_id,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? array_map([__CLASS__, 'normalize_topup_row'], $rows) : [];
    }

    public static function get_topup_requests_admin($limit = 50, $status = '') {
        global $wpdb;
        $limit = max(1, min(200, (int) $limit));
        if ($status !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::topup_table_name() . ' WHERE status = %s ORDER BY id DESC LIMIT %d',
                sanitize_key((string) $status),
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::topup_table_name() . ' ORDER BY id DESC LIMIT %d',
                $limit
            ), ARRAY_A);
        }
        return is_array($rows) ? array_map([__CLASS__, 'normalize_topup_row'], $rows) : [];
    }

    public static function get_topup_request($request_id) {
        global $wpdb;
        $request_id = (int) $request_id;
        if ($request_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::topup_table_name() . ' WHERE id = %d', $request_id), ARRAY_A);
        return is_array($row) ? self::normalize_topup_row($row) : null;
    }

    private static function normalize_topup_row($row) {
        $user_id = (int) ($row['user_id'] ?? 0);
        $user = $user_id > 0 ? get_userdata($user_id) : null;
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => $user_id,
            'user_display' => $user instanceof WP_User ? $user->display_name : ('User #' . $user_id),
            'user_email' => $user instanceof WP_User ? $user->user_email : '',
            'pack_id' => sanitize_key((string) ($row['pack_id'] ?? '')),
            'pack_label' => sanitize_text_field((string) ($row['pack_label'] ?? '')),
            'credits' => round((float) ($row['credits'] ?? 0), 2),
            'price_label' => sanitize_text_field((string) ($row['price_label'] ?? '')),
            'status' => sanitize_key((string) ($row['status'] ?? 'pending')),
            'user_note' => sanitize_textarea_field((string) ($row['user_note'] ?? '')),
            'admin_note' => sanitize_textarea_field((string) ($row['admin_note'] ?? '')),
            'requested_at' => sanitize_text_field((string) ($row['requested_at'] ?? '')),
            'decided_at' => sanitize_text_field((string) ($row['decided_at'] ?? '')),
            'approved_by' => (int) ($row['approved_by'] ?? 0),
        ];
    }

    public static function approve_topup_request($request_id, $admin_note = '') {
        global $wpdb;
        $request_id = (int) $request_id;
        if ($request_id <= 0) {
            return new WP_Error('missing_request', 'No se encontró la solicitud.');
        }
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::topup_table_name() . " SET status = 'processing', admin_note = %s, decided_at = %s, approved_by = %d WHERE id = %d AND status = 'pending'",
            sanitize_textarea_field((string) $admin_note),
            current_time('mysql', true),
            get_current_user_id(),
            $request_id
        ));
        if ((int) $claimed !== 1) {
            return new WP_Error('invalid_status', 'La solicitud ya fue procesada.');
        }
        $request = self::get_topup_request($request_id);
        if (!$request) {
            return new WP_Error('missing_request', 'No se encontró la solicitud.');
        }
        $new_balance = self::adjust_user_credits((int) $request['user_id'], (float) $request['credits'], 'Top-up pack aprobado', [
            'request_id' => (int) $request['id'],
            'pack_id' => sanitize_key((string) $request['pack_id']),
            'approved_by' => get_current_user_id(),
            'usage_key' => 'topup:' . (int) $request['id'],
        ]);
        if (is_wp_error($new_balance)) {
            $wpdb->update(self::topup_table_name(), ['status' => 'pending'], ['id' => $request_id], ['%s'], ['%d']);
            return $new_balance;
        }
        $wpdb->update(
            self::topup_table_name(),
            [
                'status' => 'approved',
                'admin_note' => sanitize_textarea_field((string) $admin_note),
                'decided_at' => current_time('mysql', true),
                'approved_by' => get_current_user_id(),
            ],
            [ 'id' => (int) $request['id'] ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );
        return self::get_topup_request($request_id);
    }

    public static function reject_topup_request($request_id, $admin_note = '') {
        global $wpdb;
        $request = self::get_topup_request($request_id);
        if (!$request) {
            return new WP_Error('missing_request', 'No se encontró la solicitud.');
        }
        if (($request['status'] ?? '') !== 'pending') {
            return new WP_Error('invalid_status', 'La solicitud ya fue procesada.');
        }
        $wpdb->update(
            self::topup_table_name(),
            [
                'status' => 'rejected',
                'admin_note' => sanitize_textarea_field((string) $admin_note),
                'decided_at' => current_time('mysql', true),
                'approved_by' => get_current_user_id(),
            ],
            [ 'id' => (int) $request['id'] ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );
        self::record_event((int) $request['user_id'], 'topup_rejected', 0, self::get_balance((int) $request['user_id']), 'success', 'Solicitud de recarga rechazada', [
            'request_id' => (int) $request['id'],
            'pack_id' => sanitize_key((string) $request['pack_id']),
            'approved_by' => get_current_user_id(),
        ]);
        return self::get_topup_request($request_id);
    }

    private static function redirect_notice($base, $type, $message) {
        // v0.9.8.0: store notice in a server-side transient + httponly cookie
        // token instead of exposing the message in the URL (anti-phishing).
        self::flash_notice($type, $message);
        wp_safe_redirect($base);
        exit;
    }

    public static function handle_topup_request() {
        if (!is_user_logged_in()) {
            wp_die('No autorizado.');
        }
        if (!isset($_POST['ves_topup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ves_topup_nonce'])), 'ves_request_topup')) {
            self::redirect_notice(class_exists('VES_Auth') ? VES_Auth::get_dashboard_url() : home_url('/'), 'error', 'La sesión del formulario ha caducado.');
        }
        $user_id = get_current_user_id();
        $pack_id = sanitize_key((string) wp_unslash($_POST['pack_id'] ?? ''));
        $note = sanitize_textarea_field((string) wp_unslash($_POST['user_note'] ?? ''));
        $created = self::create_topup_request($user_id, $pack_id, $note);
        if (is_wp_error($created)) {
            self::redirect_notice(class_exists('VES_Auth') ? VES_Auth::get_dashboard_url() : home_url('/'), 'error', $created->get_error_message());
        }
        self::redirect_notice(class_exists('VES_Auth') ? VES_Auth::get_dashboard_url() : home_url('/'), 'success', 'Solicitud de recarga enviada. Un administrador la revisará.');
    }

    public static function handle_approve_topup() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('ves_approve_topup');
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $admin_note = sanitize_textarea_field((string) wp_unslash($_POST['admin_note'] ?? ''));
        $result = self::approve_topup_request($request_id, $admin_note);
        if (is_wp_error($result)) {
            self::redirect_notice(admin_url('options-general.php?page=ves-billing-requests'), 'error', $result->get_error_message());
        }
        self::redirect_notice(admin_url('options-general.php?page=ves-billing-requests'), 'success', 'Solicitud aprobada y créditos añadidos.');
    }

    public static function handle_reject_topup() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('ves_reject_topup');
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $admin_note = sanitize_textarea_field((string) wp_unslash($_POST['admin_note'] ?? ''));
        $result = self::reject_topup_request($request_id, $admin_note);
        if (is_wp_error($result)) {
            self::redirect_notice(admin_url('options-general.php?page=ves-billing-requests'), 'error', $result->get_error_message());
        }
        self::redirect_notice(admin_url('options-general.php?page=ves-billing-requests'), 'success', 'Solicitud rechazada.');
    }

    private static function render_notice_from_query() {
        // v0.9.8.0: same notice-spoofing hardening as VES_Auth — accept a one-shot
        // cookie token and require a server-side transient. Bare URL params no
        // longer render arbitrary "official" messages.
        $token = '';
        if (!empty($_COOKIE['ves_billing_notice_' . COOKIEHASH])) {
            $token = sanitize_key((string) wp_unslash($_COOKIE['ves_billing_notice_' . COOKIEHASH]));
        }
        if ($token === '') {
            return '';
        }
        setcookie(
            'ves_billing_notice_' . COOKIEHASH,
            '',
            time() - 3600,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        $payload = get_transient('ves_billing_notice_' . $token);
        delete_transient('ves_billing_notice_' . $token);
        if (!is_array($payload)) {
            return '';
        }
        $type = sanitize_key((string) ($payload['type'] ?? ''));
        $message = sanitize_text_field((string) ($payload['message'] ?? ''));
        if ($type === '' || $message === '') {
            return '';
        }
        $class = $type === 'success' ? 'ves-auth-notice is-success' : 'ves-auth-notice is-error';
        return '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    public static function flash_notice($type, $message, $ttl_seconds = 300) {
        $token = wp_generate_password(24, false, false);
        set_transient('ves_billing_notice_' . $token, [
            'type' => sanitize_key((string) $type),
            'message' => sanitize_text_field((string) $message),
        ], max(60, (int) $ttl_seconds));
        setcookie(
            'ves_billing_notice_' . COOKIEHASH,
            $token,
            [
                'expires' => time() + max(60, (int) $ttl_seconds),
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    public static function render_notice_for_admin() {
        if (empty($_COOKIE['ves_billing_notice_' . COOKIEHASH])) {
            return '';
        }
        $token = sanitize_key((string) wp_unslash($_COOKIE['ves_billing_notice_' . COOKIEHASH]));
        if ($token === '') {
            return '';
        }
        setcookie(
            'ves_billing_notice_' . COOKIEHASH,
            '',
            time() - 3600,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        $payload = get_transient('ves_billing_notice_' . $token);
        delete_transient('ves_billing_notice_' . $token);
        if (!is_array($payload)) {
            return '';
        }
        $type = sanitize_key((string) ($payload['type'] ?? ''));
        $message = sanitize_text_field((string) ($payload['message'] ?? ''));
        if ($type === '' || $message === '') {
            return '';
        }
        $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';
        return '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private static function event_label($event_type) {
        $map = [
            'manual_run' => 'Runs manuales',
            'ai_analysis' => 'Análisis AI',
            'trend_run' => 'Trend Finder',
            'trend_brief' => 'Briefs creados',
            'background_monitor' => 'Monitores automáticos',
            'brand_audit_light' => 'Brand Audit Light',
            'brand_audit_standard' => 'Brand Audit Standard',
            'brand_audit_deep' => 'Brand Audit Deep',
        ];
        return $map[$event_type] ?? $event_type;
    }

    public static function render_account_summary($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : (is_user_logged_in() ? get_current_user_id() : 0);
        if ($user_id <= 0) {
            return '';
        }
        // v0.9.30.19: a broken/missing usage table must not blank the whole panel
        // or bubble up as a generic AJAX fatal. Degrade to a visible, honest
        // "balance unavailable" card and log for admin review.
        try {
            $summary = self::get_summary($user_id);
        } catch (Throwable $summary_error) {
            if (class_exists('VES_Admin')) {
                try { VES_Admin::record_diagnostic('usage_summary_degraded', $summary_error->getMessage(), ['stage' => 'render_account_summary', 'user_id' => $user_id]); } catch (Throwable $ignored) {}
            }
            // v0.9.30.22: admin/unlimited users should always see ∞, not — even on billing error.
            $fallback_balance = (function_exists('current_user_can') && current_user_can('manage_options')) ? '∞' : '—';
            return '<div class="ves-account-summary" data-dynamic="1"><div class="ves-card ves-account-card"><div class="ves-account-kpis"><div class="ves-kpi"><small>Créditos disponibles</small><strong>' . esc_html($fallback_balance) . '</strong></div></div><p class="ves-meta">No se pudo cargar el resumen de créditos ahora mismo. Un administrador puede revisar Diagnostics.</p></div></div>';
        }
        if (!is_array($summary)) { $summary = []; }
        $summary += ['balance' => 0, 'lifetime_spent' => 0, 'active_monitor_count' => 0, 'max_active_monitors' => 0, 'plan' => [], 'period_limits' => [], 'period_usage' => []];
        $user = get_userdata($user_id);
        $display = $user instanceof WP_User ? $user->display_name : 'Usuario';
        $plan = is_array($summary['plan'] ?? null) ? $summary['plan'] : [];
        $plans = self::get_available_plans();
        // v0.9.9.6: unlimited admins see "∞" instead of a synthetic balance.
        $is_unlimited = self::is_unlimited_user($user_id);
        $balance_display = $is_unlimited ? '∞' : number_format_i18n((float) $summary['balance'], 2);
        ob_start();
        ?>
        <div class="ves-account-summary" data-dynamic="1">
            <div class="ves-card ves-account-card">
                <?php echo self::render_notice_from_query(); ?>
                <div class="ves-account-head">
                    <div>
                        <h3 class="ves-item-panel-title" style="margin:0">Mi panel SaaS</h3>
                        <div class="ves-meta"><?php
                            if ($is_unlimited) {
                                echo 'Hola, ' . esc_html($display) . '. <strong>Modo administrador con créditos ilimitados.</strong>';
                            } else {
                                echo 'Hola, ' . esc_html($display) . '. Este resumen es por usuario.';
                            }
                        ?></div>
                    </div>
                    <button type="button" class="ves-btn ves-btn-secondary ves-refresh-usage">Actualizar créditos</button>
                </div>
                <div class="ves-account-kpis">
                    <div class="ves-kpi"><small>Créditos disponibles</small><strong><?php echo esc_html($balance_display); ?></strong></div>
                    <div class="ves-kpi"><small>Plan actual</small><strong><?php echo esc_html($is_unlimited ? 'Admin ∞' : (string) ($plan['label'] ?? '—')); ?></strong></div>
                    <div class="ves-kpi"><small>Créditos consumidos</small><strong><?php echo esc_html(number_format_i18n((float) $summary['lifetime_spent'], 2)); ?></strong></div>
                    <div class="ves-kpi"><small>Monitores activos</small><strong><?php echo esc_html((string) ((int) $summary['active_monitor_count'] . '/' . ($is_unlimited ? '∞' : (string) (int) $summary['max_active_monitors']))); ?></strong></div>
                </div>

                <div class="ves-plan-current-grid">
                    <div class="ves-plan-current-card">
                        <div class="ves-trend-mini-title">Plan actual</div>
                        <h4><?php echo esc_html((string) ($plan['label'] ?? 'Plan')); ?></h4>
                        <p class="ves-meta" style="margin-top:6px"><?php echo esc_html((string) ($plan['description'] ?? '')); ?></p>
                        <div class="ves-plan-chip-row">
                            <span class="ves-trend-badge"><?php echo esc_html(number_format_i18n((float) ($plan['monthly_credits'] ?? 0), 0)); ?> créditos/mes</span>
                            <span class="ves-trend-badge ok"><?php echo esc_html((string) ((int) $summary['active_monitor_count'] . '/' . (int) $summary['max_active_monitors'])); ?> monitores</span>
                        </div>
                    </div>
                    <div class="ves-plan-usage-card">
                        <div class="ves-trend-mini-title">Uso del periodo</div>
                        <div class="ves-plan-usage-list">
                            <?php foreach ((array) $summary['period_limits'] as $event_type => $limit) :
                                $used = (int) ($summary['period_usage'][$event_type] ?? 0);
                                $pct = $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0;
                            ?>
                                <div class="ves-plan-usage-row">
                                    <div class="ves-plan-usage-label"><?php echo esc_html(self::event_label($event_type)); ?></div>
                                    <div class="ves-plan-usage-metrics"><?php echo esc_html((string) $used . '/' . (string) (int) $limit); ?></div>
                                    <div class="ves-plan-usage-bar"><span style="width:<?php echo esc_attr((string) $pct); ?>%"></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="ves-plan-catalog-grid">
                    <?php foreach ($plans as $catalog_plan) : ?>
                        <div class="ves-plan-catalog-card <?php echo (string) ($catalog_plan['slug'] ?? '') === (string) ($plan['slug'] ?? '') ? 'is-current' : ''; ?>">
                            <div class="ves-trend-mini-title"><?php echo esc_html((string) ($catalog_plan['label'] ?? 'Plan')); ?></div>
                            <div class="ves-meta" style="margin:6px 0 10px"><?php echo esc_html((string) ($catalog_plan['description'] ?? '')); ?></div>
                            <div class="ves-plan-chip-row">
                                <span class="ves-trend-badge"><?php echo esc_html(number_format_i18n((float) ($catalog_plan['monthly_credits'] ?? 0), 0)); ?> créditos</span>
                                <span class="ves-trend-badge warn"><?php echo esc_html((string) (int) ($catalog_plan['max_active_monitors'] ?? 0)); ?> monitores</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ves-account-topups-grid">
                    <div class="ves-account-topups-card">
                        <div class="ves-item-panel-title" style="font-size:14px">Credit packs</div>
                        <div class="ves-pack-grid">
                            <?php foreach ((array) $summary['credit_packs'] as $pack) : ?>
                                <div class="ves-pack-card">
                                    <div class="ves-trend-mini-title"><?php echo esc_html((string) ($pack['label'] ?? 'Pack')); ?></div>
                                    <strong style="font-size:20px"><?php echo esc_html(number_format_i18n((float) ($pack['credits'] ?? 0), 0)); ?> créditos</strong>
                                    <div class="ves-meta"><?php echo esc_html((string) ($pack['price_label'] ?? 'Manual')); ?></div>
                                    <p class="ves-meta" style="margin-top:6px"><?php echo esc_html((string) ($pack['description'] ?? '')); ?></p>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px">
                                        <input type="hidden" name="action" value="ves_request_topup">
                                        <input type="hidden" name="pack_id" value="<?php echo esc_attr((string) ($pack['id'] ?? '')); ?>">
                                        <?php wp_nonce_field('ves_request_topup', 'ves_topup_nonce'); ?>
                                        <button type="submit" class="ves-btn ves-btn-primary" style="width:100%">Solicitar pack</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="ves-hint" style="margin-top:12px">Las recargas son manuales: tú solicitas el pack y un administrador lo aprueba desde WordPress.</div>
                    </div>
                    <div class="ves-account-topups-card">
                        <div class="ves-item-panel-title" style="font-size:14px">Solicitudes recientes</div>
                        <?php if (empty($summary['topup_requests'])) : ?>
                            <div class="ves-empty" style="margin-top:10px">Todavía no has solicitado ningún pack.</div>
                        <?php else : ?>
                            <div class="ves-account-events-list" style="margin-top:10px">
                                <?php foreach ((array) $summary['topup_requests'] as $request) : ?>
                                    <div class="ves-account-event">
                                        <div>
                                            <strong><?php echo esc_html((string) ($request['pack_label'] ?? 'Pack')); ?></strong>
                                            <div class="ves-meta"><?php echo esc_html((string) ($request['requested_at'] ?? '')); ?></div>
                                        </div>
                                        <div class="ves-account-event-metrics">
                                            <span class="ves-trend-badge <?php echo esc_attr((string) (($request['status'] ?? '') === 'approved' ? 'ok' : (($request['status'] ?? '') === 'rejected' ? 'warn' : ''))); ?>"><?php echo esc_html((string) ($request['status'] ?? 'pending')); ?></span>
                                            <span class="ves-meta"><?php echo esc_html(number_format_i18n((float) ($request['credits'] ?? 0), 0)); ?> créditos</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ves-account-events">
                    <div class="ves-item-panel-title" style="font-size:14px">Actividad reciente</div>
                    <?php if (empty($summary['recent_events'])) : ?>
                        <div class="ves-empty" style="margin-top:10px">Todavía no hay actividad registrada para esta cuenta.</div>
                    <?php else : ?>
                        <div class="ves-account-events-list">
                            <?php foreach ($summary['recent_events'] as $event) : ?>
                                <div class="ves-account-event <?php echo esc_attr($event['status']); ?>">
                                    <div>
                                        <strong><?php echo esc_html($event['message'] !== '' ? $event['message'] : $event['event_type']); ?></strong>
                                        <div class="ves-meta"><?php echo esc_html($event['created_at']); ?> · <?php echo esc_html($event['event_type']); ?></div>
                                    </div>
                                    <div class="ves-account-event-metrics">
                                        <span class="ves-trend-badge <?php echo $event['credits_delta'] < 0 ? 'warn' : 'ok'; ?>"><?php echo esc_html(($event['credits_delta'] > 0 ? '+' : '') . number_format_i18n((float) $event['credits_delta'], 2)); ?></span>
                                        <span class="ves-meta">Saldo: <?php echo esc_html(number_format_i18n((float) $event['balance_after'], 2)); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_profile_fields($user) {
        if (!$user instanceof WP_User || !current_user_can('edit_users')) {
            return;
        }
        $balance = self::get_balance((int) $user->ID);
        $current_plan = self::get_user_plan_slug((int) $user->ID);
        ?>
        <h2>VE SaaS credits</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ves_plan_slug">Plan actual</label></th>
                <td>
                    <select name="ves_plan_slug" id="ves_plan_slug">
                        <?php foreach (self::get_plan_options() as $slug => $label) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($current_plan, $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">El plan define límites mensuales y monitores activos máximos.</p>
                </td>
            </tr>
            <tr>
                <th><label for="ves_credit_balance">Créditos disponibles</label></th>
                <td>
                    <input type="number" step="0.01" min="0" name="ves_credit_balance" id="ves_credit_balance" value="<?php echo esc_attr((string) $balance); ?>" class="regular-text">
                    <p class="description">Puedes ajustar el saldo manualmente. El cambio quedará registrado en el histórico de uso.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_profile_fields($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
            return;
        }
        if (isset($_POST['ves_plan_slug'])) {
            $plan_slug = sanitize_key((string) wp_unslash($_POST['ves_plan_slug']));
            self::set_user_plan($user_id, $plan_slug, true);
        }
        if (!isset($_POST['ves_credit_balance'])) {
            return;
        }
        $new_balance = max(0, (float) wp_unslash($_POST['ves_credit_balance']));
        $old_balance = self::get_balance($user_id);
        if (abs($new_balance - $old_balance) < 0.0001) {
            return;
        }
        update_user_meta($user_id, self::BALANCE_META_KEY, $new_balance);
        self::record_event($user_id, 'admin_adjustment', round($new_balance - $old_balance, 2), $new_balance, 'success', 'Ajuste manual desde perfil', [
            'edited_by' => get_current_user_id(),
        ]);
    }
}
