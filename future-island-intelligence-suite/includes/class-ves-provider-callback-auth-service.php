<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Authenticates provider/external worker callbacks before provider rows can be ingested.
 * HMAC mode signs timestamp + '.' + raw_body with the provider secret.
 */
final class VES_Provider_Callback_Auth_Service {
    const TIMESTAMP_TOLERANCE = 300;

    /** @return array|WP_Error */
    public static function authenticate($request, string $expected_family, array $opts = []) {
        $consume = array_key_exists('consume', $opts) ? (bool) $opts['consume'] : true;
        $params = self::params($request);
        $raw_body = self::raw_body($request, $params);
        $headers = self::headers($request);
        $expected_family = self::clean_key($expected_family, 60);
        $provider_key = self::clean_key((string) ($headers['x-fi-provider-key'] ?? ($params['provider_key'] ?? '')), 80);
        $idempotency_key = self::clean_text((string) ($headers['x-fi-idempotency-key'] ?? ($params['idempotency_key'] ?? '')), 191);
        $run_id = max(0, (int) ($params['run_id'] ?? 0));
        if ($expected_family === '' || $provider_key === '') { return self::fail($expected_family, $provider_key, 0, 0, 'ves_provider_auth_missing_provider', 'Provider key is required.'); }
        $contract = class_exists('VES_Provider_Contract_Service') ? VES_Provider_Contract_Service::assert_contract($expected_family, $provider_key) : null;
        if (self::is_err($contract)) { return self::fail($expected_family, $provider_key, 0, $run_id, $contract->get_error_code(), $contract->get_error_message()); }
        if (!is_array($contract)) { return self::fail($expected_family, $provider_key, 0, $run_id, 'ves_provider_auth_no_contract', 'Provider contract unavailable.'); }
        $settings = class_exists('VES_Provider_Settings_Service') ? VES_Provider_Settings_Service::get($expected_family, $provider_key) : ['enabled' => true, 'auth_mode' => 'wordpress_auth_only', 'max_rows_per_callback' => 50, 'max_callbacks_per_hour' => 120, 'secret_configured' => false, 'allowed_workspace_ids' => []];
        if (empty($settings['enabled'])) { return self::fail($expected_family, $provider_key, 0, $run_id, 'ves_provider_auth_disabled', 'Provider is disabled.'); }

        $workspace_id = self::workspace_for_run($run_id);
        if ($run_id <= 0 || $workspace_id <= 0) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_bad_run', 'A valid canonical Run is required.'); }
        if (class_exists('VES_Run_Service')) {
            $check = VES_Run_Service::assert_workspace($run_id, $workspace_id);
            if (self::is_err($check)) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_run_scope', 'Run workspace scope is invalid.'); }
        }
        if (class_exists('VES_Provider_Settings_Service') && !VES_Provider_Settings_Service::workspace_allowed($settings, $workspace_id)) {
            return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_workspace_blocked', 'Provider is not allowed for this workspace.');
        }

        $rows = is_array($params['rows'] ?? null) ? $params['rows'] : [];
        if (count($rows) > (int) ($settings['max_rows_per_callback'] ?? 50)) {
            self::safe_run_log($workspace_id, $run_id, 'warn', 'provider_auth', 'provider_result_rejected', 'Provider callback exceeded max rows.', ['provider_family' => $expected_family, 'provider_key' => $provider_key, 'row_count' => count($rows)]);
            return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_too_many_rows', 'Provider callback row limit exceeded.');
        }

        $mode = self::clean_key((string) ($settings['auth_mode'] ?? 'disabled'), 40);
        if ($mode === 'disabled') { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_disabled_mode', 'Provider callback auth mode is disabled.'); }
        if ($mode === 'hmac_signature') {
            $ok = self::check_hmac($headers, $raw_body, class_exists('VES_Provider_Settings_Service') ? VES_Provider_Settings_Service::secret($expected_family, $provider_key) : '');
            if (self::is_err($ok)) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, $ok->get_error_code(), $ok->get_error_message()); }
        } elseif ($mode === 'wordpress_auth_only' || $mode === 'application_password') {
            if (function_exists('is_user_logged_in') && !is_user_logged_in()) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_wp_required', 'WordPress authentication is required.'); }
            if (class_exists('VES_Permission_Service') && !VES_Permission_Service::can_run_playbook($workspace_id)) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_forbidden', 'User cannot ingest provider rows for this workspace.'); }
        } elseif ($mode === 'internal_test') {
            if (!(PHP_SAPI === 'cli' || (defined('WP_DEBUG') && WP_DEBUG))) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_internal_only', 'Internal test callback mode is not enabled.'); }
        } else {
            return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_unknown_mode', 'Unsupported provider auth mode.');
        }

        if ($idempotency_key === '') { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, 'ves_provider_auth_missing_idempotency', 'Provider idempotency key is required.'); }
        if (class_exists('VES_Provider_Replay_Guard')) {
            $replay = VES_Provider_Replay_Guard::check($provider_key, $idempotency_key, $consume);
            if (self::is_err($replay)) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, $replay->get_error_code(), $replay->get_error_message()); }
        }
        $ip = self::ip_address();
        if (class_exists('VES_Provider_Rate_Limit_Service')) {
            $rate = VES_Provider_Rate_Limit_Service::check($provider_key, $workspace_id, $run_id, $ip, (int) ($settings['max_callbacks_per_hour'] ?? 120), $consume);
            if (self::is_err($rate)) { return self::fail($expected_family, $provider_key, $workspace_id, $run_id, $rate->get_error_code(), $rate->get_error_message()); }
        }
        if ($consume) {
            if (class_exists('VES_Provider_Settings_Service')) { VES_Provider_Settings_Service::touch_success($expected_family, $provider_key); }
            self::safe_run_log($workspace_id, $run_id, 'info', 'provider_auth', 'provider_authenticated', 'Provider callback authenticated.', ['provider_family' => $expected_family, 'provider_key' => $provider_key, 'auth_mode' => $mode, 'idempotency_key' => $idempotency_key]);
        }
        return ['workspace_id' => $workspace_id, 'run_id' => $run_id, 'provider_key' => $provider_key, 'idempotency_key' => $idempotency_key, 'auth_mode' => $mode, 'settings' => self::public_settings($settings)];
    }

    /** @return true|WP_Error */
    private static function check_hmac(array $headers, string $raw_body, string $secret) {
        $timestamp = self::clean_text((string) ($headers['x-fi-timestamp'] ?? ''), 40);
        $signature = trim((string) ($headers['x-fi-signature'] ?? ''));
        if ($secret === '') { return self::err('ves_provider_auth_secret_missing', 'Provider secret is not configured.'); }
        if ($timestamp === '') { return self::err('ves_provider_auth_timestamp_missing', 'Provider timestamp header is required.'); }
        if ($signature === '') { return self::err('ves_provider_auth_signature_missing', 'Provider signature header is required.'); }
        if (!preg_match('/^-?\d+$/', $timestamp)) { return self::err('ves_provider_auth_timestamp_malformed', 'Provider timestamp is malformed.'); }
        $now = time();
        $ts = (int) $timestamp;
        if ($ts < $now - self::TIMESTAMP_TOLERANCE) { return self::err('ves_provider_auth_timestamp_stale', 'Provider timestamp is stale.'); }
        if ($ts > $now + self::TIMESTAMP_TOLERANCE) { return self::err('ves_provider_auth_timestamp_future', 'Provider timestamp is too far in the future.'); }
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);
        $normalized = preg_replace('/^sha256=/i', '', $signature);
        if (!is_string($normalized) || !preg_match('/^[a-f0-9]{64}$/i', $normalized)) { return self::err('ves_provider_auth_signature_malformed', 'Provider signature is malformed.'); }
        if (!function_exists('hash_equals') || !hash_equals(strtolower($expected), strtolower($normalized))) { return self::err('ves_provider_auth_signature_bad', 'Provider signature is invalid.'); }
        return true;
    }

    public static function sign_body(string $body, string $secret, int $timestamp = null): array {
        $timestamp = $timestamp === null ? time() : $timestamp;
        return [
            'X-FI-Timestamp' => (string) $timestamp,
            'X-FI-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ];
    }

    private static function fail(string $family, string $provider_key, int $workspace_id, int $run_id, string $code, string $message) {
        if (class_exists('VES_Provider_Settings_Service') && $provider_key !== '') { VES_Provider_Settings_Service::touch_error($family, $provider_key, $code); }
        self::safe_run_log($workspace_id, $run_id, 'warn', 'provider_auth', 'provider_auth_blocked', 'Provider callback blocked.', ['provider_family' => $family, 'provider_key' => $provider_key, 'reason' => $code]);
        return self::err($code, $message);
    }

    private static function safe_run_log(int $workspace_id, int $run_id, string $level, string $component, string $event_type, string $message, array $context): void {
        if ($workspace_id <= 0 || $run_id <= 0 || !class_exists('VES_Run_Log_Service')) { return; }
        VES_Run_Log_Service::append($workspace_id, $run_id, $level, $component, $event_type, $message, $context);
    }

    private static function public_settings(array $settings): array {
        unset($settings['secret']);
        return class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($settings) : $settings;
    }

    private static function workspace_for_run(int $run_id): int {
        if ($run_id > 0 && class_exists('VES_Run_Service')) {
            $run = VES_Run_Service::get_run($run_id);
            if (is_array($run)) { return max(0, (int) ($run['workspace_id'] ?? 0)); }
        }
        return class_exists('VES_Workspace_Service') ? VES_Workspace_Service::current_workspace_id() : (function_exists('get_current_user_id') ? max(1, (int) get_current_user_id()) : 1);
    }

    private static function params($request): array { if (is_object($request) && method_exists($request, 'get_json_params')) { $json = $request->get_json_params(); if (is_array($json) && !empty($json)) { return $json; } } if (is_object($request) && method_exists($request, 'get_params')) { $p = $request->get_params(); return is_array($p) ? $p : []; } return is_array($request) ? $request : []; }
    private static function raw_body($request, array $params): string { if (is_object($request) && method_exists($request, 'get_body')) { $body = (string) $request->get_body(); if ($body !== '') { return $body; } } $json = function_exists('wp_json_encode') ? wp_json_encode($params, JSON_UNESCAPED_UNICODE) : json_encode($params); return is_string($json) ? $json : '{}'; }
    private static function headers($request): array { $out = []; $names = ['x-fi-provider-key','x-fi-timestamp','x-fi-signature','x-fi-idempotency-key']; foreach ($names as $name) { $value = ''; if (is_object($request) && method_exists($request, 'get_header')) { $value = (string) $request->get_header($name); } elseif (is_array($request) && isset($request['headers'][$name])) { $value = (string) $request['headers'][$name]; } elseif (is_array($request) && isset($request['headers'][strtoupper(str_replace('-', '_', $name))])) { $value = (string) $request['headers'][strtoupper(str_replace('-', '_', $name))]; } if ($value !== '') { $out[$name] = $value; } } return $out; }
    private static function ip_address(): string { return isset($_SERVER['REMOTE_ADDR']) ? self::clean_text((string) $_SERVER['REMOTE_ADDR'], 80) : 'cli'; }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function is_err($v): bool { return function_exists('is_wp_error') ? is_wp_error($v) : ($v instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code'=>$code,'message'=>$message]; }
}
