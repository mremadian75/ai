<?php
if (!defined('ABSPATH')) { exit; }

final class FIDTF_DB {
    public static function table(string $key): string {
        global $wpdb;
        $map = [
            'runs' => 'fi_dtf_runs',
            'source_jobs' => 'fi_dtf_source_jobs',
            'items' => 'fi_dtf_items',
            'reports' => 'fi_dtf_reports',
        ];
        return $wpdb->prefix . ($map[$key] ?? $key);
    }

    public static function schema_sql(): array {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        return [
            "CREATE TABLE " . self::table('runs') . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                workspace_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'created',
                user_brief LONGTEXT NULL,
                objective TEXT NULL,
                market VARCHAR(80) NULL,
                language VARCHAR(20) NULL,
                date_range VARCHAR(40) NULL,
                channels_json LONGTEXT NULL,
                keywords_json LONGTEXT NULL,
                request_meta_json LONGTEXT NULL,
                ai_planner_output_json LONGTEXT NULL,
                credit_estimate DECIMAL(12,2) NOT NULL DEFAULT 0,
                credits_reserved DECIMAL(12,2) NOT NULL DEFAULT 0,
                final_credits_settled DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY created_at (created_at)
            ) {$charset};",
            "CREATE TABLE " . self::table('source_jobs') . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                source_key VARCHAR(60) NOT NULL,
                provider VARCHAR(60) NULL,
                actor_id VARCHAR(160) NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'queued',
                query_payload_json LONGTEXT NULL,
                provider_run_id VARCHAR(160) NULL,
                raw_count INT UNSIGNED NOT NULL DEFAULT 0,
                provider_dataset_rows INT UNSIGNED NOT NULL DEFAULT 0,
                provider_dataset_total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                flattened_raw_items INT UNSIGNED NOT NULL DEFAULT 0,
                normalized_count INT UNSIGNED NOT NULL DEFAULT 0,
                relevant_count INT UNSIGNED NOT NULL DEFAULT 0,
                diagnostics_json LONGTEXT NULL,
                error_code VARCHAR(120) NULL,
                retryable TINYINT(1) NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY run_id (run_id),
                KEY source_status (source_key, status)
            ) {$charset};",
            "CREATE TABLE " . self::table('items') . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                source_job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_key VARCHAR(60) NOT NULL,
                external_id VARCHAR(191) NULL,
                url TEXT NULL,
                author TEXT NULL,
                title TEXT NULL,
                caption_or_text LONGTEXT NULL,
                published_at DATETIME NULL,
                metrics_json LONGTEXT NULL,
                media_json LONGTEXT NULL,
                transcript_json LONGTEXT NULL,
                comments_sample_json LONGTEXT NULL,
                raw_json LONGTEXT NULL,
                normalized_json LONGTEXT NULL,
                relevance_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                relevance_reason TEXT NULL,
                evidence_quality_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY run_id (run_id),
                KEY source_job_id (source_job_id),
                KEY relevance_score (relevance_score)
            ) {$charset};",
            "CREATE TABLE " . self::table('reports') . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                report_type VARCHAR(60) NOT NULL DEFAULT 'final_synthesis',
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                source_summary_json LONGTEXT NULL,
                final_synthesis_json LONGTEXT NULL,
                html_snapshot LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY run_id (run_id),
                KEY status (status)
            ) {$charset};",
        ];
    }

    public static function create_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (self::schema_sql() as $sql) {
            dbDelta($sql);
        }
        update_option('fidtf_db_version', FIDTF_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if (!function_exists('get_option') || !function_exists('update_option')) { return; }
        $installed = (string) get_option('fidtf_db_version', '');
        if ($installed === FIDTF_VERSION) { return; }
        self::create_tables();
    }
}
