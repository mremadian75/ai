<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Health_Check
{
    public static function diagnostics()
    {
        $settings = FBAIA_Helpers::get_settings();
        $adapter = new FBAIA_FluentBoards_Adapter();
        $logs = FBAIA_Logger::all();
        $recent_errors = array_values(array_filter($logs, function ($log) {
            return in_array($log['level'] ?? '', ['error', 'warning'], true);
        }));
        $knowledge_counts = post_type_exists(FBAIA_Knowledge_Library::CPT) ? wp_count_posts(FBAIA_Knowledge_Library::CPT) : null;
        $knowledge_count = $knowledge_counts && isset($knowledge_counts->publish) ? (int) $knowledge_counts->publish : 0;
        $suggestion_stats = class_exists('FBAIA_Suggestion_Store') ? FBAIA_Suggestion_Store::stats() : [];
        $audit_stats = class_exists('FBAIA_Audit_Trail') ? FBAIA_Audit_Trail::stats() : [];
        $usage = class_exists('FBAIA_Usage_Tracker') ? FBAIA_Usage_Tracker::snapshot() : [];
        $playbooks = class_exists('FBAIA_Automation_Playbooks') ? (new FBAIA_Automation_Playbooks($settings))->parse((string) ($settings['automation_playbooks'] ?? '')) : [];
        $context_builder = class_exists('FBAIA_Context_Builder') ? new FBAIA_Context_Builder($settings) : null;
        $team_members = $context_builder ? $context_builder->parse_team_directory((string) ($settings['team_directory'] ?? '')) : [];
        $checks = [];

        self::add_check($checks, 'WordPress version', version_compare(get_bloginfo('version'), '6.2', '>='), 'WordPress 6.2 or newer is recommended.', 'warning');
        self::add_check($checks, 'PHP version', version_compare(PHP_VERSION, '7.4', '>='), 'PHP 7.4 or newer is required for this plugin.');
        self::add_check($checks, 'Plugin enabled', ($settings['enabled'] ?? 'no') === 'yes', 'Engine is disabled (safe mode). Enable it on the Automations tab when ready.', 'warning');
        self::add_check($checks, 'Fluent Boards detected', $adapter->is_available(), 'Fluent Boards was not detected; hooks/write-back may not work.');
        self::add_check($checks, 'Fluent Boards Task model', class_exists('FluentBoards\\App\\Models\\Task'), 'Fluent Boards Task model is unavailable; task reads and subtask creation will not work.', 'warning');
        self::add_check($checks, 'Fluent Boards Comment model', class_exists('FluentBoards\\App\\Models\\Comment'), 'Fluent Boards Comment model is unavailable; AI comments cannot be written back.', 'warning');
        self::add_check($checks, 'Options writable', self::options_writable(), 'WordPress could not write to the options table; settings, logs, and the review queue will not persist.');
        self::add_check($checks, 'API key configured', !empty($settings['api_key']), 'OpenAI-compatible API key is missing.');
        self::add_check($checks, 'Action Scheduler available', function_exists('as_enqueue_async_action'), 'Action Scheduler is missing; plugin will fall back to WP-Cron.');
        self::add_check($checks, 'WP-Cron available', !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON), 'DISABLE_WP_CRON is true; scheduled scans/digests need a real server cron.');
        self::add_check($checks, 'Company memory exists', self::has_company_memory($settings) || $knowledge_count > 0, 'Company memory is empty; recommendations will stay generic.');
        self::add_check($checks, 'Team directory exists', !empty($team_members) || (($settings['include_wp_users'] ?? 'no') === 'yes'), 'Team context is empty or only contains a header row; owner/reviewer suggestions will be weak.');
        self::add_check($checks, 'Review-first policy', ($settings['action_execution_policy'] ?? '') === 'review_first', 'Safe-auto is active; keep it off until suggestions are consistently accurate.', 'warning');
        self::add_check($checks, 'Recent error volume', count($recent_errors) < 8, 'Several recent warnings/errors exist. Inspect logs before enabling auto-actions.', 'warning');
        self::add_check($checks, 'Usage budget available', !class_exists('FBAIA_Usage_Tracker') || !is_wp_error(FBAIA_Usage_Tracker::can_call($settings)), 'AI cost guard is currently blocking new model calls.', 'warning');
        self::add_check($checks, 'Automation playbooks', (($settings['playbooks_enabled'] ?? 'yes') !== 'yes') || count($playbooks) > 0, 'Playbooks are enabled but no automation playbooks are defined; recommendations may miss internal guardrails.', 'warning');

        return [
            'generated_at' => current_time('mysql', true),
            'version' => defined('FBAIA_VERSION') ? FBAIA_VERSION : '',
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'site_timezone' => wp_timezone_string(),
            'fluent_boards_detected' => $adapter->is_available(),
            'action_scheduler' => function_exists('as_enqueue_async_action'),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_overdue_scan' => wp_next_scheduled('fbaia_overdue_scan') ?: 0,
            'next_daily_digest' => wp_next_scheduled('fbaia_daily_digest') ?: 0,
            'next_weekly_intelligence' => wp_next_scheduled('fbaia_weekly_intelligence_digest') ?: 0,
            'settings_summary' => [
                'mode' => $settings['mode'] ?? '',
                'policy' => $settings['action_execution_policy'] ?? '',
                'model' => $settings['model'] ?? '',
                'triggers' => $settings['triggers'] ?? [],
                'knowledge_posts' => $knowledge_count,
                'team_directory_chars' => strlen((string) ($settings['team_directory'] ?? '')),
                'parsed_team_members' => count($team_members),
                'board_profiles_chars' => strlen((string) ($settings['board_profiles'] ?? '')),
                'playbook_count' => count($playbooks),
                'cost_guard_enabled' => $settings['cost_guard_enabled'] ?? 'yes',
            ],
            'usage' => $usage,
            'suggestion_stats' => $suggestion_stats,
            'audit_stats' => $audit_stats,
            'recent_problem_logs' => array_slice($recent_errors, 0, 8),
            'checks' => $checks,
        ];
    }

    private static function add_check(array &$checks, $label, $ok, $message, $severity = 'error')
    {
        $checks[] = [
            'label' => sanitize_text_field($label),
            'ok' => (bool) $ok,
            'severity' => $ok ? 'ok' : sanitize_key($severity),
            'message' => $ok ? 'OK' : sanitize_text_field($message),
        ];
    }

    private static function options_writable()
    {
        $key = 'fbaia_writable_probe';
        $value = 'probe_' . substr(md5(current_time('mysql', true)), 0, 8);
        $ok = update_option($key, $value, false);
        if (get_option($key) !== $value) {
            $ok = false;
        }
        delete_option($key);
        return (bool) $ok;
    }

    private static function has_company_memory(array $settings)
    {
        foreach (['company_profile','company_products','company_goals','company_customers','company_constraints','decision_rules','business_metrics','brand_voice','project_playbooks','operating_principles','definition_of_done','escalation_policy','board_profiles'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}
