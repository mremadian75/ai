<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Plugin {
    private static $booted = false;

    public static function boot() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [__CLASS__, 'register_components']);
        add_action('init', [__CLASS__, 'ensure_scheduled_tasks']);
        if (class_exists('VES_Queue')) { VES_Queue::init(); }
        if (class_exists('VES_Action_Scheduler_Jobs')) { VES_Action_Scheduler_Jobs::register(); }
        if (defined('WP_CLI') && WP_CLI && class_exists('VES_CLI_Trend_Backfill')) {
            VES_CLI_Trend_Backfill::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('VES_CLI_Schema')) {
            VES_CLI_Schema::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('VES_CLI_Trends')) {
            VES_CLI_Trends::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('VES_CLI_Brand_Context')) {
            VES_CLI_Brand_Context::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('VES_CLI_RC_Readiness')) {
            VES_CLI_RC_Readiness::register();
        }
        add_action('wp_enqueue_scripts', ['VES_Assets', 'register']);

        // Central, version-driven schema guard — forces a one-time dbDelta refresh
        // across all tables when the schema version changes, so a missing column can
        // never silently break inserts (the class of bug behind reserve failures).
        if (class_exists('VES_Migrations')) {
            VES_Migrations::register();
        }

        // Additive Intelligence Suite dashboard. Wrapped so a failure here can
        // never affect plugin boot or wp-admin.
        if (is_admin() && class_exists('VES_Admin_Console')) {
            try { VES_Admin_Console::init(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Admin_Console] init failed: ' . $e->getMessage()); }
            }
        }

        // v0.1 RC — read-only Release Candidate diagnostics page. Same isolation.
        if (is_admin() && class_exists('VES_Release_Candidate_Page')) {
            try { VES_Release_Candidate_Page::init(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Release_Candidate_Page] init failed: ' . $e->getMessage()); }
            }
        }

        if (is_admin() && class_exists('VES_Admin')) {
            VES_Admin::register();
        }
        if (is_admin() && class_exists('VES_Source_Intake')) {
            try { VES_Source_Intake::register(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Source_Intake] register failed: ' . $e->getMessage()); }
            }
        }
        if (is_admin() && class_exists('VES_Billing_Admin')) {
            VES_Billing_Admin::register();
        }

        if (class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::register_hooks();
        }
        if (class_exists('VES_Monitor_Runner')) {
            VES_Monitor_Runner::register_hooks();
        }
        if (class_exists('VES_Usage_Billing')) {
            VES_Usage_Billing::register_hooks();
        }
        if (class_exists('VES_Stripe_Billing')) {
            try { VES_Stripe_Billing::register(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Stripe_Billing] register failed: ' . $e->getMessage()); }
            }
        }
        if (class_exists('VES_Promo_Social_Credits')) {
            try { VES_Promo_Social_Credits::register_hooks(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Promo_Social_Credits] register failed: ' . $e->getMessage()); }
            }
        }
        if (class_exists('VES_Lead_Gate')) {
            try { VES_Lead_Gate::register(); } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[VES_Lead_Gate] register failed: ' . $e->getMessage()); }
            }
        }
        if (class_exists('VES_Creative_Intelligence')) {
            VES_Creative_Intelligence::register();
        }
        if (class_exists('VES_Market_Signal_Store')) {
            VES_Market_Signal_Store::register();
        }
        if (class_exists('VES_Market_Signal_Commercial')) {
            VES_Market_Signal_Commercial::register();
        }
        if (class_exists('VES_Intelligence_Map_Admin')) {
            VES_Intelligence_Map_Admin::init();
        }
        if (class_exists('VES_Auth')) {
            VES_Auth::register();
            if (is_admin()) {
                add_action('admin_init', ['VES_Auth', 'maybe_ensure_pages']);
            }
        }
    }

    public static function activate() {
        if (class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::create_table();
            VES_Temp_Memory::schedule_cleanup();
        }
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::create_table();
        }
        if (class_exists('VES_Trend_Insight_Store')) {
            VES_Trend_Insight_Store::create_table();
        }
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            VES_Brand_Audit_Run_Store::create_table();
        }
        if (class_exists('VES_Projects')) {
            VES_Projects::create_table(true);
        }
        if (class_exists('VES_Evidence_Store')) {
            VES_Evidence_Store::create_table(true);
        }
        if (class_exists('VES_Memory_Records')) {
            VES_Memory_Records::create_table(true);
        }
        if (class_exists('VES_Workspace_Profile')) {
            VES_Workspace_Profile::create_table(true);
        }
        if (class_exists('VES_Semantic_Index')) {
            VES_Semantic_Index::create_table(true);
        }
        if (class_exists('VES_Feedback_Learning')) {
            VES_Feedback_Learning::create_table(true);
        }
        if (class_exists('VES_Knowledge_Graph')) {
            VES_Knowledge_Graph::create_tables(true);
        }
        if (class_exists('VES_Knowledge_Consolidator')) {
            VES_Knowledge_Consolidator::create_table(true);
        }
        if (class_exists('VES_Assistant_Threads')) {
            VES_Assistant_Threads::create_tables(true);
        }
        if (class_exists('VES_Metric_Snapshots')) {
            VES_Metric_Snapshots::create_table(true);
        }
        if (class_exists('VES_Pattern_Candidates')) {
            VES_Pattern_Candidates::create_table(true);
        }
        if (class_exists('VES_Insight_Records')) {
            VES_Insight_Records::create_table(true);
        }
        if (class_exists('VES_Opportunity_Records')) {
            VES_Opportunity_Records::create_table(true);
        }
        if (class_exists('VES_Brief_Records')) {
            VES_Brief_Records::create_table(true);
        }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::create_table(true);
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::create_table();
        }
        if (class_exists('VES_AI_Usage_Tracker')) {
            VES_AI_Usage_Tracker::create_table();
        }
        if (class_exists('VES_Intelligence_Store')) {
            VES_Intelligence_Store::create_tables();
        }
        if (class_exists("VES_Trend_Observation_Store")) {
            VES_Trend_Observation_Store::create_table();
        }
        if (class_exists("VES_Trend_Record_Store")) {
            VES_Trend_Record_Store::create_table();
        }
        if (class_exists('VES_Usage_Billing')) {
            VES_Usage_Billing::activate();
        }
        if (class_exists('VES_Monitor_Store')) {
            VES_Monitor_Store::create_table();
        }
        if (class_exists('VES_Monitor_Runner')) {
            VES_Monitor_Runner::activate();
        }
        if (class_exists('VES_Creative_Intelligence')) {
            VES_Creative_Intelligence::activate();
        }
        if (class_exists('VES_Market_Signal_Store')) {
            VES_Market_Signal_Store::create_tables(true);
        }
        if (class_exists('VES_Market_Signal_Commercial')) {
            VES_Market_Signal_Commercial::create_tables(true);
        }
        if (class_exists('VES_Market_Signal_Interface')) {
            VES_Market_Signal_Interface::ensure_frontend_page();
        }
        if (class_exists('VES_Auth')) {
            VES_Auth::activate();
        }
    }

    public static function deactivate() {
        if (class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::clear_schedule();
        }
        if (class_exists('VES_Monitor_Runner')) {
            VES_Monitor_Runner::deactivate();
        }
    }

    public static function ensure_scheduled_tasks() {
        if (class_exists('VES_Temp_Memory')) {
            VES_Temp_Memory::create_table();
            VES_Temp_Memory::schedule_cleanup();
        }
        if (class_exists('VES_Usage_Billing')) {
            VES_Usage_Billing::ensure_setup();
        }
        if (class_exists('VES_Monitor_Store')) {
            VES_Monitor_Store::create_table();
        }
        if (class_exists('VES_Monitor_Runner')) {
            VES_Monitor_Runner::ensure_schedules();
        }
        if (class_exists('VES_Run_Log_Service')) {
            VES_Run_Log_Service::create_table();
        }
        if (class_exists('VES_AI_Usage_Tracker')) {
            VES_AI_Usage_Tracker::create_table();
        }
        if (class_exists('VES_Intelligence_Store')) {
            VES_Intelligence_Store::create_tables();
        }
        if (class_exists("VES_Trend_Observation_Store")) {
            VES_Trend_Observation_Store::create_table();
        }
        if (class_exists("VES_Trend_Record_Store")) {
            VES_Trend_Record_Store::create_table();
        }
        if (class_exists('VES_Trend_Run_Store')) {
            VES_Trend_Run_Store::create_table();
        }
        if (class_exists('VES_Trend_Insight_Store')) {
            VES_Trend_Insight_Store::create_table();
        }
        if (class_exists('VES_Brand_Audit_Run_Store')) {
            VES_Brand_Audit_Run_Store::create_table();
        }
        if (class_exists('VES_Projects')) {
            VES_Projects::create_table();
        }
        if (class_exists('VES_Evidence_Store')) {
            VES_Evidence_Store::create_table();
        }
        if (class_exists('VES_Memory_Records')) {
            VES_Memory_Records::create_table();
        }
        if (class_exists('VES_Workspace_Profile')) {
            VES_Workspace_Profile::create_table();
        }
        if (class_exists('VES_Semantic_Index')) {
            VES_Semantic_Index::create_table();
        }
        if (class_exists('VES_Feedback_Learning')) {
            VES_Feedback_Learning::create_table();
        }
        if (class_exists('VES_Knowledge_Graph')) {
            VES_Knowledge_Graph::create_tables();
        }
        if (class_exists('VES_Knowledge_Consolidator')) {
            VES_Knowledge_Consolidator::create_table();
        }
        if (class_exists('VES_Assistant_Threads')) {
            VES_Assistant_Threads::create_tables();
        }
        if (class_exists('VES_Metric_Snapshots')) {
            VES_Metric_Snapshots::create_table();
        }
        if (class_exists('VES_Pattern_Candidates')) {
            VES_Pattern_Candidates::create_table();
        }
        if (class_exists('VES_Insight_Records')) {
            VES_Insight_Records::create_table();
        }
        if (class_exists('VES_Opportunity_Records')) {
            VES_Opportunity_Records::create_table();
        }
        if (class_exists('VES_Brief_Records')) {
            VES_Brief_Records::create_table();
        }
        if (class_exists('VES_Workflow_Events')) {
            VES_Workflow_Events::create_table();
        }
        if (class_exists('VES_Creative_Intelligence')) {
            VES_Creative_Intelligence::ensure_setup();
        }
        if (class_exists('VES_Market_Signal_Store')) {
            VES_Market_Signal_Store::create_tables();
        }
        if (class_exists('VES_Queue')) {
            VES_Queue::schedule_recurring('ves_as_cleanup_expired_memory_job', 'daily');
            VES_Queue::schedule_recurring('ves_as_reconcile_pending_usage_job', 'ves_five_minutes');
            VES_Queue::schedule_recurring('ves_as_release_stale_locks_job', 'hourly');
            VES_Queue::schedule_recurring('ves_as_knowledge_consolidation_job', 'daily');
        }
        if (class_exists('VES_Auth')) {
            VES_Auth::maybe_ensure_pages();
        }
    }

    public static function register_components() {
        VES_Shortcode::register();
        if (class_exists('VES_Market_Signal_Interface')) {
            VES_Market_Signal_Interface::register();
        }
        VES_Ajax_Controller::register();
    }
}
