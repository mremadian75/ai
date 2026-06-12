<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Core shell glue for the Deep Trend Finder module.
 *
 * In the unified Intelligence Suite the Deep Trend Finder is always built-in.
 * addon_active() unconditionally returns true. All other public methods
 * (addon_page_url, addon_module_info, deprecated_payload, send_deprecated_response)
 * retain the same signatures so existing callers need no changes.
 *
 * @since 1.0.0 (unified suite — was 0.9.31.8-p4 in standalone Core)
 */
final class VES_Deep_Trend_Addon_Bridge {

    /** Stable module key shared with the add-on and the external module bus. */
    const MODULE_KEY = 'deep_trend_finder';

    /** Shortcode the add-on registers; also the advertised replacement. */
    const SHORTCODE  = 'future_island_deep_trend_finder';

    /** Option key holding the add-on's frontend page id, when stored by Core. */
    const PAGE_OPT   = 'fidtf_page_id';

    /** Optimistic fallback path used only when the add-on is active but its
     *  page cannot be resolved by any authoritative source. */
    const DEFAULT_PAGE_PATH = '/deep-trend-finder/';

    /** Machine-readable code returned in every deprecation envelope. */
    const DEPRECATION_CODE = 'trend_finder_deprecated';

    /**
     * Deep Trend Finder is always active in the unified Intelligence Suite.
     */
    public static function addon_active(): bool {
        return true;
    }

    /**
     * Add-on frontend page id, or 0 when it cannot be resolved.
     *
     * @return int Non-negative page id.
     */
    public static function addon_page_id(): int {
        if (function_exists('fidtf_get_frontend_page_id')) {
            try { return max(0, (int) fidtf_get_frontend_page_id()); } catch (Throwable $e) {}
        }
        return max(0, (int) get_option(self::PAGE_OPT, 0));
    }

    /**
     * Public URL of the Deep Trend Finder frontend page.
     */
    public static function addon_page_url(): string {
        if (function_exists('fidtf_get_frontend_url')) {
            try {
                $url = fidtf_get_frontend_url();
                if (is_string($url) && $url !== '') { return $url; }
            } catch (Throwable $e) {}
        }
        $id = self::addon_page_id();
        if ($id > 0 && function_exists('get_permalink')) {
            $url = get_permalink($id);
            if (is_string($url) && $url !== '') { return $url; }
        }
        return function_exists('home_url') ? home_url(self::DEFAULT_PAGE_PATH) : self::DEFAULT_PAGE_PATH;
    }

    /**
     * Module descriptor consumed by Core navigation and admin panels.
     * fidtf_module_info() is always available in the unified suite.
     */
    public static function addon_module_info(): array {
        if (function_exists('fidtf_module_info')) {
            try {
                $info = fidtf_module_info();
                if (is_array($info) && !empty($info)) {
                    return array_merge($info, self::base_module_info());
                }
            } catch (Throwable $e) {}
        }
        return self::base_module_info();
    }

    private static function base_module_info(): array {
        return [
            'key'           => self::MODULE_KEY,
            'label'         => __('Deep Trend Finder', 'future-island-intelligence-suite'),
            'shortcode'     => self::SHORTCODE,
            'page_id'       => self::addon_page_id(),
            'url'           => self::addon_page_url(),
            'status'        => 'active',
            'requires_core' => true,
            'capability'    => 'read',
        ];
    }

    public static function deprecated_payload(array $context = []): array {
        $url     = self::addon_page_url();
        $payload = [
            'deprecated'   => true,
            'code'         => self::DEPRECATION_CODE,
            'message'      => __('The internal Trend Finder has been replaced by the Deep Trend Finder module.', 'future-island-intelligence-suite'),
            'module'       => self::MODULE_KEY,
            'replacement'  => self::SHORTCODE,
            'addon_active' => true,
            'addon_label'  => __('Deep Trend Finder', 'future-island-intelligence-suite'),
            'addon_url'    => ($url !== '') ? $url : null,
            'timestamp'    => gmdate('c'),
            'context'      => $context,
        ];
        if (!empty($context['request_id'])) {
            $payload['request_id'] = (string) $context['request_id'];
        }
        return $payload;
    }

    /**
     * Emit the deprecation envelope as HTTP 410 JSON and exit.
     */
    public static function send_deprecated_response(array $context = []): void {
        $payload = self::deprecated_payload($context);

        if (function_exists('headers_sent') && !headers_sent()) {
            header('Deprecation: true');
            if (!empty($payload['addon_url'])) {
                $link = str_replace(["\r", "\n"], '', (string) $payload['addon_url']);
                header('Link: <' . $link . '>; rel="successor-version"');
            }
        }

        wp_send_json(array_merge(['success' => false], $payload), 410);
    }
}
