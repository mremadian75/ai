<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Brief_OS_Service — small facade for structured Future Island briefs.
 *
 * This does not replace VES_Insight_Brief_Builder. It adds the missing creation-led
 * path and normalizes Brief OS metadata for new writes while preserving the
 * existing ves_intel_briefs table contract.
 */
final class VES_Brief_OS_Service {

    const ORIGIN_TYPES = ['insight','manual','memory','source','playbook','outcome'];

    /** @return int|WP_Error */
    public static function create_from_insight($insight_id, array $args = []) {
        if (!class_exists('VES_Insight_Brief_Builder')) {
            return self::err('ves_brief_os_builder_missing', 'Insight brief builder unavailable.');
        }
        $brief_id = VES_Insight_Brief_Builder::create_brief_from_insight((int) $insight_id, $args);
        if (self::is_err($brief_id)) { return $brief_id; }
        self::edge_from_insight((int) $insight_id, (int) $brief_id, $args);
        return (int) $brief_id;
    }

    /** Creation-led/manual brief path. @return int|WP_Error */
    public static function create_manual_brief(array $args) {
        if (!class_exists('VES_Intelligence_Store')) {
            return self::err('ves_brief_os_store_missing', 'Intelligence store unavailable.');
        }
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return self::err('ves_brief_os_missing_workspace', 'workspace_id is required.'); }
        $origin_type = self::clean_key((string) ($args['origin_type'] ?? 'manual'), 40);
        if (!in_array($origin_type, self::ORIGIN_TYPES, true)) { $origin_type = 'manual'; }
        $title = self::text((string) ($args['title'] ?? ''), 255);
        $objective = self::long_text((string) ($args['objective'] ?? ''), 5000);
        if ($title === '') { return self::err('ves_brief_os_missing_title', 'Manual brief requires a title.'); }
        if ($objective === '') { return self::err('ves_brief_os_missing_objective', 'Manual brief requires an objective.'); }
        $source_evidence = self::array_value($args['source_evidence'] ?? ($args['evidence_ids'] ?? []));
        $manual_assumption = empty($source_evidence);
        $run_id = max(0, (int) ($args['run_id'] ?? 0));
        $payload = [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'insight_id' => max(0, (int) ($args['insight_id'] ?? 0)),
            'origin_type' => $origin_type,
            'origin_id' => max(0, (int) ($args['origin_id'] ?? 0)),
            'brief_type' => self::clean_key((string) ($args['brief_type'] ?? 'content'), 40) ?: 'content',
            'channel' => self::text((string) ($args['channel'] ?? 'generic'), 80),
            'format' => self::text((string) ($args['format'] ?? 'campaign_angle'), 80),
            'title' => $title,
            'objective' => $objective,
            'audience' => self::text((string) ($args['audience'] ?? ($args['target_audience'] ?? '')), 255),
            'target_audience' => self::text((string) ($args['target_audience'] ?? ($args['audience'] ?? '')), 255),
            'angle' => self::text((string) ($args['angle'] ?? ''), 255),
            'key_message' => self::long_text((string) ($args['key_message'] ?? ''), 5000),
            'key_points' => self::array_value($args['key_points'] ?? []),
            'constraints' => self::array_value($args['constraints'] ?? []),
            'source_evidence' => $source_evidence,
            'decision_context' => array_merge(
                is_array($args['decision_context'] ?? null) ? $args['decision_context'] : [],
                ['origin_type' => $origin_type, 'manual_assumption_based' => $manual_assumption, 'run_id' => $run_id]
            ),
            'prompt_preset_key' => self::clean_key((string) ($args['prompt_preset_key'] ?? 'future_island_manual_brief_v1'), 120),
            'version' => max(1, (int) ($args['version'] ?? 1)),
            'evidence_ids' => array_values(array_filter(array_map('intval', (array) ($args['evidence_ids'] ?? [])))) ,
            'status' => self::clean_key((string) ($args['status'] ?? 'draft'), 24) ?: 'draft',
            'metadata' => array_merge(is_array($args['metadata'] ?? null) ? $args['metadata'] : [], [
                'origin_type' => $origin_type,
                'origin_id' => max(0, (int) ($args['origin_id'] ?? 0)),
                'run_id' => $run_id,
                'manual_assumption_based' => $manual_assumption,
                'assumption_label' => $manual_assumption ? 'manual / assumption-based brief' : 'evidence-linked manual brief',
                'built_by' => 'brief_os_service',
            ]),
        ];
        $brief_id = VES_Intelligence_Store::create_brief($payload);
        if (self::is_err($brief_id)) { return $brief_id; }
        if ($run_id > 0 && class_exists('VES_Decision_Edge_Service')) {
            VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'run', $run_id, 'brief', (int) $brief_id, 'generated', ['metadata' => ['origin_type' => $origin_type]]);
        }
        return (int) $brief_id;
    }

    private static function edge_from_insight($insight_id, $brief_id, array $args): void {
        if (!class_exists('VES_Decision_Edge_Service') || !class_exists('VES_Intelligence_Store')) { return; }
        $insight = VES_Intelligence_Store::get_insight((int) $insight_id);
        if (!is_array($insight)) { return; }
        $workspace_id = (int) ($insight['workspace_id'] ?? 0);
        $run_id = max(0, (int) ($args['run_id'] ?? ($insight['run_id'] ?? 0)));
        if ($workspace_id <= 0) { return; }
        VES_Decision_Edge_Service::add_edge($workspace_id, $run_id, 'insight', (int) $insight_id, 'brief', (int) $brief_id, 'generated', ['metadata' => ['source' => 'brief_os_service']]);
    }

    private static function array_value($value): array { return is_array($value) ? $value : (($value === '' || $value === null) ? [] : [(string) $value]); }
    private static function clean_key(string $s, int $max): string { $s = function_exists('sanitize_key') ? sanitize_key($s) : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $s)); return substr($s, 0, $max); }
    private static function text(string $s, int $max): string { $s = function_exists('sanitize_text_field') ? sanitize_text_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function long_text(string $s, int $max): string { $s = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($s) : trim(strip_tags($s)); return substr($s, 0, $max); }
    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
    private static function err($code, $message) { return class_exists('WP_Error') ? new WP_Error($code, $message) : false; }
}
