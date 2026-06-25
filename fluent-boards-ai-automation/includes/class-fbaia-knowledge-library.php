<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Knowledge_Library
{
    const CPT = 'fbaia_knowledge';
    const TAX = 'fbaia_topic';

    public function register()
    {
        // Escape hatch: define FBAIA_DISABLE_KNOWLEDGE_CPT in wp-config.php to skip the
        // custom post type entirely. Useful to confirm whether the CPT is the cause of any
        // admin-menu issue on a given site.
        if (defined('FBAIA_DISABLE_KNOWLEDGE_CPT') && FBAIA_DISABLE_KNOWLEDGE_CPT) {
            return;
        }
        add_action('init', [$this, 'register_post_type'], 25);
    }

    public function register_post_type()
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('AI Company Knowledge', 'fluent-boards-ai-automation'),
                'singular_name' => __('AI Knowledge Entry', 'fluent-boards-ai-automation'),
                'add_new_item' => __('Add Knowledge Entry', 'fluent-boards-ai-automation'),
                'edit_item' => __('Edit Knowledge Entry', 'fluent-boards-ai-automation'),
            ],
            'public' => false,
            'show_ui' => true,
            // IMPORTANT: do NOT auto-inject into the core Settings menu. WordPress'
            // _add_post_type_submenus() inserts a CPT submenu into options-general.php with
            // the post type's own capability mapping; for an admin-only CPT with a custom
            // capability_type this can interact badly with the Settings submenu list on some
            // sites and hide items. We register the menu link ourselves, safely, in
            // FBAIA_Admin::menu() using a plain manage_options add_submenu_page().
            'show_in_menu' => false,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'excerpt', 'revisions'],
            'capability_type' => 'fbaia_knowledge',
            'map_meta_cap' => true,
            // Only PRIMITIVE caps belong here when map_meta_cap is true. The meta caps
            // (edit_post / read_post / delete_post) are derived by map_meta_cap() from these
            // primitives — listing them explicitly is an anti-pattern that breaks meta-cap
            // mapping. All primitives require manage_options, so access stays admin-only.
            'capabilities' => [
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts' => 'manage_options',
                'delete_private_posts' => 'manage_options',
                'delete_published_posts' => 'manage_options',
                'delete_others_posts' => 'manage_options',
                'edit_private_posts' => 'manage_options',
                'edit_published_posts' => 'manage_options',
                'create_posts' => 'manage_options',
            ],
            'menu_icon' => 'dashicons-database',
        ]);

        register_taxonomy(self::TAX, [self::CPT], [
            'labels' => [
                'name' => __('AI Knowledge Topics', 'fluent-boards-ai-automation'),
                'singular_name' => __('AI Knowledge Topic', 'fluent-boards-ai-automation'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => false,
            'show_admin_column' => true,
            'capabilities' => [
                'manage_terms' => 'manage_options',
                'edit_terms' => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'manage_options',
            ],
        ]);
    }

    public static function retrieve($query_text, $limit = 5)
    {
        if (!post_type_exists(self::CPT)) {
            return [];
        }

        $limit = max(0, min(12, absint($limit)));
        if (!$limit) {
            return [];
        }

        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 80,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);

        if (empty($posts)) {
            return [];
        }

        $scored = [];
        foreach ($posts as $post) {
            $terms = wp_get_post_terms($post->ID, self::TAX, ['fields' => 'names']);
            if (!is_array($terms)) {
                $terms = [];
            }
            $text = implode(' ', [
                $post->post_title,
                wp_strip_all_tags($post->post_excerpt),
                wp_strip_all_tags($post->post_content),
                implode(' ', $terms),
            ]);
            $score = self::keyword_score($query_text, $text);
            $scored[] = [
                'score' => $score,
                'modified' => strtotime($post->post_modified_gmt ?: $post->post_modified) ?: 0,
                'post' => $post,
                'topics' => $terms,
            ];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $b['modified'] - $a['modified'];
            }
            return $b['score'] - $a['score'];
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            if ($item['score'] <= 0 && count($out) >= 2) {
                continue;
            }
            $post = $item['post'];
            $out[] = [
                'source' => 'knowledge_post',
                'id' => (int) $post->ID,
                'title' => sanitize_text_field($post->post_title),
                'topics' => array_map('sanitize_text_field', $item['topics']),
                'score' => (int) $item['score'],
                'updated_at' => sanitize_text_field($post->post_modified_gmt),
                'content' => FBAIA_Helpers::limit_chars(wp_strip_all_tags($post->post_content), 2200),
            ];
        }

        return $out;
    }

    public static function import_legacy_entries($raw)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('You do not have permission to import knowledge.', 'fluent-boards-ai-automation'));
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['created' => 0];
        }

        $chunks = preg_split('/\n\s*---+\s*\n/', $raw);
        if (!is_array($chunks)) {
            $chunks = [$raw];
        }

        $created = 0;
        foreach ($chunks as $index => $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\r|\n/', $chunk);
            $first = trim((string) ($lines[0] ?? ''));
            $title = $first !== '' ? FBAIA_Helpers::limit_chars($first, 120) : 'Imported knowledge entry ' . ($index + 1);
            $post_id = wp_insert_post([
                'post_type' => self::CPT,
                'post_status' => 'publish',
                'post_title' => sanitize_text_field($title),
                'post_content' => sanitize_textarea_field($chunk),
            ], true);
            if (!is_wp_error($post_id) && $post_id) {
                wp_set_post_terms($post_id, ['imported'], self::TAX, true);
                $created++;
            }
        }

        return ['created' => $created];
    }

    private static function keyword_score($needle_text, $haystack_text)
    {
        $needles = self::keywords($needle_text);
        $haystack = ' ' . strtolower((string) $haystack_text) . ' ';
        $score = 0;
        foreach ($needles as $word => $weight) {
            if (strpos($haystack, strtolower($word)) !== false) {
                $score += $weight;
            }
        }
        return $score;
    }

    private static function keywords($text)
    {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $tokens = preg_split('/[^\p{L}\p{N}_@.\-]+/u', $text);
        $stop = ['the','and','for','with','from','this','that','have','has','will','task','board','comment','created','updated','changed','user','your','you','در','از','به','برای','که','این','آن','های','است','شد','شود','یک','یا','و','را','با'];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            $out[$token] = isset($out[$token]) ? $out[$token] + 1 : 1;
        }
        arsort($out);
        return array_slice($out, 0, 100, true);
    }
}
