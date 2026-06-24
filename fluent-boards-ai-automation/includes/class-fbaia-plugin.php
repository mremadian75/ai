<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Plugin
{
    const DB_VERSION_OPTION = 'fbaia_db_version';
    const SETTINGS_BACKUP_OPTION = 'fbaia_settings_backup';

    private static $instance;

    public static function init()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        load_plugin_textdomain('fluent-boards-ai-automation', false, dirname(plugin_basename(FBAIA_FILE)) . '/languages');
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);

        // Handle upgrades applied by replacing plugin files without re-running activation.
        if (is_admin()) {
            add_action('admin_init', [__CLASS__, 'maybe_migrate'], 1);
        }

        $knowledge = new FBAIA_Knowledge_Library();
        $knowledge->register();

        $runner = new FBAIA_Runner();
        $runner->register();

        $rest = new FBAIA_REST_Controller();
        $rest->register();

        if (is_admin()) {
            $admin = new FBAIA_Admin();
            $admin->register();
        }
    }

    public static function cron_schedules($schedules)
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Once Weekly', 'fluent-boards-ai-automation'),
            ];
        }
        return $schedules;
    }

    public static function activate()
    {
        $stored = get_option(FBAIA_OPTION, null);
        $fresh_install = !is_array($stored) || empty($stored);

        if ($fresh_install) {
            // Safe by default: a brand-new install is DISABLED so the plugin never
            // calls the AI provider or mutates a single task until an admin reviews
            // the settings and explicitly turns the engine on. This prevents the
            // "it started doing things the moment I activated it" surprise.
            $current = FBAIA_Helpers::defaults();
            $current['enabled'] = 'no';
            $current['action_execution_policy'] = 'review_first';
            $current['action_update_priority'] = 'no';
            $current['action_create_subtasks'] = 'no';
            update_option(FBAIA_OPTION, $current, false);
            update_option(self::DB_VERSION_OPTION, FBAIA_VERSION, false);
            FBAIA_Logger::add('info', 'Plugin installed in safe mode (engine disabled until configured)', ['version' => FBAIA_VERSION]);
        } else {
            // Existing install: migrate non-destructively. Never overwrite saved settings.
            $current = self::run_migrations($stored);
        }

        self::reschedule_events($current);
    }

    /**
     * Runs on admin_init. Catches upgrades performed by overwriting plugin files
     * (no activation hook fires) and brings the stored data version up to date
     * without ever destroying saved settings.
     */
    public static function maybe_migrate()
    {
        $installed = get_option(self::DB_VERSION_OPTION, '');
        if ($installed === FBAIA_VERSION) {
            return;
        }

        $stored = get_option(FBAIA_OPTION, null);
        if (!is_array($stored) || empty($stored)) {
            // Files dropped in without activation and no settings yet: install safe defaults.
            $current = FBAIA_Helpers::defaults();
            $current['enabled'] = 'no';
            update_option(FBAIA_OPTION, $current, false);
            update_option(self::DB_VERSION_OPTION, FBAIA_VERSION, false);
            return;
        }

        self::run_migrations($stored);
    }

    /**
     * Versioned, non-destructive migration. Backs up the current settings first, then
     * adds any new default keys without overwriting stored values and without deleting
     * unknown keys. Future per-version transforms can branch on $from.
     *
     * @return array The merged, persisted settings.
     */
    public static function run_migrations(array $stored)
    {
        $from = (string) get_option(self::DB_VERSION_OPTION, '0');

        // Back up the existing settings before any structural change so an admin can recover.
        update_option(self::SETTINGS_BACKUP_OPTION, [
            'backed_up_at' => current_time('mysql', true),
            'from_version' => $from,
            'to_version'   => FBAIA_VERSION,
            'settings'     => $stored,
        ], false);

        // Add new keys introduced by upgrades; never clobber or remove existing data.
        $merged = wp_parse_args($stored, FBAIA_Helpers::defaults());

        // Future versioned data transforms go here, e.g.:
        // if (version_compare($from, '1.0.0', '<')) { ... }

        update_option(FBAIA_OPTION, $merged, false);
        update_option(self::DB_VERSION_OPTION, FBAIA_VERSION, false);
        FBAIA_Logger::add('info', 'Plugin settings migrated', ['from' => $from, 'to' => FBAIA_VERSION]);

        return $merged;
    }

    public static function deactivate()
    {
        self::clear_scheduled_events();
        wp_clear_scheduled_hook('fbaia_process_event');
        FBAIA_Logger::add('info', 'Plugin deactivated', ['version' => FBAIA_VERSION]);
    }

    public static function clear_scheduled_events()
    {
        wp_clear_scheduled_hook('fbaia_overdue_scan');
        wp_clear_scheduled_hook('fbaia_daily_digest');
        wp_clear_scheduled_hook('fbaia_weekly_intelligence_digest');
    }

    public static function reschedule_events($settings = null)
    {
        $settings = is_array($settings) ? $settings : FBAIA_Helpers::get_settings();

        wp_clear_scheduled_hook('fbaia_overdue_scan');
        wp_clear_scheduled_hook('fbaia_daily_digest');
        wp_clear_scheduled_hook('fbaia_weekly_intelligence_digest');

        // Honor the master switch / safe mode: when the engine is disabled, schedule no
        // background jobs at all. Otherwise an admin could enable the overdue scan while
        // the engine is globally off and tasks would still be mutated by cron.
        if (($settings['enabled'] ?? 'no') !== 'yes') {
            return;
        }

        if (($settings['overdue_scan_enabled'] ?? 'no') === 'yes') {
            wp_schedule_event(time() + 300, 'hourly', 'fbaia_overdue_scan');
        }

        if (($settings['daily_digest_enabled'] ?? 'no') === 'yes') {
            $hour = max(0, min(23, (int) ($settings['daily_digest_hour'] ?? 9)));
            $timestamp = self::next_local_hour_timestamp($hour);
            wp_schedule_event($timestamp, 'daily', 'fbaia_daily_digest');
        }

        if (($settings['weekly_intelligence_enabled'] ?? 'no') === 'yes') {
            $hour = max(0, min(23, (int) ($settings['weekly_intelligence_hour'] ?? 9)));
            $day = max(1, min(7, (int) ($settings['weekly_intelligence_day'] ?? 1)));
            $timestamp = self::next_local_weekday_hour_timestamp($day, $hour);
            wp_schedule_event($timestamp, 'weekly', 'fbaia_weekly_intelligence_digest');
        }
    }


    private static function next_local_weekday_hour_timestamp($day, $hour)
    {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->setTime((int) $hour, 0, 0);
        $target_day = max(1, min(7, (int) $day));
        while ((int) $next->format('N') !== $target_day || $next <= $now) {
            $next = $next->modify('+1 day');
        }
        return $next->getTimestamp();
    }

    private static function next_local_hour_timestamp($hour)
    {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->setTime((int) $hour, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        return $next->getTimestamp();
    }
}
