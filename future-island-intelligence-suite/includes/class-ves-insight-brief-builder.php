<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Insight_Brief_Builder — Phase 5 minimal Insight → action Brief.
 *
 * Deterministic, template-based (NO LLM). Converts an evidence-backed insight into a
 * DRAFT brief (objective + key message + constraints + evidence links). Requires the
 * insight to have evidence; de-dupes by source_insight_id unless forced. This is the
 * brief FOUNDATION — full campaign generation is out of scope for this phase.
 */
final class VES_Insight_Brief_Builder {

    /** Build the brief payload from an insight (no DB write). @return array|WP_Error */
    public static function build_brief_from_insight(int $insight_id, array $args = []) {
        if (!class_exists('VES_Intelligence_Store')) { return new WP_Error('ves_brief_unavailable', 'Intelligence store unavailable.'); }
        $insight = VES_Intelligence_Store::get_insight($insight_id);
        if (!is_array($insight) || empty($insight['id'])) { return new WP_Error('ves_brief_insight_not_found', 'Insight not found.'); }
        $workspace_id = (int) ($insight['workspace_id'] ?? 0);
        if ($workspace_id <= 0) { return new WP_Error('ves_brief_no_workspace', 'Insight has no workspace.'); }

        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? array_map('intval', $insight['evidence_ids']) : [];
        if (empty($evidence_ids) && empty($args['allow_without_evidence'])) {
            return new WP_Error('ves_brief_no_evidence', 'Cannot build a brief from an unsupported (evidence-less) insight.');
        }

        $meta = is_array($insight['metadata'] ?? null) ? $insight['metadata'] : [];
        $action = (string) ($insight['recommendation'] ?? ($meta['recommended_action'] ?? ''));
        $finding = (string) ($meta['finding'] ?? $insight['summary'] ?? '');
        $term = (string) ($meta['normalized_term'] ?? '');
        $classification = (string) ($meta['classification'] ?? '');
        $run_id = max(0, (int) ($args['run_id'] ?? ($insight['run_id'] ?? ($meta['run_id'] ?? 0))));
        $channel = (string) ($args['channel'] ?? 'generic');
        $format = (string) ($args['format'] ?? 'campaign_angle');
        $audience = (string) ($args['audience'] ?? ($args['target_audience'] ?? ''));
        $angle = (string) ($args['angle'] ?? ($meta['message_angle'] ?? ($insight['title'] ?? '')));
        $objective = $action !== '' ? $action : ('Act on the finding for "' . $term . '".');
        $key_message = $finding !== '' ? substr($finding, 0, 1000) : ('Evidence-backed finding for "' . $term . '".');
        $key_points = array_values(array_filter([
            substr((string) ($insight['title'] ?? ''), 0, 180),
            $key_message,
            substr($objective, 0, 300),
        ]));
        $constraints = [
            'evidence_required' => true,
            'classification'    => $classification,
            'trend_score'       => $meta['trend_score'] ?? ($insight['confidence_score'] ?? null),
        ];
        $decision_context = [
            'origin_type' => 'insight',
            'origin_id' => (int) $insight['id'],
            'run_id' => $run_id,
            'evidence_basis' => empty($evidence_ids) ? 'manual_or_assumption' : 'evidence_backed',
        ];

        $payload = [
            'workspace_id'  => $workspace_id,
            'run_id'        => $run_id,
            'insight_id'    => (int) $insight['id'],
            'origin_type'   => 'insight',
            'origin_id'     => (int) $insight['id'],
            'brief_type'    => (string) ($args['brief_type'] ?? 'content'),
            'channel'       => $channel,
            'format'        => $format,
            'title'         => 'Brief: ' . substr((string) ($insight['title'] ?? 'Insight'), 0, 180),
            'objective'     => $objective,
            'audience'      => $audience,
            'target_audience' => $audience,
            'angle'         => $angle,
            'key_message'   => $key_message,
            'key_points'    => $key_points,
            'constraints'   => $constraints,
            'source_evidence' => $evidence_ids,
            'decision_context' => $decision_context,
            'prompt_preset_key' => (string) ($args['prompt_preset_key'] ?? 'future_island_intake_brief_v1'),
            'version'       => 1,
            'evidence_ids'  => $evidence_ids,
            'status'        => 'draft',
            'metadata'      => [
                'source_insight_id'  => (int) $insight['id'],
                'origin_type'        => 'insight',
                'origin_id'          => (int) $insight['id'],
                'run_id'             => $run_id,
                'channel'            => $channel,
                'format'             => $format,
                'target_audience'    => $audience,
                'angle'              => $angle,
                'key_points_json'    => $key_points,
                'constraints_json'   => $constraints,
                'source_evidence_json' => $evidence_ids,
                'decision_context_json' => $decision_context,
                'prompt_preset_key'  => (string) ($args['prompt_preset_key'] ?? 'future_island_intake_brief_v1'),
                'version'            => 1,
                'manual_assumption_based' => empty($evidence_ids),
                'quality_score'      => $meta['quality_score'] ?? null,
                'trend_record_id'    => (int) ($meta['trend_record_id'] ?? 0),
                'recommended_action' => $action,
                'built_by'           => 'insight_brief_builder',
            ],
        ];

        if (class_exists('VES_Generation_Context_Resolver')) {
            $ctx = VES_Generation_Context_Resolver::resolve([
                'workspace_id' => $workspace_id, 'use_case' => 'brief_generation',
                'audience' => $audience,
            ]);
            if (!empty($ctx['applied'])) {
                $payload['metadata']['brand_context'] = VES_Generation_Context_Resolver::section_from_items($ctx['context_package']['items']);
            }
        }

        return $payload;
    }

    /** Create a DRAFT brief from an insight. De-dupes by source_insight_id unless ['force'=>true]. @return int|WP_Error */
    public static function create_brief_from_insight(int $insight_id, array $args = []) {
        $payload = self::build_brief_from_insight($insight_id, $args);
        if (self::is_err($payload)) { return $payload; }
        $workspace_id = (int) $payload['workspace_id'];

        if (empty($args['force'])) {
            $existing = VES_Intelligence_Store::find_brief_by_insight($workspace_id, $insight_id);
            if ($existing > 0) { return $existing; }
        }
        return VES_Intelligence_Store::create_brief($payload);
    }


    /** Build a DRAFT manual/assumption-based brief payload without requiring an Insight. @return array|WP_Error */
    public static function build_manual_brief(array $args = []) {
        $workspace_id = max(0, (int) ($args['workspace_id'] ?? 0));
        if ($workspace_id <= 0) { return new WP_Error('ves_brief_no_workspace', 'workspace_id is required.'); }
        $title = self::clean_text((string) ($args['title'] ?? 'Manual brief'), 255);
        $objective = trim((string) ($args['objective'] ?? ''));
        if ($objective === '') { return new WP_Error('ves_brief_missing_objective', 'Manual briefs require an objective.'); }
        $run_id = max(0, (int) ($args['run_id'] ?? 0));
        $channel = self::clean_text((string) ($args['channel'] ?? 'generic'), 80);
        $format = self::clean_text((string) ($args['format'] ?? 'campaign_angle'), 80);
        $audience = self::clean_text((string) ($args['target_audience'] ?? ($args['audience'] ?? '')), 255);
        $angle = self::clean_text((string) ($args['angle'] ?? $title), 255);
        $key_points = is_array($args['key_points'] ?? null) ? array_values($args['key_points']) : [];
        $constraints = is_array($args['constraints'] ?? null) ? $args['constraints'] : [];
        $source_evidence = is_array($args['source_evidence'] ?? null) ? $args['source_evidence'] : [];
        $decision_context = is_array($args['decision_context'] ?? null) ? $args['decision_context'] : [];
        $decision_context = array_merge([
            'origin_type' => 'manual',
            'origin_id' => max(0, (int) ($args['origin_id'] ?? 0)),
            'run_id' => $run_id,
            'evidence_basis' => empty($source_evidence) ? 'manual_assumption' : 'manual_with_evidence_refs',
            'assumption_label' => empty($source_evidence) ? 'manual/assumption-based brief' : 'manual brief with evidence refs',
        ], $decision_context);
        return [
            'workspace_id' => $workspace_id,
            'run_id' => $run_id,
            'insight_id' => 0,
            'origin_type' => 'manual',
            'origin_id' => max(0, (int) ($args['origin_id'] ?? 0)),
            'brief_type' => (string) ($args['brief_type'] ?? 'content'),
            'channel' => $channel,
            'format' => $format,
            'title' => $title,
            'objective' => $objective,
            'audience' => $audience,
            'target_audience' => $audience,
            'angle' => $angle,
            'key_message' => (string) ($args['key_message'] ?? $objective),
            'key_points' => $key_points,
            'constraints' => $constraints,
            'source_evidence' => $source_evidence,
            'decision_context' => $decision_context,
            'prompt_preset_key' => (string) ($args['prompt_preset_key'] ?? 'future_island_manual_brief_v1'),
            'version' => max(1, (int) ($args['version'] ?? 1)),
            'evidence_ids' => array_values(array_filter(array_map('intval', (array) ($args['evidence_ids'] ?? [])))),
            'status' => 'draft',
            'metadata' => [
                'origin_type' => 'manual',
                'origin_id' => max(0, (int) ($args['origin_id'] ?? 0)),
                'run_id' => $run_id,
                'channel' => $channel,
                'format' => $format,
                'target_audience' => $audience,
                'angle' => $angle,
                'manual_assumption_based' => empty($source_evidence),
                'assumption_label' => empty($source_evidence) ? 'manual/assumption-based brief' : 'manual brief with evidence refs',
                'built_by' => 'manual_brief_builder',
            ],
        ];
    }

    /** Create a DRAFT manual brief. @return int|WP_Error */
    public static function create_manual_brief(array $args = []) {
        if (!class_exists('VES_Intelligence_Store')) { return new WP_Error('ves_brief_unavailable', 'Intelligence store unavailable.'); }
        $payload = self::build_manual_brief($args);
        if (self::is_err($payload)) { return $payload; }
        return VES_Intelligence_Store::create_brief($payload);
    }

    private static function clean_text(string $value, int $max): string {
        $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($value)));
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
}
