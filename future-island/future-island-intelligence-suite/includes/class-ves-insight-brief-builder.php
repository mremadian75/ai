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

        $payload = [
            'workspace_id'  => $workspace_id,
            'brief_type'    => (string) ($args['brief_type'] ?? 'content'),
            'title'         => 'Brief: ' . substr((string) ($insight['title'] ?? 'Insight'), 0, 180),
            'objective'     => $action !== '' ? $action : ('Act on the finding for "' . $term . '".'),
            'audience'      => (string) ($args['audience'] ?? ''),
            'key_message'   => $finding !== '' ? substr($finding, 0, 1000) : ('Evidence-backed finding for "' . $term . '".'),
            'constraints'   => [
                'evidence_required' => true,
                'classification'    => $classification,
                'trend_score'       => $meta['trend_score'] ?? ($insight['confidence_score'] ?? null),
            ],
            'evidence_ids'  => $evidence_ids,
            'status'        => 'draft',
            'metadata'      => [
                'source_insight_id'  => (int) $insight['id'],
                'quality_score'      => $meta['quality_score'] ?? null,
                'trend_record_id'    => (int) ($meta['trend_record_id'] ?? 0),
                'recommended_action' => $action,
                'built_by'           => 'insight_brief_builder',
            ],
        ];

        // Phase 6B-A — DISABLED-BY-DEFAULT trusted Brand Context skeleton. Attach
        // metadata.brand_context ONLY when the resolver actually applies trusted
        // context. Flag off / no trusted context ⇒ payload unchanged. Metadata only:
        // objective/key_message/constraints/evidence_ids never change; memory ≠ evidence.
        if (class_exists('VES_Generation_Context_Resolver')) {
            $ctx = VES_Generation_Context_Resolver::resolve([
                'workspace_id' => $workspace_id, 'use_case' => 'brief_generation',
                'audience' => (string) ($args['audience'] ?? ''),
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

    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
}
