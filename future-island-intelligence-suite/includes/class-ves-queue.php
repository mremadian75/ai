<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Queue {
    private static $registered = false;

    public static function init() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_filter('cron_schedules', [__CLASS__, 'register_schedules']);
    }

    public static function register_schedules($schedules) {
        if (!isset($schedules['ves_five_minutes'])) {
            $schedules['ves_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => 'VE Every 5 Minutes',
            ];
        }
        if (!isset($schedules['ves_fifteen_minutes'])) {
            $schedules['ves_fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => 'VE Every 15 Minutes',
            ];
        }
        return $schedules;
    }

    private static function has_action_scheduler() {
        return function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action');
    }

    public static function enqueue($hook, $args = [], $group = 'ves', $delay = 0) {
        self::init();
        $args = is_array($args) ? $args : [];
        $delay = max(0, (int) $delay);
        if (self::has_action_scheduler()) {
            return (int) as_schedule_single_action(time() + $delay, $hook, [$args], $group);
        }
        wp_schedule_single_event(time() + $delay, $hook, [$args]);
        return time() + $delay;
    }

    public static function enqueue_unique($hook, $args = [], $group = 'ves', $delay = 0) {
        self::init();
        $args = is_array($args) ? $args : [];
        $job_key = !empty($args['_ves_job_key']) ? sanitize_key((string) $args['_ves_job_key']) : md5($hook . '|' . wp_json_encode($args));
        $args['_ves_job_key'] = $job_key;
        if (self::is_scheduled($hook, $args, $group)) {
            return 0;
        }
        return self::enqueue($hook, $args, $group, $delay);
    }

    public static function enqueue_retry($hook, $args, $attempt, $delay) {
        $args = is_array($args) ? $args : [];
        $args['attempt'] = max(1, (int) $attempt);
        return self::enqueue($hook, $args, 'ves', max(0, (int) $delay));
    }

    public static function schedule_recurring($hook, $recurrence, $args = [], $group = 'ves', $start = 0) {
        self::init();
        $args = is_array($args) ? $args : [];
        $start = $start > 0 ? (int) $start : time() + 60;
        if (self::has_action_scheduler()) {
            if (!as_next_scheduled_action($hook, [$args], $group)) {
                $interval = HOUR_IN_SECONDS;
                if ($recurrence === 'ves_five_minutes') {
                    $interval = 5 * MINUTE_IN_SECONDS;
                } elseif ($recurrence === 'ves_fifteen_minutes') {
                    $interval = 15 * MINUTE_IN_SECONDS;
                } elseif ($recurrence === 'daily') {
                    $interval = DAY_IN_SECONDS;
                } elseif ($recurrence === 'twicedaily') {
                    $interval = 12 * HOUR_IN_SECONDS;
                }
                as_schedule_recurring_action($start, $interval, $hook, [$args], $group);
            }
            return;
        }
        if (!wp_next_scheduled($hook, [$args])) {
            wp_schedule_event($start, $recurrence, $hook, [$args]);
        }
    }

    public static function is_scheduled($hook, $args = [], $group = 'ves') {
        self::init();
        $args = is_array($args) ? $args : [];
        if (self::has_action_scheduler()) {
            return (bool) as_next_scheduled_action($hook, [$args], $group);
        }
        return (bool) wp_next_scheduled($hook, [$args]);
    }

    public static function cancel($hook, $args = [], $group = 'ves') {
        self::init();
        $args = is_array($args) ? $args : [];
        if (self::has_action_scheduler()) {
            // v0.9.24.7: guard against infinite loop if unschedule fails silently.
            // The original `if (!$action_id) break` was dead because the while
            // condition guarantees $action_id is truthy.
            $max_iterations = 50;
            while ($max_iterations-- > 0 && ($action_id = as_next_scheduled_action($hook, [$args], $group))) {
                as_unschedule_action($hook, [$args], $group);
            }
            return;
        }
        $timestamp = wp_next_scheduled($hook, [$args]);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook, [$args]);
            $timestamp = wp_next_scheduled($hook, [$args]);
        }
    }
}
