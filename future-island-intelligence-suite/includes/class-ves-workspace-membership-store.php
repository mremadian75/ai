<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Additive workspace + membership store for the private-beta self-serve layer.
 *
 * This does not replace legacy user-scoped workspace fallbacks. Controllers should
 * continue resolving workspace IDs through VES_Workspace_Service, which prefers
 * active membership rows when present and falls back safely for older installs.
 */
final class VES_Workspace_Membership_Store {
    const WORKSPACE_TABLE = 'ves_workspaces';
    const MEMBER_TABLE = 'ves_workspace_members';
    const DB_VERSION = '1.0.0';
    const VERSION_OPT = 'ves_workspace_membership_db_version';

    const ROLES = ['owner','admin','operator','reviewer','viewer'];

    const CAPABILITIES = [
        'can_view_workspace','can_run_playbook','can_review_outputs','can_manage_sources',
        'can_view_usage','can_record_outcome','can_manage_memory','can_manage_members','can_export_reports',
    ];

    public static function workspace_table(): string { global $wpdb; return $wpdb->prefix . self::WORKSPACE_TABLE; }
    public static function member_table(): string { global $wpdb; return $wpdb->prefix . self::MEMBER_TABLE; }

    public static function create_tables($force = false): void {
        if (!$force && function_exists('get_option') && get_option(self::VERSION_OPT) === self::DB_VERSION) { return; }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $cc = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $workspaces = self::workspace_table();
        $members = self::member_table();
        $sql1 = "CREATE TABLE {$workspaces} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id varchar(64) NOT NULL,
            name varchar(191) NOT NULL DEFAULT '',
            slug varchar(191) NULL,
            status varchar(40) NOT NULL DEFAULT 'active',
            default_language varchar(20) NULL,
            market varchar(80) NULL,
            timezone varchar(80) NULL,
            brand_profile_json longtext NULL,
            settings_json longtext NULL,
            created_by_user_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY public_id (public_id),
            KEY slug (slug),
            KEY status (status),
            KEY created_by_user_id (created_by_user_id)
        ) {$cc};";
        $sql2 = "CREATE TABLE {$members} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(40) NOT NULL DEFAULT 'viewer',
            status varchar(40) NOT NULL DEFAULT 'active',
            capabilities_json longtext NULL,
            invited_by_user_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY role (role),
            KEY status (status),
            UNIQUE KEY workspace_user (workspace_id,user_id)
        ) {$cc};";
        if (function_exists('dbDelta')) { dbDelta($sql1); dbDelta($sql2); }
        elseif (method_exists($wpdb, 'query')) { $wpdb->query($sql1); $wpdb->query($sql2); }
        if (function_exists('update_option')) { update_option(self::VERSION_OPT, self::DB_VERSION, false); }
    }

    public static function create_table($force = false): void { self::create_tables($force); }

    /** @return int|WP_Error */
    public static function create_workspace(array $args) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_workspace_no_db', 'Database unavailable.'); }
        $name = self::text((string) ($args['name'] ?? ''), 191);
        if ($name === '') { return self::err('ves_workspace_missing_name', 'Workspace name is required.'); }
        $now = self::now();
        $public_id = self::public_id((string) ($args['public_id'] ?? ''));
        $row = [
            'public_id' => $public_id,
            'name' => $name,
            'slug' => self::slug((string) ($args['slug'] ?? $name)),
            'status' => self::status((string) ($args['status'] ?? 'active')),
            'default_language' => self::text((string) ($args['default_language'] ?? ''), 20),
            'market' => self::text((string) ($args['market'] ?? ''), 80),
            'timezone' => self::text((string) ($args['timezone'] ?? ''), 80),
            'brand_profile_json' => self::json(is_array($args['brand_profile'] ?? null) ? $args['brand_profile'] : []),
            'settings_json' => self::json(is_array($args['settings'] ?? null) ? $args['settings'] : []),
            'created_by_user_id' => max(0, (int) ($args['created_by_user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $ok = $wpdb->insert(self::workspace_table(), $row, ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { return self::err('ves_workspace_insert_failed', 'Could not create workspace.'); }
        return (int) $wpdb->insert_id;
    }

    /** @return int|WP_Error */
    public static function add_member(int $workspace_id, int $user_id, string $role, array $args = []) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return self::err('ves_member_no_db', 'Database unavailable.'); }
        if ($workspace_id <= 0 || $user_id <= 0) { return self::err('ves_member_bad_ids', 'Workspace and user are required.'); }
        $role = self::role($role);
        $now = self::now();
        $existing = self::get_member($workspace_id, $user_id, true);
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'role' => $role,
            'status' => self::member_status((string) ($args['status'] ?? 'active')),
            'capabilities_json' => self::json(self::normalize_capabilities($args['capabilities'] ?? [])),
            'invited_by_user_id' => max(0, (int) ($args['invited_by_user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0))),
            'updated_at' => $now,
        ];
        if (is_array($existing) && !empty($existing['id'])) {
            $updated = $wpdb->update(self::member_table(), $row, ['id' => (int) $existing['id']], ['%d','%d','%s','%s','%s','%d','%s'], ['%d']);
            return $updated === false ? self::err('ves_member_update_failed', 'Could not update workspace member.') : (int) $existing['id'];
        }
        $row['created_at'] = $now;
        $ok = $wpdb->insert(self::member_table(), $row, ['%d','%d','%s','%s','%s','%d','%s','%s']);
        if (!$ok) { return self::err('ves_member_insert_failed', 'Could not add workspace member.'); }
        return (int) $wpdb->insert_id;
    }

    public static function get_workspace(int $workspace_id): ?array {
        global $wpdb;
        if ($workspace_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::workspace_table() . ' WHERE id = %d LIMIT 1', $workspace_id), ARRAY_A);
        return is_array($row) ? self::decode_workspace($row) : null;
    }

    public static function get_member(int $workspace_id, int $user_id, bool $include_inactive = false): ?array {
        global $wpdb;
        if ($workspace_id <= 0 || $user_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $sql = 'SELECT * FROM ' . self::member_table() . ' WHERE workspace_id = %d AND user_id = %d';
        $args = [$workspace_id, $user_id];
        if (!$include_inactive) { $sql .= ' AND status = %s'; $args[] = 'active'; }
        $sql .= ' LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);
        return is_array($row) ? self::decode_member($row) : null;
    }

    public static function first_active_membership(int $user_id): ?array {
        global $wpdb;
        if ($user_id <= 0 || !isset($wpdb) || !is_object($wpdb)) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::member_table() . ' WHERE user_id = %d AND status = %s ORDER BY id ASC LIMIT 1', $user_id, 'active'), ARRAY_A);
        return is_array($row) ? self::decode_member($row) : null;
    }

    public static function capability_map_for_member(array $member): array {
        if (empty($member) || (string) ($member['status'] ?? '') !== 'active') { return self::empty_capabilities(); }
        $map = self::capabilities_for_role((string) ($member['role'] ?? 'viewer'));
        $custom = is_array($member['capabilities'] ?? null) ? $member['capabilities'] : [];
        foreach ($custom as $cap => $allowed) {
            $cap = self::clean_key((string) $cap, 80);
            if (in_array($cap, self::CAPABILITIES, true)) { $map[$cap] = (bool) $allowed; }
        }
        return $map;
    }

    public static function capabilities_for_role(string $role): array {
        $role = self::role($role);
        $all = array_fill_keys(self::CAPABILITIES, false);
        $sets = [
            'owner' => self::CAPABILITIES,
            'admin' => self::CAPABILITIES,
            'operator' => ['can_view_workspace','can_run_playbook','can_review_outputs','can_manage_sources','can_view_usage','can_record_outcome','can_manage_memory','can_export_reports'],
            'reviewer' => ['can_view_workspace','can_review_outputs','can_record_outcome','can_manage_memory','can_export_reports'],
            'viewer' => ['can_view_workspace','can_view_usage','can_export_reports'],
        ];
        foreach ($sets[$role] ?? [] as $cap) { $all[$cap] = true; }
        return $all;
    }

    public static function empty_capabilities(): array { return array_fill_keys(self::CAPABILITIES, false); }
    public static function role(string $role): string { $role = self::clean_key($role, 40); return in_array($role, self::ROLES, true) ? $role : 'viewer'; }

    private static function decode_workspace(array $row): array {
        foreach (['id','created_by_user_id'] as $k) { if (isset($row[$k])) { $row[$k] = (int) $row[$k]; } }
        foreach (['brand_profile_json' => 'brand_profile', 'settings_json' => 'settings'] as $col => $alias) { $d = json_decode((string) ($row[$col] ?? ''), true); $row[$alias] = is_array($d) ? $d : []; }
        return $row;
    }
    private static function decode_member(array $row): array {
        foreach (['id','workspace_id','user_id','invited_by_user_id'] as $k) { if (isset($row[$k])) { $row[$k] = (int) $row[$k]; } }
        $d = json_decode((string) ($row['capabilities_json'] ?? ''), true);
        $row['capabilities'] = is_array($d) ? $d : [];
        return $row;
    }
    private static function normalize_capabilities($caps): array {
        $out = [];
        foreach ((array) $caps as $cap => $value) { $cap = self::clean_key((string) $cap, 80); if (in_array($cap, self::CAPABILITIES, true)) { $out[$cap] = (bool) $value; } }
        return $out;
    }
    private static function status(string $status): string { $status = self::clean_key($status, 40); return in_array($status, ['active','archived','suspended'], true) ? $status : 'active'; }
    private static function member_status(string $status): string { $status = self::clean_key($status, 40); return in_array($status, ['active','inactive','invited','removed'], true) ? $status : 'active'; }
    private static function public_id(string $value): string { $value = preg_replace('/[^a-zA-Z0-9_\-]/', '', $value); return $value !== '' ? substr($value, 0, 64) : 'ws_' . substr(hash('sha256', uniqid('', true)), 0, 24); }
    private static function slug(string $value): string { $value = function_exists('sanitize_key') ? sanitize_key($value) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value)); return substr($value, 0, 191); }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function json(array $data): string { $json = function_exists('wp_json_encode') ? wp_json_encode($data, JSON_UNESCAPED_UNICODE) : json_encode($data); return is_string($json) ? $json : '{}'; }
    private static function now(): string { return function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
