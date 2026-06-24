<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Logger
{
    public static function add($level, $message, $context = [])
    {
        $settings = FBAIA_Helpers::get_settings();
        $logs = get_option(FBAIA_LOG_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $entry = [
            'time'    => gmdate('Y-m-d H:i:s'),
            'level'   => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
        ];

        $logs[] = $entry;

        $limit = max(20, (int) ($settings['log_retention'] ?? 100));
        if (count($logs) > $limit) {
            $logs = array_slice($logs, -1 * $limit);
        }

        update_option(FBAIA_LOG_OPTION, $logs, false);

        if (($settings['debug'] ?? 'no') === 'yes') {
            error_log('[FBAIA][' . $entry['level'] . '] ' . $entry['message'] . ' ' . wp_json_encode($entry['context']));
        }
    }

    public static function all()
    {
        $logs = get_option(FBAIA_LOG_OPTION, []);
        return is_array($logs) ? array_reverse($logs) : [];
    }

    public static function clear()
    {
        delete_option(FBAIA_LOG_OPTION);
    }

    private static function sanitize_context($context)
    {
        if (!is_array($context)) {
            return [];
        }

        $safe = [];
        foreach ($context as $key => $value) {
            $clean_key = sanitize_key($key);
            if (FBAIA_Helpers::is_sensitive_key($key)) {
                $safe[$clean_key] = '[redacted]';
                continue;
            }

            if (is_scalar($value) || is_null($value)) {
                $safe[$clean_key] = is_string($value) ? sanitize_text_field(FBAIA_Helpers::truncate_string($value)) : $value;
            } else {
                $safe[$clean_key] = FBAIA_Helpers::normalize($value);
            }
        }
        return $safe;
    }
}
