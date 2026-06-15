<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Server-side persistence + backend generation for the MarketSignal workspace.
 *
 * This keeps the React UI out of browser-only localStorage and gives v0.1 a
 * production-shaped WordPress record layer: REST, nonce/capability checks,
 * database persistence, audit events and credit-aware generation hooks.
 */
final class VES_Market_Signal_Store {
    const DB_VERSION = '1.0.2';
    const OPTION_KEY = 'ves_market_signal_store_db_version';
    const REST_NAMESPACE = 'ves/v1';
    const REST_BASE = 'market-signal';

    private static $state_keys = [
        'workspaces' => 'workspace',
        'sources'    => 'source',
        'signals'    => 'signal',
        'insights'   => 'insight',
        'briefs'     => 'brief',
        'drafts'     => 'draft',
        'runs'       => 'run',
        'usage'      => 'usage',
    ];

    public static function register() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function object_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_market_signal_objects';
    }

    public static function event_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_market_signal_events';
    }

    public static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function create_tables($force = false) {
        if (!$force && get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists(self::object_table_name()) && self::table_exists(self::event_table_name())) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $objects = self::object_table_name();
        $events = self::event_table_name();

        $sql_objects = "CREATE TABLE {$objects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(40) NOT NULL DEFAULT '',
            object_id varchar(80) NOT NULL DEFAULT '',
            workspace_id varchar(80) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            sort_order int(11) NOT NULL DEFAULT 0,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_object (user_id, object_type, object_id),
            KEY user_id (user_id),
            KEY object_type (object_type),
            KEY workspace_id (workspace_id),
            KEY status (status),
            KEY sort_order (sort_order),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $sql_events = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(64) NOT NULL DEFAULT '',
            target_type varchar(40) NOT NULL DEFAULT '',
            target_id varchar(80) NOT NULL DEFAULT '',
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY target_lookup (target_type, target_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql_objects);
        dbDelta($sql_events);
        if (class_exists('VES_Market_Signal_Commercial')) {
            VES_Market_Signal_Commercial::create_tables($force);
        }
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/state', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [__CLASS__, 'rest_get_state'],
                'permission_callback' => [__CLASS__, 'permission_check'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'rest_save_state'],
                'permission_callback' => [__CLASS__, 'permission_check'],
                'args' => [
                    'state' => ['required' => true, 'type' => 'object'],
                ],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/' . self::REST_BASE . '/generate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_generate'],
            'permission_callback' => [__CLASS__, 'permission_check'],
            'args' => [
                'prompt' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    public static function permission_check() {
        if (!is_user_logged_in()) {
            return new WP_Error('ves_market_signal_auth_required', __('Login required.', 'ves'), ['status' => 401]);
        }
        $user_id = get_current_user_id();
        if (class_exists('VES_Usage_Billing') && !VES_Usage_Billing::can_access_panel($user_id)) {
            return new WP_Error('ves_market_signal_forbidden', __('Your account cannot access this workspace.', 'ves'), ['status' => 403]);
        }
        return current_user_can('read');
    }

    public static function rest_get_state(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        return rest_ensure_response([
            'ok' => true,
            'state' => self::get_state($user_id),
            'server_time' => current_time('mysql', true),
        ]);
    }

    public static function rest_save_state(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $state = $request->get_param('state');
        $saved = self::replace_state($user_id, is_array($state) ? $state : []);
        if (is_wp_error($saved)) {
            return $saved;
        }
        return rest_ensure_response([
            'ok' => true,
            'saved' => $saved,
            'state' => self::get_state($user_id),
            'server_time' => current_time('mysql', true),
        ]);
    }

    public static function rest_generate(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $prompt = (string) $request->get_param('prompt');
        $prompt = self::truncate_text($prompt, 20000);
        if (trim($prompt) === '') {
            return new WP_Error('ves_market_signal_empty_prompt', __('Prompt is required.', 'ves'), ['status' => 400]);
        }

        $kind = self::detect_generation_kind($prompt);
        $event_type = self::event_type_for_kind($kind);
        $target_id = md5($kind . ':' . $prompt . ':' . microtime(true));
        $usage_key = 'market_signal:' . $kind . ':' . $user_id . ':' . $target_id;
        $usage = null;

        if (class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'reserve_usage')) {
            $usage = VES_Usage_Billing::reserve_usage(
                $user_id,
                $event_type,
                $usage_key,
                'MarketSignal generation reserved: ' . $kind,
                [
                    'kind' => $kind,
                    'prompt_hash' => md5($prompt),
                    'backend' => class_exists('VES_Market_Signal_Commercial') ? 'commercial_provider_chain' : 'wordpress_local_generator',
                    'target_type' => 'market_signal_' . $kind,
                    'target_id' => $target_id,
                ]
            );
            if (is_wp_error($usage)) { return $usage; }
        }

        $payload = class_exists('VES_Market_Signal_Commercial')
            ? VES_Market_Signal_Commercial::generate($user_id, $kind, $prompt)
            : self::generate_payload($prompt, $kind);

        if (is_wp_error($payload)) {
            if ($usage_key && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'void_reserved_usage')) {
                VES_Usage_Billing::void_reserved_usage($usage_key, 'MarketSignal generation failed: ' . $payload->get_error_message());
            }
            self::log_event($user_id, 'generate_' . $kind . '_failed', 'market_signal_' . $kind, $target_id, [
                'kind' => $kind,
                'prompt_hash' => md5($prompt),
                'error' => $payload->get_error_message(),
            ]);
            return $payload;
        }

        if ($usage_key && class_exists('VES_Usage_Billing') && method_exists('VES_Usage_Billing', 'post_reserved_usage')) {
            $posted = VES_Usage_Billing::post_reserved_usage($usage_key, 'MarketSignal generation completed: ' . $kind);
            if (is_wp_error($posted)) { return $posted; }
            if (is_array($usage)) { $usage['status'] = 'posted'; }
        }

        self::log_event($user_id, 'generate_' . $kind, 'market_signal_' . $kind, $target_id, [
            'kind' => $kind,
            'prompt_hash' => md5($prompt),
            'usage' => $usage,
        ]);
        if (class_exists('VES_Market_Signal_Commercial')) {
            VES_Market_Signal_Commercial::audit($user_id, 'generate_' . $kind, 'market_signal_' . $kind, $target_id, [
                'kind' => $kind,
                'prompt_hash' => md5($prompt),
                'usage_key' => $usage_key,
            ]);
        }

        return rest_ensure_response([
            'ok' => true,
            'kind' => $kind,
            'payload' => $payload,
            'usage' => $usage,
            'server_time' => current_time('mysql', true),
        ]);
    }

    public static function get_state($user_id) {
        global $wpdb;
        self::create_tables();
        $user_id = max(0, (int) $user_id);
        $state = self::empty_state();
        if ($user_id <= 0) {
            return $state;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT object_type, payload_json, updated_at FROM ' . self::object_table_name() . ' WHERE user_id = %d ORDER BY object_type ASC, sort_order ASC, id ASC',
            $user_id
        ), ARRAY_A);

        $reverse = array_flip(self::$state_keys);
        foreach ((array) $rows as $row) {
            $object_type = sanitize_key((string) ($row['object_type'] ?? ''));
            if (!isset($reverse[$object_type])) {
                continue;
            }
            $key = $reverse[$object_type];
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $state[$key][] = $payload;
        }

        return $state;
    }

    public static function replace_state($user_id, $state) {
        global $wpdb;
        self::create_tables();
        $user_id = max(0, (int) $user_id);
        if ($user_id <= 0) {
            return new WP_Error('ves_market_signal_invalid_user', __('Invalid user.', 'ves'), ['status' => 400]);
        }
        $state = self::sanitize_state($state);
        $objects = [];
        foreach (self::$state_keys as $key => $type) {
            foreach ((array) ($state[$key] ?? []) as $item) {
                if (!is_array($item)) { continue; }
                $object_id = self::object_id($item);
                if ($object_id === '') { continue; }
                $objects[] = [
                    'object_type' => $type,
                    'object_id' => $object_id,
                    'workspace_id' => self::workspace_id_for_item($item, $type),
                    'status' => self::status_for_item($item),
                    'title' => self::title_for_item($item, $type),
                    'payload_json' => wp_json_encode($item, JSON_UNESCAPED_UNICODE),
                    'sort_order' => count($objects),
                ];
            }
        }

        $table = self::object_table_name();
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $deleted = $wpdb->delete($table, ['user_id' => $user_id], ['%d']);
        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('ves_market_signal_delete_failed', __('Could not replace workspace state.', 'ves'), ['status' => 500]);
        }

        foreach ($objects as $object) {
            $inserted = $wpdb->insert($table, [
                'user_id' => $user_id,
                'object_type' => $object['object_type'],
                'object_id' => $object['object_id'],
                'workspace_id' => $object['workspace_id'],
                'status' => $object['status'],
                'title' => $object['title'],
                'payload_json' => $object['payload_json'],
                'sort_order' => (int) $object['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d','%s','%s','%s','%s','%s','%s','%d','%s','%s']);
            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('ves_market_signal_insert_failed', __('Could not save workspace state.', 'ves'), ['status' => 500]);
            }
        }
        $wpdb->query('COMMIT');

        self::log_event($user_id, 'state_saved', 'market_signal_state', (string) $user_id, [
            'object_count' => count($objects),
            'checksum' => md5(wp_json_encode($state)),
        ]);
        $sync = class_exists('VES_Market_Signal_Commercial') ? VES_Market_Signal_Commercial::sync_state($user_id, $state) : null;
        return ['object_count' => count($objects), 'canonical_sync' => $sync];
    }

    private static function empty_state() {
        return [
            'workspaces' => [],
            'sources' => [],
            'signals' => [],
            'insights' => [],
            'briefs' => [],
            'drafts' => [],
            'runs' => [],
            'usage' => [],
        ];
    }

    private static function sanitize_state($state) {
        $state = is_array($state) ? $state : [];
        $clean = self::empty_state();
        foreach (array_keys(self::$state_keys) as $key) {
            $items = isset($state[$key]) && is_array($state[$key]) ? $state[$key] : [];
            $clean[$key] = [];
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                $clean[$key][] = self::sanitize_payload($item, 0);
            }
        }
        return $clean;
    }

    private static function sanitize_payload($value, $depth = 0) {
        if ($depth > 8) { return null; }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? self::truncate_text(sanitize_key($k), 80) : $k;
                $out[$key] = self::sanitize_payload($v, $depth + 1);
            }
            return $out;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return self::truncate_text(wp_kses_post((string) $value), 20000);
    }

    private static function object_id($item) {
        $id = isset($item['id']) ? sanitize_text_field((string) $item['id']) : '';
        if ($id === '') {
            $id = md5(wp_json_encode($item));
        }
        return self::truncate_text($id, 80);
    }

    private static function workspace_id_for_item($item, $type) {
        if ($type === 'workspace') {
            return self::object_id($item);
        }
        $workspace_id = isset($item['workspace_id']) ? sanitize_text_field((string) $item['workspace_id']) : '';
        return self::truncate_text($workspace_id, 80);
    }

    private static function status_for_item($item) {
        return self::truncate_text(sanitize_key((string) ($item['status'] ?? '')), 40);
    }

    private static function title_for_item($item, $type) {
        $candidates = ['title', 'name', 'label', 'event_type', 'type'];
        foreach ($candidates as $key) {
            if (!empty($item[$key])) {
                return self::truncate_text(wp_strip_all_tags((string) $item[$key]), 240);
            }
        }
        return self::truncate_text($type . ':' . self::object_id($item), 240);
    }

    private static function detect_generation_kind($prompt) {
        $prompt = (string) $prompt;
        if (strpos($prompt, '"signals"') !== false && strpos($prompt, '"insights"') !== false) {
            return 'run';
        }
        if (preg_match('/senior content strategist/i', $prompt)) {
            return 'brief';
        }
        if (preg_match('/expert copywriter/i', $prompt)) {
            return 'draft';
        }
        return 'run';
    }

    private static function event_type_for_kind($kind) {
        if ($kind === 'brief') { return 'market_signal_brief_generation'; }
        if ($kind === 'draft') { return 'market_signal_draft_generation'; }
        return 'market_signal_research_run';
    }

    private static function generate_payload($prompt, $kind) {
        if ($kind === 'brief') { return self::build_brief($prompt); }
        if ($kind === 'draft') { return self::build_draft($prompt); }
        return self::build_signals($prompt); }

    private static function get_prompt_field($prompt, $label) {
        $label = preg_quote((string) $label, '/');
        if (preg_match('/' . $label . ':\s*([\s\S]*?)(?:\n[A-Z][A-Za-z ]{1,30}:|\nReturn ONLY valid JSON|$)/i', (string) $prompt, $m)) {
            return trim((string) $m[1]);
        }
        return '';
    }

    private static function clean_words($text, $limit = 8) {
        $stop = ['the','and','for','with','from','that','this','into','para','con','una','uno','los','las','del','por','que','como','brand','audience','general','source','input','market','signal'];
        $text = strtolower(remove_accents((string) $text));
        $text = preg_replace('/https?:\/\/\S+/i', ' ', $text);
        $text = preg_replace('/[^a-z0-9#@ ]+/i', ' ', $text);
        $parts = preg_split('/\s+/', $text);
        $out = [];
        foreach ((array) $parts as $word) {
            $word = trim((string) $word);
            if (strlen($word) <= 3 || in_array($word, $stop, true)) { continue; }
            $out[] = $word;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    private static function title_case($text) {
        return ucwords(str_replace(['_', '-'], ' ', (string) $text));
    }

    private static function summarize($text, $max = 170) {
        $s = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        if ($s === '') { return 'The workspace needs clearer market evidence before execution.'; }
        if (strlen($s) <= $max) { return $s; }
        return rtrim(substr($s, 0, $max)) . '...';
    }

    private static function build_signals($prompt) {
        $input = self::get_prompt_field($prompt, 'Input');
        if ($input === '') { $input = $prompt; }
        $brand = self::get_prompt_field($prompt, 'Brand');
        if ($brand === '') { $brand = 'the brand'; }
        $audience = self::get_prompt_field($prompt, 'Audience');
        if ($audience === '') { $audience = 'the target audience'; }
        $kws = self::clean_words($input . ' ' . $brand . ' ' . $audience, 8);
        $main = $kws[0] ?? 'market';
        $second = $kws[1] ?? 'audience';
        $third = $kws[2] ?? 'content';
        return [
            'signals' => [
                ['title' => self::title_case($main) . ' demand signal', 'summary' => 'The input suggests active interest around ' . $main . '. Treat this as a signal to validate search, social and competitor evidence before creating content.', 'type' => 'trend', 'confidence' => 'medium'],
                ['title' => 'Audience question around ' . self::title_case($second), 'summary' => 'People likely need clearer answers about ' . $second . '. Use this as a question-led content angle rather than a generic brand message.', 'type' => 'audience_question', 'confidence' => 'medium'],
                ['title' => 'Content gap: ' . self::title_case($third) . ' proof', 'summary' => 'Current context points to a possible proof gap. Stronger examples, comparisons or demonstrations could reduce uncertainty.', 'type' => 'content_gap', 'confidence' => 'high'],
                ['title' => 'Competitor positioning pressure', 'summary' => 'If competitors already own the obvious category language, the workspace should build a sharper angle tied to ' . $main . ' and measurable value.', 'type' => 'competitor_move', 'confidence' => 'low'],
                ['title' => 'Action opportunity: insight-to-brief flow', 'summary' => 'The most useful next step is to convert this research into a short brief with audience, message, proof points and output format.', 'type' => 'opportunity', 'confidence' => 'high'],
            ],
            'insights' => [
                ['title' => 'Do not jump from ' . self::title_case($main) . ' data to content too fast', 'body' => 'The strongest path is to interpret the signal first: what changed, who cares, what gap exists, and what decision the team should make. Input summary: ' . self::summarize($input, 220), 'type' => 'pattern'],
                ['title' => 'The useful middle layer is the brief', 'body' => 'This workspace should turn research into a structured brief before generating drafts. That keeps outputs tied to evidence instead of random prompting.', 'type' => 'next_action'],
                ['title' => 'Potential gap: proof and specificity', 'body' => 'The material points to a need for more concrete claims, examples and audience-specific proof. Avoid vague content until this is validated.', 'type' => 'gap'],
            ],
        ];
    }

    private static function build_brief($prompt) {
        $input = self::get_prompt_field($prompt, 'Input');
        if ($input === '') { $input = $prompt; }
        $brand = self::get_prompt_field($prompt, 'Brand');
        if ($brand === '') { $brand = 'Professional, evidence-led brand'; }
        $audience = self::get_prompt_field($prompt, 'Audience');
        if ($audience === '') { $audience = 'Marketing teams and agencies'; }
        $kws = self::clean_words($input . ' ' . $brand . ' ' . $audience, 8);
        $topic = self::title_case(implode(' ', array_slice($kws, 0, 3)) ?: 'Market Signal');
        return [
            'title' => $topic . ' Brief',
            'objective' => 'Turn the selected signal into an actionable marketing output that explains the opportunity, reduces noise, and gives the team a clear next move.',
            'target_audience' => $audience,
            'key_messages' => '1. Start from evidence, not assumptions. 2. Interpret the signal before creating. 3. Use the output to test a clear market angle.',
            'proof_points' => 'Use the captured signal, supporting insight, competitor/context notes and one concrete example before approving production.',
            'recommended_formats' => 'LinkedIn post, short article outline, campaign angle, sales enablement note, creative concept.',
            'tone' => 'Clear, strategic, direct, evidence-led, not hype-driven.',
        ];
    }

    private static function build_draft($prompt) {
        $ctx = self::get_prompt_field($prompt, 'Context');
        if ($ctx === '') { $ctx = $prompt; }
        $kws = self::clean_words($ctx, 8);
        $topic = self::title_case(implode(' ', array_slice($kws, 0, 3)) ?: 'Market Signal');
        return [
            'title' => $topic . ': from signal to action',
            'content' => "Most teams do not need more raw data. They need a clearer read on what the signal means.\n\nThe useful workflow is simple: capture the signal, interpret the pattern, define the brief, then create the output.\n\nFor this case, the next move is to test a focused message around " . ($kws[0] ?? 'the core audience need') . " and support it with concrete proof, not generic content.\n\nContext used: " . self::summarize($ctx, 260),
        ];
    }

    public static function log_event($user_id, $event_type, $target_type = '', $target_id = '', $payload = []) {
        global $wpdb;
        self::create_tables();
        $wpdb->insert(self::event_table_name(), [
            'user_id' => max(0, (int) $user_id),
            'event_type' => sanitize_key((string) $event_type),
            'target_type' => sanitize_key((string) $target_type),
            'target_id' => self::truncate_text(sanitize_text_field((string) $target_id), 80),
            'payload_json' => wp_json_encode(is_array($payload) ? $payload : [], JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql', true),
        ], ['%d','%s','%s','%s','%s','%s']);
        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    private static function truncate_text($text, $max) {
        $text = (string) $text;
        $max = max(1, (int) $max);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }
}
