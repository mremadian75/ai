<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_Module_Info {
    const KEY = 'deep_trend_finder';
    const SHORTCODE = 'future_island_deep_trend_finder';

    public static function get(): array {
        return [
            'key' => self::KEY,
            'label' => __('Deep Trend Finder', 'future-island-deep-trend-finder-addon'),
            'shortcode' => self::SHORTCODE,
            'page_id' => fidtf_get_frontend_page_id(),
            'url' => fidtf_get_frontend_url(),
            'status' => FIDTF_Dependency::mother_active() ? 'active' : 'core_missing',
            'requires_core' => true,
            'capability' => 'read',
        ];
    }

    public static function register_via_filter(array $modules): array {
        $modules[self::KEY] = self::get();
        return $modules;
    }
}

function fidtf_module_info(): array {
    return FIDTF_Module_Info::get();
}

function fidtf_get_frontend_page_id(): int {
    return max(0, (int) get_option('fidtf_page_id', 0));
}

function fidtf_get_frontend_url(): string {
    $id = fidtf_get_frontend_page_id();
    if ($id > 0 && function_exists('get_permalink')) {
        $url = get_permalink($id);
        if (is_string($url) && $url !== '') { return $url; }
    }
    return function_exists('home_url') ? home_url('/deep-trend-finder/') : '/deep-trend-finder/';
}
