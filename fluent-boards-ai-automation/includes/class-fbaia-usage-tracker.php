<?php
if (!defined('ABSPATH')) {
    exit;
}

class FBAIA_Usage_Tracker
{
    const OPTION = 'fbaia_usage_stats';

    public static function can_call(array $settings)
    {
        if (($settings['cost_guard_enabled'] ?? 'yes') !== 'yes') {
            return true;
        }

        $stats = self::snapshot();
        $daily_limit = max(0, (int) ($settings['daily_ai_call_budget'] ?? 60));
        $monthly_token_limit = max(0, (int) ($settings['monthly_token_budget'] ?? 250000));

        if ($daily_limit > 0 && (int) ($stats['today']['calls'] ?? 0) >= $daily_limit) {
            return new WP_Error('fbaia_daily_call_budget_reached', __('Daily AI call budget reached. Increase the budget or wait until tomorrow.', 'fluent-boards-ai-automation'));
        }

        if ($monthly_token_limit > 0 && (int) ($stats['month']['total_tokens'] ?? 0) >= $monthly_token_limit) {
            return new WP_Error('fbaia_monthly_token_budget_reached', __('Monthly AI token budget reached. Increase the budget or wait until next month.', 'fluent-boards-ai-automation'));
        }

        return true;
    }

    public static function record(array $usage = [], array $meta = [])
    {
        $stats = self::raw();
        $today = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        if (!isset($stats['days'][$today]) || !is_array($stats['days'][$today])) {
            $stats['days'][$today] = self::empty_bucket();
        }
        if (!isset($stats['months'][$month]) || !is_array($stats['months'][$month])) {
            $stats['months'][$month] = self::empty_bucket();
        }

        $prompt = absint($usage['prompt_tokens'] ?? 0);
        $completion = absint($usage['completion_tokens'] ?? 0);
        $total = absint($usage['total_tokens'] ?? ($prompt + $completion));
        if (!$total && !empty($meta['estimated_tokens'])) {
            $total = absint($meta['estimated_tokens']);
        }

        foreach (['days' => $today, 'months' => $month] as $scope => $key) {
            $stats[$scope][$key]['calls']++;
            $stats[$scope][$key]['prompt_tokens'] += $prompt;
            $stats[$scope][$key]['completion_tokens'] += $completion;
            $stats[$scope][$key]['total_tokens'] += $total;
            $stats[$scope][$key]['last_call_at'] = current_time('mysql', true);
        }

        $entry = [
            'time' => current_time('mysql', true),
            'event' => sanitize_key($meta['event'] ?? ''),
            'task_id' => absint($meta['task_id'] ?? 0),
            'model' => sanitize_text_field($meta['model'] ?? ''),
            'usage' => FBAIA_Helpers::normalize($usage),
            'estimated_tokens' => absint($meta['estimated_tokens'] ?? 0),
        ];
        array_unshift($stats['recent'], $entry);
        $stats['recent'] = array_slice($stats['recent'], 0, 80);

        self::prune($stats);
        update_option(self::OPTION, $stats, false);
        return $stats;
    }

    public static function snapshot()
    {
        $stats = self::raw();
        $today = gmdate('Y-m-d');
        $month = gmdate('Y-m');
        return [
            'today_key' => $today,
            'month_key' => $month,
            'today' => $stats['days'][$today] ?? self::empty_bucket(),
            'month' => $stats['months'][$month] ?? self::empty_bucket(),
            'recent' => array_slice($stats['recent'] ?? [], 0, 20),
        ];
    }

    public static function reset()
    {
        delete_option(self::OPTION);
    }

    public static function estimate_tokens_from_text($text)
    {
        $text = (string) $text;
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        return max(1, (int) ceil($length / 4));
    }

    private static function raw()
    {
        $stats = get_option(self::OPTION, []);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats = wp_parse_args($stats, [
            'days' => [],
            'months' => [],
            'recent' => [],
        ]);
        if (!is_array($stats['days'])) { $stats['days'] = []; }
        if (!is_array($stats['months'])) { $stats['months'] = []; }
        if (!is_array($stats['recent'])) { $stats['recent'] = []; }
        return $stats;
    }

    private static function empty_bucket()
    {
        return [
            'calls' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'last_call_at' => '',
        ];
    }

    private static function prune(array &$stats)
    {
        if (count($stats['days']) > 45) {
            krsort($stats['days']);
            $stats['days'] = array_slice($stats['days'], 0, 45, true);
        }
        if (count($stats['months']) > 18) {
            krsort($stats['months']);
            $stats['months'] = array_slice($stats['months'], 0, 18, true);
        }
    }
}
