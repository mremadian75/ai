<?php
if (!defined('ABSPATH')) { exit; }

/** Replay protection for provider callback idempotency keys. */
final class VES_Provider_Replay_Guard {
    const TTL = 604800; // 7 days.

    /** @return true|WP_Error */
    public static function check(string $provider_key, string $idempotency_key, bool $consume = true) {
        $provider_key = self::clean_key($provider_key, 80);
        $idempotency_key = self::clean_text($idempotency_key, 191);
        if ($provider_key === '' || $idempotency_key === '') { return self::err('ves_provider_replay_missing_key', 'Provider idempotency key is required.'); }
        $key = 'ves_provider_replay_' . md5($provider_key . '|' . $idempotency_key);
        $existing = function_exists('get_transient') ? get_transient($key) : false;
        if ($existing !== false) { return self::err('ves_provider_replay_blocked', 'Provider callback replay blocked.'); }
        if ($consume && function_exists('set_transient')) { set_transient($key, 1, self::TTL); }
        return true;
    }

    public static function remember(string $provider_key, string $idempotency_key): void {
        self::check($provider_key, $idempotency_key, true);
    }

    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : ['code'=>$code,'message'=>$message]; }
}
