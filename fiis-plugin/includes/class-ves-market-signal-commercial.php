<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Commercial production layer for MarketSignal.
 *
 * Adds canonical WordPress tables, provider settings, health checks, audit logs,
 * export/delete endpoints, and a secure generation provider chain. The existing
 * React UI can continue using the state/generate endpoints while this layer keeps
 * a normalized record layer in sync for real SaaS operations.
 *
 * Provider architecture: WordPress + ChatGPT/OpenAI + Apify.
 */
final class VES_Market_Signal_Commercial {
    const DB_VERSION = '2.0.0';
    const OPTION_DB_VERSION = 'ves_market_signal_commercial_db_version';
    const OPTION_SETTINGS = 'ves_market_signal_commercial_settings';
    const REST_NAMESPACE = 'ves/v1';
    const REST_BASE = 'market-signal';

    private static $object_maps = [
        'workspaces' => [
            'table' => 'workspaces',
            'fields' => ['id','user_id','name','slug','status','brand_context','target_audience','industry','website_url','credit_balance','settings_json','created_at','updated_at'],
        ],
        'sources' => [
            'table' => 'sources',
            'fields' => ['id','user_id','workspace_id','source_type','name','status','config_json','created_at','updated_at'],
        ],
        'runs' => [
            'table' => 'runs',
            'fields' => ['id','user_id','workspace_id','source_id','run_type','status','input_json','result_json','started_at','completed_at','created_at','updated_at'],
        ],
        'signals' => [
            'table' => 'signals',
            'fields' => ['id','user_id','workspace_id','source_id','run_id','title','signal_type','status','confidence','summary','source_url','payload_json','created_at','updated_at'],
        ],
        'insights' => [
            'table' => 'insights',
            'fields' => ['id','user_id','workspace_id','run_id','primary_signal_id','title','insight_type','status','score','body','supporting_signal_ids_json','payload_json','created_at','updated_at'],
        ],
        'briefs' => [
            'table' => 'briefs',
            'fields' => ['id','user_id','workspace_id','insight_id','origin_type','origin_id','title','status','objective','target_audience','payload_json','created_at','updated_at'],
        ],
        'drafts' => [
            'table' => 'drafts',
            'fields' => ['id','user_id','workspace_id','brief_id','title','status','content','format','payload_json','created_at','updated_at'],
        ],
    ];

    public static function register() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        if (is_admin()) {
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        }
    }

    public static function register_admin_page() {
        add_submenu_page(
            'options-general.php',
            __('MarketSignal Commercial', 'ves'),
            __('MarketSignal Commercial', 'ves'),
            'manage_options',
            'ves-market-signal-commercial',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function register_settings() {
        register_setting('ves_market_signal_commercial', self::OPTION_SETTINGS, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => self::default_settings(),
        ]);
    }

    public static function default_settings() {
        return [
            'provider' => 'openai',
            'openai_model' => class_exists('VES_Config') ? VES_Config::get_openai_model_for('brief_generation') : 'gpt-4.1-mini',
            'openai_api_key' => '',
            'apify_token' => '',
            'apify_actor_id' => 'apify/google-search-scraper',
            'apify_task_id' => '',
            'apify_input_template' => '{"queries":"{{query}}","maxPagesPerQuery":1,"resultsPerPage":10}',
            'apify_max_items' => 20,
            'apify_timeout_seconds' => 60,
            'use_apify_for_runs' => 1,
            'allow_local_fallback' => 0,
            'max_prompt_chars' => 20000,
            'max_state_objects' => 750,
            'retention_days' => 30,
            'strict_json' => 1,
            'audit_ip_hashing' => 1,
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_SETTINGS, []);
        return array_merge(self::default_settings(), is_array($saved) ? $saved : []);
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $current = self::get_settings();
        $out = self::default_settings();
        $provider = sanitize_key((string) ($input['provider'] ?? $out['provider']));
        $out['provider'] = in_array($provider, ['local','openai','apify_openai'], true) ? $provider : 'openai';
        $out['openai_model'] = sanitize_text_field((string) ($input['openai_model'] ?? $out['openai_model']));
        $incoming_key = trim((string) ($input['openai_api_key'] ?? ''));
        $out['openai_api_key'] = ($incoming_key === '********') ? (string) ($current['openai_api_key'] ?? '') : sanitize_text_field($incoming_key);
        $incoming_apify = trim((string) ($input['apify_token'] ?? ''));
        $out['apify_token'] = ($incoming_apify === '********') ? (string) ($current['apify_token'] ?? '') : sanitize_text_field($incoming_apify);
        $out['apify_actor_id'] = self::clean_actor_id((string) ($input['apify_actor_id'] ?? $out['apify_actor_id']));
        $out['apify_task_id'] = self::clean_actor_id((string) ($input['apify_task_id'] ?? ''));
        $out['apify_input_template'] = self::sanitize_json_template((string) ($input['apify_input_template'] ?? $out['apify_input_template']));
        $out['apify_max_items'] = max(1, min(100, (int) ($input['apify_max_items'] ?? 20)));
        $out['apify_timeout_seconds'] = max(20, min(240, (int) ($input['apify_timeout_seconds'] ?? 60)));
        $out['use_apify_for_runs'] = !empty($input['use_apify_for_runs']) ? 1 : 0;
        $out['allow_local_fallback'] = !empty($input['allow_local_fallback']) ? 1 : 0;
        $out['strict_json'] = !empty($input['strict_json']) ? 1 : 0;
        $out['audit_ip_hashing'] = !empty($input['audit_ip_hashing']) ? 1 : 0;
        $out['max_prompt_chars'] = max(500, min(100000, (int) ($input['max_prompt_chars'] ?? 20000)));
        $out['max_state_objects'] = max(50, min(5000, (int) ($input['max_state_objects'] ?? 750)));
        $out['retention_days'] = max(1, min(365, (int) ($input['retention_days'] ?? 30)));
        return $out;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Permission denied.', 'ves')); }
        $settings = self::get_settings();
        $health = self::health_report();
        echo '<div class="wrap"><h1>MarketSignal Commercial Production</h1>';
        echo '<p>This layer turns MarketSignal into a server-side SaaS workspace: canonical tables, audit logs, provider settings, export/delete routes, and health checks.</p>';
        echo '<h2>Health</h2><table class="widefat striped" style="max-width:920px"><tbody>';
        foreach ($health as $k => $v) {
            if (is_array($v)) { $v = wp_json_encode($v); }
            echo '<tr><th style="width:240px">' . esc_html($k) . '</th><td><code>' . esc_html((string) $v) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="options.php" style="max-width:920px;margin-top:24px">';
        settings_fields('ves_market_signal_commercial');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::row_select('Provider', 'provider', $settings['provider'], ['openai' => 'ChatGPT / OpenAI only', 'apify_openai' => 'Apify research + ChatGPT interpretation', 'local' => 'Local deterministic fallback']);
        self::row_text('OpenAI model', 'openai_model', $settings['openai_model']);
        self::row_password('OpenAI API key', 'openai_api_key', !empty($settings['openai_api_key']) ? '********' : '');
        self::row_password('Apify API token', 'apify_token', !empty($settings['apify_token']) ? '********' : '');
        self::row_text('Apify Actor ID', 'apify_actor_id', $settings['apify_actor_id']);
        self::row_text('Apify Task ID optional', 'apify_task_id', $settings['apify_task_id']);
        self::row_textarea('Apify input template JSON', 'apify_input_template', $settings['apify_input_template']);
        self::row_number('Apify max items', 'apify_max_items', $settings['apify_max_items']);
        self::row_number('Apify timeout seconds', 'apify_timeout_seconds', $settings['apify_timeout_seconds']);
        self::row_checkbox('Use Apify for research runs', 'use_apify_for_runs', !empty($settings['use_apify_for_runs']), 'For Run generation, collect public signals from Apify before ChatGPT interprets them.');
        self::row_checkbox('Allow local fallback', 'allow_local_fallback', !empty($settings['allow_local_fallback']), 'Use deterministic local generation if ChatGPT/Apify is unavailable. Keep disabled for strict production.');
        self::row_checkbox('Strict JSON', 'strict_json', !empty($settings['strict_json']), 'Require provider output to parse as JSON.');
        self::row_checkbox('Hash IP/user agent in audit logs', 'audit_ip_hashing', !empty($settings['audit_ip_hashing']), 'Recommended for privacy-conscious auditability.');
        self::row_number('Max prompt characters', 'max_prompt_chars', $settings['max_prompt_chars']);
        self::row_number('Max state objects per save', 'max_state_objects', $settings['max_state_objects']);
        self::row_number('Retention days', 'retention_days', $settings['retention_days']);
        echo '</tbody></table>';
        submit_button('Save production settings');
        echo '</form></div>';
    }

    private static function option_name($key) { return self::OPTION_SETTINGS . '[' . esc_attr($key) . ']'; }
    private static function row_text($label, $key, $value) { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><input type="text" class="regular-text" name="' . self::option_name($key) . '" value="' . esc_attr((string) $value) . '"></td></tr>'; }
    private static function row_textarea($label, $key, $value) { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><textarea class="large-text code" rows="5" name="' . self::option_name($key) . '">' . esc_textarea((string) $value) . '</textarea></td></tr>'; }
    private static function row_password($label, $key, $value) { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><input type="password" class="regular-text" autocomplete="new-password" name="' . self::option_name($key) . '" value="' . esc_attr((string) $value) . '"></td></tr>'; }
    private static function row_number($label, $key, $value) { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><input type="number" class="small-text" name="' . self::option_name($key) . '" value="' . esc_attr((string) $value) . '"></td></tr>'; }
    private static function row_checkbox($label, $key, $checked, $help = '') { echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . self::option_name($key) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($help) . '</label></td></tr>'; }
    private static function row_select($label, $key, $value, $options) { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><select name="' . self::option_name($key) . '">'; foreach ($options as $k => $lab) { echo '<option value="' . esc_attr($k) . '" ' . selected($value, $k, false) . '>' . esc_html($lab) . '</option>'; } echo '</select></td></tr>'; }

    public static function table_name($slug) {
        global $wpdb;
        return $wpdb->prefix . 'ves_ms_' . sanitize_key((string) $slug);
    }

    public static function audit_table_name() {
        return self::table_name('audit_log');
    }

    public static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function create_tables($force = false) {
        if (!$force && get_option(self::OPTION_DB_VERSION) === self::DB_VERSION && self::table_exists(self::audit_table_name())) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = [];
        $sql[] = "CREATE TABLE " . self::table_name('workspaces') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(190) NOT NULL DEFAULT '',
            slug varchar(190) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'active',
            brand_context longtext NULL,
            target_audience longtext NULL,
            industry varchar(160) NOT NULL DEFAULT '',
            website_url text NULL,
            credit_balance decimal(12,2) NOT NULL DEFAULT 0,
            settings_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY user_id (user_id),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('sources') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            source_type varchar(60) NOT NULL DEFAULT 'manual_input',
            name varchar(190) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'active',
            config_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY source_type (source_type),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('runs') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            source_id varchar(80) NOT NULL DEFAULT '',
            run_type varchar(60) NOT NULL DEFAULT 'research',
            status varchar(40) NOT NULL DEFAULT 'completed',
            input_json longtext NULL,
            result_json longtext NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY source_id (source_id),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('signals') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            source_id varchar(80) NOT NULL DEFAULT '',
            run_id varchar(80) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            signal_type varchar(60) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'new',
            confidence varchar(40) NOT NULL DEFAULT '',
            summary longtext NULL,
            source_url text NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY source_id (source_id),
            KEY run_id (run_id),
            KEY signal_type (signal_type),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('insights') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            run_id varchar(80) NOT NULL DEFAULT '',
            primary_signal_id varchar(80) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            insight_type varchar(60) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'new',
            score decimal(8,3) NOT NULL DEFAULT 0,
            body longtext NULL,
            supporting_signal_ids_json longtext NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY run_id (run_id),
            KEY insight_type (insight_type),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('briefs') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            insight_id varchar(80) NOT NULL DEFAULT '',
            origin_type varchar(60) NOT NULL DEFAULT '',
            origin_id varchar(80) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'draft',
            objective longtext NULL,
            target_audience longtext NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY insight_id (insight_id),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::table_name('drafts') . " (
            row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            id varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            workspace_id varchar(80) NOT NULL DEFAULT '',
            brief_id varchar(80) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT 'draft',
            content longtext NULL,
            format varchar(80) NOT NULL DEFAULT '',
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (row_id),
            UNIQUE KEY user_item (user_id,id),
            KEY workspace_id (workspace_id),
            KEY brief_id (brief_id),
            KEY status (status)
        ) {$charset};";
        $sql[] = "CREATE TABLE " . self::audit_table_name() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(80) NOT NULL DEFAULT '',
            target_type varchar(80) NOT NULL DEFAULT '',
            target_id varchar(80) NOT NULL DEFAULT '',
            ip_hash varchar(80) NOT NULL DEFAULT '',
            agent_hash varchar(80) NOT NULL DEFAULT '',
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY target_lookup (target_type,target_id),
            KEY created_at (created_at)
        ) {$charset};";
        foreach ($sql as $statement) { dbDelta($statement); }
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'rest_health'],
            'permission_callback' => [__CLASS__, 'admin_permission_check'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'rest_export'],
            'permission_callback' => [__CLASS__, 'permission_check'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'rest_events'],
            'permission_callback' => [__CLASS__, 'permission_check'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/delete-my-data', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [__CLASS__, 'rest_delete_my_data'],
            'permission_callback' => [__CLASS__, 'permission_check'],
        ]);
    }

    public static function permission_check() {
        if (!is_user_logged_in()) {
            return new WP_Error('ves_ms_auth_required', __('Login required.', 'ves'), ['status' => 401]);
        }
        if (class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel(get_current_user_id())) {
            return new WP_Error('ves_ms_forbidden', __('Your account cannot access this workspace.', 'ves'), ['status' => 403]);
        }
        return current_user_can('read');
    }

    public static function admin_permission_check() {
        return current_user_can('manage_options');
    }

    public static function rest_health(WP_REST_Request $request) {
        return rest_ensure_response(['ok' => true, 'health' => self::health_report()]);
    }

    public static function rest_events(WP_REST_Request $request) {
        global $wpdb;
        self::create_tables();
        $user_id = get_current_user_id();
        $limit = max(1, min(100, (int) $request->get_param('limit')));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT event_type,target_type,target_id,payload_json,created_at FROM ' . self::audit_table_name() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', $user_id, $limit), ARRAY_A);
        foreach ($rows as &$row) { $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true); unset($row['payload_json']); }
        return rest_ensure_response(['ok' => true, 'events' => $rows]);
    }

    public static function rest_export(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        return rest_ensure_response(['ok' => true, 'exported_at' => current_time('mysql', true), 'data' => self::export_user_data($user_id)]);
    }

    public static function rest_delete_my_data(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $deleted = self::delete_user_data($user_id);
        self::audit($user_id, 'user_data_deleted', 'user', (string) $user_id, ['deleted' => $deleted]);
        return rest_ensure_response(['ok' => true, 'deleted' => $deleted]);
    }

    public static function health_report() {
        self::create_tables();
        $settings = self::get_settings();
        $tables = [];
        foreach (['workspaces','sources','runs','signals','insights','briefs','drafts','audit_log'] as $slug) {
            $tables[$slug] = self::table_exists($slug === 'audit_log' ? self::audit_table_name() : self::table_name($slug));
        }
        $openai_key_present = self::get_openai_api_key($settings) !== '';
        return [
            'db_version' => get_option(self::OPTION_DB_VERSION),
            'expected_db_version' => self::DB_VERSION,
            'tables' => $tables,
            'provider' => $settings['provider'],
            'openai_key_present' => $openai_key_present ? 'yes' : 'no',
            'apify_token_present' => self::get_apify_token($settings) !== '' ? 'yes' : 'no',
            'apify_actor_id' => $settings['apify_actor_id'],
            'apify_task_id' => !empty($settings['apify_task_id']) ? $settings['apify_task_id'] : '',
            'use_apify_for_runs' => !empty($settings['use_apify_for_runs']) ? 'yes' : 'no',
            'local_fallback' => !empty($settings['allow_local_fallback']) ? 'enabled' : 'disabled',
            'plugin_version' => defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '',
        ];
    }

    public static function sync_state($user_id, $state) {
        self::create_tables();
        $user_id = max(0, (int) $user_id);
        if ($user_id <= 0 || !is_array($state)) { return ['synced' => 0]; }
        $settings = self::get_settings();
        $max = max(50, (int) $settings['max_state_objects']);
        $count = 0;
        foreach (self::$object_maps as $state_key => $map) {
            $items = isset($state[$state_key]) && is_array($state[$state_key]) ? $state[$state_key] : [];
            foreach ($items as $item) {
                if ($count >= $max) { break 2; }
                if (!is_array($item)) { continue; }
                self::upsert_item($user_id, $state_key, $item);
                $count++;
            }
        }
        self::audit($user_id, 'canonical_state_synced', 'state', (string) $user_id, ['object_count' => $count]);
        return ['synced' => $count, 'max' => $max];
    }

    private static function upsert_item($user_id, $state_key, $item) {
        global $wpdb;
        $table_slug = self::$object_maps[$state_key]['table'];
        $table = self::table_name($table_slug);
        $now = current_time('mysql', true);
        $id = self::item_id($item);
        if ($id === '') { return false; }
        $row = self::row_for_item($user_id, $state_key, $item, $now);
        $existing = $wpdb->get_var($wpdb->prepare('SELECT row_id FROM ' . $table . ' WHERE user_id = %d AND id = %s LIMIT 1', $user_id, $id));
        if ($existing) {
            unset($row['created_at']);
            return false !== $wpdb->update($table, $row, ['row_id' => (int) $existing], self::formats_for_row($row), ['%d']);
        }
        return false !== $wpdb->insert($table, $row, self::formats_for_row($row));
    }

    private static function row_for_item($user_id, $state_key, $item, $now) {
        $id = self::item_id($item);
        $workspace_id = self::clean_id((string) ($item['workspace_id'] ?? ''));
        $base = ['id' => $id, 'user_id' => (int) $user_id, 'created_at' => self::date_value($item['created_at'] ?? $now), 'updated_at' => self::date_value($item['updated_at'] ?? $now)];
        $payload = wp_json_encode(self::sanitize_payload($item), JSON_UNESCAPED_UNICODE);
        if ($state_key === 'workspaces') {
            return array_merge($base, [
                'name' => self::text($item['name'] ?? 'Workspace', 190),
                'slug' => sanitize_title((string) ($item['slug'] ?? $item['name'] ?? $id)),
                'status' => self::status($item['status'] ?? 'active'),
                'brand_context' => self::long($item['brand_context'] ?? ''),
                'target_audience' => self::long($item['target_audience'] ?? ''),
                'industry' => self::text($item['industry'] ?? '', 160),
                'website_url' => esc_url_raw((string) ($item['website_url'] ?? '')),
                'credit_balance' => isset($item['credit_balance']) ? (float) $item['credit_balance'] : 0,
                'settings_json' => $payload,
            ]);
        }
        if ($state_key === 'sources') {
            return array_merge($base, ['workspace_id' => $workspace_id, 'source_type' => self::status($item['type'] ?? $item['source_type'] ?? 'manual_input'), 'name' => self::text($item['name'] ?? 'Source', 190), 'status' => self::status($item['status'] ?? 'active'), 'config_json' => $payload]);
        }
        if ($state_key === 'runs') {
            return array_merge($base, ['workspace_id' => $workspace_id, 'source_id' => self::clean_id((string) ($item['source_id'] ?? '')), 'run_type' => self::status($item['type'] ?? $item['run_type'] ?? 'research'), 'status' => self::status($item['status'] ?? 'completed'), 'input_json' => $payload, 'result_json' => $payload, 'started_at' => self::date_value($item['started_at'] ?? $item['created_at'] ?? $now), 'completed_at' => self::date_value($item['completed_at'] ?? $item['created_at'] ?? $now)]);
        }
        if ($state_key === 'signals') {
            return array_merge($base, ['workspace_id' => $workspace_id, 'source_id' => self::clean_id((string) ($item['source_id'] ?? '')), 'run_id' => self::clean_id((string) ($item['run_id'] ?? '')), 'title' => self::text($item['title'] ?? 'Signal', 255), 'signal_type' => self::status($item['type'] ?? $item['signal_type'] ?? ''), 'status' => self::status($item['status'] ?? 'new'), 'confidence' => self::status($item['confidence'] ?? ''), 'summary' => self::long($item['summary'] ?? $item['body'] ?? ''), 'source_url' => esc_url_raw((string) ($item['source_url'] ?? $item['url'] ?? '')), 'payload_json' => $payload]);
        }
        if ($state_key === 'insights') {
            return array_merge($base, ['workspace_id' => $workspace_id, 'run_id' => self::clean_id((string) ($item['run_id'] ?? '')), 'primary_signal_id' => self::clean_id((string) ($item['signal_id'] ?? $item['primary_signal_id'] ?? '')), 'title' => self::text($item['title'] ?? 'Insight', 255), 'insight_type' => self::status($item['type'] ?? $item['insight_type'] ?? ''), 'status' => self::status($item['status'] ?? 'new'), 'score' => isset($item['score']) ? (float) $item['score'] : 0, 'body' => self::long($item['body'] ?? $item['summary'] ?? ''), 'supporting_signal_ids_json' => wp_json_encode($item['supporting_signal_ids'] ?? []), 'payload_json' => $payload]);
        }
        if ($state_key === 'briefs') {
            return array_merge($base, ['workspace_id' => $workspace_id, 'insight_id' => self::clean_id((string) ($item['insight_id'] ?? '')), 'origin_type' => self::status($item['origin_type'] ?? 'manual'), 'origin_id' => self::clean_id((string) ($item['origin_id'] ?? $item['insight_id'] ?? '')), 'title' => self::text($item['title'] ?? 'Brief', 255), 'status' => self::status($item['status'] ?? 'draft'), 'objective' => self::long($item['objective'] ?? ''), 'target_audience' => self::long($item['target_audience'] ?? ''), 'payload_json' => $payload]);
        }
        return array_merge($base, ['workspace_id' => $workspace_id, 'brief_id' => self::clean_id((string) ($item['brief_id'] ?? '')), 'title' => self::text($item['title'] ?? 'Draft', 255), 'status' => self::status($item['status'] ?? 'draft'), 'content' => self::long($item['content'] ?? ''), 'format' => self::status($item['format'] ?? $item['type'] ?? ''), 'payload_json' => $payload]);
    }

    private static function formats_for_row($row) {
        $formats = [];
        foreach ($row as $k => $v) {
            if ($k === 'user_id') { $formats[] = '%d'; }
            elseif ($k === 'credit_balance' || $k === 'score') { $formats[] = '%f'; }
            else { $formats[] = '%s'; }
        }
        return $formats;
    }

    public static function generate($user_id, $kind, $prompt) {
        $settings = self::get_settings();
        $prompt = self::truncate((string) $prompt, (int) $settings['max_prompt_chars']);
        if (trim($prompt) === '') {
            return new WP_Error('ves_ms_empty_prompt', __('Prompt is required.', 'ves'), ['status' => 400]);
        }
        $provider = sanitize_key((string) $settings['provider']);

        // Phase 1 A2/A3 + Phase 3B Part E: bind usage context + advisory preflight.
        // workspace_id is now resolved via the shared resolver (explicit -> user-keyed
        // workspace) instead of defaulting to 0. The generation runs inside try/finally
        // so ambient context can never leak on an exception path.
        $has_tracker = class_exists('VES_AI_Usage_Tracker');
        $workspace_id = self::resolve_workspace_id([], (int) $user_id);
        if ($has_tracker) {
            VES_AI_Usage_Tracker::set_context([
                'module' => 'market_signal', 'operation_type' => 'market_signal_analysis',
                'user_id' => (int) $user_id, 'workspace_id' => $workspace_id,
            ]);
            if (method_exists('VES_AI_Usage_Tracker', 'preflight')) {
                try {
                    VES_AI_Usage_Tracker::preflight([
                        'module' => 'market_signal', 'operation_type' => 'market_signal_analysis',
                        'user_id' => (int) $user_id, 'workspace_id' => $workspace_id,
                        'model' => (string) ($settings['openai_model'] ?? 'gpt-4.1-mini'),
                        'input_text' => $prompt, 'max_output_tokens' => 2600,
                        'record_estimate' => true, // Fix 2: persist the estimate as a usage row.
                    ]);
                } catch (\Throwable $e) { /* advisory only */ }
            }
        }

        try {
            $result = null;
            if ($provider === 'apify_openai' && $kind === 'run' && !empty($settings['use_apify_for_runs'])) {
                $result = self::generate_with_apify_and_openai($kind, $prompt, $settings, $user_id);
            } elseif ($provider === 'openai' || $provider === 'apify_openai') {
                $result = self::generate_with_openai($kind, $prompt, $settings, $user_id);
            } else {
                $result = self::local_generate($kind, $prompt);
            }
            if (is_wp_error($result) && !empty($settings['allow_local_fallback'])) {
                self::audit($user_id, 'provider_fallback_used', 'generation', $kind, ['provider' => $provider, 'error' => $result->get_error_message()]);
                $result = self::local_generate($kind, $prompt);
            }
            if (is_wp_error($result)) { return $result; }
            self::audit($user_id, 'generation_completed', 'generation', $kind, ['provider' => is_array($result) && isset($result['_provider']) ? $result['_provider'] : $provider, 'kind' => $kind]);
            if (is_array($result) && isset($result['_provider'])) { unset($result['_provider']); }
            return $result;
        } finally {
            if ($has_tracker) { VES_AI_Usage_Tracker::clear_context(); }
        }
    }

    private static function generate_with_apify_and_openai($kind, $prompt, $settings, $user_id) {
        $items = self::run_apify_sync($prompt, $settings, $user_id);
        if (is_wp_error($items)) { return $items; }
        $compact_items = self::normalize_apify_items($items, (int) $settings['apify_max_items']);
        $apify_context = wp_json_encode($compact_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $enhanced_prompt = $prompt . "\n\nApify public-signal dataset items:\n" . self::truncate($apify_context, 16000) . "\n\nUse the Apify items as evidence. Return only valid JSON for signals and insights. Do not invent sources that are not present in the dataset.";
        $result = self::generate_with_openai($kind, $enhanced_prompt, $settings, $user_id);
        if (is_wp_error($result)) { return $result; }
        if (is_array($result)) {
            $result['_provider'] = 'apify_openai';
            $result['_apify_items_count'] = count($compact_items);
        }
        return $result;
    }

    /**
     * Phase 3B Part E — resolve a workspace id without inventing one. Explicit wins;
     * otherwise the plugin's established user-keyed workspace identity (via
     * VES_Memory_Records::infer_workspace_id); 0 only when genuinely unavailable.
     */
    private static function resolve_workspace_id(array $context = [], int $user_id = 0): int {
        $user_id = max(0, (int) ($user_id ?: ($context['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))));
        if (class_exists('VES_Memory_Records') && method_exists('VES_Memory_Records', 'infer_workspace_id')) {
            return max(0, (int) VES_Memory_Records::infer_workspace_id($context, $user_id));
        }
        $explicit = (int) ($context['workspace_id'] ?? 0);
        return $explicit > 0 ? $explicit : 0;
    }

    private static function run_apify_sync($prompt, $settings, $user_id) {
        $token = self::get_apify_token($settings);
        if ($token === '') { return new WP_Error('ves_ms_apify_missing_token', __('Apify API token is not configured.', 'ves'), ['status' => 500]); }
        $task_id = self::clean_actor_id((string) ($settings['apify_task_id'] ?? ''));
        $actor_id = self::clean_actor_id((string) ($settings['apify_actor_id'] ?? ''));
        if ($task_id === '' && $actor_id === '') { return new WP_Error('ves_ms_apify_missing_actor', __('Apify Actor ID or Task ID is required.', 'ves'), ['status' => 500]); }
        $apify_id = str_replace('/', '~', $task_id !== '' ? $task_id : $actor_id);
        $base = $task_id !== '' ? 'https://api.apify.com/v2/actor-tasks/' : 'https://api.apify.com/v2/acts/';
        $url = add_query_arg([
            'format' => 'json',
            'clean' => 'true',
            'maxItems' => max(1, min(100, (int) ($settings['apify_max_items'] ?? 20))),
            'timeout' => max(20, min(240, (int) ($settings['apify_timeout_seconds'] ?? 60))),
        ], $base . rawurlencode($apify_id) . '/run-sync-get-dataset-items');
        $input = self::build_apify_input($prompt, $settings);
        $response = wp_remote_post($url, [
            'timeout' => max(30, min(260, (int) ($settings['apify_timeout_seconds'] ?? 60) + 20)),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
                'User-Agent' => 'MarketSignal-WordPress-ChatGPT-Apify/' . (defined('VES_PLUGIN_VERSION') ? VES_PLUGIN_VERSION : '1.0'),
            ],
            'body' => wp_json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $ms_actor = $task_id !== '' ? $task_id : $actor_id;
        if (is_wp_error($response)) { self::record_apify_scrape($user_id, $ms_actor, 0, 'failed', 'transport_error'); return $response; }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            self::record_apify_scrape($user_id, $ms_actor, 0, 'failed', 'http_' . $code);
            return new WP_Error('ves_ms_apify_http_error', 'Apify returned HTTP ' . $code . '.', ['status' => 502, 'body' => self::truncate($raw, 600)]);
        }
        $items = json_decode($raw, true);
        if (!is_array($items)) {
            self::record_apify_scrape($user_id, $ms_actor, 0, 'failed', 'invalid_json');
            return new WP_Error('ves_ms_apify_bad_json', __('Apify did not return valid JSON dataset items.', 'ves'), ['status' => 502]);
        }
        self::audit($user_id, 'apify_run_completed', 'apify', $ms_actor, ['items' => count($items)]);
        // Phase 3B Part C: non-DTF Apify coverage — emit one scrape usage row per run.
        self::record_apify_scrape($user_id, $ms_actor, count($items), 'completed', '');
        return $items;
    }

    /**
     * Phase 3B Part C — record a MarketSignal Apify run as scrape telemetry. Distinct
     * module ('market_signal') from DTF so the two never double-count. Never throws,
     * never charges, stores no token; cost is unknown (pricing_source=unavailable).
     */
    private static function record_apify_scrape($user_id, string $actor, int $item_count, string $status, string $error_code): void {
        if (!class_exists('VES_AI_Usage_Tracker') || !method_exists('VES_AI_Usage_Tracker', 'record_scrape')) { return; }
        try {
            $ws = self::resolve_workspace_id([], (int) $user_id);
            VES_AI_Usage_Tracker::record_scrape([
                'module' => 'market_signal',
                'operation_type' => 'apify_actor_run',
                'workspace_id' => $ws,
                'user_id' => (int) $user_id,
                'status' => $status,
                'error_code' => $error_code,
                'actor_id' => $actor,
                'item_count' => max(0, $item_count),
                'pricing_source' => 'unavailable',
                'metadata' => ['actor_name' => $actor, 'item_count' => max(0, $item_count), 'usage_source' => 'market_signal_sync'],
            ]);
        } catch (\Throwable $e) {
            if (class_exists('VES_Log')) { VES_Log::warn('market_signal', 'scrape_telemetry_failed', ['error_code' => 'scrape_telemetry_failed']); }
        }
    }

    private static function build_apify_input($prompt, $settings) {
        $query = self::extract_query_from_prompt($prompt);
        $template = (string) ($settings['apify_input_template'] ?? '');
        if ($template === '') { $template = '{"queries":"{{query}}","maxPagesPerQuery":1,"resultsPerPage":10}'; }
        $json = str_replace(['{{query}}','{{prompt}}'], [$query, self::truncate((string) $prompt, 4000)], $template);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) { $decoded = ['queries' => $query, 'maxPagesPerQuery' => 1, 'resultsPerPage' => 10]; }
        return $decoded;
    }

    private static function extract_query_from_prompt($prompt) {
        $input = '';
        foreach (['Input','Brand','Audience'] as $field) {
            if (preg_match('/' . preg_quote($field, '/') . ':\s*([\s\S]*?)(?:\n[A-Z][A-Za-z ]{1,30}:|\nReturn ONLY valid JSON|$)/i', (string) $prompt, $m)) {
                $input .= ' ' . trim((string) $m[1]);
            }
        }
        $input = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($input !== '' ? $input : (string) $prompt)));
        return self::truncate($input !== '' ? $input : 'marketing trends', 240);
    }

    private static function normalize_apify_items($items, $limit = 20) {
        $out = [];
        foreach ((array) $items as $item) {
            if (!is_array($item)) { continue; }
            $out[] = [
                'title' => self::truncate(wp_strip_all_tags((string) ($item['title'] ?? $item['name'] ?? $item['text'] ?? $item['caption'] ?? '')), 180),
                'url' => esc_url_raw((string) ($item['url'] ?? $item['link'] ?? $item['postUrl'] ?? $item['videoUrl'] ?? '')),
                'description' => self::truncate(wp_strip_all_tags((string) ($item['description'] ?? $item['snippet'] ?? $item['text'] ?? $item['caption'] ?? '')), 500),
                'source' => self::truncate(wp_strip_all_tags((string) ($item['source'] ?? $item['domain'] ?? $item['platform'] ?? 'apify')), 80),
                'metrics' => array_filter([
                    'views' => $item['views'] ?? $item['playCount'] ?? null,
                    'likes' => $item['likes'] ?? $item['diggCount'] ?? null,
                    'comments' => $item['comments'] ?? $item['commentCount'] ?? null,
                    'shares' => $item['shares'] ?? $item['shareCount'] ?? null,
                ], static function($v) { return $v !== null && $v !== ''; }),
            ];
            if (count($out) >= max(1, min(100, (int) $limit))) { break; }
        }
        return $out;
    }

    private static function generate_with_openai($kind, $prompt, $settings, $user_id) {
        $api_key = self::get_openai_api_key($settings);
        if ($api_key === '') { return new WP_Error('ves_ms_openai_missing_key', __('OpenAI API key is not configured.', 'ves'), ['status' => 500]); }
        $schema_hint = self::schema_hint($kind);
        $schema = self::response_json_schema($kind);
        $system_prompt = 'You are a senior marketing intelligence operator inside a commercial WordPress SaaS. You must produce practical, evidence-led output, not generic advice. Use the user request, Apify/public-signal evidence, workspace context and provided memory when present. Separate observed evidence from inference. If evidence is thin, state the limitation and give a validation plan. Return only JSON matching this shape: ' . $schema_hint;
        $body = [
            'model' => sanitize_text_field((string) ($settings['openai_model'] ?? 'gpt-4.1-mini')),
            'input' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'market_signal_' . sanitize_key((string) $kind),
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'store' => false,
            'max_output_tokens' => 2600,
        ];
        $ves_ai_t0 = microtime(true); // Phase 1 cost-spine timing.
        $ves_ai_meta = [
            'module' => 'market_signal', 'operation_type' => 'ms_' . sanitize_key((string) $kind),
            'model' => (string) ($body['model'] ?? ''), 'user_id' => (int) $user_id,
        ];
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($response)) {
            if (class_exists('VES_AI_Usage_Tracker')) { VES_AI_Usage_Tracker::record_openai_call(array_merge($ves_ai_meta, ['started_at' => $ves_ai_t0, 'response' => $response])); }
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            if (class_exists('VES_AI_Usage_Tracker')) { VES_AI_Usage_Tracker::record_openai_call(array_merge($ves_ai_meta, ['started_at' => $ves_ai_t0, 'response' => json_decode($raw, true), 'status' => 'failed', 'error_code' => 'http_' . $code])); }
            return new WP_Error('ves_ms_openai_http_error', 'OpenAI returned HTTP ' . $code . '.', ['status' => 502, 'body' => self::truncate($raw, 600)]);
        }
        $decoded = json_decode($raw, true);
        if (class_exists('VES_AI_Usage_Tracker')) { VES_AI_Usage_Tracker::record_openai_call(array_merge($ves_ai_meta, ['started_at' => $ves_ai_t0, 'response' => is_array($decoded) ? $decoded : null])); }
        $json = class_exists('VES_OpenAI_Client') ? VES_OpenAI_Client::extract_output_json($decoded) : null;
        if (!is_array($json)) {
            $text = class_exists('VES_OpenAI_Client') ? VES_OpenAI_Client::extract_output_text($decoded) : self::extract_output_text($decoded);
            $json = json_decode(trim((string) $text), true);
            if (!is_array($json)) {
                $start = strpos((string) $text, '{'); $end = strrpos((string) $text, '}');
                if ($start !== false && $end !== false && $end > $start) { $json = json_decode(substr($text, $start, $end - $start + 1), true); }
            }
        }
        if (!is_array($json)) { return new WP_Error('ves_ms_openai_bad_json', __('OpenAI did not return parseable JSON.', 'ves'), ['status' => 502]); }
        $json['_provider'] = 'openai';
        return $json;
    }

    private static function get_openai_api_key($settings) {
        if (!empty($settings['openai_api_key'])) { return trim((string) $settings['openai_api_key']); }
        if (class_exists('VES_Config')) { return VES_Config::get_openai_api_key(); }
        if (defined('VE_SOCIAL_OPENAI_API_KEY') && VE_SOCIAL_OPENAI_API_KEY) { return trim((string) VE_SOCIAL_OPENAI_API_KEY); }
        return '';
    }

    private static function get_apify_token($settings) {
        if (!empty($settings['apify_token'])) { return trim((string) $settings['apify_token']); }
        if (class_exists('VES_Config')) { return VES_Config::get_token(); }
        if (defined('VE_SOCIAL_BACKEND_TOKEN') && VE_SOCIAL_BACKEND_TOKEN) { return trim((string) VE_SOCIAL_BACKEND_TOKEN); }
        return '';
    }

    private static function clean_actor_id($value) {
        $value = trim((string) $value);
        $value = str_replace('~', '/', $value);
        $value = preg_replace('/[^A-Za-z0-9_\-\.\/]/', '', $value);
        $value = trim($value, '/');
        return self::truncate($value, 190);
    }

    private static function sanitize_json_template($value) {
        $value = trim((string) $value);
        if ($value === '') { return '{"queries":"{{query}}","maxPagesPerQuery":1,"resultsPerPage":10}'; }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) { return '{"queries":"{{query}}","maxPagesPerQuery":1,"resultsPerPage":10}'; }
        return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function response_json_schema($kind) {
        $kind = sanitize_key((string) $kind);
        if ($kind === 'brief') {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'objective' => ['type' => 'string'],
                    'target_audience' => ['type' => 'string'],
                    'key_messages' => ['type' => 'string'],
                    'proof_points' => ['type' => 'string'],
                    'recommended_formats' => ['type' => 'string'],
                    'tone' => ['type' => 'string'],
                ],
                'required' => ['title','objective','target_audience','key_messages','proof_points','recommended_formats','tone'],
            ];
        }
        if ($kind === 'draft') {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                ],
                'required' => ['title','content'],
            ];
        }
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'signals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'confidence' => ['type' => 'string'],
                        ],
                        'required' => ['title','summary','type','confidence'],
                    ],
                ],
                'insights' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                        ],
                        'required' => ['title','body','type'],
                    ],
                ],
            ],
            'required' => ['signals','insights'],
        ];
    }

    private static function schema_hint($kind) {
        if ($kind === 'brief') { return '{"title":"","objective":"","target_audience":"","key_messages":"","proof_points":"","recommended_formats":"","tone":""}'; }
        if ($kind === 'draft') { return '{"title":"","content":""}'; }
        return '{"signals":[{"title":"","summary":"","type":"","confidence":""}],"insights":[{"title":"","body":"","type":""}]}';
    }

    private static function extract_output_text($payload) {
        if (!is_array($payload)) { return ''; }
        if (!empty($payload['output_text'])) { return (string) $payload['output_text']; }
        $chunks = [];
        foreach (($payload['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) { $chunks[] = is_array($content['text']) && isset($content['text']['value']) ? $content['text']['value'] : $content['text']; }
            }
        }
        return trim(implode("\n", array_map('strval', $chunks)));
    }

    private static function local_generate($kind, $prompt) {
        if (class_exists('VES_Market_Signal_Store')) {
            $ref = new ReflectionClass('VES_Market_Signal_Store');
            if ($ref->hasMethod('generate_payload')) {
                $m = $ref->getMethod('generate_payload');
                $m->setAccessible(true);
                $payload = $m->invoke(null, $prompt, $kind);
                if (is_array($payload)) { $payload['_provider'] = 'local'; return $payload; }
            }
        }
        $topic = self::text(wp_strip_all_tags($prompt), 80);
        if ($topic === '') { $topic = 'Market signal'; }
        if ($kind === 'brief') { return ['_provider' => 'local', 'title' => $topic . ' Brief', 'objective' => 'Turn the selected signal into an actionable execution brief.', 'target_audience' => 'Marketing teams', 'key_messages' => 'Interpret before creating. Use evidence. Keep the output testable.', 'proof_points' => 'Captured signal, insight, and one concrete example.', 'recommended_formats' => 'Post, article outline, campaign angle.', 'tone' => 'Direct and evidence-led.']; }
        if ($kind === 'draft') { return ['_provider' => 'local', 'title' => $topic, 'content' => 'Most teams do not need more raw data. They need a clearer interpretation of what changed, why it matters, and what to do next.']; }
        return ['_provider' => 'local', 'signals' => [['title' => 'Market signal', 'summary' => self::truncate($topic, 180), 'type' => 'manual_input', 'confidence' => 'medium']], 'insights' => [['title' => 'Interpret before creating', 'body' => 'Convert this signal into a brief before producing content.', 'type' => 'next_action']]];
    }

    public static function export_user_data($user_id) {
        global $wpdb;
        self::create_tables();
        $out = [];
        foreach (array_keys(self::$object_maps) as $key) {
            $table = self::table_name(self::$object_maps[$key]['table']);
            $out[$key] = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE user_id = %d ORDER BY row_id ASC', (int) $user_id), ARRAY_A);
        }
        $out['audit'] = $wpdb->get_results($wpdb->prepare('SELECT event_type,target_type,target_id,payload_json,created_at FROM ' . self::audit_table_name() . ' WHERE user_id = %d ORDER BY id ASC', (int) $user_id), ARRAY_A);
        return $out;
    }

    public static function delete_user_data($user_id) {
        global $wpdb;
        self::create_tables();
        $deleted = [];
        foreach (array_keys(self::$object_maps) as $key) {
            $table = self::table_name(self::$object_maps[$key]['table']);
            $res = $wpdb->delete($table, ['user_id' => (int) $user_id], ['%d']);
            $deleted[$key] = $res === false ? 0 : (int) $res;
        }
        return $deleted;
    }

    public static function audit($user_id, $event_type, $target_type = '', $target_id = '', $payload = []) {
        global $wpdb;
        self::create_tables();
        $settings = self::get_settings();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $hash_ip = !empty($settings['audit_ip_hashing']) && $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : '';
        $hash_agent = !empty($settings['audit_ip_hashing']) && $agent !== '' ? hash_hmac('sha256', $agent, wp_salt('auth')) : '';
        $wpdb->insert(self::audit_table_name(), [
            'user_id' => max(0, (int) $user_id),
            'event_type' => sanitize_key((string) $event_type),
            'target_type' => sanitize_key((string) $target_type),
            'target_id' => self::clean_id((string) $target_id),
            'ip_hash' => $hash_ip,
            'agent_hash' => $hash_agent,
            'payload_json' => wp_json_encode(is_array($payload) ? self::sanitize_payload($payload) : [], JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql', true),
        ], ['%d','%s','%s','%s','%s','%s','%s','%s']);
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    private static function item_id($item) { return self::clean_id((string) ($item['id'] ?? md5(wp_json_encode($item)))); }
    private static function clean_id($value) { return self::truncate(sanitize_text_field((string) $value), 80); }
    private static function status($value) { return self::truncate(sanitize_key((string) $value), 60); }
    private static function text($value, $max = 190) { return self::truncate(wp_strip_all_tags((string) $value), $max); }
    private static function long($value) { return self::truncate(wp_kses_post((string) $value), 50000); }
    private static function date_value($value) {
        if (is_numeric($value)) { return gmdate('Y-m-d H:i:s', ((int) $value) > 2000000000 ? ((int) $value / 1000) : (int) $value); }
        $ts = strtotime((string) $value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : current_time('mysql', true);
    }
    private static function sanitize_payload($value, $depth = 0) {
        if ($depth > 8) { return null; }
        if (is_array($value)) { $out = []; foreach ($value as $k => $v) { $out[is_string($k) ? self::truncate(sanitize_key($k), 80) : $k] = self::sanitize_payload($v, $depth + 1); } return $out; }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) { return $value; }
        return self::truncate(wp_kses_post((string) $value), 50000);
    }
    private static function truncate($text, $max) { $text = (string) $text; $max = max(1, (int) $max); return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max); }
}
