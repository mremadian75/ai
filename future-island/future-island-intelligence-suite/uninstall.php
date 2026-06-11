<?php
/**
 * Unified uninstall for Future Island Intelligence Suite.
 *
 * Data retention is the default for all modules (VES Core + Deep Trend Finder).
 * Destructive cleanup only runs when the administrator explicitly enables
 * ves_delete_data_on_uninstall in plugin settings.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$settings    = get_option('ves_settings', []);
$delete_data = !empty($settings['delete_data_on_uninstall'])
               || (bool) get_option('ves_delete_data_on_uninstall', false);

// Always remove short-lived operational options that do not contain customer data.
delete_option('ves_last_diagnostic');
delete_option('fidtf_page_shortcode_missing');

if (!$delete_data) {
    return;
}

global $wpdb;

// VES Core tables
$ves_tables = [
    'ves_temp_memory', 'ves_brand_audit_runs', 'ves_evidence_items',
    'ves_memory_records', 'ves_semantic_index', 'ves_feedback_events',
    'ves_assistant_threads', 'ves_assistant_messages', 'ves_metric_snapshots',
    'ves_pattern_candidates', 'ves_insight_records', 'ves_opportunity_records',
    'ves_brief_records', 'ves_workflow_events', 'ves_usage_events',
    'ves_run_logs', 'ves_trend_runs', 'ves_trend_insights',
    'ves_workspace_knowledge', 'ves_market_signal_objects',
    'ves_market_signal_events', 'ves_ms_workspaces', 'ves_ms_sources',
    'ves_ms_runs', 'ves_ms_signals', 'ves_ms_insights',
    'ves_ms_briefs', 'ves_ms_drafts', 'ves_ms_audit_log',
    // Phases 2-9 (intelligence contract, trend engine, rails, audit ledger)
    'ves_intel_sources', 'ves_intel_signals', 'ves_intel_evidences',
    'ves_intel_insights', 'ves_intel_briefs', 'ves_intel_drafts',
    'ves_intel_memory', 'ves_trend_observations', 'ves_trend_records',
    'ves_review_decisions', 'ves_ai_usage_events', 'ves_topup_requests',
];

// Deep Trend Finder tables
$fidtf_tables = [
    'fi_dtf_runs', 'fi_dtf_source_jobs', 'fi_dtf_items', 'fi_dtf_reports',
];

foreach (array_merge($ves_tables, $fidtf_tables) as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// VES Core options
$ves_options = [
    'ves_settings', 'ves_delete_data_on_uninstall', 'ves_diagnostic_log',
    'ves_evidence_store_db_version', 'ves_memory_records_db_version',
    'ves_semantic_index_db_version', 'ves_feedback_learning_db_version',
    'ves_assistant_threads_db_version', 'ves_metric_snapshots_db_version',
    'ves_pattern_candidates_db_version', 'ves_insight_records_db_version',
    'ves_opportunity_records_db_version', 'ves_brief_records_db_version',
    'ves_workflow_events_db_version', 'ves_market_signal_store_db_version',
    'ves_market_signal_commercial_db_version', 'ves_market_signal_commercial_settings',
    // Phases 2-9 options (rails, ledgers, evidence, egress) — wiped on explicit
    // delete so no validation/audit state survives a "remove my data" uninstall.
    'ves_trend_observations_db_version', 'ves_review_decisions_db_version',
    'ves_trend_backfill_done', 'ves_trend_backfill_last',
    'ves_rc_live_validation', 'ves_security_event_log',
    'ves_job_retry_counts', 'ves_job_dead_letter',
    'ves_usage_settlement_required', 'ves_apify_actor_allowlist_extra',
    'ves_apify_actor_registry_overrides', 'ves_apify_active_slots',
    'ves_generation_execution_enabled',
];

// Deep Trend Finder options
$fidtf_options = [
    'fidtf_settings', 'fidtf_db_version', 'fidtf_page_id', 'fidtf_page_slug',
];

foreach (array_merge($ves_options, $fidtf_options) as $option) {
    delete_option($option);
}
