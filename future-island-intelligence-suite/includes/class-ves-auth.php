<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Auth {
    const OPTION_KEY = 'ves_auth_pages';

    public static function register() {
        add_shortcode('ves_social_login', [__CLASS__, 'render_login_shortcode']);
        add_shortcode('ves_social_register', [__CLASS__, 'render_register_shortcode']);
        add_shortcode('ves_social_panel', [__CLASS__, 'render_panel_shortcode']);
        add_shortcode('ves_social_scrapers', [__CLASS__, 'render_scrapers_shortcode']);
        add_action('admin_post_nopriv_ves_auth_login', [__CLASS__, 'handle_login']);
        add_action('admin_post_ves_auth_login', [__CLASS__, 'handle_login']);
        add_action('admin_post_nopriv_ves_auth_register', [__CLASS__, 'handle_register']);
        add_action('admin_post_ves_auth_register', [__CLASS__, 'handle_register']);
    }

    public static function activate() {
        self::ensure_pages(true);
    }

    public static function maybe_ensure_pages() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        self::ensure_pages(false);
    }

    private static function page_defaults() {
        return [
            'login' => [
                'slug' => 'ves-saas-login',
                'title' => 'SaaS Login',
                'content' => '[ves_social_login]',
            ],
            'register' => [
                'slug' => 'ves-saas-register',
                'title' => 'SaaS Register',
                'content' => '[ves_social_register]',
            ],
            'dashboard' => [
                'slug' => 'ves-saas-dashboard',
                'title' => 'Mi Panel SaaS',
                'content' => '[ves_dashboard]',
            ],
            'scrapers' => [
                'slug' => 'ves-saas-scrapers',
                'title' => 'SaaS Scrapers',
                'content' => '[vietnam_social_scraper]',
            ],
        ];
    }

    public static function ensure_pages($force = false) {
        $pages = get_option(self::OPTION_KEY, []);
        if (!is_array($pages)) {
            $pages = [];
        }

        foreach (self::page_defaults() as $key => $cfg) {
            $page = null;
            $existing_id = isset($pages[$key]) ? (int) $pages[$key] : 0;
            $stored_post = $existing_id > 0 ? get_post($existing_id) : null;

            if ($stored_post && $stored_post->post_type === 'page' && $stored_post->post_status !== 'trash') {
                $page = $stored_post;
            } else {
                $found = get_page_by_path($cfg['slug']);
                if ($found instanceof WP_Post && $found->post_type === 'page' && $found->post_status !== 'trash') {
                    $page = $found;
                }
            }

            if ($page instanceof WP_Post) {
                $pages[$key] = (int) $page->ID;
                self::repair_managed_page($page, $key, $cfg, $force);
                continue;
            }

            $page_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $cfg['title'],
                'post_name' => $cfg['slug'],
                'post_content' => $cfg['content'],
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ], true);

            if (!is_wp_error($page_id) && $page_id) {
                $pages[$key] = (int) $page_id;
            }
        }

        update_option(self::OPTION_KEY, $pages, false);
        delete_transient('ves_dashboard_page_url');
        delete_transient('ves_scrapers_page_url');
    }

    private static function repair_managed_page(WP_Post $page, $key, array $cfg, $force = false) {
        $content = (string) $page->post_content;
        $expected_shortcode = (string) $cfg['content'];
        $has_expected_shortcode = strpos($content, $expected_shortcode) !== false;
        $has_any_ves_shortcode = (bool) preg_match('/\[(ves_dashboard|vietnam_social_scraper|ves_social_panel|ves_social_login|ves_social_register|ves_social_scrapers)\b/', $content);
        $is_legacy_combined_dashboard = ($key === 'dashboard' && strpos($content, '[ves_social_panel') !== false && strpos($content, '[ves_dashboard') === false);

        $should_repair_content = $force || $is_legacy_combined_dashboard || !$has_any_ves_shortcode || !$has_expected_shortcode;
        $updates = ['ID' => (int) $page->ID];

        if ($should_repair_content) {
            $updates['post_content'] = $expected_shortcode;
        }
        if ((string) $page->post_title !== (string) $cfg['title']) {
            $updates['post_title'] = $cfg['title'];
        }
        if ((string) $page->post_name !== (string) $cfg['slug']) {
            $updates['post_name'] = $cfg['slug'];
        }

        if (count($updates) > 1) {
            wp_update_post($updates);
        }
    }

    public static function get_page_id($key) {
        $pages = get_option(self::OPTION_KEY, []);
        $id = is_array($pages) ? (int) ($pages[$key] ?? 0) : 0;
        return $id > 0 ? $id : 0;
    }

    public static function get_page_url($key) {
        $id = self::get_page_id($key);
        return $id > 0 ? get_permalink($id) : home_url('/');
    }

    public static function get_dashboard_url() {
        return self::get_page_url('dashboard');
    }

    public static function get_scrapers_url() {
        return self::get_page_url('scrapers');
    }

    private static function notice_cookie_name() {
        return 'ves_auth_notice_' . COOKIEHASH;
    }

    private static function consume_notice_token() {
        $name = self::notice_cookie_name();
        if (empty($_COOKIE[$name])) {
            return null;
        }
        $token = sanitize_key((string) wp_unslash($_COOKIE[$name]));
        if ($token === '') {
            return null;
        }
        // Clear the cookie immediately so a second refresh doesn't re-show the notice.
        setcookie($name, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $payload = get_transient('ves_auth_notice_' . $token);
        delete_transient('ves_auth_notice_' . $token);
        return is_array($payload) ? $payload : null;
    }

    private static function render_notice() {
        // v0.9.8.0 bug fix: previous version read the notice text from $_GET, which
        // let anyone craft URLs with arbitrary "official" messages (phishing).
        // We now require a one-shot token issued by the server. Falls back to nothing.
        $payload = self::consume_notice_token();
        if (!$payload) {
            return '';
        }
        $type = isset($payload['type']) ? sanitize_key((string) $payload['type']) : '';
        $message = isset($payload['message']) ? sanitize_text_field((string) $payload['message']) : '';
        if ($type === '' || $message === '') {
            return '';
        }
        $class = $type === 'error' ? 'is-error' : 'is-success';
        return '<div class="ves-auth-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }



    private static function client_ip_hash() {
        $ip = '';
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = (string) wp_unslash($_SERVER[$key]);
                $ip = trim(explode(',', $raw)[0]);
                break;
            }
        }
        return md5($ip !== '' ? $ip : 'unknown');
    }

    private static function rate_key($type, $identity = '') {
        $identity = strtolower(trim((string) $identity));
        return 'ves_auth_rate_' . sanitize_key($type) . '_' . self::client_ip_hash() . '_' . md5($identity);
    }

    private static function rate_limited($type, $identity, $limit = 8) {
        return (int) get_transient(self::rate_key($type, $identity)) >= max(1, (int) $limit);
    }

    private static function record_failed_attempt($type, $identity, $window = 900) {
        $key = self::rate_key($type, $identity);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, max(60, (int) $window));
    }

    private static function clear_failed_attempts($type, $identity) {
        delete_transient(self::rate_key($type, $identity));
    }

    private static function password_field($name, $label, $attrs = '') {
        $id = 'ves_' . sanitize_key($name) . '_' . wp_generate_password(6, false, false);
        return '<label class="ves-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label><div class="ves-password-wrap"><input id="' . esc_attr($id) . '" class="ves-input" type="password" name="' . esc_attr($name) . '" ' . $attrs . '><button class="ves-password-toggle" type="button" data-ves-password-toggle aria-label="Mostrar contraseña">Mostrar</button></div>';
    }

    public static function render_panel_shortcode() {
        // Backward-compatible alias for old pages. It now renders only Mi Panel,
        // not the scraper workspace. Scrapers live on their own page.
        return do_shortcode('[ves_dashboard]');
    }

    public static function render_scrapers_shortcode() {
        // Optional alias for sites that prefer auth-named shortcodes.
        return do_shortcode('[vietnam_social_scraper]');
    }

    public static function render_gate() {
        // Public lead-gen entry: anonymous visitors fill the business form to get
        // 10 free social analyses. Falls back to the classic login/register gate
        // if the lead gate is unavailable.
        if (class_exists('VES_Lead_Gate')) {
            return VES_Lead_Gate::render_form();
        }
        VES_Assets::enqueue(false);
        $login = self::get_page_url('login');
        $register = self::get_page_url('register');
        return '<div class="ves-auth-shell"><div class="ves-auth-card"><h2 class="ves-auth-title">Accede a tu panel</h2><p class="ves-auth-sub">Para usar este panel necesitas una cuenta o iniciar sesión.</p><div class="ves-auth-actions"><a class="ves-btn ves-btn-primary" href="' . esc_url($login) . '">Iniciar sesión</a><a class="ves-btn ves-btn-secondary" href="' . esc_url($register) . '">Crear cuenta</a></div></div></div>';
    }

    public static function render_login_shortcode() {
        VES_Assets::enqueue(false);
        if (is_user_logged_in()) {
            return '<div class="ves-auth-shell"><div class="ves-auth-card"><h2 class="ves-auth-title">Ya has iniciado sesión</h2><p class="ves-auth-sub">Tu cuenta ya está activa.</p><div class="ves-auth-actions"><a class="ves-btn ves-btn-primary" href="' . esc_url(self::get_dashboard_url()) . '">Ir al panel</a></div></div></div>';
        }
        $notice = self::render_notice();
        $register = self::get_page_url('register');
        $lost_password = wp_lostpassword_url(self::get_page_url('login'));
        // v0.9.24.75 — kicker now comes from VES_Branding so it can be filtered per tenant.
        $kicker_company = class_exists('VES_Branding') ? VES_Branding::company_name() : '';
        $kicker_product = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';
        $kicker = trim($kicker_company !== '' ? $kicker_company : $kicker_product);
        $kicker_html = $kicker !== '' ? '<div class="ves-auth-kicker">' . esc_html($kicker) . '</div>' : '';
        return '<div class="ves-auth-shell ves-auth-shell-modern"><div class="ves-auth-layout"><aside class="ves-auth-benefits">' . $kicker_html . '<h1>Marketing intelligence that remembers your brand.</h1><p>Collect evidence, compare competitors, detect opportunities and move from research to action with an AI operator.</p><ul><li>Evidence-backed Brand Deep Audits</li><li>Workspace memory and saved research</li><li>Social, search, trends and creative intelligence</li></ul></aside><div class="ves-auth-card ves-auth-card-modern">' . $notice . '<h2 class="ves-auth-title">Iniciar sesión</h2><p class="ves-auth-sub">Accede a tu workspace de inteligencia y creación.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ves-auth-form"><input type="hidden" name="action" value="ves_auth_login">' . wp_nonce_field('ves_auth_login', 'ves_auth_nonce', true, false) . '<input type="hidden" name="redirect_to" value="' . esc_attr(self::get_dashboard_url()) . '"><label class="ves-label">Usuario o email</label><input class="ves-input" type="text" name="log" autocomplete="username" required>' . self::password_field('pwd', 'Contraseña', 'autocomplete="current-password" required') . '<div class="ves-auth-row"><label class="ves-checkline"><input type="checkbox" name="rememberme" value="1"><span>Recordarme</span></label><a href="' . esc_url($lost_password) . '">¿Olvidaste tu contraseña?</a></div><button type="submit" class="ves-btn ves-btn-primary ves-auth-submit" data-ves-auth-submit>Entrar</button></form><div class="ves-auth-foot">¿No tienes cuenta? <a href="' . esc_url($register) . '">Crear cuenta</a></div></div></div></div>';
    }

    public static function render_register_shortcode() {
        VES_Assets::enqueue(false);
        if (is_user_logged_in()) {
            return '<div class="ves-auth-shell"><div class="ves-auth-card"><h2 class="ves-auth-title">Tu cuenta ya está activa</h2><div class="ves-auth-actions"><a class="ves-btn ves-btn-primary" href="' . esc_url(self::get_dashboard_url()) . '">Ir al panel</a></div></div></div>';
        }
        $notice = self::render_notice();
        $login = self::get_page_url('login');
        $terms = home_url('/terms/');
        $privacy = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : home_url('/privacy-policy/');
        $kicker_company = class_exists('VES_Branding') ? VES_Branding::company_name() : '';
        $kicker_product = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';
        $kicker = trim($kicker_company !== '' ? $kicker_company : $kicker_product);
        $kicker_html = $kicker !== '' ? '<div class="ves-auth-kicker">' . esc_html($kicker) . '</div>' : '';
        return '<div class="ves-auth-shell ves-auth-shell-modern"><div class="ves-auth-layout"><aside class="ves-auth-benefits">' . $kicker_html . '<h1>Create a research-to-action workspace.</h1><p>Use credits for social/search collection, normalize evidence, keep company memory and generate briefs, campaigns and next actions.</p><ul><li>Persistent Brand Audit history</li><li>Evidence and opportunity memory</li><li>Assistant-ready marketing context</li></ul></aside><div class="ves-auth-card ves-auth-card-modern">' . $notice . '<h2 class="ves-auth-title">Crear cuenta</h2><p class="ves-auth-sub">Regístrate y entra directamente a tu panel SaaS.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ves-auth-form"><input type="hidden" name="action" value="ves_auth_register">' . wp_nonce_field('ves_auth_register', 'ves_auth_nonce', true, false) . '<input type="hidden" name="redirect_to" value="' . esc_attr(self::get_dashboard_url()) . '"><label class="ves-label">Nombre completo</label><input class="ves-input" type="text" name="display_name" autocomplete="name" required><label class="ves-label">Usuario</label><input class="ves-input" type="text" name="user_login" autocomplete="username" required><label class="ves-label">Email</label><input class="ves-input" type="email" name="user_email" autocomplete="email" required>' . self::password_field('user_pass', 'Contraseña', 'autocomplete="new-password" minlength="8" required') . self::password_field('user_pass_confirm', 'Repetir contraseña', 'autocomplete="new-password" minlength="8" required') . '<p class="ves-auth-terms">Al crear una cuenta aceptas los <a href="' . esc_url($terms) . '">términos</a> y la <a href="' . esc_url($privacy) . '">política de privacidad</a>.</p><button type="submit" class="ves-btn ves-btn-primary ves-auth-submit" data-ves-auth-submit>Crear cuenta</button></form><div class="ves-auth-foot">¿Ya tienes cuenta? <a href="' . esc_url($login) . '">Iniciar sesión</a></div></div></div></div>';
    }

    private static function redirect_with_notice($base, $type, $message) {
        // v0.9.8.0: store the notice server-side and pass only an opaque token
        // via cookie. Prevents URL-driven message spoofing.
        $token = wp_generate_password(24, false, false);
        set_transient('ves_auth_notice_' . $token, [
            'type' => sanitize_key((string) $type),
            'message' => sanitize_text_field((string) $message),
        ], 5 * MINUTE_IN_SECONDS);

        $name = self::notice_cookie_name();
        // Cookie is set with `httponly` and SameSite=Lax-equivalent semantics,
        // and same-origin since both pages live in the same WP install.
        setcookie($name, $token, [
            'expires' => time() + 5 * MINUTE_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        wp_safe_redirect($base);
        exit;
    }

    public static function handle_login() {
        $login_url = self::get_page_url('login');
        if (!isset($_POST['ves_auth_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ves_auth_nonce'])), 'ves_auth_login')) {
            self::redirect_with_notice($login_url, 'error', 'La sesión del formulario ha caducado.');
        }
        $login = sanitize_text_field((string) wp_unslash($_POST['log'] ?? ''));
        $password = (string) wp_unslash($_POST['pwd'] ?? '');
        $remember = !empty($_POST['rememberme']);
        if ($login === '' || $password === '') {
            self::record_failed_attempt('login', $login ?: 'empty');
            self::redirect_with_notice($login_url, 'error', 'Completa usuario/email y contraseña.');
        }
        if (self::rate_limited('login', $login, 8)) {
            self::redirect_with_notice($login_url, 'error', 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.');
        }
        if (is_email($login)) {
            $user = get_user_by('email', $login);
            if ($user instanceof WP_User) {
                $login = (string) $user->user_login;
            }
        }
        $user = wp_signon([
            'user_login' => $login,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());
        if (is_wp_error($user)) {
            self::record_failed_attempt('login', $login);
            self::redirect_with_notice($login_url, 'error', 'No se pudo iniciar sesión. Revisa tus datos.');
        }
        self::clear_failed_attempts('login', $login);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember, is_ssl());
        $redirect_to = esc_url_raw((string) wp_unslash($_POST['redirect_to'] ?? self::get_dashboard_url()));
        wp_safe_redirect($redirect_to ?: self::get_dashboard_url());
        exit;
    }

    public static function handle_register() {
        $register_url = self::get_page_url('register');
        if (!isset($_POST['ves_auth_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ves_auth_nonce'])), 'ves_auth_register')) {
            self::redirect_with_notice($register_url, 'error', 'La sesión del formulario ha caducado.');
        }
        $display_name = sanitize_text_field((string) wp_unslash($_POST['display_name'] ?? ''));
        $user_login = sanitize_user((string) wp_unslash($_POST['user_login'] ?? ''), true);
        $user_email = sanitize_email((string) wp_unslash($_POST['user_email'] ?? ''));
        $user_pass = (string) wp_unslash($_POST['user_pass'] ?? '');
        $user_pass_confirm = (string) wp_unslash($_POST['user_pass_confirm'] ?? '');
        if (self::rate_limited('register', $user_email ?: $user_login, 5)) {
            self::redirect_with_notice($register_url, 'error', 'Demasiados intentos de registro. Espera unos minutos antes de volver a intentarlo.');
        }
        if ($display_name === '' || $user_login === '' || $user_email === '' || $user_pass === '' || $user_pass_confirm === '') {
            self::record_failed_attempt('register', $user_email ?: $user_login ?: 'empty');
            self::redirect_with_notice($register_url, 'error', 'Completa todos los campos del registro.');
        }
        if (!is_email($user_email)) {
            self::record_failed_attempt('register', $user_email ?: $user_login);
            self::redirect_with_notice($register_url, 'error', 'Introduce un email válido.');
        }
        if ($user_pass !== $user_pass_confirm) {
            self::record_failed_attempt('register', $user_email ?: $user_login);
            self::redirect_with_notice($register_url, 'error', 'Las contraseñas no coinciden.');
        }
        if (strlen($user_pass) < 8) {
            self::record_failed_attempt('register', $user_email ?: $user_login);
            self::redirect_with_notice($register_url, 'error', 'La contraseña debe tener al menos 8 caracteres.');
        }
        if (username_exists($user_login) || email_exists($user_email)) {
            self::record_failed_attempt('register', $user_email ?: $user_login);
            self::redirect_with_notice($register_url, 'error', 'No se pudo crear la cuenta con esos datos.');
        }
        $user_id = wp_create_user($user_login, $user_pass, $user_email);
        if (is_wp_error($user_id)) {
            self::record_failed_attempt('register', $user_email ?: $user_login);
            self::redirect_with_notice($register_url, 'error', 'No se pudo crear la cuenta.');
        }
        self::clear_failed_attempts('register', $user_email ?: $user_login);
        wp_update_user([
            'ID' => (int) $user_id,
            'display_name' => $display_name,
            'nickname' => $display_name,
            'first_name' => $display_name,
            'role' => class_exists('VES_Config') ? VES_Config::registration_role() : 'subscriber',
        ]);
        wp_set_current_user((int) $user_id);
        wp_set_auth_cookie((int) $user_id, true, is_ssl());
        $redirect_to = esc_url_raw((string) wp_unslash($_POST['redirect_to'] ?? self::get_dashboard_url()));
        wp_safe_redirect($redirect_to ?: self::get_dashboard_url());
        exit;
    }
}
