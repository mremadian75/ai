<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Project_Context {
    const USER_META_KEY = '_ves_project_context';

    public static function get_default_context() {
        return [
            'brand_name' => '',
            'website_url' => '',
            'brand_summary' => '',
            'products_services' => '',
            'target_audience' => '',
            'primary_goal' => '',
            'tone_of_voice' => '',
            'markets' => '',
            'competitors' => '',
            'notes' => '',
        ];
    }

    public static function sanitize_context($input) {
        $defaults = self::get_default_context();
        $input = is_array($input) ? $input : [];
        return [
            'brand_name' => sanitize_text_field((string) ($input['brand_name'] ?? $defaults['brand_name'])),
            'website_url' => esc_url_raw((string) ($input['website_url'] ?? $defaults['website_url'])),
            'brand_summary' => sanitize_textarea_field((string) ($input['brand_summary'] ?? $defaults['brand_summary'])),
            'products_services' => sanitize_textarea_field((string) ($input['products_services'] ?? $defaults['products_services'])),
            'target_audience' => sanitize_textarea_field((string) ($input['target_audience'] ?? $defaults['target_audience'])),
            'primary_goal' => sanitize_textarea_field((string) ($input['primary_goal'] ?? $defaults['primary_goal'])),
            'tone_of_voice' => sanitize_text_field((string) ($input['tone_of_voice'] ?? $defaults['tone_of_voice'])),
            'markets' => sanitize_textarea_field((string) ($input['markets'] ?? $defaults['markets'])),
            'competitors' => sanitize_textarea_field((string) ($input['competitors'] ?? $defaults['competitors'])),
            'notes' => sanitize_textarea_field((string) ($input['notes'] ?? $defaults['notes'])),
        ];
    }

    public static function get_user_context($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return self::get_default_context();
        }
        $stored = get_user_meta($user_id, self::USER_META_KEY, true);
        if (!is_array($stored)) {
            $stored = [];
        }
        $context = array_merge(self::get_default_context(), self::sanitize_context($stored));
        if (class_exists('VES_Projects')) {
            $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(['user_id' => $user_id], $user_id) : $user_id;
            $project_id = VES_Projects::resolve_project_from_request(array_merge($context, ['user_id' => $user_id]), $user_id, $workspace_id);
            $summary = VES_Projects::current_project_summary(['project_id' => $project_id, 'user_id' => $user_id], $user_id);
            $context['project_id'] = (int) $project_id;
            $context['project_label'] = is_array($summary) && !empty($summary['name']) ? (string) $summary['name'] : ($context['brand_name'] !== '' ? $context['brand_name'] : 'Default Project');
        }
        return $context;
    }

    public static function get_current_user_context() {
        return is_user_logged_in() ? self::get_user_context(get_current_user_id()) : self::get_default_context();
    }

    public static function save_user_context($user_id, $context) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Usuario inválido.');
        }
        $clean = self::sanitize_context($context);
        update_user_meta($user_id, self::USER_META_KEY, $clean);
        $workspace_id = class_exists('VES_Memory_Records') ? VES_Memory_Records::infer_workspace_id(['user_id' => $user_id], $user_id) : $user_id;
        if (class_exists('VES_Workspace_Profile')) {
            VES_Workspace_Profile::upsert_from_project_context($workspace_id, $user_id, $clean, 'project_context');
        }
        if (class_exists('VES_Projects')) {
            $project_id = VES_Projects::create_default_project($workspace_id, $user_id, [
                'brand_name' => $clean['brand_name'],
                'name' => $clean['brand_name'] !== '' ? $clean['brand_name'] : 'Default Project',
                'market' => $clean['markets'],
                'language' => '',
                'settings' => ['source' => 'project_context', 'website_url' => $clean['website_url']],
            ]);
            $clean['project_id'] = $project_id;
            $clean['project_label'] = $clean['brand_name'] !== '' ? $clean['brand_name'] : 'Default Project';
        }
        return $clean;
    }

    public static function get_context_summary($user_id = 0) {
        $context = $user_id > 0 ? self::get_user_context($user_id) : self::get_current_user_context();
        $summary = [];
        foreach (['brand_name','website_url','brand_summary','products_services','target_audience','primary_goal','tone_of_voice','markets','competitors','notes'] as $key) {
            if (!empty($context[$key])) {
                $summary[$key] = $context[$key];
            }
        }
        return $summary;
    }

    public static function build_ai_context_block($user_id = 0, $focus_args = []) {
        if (class_exists('VES_AI_Context_Builder') && $user_id > 0) {
            $pack = VES_AI_Context_Builder::build(array_merge((array) $focus_args, ['user_id' => (int) $user_id]));
            if (is_array($pack) && !empty($pack['project_context'])) {
                return $pack['project_context'];
            }
        }

        $summary = self::get_context_summary($user_id);
        $knowledge = class_exists('VES_Workspace_Knowledge') && $user_id > 0
            ? VES_Workspace_Knowledge::get_relevant_context($user_id, array_merge($summary, (array) $focus_args), 4)
            : [];

        if (empty($summary) && empty($knowledge)) {
            return null;
        }

        $project_summary = null;
        if (class_exists('VES_Projects') && $user_id > 0) {
            $project_summary = VES_Projects::current_project_summary(array_merge($summary, (array) $focus_args, ['user_id' => $user_id]), $user_id);
        }

        $block = [
            'project' => $project_summary,
            'project_profile' => $summary,
            'available_fields' => array_keys($summary),
            'has_profile' => !empty($summary),
            'workspace_knowledge' => $knowledge,
            'workspace_knowledge_count' => count($knowledge),
        ];

        if (!empty($summary['website_url'])) {
            $host = wp_parse_url($summary['website_url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $block['website_domain'] = strtolower($host);
            }
        }

        return $block;
    }
}
