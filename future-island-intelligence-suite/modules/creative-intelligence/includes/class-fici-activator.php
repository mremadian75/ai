<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FICI_Activator {
    public static function activate(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $runs_table      = $wpdb->prefix . 'fici_analysis_runs';
        $assets_table    = $wpdb->prefix . 'fici_assets';

        $sql_runs = "CREATE TABLE {$runs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            core_run_id BIGINT UNSIGNED NULL,
            workspace_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'created',
            analysis_mode VARCHAR(60) NOT NULL DEFAULT 'standard',
            target_platform VARCHAR(80) NULL,
            provider VARCHAR(40) NOT NULL DEFAULT 'openai',
            model VARCHAR(120) NULL,
            input_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            usage_json LONGTEXT NULL,
            core_links_json LONGTEXT NULL,
            error_code VARCHAR(120) NULL,
            error_message TEXT NULL,
            credit_reservation_id VARCHAR(160) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY workspace_id (workspace_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_assets = "CREATE TABLE {$assets_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workspace_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL,
            asset_type VARCHAR(40) NOT NULL DEFAULT 'unknown',
            source_type VARCHAR(40) NOT NULL DEFAULT 'upload',
            file_path TEXT NULL,
            file_url TEXT NULL,
            source_url TEXT NULL,
            mime_type VARCHAR(160) NULL,
            file_size BIGINT UNSIGNED NULL,
            duration_seconds INT NULL,
            metadata_json LONGTEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'uploaded',
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY workspace_id (workspace_id),
            KEY asset_type (asset_type),
            KEY status (status),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        dbDelta( $sql_runs );
        dbDelta( $sql_assets );

        update_option( 'fici_version', FICI_VERSION );
    }

    public static function maybe_upgrade(): void {
        $stored_version = (string) get_option( 'fici_version', '' );
        if ( FICI_VERSION !== $stored_version ) {
            self::activate();
        }
    }
}
