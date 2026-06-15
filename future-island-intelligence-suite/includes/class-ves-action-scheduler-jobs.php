<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Action_Scheduler_Jobs {
    public static function register() {
        add_action('ves_as_run_source_job', [__CLASS__, 'handle_run_source_job'], 10, 1);
        add_action('ves_as_collect_source_result_job', [__CLASS__, 'handle_collect_source_result_job'], 10, 1);
        add_action('ves_as_finalize_run_job', [__CLASS__, 'handle_finalize_run_job'], 10, 1);
        add_action('ves_as_brand_audit_source_job', [__CLASS__, 'handle_brand_audit_source_job'], 10, 1);
        add_action('ves_as_brand_audit_collect_job', [__CLASS__, 'handle_brand_audit_collect_job'], 10, 1);
        add_action('ves_as_brand_audit_finalize_job', [__CLASS__, 'handle_brand_audit_finalize_job'], 10, 1);
        add_action('ves_as_cleanup_expired_memory_job', [__CLASS__, 'handle_cleanup_expired_memory_job'], 10, 0);
        add_action('ves_as_reconcile_pending_usage_job', [__CLASS__, 'handle_reconcile_pending_usage_job'], 10, 0);
        add_action('ves_as_release_stale_locks_job', [__CLASS__, 'handle_release_stale_locks_job'], 10, 0);
        add_action('ves_as_knowledge_consolidation_job', [__CLASS__, 'handle_knowledge_consolidation_job'], 10, 1);
    }

    private static function fail_payload($payload, $job_type, $message, $context = []) {
        $payload = is_array($payload) ? $payload : [];
        $bundle_id = sanitize_text_field((string) ($payload['bundle_id'] ?? ''));
        $run_id = (int) ($payload['run_id'] ?? 0);
        if (strpos((string) $job_type, 'brand_audit_') === 0 && class_exists('VES_Brand_Audit_Execution_Service')) {
            if ($bundle_id !== '') {
                VES_Brand_Audit_Execution_Service::mark_failed($bundle_id, $message, array_merge($context, ['job_type' => $job_type]));
            } elseif ($run_id > 0) {
                VES_Brand_Audit_Execution_Service::mark_failed_by_id($run_id, $message, array_merge($context, ['job_type' => $job_type]));
            }
            return;
        }
        if (class_exists('VES_Run_Execution_Service')) {
            if ($bundle_id !== '') {
                VES_Run_Execution_Service::mark_failed($bundle_id, $message, array_merge($context, ['job_type' => $job_type]));
            } elseif ($run_id > 0) {
                VES_Run_Execution_Service::mark_failed_by_id($run_id, $message, array_merge($context, ['job_type' => $job_type]));
            }
        }
    }

    private static function with_lock($payload, $job_type, $callback) {
        $run_id = is_array($payload) ? (int) ($payload['run_id'] ?? 0) : 0;
        $source_key = is_array($payload) ? sanitize_key((string) ($payload['source_key'] ?? '')) : '';
        $lock_key = $job_type;
        if ($source_key !== '' && in_array($job_type, ['run_source', 'collect_source_result', 'brand_audit_source', 'brand_audit_collect'], true)) {
            $lock_key .= '_' . $source_key;
        }

        // Phase 9A.5 — retry/dead-letter rails. A dead-lettered job key is never
        // executed again until an operator explicitly clears it.
        $rails = class_exists('VES_Job_Rails');
        $job_key = $rails ? VES_Job_Rails::job_key($job_type, is_array($payload) ? $payload : []) : '';
        if ($rails && VES_Job_Rails::is_dead($job_key)) {
            if ($run_id > 0 && class_exists('VES_Run_Log_Service')) {
                VES_Run_Log_Service::warn($run_id, 'job_rails', 'Job skipped: dead-lettered after max retries.', ['job_type' => $job_type, 'job_key' => $job_key]);
            }
            return;
        }

        if ($run_id <= 0) {
            try {
                call_user_func($callback, $payload);
                if ($rails) { VES_Job_Rails::record_success($job_key); }
            } catch (Throwable $e) {
                if ($rails) { VES_Job_Rails::record_failure($job_key, $job_type, $e->getMessage(), ['run_id' => 0]); }
                self::fail_payload($payload, $job_type, $e->getMessage(), ['run_id' => 0, 'source_key' => $source_key]);
            }
            return;
        }
        if (!VES_Run_Lock_Manager::acquire($run_id, $lock_key, 900)) {
            VES_Run_Log_Service::warn($run_id, 'scheduler', 'Job skipped because lock is active.', ['job_type' => $job_type, 'lock_key' => $lock_key, 'source_key' => $source_key]);
            return;
        }
        try {
            call_user_func($callback, $payload);
            if ($rails) { VES_Job_Rails::record_success($job_key); }
        } catch (Throwable $e) {
            VES_Run_Log_Service::error($run_id, 'scheduler', 'Unhandled background exception.', ['job_type' => $job_type, 'lock_key' => $lock_key, 'source_key' => $source_key, 'message' => $e->getMessage()]);
            if ($rails) { VES_Job_Rails::record_failure($job_key, $job_type, $e->getMessage(), ['run_id' => $run_id]); }
            self::fail_payload($payload, $job_type, $e->getMessage(), ['run_id' => $run_id, 'lock_key' => $lock_key, 'source_key' => $source_key]);
        }
        VES_Run_Lock_Manager::release($run_id, $lock_key);
    }

    public static function handle_run_source_job($payload) {
        self::with_lock($payload, 'run_source', [ 'VES_Run_Execution_Service', 'run_source_job' ]);
    }

    public static function handle_collect_source_result_job($payload) {
        self::with_lock($payload, 'collect_source_result', [ 'VES_Run_Execution_Service', 'collect_source_result_job' ]);
    }

    public static function handle_finalize_run_job($payload) {
        self::with_lock($payload, 'finalize_run', [ 'VES_Run_Execution_Service', 'finalize_run_job' ]);
    }

    public static function handle_brand_audit_source_job($payload) {
        self::with_lock($payload, 'brand_audit_source', [ 'VES_Brand_Audit_Execution_Service', 'run_source_job' ]);
    }

    public static function handle_brand_audit_collect_job($payload) {
        self::with_lock($payload, 'brand_audit_collect', [ 'VES_Brand_Audit_Execution_Service', 'collect_source_result_job' ]);
    }

    public static function handle_brand_audit_finalize_job($payload) {
        self::with_lock($payload, 'brand_audit_finalize', [ 'VES_Brand_Audit_Execution_Service', 'finalize_run_job' ]);
    }

    public static function handle_cleanup_expired_memory_job() {
        if (class_exists('VES_Memory_Cleanup_Service')) {
            VES_Memory_Cleanup_Service::cleanup_expired_memory();
        } elseif (class_exists('VES_Temp_Memory') && method_exists('VES_Temp_Memory', 'cleanup_expired')) {
            VES_Temp_Memory::cleanup_expired();
        }
    }

    public static function handle_reconcile_pending_usage_job() {
        if (class_exists('VES_Usage_Reconciliation_Service')) {
            VES_Usage_Reconciliation_Service::reconcile_pending();
        }
    }

    public static function handle_release_stale_locks_job() {
        VES_Run_Lock_Manager::release_stale_locks();
    }

    public static function handle_knowledge_consolidation_job($payload = []) {
        $settings = class_exists('VES_Config') ? VES_Config::get_settings() : [];
        if (array_key_exists('knowledge_consolidation_enabled', $settings) && empty($settings['knowledge_consolidation_enabled'])) {
            return;
        }
        if (class_exists('VES_Knowledge_Consolidator')) {
            VES_Knowledge_Consolidator::run_scheduled_consolidation(is_array($payload) ? $payload : []);
        }
    }
}
