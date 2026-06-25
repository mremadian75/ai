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
delete_option('fbaia_recipes');
delete_option('fbaia_recipe_actions');
delete_option('fbaia_writable_probe');

// NOTE: AI Company Knowledge posts (the fbaia_knowledge CPT) and their topics are
// intentionally NOT deleted here. They are admin-authored content, so we preserve them on
// uninstall to avoid destroying work the user may want to keep or migrate.

wp_clear_scheduled_hook('fbaia_process_event');
wp_clear_scheduled_hook('fbaia_overdue_scan');
wp_clear_scheduled_hook('fbaia_daily_digest');
wp_clear_scheduled_hook('fbaia_weekly_intelligence_digest');
