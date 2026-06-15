<?php
if (!defined('ABSPATH')) { exit; }

/** Transient-backed provider callback rate limits for private beta. */
final class VES_Provider_Rate_Limit_Service {
    const WINDOW = 3600;

    /** @return true|WP_Error */
    public static function check(string $provider_key, int $workspace_id, int $run_id, string $ip, int $max_per_hour, bool $consume = true) {
        $max_per_hour = max(1, $max_per_hour);
        $scopes = [
            'provider' => self::clean_key($provider_key, 80),
            'workspace' => (string) max(0, $workspace_id),
            'run' => (string) max(0, $run_id),
            'ip' => self::clean_text($ip, 80),
        ];
        foreach ($scopes as $scope => $value) {
            if ($value === '' || $value === '0') { continue; }
            $key = 'ves_provider_rate_' . md5($scope . '|' . $value . '|' . gmdate('YmdH'));
            $count = function_exists('get_transient') ? (int) get_transient($key) : 0;
            if ($count >= $max_per_hour) { return self::err('ves_provider_rate_limited', 'Provider callback rate limit exceeded.'); }
        }
        if ($consume && function_exists('set_transient') && function_exists('get_transient')) {
            foreach ($scopes as $scope => $value) {
                if ($value === '' || $value === '0') { continue; }
                $key = 'ves_provider_rate_' . md5($scope . '|' . $value . '|' . gmdate('YmdH'));
                $count = (int) get_transient($key);
                set_transient($key, $count + 1, self::WINDOW);
            }
        }
        return true;
    }

    public static function snapshot(string $provider_key, int $workspace_id = 0, int $run_id = 0): array {
        return [
            'provider_key' => self::clean_key($provider_key, 80),
            'workspace_id' => max(0, $workspace_id),
            'run_id' => max(0, $run_id),
            'window_seconds' => self::WINDOW,
            'storage' => function_exists('get_transient') ? 'transient' : 'unavailable',
        ];
    }

    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code'=>$code,'message'=>$message]; }
}
