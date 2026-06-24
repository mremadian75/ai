<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Exporter
{
    public static function snapshot()
    {
        $settings = FBAIA_Helpers::get_settings();
        unset($settings['api_key'], $settings['webhook_secret']);
        $knowledge = [];
        if (post_type_exists(FBAIA_Knowledge_Library::CPT)) {
            $posts = get_posts([
                'post_type' => FBAIA_Knowledge_Library::CPT,
                'post_status' => 'publish',
                'numberposts' => 200,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            foreach ($posts as $post) {
                $knowledge[] = [
                    'title' => get_the_title($post),
                    'content' => $post->post_content,
                    'topics' => wp_get_post_terms($post->ID, FBAIA_Knowledge_Library::TAX, ['fields' => 'names']),
                    'updated_at' => $post->post_modified_gmt,
                ];
            }
        }
        return [
            'exported_at' => current_time('mysql', true),
            'plugin_version' => defined('FBAIA_VERSION') ? FBAIA_VERSION : '',
            'site' => home_url('/'),
            'settings_without_secrets' => $settings,
            'knowledge_library' => $knowledge,
            'suggestions' => class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::all() : [],
            'audit_trail' => class_exists('FBAIA_Audit_Trail') ? FBAIA_Audit_Trail::all(500) : [],
            'health' => class_exists('FBAIA_Health_Check') ? FBAIA_Health_Check::diagnostics() : [],
            'insights' => class_exists('FBAIA_Insights') ? FBAIA_Insights::snapshot() : [],
            'usage' => class_exists('FBAIA_Usage_Tracker') ? FBAIA_Usage_Tracker::snapshot() : [],
            'automation_playbooks' => class_exists('FBAIA_Automation_Playbooks') ? (new FBAIA_Automation_Playbooks($settings))->parse((string) ($settings['automation_playbooks'] ?? '')) : [],
        ];
    }

    public static function stream_json()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        $filename = 'fluent-boards-ai-automation-snapshot-' . gmdate('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode(self::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
