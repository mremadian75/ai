<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Run_Log_Service {
    const TABLE_SLUG = 'ves_run_logs';
    const DB_VERSION = '1.1';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function create_table() {
        $version_option = 'ves_run_logs_db_version';
        if (get_option($version_option) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            workspace_id bigint(20) unsigned NULL,
            project_id bigint(20) unsigned NULL,
            level varchar(16) NOT NULL,
            component varchar(50) NOT NULL,
            message text NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY workspace_id (workspace_id),
            KEY project_id (project_id),
            KEY level (level),
            KEY component (component),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option($version_option, self::DB_VERSION, false);
    }

    private static function write($run_id, $level, $component, $message, $context = []) {
        global $wpdb;
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return;
        }
        $level = sanitize_key((string) $level);
        $component = sanitize_key((string) $component);
        $message = sanitize_text_field((string) $message);
        $context = is_array($context) ? $context : [];
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        $project_id = max(0, (int) ($context['project_id'] ?? 0));
        if ($project_id <= 0 && class_exists('VES_Projects')) {
            $project_id = VES_Projects::resolve_project_from_request($context, (int) ($context['user_id'] ?? 0), $workspace_id);
        }
        $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        $wpdb->insert(self::table_name(), [
            'run_id' => $run_id,
            'workspace_id' => $workspace_id,
            'project_id' => $project_id,
            'level' => $level,
            'component' => $component,
            'message' => $message,
            'context_json' => is_string($json) ? $json : '{}',
            'created_at' => current_time('mysql', true),
        ], ['%d','%d','%d','%s','%s','%s','%s','%s']);
    }

    public static function debug($run_id, $component, $message, $context = []) { self::write($run_id, 'debug', $component, $message, $context); }
    public static function info($run_id, $component, $message, $context = []) { self::write($run_id, 'info', $component, $message, $context); }
    public static function warn($run_id, $component, $message, $context = []) { self::write($run_id, 'warn', $component, $message, $context); }
    public static function error($run_id, $component, $message, $context = []) { self::write($run_id, 'error', $component, $message, $context); }
}
