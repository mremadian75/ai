<?php
if (!defined('ABSPATH')) { exit; }

final class VES_CLI_Trend_Backfill {
    public static function register() {
        if (!class_exists('WP_CLI')) {
            return;
        }
        WP_CLI::add_command('ves trend-backfill', [__CLASS__, 'handle']);
    }

    public static function handle($args, $assoc_args) {
        if (!class_exists('WP_CLI')) {
            return;
        }
        $sub = $args[0] ?? 'dry-run';
        switch ($sub) {
            case 'dry-run':
                self::dry_run($assoc_args);
                return;
            case 'run':
                self::run($assoc_args);
                return;
            case 'status':
                self::status($assoc_args);
                return;
            case 'resume':
                self::resume($assoc_args);
                return;
            case 'rollback':
                self::rollback($assoc_args);
                return;
            default:
                \WP_CLI::error('Unknown subcommand. Use dry-run, run, status, resume, or rollback.');
        }
    }

    private static function parse_assoc($assoc_args) {
        $parsed = [
            'days' => isset($assoc_args['days']) ? max(1, (int) $assoc_args['days']) : 0,
            'from' => isset($assoc_args['from']) ? sanitize_text_field((string) $assoc_args['from']) : '',
            'to' => isset($assoc_args['to']) ? sanitize_text_field((string) $assoc_args['to']) : '',
            'limit' => isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 0,
            'offset' => isset($assoc_args['offset']) ? max(0, (int) $assoc_args['offset']) : 0,
            'user_id' => isset($assoc_args['user_id']) ? max(0, (int) $assoc_args['user_id']) : 0,
            'session_key' => isset($assoc_args['session_key']) ? sanitize_key((string) $assoc_args['session_key']) : '',
            'batch_size' => isset($assoc_args['batch-size']) ? max(1, min(500, (int) $assoc_args['batch-size'])) : 100,
            'sleep_ms' => isset($assoc_args['sleep-ms']) ? max(0, (int) $assoc_args['sleep-ms']) : 0,
            'format' => isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table',
            'verbose' => isset($assoc_args['verbose']),
            'strict' => isset($assoc_args['strict']),
            'skip_existing' => !isset($assoc_args['skip-existing']) || (string) $assoc_args['skip-existing'] !== '0',
            'default_status' => isset($assoc_args['default-status']) ? sanitize_key((string) $assoc_args['default-status']) : 'archived',
        ];
        if (!empty($assoc_args['memory_ids'])) {
            $parsed['memory_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $assoc_args['memory_ids']))));
        }
        return $parsed;
    }

    private static function output($data, $format = 'table', $fields = []) {
        if ($format === 'json') {
            \WP_CLI::line(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($format === 'csv' && !empty($fields) && is_array($data)) {
            \WP_CLI\Utils\format_items('csv', $data, $fields);
            return;
        }
        if (!empty($fields) && is_array($data)) {
            \WP_CLI\Utils\format_items('table', $data, $fields);
            return;
        }
        \WP_CLI::line(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function dry_run($assoc_args) {
        $args = self::parse_assoc($assoc_args);
        $plan = VES_Trend_Backfill_Runner::build_plan($args);
        \WP_CLI::success('Trend backfill dry-run complete.');
        \WP_CLI::line('Scanned rows: ' . (int) ($plan['summary']['scanned_rows'] ?? 0));
        \WP_CLI::line('Eligible rows: ' . (int) ($plan['summary']['eligible_rows'] ?? 0));
        \WP_CLI::line('Already backfilled: ' . (int) ($plan['summary']['already_backfilled'] ?? 0));
        \WP_CLI::line('Estimated runs to create: ' . (int) ($plan['summary']['estimated_runs_to_create'] ?? 0));
        \WP_CLI::line('Estimated insights to create: ' . (int) ($plan['summary']['estimated_insights_to_create'] ?? 0));
        self::output($plan['samples'] ?? [], $args['format'], ['memory_id','owner','date','run_status','mode','insights','action','errors']);
    }

    public static function run($assoc_args) {
        if (!isset($assoc_args['yes'])) {
            \WP_CLI::confirm('Run historical trend backfill now?');
        }
        $args = self::parse_assoc($assoc_args);
        $result = VES_Trend_Backfill_Runner::run($args);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Trend backfill completed.');
        self::output([$result], $args['format'], ['job_id','status','processed_count','inserted_run_count','inserted_insight_count','skipped_count','error_count','last_processed_memory_id']);
    }

    public static function status($assoc_args) {
        $job_id = sanitize_text_field((string) ($assoc_args['job-id'] ?? ''));
        $state = VES_Trend_Backfill_Runner::status($job_id);
        if (empty($state)) {
            \WP_CLI::warning('No trend backfill status found.');
            return;
        }
        self::output([$state], sanitize_key((string) ($assoc_args['format'] ?? 'table')), ['job_id','status','processed_count','inserted_run_count','inserted_insight_count','skipped_count','error_count','last_processed_memory_id','updated_at']);
    }

    public static function resume($assoc_args) {
        $job_id = sanitize_text_field((string) ($assoc_args['job-id'] ?? ''));
        if ($job_id === '') {
            \WP_CLI::error('Provide --job-id to resume a trend backfill job.');
        }
        if (!isset($assoc_args['yes'])) {
            \WP_CLI::confirm('Resume trend backfill job ' . $job_id . '?');
        }
        $args = self::parse_assoc($assoc_args);
        $result = VES_Trend_Backfill_Runner::run($args, $job_id);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Trend backfill resumed successfully.');
        self::output([$result], sanitize_key((string) ($assoc_args['format'] ?? 'table')), ['job_id','status','processed_count','inserted_run_count','inserted_insight_count','skipped_count','error_count','last_processed_memory_id']);
    }

    public static function rollback($assoc_args) {
        $job_id = sanitize_text_field((string) ($assoc_args['job-id'] ?? ''));
        if ($job_id === '') {
            \WP_CLI::error('Provide --job-id to rollback a trend backfill job.');
        }
        if (!isset($assoc_args['yes'])) {
            \WP_CLI::confirm('Rollback trend backfill job ' . $job_id . '?');
        }
        $result = VES_Trend_Backfill_Runner::rollback($job_id);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        \WP_CLI::success('Trend backfill rollback completed.');
        self::output([$result], sanitize_key((string) ($assoc_args['format'] ?? 'table')), ['job_id','deleted_runs','deleted_insights']);
    }
}
