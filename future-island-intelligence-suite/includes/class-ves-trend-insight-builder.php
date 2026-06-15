<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Insight_Builder — Phase 5 deterministic trend → action-oriented insight.
 *
 * Template-based ONLY (no LLM). Shapes a structured, evidence-first DRAFT insight
 * from a trend record: finding, evidence summary, business implication, recommended
 * action, confidence, and source/signal/evidence links. Strong insights require
 * evidence; noise/insufficient_data trends never produce a strong insight; output is
 * always status=draft. De-duplicates by trend_record_id unless forced.
 */
final class VES_Trend_Insight_Builder {

    const WEAK_CLASSES = ['noise', 'insufficient_data'];

    /** Classification → [implication, action] templates. */
    private static function recommendation_templates(): array {
        return [
            'emerging'  => ['Interest is rising from a low base — an early-mover window may be opening.', 'Explore and test content/offers around "%s" now; produce 1–2 low-cost experiments and measure pickup.'],
            'sustained' => ['Consistent, durable interest — a reliable demand area worth systematizing.', 'Maintain and invest in "%s": systematize production and double down on what already performs.'],
            'peaking'   => ['Interest rose sharply and is now flattening/declining — the peak window is closing.', 'Capture remaining attention on "%s" immediately and prepare for the decline; avoid new long-lead bets.'],
            'fading'    => ['Interest is in clear decline — diminishing returns ahead.', 'Reduce priority for "%s" or refresh the angle; reallocate effort to rising topics.'],
            'spike'     => ['A short, sharp spike that is not yet a consistent pattern — cause is unclear.', 'Investigate the cause of the "%s" spike (event-driven?) before scaling any spend or production.'],
        ];
    }

    /** @return array{business_implication:string,recommended_action:string} */
    public static function build_recommendation(array $trend_record, array $evidence_records = []): array {
        $class = (string) ($trend_record['classification'] ?? '');
        $term  = (string) ($trend_record['display_term'] ?? $trend_record['normalized_term'] ?? 'this topic');
        $tpl = self::recommendation_templates();
        if (isset($tpl[$class])) {
            return ['business_implication' => $tpl[$class][0], 'recommended_action' => sprintf($tpl[$class][1], $term)];
        }
        return ['business_implication' => 'Signal is too weak or noisy to act on yet.', 'recommended_action' => 'Collect more observations before acting on "' . $term . '".'];
    }

    /**
     * Build the structured insight payload from a trend record (no DB write).
     * @return array|WP_Error
     */
    public static function build_from_trend_record(array $trend_record, array $context = []) {
        $class = (string) ($trend_record['classification'] ?? '');
        $term  = (string) ($trend_record['display_term'] ?? $trend_record['normalized_term'] ?? '');
        if ($term === '') { return new WP_Error('ves_trend_insight_no_term', 'Trend record has no term.'); }
        if (in_array($class, self::WEAK_CLASSES, true)) { return new WP_Error('ves_trend_insight_weak', 'Noise/insufficient trends do not produce a strong insight.'); }

        $evidence_ids = is_array($trend_record['evidence_ids'] ?? null) ? array_map('intval', $trend_record['evidence_ids']) : [];
        $signal_ids   = is_array($trend_record['signal_ids'] ?? null) ? array_map('intval', $trend_record['signal_ids']) : [];
        $source_ids   = is_array($trend_record['source_ids'] ?? null) ? array_map('intval', $trend_record['source_ids']) : [];
        $trend_score  = (float) ($trend_record['trend_score'] ?? 0);
        $confidence   = (float) ($trend_record['confidence_score'] ?? 0);

        $rec = self::build_recommendation($trend_record, is_array($context['evidence'] ?? null) ? $context['evidence'] : []);
        $finding = sprintf('"%s" is classified as %s (trend score %.0f, confidence %.0f) over %d observation buckets.',
            $term, $class, $trend_score, $confidence, (int) ($trend_record['point_count'] ?? 0));
        $evidence_summary = sprintf('%d linked evidence item(s); %d source(s); %d signal(s).', count($evidence_ids), count($source_ids), count($signal_ids));

        return [
            'title'               => 'Trend: ' . substr($term, 0, 180) . ' (' . $class . ')',
            'summary'             => $finding . ' ' . $rec['business_implication'],
            'recommendation'      => $rec['recommended_action'],
            'finding'             => $finding,
            'evidence_summary'    => $evidence_summary,
            'business_implication'=> $rec['business_implication'],
            'recommended_action'  => $rec['recommended_action'],
            'confidence_score'    => $confidence,
            'trend_classification'=> $class,
            'trend_score'         => $trend_score,
            'evidence_ids'        => $evidence_ids,
            'signal_ids'          => $signal_ids,
            'source_ids'          => $source_ids,
            'metadata'            => [
                'trend_record_id'      => (int) ($trend_record['id'] ?? 0),
                'normalized_term'      => (string) ($trend_record['normalized_term'] ?? ''),
                'finding'              => $finding,
                'recommended_action'   => $rec['recommended_action'],
                'business_implication' => $rec['business_implication'],
                'classification'       => $class,
            ],
        ];
    }

    /**
     * Create a DRAFT insight from a trend record id (DB write). Requires evidence and
     * passing score thresholds; de-dupes by trend_record_id unless ['force'=>true].
     * @return int|WP_Error  (returns the existing insight id when a duplicate exists)
     */
    public static function create_draft_insight_from_trend(int $trend_record_id, array $args = []) {
        if (!class_exists('VES_Trend_Record_Store') || !class_exists('VES_Intelligence_Store')) {
            return new WP_Error('ves_trend_insight_unavailable', 'Trend/intelligence store unavailable.');
        }
        $tr = VES_Trend_Record_Store::get_trend_record($trend_record_id);
        if (!is_array($tr) || empty($tr['id'])) { return new WP_Error('ves_trend_insight_not_found', 'Trend record not found.'); }
        $workspace_id = (int) ($tr['workspace_id'] ?? 0);
        if ($workspace_id <= 0) { return new WP_Error('ves_trend_insight_no_workspace', 'Trend record has no workspace.'); }

        $evidence_ids = is_array($tr['evidence_ids'] ?? null) ? array_map('intval', $tr['evidence_ids']) : [];
        // Evidence-first: a strong trend insight requires linked evidence.
        if (empty($evidence_ids) && empty($args['allow_without_evidence'])) {
            return new WP_Error('ves_trend_insight_no_evidence', 'Trend has no evidence; not creating a strong insight.');
        }
        // Score thresholds.
        $min_score = isset($args['min_score']) ? (float) $args['min_score'] : 60.0;
        $min_conf  = isset($args['min_confidence']) ? (float) $args['min_confidence'] : 60.0;
        if ((float) ($tr['trend_score'] ?? 0) < $min_score || (float) ($tr['confidence_score'] ?? 0) < $min_conf) {
            return new WP_Error('ves_trend_insight_below_threshold', 'Trend score/confidence below threshold.');
        }

        // Dedup by trend_record_id.
        if (empty($args['force'])) {
            $existing = VES_Intelligence_Store::find_insight_by_trend_record($workspace_id, (int) $tr['id']);
            if ($existing > 0) { return $existing; }
        }

        $payload = self::build_from_trend_record($tr, $args);
        if (self::is_err($payload)) { return $payload; }

        // Quality scoring → store in metadata (advisory; does not gate draft creation).
        $quality = class_exists('VES_Insight_Quality_Scorer')
            ? VES_Insight_Quality_Scorer::score_insight($payload, ['trend_record' => $tr])
            : [];

        return VES_Intelligence_Store::create_insight([
            'workspace_id'     => $workspace_id,
            'insight_type'     => 'trend',
            'title'            => $payload['title'],
            'summary'          => $payload['summary'],
            'recommendation'   => $payload['recommendation'],
            'confidence_score' => $payload['confidence_score'],
            'status'           => 'draft', // always draft from the builder
            'evidence_ids'     => $payload['evidence_ids'],
            'signal_ids'       => $payload['signal_ids'],
            'metadata'         => array_merge($payload['metadata'], [
                'source_ids'           => $payload['source_ids'],
                'evidence_summary'     => $payload['evidence_summary'],
                'quality_score'        => $quality['quality_score'] ?? null,
                'eligible_for_review'  => $quality['eligible_for_review'] ?? null,
                'built_by'             => 'trend_insight_builder',
            ]),
        ]);
    }

    private static function is_err($thing): bool { return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error); }
}
