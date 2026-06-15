<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Environment-aware provider controls for private-beta callbacks.
 *
 * Secrets may be stored in WordPress options, but this service never returns
 * the raw secret to UI, reports or REST responses. Public/status methods only
 * expose secret_configured booleans and redacted summaries.
 */
final class VES_Provider_Settings_Service {
    const OPTION = 'ves_provider_settings_v1';
    const ENV_OPTION = 'ves_fi_environment';
    const DEFAULT_MAX_ROWS = 50;
    const DEFAULT_CALLBACKS_PER_HOUR = 120;

    public static function environment(): string {
        $env = function_exists('get_option') ? (string) get_option(self::ENV_OPTION, '') : '';
        $env = self::clean_key($env, 20);
        if ($env === '' && defined('WP_ENVIRONMENT_TYPE')) { $env = self::clean_key((string) WP_ENVIRONMENT_TYPE, 20); }
        if (!in_array($env, ['local','development','staging','production'], true)) { $env = 'local'; }
        return $env === 'development' ? 'local' : $env;
    }

    public static function set_environment(string $environment): bool {
        $environment = self::clean_key($environment, 20);
        if (!in_array($environment, ['local','staging','production'], true)) { $environment = 'local'; }
        return function_exists('update_option') ? (bool) update_option(self::ENV_OPTION, $environment, false) : false;
    }

    public static function all(): array {
        $raw = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        return is_array($raw) ? $raw : [];
    }

    public static function get(string $family, string $provider_key): array {
        $family = self::clean_key($family, 60);
        $provider_key = self::clean_key($provider_key, 80);
        $contract = class_exists('VES_Provider_Contract_Service') ? VES_Provider_Contract_Service::get_contract($family, $provider_key) : null;
        $stored = self::all();
        $overrides = is_array($stored[$family . ':' . $provider_key] ?? null) ? $stored[$family . ':' . $provider_key] : [];
        $env = self::environment();
        $auth_default = in_array($env, ['staging','production'], true) ? 'hmac_signature' : 'wordpress_auth_only';
        $enabled_default = is_array($contract) ? !empty($contract['enabled']) : false;
        $settings = array_merge([
            'provider_family' => $family,
            'provider_key' => $provider_key,
            'schema_version' => is_array($contract) ? (string) ($contract['schema_version'] ?? 'v1') : 'unknown',
            'environment' => $env,
            'enabled' => $enabled_default,
            'auth_mode' => $auth_default,
            'secret' => '',
            'rate_limit_policy' => 'private_beta_default',
            'max_rows_per_callback' => self::DEFAULT_MAX_ROWS,
            'max_callbacks_per_hour' => self::DEFAULT_CALLBACKS_PER_HOUR,
            'allowed_workspace_ids' => [],
            'last_seen' => '',
            'last_error' => '',
            'updated_at' => '',
        ], $overrides);
        $settings['provider_family'] = $family;
        $settings['provider_key'] = $provider_key;
        $settings['environment'] = $env;
        $settings['enabled'] = !empty($settings['enabled']) && $enabled_default;
        $settings['auth_mode'] = self::auth_mode((string) ($settings['auth_mode'] ?? $auth_default));
        $settings['max_rows_per_callback'] = max(1, min(500, (int) ($settings['max_rows_per_callback'] ?? self::DEFAULT_MAX_ROWS)));
        $settings['max_callbacks_per_hour'] = max(1, min(5000, (int) ($settings['max_callbacks_per_hour'] ?? self::DEFAULT_CALLBACKS_PER_HOUR)));
        $settings['allowed_workspace_ids'] = self::int_list($settings['allowed_workspace_ids'] ?? []);
        $settings['secret_configured'] = self::secret_configured($settings);
        return $settings;
    }

    public static function public_status(string $family, string $provider_key): array {
        $s = self::get($family, $provider_key);
        unset($s['secret']);
        $s['secret_configured'] = !empty($s['secret_configured']);
        return class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($s) : $s;
    }

    public static function public_statuses(): array {
        $out = [];
        if (class_exists('VES_Provider_Contract_Service')) {
            foreach (VES_Provider_Contract_Service::public_contracts() as $contract) {
                $out[] = self::public_status((string) ($contract['provider_family'] ?? ''), (string) ($contract['provider_key'] ?? ''));
            }
        }
        return $out;
    }

    /** @return true|WP_Error */
    public static function set(string $family, string $provider_key, array $args) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::err('ves_provider_settings_forbidden', 'Only administrators can update provider settings.'); }
        $family = self::clean_key($family, 60);
        $provider_key = self::clean_key($provider_key, 80);
        if ($family === '' || $provider_key === '') { return self::err('ves_provider_settings_bad_key', 'Provider family and key are required.'); }
        $all = self::all();
        $existing = is_array($all[$family . ':' . $provider_key] ?? null) ? $all[$family . ':' . $provider_key] : [];
        $row = $existing;
        if (array_key_exists('enabled', $args)) { $row['enabled'] = !empty($args['enabled']); }
        if (array_key_exists('auth_mode', $args)) { $row['auth_mode'] = self::auth_mode((string) $args['auth_mode']); }
        if (array_key_exists('rate_limit_policy', $args)) { $row['rate_limit_policy'] = self::clean_key((string) $args['rate_limit_policy'], 80); }
        if (array_key_exists('max_rows_per_callback', $args)) { $row['max_rows_per_callback'] = max(1, min(500, (int) $args['max_rows_per_callback'])); }
        if (array_key_exists('max_callbacks_per_hour', $args)) { $row['max_callbacks_per_hour'] = max(1, min(5000, (int) $args['max_callbacks_per_hour'])); }
        if (array_key_exists('allowed_workspace_ids', $args)) { $row['allowed_workspace_ids'] = self::int_list($args['allowed_workspace_ids']); }
        if (array_key_exists('secret', $args) && (string) $args['secret'] !== '') { $row['secret'] = self::clean_secret((string) $args['secret']); }
        $row['updated_at'] = self::now();
        $all[$family . ':' . $provider_key] = $row;
        return function_exists('update_option') ? (update_option(self::OPTION, $all, false) ? true : self::err('ves_provider_settings_save_failed', 'Provider settings could not be saved.')) : self::err('ves_provider_settings_no_options', 'Options API unavailable.');
    }

    /** @return string|WP_Error */
    public static function rotate_secret(string $family, string $provider_key) {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) { return self::err('ves_provider_settings_forbidden', 'Only administrators can rotate provider secrets.'); }
        $secret = self::generate_secret();
        $ok = self::set($family, $provider_key, ['secret' => $secret]);
        if (self::is_err($ok)) { return $ok; }
        return $secret;
    }

    public static function secret(string $family, string $provider_key): string {
        $s = self::get($family, $provider_key);
        return self::clean_secret((string) ($s['secret'] ?? ''));
    }

    public static function touch_success(string $family, string $provider_key): void {
        self::touch($family, $provider_key, ['last_seen' => self::now(), 'last_error' => '']);
    }

    public static function touch_error(string $family, string $provider_key, string $error): void {
        self::touch($family, $provider_key, ['last_error' => self::clean_text($error, 240)]);
    }

    private static function touch(string $family, string $provider_key, array $data): void {
        $family = self::clean_key($family, 60);
        $provider_key = self::clean_key($provider_key, 80);
        $all = self::all();
        $row = is_array($all[$family . ':' . $provider_key] ?? null) ? $all[$family . ':' . $provider_key] : [];
        foreach ($data as $k => $v) { $row[$k] = $v; }
        $row['updated_at'] = self::now();
        $all[$family . ':' . $provider_key] = $row;
        if (function_exists('update_option')) { update_option(self::OPTION, $all, false); }
    }

    public static function workspace_allowed(array $settings, int $workspace_id): bool {
        $allowed = self::int_list($settings['allowed_workspace_ids'] ?? []);
        return empty($allowed) || in_array($workspace_id, $allowed, true);
    }

    private static function secret_configured(array $settings): bool {
        return self::clean_secret((string) ($settings['secret'] ?? '')) !== '';
    }

    private static function auth_mode(string $mode): string {
        $mode = self::clean_key($mode, 40);
        return in_array($mode, ['disabled','wordpress_auth_only','hmac_signature','application_password','internal_test'], true) ? $mode : 'disabled';
    }

    public static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function clean_secret(string $s): string { $s = trim((string) $s); return substr($s, 0, 255); }
    private static function int_list($items): array { $out = []; foreach ((array) $items as $v) { $i = (int) $v; if ($i > 0) { $out[] = $i; } } return array_values(array_unique($out)); }
    private static function generate_secret(): string { try { return 'fi_' . bin2hex(random_bytes(24)); } catch (\Throwable $e) { return 'fi_' . md5(uniqid('', true)); } }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function is_err($v): bool { return function_exists('is_wp_error') ? is_wp_error($v) : ($v instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code' => $code, 'message' => $message]; }
}
