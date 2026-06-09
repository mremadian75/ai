<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Durable company/workspace knowledge profile.
 *
 * This is intentionally structured storage, not model training. It gives the AI
 * a bounded, admin-reviewable company memory layer that can be retrieved before
 * generation and updated over time without exposing raw payloads.
 */
final class VES_Workspace_Profile {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'ves_workspace_profiles_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ves_workspace_profiles';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function required_columns() {
        return [
            'id','workspace_id','user_id','company_name','market_country','industry','brand_positioning',
            'products_services_json','competitors_json','audience_segments_json','known_strengths_json',
            'known_risks_json','known_opportunities_json','tone_style_guidance','approved_learnings_json',
            'rejected_assumptions_json','source_refs_json','created_at','updated_at'
        ];
    }

    public static function existing_columns() {
        global $wpdb;
        if (!self::table_exists()) { return []; }
        $columns = $wpdb->get_col('DESC ' . self::table_name(), 0);
        return is_array($columns) ? array_map('strval', $columns) : [];
    }

    public static function schema_is_current() {
        return get_option(self::OPTION_KEY) === self::DB_VERSION && self::table_exists() && empty(array_diff(self::required_columns(), self::existing_columns()));
    }

    public static function schema_health_check() {
        return [
            'table' => self::table_name(),
            'exists' => self::table_exists(),
            'db_version' => get_option(self::OPTION_KEY),
            'expected_version' => self::DB_VERSION,
            'missing_columns' => array_values(array_diff(self::required_columns(), self::existing_columns())),
            'is_current' => self::schema_is_current(),
        ];
    }

    public static function create_table($force = false) {
        if (!$force && self::schema_is_current()) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            company_name varchar(190) NOT NULL DEFAULT '',
            market_country varchar(120) NOT NULL DEFAULT '',
            industry varchar(160) NOT NULL DEFAULT '',
            brand_positioning longtext NULL,
            products_services_json longtext NULL,
            competitors_json longtext NULL,
            audience_segments_json longtext NULL,
            known_strengths_json longtext NULL,
            known_risks_json longtext NULL,
            known_opportunities_json longtext NULL,
            tone_style_guidance longtext NULL,
            approved_learnings_json longtext NULL,
            rejected_assumptions_json longtext NULL,
            source_refs_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY company_name (company_name),
            KEY updated_at (updated_at)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function get_profile($workspace_id) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        if ($workspace_id <= 0) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE workspace_id = %d LIMIT 1', $workspace_id), ARRAY_A);
        return is_array($row) ? self::prepare_profile($row) : null;
    }

    public static function upsert_profile($workspace_id, $user_id, $profile = []) {
        global $wpdb;
        self::create_table();
        $workspace_id = max(0, (int) $workspace_id);
        $user_id = max(0, (int) $user_id);
        if ($workspace_id <= 0) { return new WP_Error('invalid_workspace', 'Workspace inválido.'); }
        $profile = self::sanitize_profile($profile);
        $now = current_time('mysql');
        $row = [
            'workspace_id' => $workspace_id,
            'user_id' => $user_id,
            'company_name' => $profile['company_name'],
            'market_country' => $profile['market_country'],
            'industry' => $profile['industry'],
            'brand_positioning' => $profile['brand_positioning'],
            'products_services_json' => wp_json_encode($profile['known_products_services'], JSON_UNESCAPED_UNICODE),
            'competitors_json' => wp_json_encode($profile['competitors'], JSON_UNESCAPED_UNICODE),
            'audience_segments_json' => wp_json_encode($profile['audience_segments'], JSON_UNESCAPED_UNICODE),
            'known_strengths_json' => wp_json_encode($profile['known_strengths'], JSON_UNESCAPED_UNICODE),
            'known_risks_json' => wp_json_encode($profile['known_risks'], JSON_UNESCAPED_UNICODE),
            'known_opportunities_json' => wp_json_encode($profile['known_opportunities'], JSON_UNESCAPED_UNICODE),
            'tone_style_guidance' => $profile['tone_style_guidance'],
            'approved_learnings_json' => wp_json_encode($profile['approved_strategic_learnings'], JSON_UNESCAPED_UNICODE),
            'rejected_assumptions_json' => wp_json_encode($profile['rejected_outdated_assumptions'], JSON_UNESCAPED_UNICODE),
            'source_refs_json' => wp_json_encode($profile['source_refs'], JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        $existing = self::get_profile($workspace_id);
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];
        if ($existing) {
            $updated = $wpdb->update(self::table_name(), $row, ['workspace_id' => $workspace_id], $formats, ['%d']);
            return false === $updated ? new WP_Error('db_error', 'No se pudo actualizar el perfil.') : self::get_profile($workspace_id);
        }
        $row['created_at'] = $now;
        $inserted = $wpdb->insert(self::table_name(), $row, array_merge($formats, ['%s']));
        return false === $inserted ? new WP_Error('db_error', 'No se pudo crear el perfil.') : self::get_profile($workspace_id);
    }

    public static function upsert_from_project_context($workspace_id, $user_id, $context = [], $source_ref = '') {
        $context = is_array($context) ? $context : [];
        $existing = self::get_profile($workspace_id);
        $base = is_array($existing) ? $existing : [];
        $source_refs = is_array($base['source_refs'] ?? null) ? $base['source_refs'] : [];
        if ($source_ref !== '') { $source_refs[] = sanitize_text_field((string) $source_ref); }
        $profile = array_merge($base, [
            'company_name' => $context['brand_name'] ?? ($base['company_name'] ?? ''),
            'market_country' => $context['markets'] ?? ($base['market_country'] ?? ''),
            'industry' => $context['industry'] ?? ($base['industry'] ?? ''),
            'brand_positioning' => $context['brand_summary'] ?? ($base['brand_positioning'] ?? ''),
            'known_products_services' => self::string_list($context['products_services'] ?? ($base['known_products_services'] ?? [])),
            'competitors' => self::string_list($context['competitors'] ?? ($base['competitors'] ?? [])),
            'audience_segments' => self::string_list($context['target_audience'] ?? ($base['audience_segments'] ?? [])),
            'tone_style_guidance' => $context['tone_of_voice'] ?? ($base['tone_style_guidance'] ?? ''),
            'approved_strategic_learnings' => self::string_list($context['notes'] ?? ($base['approved_strategic_learnings'] ?? [])),
            'source_refs' => array_values(array_unique(array_filter($source_refs))),
        ]);
        return self::upsert_profile($workspace_id, $user_id, $profile);
    }

    public static function add_rejected_assumption($workspace_id, $user_id, $assumption, $source_ref = '') {
        $profile = self::get_profile($workspace_id);
        $profile = is_array($profile) ? $profile : [];
        $items = is_array($profile['rejected_outdated_assumptions'] ?? null) ? $profile['rejected_outdated_assumptions'] : [];
        $assumption = sanitize_textarea_field((string) $assumption);
        if ($assumption !== '') { $items[] = $assumption; }
        $profile['rejected_outdated_assumptions'] = array_values(array_unique(array_filter($items)));
        if ($source_ref !== '') {
            $refs = is_array($profile['source_refs'] ?? null) ? $profile['source_refs'] : [];
            $refs[] = sanitize_text_field((string) $source_ref);
            $profile['source_refs'] = array_values(array_unique(array_filter($refs)));
        }
        return self::upsert_profile($workspace_id, $user_id, $profile);
    }

    public static function sanitize_profile($profile) {
        $profile = is_array($profile) ? $profile : [];
        return [
            'company_name' => self::text($profile['company_name'] ?? $profile['brand_name'] ?? '', 190),
            'market_country' => self::text($profile['market_country'] ?? $profile['markets'] ?? $profile['country'] ?? '', 120),
            'industry' => self::text($profile['industry'] ?? '', 160),
            'brand_positioning' => self::textarea($profile['brand_positioning'] ?? $profile['brand_summary'] ?? '', 3000),
            'known_products_services' => self::string_list($profile['known_products_services'] ?? $profile['products_services'] ?? []),
            'competitors' => self::string_list($profile['competitors'] ?? []),
            'audience_segments' => self::string_list($profile['audience_segments'] ?? $profile['target_audience'] ?? []),
            'known_strengths' => self::string_list($profile['known_strengths'] ?? []),
            'known_risks' => self::string_list($profile['known_risks'] ?? []),
            'known_opportunities' => self::string_list($profile['known_opportunities'] ?? []),
            'tone_style_guidance' => self::textarea($profile['tone_style_guidance'] ?? $profile['tone_of_voice'] ?? '', 2000),
            'approved_strategic_learnings' => self::string_list($profile['approved_strategic_learnings'] ?? $profile['approved_learnings'] ?? []),
            'rejected_outdated_assumptions' => self::string_list($profile['rejected_outdated_assumptions'] ?? $profile['rejected_assumptions'] ?? []),
            'source_refs' => self::string_list($profile['source_refs'] ?? [], 80, 180),
        ];
    }

    private static function prepare_profile($row) {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'market_country' => (string) ($row['market_country'] ?? ''),
            'industry' => (string) ($row['industry'] ?? ''),
            'brand_positioning' => (string) ($row['brand_positioning'] ?? ''),
            'known_products_services' => self::decode_list($row['products_services_json'] ?? '[]'),
            'competitors' => self::decode_list($row['competitors_json'] ?? '[]'),
            'audience_segments' => self::decode_list($row['audience_segments_json'] ?? '[]'),
            'known_strengths' => self::decode_list($row['known_strengths_json'] ?? '[]'),
            'known_risks' => self::decode_list($row['known_risks_json'] ?? '[]'),
            'known_opportunities' => self::decode_list($row['known_opportunities_json'] ?? '[]'),
            'tone_style_guidance' => (string) ($row['tone_style_guidance'] ?? ''),
            'approved_strategic_learnings' => self::decode_list($row['approved_learnings_json'] ?? '[]'),
            'rejected_outdated_assumptions' => self::decode_list($row['rejected_assumptions_json'] ?? '[]'),
            'source_refs' => self::decode_list($row['source_refs_json'] ?? '[]'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private static function decode_list($json) {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private static function string_list($items, $limit = 40, $max_len = 220) {
        if (is_string($items)) { $items = preg_split('/[\n,;]+/', $items); }
        if (!is_array($items)) { return []; }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) { $item = $item['title'] ?? $item['summary'] ?? $item['name'] ?? ''; }
            if (!is_scalar($item)) { continue; }
            $item = self::textarea((string) $item, $max_len);
            if ($item !== '') { $out[] = $item; }
        }
        return array_values(array_unique(array_slice($out, 0, max(1, (int) $limit))));
    }

    private static function text($value, $max = 190) {
        $value = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function textarea($value, $max = 2000) {
        $value = sanitize_textarea_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
