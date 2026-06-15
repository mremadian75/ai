<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Run_Log_Service {
    const TABLE_SLUG = 'ves_run_logs';
    const DB_VERSION = '1.2';

    public static function table_name() { global $wpdb; return $wpdb->prefix . self::TABLE_SLUG; }

    public static function create_table($force = false) {
        $version_option = 'ves_run_logs_db_version';
        if (!$force && function_exists('get_option') && get_option($version_option) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $table = self::table_name();
        $charset_collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            workspace_id bigint(20) unsigned NULL,
            project_id bigint(20) unsigned NULL,
            level varchar(16) NOT NULL,
            component varchar(50) NOT NULL,
            event_type varchar(60) NOT NULL DEFAULT 'log',
            message text NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY workspace_id (workspace_id),
            KEY project_id (project_id),
            KEY level (level),
            KEY component (component),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";
        if (function_exists('dbDelta')) { dbDelta($sql); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql); }
        if (function_exists('update_option')) { update_option($version_option, self::DB_VERSION, false); }
    }

    public static function append($workspace_id, $run_id, $level, $component, $event_type, $message, $context = []) {
        return self::write($run_id, $level, $component, $event_type, $message, array_merge(is_array($context) ? $context : [], ['workspace_id' => (int) $workspace_id]));
    }

    private static function write($run_id, $level, $component, $event_type, $message, $context = []) {
        global $wpdb;
        $run_id = (int) $run_id;
        if ($run_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return 0; }
        $level = self::clean_key((string) $level, 16);
        if (!in_array($level, ['debug','info','warn','warning','error'], true)) { $level = 'info'; }
        $component = self::clean_key((string) $component, 50) ?: 'system';
        $event_type = self::clean_key((string) $event_type, 60) ?: 'log';
        $message = self::clean_text(VES_Secret_Redactor::redact_string((string) $message), 1000);
        $context = VES_Secret_Redactor::redact(is_array($context) ? $context : []);
        $workspace_id = max(0, (int) ($context['workspace_id'] ?? 0));
        $project_id = max(0, (int) ($context['project_id'] ?? 0));
        if ($project_id <= 0 && class_exists('VES_Projects')) { $project_id = VES_Projects::resolve_project_from_request($context, (int) ($context['user_id'] ?? 0), $workspace_id); }
        $json = self::encode($context);
        $ok = $wpdb->insert(self::table_name(), [
            'run_id' => $run_id, 'workspace_id' => $workspace_id, 'project_id' => $project_id,
            'level' => $level, 'component' => $component, 'event_type' => $event_type,
            'message' => $message, 'context_json' => $json,
            'created_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        ], ['%d','%d','%d','%s','%s','%s','%s','%s','%s']);
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    public static function list_for_run($workspace_id, $run_id): array {
        global $wpdb;
        $workspace_id = (int) $workspace_id; $run_id = (int) $run_id;
        if ($workspace_id <= 0 || $run_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return []; }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d AND run_id = %d ORDER BY id ASC LIMIT 200', $workspace_id, $run_id), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        usort($rows, static function($a, $b){ return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0); });
        return array_map([__CLASS__, 'decode_row'], $rows);
    }

    public static function timeline_for_run($workspace_id, $run_id): array {
        $rows = self::list_for_run($workspace_id, $run_id);
        $timeline = [];
        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? 'log');
            $stage = self::stage_for_event($type);
            if (!isset($timeline[$stage])) {
                $timeline[$stage] = [
                    'stage' => $stage,
                    'event_type' => $type,
                    'count' => 0,
                    'level' => $row['level'] ?? 'info',
                    'status_chip' => self::status_chip((string) ($row['level'] ?? 'info'), $type),
                    'last_message' => '',
                    'last_at' => '',
                    'events' => [],
                ];
            }
            $preview = $row;
            if (isset($preview['context']) && is_array($preview['context'])) {
                $preview['context_preview'] = self::context_preview($preview['context']);
                unset($preview['context']);
            }
            $timeline[$stage]['count']++;
            $timeline[$stage]['event_type'] = $type;
            $timeline[$stage]['level'] = $row['level'] ?? 'info';
            $timeline[$stage]['status_chip'] = self::status_chip((string) ($row['level'] ?? 'info'), $type);
            $timeline[$stage]['last_message'] = (string) ($row['message'] ?? '');
            $timeline[$stage]['last_at'] = (string) ($row['created_at'] ?? '');
            $timeline[$stage]['events'][] = $preview;
        }
        return array_values($timeline);
    }

    public static function stage_for_event(string $event_type): string {
        $event_type = self::clean_key($event_type, 60);
        $map = [
            'run_created' => 'Created',
            'provider_requested' => 'Provider requested',
            'provider_authenticated' => 'Provider authenticated',
            'provider_auth_blocked' => 'Provider authenticated',
            'provider_result_received' => 'Provider result received',
            'provider_result_rejected' => 'Rows rejected',
            'normalized' => 'Rows normalized',
            'signal_created' => 'Signals created',
            'evidence_created' => 'Evidence created',
            'insight_created' => 'Insights created',
            'brief_created' => 'Briefs created',
            'draft_created' => 'Drafts created',
            'usage_reserved' => 'Usage reserved',
            'usage_settled' => 'Usage settled/voided',
            'usage_voided' => 'Usage settled/voided',
            'review_recorded' => 'Review pending',
            'outcome_recorded' => 'Outcome recorded',
            'pattern_updated' => 'Pattern updated',
            'report_built' => 'Report built',
            'run_completed' => 'Completed / partial / failed',
            'run_failed' => 'Completed / partial / failed',
        ];
        return $map[$event_type] ?? str_replace('_', ' ', $event_type ?: 'log');
    }

    public static function status_chip(string $level, string $event_type): string {
        $level = self::clean_key($level, 16);
        $event_type = self::clean_key($event_type, 60);
        if (in_array($event_type, ['provider_auth_blocked','provider_result_rejected'], true)) { return 'blocked'; }
        if (in_array($event_type, ['run_failed'], true) || $level === 'error') { return 'failed'; }
        if (in_array($event_type, ['run_completed','usage_settled','provider_authenticated'], true)) { return 'ok'; }
        if (in_array($event_type, ['review_recorded'], true)) { return 'needs review'; }
        if ($level === 'warn' || $level === 'warning') { return 'warning'; }
        return 'ok';
    }

    private static function context_preview(array $context): array {
        $context = class_exists('VES_Secret_Redactor') ? VES_Secret_Redactor::redact($context) : $context;
        $out = [];
        foreach ($context as $k => $v) {
            if (count($out) >= 8) { break; }
            if (is_array($v)) { $out[$k] = '[array:' . count($v) . ']'; }
            elseif (is_object($v)) { $out[$k] = '[object]'; }
            else { $out[$k] = self::clean_text((string) $v, 160); }
        }
        return $out;
    }

    public static function has_event($workspace_id, $run_id, string $event_type, array $context_match = []): bool {
        $event_type = self::clean_key($event_type, 60);
        foreach (self::list_for_run($workspace_id, $run_id) as $row) {
            if (($row['event_type'] ?? '') !== $event_type) { continue; }
            $ctx = is_array($row['context'] ?? null) ? $row['context'] : [];
            $ok = true;
            foreach ($context_match as $k => $v) { if ((string) ($ctx[$k] ?? '') !== (string) $v) { $ok = false; break; } }
            if ($ok) { return true; }
        }
        return false;
    }

    public static function debug($run_id, $component, $message, $context = []) { return self::write($run_id, 'debug', $component, 'log', $message, $context); }
    public static function info($run_id, $component, $message, $context = []) { return self::write($run_id, 'info', $component, 'log', $message, $context); }
    public static function warn($run_id, $component, $message, $context = []) { return self::write($run_id, 'warn', $component, 'log', $message, $context); }
    public static function error($run_id, $component, $message, $context = []) { return self::write($run_id, 'error', $component, 'log', $message, $context); }

    public static function decode_row(array $row): array {
        $row['id'] = (int) ($row['id'] ?? 0); $row['run_id'] = (int) ($row['run_id'] ?? 0); $row['workspace_id'] = (int) ($row['workspace_id'] ?? 0); $row['project_id'] = (int) ($row['project_id'] ?? 0);
        if (empty($row['event_type'])) { $row['event_type'] = 'log'; }
        $ctx = json_decode((string) ($row['context_json'] ?? ''), true);
        $row['context'] = VES_Secret_Redactor::redact(is_array($ctx) ? $ctx : []);
        $row['message'] = VES_Secret_Redactor::redact_string((string) ($row['message'] ?? ''));
        return $row;
    }

    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function clean_text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function encode($v): string { $json = function_exists('wp_json_encode') ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : json_encode($v); return is_string($json) ? $json : '{}'; }
}
