<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Trend_Rebuild_Service — Phase 4B. Recomputes trend records from stored
 * observations: groups by (workspace, normalized_term, observation_type, platform,
 * provider), builds a series, scores/classifies deterministically, and upserts the
 * trend record (preserving evidence/signal/source links). Deterministic, idempotent,
 * no LLM, no external calls. Fails per term, never globally.
 */
final class VES_Trend_Rebuild_Service {

    /** Distinct term groups eligible for rebuild. */
    public static function list_rebuild_terms(array $filters = []): array {
        return class_exists('VES_Trend_Observation_Store') ? VES_Trend_Observation_Store::distinct_terms($filters) : [];
    }

    /**
     * Rebuild one term group. @return array{record_id:int,classification:string,result:string}
     * result ∈ created|updated|insufficient|error
     */
    public static function rebuild_term(array $context): array {
        $out = ['record_id' => 0, 'classification' => '', 'result' => 'error'];
        $ws = (int) ($context['workspace_id'] ?? 0);
        $term = (string) ($context['normalized_term'] ?? '');
        $type = (string) ($context['observation_type'] ?? '');
        $platform = (string) ($context['platform'] ?? '');
        $provider = (string) ($context['provider'] ?? '');
        if ($ws <= 0 || $term === '' || !class_exists('VES_Trend_Series_Builder') || !class_exists('VES_Trend_Scorer') || !class_exists('VES_Trend_Record_Store')) {
            return $out;
        }

        $series_filters = [
            'workspace_id' => $ws, 'normalized_term' => $term, 'observation_type' => $type,
            'platform' => $platform, 'provider' => $provider,
            'granularity' => (string) ($context['granularity'] ?? 'day'),
        ];
        if (!empty($context['from'])) { $series_filters['from'] = (string) $context['from']; }
        if (!empty($context['to'])) { $series_filters['to'] = (string) $context['to']; }

        $series = VES_Trend_Series_Builder::build_series($series_filters);
        $min_points = (int) ($context['min_points'] ?? 0);
        if ($min_points > 0 && count($series['points']) < $min_points) {
            $out['result'] = 'insufficient'; $out['classification'] = 'insufficient_data'; return $out;
        }
        $score = VES_Trend_Scorer::score_series($series);

        $links = VES_Trend_Observation_Store::collect_links([
            'workspace_id' => $ws, 'normalized_term' => $term, 'observation_type' => $type,
            'platform' => $platform, 'provider' => $provider,
        ]);
        $existed = VES_Trend_Record_Store::find_record($ws, $term, $type, $platform, $provider) > 0;

        $rec_id = VES_Trend_Record_Store::create_or_update_trend_record(array_merge($score, [
            'workspace_id' => $ws, 'normalized_term' => $term, 'display_term' => (string) ($context['display_term'] ?? $term),
            'observation_type' => $type, 'platform' => $platform, 'provider' => $provider,
            'point_count' => (int) ($score['metadata']['point_count'] ?? count($series['points'])),
            'first_seen_at' => (string) ($context['first_seen_at'] ?? ''),
            'last_seen_at' => (string) ($context['last_seen_at'] ?? ''),
            'source_ids' => $links['source_ids'], 'signal_ids' => $links['signal_ids'], 'evidence_ids' => $links['evidence_ids'],
            'metadata' => ['rebuilt' => true, 'reasons' => implode(';', (array) ($score['reasons'] ?? []))],
        ]));
        if (self::is_wp_error($rec_id)) { return $out; }
        $out['record_id'] = (int) $rec_id;
        $out['classification'] = (string) $score['classification'];
        $out['result'] = ($score['classification'] === 'insufficient_data') ? 'insufficient' : ($existed ? 'updated' : 'created');
        return $out;
    }

    /**
     * Rebuild all matching term groups. Per-term failure isolation.
     * @return array{terms_seen:int,records_created:int,records_updated:int,insufficient_data:int,errors:int}
     */
    public static function rebuild_trends(array $filters = []): array {
        $summary = ['terms_seen' => 0, 'records_created' => 0, 'records_updated' => 0, 'insufficient_data' => 0, 'errors' => 0];
        foreach (self::list_rebuild_terms($filters) as $group) {
            $summary['terms_seen']++;
            try {
                $ctx = array_merge($group, [
                    'min_points' => (int) ($filters['min_points'] ?? 0),
                    'granularity' => (string) ($filters['granularity'] ?? 'day'),
                    'display_term' => (string) ($group['normalized_term'] ?? ''),
                ]);
                $r = self::rebuild_term($ctx);
                if ($r['result'] === 'created') { $summary['records_created']++; }
                elseif ($r['result'] === 'updated') { $summary['records_updated']++; }
                elseif ($r['result'] === 'insufficient') { $summary['insufficient_data']++; }
                else { $summary['errors']++; }
            } catch (\Throwable $e) {
                $summary['errors']++;
                if (class_exists('VES_Log')) { VES_Log::warn('trend_rebuild', 'term_failed', ['error_code' => 'rebuild_term_failed']); }
            }
        }
        return $summary;
    }

    private static function is_wp_error($thing): bool {
        return function_exists('is_wp_error') ? is_wp_error($thing) : ($thing instanceof WP_Error);
    }
}
