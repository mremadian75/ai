<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('fbaia_settings');
delete_option('fbaia_settings_backup');
delete_option('fbaia_db_version');
delete_option('fbaia_logs');
delete_option('fbaia_suggestions');
delete_option('fbaia_audit_trail');
delete_option('fbaia_usage_stats');
wp_clear_scheduled_hook('fbaia_process_event');
wp_clear_scheduled_hook('fbaia_overdue_scan');
wp_clear_scheduled_hook('fbaia_daily_digest');
wp_clear_scheduled_hook('fbaia_weekly_intelligence_digest');
