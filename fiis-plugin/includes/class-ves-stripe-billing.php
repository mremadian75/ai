<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Stripe_Billing — opt-in, SDK-free Stripe monetization layer.
 *
 * Turns the existing credit ledger into a self-serve revenue path: a logged-in
 * user buys a credit pack through Stripe Checkout, Stripe calls our webhook, and
 * we idempotently grant the purchased credits via VES_Usage_Billing. The whole
 * subsystem is INERT until an operator pastes a Stripe secret key — no key, no
 * routes do anything, no behavior changes. There is no Composer/Stripe SDK
 * dependency: we talk to the Stripe REST API directly with wp_remote_post and
 * verify webhooks with a hand-rolled HMAC check, so the plugin stays a drop-in
 * ZIP.
 *
 * Design guarantees:
 *   - Inert by default. is_enabled() is false unless explicitly turned on AND a
 *     secret key is present.
 *   - Test/live aware. The mode is derived from the key prefix (sk_test_ / sk_live_)
 *     and surfaced in the admin UI so an operator can validate in test mode first
 *     before flipping to live — exactly the "validate before going live" caution.
 *   - Idempotent grants. Every Stripe event id is recorded; a redelivered event
 *     never double-grants. The actual balance write goes through the per-user
 *     GET_LOCK mutex in adjust_user_credits(), so concurrent deliveries serialize.
 *   - Signature-verified webhook. The raw body is HMAC-SHA256'd against the
 *     webhook signing secret with a replay-tolerance window before we trust it.
 *   - Lago-ready. grant_credits() is provider-agnostic; a Lago adapter can reuse
 *     the same idempotent grant path without touching the ledger.
 */
final class VES_Stripe_Billing {
    const OPTION = 'ves_stripe_settings';
    const PROCESSED_EVENTS_OPTION = 'ves_stripe_processed_events';
    const EVENTS_TABLE_SLUG = 'ves_stripe_events';
    const EVENTS_DB_VERSION = '1';
    const EVENTS_DB_OPTION = 'ves_stripe_events_db_version';
    const REST_NAMESPACE = 'ves/v1';
    const CHECKOUT_ACTION = 'ves_stripe_checkout';
    const SUBSCRIBE_ACTION = 'ves_stripe_subscribe';
    const PORTAL_ACTION = 'ves_stripe_portal';
    const SAVE_SETTINGS_ACTION = 'ves_save_stripe_settings';
    const CUSTOMER_META = 'ves_stripe_customer_id';
    const API_BASE = 'https://api.stripe.com/v1';
    const SIGNATURE_TOLERANCE = 300; // seconds; Stripe's recommended replay window

    public static function register() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_' . self::CHECKOUT_ACTION, [__CLASS__, 'handle_checkout']);
        add_action('admin_post_' . self::SUBSCRIBE_ACTION, [__CLASS__, 'handle_subscribe']);
        add_action('admin_post_' . self::PORTAL_ACTION, [__CLASS__, 'handle_portal']);
        add_action('admin_post_' . self::SAVE_SETTINGS_ACTION, [__CLASS__, 'handle_save_settings']);
        add_shortcode('ves_usage_dashboard', [__CLASS__, 'render_usage_dashboard']);
        // Async webhook worker (used when Action Scheduler is available).
        add_action('ves_stripe_process_event', [__CLASS__, 'process_event_async'], 10, 1);
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public static function settings() {
        $defaults = [
            'enabled' => 0,
            'secret_key' => '',
            'webhook_secret' => '',
            'pack_prices' => [], // pack_id => integer cents (EUR); falls back to price_label
            'currency' => 'eur',
            'success_url' => '',
            'cancel_url' => '',
            // Recurring subscriptions: map a Stripe Price id to a plan slug + the
            // monthly credit allotment granted on each paid invoice.
            'subscriptions_enabled' => 0,
            'plan_prices' => [], // stripe_price_id => ['plan_slug'=>..,'monthly_credits'=>int,'label'=>..]
            'portal_configuration' => '', // optional Stripe billing-portal config id (bpc_...)
        ];
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) { $saved = []; }
        return array_merge($defaults, $saved);
    }

    public static function is_enabled() {
        $s = self::settings();
        return !empty($s['enabled']) && self::secret_key() !== '';
    }

    public static function secret_key() {
        // Allow the key to live in wp-config.php (constant) so it never sits in
        // the options table on security-sensitive installs.
        if (defined('VES_STRIPE_SECRET_KEY') && VES_STRIPE_SECRET_KEY) {
            return (string) VES_STRIPE_SECRET_KEY;
        }
        $s = self::settings();
        return trim((string) ($s['secret_key'] ?? ''));
    }

    public static function webhook_secret() {
        if (defined('VES_STRIPE_WEBHOOK_SECRET') && VES_STRIPE_WEBHOOK_SECRET) {
            return (string) VES_STRIPE_WEBHOOK_SECRET;
        }
        $s = self::settings();
        return trim((string) ($s['webhook_secret'] ?? ''));
    }

    /** 'test' | 'live' | 'unknown' — inferred from the secret key prefix. */
    public static function mode() {
        $key = self::secret_key();
        if (strpos($key, 'sk_test_') === 0 || strpos($key, 'rk_test_') === 0) { return 'test'; }
        if (strpos($key, 'sk_live_') === 0 || strpos($key, 'rk_live_') === 0) { return 'live'; }
        return 'unknown';
    }

    public static function currency() {
        $s = self::settings();
        $c = strtolower(trim((string) ($s['currency'] ?? 'eur')));
        return preg_match('/^[a-z]{3}$/', $c) ? $c : 'eur';
    }

    /**
     * Price for a pack in the smallest currency unit (cents). Operators can set an
     * explicit price; otherwise we parse the human price_label (e.g. "€15" -> 1500)
     * so the foundation works out of the box without per-pack configuration.
     */
    public static function pack_price_cents($pack) {
        $pack = is_array($pack) ? $pack : [];
        $pack_id = sanitize_key((string) ($pack['id'] ?? ''));
        $s = self::settings();
        $configured = $s['pack_prices'][$pack_id] ?? null;
        if ($configured !== null && (int) $configured > 0) {
            return (int) $configured;
        }
        // Derive from the price_label: keep digits, comma/period as decimal.
        $label = (string) ($pack['price_label'] ?? '');
        if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/', $label, $m)) {
            $amount = (float) str_replace(',', '.', $m[1]);
            return (int) round($amount * 100);
        }
        return 0;
    }

    // ── Subscriptions ────────────────────────────────────────────────────────

    public static function subscriptions_enabled() {
        $s = self::settings();
        return self::is_enabled() && !empty($s['subscriptions_enabled']) && !empty(self::plan_prices());
    }

    /** Configured Stripe Price → plan map: [price_id => [plan_slug, monthly_credits, label]]. */
    public static function plan_prices() {
        $s = self::settings();
        $map = is_array($s['plan_prices'] ?? null) ? $s['plan_prices'] : [];
        $out = [];
        foreach ($map as $price_id => $row) {
            $price_id = trim((string) $price_id);
            if ($price_id === '' || !is_array($row)) { continue; }
            $slug = sanitize_key((string) ($row['plan_slug'] ?? ''));
            if ($slug === '') { continue; }
            $out[$price_id] = [
                'plan_slug' => $slug,
                'monthly_credits' => max(0, (int) ($row['monthly_credits'] ?? 0)),
                'label' => sanitize_text_field((string) ($row['label'] ?? $slug)),
            ];
        }
        return $out;
    }

    /** Resolve a plan definition from a Stripe Price id, or null if unmapped. */
    public static function resolve_plan_for_price($price_id) {
        $price_id = trim((string) $price_id);
        if ($price_id === '') { return null; }
        $map = self::plan_prices();
        return $map[$price_id] ?? null;
    }

    /** Persist the Stripe customer ↔ WP user link so subscription events resolve. */
    public static function link_customer($user_id, $customer_id) {
        $user_id = (int) $user_id;
        $customer_id = sanitize_text_field((string) $customer_id);
        if ($user_id <= 0 || $customer_id === '' || !function_exists('update_user_meta')) { return; }
        update_user_meta($user_id, self::CUSTOMER_META, $customer_id);
    }

    /** The Stripe customer id linked to a WP user, or '' if none. */
    public static function customer_id_for_user($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !function_exists('get_user_meta')) { return ''; }
        return trim((string) get_user_meta($user_id, self::CUSTOMER_META, true));
    }

    /** True when a self-serve Billing Portal link can be offered to this user. */
    public static function portal_available($user_id) {
        return self::is_enabled() && self::customer_id_for_user($user_id) !== '';
    }

    /** Reverse lookup: which WP user owns a Stripe customer id. 0 if unknown. */
    public static function user_id_for_customer($customer_id) {
        $customer_id = sanitize_text_field((string) $customer_id);
        if ($customer_id === '' || !function_exists('get_users')) { return 0; }
        $ids = get_users([
            'meta_key' => self::CUSTOMER_META,
            'meta_value' => $customer_id,
            'fields' => 'ID',
            'number' => 1,
        ]);
        if (is_array($ids) && !empty($ids)) {
            $first = $ids[0];
            return (int) (is_object($first) ? ($first->ID ?? 0) : $first);
        }
        return 0;
    }

    // ── REST routes ──────────────────────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/stripe/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_webhook'],
            'permission_callback' => '__return_true', // public; verified by signature
        ]);
        register_rest_route(self::REST_NAMESPACE, '/usage/summary', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'rest_usage_summary'],
            'permission_callback' => [__CLASS__, 'logged_in_permission'],
        ]);
    }

    public static function logged_in_permission() {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) { return true; }
        return new WP_Error('ves_auth_required', 'Login required.', ['status' => 401]);
    }

    /** Current user's billing summary, for the in-app usage dashboard. */
    public static function rest_usage_summary($request) {
        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('ves_auth_required', 'Login required.', ['status' => 401]);
        }
        $summary = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_summary($user_id) : [];
        $summary['stripe'] = [
            'enabled' => self::is_enabled(),
            'mode' => self::mode(),
            'checkout_url' => self::is_enabled() && function_exists('admin_url') ? admin_url('admin-post.php') : '',
        ];
        $summary['is_unlimited'] = class_exists('VES_Usage_Billing') && VES_Usage_Billing::is_unlimited_user($user_id);
        return rest_ensure_response($summary);
    }

    // ── Checkout (server-side redirect, no JS required) ───────────────────────

    public static function handle_checkout() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            self::redirect_back('error', 'Inicia sesión para comprar créditos.');
        }
        $user_id = (int) get_current_user_id();
        $pack_id = isset($_POST['pack_id']) ? sanitize_key(wp_unslash($_POST['pack_id'])) : '';
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::CHECKOUT_ACTION . '_' . $pack_id);
        }
        if (!self::is_enabled()) {
            self::redirect_back('error', 'La compra con tarjeta no está disponible todavía.');
        }
        if (!class_exists('VES_Usage_Billing')) {
            self::redirect_back('error', 'Facturación no disponible.');
        }
        $pack = VES_Usage_Billing::get_credit_pack($pack_id);
        if (!$pack || ($pack['status'] ?? 'inactive') !== 'active') {
            self::redirect_back('error', 'El pack solicitado no está disponible.');
        }
        $cents = self::pack_price_cents($pack);
        if ($cents <= 0) {
            self::redirect_back('error', 'Este pack no tiene un precio configurado.');
        }

        $session = self::create_checkout_session($user_id, $pack, $cents);
        if (is_wp_error($session)) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_Stripe_Billing] checkout session error: ' . $session->get_error_message());
            }
            self::redirect_back('error', 'No se pudo iniciar el pago. Inténtalo de nuevo.');
        }
        $url = is_array($session) ? (string) ($session['url'] ?? '') : '';
        if ($url === '') {
            self::redirect_back('error', 'No se pudo iniciar el pago.');
        }
        if (function_exists('wp_redirect')) { wp_redirect($url); }
        exit;
    }

    public static function handle_subscribe() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            self::redirect_back('error', 'Inicia sesión para suscribirte.');
        }
        $user_id = (int) get_current_user_id();
        $price_id = isset($_POST['price_id']) ? sanitize_text_field(wp_unslash($_POST['price_id'])) : '';
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::SUBSCRIBE_ACTION . '_' . md5($price_id));
        }
        if (!self::subscriptions_enabled()) {
            self::redirect_back('error', 'Las suscripciones no están disponibles todavía.');
        }
        $plan = self::resolve_plan_for_price($price_id);
        if ($plan === null) {
            self::redirect_back('error', 'El plan solicitado no está disponible.');
        }
        $session = self::create_subscription_session($user_id, $price_id, $plan);
        if (is_wp_error($session)) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_Stripe_Billing] subscription session error: ' . $session->get_error_message());
            }
            self::redirect_back('error', 'No se pudo iniciar la suscripción. Inténtalo de nuevo.');
        }
        $url = is_array($session) ? (string) ($session['url'] ?? '') : '';
        if ($url === '') {
            self::redirect_back('error', 'No se pudo iniciar la suscripción.');
        }
        if (function_exists('wp_redirect')) { wp_redirect($url); }
        exit;
    }

    /**
     * Redirect the user to the Stripe-hosted Billing Portal for self-serve
     * management (update card, cancel/switch plan, download invoices, recover a
     * failed payment). All of that UI is owned by Stripe — we just mint a session.
     */
    public static function handle_portal() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            self::redirect_back('error', 'Inicia sesión para gestionar tu suscripción.');
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::PORTAL_ACTION);
        }
        $user_id = (int) get_current_user_id();
        if (!self::is_enabled()) {
            self::redirect_back('error', 'La gestión de facturación no está disponible.');
        }
        $customer = self::customer_id_for_user($user_id);
        if ($customer === '') {
            self::redirect_back('error', 'Aún no tienes una suscripción activa que gestionar.');
        }
        $session = self::create_portal_session($customer);
        if (is_wp_error($session)) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[VES_Stripe_Billing] portal session error: ' . $session->get_error_message());
            }
            self::redirect_back('error', 'No se pudo abrir el portal de facturación. Inténtalo de nuevo.');
        }
        $url = is_array($session) ? (string) ($session['url'] ?? '') : '';
        if ($url === '') {
            self::redirect_back('error', 'No se pudo abrir el portal de facturación.');
        }
        if (function_exists('wp_redirect')) { wp_redirect($url); }
        exit;
    }

    /**
     * Create a Stripe Billing Portal session for a customer.
     * @return array|WP_Error
     */
    public static function create_portal_session($customer_id) {
        $customer_id = trim((string) $customer_id);
        if ($customer_id === '') { return new WP_Error('ves_stripe_no_customer', 'No customer to manage.'); }
        $s = self::settings();
        $home = function_exists('home_url') ? home_url('/') : '';
        $return = (string) ($s['success_url'] ?? '') ?: $home;
        $body = ['customer' => $customer_id, 'return_url' => $return];
        // An explicit portal configuration id is optional; pass it through if set.
        $config = trim((string) ($s['portal_configuration'] ?? ''));
        if ($config !== '') { $body['configuration'] = $config; }
        return self::api_post('/billing_portal/sessions', $body);
    }

    /**
     * Create a Stripe Checkout Session in subscription mode for a mapped Price.
     * The user id is stamped on BOTH the session and the subscription metadata so
     * later invoice/subscription webhooks (which may not carry session metadata)
     * still resolve the WP user.
     * @return array|WP_Error
     */
    public static function create_subscription_session($user_id, $price_id, $plan) {
        $user_id = (int) $user_id;
        $price_id = trim((string) $price_id);
        $s = self::settings();
        $home = function_exists('home_url') ? home_url('/') : '';
        $success = (string) ($s['success_url'] ?? '') ?: $home;
        $cancel = (string) ($s['cancel_url'] ?? '') ?: $home;
        $success = add_query_arg(['ves_purchase' => 'success'], $success);
        if (strpos($success, 'session_id=') === false) {
            $success .= (strpos($success, '?') === false ? '?' : '&') . 'session_id={CHECKOUT_SESSION_ID}';
        }
        $cancel = add_query_arg(['ves_purchase' => 'cancel'], $cancel);

        $meta = [
            'ves_user_id' => (string) $user_id,
            'ves_plan_slug' => (string) ($plan['plan_slug'] ?? ''),
        ];
        $body = [
            'mode' => 'subscription',
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => (string) $user_id,
            'line_items' => [[ 'price' => $price_id, 'quantity' => 1 ]],
            'metadata' => $meta,
            'subscription_data' => [ 'metadata' => $meta ],
        ];
        $email = '';
        if (function_exists('get_userdata')) {
            $u = get_userdata($user_id);
            if ($u && !empty($u->user_email)) { $email = (string) $u->user_email; }
        }
        if ($email !== '') { $body['customer_email'] = $email; }

        $idem = 'ves_sub_' . $user_id . '_' . substr(md5($price_id . '|' . floor(time() / 60)), 0, 16);
        return self::api_post('/checkout/sessions', $body, $idem);
    }

    /**
     * Create a Stripe Checkout Session via the REST API (no SDK).
     * @return array|WP_Error decoded session on success
     */
    public static function create_checkout_session($user_id, $pack, $cents) {
        $user_id = (int) $user_id;
        $credits = (float) ($pack['credits'] ?? 0);
        $pack_id = sanitize_key((string) ($pack['id'] ?? ''));
        $name = sanitize_text_field((string) ($pack['label'] ?? ('Pack ' . $pack_id)));
        $home = function_exists('home_url') ? home_url('/') : '';
        $s = self::settings();
        $success = (string) ($s['success_url'] ?? '');
        $cancel = (string) ($s['cancel_url'] ?? '');
        if ($success === '') { $success = $home; }
        if ($cancel === '') { $cancel = $home; }

        // Carry the Stripe session id back on success for optional reconciliation.
        $success = add_query_arg(['ves_purchase' => 'success'], $success);
        $success = str_replace('%7B', '{', str_replace('%7D', '}', $success));
        if (strpos($success, 'session_id=') === false) {
            $success .= (strpos($success, '?') === false ? '?' : '&') . 'session_id={CHECKOUT_SESSION_ID}';
        }
        $cancel = add_query_arg(['ves_purchase' => 'cancel'], $cancel);

        $email = '';
        if (function_exists('get_userdata')) {
            $u = get_userdata($user_id);
            if ($u && !empty($u->user_email)) { $email = (string) $u->user_email; }
        }

        $body = [
            'mode' => 'payment',
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => (string) $user_id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => self::currency(),
                    'unit_amount' => (int) $cents,
                    'product_data' => [
                        'name' => $name . ' · ' . self::format_credits($credits) . ' créditos',
                    ],
                ],
            ]],
            'metadata' => [
                'ves_user_id' => (string) $user_id,
                'ves_pack_id' => $pack_id,
                'ves_credits' => self::format_credits($credits),
            ],
        ];
        if ($email !== '') { $body['customer_email'] = $email; }

        // Idempotency-Key makes a double-submitted checkout button safe at Stripe.
        $idem = 'ves_co_' . $user_id . '_' . $pack_id . '_' . substr(md5($user_id . '|' . $pack_id . '|' . floor(time() / 60)), 0, 16);
        return self::api_post('/checkout/sessions', $body, $idem);
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    public static function rest_webhook($request) {
        $payload = $request->get_body();
        $sig = '';
        if (method_exists($request, 'get_header')) {
            $sig = (string) $request->get_header('stripe-signature');
        }
        $secret = self::webhook_secret();
        if ($secret === '') {
            return new WP_Error('ves_stripe_unconfigured', 'Webhook not configured.', ['status' => 400]);
        }
        if (!self::verify_signature($payload, $sig, $secret)) {
            return new WP_Error('ves_stripe_bad_signature', 'Invalid signature.', ['status' => 400]);
        }
        $event = json_decode((string) $payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return new WP_Error('ves_stripe_bad_payload', 'Malformed event.', ['status' => 400]);
        }

        $type = (string) $event['type'];
        $event_id = (string) $event['id'];
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        // Process inline by default: our handlers are a few fast DB ops, so we
        // finish well within Stripe's timeout and return 200. Offloading to
        // Action Scheduler is OPT-IN (filter -> true) because on hosts where the
        // AS queue isn't actually running (WP-Cron disabled), a stashed event
        // would never be processed and would be silently lost. When opted in, the
        // async worker re-runs the same idempotent process_event(), so the DB
        // UNIQUE dedup still guarantees exactly-once.
        $async = function_exists('as_enqueue_async_action')
            && apply_filters('ves_stripe_async_webhooks', false);
        if ($async) {
            // Stash the raw object keyed by event id; the worker reads it back.
            set_transient('ves_stripe_evt_' . $event_id, ['type' => $type, 'object' => $object], 6 * HOUR_IN_SECONDS);
            as_enqueue_async_action('ves_stripe_process_event', ['event_id' => $event_id], 'ves-stripe');
        } else {
            self::process_event($type, $event_id, $object);
        }

        return rest_ensure_response(['received' => true]);
    }

    /** Action Scheduler worker: rehydrate a stashed event and process it. */
    public static function process_event_async($event_id) {
        $event_id = sanitize_text_field((string) $event_id);
        if ($event_id === '') { return; }
        $stash = get_transient('ves_stripe_evt_' . $event_id);
        if (!is_array($stash)) { return; }
        delete_transient('ves_stripe_evt_' . $event_id);
        self::process_event((string) ($stash['type'] ?? ''), $event_id, is_array($stash['object'] ?? null) ? $stash['object'] : []);
    }

    /**
     * Central, idempotent event dispatcher (pure of HTTP/signature concerns so it
     * is unit-testable). Returns a short status string describing what happened.
     */
    public static function process_event($type, $event_id, $object) {
        $type = (string) $type;
        $event_id = (string) $event_id;
        $object = is_array($object) ? $object : [];

        switch ($type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $mode = (string) ($object['mode'] ?? 'payment');
                if ($mode === 'subscription') {
                    return self::handle_subscription_checkout($object);
                }
                // One-time credit pack purchase.
                $paid = ($object['payment_status'] ?? '') === 'paid' || ($object['status'] ?? '') === 'complete';
                return $paid ? (self::fulfill_from_session($object, $event_id) ? 'topup_granted' : 'topup_skipped') : 'unpaid';

            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                return self::handle_invoice_paid($object);

            case 'customer.subscription.updated':
                return self::handle_subscription_updated($object);

            case 'customer.subscription.deleted':
                return self::handle_subscription_deleted($object);
        }
        return 'ignored';
    }

    /** Link the Stripe customer to the user and apply the subscribed plan. */
    public static function handle_subscription_checkout($session) {
        $session = is_array($session) ? $session : [];
        $user_id = self::resolve_user_from_object($session);
        $customer = (string) ($session['customer'] ?? '');
        if ($user_id > 0 && $customer !== '') { self::link_customer($user_id, $customer); }
        // Plan is applied on invoice.paid (which always follows), so credits and
        // plan move together. Nothing else to do here beyond the customer link.
        return $user_id > 0 ? 'subscription_linked' : 'subscription_unresolved';
    }

    /**
     * A subscription invoice was paid — apply the mapped plan and grant that
     * plan's monthly credits, idempotently keyed by the invoice id so a
     * redelivered invoice.paid never double-grants a renewal.
     */
    public static function handle_invoice_paid($invoice) {
        $invoice = is_array($invoice) ? $invoice : [];
        // Only act on subscription invoices.
        $subscription = (string) ($invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? ''));
        if ($subscription === '') { return 'not_subscription'; }

        $price_id = self::price_id_from_invoice($invoice);
        $plan = self::resolve_plan_for_price($price_id);
        if ($plan === null) { return 'unmapped_price'; }

        $user_id = self::resolve_user_from_object($invoice);
        if ($user_id <= 0) {
            $user_id = self::user_id_for_customer((string) ($invoice['customer'] ?? ''));
        }
        if ($user_id <= 0) { return 'user_unresolved'; }

        // Apply the plan/entitlement.
        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'set_user_plan')) {
            VES_Usage_Billing::set_user_plan($user_id, $plan['plan_slug']);
        }

        // Grant this period's credits (idempotent on the invoice id).
        $credits = (float) $plan['monthly_credits'];
        $invoice_id = (string) ($invoice['id'] ?? '');
        if ($credits > 0 && $invoice_id !== '') {
            $granted = self::grant_credits($user_id, $credits, 'stripe_inv:' . $invoice_id, [
                'source' => 'stripe_subscription',
                'plan_slug' => $plan['plan_slug'],
                'stripe_invoice_id' => $invoice_id,
                'stripe_subscription_id' => $subscription,
            ]);
            return $granted ? 'subscription_renewed' : 'subscription_duplicate';
        }
        return 'subscription_applied';
    }

    /** Subscription status changed — keep the plan while active, else revert. */
    public static function handle_subscription_updated($subscription) {
        $subscription = is_array($subscription) ? $subscription : [];
        $status = (string) ($subscription['status'] ?? '');
        $user_id = self::resolve_user_from_object($subscription);
        if ($user_id <= 0) { $user_id = self::user_id_for_customer((string) ($subscription['customer'] ?? '')); }
        if ($user_id <= 0) { return 'user_unresolved'; }

        $dead = ['canceled', 'unpaid', 'incomplete_expired'];
        if (in_array($status, $dead, true)) {
            self::revert_to_free($user_id);
            return 'reverted_' . $status;
        }
        // active / trialing / past_due → keep entitlement; invoice.paid handles credits.
        return 'status_' . ($status !== '' ? $status : 'unknown');
    }

    public static function handle_subscription_deleted($subscription) {
        $subscription = is_array($subscription) ? $subscription : [];
        $user_id = self::resolve_user_from_object($subscription);
        if ($user_id <= 0) { $user_id = self::user_id_for_customer((string) ($subscription['customer'] ?? '')); }
        if ($user_id <= 0) { return 'user_unresolved'; }
        self::revert_to_free($user_id);
        return 'subscription_canceled';
    }

    private static function revert_to_free($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !class_exists('VES_Usage_Billing')) { return; }
        $free = apply_filters('ves_stripe_free_plan_slug', 'free');
        if (method_exists('VES_Usage_Billing', 'set_user_plan')) {
            VES_Usage_Billing::set_user_plan($user_id, $free);
        }
    }

    /** Return $v if it is an array, else an empty array (PHP-8 offset-safe). */
    private static function a($v) {
        return is_array($v) ? $v : [];
    }

    /** Pull the first line item's Price id from a Stripe invoice object. */
    public static function price_id_from_invoice($invoice) {
        $invoice = self::a($invoice);
        $lines = self::a($invoice['lines'] ?? null)['data'] ?? [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = self::a($line);
                if (empty($line)) { continue; }
                $price = self::a($line['price'] ?? null);
                $plan = self::a($line['plan'] ?? null);
                $price_details = self::a(self::a($line['pricing'] ?? null)['price_details'] ?? null);
                $pid = (string) ($price['id'] ?? ($plan['id'] ?? ($price_details['price'] ?? '')));
                if ($pid !== '') { return $pid; }
            }
        }
        return '';
    }

    /** Resolve a WP user id from a Stripe object's metadata (set at checkout). */
    public static function resolve_user_from_object($obj) {
        $obj = self::a($obj);
        $sub_details = self::a($obj['subscription_details'] ?? null);
        $parent_sub = self::a(self::a($obj['parent'] ?? null)['subscription_details'] ?? null);
        $line0 = self::a(self::a(self::a($obj['lines'] ?? null)['data'] ?? null)[0] ?? null);
        $candidates = [
            self::a($obj['metadata'] ?? null),
            self::a($sub_details['metadata'] ?? null),
            self::a($parent_sub['metadata'] ?? null),
            // First invoice line can also carry the subscription metadata.
            self::a($line0['metadata'] ?? null),
        ];
        foreach ($candidates as $meta) {
            $uid = (int) ($meta['ves_user_id'] ?? 0);
            if ($uid > 0) { return $uid; }
        }
        $cri = (int) ($obj['client_reference_id'] ?? 0);
        return $cri > 0 ? $cri : 0;
    }

    /**
     * Verify a Stripe webhook signature header without the SDK.
     * Header form: "t=<ts>,v1=<hex>,v1=<hex>,...". We accept if any v1 matches
     * HMAC-SHA256(secret, "ts.payload") within the tolerance window.
     */
    public static function verify_signature($payload, $sig_header, $secret) {
        $payload = (string) $payload;
        $sig_header = (string) $sig_header;
        if ($sig_header === '' || $secret === '') { return false; }
        $t = null; $sigs = [];
        foreach (explode(',', $sig_header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) { continue; }
            if ($kv[0] === 't') { $t = (int) $kv[1]; }
            elseif ($kv[0] === 'v1') { $sigs[] = $kv[1]; }
        }
        if ($t === null || empty($sigs)) { return false; }
        $tolerance = (int) apply_filters('ves_stripe_signature_tolerance', self::SIGNATURE_TOLERANCE);
        if ($tolerance > 0 && abs(time() - $t) > $tolerance) { return false; }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $candidate) {
            if (is_string($candidate) && hash_equals($expected, $candidate)) { return true; }
        }
        return false;
    }

    /** Resolve user + credits from a paid session and grant idempotently. */
    public static function fulfill_from_session($session, $event_id) {
        $session = is_array($session) ? $session : [];
        $meta = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $user_id = (int) ($meta['ves_user_id'] ?? $session['client_reference_id'] ?? 0);
        $credits = (float) ($meta['ves_credits'] ?? 0);
        $pack_id = sanitize_key((string) ($meta['ves_pack_id'] ?? ''));

        // If credits weren't in metadata (older session), recover from the pack.
        if ($credits <= 0 && $pack_id !== '' && class_exists('VES_Usage_Billing')) {
            $pack = VES_Usage_Billing::get_credit_pack($pack_id);
            $credits = (float) ($pack['credits'] ?? 0);
        }
        if ($user_id <= 0 || $credits <= 0) { return false; }

        return self::grant_credits($user_id, $credits, $event_id, [
            'source' => 'stripe',
            'pack_id' => $pack_id,
            'stripe_session_id' => sanitize_text_field((string) ($session['id'] ?? '')),
            'stripe_event_id' => sanitize_text_field((string) $event_id),
            'amount_total' => (int) ($session['amount_total'] ?? 0),
            'currency' => sanitize_key((string) ($session['currency'] ?? '')),
        ]);
    }

    /**
     * Provider-agnostic, idempotent credit grant. Returns false if this dedup key
     * was already processed (so a webhook redelivery is a safe no-op). Lago or any
     * future provider can call this with their own event id.
     */
    public static function grant_credits($user_id, $credits, $dedup_key, $context = []) {
        $user_id = (int) $user_id;
        $credits = round((float) $credits, 2);
        $dedup_key = sanitize_text_field((string) $dedup_key);
        if ($user_id <= 0 || $credits <= 0 || $dedup_key === '') { return false; }
        if (!class_exists('VES_Usage_Billing')) { return false; }
        $label = 'Compra de créditos (' . sanitize_key((string) ($context['source'] ?? 'stripe')) . ')';

        // Run the dedup check AND the grant inside the SAME per-user mutex so two
        // concurrent webhook deliveries of one event can't both pass the check and
        // double-grant. with_user_lock() is reentrant, so adjust_user_credits()
        // re-entering the lock inside the closure is safe. If a locking primitive
        // is available, with_user_lock returns the closure's value; if it returns
        // a WP_Error (lock could not be taken and degrade was disabled), we report
        // not-granted so Stripe retries.
        if (method_exists('VES_Usage_Billing', 'with_user_lock')) {
            $result = VES_Usage_Billing::with_user_lock($user_id, function () use ($user_id, $credits, $dedup_key, $context, $label) {
                if (!self::mark_event_processed($dedup_key)) {
                    return 'duplicate';
                }
                VES_Usage_Billing::adjust_user_credits($user_id, $credits, $label, array_merge([
                    'event_type' => 'credit_purchase',
                    'module' => 'billing',
                ], is_array($context) ? $context : []));
                return 'granted';
            });
            return $result === 'granted';
        }

        // Fallback (no locking wrapper available): keep the dedup guard.
        if (!self::mark_event_processed($dedup_key)) {
            return false;
        }
        VES_Usage_Billing::adjust_user_credits($user_id, $credits, $label, array_merge([
            'event_type' => 'credit_purchase',
            'module' => 'billing',
        ], is_array($context) ? $context : []));
        return true;
    }

    /**
     * Atomic exactly-once idempotency for webhook events. A dedicated table with
     * a UNIQUE index on event_id lets the DATABASE enforce single-processing —
     * an INSERT IGNORE that affects one row means "we are the first to see this
     * event"; zero rows means a duplicate. This removes the read-then-write race
     * the previous option-array approach documented, and bounds growth via a
     * UNIQUE-keyed table instead of an ever-growing option. If the table can't be
     * used (creation failed, exotic host), we fall back to the option ledger so
     * dedup still works, just less strictly.
     *
     * Returns true the first time a key is seen, false on any repeat.
     */
    public static function mark_event_processed($key) {
        $key = sanitize_text_field((string) $key);
        if ($key === '') { return false; }

        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')) {
            self::ensure_events_table();
            $table = self::events_table_name();
            // INSERT IGNORE: 1 affected row = inserted (first time); 0 = duplicate
            // (or insert error — verified below).
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (event_id, created_at) VALUES (%s, %s)",
                $key,
                gmdate('Y-m-d H:i:s')
            ));
            if ((int) $wpdb->rows_affected === 1) {
                return true;
            }
            // Zero rows: a duplicate if the row exists, otherwise the insert truly
            // failed (e.g. table missing) and we fall through to the option ledger.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %s",
                $key
            ));
            if ($exists > 0) {
                return false;
            }
        }

        return self::mark_event_processed_option($key);
    }

    /** Legacy/fallback option-based idempotency ledger (capped ring buffer). */
    private static function mark_event_processed_option($key) {
        $processed = get_option(self::PROCESSED_EVENTS_OPTION, []);
        if (!is_array($processed)) { $processed = []; }
        if (isset($processed[$key])) { return false; }
        $processed[$key] = time();
        $cap = (int) apply_filters('ves_stripe_processed_events_cap', 2000);
        if ($cap > 0 && count($processed) > $cap) {
            $processed = array_slice($processed, -$cap, null, true);
        }
        update_option(self::PROCESSED_EVENTS_OPTION, $processed, false);
        return true;
    }

    public static function events_table_name() {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
        return $prefix . self::EVENTS_TABLE_SLUG;
    }

    /**
     * Create the idempotency table (idempotent). Registered with VES_Migrations
     * so it is created/repaired alongside the rest of the schema. The UNIQUE key
     * on event_id is what makes mark_event_processed() atomic.
     */
    public static function create_tables($force = false) {
        global $wpdb;
        if (!is_object($wpdb)) { return; }
        if (!$force && (string) get_option(self::EVENTS_DB_OPTION, '') === self::EVENTS_DB_VERSION) {
            return;
        }
        if (!function_exists('dbDelta')) {
            $upgrade = (defined('ABSPATH') ? ABSPATH : '') . 'wp-admin/includes/upgrade.php';
            if (is_readable($upgrade)) { require_once $upgrade; }
        }
        if (!function_exists('dbDelta')) { return; }
        $table = self::events_table_name();
        $charset = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id)
        ) {$charset};";
        dbDelta($sql);
        update_option(self::EVENTS_DB_OPTION, self::EVENTS_DB_VERSION, false);
    }

    private static function ensure_events_table() {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;
        if ((string) get_option(self::EVENTS_DB_OPTION, '') !== self::EVENTS_DB_VERSION) {
            self::create_tables(false);
        }
    }

    // ── Stripe REST transport (no SDK) ───────────────────────────────────────

    private static function api_post($path, $body, $idempotency_key = '') {
        $key = self::secret_key();
        if ($key === '') { return new WP_Error('ves_stripe_unconfigured', 'Stripe is not configured.'); }
        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        if ($idempotency_key !== '') { $headers['Idempotency-Key'] = $idempotency_key; }
        $args = [
            'headers' => $headers,
            'body' => self::http_build_stripe_query($body),
            'timeout' => 25,
        ];
        $resp = wp_remote_post(self::API_BASE . $path, $args);
        if (is_wp_error($resp)) { return $resp; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $decoded = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? 'Stripe error') : 'Stripe error';
            return new WP_Error('ves_stripe_http_' . $code, $msg, ['status' => $code]);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Stripe expects PHP-style nested form encoding
     * (line_items[0][price_data][currency]=eur). http_build_query produces exactly
     * that, so we lean on it rather than hand-rolling the bracket syntax.
     */
    private static function http_build_stripe_query($body) {
        return http_build_query($body, '', '&');
    }

    // ── Helpers / UI ─────────────────────────────────────────────────────────

    private static function format_credits($credits) {
        $credits = (float) $credits;
        return rtrim(rtrim(number_format($credits, 2, '.', ''), '0'), '.');
    }

    private static function redirect_back($status, $message) {
        $url = function_exists('wp_get_referer') ? wp_get_referer() : '';
        if (!$url) { $url = function_exists('home_url') ? home_url('/') : '/'; }
        $url = add_query_arg(['ves_billing_notice' => rawurlencode($message), 'ves_billing_status' => $status], $url);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); }
        elseif (function_exists('wp_redirect')) { wp_redirect($url); }
        exit;
    }

    /**
     * [ves_usage_dashboard] — server-rendered, in-app usage + buy-credits panel.
     * No build step, no React dependency; safe to drop on any page.
     */
    public static function render_usage_dashboard($atts = []) {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return '<div class="ves-usage-dash ves-usage-dash--guest"><p>Inicia sesión para ver tu consumo y comprar créditos.</p></div>';
        }
        if (!class_exists('VES_Usage_Billing')) { return ''; }
        $user_id = (int) get_current_user_id();
        $summary = VES_Usage_Billing::get_summary($user_id);
        $unlimited = VES_Usage_Billing::is_unlimited_user($user_id);

        $balance = $unlimited ? '∞' : self::format_credits((float) ($summary['balance'] ?? 0));
        $plan = is_array($summary['plan'] ?? null) ? $summary['plan'] : [];
        $plan_name = esc_html((string) ($plan['name'] ?? $plan['label'] ?? ($plan['slug'] ?? 'Plan')));

        ob_start();
        echo self::dashboard_styles();
        echo '<div class="ves-usage-dash">';

        // Notice from a redirect (purchase started / error).
        $notice = isset($_GET['ves_billing_notice']) ? sanitize_text_field(wp_unslash($_GET['ves_billing_notice'])) : '';
        $nstatus = isset($_GET['ves_billing_status']) ? sanitize_key(wp_unslash($_GET['ves_billing_status'])) : '';
        if (isset($_GET['ves_purchase']) && sanitize_key(wp_unslash($_GET['ves_purchase'])) === 'success') {
            echo '<div class="ves-usage-note ves-usage-note--ok">¡Gracias! Tu compra se está procesando; los créditos aparecerán en unos segundos.</div>';
        } elseif ($notice !== '') {
            $cls = $nstatus === 'error' ? 'ves-usage-note--err' : 'ves-usage-note--ok';
            echo '<div class="ves-usage-note ' . esc_attr($cls) . '">' . esc_html($notice) . '</div>';
        }

        // Decision-first figures: balance, burn rate, and projected runout — the
        // numbers a user actually acts on (top up / slow down / upgrade).
        $balance_num = (float) ($summary['balance'] ?? 0);
        $burn_7d = (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'get_spend_in_days'))
            ? (float) VES_Usage_Billing::get_spend_in_days($user_id, 7) : 0.0;
        $daily_burn = round($burn_7d / 7, 2);

        echo '<div class="ves-usage-grid">';
        echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Saldo</span><span class="ves-usage-stat__value">' . esc_html($balance) . '</span><span class="ves-usage-stat__unit">créditos</span></div>';
        echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Plan</span><span class="ves-usage-stat__value">' . $plan_name . '</span></div>';
        if (!$unlimited) {
            echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Consumo diario</span><span class="ves-usage-stat__value">' . esc_html(self::format_credits($daily_burn)) . '</span><span class="ves-usage-stat__unit">créditos/día (7d)</span></div>';
            // Projected runout: how long the current balance lasts at this pace.
            if ($daily_burn > 0 && $balance_num > 0) {
                $days_left = (int) floor($balance_num / $daily_burn);
                $runout_cls = $days_left <= 3 ? 'ves-usage-stat__value ves-usage-runout-low' : 'ves-usage-stat__value';
                echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Autonomía estimada</span><span class="' . esc_attr($runout_cls) . '">~' . (int) $days_left . '</span><span class="ves-usage-stat__unit">días al ritmo actual</span></div>';
            } else {
                echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Gasto total</span><span class="ves-usage-stat__value">' . esc_html(self::format_credits((float) ($summary['lifetime_spent'] ?? 0))) . '</span><span class="ves-usage-stat__unit">créditos</span></div>';
            }
        } else {
            echo '<div class="ves-usage-stat"><span class="ves-usage-stat__label">Gasto total</span><span class="ves-usage-stat__value">' . esc_html(self::format_credits((float) ($summary['lifetime_spent'] ?? 0))) . '</span><span class="ves-usage-stat__unit">créditos</span></div>';
        }
        echo '</div>';

        // Low-balance nudge: when the projected runout is short, prompt a top-up.
        if (!$unlimited && $daily_burn > 0 && $balance_num > 0) {
            $days_left = $balance_num / $daily_burn;
            if ($days_left <= 3) {
                echo '<div class="ves-usage-note ves-usage-note--err">Tu saldo se agota en ~' . (int) floor($days_left) . ' día(s) al ritmo actual. Considera recargar créditos.</div>';
            }
        }

        // This month's activity as proportional bars (per-feature breakdown).
        $usage = is_array($summary['period_usage'] ?? null) ? $summary['period_usage'] : [];
        $usage = array_filter($usage, static function ($v) { return (int) $v > 0; });
        if (!empty($usage)) {
            arsort($usage);
            $max = max($usage);
            echo '<h4 class="ves-usage-h">Actividad este mes</h4><ul class="ves-usage-list ves-usage-list--bars">';
            $shown = 0;
            foreach ($usage as $type => $count) {
                if ($shown++ >= 8) { break; }
                $pct = $max > 0 ? max(4, (int) round(((int) $count / $max) * 100)) : 0;
                echo '<li><span class="ves-usage-bar__label">' . esc_html(self::humanize_event($type)) . '</span>';
                echo '<span class="ves-usage-bar"><span class="ves-usage-bar__fill" style="width:' . (int) $pct . '%"></span></span>';
                echo '<strong>' . (int) $count . '</strong></li>';
            }
            echo '</ul>';
        }

        // Buy credits — only when Stripe is live; otherwise fall back to the
        // existing manual top-up request flow (handled elsewhere).
        if (self::is_enabled() && !$unlimited && function_exists('admin_url')) {
            $packs = VES_Usage_Billing::get_credit_packs();
            echo '<h4 class="ves-usage-h">Comprar créditos</h4>';
            if (self::mode() === 'test') {
                echo '<div class="ves-usage-note ves-usage-note--info">Modo de prueba de Stripe activo — usa tarjetas de test.</div>';
            }
            echo '<div class="ves-usage-packs">';
            foreach ($packs as $pack) {
                if (($pack['status'] ?? 'inactive') !== 'active') { continue; }
                $cents = self::pack_price_cents($pack);
                if ($cents <= 0) { continue; }
                $pid = sanitize_key((string) ($pack['id'] ?? ''));
                echo '<form class="ves-usage-pack" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::CHECKOUT_ACTION) . '">';
                echo '<input type="hidden" name="pack_id" value="' . esc_attr($pid) . '">';
                if (function_exists('wp_nonce_field')) { wp_nonce_field(self::CHECKOUT_ACTION . '_' . $pid); }
                echo '<div class="ves-usage-pack__name">' . esc_html((string) ($pack['label'] ?? $pid)) . '</div>';
                echo '<div class="ves-usage-pack__credits">' . esc_html(self::format_credits((float) ($pack['credits'] ?? 0))) . ' créditos</div>';
                echo '<div class="ves-usage-pack__price">' . esc_html((string) ($pack['price_label'] ?? self::format_price($cents))) . '</div>';
                echo '<button type="submit" class="ves-usage-pack__buy">Comprar</button>';
                echo '</form>';
            }
            echo '</div>';
        }

        // Recurring subscription plans.
        if (self::subscriptions_enabled() && !$unlimited && function_exists('admin_url')) {
            $plans = self::plan_prices();
            if (!empty($plans)) {
                echo '<h4 class="ves-usage-h">Planes de suscripción</h4>';
                echo '<div class="ves-usage-packs">';
                foreach ($plans as $price_id => $plan) {
                    echo '<form class="ves-usage-pack" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="' . esc_attr(self::SUBSCRIBE_ACTION) . '">';
                    echo '<input type="hidden" name="price_id" value="' . esc_attr((string) $price_id) . '">';
                    if (function_exists('wp_nonce_field')) { wp_nonce_field(self::SUBSCRIBE_ACTION . '_' . md5((string) $price_id)); }
                    echo '<div class="ves-usage-pack__name">' . esc_html((string) ($plan['label'] ?? $plan['plan_slug'])) . '</div>';
                    echo '<div class="ves-usage-pack__credits">' . esc_html(self::format_credits((float) ($plan['monthly_credits'] ?? 0))) . ' créditos/mes</div>';
                    echo '<button type="submit" class="ves-usage-pack__buy">Suscribirme</button>';
                    echo '</form>';
                }
                echo '</div>';
            }
        }

        // Self-serve billing management (Stripe Billing Portal) — shown once the
        // user has a linked Stripe customer (i.e. has subscribed at least once).
        if (self::portal_available($user_id) && function_exists('admin_url')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ves-usage-portal">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::PORTAL_ACTION) . '">';
            if (function_exists('wp_nonce_field')) { wp_nonce_field(self::PORTAL_ACTION); }
            echo '<button type="submit" class="ves-usage-portal__btn">Gestionar suscripción y facturación</button>';
            echo '</form>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    /** Self-contained styles for the dashboard; printed at most once per request. */
    private static function dashboard_styles() {
        static $printed = false;
        if ($printed) { return ''; }
        $printed = true;
        return '<style>'
            . '.ves-usage-dash{--vud-bg:#ffffff;--vud-fg:#0f172a;--vud-muted:#64748b;--vud-border:#e2e8f0;--vud-accent:#6d28d9;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--vud-fg);'
            . 'background:var(--vud-bg);border:1px solid var(--vud-border);border-radius:16px;padding:24px;max-width:760px;margin:16px auto;box-shadow:0 1px 3px rgba(15,23,42,.06)}'
            . '.ves-usage-dash--guest{text-align:center;color:var(--vud-muted)}'
            . '.ves-usage-note{border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px;line-height:1.4}'
            . '.ves-usage-note--ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}'
            . '.ves-usage-note--err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}'
            . '.ves-usage-note--info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}'
            . '.ves-usage-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:8px}'
            . '.ves-usage-stat{background:#f8fafc;border:1px solid var(--vud-border);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:2px}'
            . '.ves-usage-stat__label{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--vud-muted)}'
            . '.ves-usage-stat__value{font-size:28px;font-weight:700;line-height:1.1}'
            . '.ves-usage-stat__unit{font-size:12px;color:var(--vud-muted)}'
            . '.ves-usage-h{margin:20px 0 8px;font-size:15px;font-weight:600}'
            . '.ves-usage-list{list-style:none;margin:0;padding:0;border:1px solid var(--vud-border);border-radius:12px;overflow:hidden}'
            . '.ves-usage-list li{display:flex;justify-content:space-between;padding:10px 14px;font-size:14px;border-top:1px solid var(--vud-border)}'
            . '.ves-usage-list li:first-child{border-top:0}'
            . '.ves-usage-list li span{color:var(--vud-muted)}'
            . '.ves-usage-runout-low{color:#b91c1c}'
            . '.ves-usage-list--bars li{align-items:center;gap:12px}'
            . '.ves-usage-bar__label{flex:0 0 42%;color:var(--vud-fg)!important;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.ves-usage-bar{flex:1 1 auto;height:8px;background:#eef2f7;border-radius:999px;overflow:hidden}'
            . '.ves-usage-bar__fill{display:block;height:100%;background:var(--vud-accent);border-radius:999px}'
            . '.ves-usage-list--bars strong{flex:0 0 auto;min-width:28px;text-align:right}'
            . '.ves-usage-packs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}'
            . '.ves-usage-pack{background:#f8fafc;border:1px solid var(--vud-border);border-radius:12px;padding:16px;text-align:center;margin:0}'
            . '.ves-usage-pack__name{font-weight:600;font-size:15px}'
            . '.ves-usage-pack__credits{color:var(--vud-muted);font-size:13px;margin:4px 0}'
            . '.ves-usage-pack__price{font-size:20px;font-weight:700;margin:6px 0 12px}'
            . '.ves-usage-pack__buy{display:inline-block;width:100%;background:var(--vud-accent);color:#fff;border:0;border-radius:8px;'
            . 'padding:10px 14px;font-size:14px;font-weight:600;cursor:pointer}'
            . '.ves-usage-pack__buy:hover{filter:brightness(1.05)}'
            . '.ves-usage-portal{margin-top:16px}'
            . '.ves-usage-portal__btn{background:transparent;border:1px solid var(--vud-border);color:var(--vud-fg);border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer}'
            . '.ves-usage-portal__btn:hover{background:#f8fafc}'
            . '</style>';
    }

    private static function format_price($cents) {
        $cur = strtoupper(self::currency());
        return number_format(((int) $cents) / 100, 2) . ' ' . $cur;
    }

    private static function humanize_event($type) {
        $map = [
            'manual_run' => 'Análisis sociales',
            'ai_analysis' => 'Análisis con IA',
            'trend_run' => 'Tendencias',
            'trend_brief' => 'Briefings de tendencias',
            'brand_audit_light' => 'Brand Audit (ligero)',
            'brand_audit_standard' => 'Brand Audit (estándar)',
            'brand_audit_deep' => 'Brand Audit (profundo)',
            'creative_asset_analysis' => 'Análisis creativo',
            'credit_purchase' => 'Compras de créditos',
        ];
        return $map[$type] ?? ucwords(str_replace('_', ' ', (string) $type));
    }

    // ── Admin settings UI (rendered inside the Intelligence Suite console) ────

    public static function handle_save_settings() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::SAVE_SETTINGS_ACTION);
        }
        $s = self::settings();
        $s['enabled'] = empty($_POST['ves_stripe_enabled']) ? 0 : 1;
        // Only overwrite a stored secret when a NON-EMPTY value is submitted (masked field).
        if (isset($_POST['ves_stripe_secret_key'])) { $v = sanitize_text_field(wp_unslash($_POST['ves_stripe_secret_key'])); if ($v !== '') { $s['secret_key'] = $v; } }
        if (isset($_POST['ves_stripe_webhook_secret'])) { $v = sanitize_text_field(wp_unslash($_POST['ves_stripe_webhook_secret'])); if ($v !== '') { $s['webhook_secret'] = $v; } }
        if (isset($_POST['ves_stripe_currency'])) {
            $cur = strtolower(sanitize_text_field(wp_unslash($_POST['ves_stripe_currency'])));
            if (preg_match('/^[a-z]{3}$/', $cur)) { $s['currency'] = $cur; }
        }
        if (isset($_POST['ves_stripe_success_url'])) {
            $s['success_url'] = esc_url_raw(wp_unslash($_POST['ves_stripe_success_url']));
        }
        if (isset($_POST['ves_stripe_cancel_url'])) {
            $s['cancel_url'] = esc_url_raw(wp_unslash($_POST['ves_stripe_cancel_url']));
        }
        // Optional per-pack price overrides (EUR, decimal -> cents).
        if (isset($_POST['ves_stripe_pack_prices']) && is_array($_POST['ves_stripe_pack_prices'])) {
            $prices = [];
            foreach (wp_unslash($_POST['ves_stripe_pack_prices']) as $pid => $val) {
                $pid = sanitize_key($pid);
                $val = (string) $val;
                if ($pid === '' || trim($val) === '') { continue; }
                if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/', $val, $m)) {
                    $prices[$pid] = (int) round(((float) str_replace(',', '.', $m[1])) * 100);
                }
            }
            $s['pack_prices'] = $prices;
        }

        // Subscriptions: enable flag + the Price→plan map.
        $s['subscriptions_enabled'] = empty($_POST['ves_stripe_subscriptions_enabled']) ? 0 : 1;
        if (isset($_POST['ves_stripe_plan_prices']) && is_array($_POST['ves_stripe_plan_prices'])) {
            $plan_prices = [];
            foreach (wp_unslash($_POST['ves_stripe_plan_prices']) as $row) {
                if (!is_array($row)) { continue; }
                $price_id = trim(sanitize_text_field((string) ($row['price_id'] ?? '')));
                $slug = sanitize_key((string) ($row['plan_slug'] ?? ''));
                if ($price_id === '' || $slug === '') { continue; }
                $plan_prices[$price_id] = [
                    'plan_slug' => $slug,
                    'monthly_credits' => max(0, (int) ($row['monthly_credits'] ?? 0)),
                    'label' => sanitize_text_field((string) ($row['label'] ?? $slug)),
                ];
            }
            $s['plan_prices'] = $plan_prices;
        }
        if (isset($_POST['ves_stripe_portal_configuration'])) {
            $s['portal_configuration'] = sanitize_text_field(wp_unslash($_POST['ves_stripe_portal_configuration']));
        }

        update_option(self::OPTION, $s, false);
        $back = function_exists('wp_get_referer') ? wp_get_referer() : '';
        if (!$back) { $back = function_exists('admin_url') ? admin_url('admin.php?page=ves-intelligence-suite&tab=advanced') : '/'; }
        $back = add_query_arg(['ves_stripe_saved' => 1], $back);
        if (function_exists('wp_safe_redirect')) { wp_safe_redirect($back); }
        exit;
    }

    /** Settings card HTML for the console Advanced tab. Self-escaping. */
    public static function render_admin_settings_html() {
        if (!function_exists('admin_url')) { return ''; }
        $s = self::settings();
        $enabled = !empty($s['enabled']);
        $mode = self::mode();
        $has_key = self::secret_key() !== '';
        $key_via_const = defined('VES_STRIPE_SECRET_KEY') && VES_STRIPE_SECRET_KEY;
        $hook_via_const = defined('VES_STRIPE_WEBHOOK_SECRET') && VES_STRIPE_WEBHOOK_SECRET;
        $webhook_url = function_exists('rest_url') ? rest_url(self::REST_NAMESPACE . '/stripe/webhook') : '';
        $saved = isset($_GET['ves_stripe_saved']);

        ob_start();
        echo '<div class="fiis-card fiis-promo"><h3>Monetización · Stripe</h3>';
        echo '<p>Vende packs de créditos con Stripe Checkout. El sistema permanece inactivo hasta que pegues una clave secreta. Valida primero en <strong>modo test</strong> (clave <code>sk_test_…</code>) antes de pasar a producción.</p>';

        if ($saved) { echo '<div class="notice notice-success inline"><p>Ajustes de Stripe guardados.</p></div>'; }

        // Status line.
        $badge = self::is_enabled()
            ? '<span class="fiis-badge fiis-badge-ok">Activo · ' . esc_html(strtoupper($mode)) . '</span>'
            : '<span class="fiis-badge fiis-badge-muted">Inactivo</span>';
        echo '<p>Estado: ' . $badge;
        if (self::is_enabled() && $mode === 'live') {
            echo ' <span class="fiis-badge fiis-badge-warn">PRODUCCIÓN — cobros reales</span>';
        }
        echo '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_SETTINGS_ACTION) . '">';
        if (function_exists('wp_nonce_field')) { wp_nonce_field(self::SAVE_SETTINGS_ACTION); }

        echo '<p><label><input type="checkbox" name="ves_stripe_enabled" value="1" ' . checked($enabled, true, false) . '> Activar compras con Stripe</label></p>';

        if ($key_via_const) {
            echo '<p class="fiis-muted">La clave secreta se define en <code>wp-config.php</code> (VES_STRIPE_SECRET_KEY).</p>';
        } else {
            $sk_set = (string) ($s['secret_key'] ?? '') !== '';
            echo '<p><label>Clave secreta (sk_test_… / sk_live_…)<br><input type="password" name="ves_stripe_secret_key" class="regular-text" autocomplete="off" value="" placeholder="' . esc_attr($sk_set ? '•••••• guardada — vacío para conservar' : 'sk_live_…') . '" style="max-width:480px;width:100%"></label></p>';
        }
        if ($hook_via_const) {
            echo '<p class="fiis-muted">El secreto de webhook se define en <code>wp-config.php</code> (VES_STRIPE_WEBHOOK_SECRET).</p>';
        } else {
            $wh_set = (string) ($s['webhook_secret'] ?? '') !== '';
            echo '<p><label>Secreto de firma del webhook (whsec_…)<br><input type="password" name="ves_stripe_webhook_secret" class="regular-text" autocomplete="off" value="" placeholder="' . esc_attr($wh_set ? '•••••• guardado — vacío para conservar' : 'whsec_…') . '" style="max-width:480px;width:100%"></label></p>';
        }

        echo '<p><label>Moneda (ISO 4217)<br><input type="text" name="ves_stripe_currency" class="small-text" maxlength="3" value="' . esc_attr(self::currency()) . '"></label></p>';
        echo '<p><label>URL de éxito (opcional)<br><input type="url" name="ves_stripe_success_url" class="regular-text" value="' . esc_attr((string) ($s['success_url'] ?? '')) . '" style="max-width:480px;width:100%" placeholder="' . esc_attr(function_exists('home_url') ? home_url('/') : '') . '"></label></p>';
        echo '<p><label>URL de cancelación (opcional)<br><input type="url" name="ves_stripe_cancel_url" class="regular-text" value="' . esc_attr((string) ($s['cancel_url'] ?? '')) . '" style="max-width:480px;width:100%"></label></p>';

        // Per-pack price overrides.
        if (class_exists('VES_Usage_Billing')) {
            echo '<p><strong>Precios por pack</strong> (vacío = se deduce de la etiqueta del pack):</p>';
            echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>Pack</th><th>Créditos</th><th>Precio (' . esc_html(strtoupper(self::currency())) . ')</th></tr></thead><tbody>';
            foreach (VES_Usage_Billing::get_credit_packs() as $pack) {
                $pid = sanitize_key((string) ($pack['id'] ?? ''));
                if ($pid === '') { continue; }
                $cents = self::pack_price_cents($pack);
                $val = isset($s['pack_prices'][$pid]) ? number_format(((int) $s['pack_prices'][$pid]) / 100, 2, '.', '') : '';
                echo '<tr><th scope="row" style="text-align:left">' . esc_html((string) ($pack['label'] ?? $pid)) . '</th>';
                echo '<td>' . esc_html(self::format_credits((float) ($pack['credits'] ?? 0))) . '</td>';
                echo '<td><input type="text" name="ves_stripe_pack_prices[' . esc_attr($pid) . ']" value="' . esc_attr($val) . '" placeholder="' . esc_attr(number_format($cents / 100, 2)) . '" class="small-text"></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Subscriptions: enable + Price→plan map (up to 5 rows).
        echo '<hr style="margin:18px 0;border:0;border-top:1px solid #e2e8f0">';
        echo '<p><label><input type="checkbox" name="ves_stripe_subscriptions_enabled" value="1" ' . checked(!empty($s['subscriptions_enabled']), true, false) . '> Activar suscripciones recurrentes</label></p>';
        echo '<p class="fiis-muted">Crea precios recurrentes en Stripe y mapéalos a un plan + créditos mensuales. En cada factura pagada se asigna el plan y se otorgan los créditos (idempotente por factura).</p>';
        $existing_plans = array_values(self::plan_prices());
        $rows = max(3, count($existing_plans) + 1);
        echo '<div class="fiis-matrix-wrap"><table class="widefat fiis-matrix"><thead><tr><th>Stripe Price ID</th><th>Plan slug</th><th>Créditos/mes</th><th>Etiqueta</th></tr></thead><tbody>';
        for ($i = 0; $i < $rows; $i++) {
            $r = $existing_plans[$i] ?? ['price_id' => '', 'plan_slug' => '', 'monthly_credits' => '', 'label' => ''];
            // plan_prices() keys by price id; recover it.
            $pid = '';
            if (isset($existing_plans[$i])) {
                $keys = array_keys(self::plan_prices());
                $pid = $keys[$i] ?? '';
            }
            echo '<tr>';
            echo '<td><input type="text" name="ves_stripe_plan_prices[' . $i . '][price_id]" value="' . esc_attr($pid) . '" placeholder="price_..." class="regular-text" style="max-width:220px"></td>';
            echo '<td><input type="text" name="ves_stripe_plan_prices[' . $i . '][plan_slug]" value="' . esc_attr((string) ($r['plan_slug'] ?? '')) . '" placeholder="pro" class="small-text"></td>';
            echo '<td><input type="number" min="0" name="ves_stripe_plan_prices[' . $i . '][monthly_credits]" value="' . esc_attr((string) ($r['monthly_credits'] ?? '')) . '" class="small-text"></td>';
            echo '<td><input type="text" name="ves_stripe_plan_prices[' . $i . '][label]" value="' . esc_attr((string) ($r['label'] ?? '')) . '" placeholder="Pro" class="regular-text" style="max-width:160px"></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p><label>ID de configuración del portal (opcional, <code>bpc_…</code>)<br><input type="text" name="ves_stripe_portal_configuration" value="' . esc_attr((string) ($s['portal_configuration'] ?? '')) . '" class="regular-text" style="max-width:320px" placeholder="bpc_..."></label><br><span class="fiis-muted">Vacío = usa la configuración por defecto del portal de Stripe. El botón «Gestionar suscripción» aparece en <code>[ves_usage_dashboard]</code> para usuarios con suscripción.</span></p>';

        echo '<button type="submit" class="button button-primary">Guardar ajustes de Stripe</button>';
        echo '</form>';

        if ($webhook_url !== '') {
            echo '<p class="fiis-muted" style="margin-top:12px">Configura este endpoint de webhook en Stripe. Eventos recomendados: <code>checkout.session.completed</code>, <code>invoice.paid</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>:<br><code>' . esc_html($webhook_url) . '</code></p>';
        }
        echo '<p class="fiis-muted">Inserta el panel de consumo del usuario con el shortcode <code>[ves_usage_dashboard]</code>.</p>';
        echo '</div>';
        return ob_get_clean();
    }
}
