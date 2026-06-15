<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Cross-product secret redaction utility.
 *
 * Redacts provider/API credentials before they enter logs, reports, prompts,
 * REST responses, or UI metadata. The utility is intentionally conservative:
 * it preserves public URLs while removing sensitive query/header values.
 */
final class VES_Secret_Redactor {
    private const REDACTED = '[redacted]';

    private static $sensitive_keys = [
        'api_key','apikey','key','token','access_token','refresh_token','id_token','secret',
        'client_secret','authorization','auth','bearer','cookie','set_cookie','password',
        'openai_api_key','apify_token','apify_api_token','provider_token','x_api_key','x-api-key','signature','x_fi_signature','hmac_signature',
    ];

    public static function redact($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = self::normalize_key((string) $k);
                if (self::is_sensitive_key($key)) { $out[$k] = self::REDACTED; continue; }
                $out[$k] = self::redact($v);
            }
            return $out;
        }
        if (is_object($value)) { return self::redact(get_object_vars($value)); }
        if (is_string($value)) { return self::redact_string($value); }
        return $value;
    }

    public static function redact_string(string $text): string {
        if ($text === '') { return ''; }
        $text = preg_replace('/sk-[A-Za-z0-9_\-]{12,}/', self::REDACTED, $text);
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\-\/=]{8,}/i', 'Bearer ' . self::REDACTED, $text);
        $text = preg_replace('/\bapify[_\-]?(?:api[_\-]?)?token[_\-]?[A-Za-z0-9_\-]{8,}/i', 'apify_token_' . self::REDACTED, $text);
        $text = preg_replace('/\b(api[_\-]?key|access[_\-]?token|refresh[_\-]?token|oauth[_\-]?token|client[_\-]?secret|authorization|cookie|signature|hmac[_\-]?signature)\s*[:=]\s*[A-Za-z0-9._~+\-\/=]{6,}/i', '$1=' . self::REDACTED, $text);
        $text = preg_replace_callback('/([?&](?:api_key|apikey|key|token|access_token|refresh_token|client_secret|auth|authorization)=)([^&#\s]+)/i', static function($m){ return $m[1] . self::REDACTED; }, $text);
        return $text;
    }

    public static function redact_json_string(string $json): string {
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return self::encode_json(self::redact($decoded));
        }
        return self::redact_string($json);
    }

    public static function context_json($value): string {
        return self::encode_json(self::redact(is_array($value) ? $value : []));
    }

    public static function is_sensitive_key(string $key): bool {
        $key = self::normalize_key($key);
        if (in_array($key, self::$sensitive_keys, true)) { return true; }
        return (bool) preg_match('/(?:api|access|refresh|oauth|bearer|auth|cookie|secret|password).*?(?:key|token|secret|header)?$/', $key);
    }

    private static function normalize_key(string $key): string {
        return strtolower(str_replace('-', '_', trim($key)));
    }

    private static function encode_json($value): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : json_encode($value);
        return is_string($json) ? $json : '{}';
    }
}
