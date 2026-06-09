<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Lead_Gate — public lead-capture entry for the SaaS.
 *
 * Anonymous visitors must fill a short business form (first/last name, corporate
 * email, phone, LinkedIn, position) to enter. On submit a `ves_customer` account
 * is created (passwordless: a random password is generated and they are logged
 * in), the lead fields are stored as user meta, and they land in the panel with
 * the free plan (Social-only · TikTok/YouTube/Instagram · 10 requests).
 *
 * Reuses the existing auth/credit/access stack:
 *   - Role + plan come from registration (ves_customer + free plan defaults).
 *   - Module/provider restriction is enforced by VES_Access_Control's free map.
 *   - The 10-request cap is the free plan's credits/limits.
 *   - Admins (manage_options) are never gated and stay unlimited.
 *
 * Security: anonymous account creation is rate-limited per IP, nonce-protected,
 * input-validated, and de-duplicated by email. Everything is server-side; no
 * change to billing accounting, the React bundle, or DB schema.
 */
final class VES_Lead_Gate {

    const ACTION       = 'ves_lead_register';
    const SAVE_DEMO    = 'ves_save_demo_booking_url';
    const EXPORT       = 'ves_export_leads';
    const MIGRATE      = 'ves_migrate_users_free';
    const NONCE        = 'ves_lead_register';
    const DEMO_OPTION  = 'ves_demo_booking_url';
    const META_PREFIX  = 'ves_lead_';
    const RATE_MAX     = 6;          // account creations per IP per window
    const RATE_WINDOW  = 3600;       // seconds
    const FREE_REQUESTS = 10;        // advertised free-request allotment

    public static function register() {
        add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::SAVE_DEMO, [__CLASS__, 'handle_save_demo_url']);
        add_action('admin_post_' . self::EXPORT, [__CLASS__, 'handle_export_leads']);
        add_action('admin_post_' . self::MIGRATE, [__CLASS__, 'handle_migrate']);
        add_shortcode('ves_lead_gate', [__CLASS__, 'render_form']);
        // Surface captured leads on the Users list (admin only).
        add_filter('manage_users_columns', [__CLASS__, 'add_users_columns']);
        add_filter('manage_users_custom_column', [__CLASS__, 'render_users_column'], 10, 3);
    }

    /** (a) Corporate-email enforcement: reject common free / disposable domains. */
    public static function is_free_email_domain($email): bool {
        $parts = explode('@', strtolower(trim((string) $email)));
        if (count($parts) !== 2 || $parts[1] === '') { return false; }
        $domain = $parts[1];
        $blocked = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.es', 'ymail.com',
            'outlook.com', 'hotmail.com', 'hotmail.es', 'live.com', 'msn.com',
            'icloud.com', 'me.com', 'mac.com', 'aol.com', 'gmx.com', 'gmx.net', 'mail.com',
            'yandex.com', 'yandex.ru', 'zoho.com', 'protonmail.com', 'proton.me', 'pm.me',
            'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
            'trashmail.com', 'getnada.com', 'dispostable.com', 'sharklasers.com', 'yopmail.com',
        ];
        if (function_exists('apply_filters')) {
            $blocked = (array) apply_filters('ves_lead_blocked_email_domains', $blocked);
        }
        return in_array($domain, array_map('strtolower', $blocked), true);
    }

    /** Admin-configurable booking link for the demo CTA. */
    public static function booking_url(): string {
        $url = (string) get_option(self::DEMO_OPTION, '');
        return esc_url_raw(trim($url));
    }

    // ── Lead form (the public gate) ───────────────────────────────────────────

    public static function render_form($atts = []) {
        if (class_exists('VES_Assets')) { VES_Assets::enqueue(false); }
        if (is_user_logged_in()) {
            $url = class_exists('VES_Auth') ? VES_Auth::get_dashboard_url() : home_url('/');
            return '<div class="ves-auth-shell"><div class="ves-auth-card"><h2 class="ves-auth-title">Tu cuenta ya está activa</h2><div class="ves-auth-actions"><a class="ves-btn ves-btn-primary" href="' . esc_url($url) . '">Entrar al panel</a></div></div></div>';
        }
        $notice = (class_exists('VES_Auth') && method_exists('VES_Auth', 'render_notice_public')) ? VES_Auth::render_notice_public() : self::render_notice();
        $redirect = class_exists('VES_Auth') ? (method_exists('VES_Auth', 'get_scrapers_url') ? VES_Auth::get_scrapers_url() : VES_Auth::get_dashboard_url()) : home_url('/');
        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field(self::NONCE, 'ves_lead_nonce', true, false);
        $kicker = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';

        $html  = '<div class="ves-auth-shell ves-auth-shell-modern ves-lead-shell"><div class="ves-auth-layout">';
        $html .= '<aside class="ves-auth-benefits"><div class="ves-auth-kicker">' . esc_html($kicker) . '</div>';
        $html .= '<h1>Prueba la inteligencia social, gratis.</h1>';
        $html .= '<p>Completa tus datos y obtén <strong>10 análisis gratuitos</strong> de TikTok, YouTube e Instagram. Sin tarjeta.</p>';
        $html .= '<ul><li>10 análisis sociales incluidos</li><li>Evidencia y métricas reales</li><li>Demo gratuita para acceso ilimitado</li></ul></aside>';
        $html .= '<div class="ves-auth-card ves-auth-card-modern">' . $notice;
        $html .= '<h2 class="ves-auth-title">Empieza gratis</h2>';
        $html .= '<p class="ves-auth-sub">Rellena el formulario para acceder al panel.</p>';
        $html .= '<form method="post" action="' . $action . '" class="ves-auth-form ves-lead-form">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        $html .= '<input type="hidden" name="redirect_to" value="' . esc_attr($redirect) . '">';
        $html .= $nonce;
        // Honeypot (spam bots fill hidden fields).
        $html .= '<input type="text" name="ves_lead_company_extra" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">';
        $html .= self::field('first_name', 'Nombre', 'text', 'given-name', true);
        $html .= self::field('last_name', 'Apellidos', 'text', 'family-name', true);
        $html .= self::field('corp_email', 'Email corporativo', 'email', 'email', true);
        $html .= self::field('phone', 'Teléfono', 'tel', 'tel', true);
        $html .= self::field('linkedin', 'Perfil de LinkedIn', 'url', 'url', true, 'https://www.linkedin.com/in/…');
        $html .= self::field('position', 'Cargo en tu empresa', 'text', 'organization-title', true);
        $html .= '<button type="submit" class="ves-btn ves-btn-primary ves-auth-submit" data-ves-auth-submit>Acceder al panel</button>';
        $html .= '</form>';
        $login = class_exists('VES_Auth') ? VES_Auth::get_page_url('login') : '';
        if ($login !== '') { $html .= '<div class="ves-auth-foot">¿Ya tienes cuenta? <a href="' . esc_url($login) . '">Iniciar sesión</a></div>'; }
        $html .= '</div></div></div>';
        return $html;
    }

    private static function field($name, $label, $type, $autocomplete, $required, $placeholder = '') {
        return '<label class="ves-label">' . esc_html($label) . '</label>'
            . '<input class="ves-input" type="' . esc_attr($type) . '" name="' . esc_attr(self::META_PREFIX . $name) . '"'
            . ' autocomplete="' . esc_attr($autocomplete) . '"' . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
            . ($required ? ' required' : '') . '>';
    }

    // ── Handler (anonymous account creation) ────────────────────────────────────

    public static function handle() {
        $back = self::gate_url();
        if (!isset($_POST['ves_lead_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ves_lead_nonce'])), self::NONCE)) {
            self::bounce($back, 'error', 'La sesión del formulario ha caducado. Inténtalo de nuevo.');
        }
        // Honeypot — silently bounce bots.
        if (!empty($_POST['ves_lead_company_extra'])) { self::bounce($back, 'error', 'No se pudo procesar el formulario.'); }

        $first = self::clean($_POST[self::META_PREFIX . 'first_name'] ?? '');
        $last  = self::clean($_POST[self::META_PREFIX . 'last_name'] ?? '');
        $email = sanitize_email((string) wp_unslash($_POST[self::META_PREFIX . 'corp_email'] ?? ''));
        $phone = self::clean($_POST[self::META_PREFIX . 'phone'] ?? '');
        $linkedin = esc_url_raw(trim((string) wp_unslash($_POST[self::META_PREFIX . 'linkedin'] ?? '')));
        $position = self::clean($_POST[self::META_PREFIX . 'position'] ?? '');

        if ($first === '' || $last === '' || $email === '' || $phone === '' || $linkedin === '' || $position === '') {
            self::bounce($back, 'error', 'Completa todos los campos para continuar.');
        }
        if (!is_email($email)) {
            self::bounce($back, 'error', 'Introduce un email válido.');
        }
        if (self::is_free_email_domain($email)) {
            self::bounce($back, 'error', 'Usa tu email corporativo (no se admiten correos personales como Gmail, Outlook o Yahoo).');
        }
        if (!self::is_linkedin_url($linkedin)) {
            self::bounce($back, 'error', 'Introduce un enlace de LinkedIn válido.');
        }
        if (self::rate_limited()) {
            self::bounce($back, 'error', 'Demasiadas solicitudes desde esta red. Espera unos minutos.');
        }

        // De-dupe. SECURITY: never auto-login an existing account from this
        // passwordless form — doing so would let anyone sign in just by entering a
        // known email/LinkedIn (account takeover). Send them to sign in instead.
        $existing = get_user_by('email', $email);
        if ($existing instanceof WP_User) {
            self::bounce($back, 'error', 'Ya existe una cuenta con ese email. Inicia sesión para continuar.');
        }
        $linkedin_norm = self::normalize_linkedin($linkedin);
        if ($linkedin_norm !== '' && function_exists('get_users')) {
            $li_dupe = get_users([
                'meta_key'   => self::META_PREFIX . 'linkedin_norm',
                'meta_value' => $linkedin_norm,
                'number'     => 1,
                'fields'     => 'ID',
            ]);
            if (!empty($li_dupe)) {
                self::bounce($back, 'error', 'Ya existe una cuenta asociada a ese perfil de LinkedIn. Inicia sesión para continuar.');
            }
        }

        $username = self::unique_username($email);
        $password = wp_generate_password(20, true, false);
        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            self::bounce($back, 'error', 'No se pudo crear la cuenta. Inténtalo de nuevo.');
        }
        $role = class_exists('VES_Config') ? VES_Config::registration_role() : 'ves_customer';
        wp_update_user([
            'ID' => (int) $user_id,
            'display_name' => trim($first . ' ' . $last),
            'first_name' => $first,
            'last_name' => $last,
            'nickname' => trim($first . ' ' . $last),
            'role' => $role ?: 'ves_customer',
        ]);
        // Persist the lead profile (admin can read it on the user/CRM side).
        update_user_meta($user_id, self::META_PREFIX . 'first_name', $first);
        update_user_meta($user_id, self::META_PREFIX . 'last_name', $last);
        update_user_meta($user_id, self::META_PREFIX . 'corp_email', $email);
        update_user_meta($user_id, self::META_PREFIX . 'phone', $phone);
        update_user_meta($user_id, self::META_PREFIX . 'linkedin', $linkedin);
        update_user_meta($user_id, self::META_PREFIX . 'linkedin_norm', $linkedin_norm);
        update_user_meta($user_id, self::META_PREFIX . 'position', $position);
        update_user_meta($user_id, self::META_PREFIX . 'source', 'public_lead_gate');
        update_user_meta($user_id, self::META_PREFIX . 'created_at', gmdate('Y-m-d H:i:s'));

        // Notify the operator that a new lead signed up (best-effort; never blocks signup).
        self::notify_new_lead((int) $user_id, [
            'name' => trim($first . ' ' . $last), 'email' => $email, 'phone' => $phone,
            'linkedin' => $linkedin, 'position' => $position,
        ]);

        // Critical: put lead users on the FREE plan with its 10-request allotment.
        // New users otherwise default to the configured default plan (e.g. "starter"
        // + 100 credits), which would bypass the Social-only restriction and the
        // 10-request cap entirely.
        self::provision_free_plan((int) $user_id);

        self::mark_rate();
        self::login_and_redirect((int) $user_id);
    }

    /**
     * Force a user onto the FREE plan and (re)base their balance to the free
     * plan's signup allotment. Idempotent-ish and fully guarded. Public for tests.
     * @return float resulting balance (or -1 if billing is unavailable).
     */
    public static function provision_free_plan($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !class_exists('VES_Usage_Billing')) { return -1.0; }
        try {
            if (method_exists('VES_Usage_Billing', 'set_user_plan')) {
                VES_Usage_Billing::set_user_plan($user_id, 'free', false);
            }
            $signup = 25.0;
            if (method_exists('VES_Usage_Billing', 'get_plan')) {
                $free = VES_Usage_Billing::get_plan('free');
                if (is_array($free) && isset($free['signup_credits'])) { $signup = max(0.0, (float) $free['signup_credits']); }
            }
            // Re-base the balance to exactly the free allotment via the ledgered
            // adjust API (the user_register hook may have awarded the default plan's
            // larger amount).
            if (method_exists('VES_Usage_Billing', 'get_balance') && method_exists('VES_Usage_Billing', 'adjust_user_credits')) {
                $current = (float) VES_Usage_Billing::get_balance($user_id);
                $delta = round($signup - $current, 2);
                if (abs($delta) > 0.001) {
                    VES_Usage_Billing::adjust_user_credits($user_id, $delta, 'Inicialización plan gratuito (10 solicitudes sociales)', ['source' => 'lead_gate', 'plan' => 'free']);
                }
                return (float) VES_Usage_Billing::get_balance($user_id);
            }
            return $signup;
        } catch (\Throwable $e) {
            self::log($e);
            return -1.0;
        }
    }

    private static function login_and_redirect($user_id) {
        wp_set_current_user((int) $user_id);
        wp_set_auth_cookie((int) $user_id, true, is_ssl());
        $redirect = isset($_POST['redirect_to']) ? esc_url_raw((string) wp_unslash($_POST['redirect_to'])) : '';
        if ($redirect === '') {
            $redirect = (class_exists('VES_Auth') && method_exists('VES_Auth', 'get_scrapers_url')) ? VES_Auth::get_scrapers_url() : home_url('/');
        }
        wp_safe_redirect($redirect ?: home_url('/'));
        exit;
    }

    // ── Demo CTA (shown when a free non-admin runs out of credits) ──────────────

    /** Should the demo CTA show for this user? Non-admin with < one social request left. */
    public static function should_show_demo($user_id): bool {
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return false; }
        if (function_exists('user_can') && user_can($user_id, 'manage_options')) { return false; }
        if (!class_exists('VES_Usage_Billing')) { return false; }
        // One heavy social request ≈ 2.5 credits; show the CTA once they can't afford one.
        $cost = method_exists('VES_Usage_Billing', 'estimate_for_run') ? (float) VES_Usage_Billing::estimate_for_run('instagram') : 2.5;
        if ($cost <= 0) { $cost = 2.5; }
        $balance = method_exists('VES_Usage_Billing', 'get_balance') ? (float) VES_Usage_Billing::get_balance($user_id) : 0.0;
        return $balance < $cost;
    }

    public static function demo_cta_html($user_id = 0): string {
        $url = self::booking_url();
        $btn = $url !== ''
            ? '<a class="ves-btn ves-btn-primary" href="' . esc_url($url) . '" target="_blank" rel="noopener">Reservar demo gratuita (30 min)</a>'
            : '<a class="ves-btn ves-btn-primary" href="' . esc_url('mailto:' . sanitize_email((string) get_option('admin_email'))) . '">Solicitar una demo</a>';
        return '<div class="ves-demo-cta" role="region" aria-label="Demo">'
            . '<div class="ves-demo-cta-body">'
            . '<strong>Has usado tus 10 solicitudes gratuitas.</strong> '
            . 'Reserva una sesión gratuita de 30 minutos para desbloquear el uso ilimitado del SaaS.'
            . '</div><div class="ves-demo-cta-actions">' . $btn . '</div></div>';
    }

    // ── (c) Visible "N of 10 requests" counter (server-rendered strip) ──────────

    private static function request_cost(): float {
        $cost = (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'estimate_for_run'))
            ? (float) VES_Usage_Billing::estimate_for_run('instagram') : 2.5;
        return $cost > 0 ? $cost : 2.5;
    }

    public static function requests_total(): int {
        return self::FREE_REQUESTS;
    }

    public static function requests_remaining($user_id): int {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !class_exists('VES_Usage_Billing') || !method_exists('VES_Usage_Billing', 'get_balance')) { return 0; }
        $balance = (float) VES_Usage_Billing::get_balance($user_id);
        $n = (int) floor($balance / self::request_cost());
        return max(0, min(self::requests_total(), $n));
    }

    /** Show the counter for a non-admin who still has at least one request left. */
    public static function should_show_requests_status($user_id): bool {
        $user_id = (int) $user_id;
        if ($user_id <= 0) { return false; }
        if (function_exists('user_can') && user_can($user_id, 'manage_options')) { return false; }
        return self::requests_remaining($user_id) > 0;
    }

    public static function requests_status_html($user_id = 0): string {
        $left = self::requests_remaining($user_id);
        $total = self::requests_total();
        $pct = $total > 0 ? max(0, min(100, (int) round(($left / $total) * 100))) : 0;
        return '<div class="ves-requests-pill" role="status" aria-label="Solicitudes gratuitas restantes">'
            . '<span class="ves-requests-pill-label">Solicitudes gratuitas</span>'
            . '<span class="ves-requests-pill-count"><strong>' . (int) $left . '</strong> / ' . (int) $total . '</span>'
            . '<span class="ves-requests-pill-bar"><span class="ves-requests-pill-fill" style="width:' . $pct . '%"></span></span>'
            . '</div>';
    }

    // ── (b) Captured leads on the Users list + CSV export ───────────────────────

    public static function add_users_columns($columns) {
        if (!is_array($columns)) { return $columns; }
        $columns['ves_lead'] = 'Lead (FIIS)';
        return $columns;
    }

    public static function render_users_column($value, $column_name, $user_id) {
        if ($column_name !== 'ves_lead') { return $value; }
        $pos = (string) get_user_meta($user_id, self::META_PREFIX . 'position', true);
        $src = (string) get_user_meta($user_id, self::META_PREFIX . 'source', true);
        if ($pos === '' && $src === '') { return '—'; }
        $li = (string) get_user_meta($user_id, self::META_PREFIX . 'linkedin', true);
        $out = $pos !== '' ? '<strong>' . esc_html($pos) . '</strong>' : '';
        if ($li !== '') { $out .= '<br><a href="' . esc_url($li) . '" target="_blank" rel="noopener">LinkedIn</a>'; }
        if ($src !== '') { $out .= '<br><span style="color:#646970;font-size:11px">' . esc_html($src) . '</span>'; }
        return $out !== '' ? $out : '—';
    }

    /** Rows for the CSV export (pure-ish; queries lead users). Exposed for tests. */
    public static function export_rows(): array {
        $rows = [['name', 'corp_email', 'phone', 'linkedin', 'position', 'source', 'created_at', 'credit_balance']];
        if (!function_exists('get_users')) { return $rows; }
        $role = class_exists('VES_Usage_Billing') && defined('VES_Usage_Billing::CUSTOMER_ROLE') ? VES_Usage_Billing::CUSTOMER_ROLE : 'ves_customer';
        $users = get_users(['role' => $role, 'fields' => ['ID', 'display_name', 'user_email'], 'number' => 5000]);
        foreach ((array) $users as $u) {
            $id = (int) (is_object($u) ? $u->ID : ($u['ID'] ?? 0));
            if ($id <= 0) { continue; }
            $balance = (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'get_balance')) ? VES_Usage_Billing::get_balance($id) : '';
            $rows[] = [
                (string) get_user_meta($id, self::META_PREFIX . 'first_name', true) . ' ' . (string) get_user_meta($id, self::META_PREFIX . 'last_name', true),
                (string) (get_user_meta($id, self::META_PREFIX . 'corp_email', true) ?: (is_object($u) ? $u->user_email : '')),
                (string) get_user_meta($id, self::META_PREFIX . 'phone', true),
                (string) get_user_meta($id, self::META_PREFIX . 'linkedin', true),
                (string) get_user_meta($id, self::META_PREFIX . 'position', true),
                (string) get_user_meta($id, self::META_PREFIX . 'source', true),
                (string) get_user_meta($id, self::META_PREFIX . 'created_at', true),
                (string) $balance,
            ];
        }
        return $rows;
    }

    /**
     * Neutralize CSV formula/DDE injection. A spreadsheet treats a cell starting
     * with = + - @ (or a leading TAB/CR) as a formula, so a lead who registers
     * with a name/position like "=cmd|'/c calc'!A0" could execute code on the
     * operator's machine when they open the export. We prefix any such cell with
     * a single quote so it is rendered as literal text. Exposed for tests.
     */
    public static function csv_safe_cell($value): string {
        $value = (string) $value;
        if ($value === '') { return $value; }
        $first = $value[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r") {
            return "'" . $value;
        }
        return $value;
    }

    public static function handle_export_leads() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient capability'); }
        check_admin_referer(self::EXPORT);
        $rows = self::export_rows();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=fiis-leads-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        $first_row = true;
        foreach ($rows as $row) {
            // The header row is plugin-controlled; only sanitize data rows.
            if ($first_row) { $first_row = false; }
            else { $row = array_map([__CLASS__, 'csv_safe_cell'], (array) $row); }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /**
     * Move existing non-admin customers onto the restricted FREE tier (Social +
     * TikTok/YouTube/Instagram, 10 requests). Skips admins and users already on
     * free (so it never resets an in-progress free balance). Bounded per call.
     */
    public static function migrate_existing_to_free($max = 500): array {
        $out = ['migrated' => 0, 'skipped' => 0, 'scanned' => 0];
        if (!function_exists('get_users') || !class_exists('VES_Usage_Billing')) { return $out; }
        try {
            $role = defined('VES_Usage_Billing::CUSTOMER_ROLE') ? VES_Usage_Billing::CUSTOMER_ROLE : 'ves_customer';
            $users = get_users(['role' => $role, 'fields' => ['ID'], 'number' => (int) $max]);
            foreach ((array) $users as $u) {
                $id = (int) (is_object($u) ? $u->ID : ($u['ID'] ?? 0));
                if ($id <= 0) { continue; }
                $out['scanned']++;
                if (function_exists('user_can') && user_can($id, 'manage_options')) { $out['skipped']++; continue; }
                $current = method_exists('VES_Usage_Billing', 'get_user_plan_slug') ? VES_Usage_Billing::get_user_plan_slug($id) : '';
                if ($current === 'free') { $out['skipped']++; continue; }
                self::provision_free_plan($id);
                $out['migrated']++;
            }
        } catch (\Throwable $e) { self::log($e); }
        return $out;
    }

    public static function count_non_free_customers(): int {
        if (!function_exists('get_users')) { return 0; }
        try {
            $role = defined('VES_Usage_Billing::CUSTOMER_ROLE') ? VES_Usage_Billing::CUSTOMER_ROLE : 'ves_customer';
            $ids = get_users(['role' => $role, 'fields' => 'ID', 'number' => 501]);
            $n = 0;
            foreach ((array) $ids as $id) {
                $id = (int) $id;
                if (function_exists('user_can') && user_can($id, 'manage_options')) { continue; }
                $slug = (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'get_user_plan_slug')) ? VES_Usage_Billing::get_user_plan_slug($id) : 'free';
                if ($slug !== 'free') { $n++; }
            }
            return $n;
        } catch (\Throwable $e) { self::log($e); return 0; }
    }

    /**
     * Write a coherent free-tier limit preset to the Credits & Limits store so the
     * "10 requests" model actually works: no daily cap (so the monthly cap governs),
     * 10 runs/month, and per-run cost room. Removes a too-strict max_daily_runs that
     * blocks free users after a couple of runs. Preserves the access map sections.
     */
    public static function apply_recommended_free_limits(): bool {
        if (!class_exists('VES_Access_Control') || !function_exists('get_option') || !function_exists('update_option')) { return false; }
        try {
            $opt = VES_Access_Control::OPT_PLAN_ACCESS;
            $map = get_option($opt, []);
            if (!is_array($map)) { $map = []; }
            if (!isset($map['free']) || !is_array($map['free'])) { $map['free'] = []; }
            $map['free']['limits'] = [
                'monthly_credits'                 => 0,   // one-time allotment, no refill
                'signup_credits'                  => 25,  // 10 social analyses
                'max_credits_per_run'             => 10,  // room for a heavy social run
                'max_daily_runs'                  => 0,   // no daily cap → monthly cap governs
                'max_monthly_runs'                => 10,  // the "10 requests" cap
                'max_concurrent_runs'             => 2,
                'max_ai_analyses_per_day'         => 0,
                'max_ai_analyses_per_month'       => 10,
                'max_selected_items_per_analysis' => 10,
                'max_provider_cost_per_run_usd'   => 5,
            ];
            return (bool) update_option($opt, $map, false);
        } catch (\Throwable $e) {
            self::log($e);
            return false;
        }
    }

    public static function handle_migrate() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient capability'); }
        check_admin_referer(self::MIGRATE);
        // One click: fix the free-tier limits (removes the daily-cap blocker) AND
        // move existing non-admin users onto the free plan.
        self::apply_recommended_free_limits();
        $res = self::migrate_existing_to_free(500);
        $back = wp_get_referer() ?: admin_url('admin.php?page=ves-fiis-console&tab=access');
        wp_safe_redirect(add_query_arg('ves_migrated', (int) $res['migrated'], $back));
        exit;
    }

    public static function handle_save_demo_url() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient capability'); }
        check_admin_referer(self::SAVE_DEMO);
        $url = esc_url_raw(trim((string) wp_unslash($_POST['ves_demo_booking_url'] ?? '')));
        update_option(self::DEMO_OPTION, $url, false);
        $back = wp_get_referer() ?: admin_url('admin.php?page=ves-fiis-console&tab=access');
        wp_safe_redirect(add_query_arg('ves_demo_saved', '1', $back));
        exit;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private static function clean($v): string {
        return sanitize_text_field((string) wp_unslash(is_scalar($v) ? $v : ''));
    }

    /**
     * Strict LinkedIn URL check. A bare substring match ("linkedin.com" anywhere)
     * accepts hostile inputs like https://evil.com/?x=linkedin.com, so we parse
     * the host and require it to be linkedin.com or a *.linkedin.com subdomain.
     * Exposed for tests.
     */
    public static function is_linkedin_url($url): bool {
        $url = trim((string) $url);
        if ($url === '') { return false; }
        // Require an absolute http(s) URL so parse_url resolves a real host.
        if (!preg_match('#^https?://#i', $url)) { return false; }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') { return false; }
        $host = strtolower($host);
        return $host === 'linkedin.com' || substr($host, -13) === '.linkedin.com';
    }

    /** Canonical LinkedIn key for dedupe: lowercase, no scheme/www, no trailing slash. */
    public static function normalize_linkedin($url): string {
        $u = strtolower(trim((string) $url));
        $u = preg_replace('#^https?://#', '', $u);
        $u = preg_replace('#^www\.#', '', $u);
        $u = preg_replace('~[?\#].*$~', '', $u); // drop query/fragment
        return rtrim($u, '/');
    }

    /** Best-effort admin notification for a new public lead. Never blocks signup. */
    private static function notify_new_lead($user_id, array $lead): void {
        try {
            if (!function_exists('wp_mail') || !function_exists('apply_filters')) { return; }
            $to = apply_filters('ves_lead_notification_recipient', (string) get_option('admin_email'));
            if (apply_filters('ves_lead_notifications_enabled', true) !== true || empty($to)) { return; }
            $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'FIIS';
            $subject = sprintf('[%s] New free-trial lead: %s', $site, $lead['name'] ?: $lead['email']);
            $lines = [
                'A new lead signed up for the free trial.',
                '',
                'Name:     ' . ($lead['name'] ?? ''),
                'Email:    ' . ($lead['email'] ?? ''),
                'Phone:    ' . ($lead['phone'] ?? ''),
                'LinkedIn: ' . ($lead['linkedin'] ?? ''),
                'Position: ' . ($lead['position'] ?? ''),
                'User ID:  ' . (int) $user_id,
                '',
                'Manage in WP Admin → Users, or export from Intelligence Suite → Access & Credits.',
            ];
            wp_mail($to, $subject, implode("\n", $lines));
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    private static function unique_username($email): string {
        $base = sanitize_user(current(explode('@', (string) $email)), true);
        $base = $base !== '' ? $base : 'lead';
        $candidate = $base;
        $i = 0;
        while (username_exists($candidate)) {
            $i++;
            $candidate = $base . $i . substr(wp_generate_password(4, false, false), 0, 4);
            if ($i > 20) { $candidate = 'lead_' . wp_generate_password(10, false, false); break; }
        }
        return $candidate;
    }

    private static function rate_key(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR']) : 'unknown';
        return 'ves_lead_rate_' . md5($ip);
    }
    private static function rate_limited(): bool {
        $n = (int) get_transient(self::rate_key());
        return $n >= self::RATE_MAX;
    }
    private static function mark_rate(): void {
        $key = self::rate_key();
        $n = (int) get_transient($key);
        set_transient($key, $n + 1, self::RATE_WINDOW);
    }

    private static function gate_url(): string {
        if (class_exists('VES_Auth') && method_exists('VES_Auth', 'get_scrapers_url')) {
            $u = VES_Auth::get_scrapers_url();
            if ($u) { return $u; }
        }
        return home_url('/');
    }

    private static function bounce($url, $type, $message) {
        $token = wp_generate_password(24, false, false);
        set_transient('ves_lead_notice_' . $token, ['type' => sanitize_key($type), 'message' => sanitize_text_field($message)], 5 * MINUTE_IN_SECONDS);
        setcookie('ves_lead_notice', $token, [
            'expires' => time() + 300, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        wp_safe_redirect($url);
        exit;
    }

    private static function render_notice(): string {
        if (empty($_COOKIE['ves_lead_notice'])) { return ''; }
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $_COOKIE['ves_lead_notice']);
        $data = get_transient('ves_lead_notice_' . $token);
        if (!is_array($data)) { return ''; }
        delete_transient('ves_lead_notice_' . $token);
        $type = ($data['type'] ?? 'error') === 'success' ? 'success' : 'error';
        return '<div class="ves-auth-notice ves-auth-notice-' . esc_attr($type) . '">' . esc_html((string) ($data['message'] ?? '')) . '</div>';
    }
}
