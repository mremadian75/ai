<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Insight_Evidence_Validator — Phase 5 deterministic evidence/claim-support check.
 *
 * This is NOT semantic NLI and uses NO LLM (rule 12). Support is established only by
 * conservative, deterministic signals: evidence exists, evidence text contains the
 * insight's key term, evidence is linked to the same trend/source/signal, evidence
 * confidence is above threshold, and evidence is not stale. When support cannot be
 * established deterministically it returns a gap/warning — never fake support.
 */
final class VES_Insight_Evidence_Validator {

    const DEFAULT_MIN_CONFIDENCE = 40.0;
    const DEFAULT_FRESHNESS_DAYS = 60;
    const DEFAULT_MIN_EVIDENCE   = 1;

    /**
     * @param array $insight  canonical insight (title, metadata.normalized_term, status, evidence_ids)
     * @param array $context  ['evidence'=>[rows], 'now'=>ts, 'min_confidence'=>, 'min_evidence'=>,
     *                         'source_ids'=>[], 'signal_ids'=>[], 'key_term'=>'']
     */
    public static function validate_insight(array $insight, array $context = []): array {
        $evidence = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : [];
        $count = max(count($evidence), count($evidence_ids));
        $status = (string) ($insight['status'] ?? 'draft');
        $min_evidence = (int) ($context['min_evidence'] ?? self::DEFAULT_MIN_EVIDENCE);

        $key_term = (string) ($context['key_term'] ?? ($insight['metadata']['normalized_term'] ?? ''));
        if ($key_term === '') { $key_term = self::key_term_from_title((string) ($insight['title'] ?? '')); }

        $warnings = []; $gaps = [];
        $supported = []; $unsupported = [];

        // The primary claim is the insight's finding/title.
        $claim = (string) ($insight['metadata']['finding'] ?? $insight['title'] ?? '');
        if ($claim !== '') {
            $res = self::validate_claim_support($claim, $evidence, array_merge($context, ['key_term' => $key_term]));
            if (!empty($res['supported'])) { $supported[] = ['claim' => $claim, 'evidence_ids' => $res['matched_evidence_ids']]; }
            else { $unsupported[] = ['claim' => $claim, 'reason' => $res['reason']]; $gaps[] = 'claim_unsupported:' . $res['reason']; }
        }

        if ($count === 0) { $gaps[] = 'no_evidence'; }
        $summary = self::summarize_evidence($evidence);
        if ($summary['weak_confidence']) { $warnings[] = 'weak_evidence_confidence'; }
        if ($summary['stale']) { $warnings[] = 'stale_evidence'; }
        foreach (self::get_evidence_gaps($insight, $context) as $g) { if (!in_array($g, $gaps, true)) { $gaps[] = $g; } }

        // Minimum requirements: enough evidence + at least one strong (non-weak) item.
        $minimum_met = ($count >= $min_evidence) && $summary['has_strong'];
        // Reviewed/approved insights MUST meet the minimum (hard).
        if (in_array($status, ['reviewed', 'approved'], true) && !$minimum_met) { $warnings[] = 'promotion_without_minimum_evidence'; }

        return [
            'has_evidence'              => $count > 0,
            'evidence_count'            => $count,
            'supported_claims'          => $supported,
            'unsupported_claims'        => $unsupported,
            'gaps'                      => array_values(array_unique($gaps)),
            'minimum_requirements_met'  => (bool) $minimum_met,
            'warnings'                  => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Deterministic claim support. @return array{supported:bool,matched_evidence_ids:array,reason:string}
     */
    public static function validate_claim_support(string $claim, array $evidence_records, array $opts = []) {
        $key_term = strtolower(trim((string) ($opts['key_term'] ?? self::key_term_from_title($claim))));
        $min_conf = (float) ($opts['min_confidence'] ?? self::DEFAULT_MIN_CONFIDENCE);
        $now = isset($opts['now']) ? (int) $opts['now'] : time();
        $fresh_days = (int) ($opts['freshness_days'] ?? self::DEFAULT_FRESHNESS_DAYS);
        $source_ids = array_map('intval', (array) ($opts['source_ids'] ?? []));
        $signal_ids = array_map('intval', (array) ($opts['signal_ids'] ?? []));

        if (empty($evidence_records)) { return ['supported' => false, 'matched_evidence_ids' => [], 'reason' => 'no_evidence']; }

        $matched = [];
        foreach ($evidence_records as $e) {
            $eid = (int) ($e['id'] ?? 0);
            if ($eid <= 0) { continue; } // a supported claim requires a concrete evidence id
            $has_score = isset($e['confidence_score']) && is_numeric($e['confidence_score']);
            $conf = $has_score ? (float) $e['confidence_score'] : -1.0;
            $credible = $has_score && $conf >= $min_conf; // weak/zero/unscored cannot support via a bare term match
            // Staleness (only if a timestamp exists).
            $ts = 0;
            foreach (['observed_at', 'created_at'] as $col) { if (!empty($e[$col])) { $t = strtotime((string) $e[$col] . ' UTC'); if ($t) { $ts = max($ts, $t); } } }
            if ($ts > 0 && ($now - $ts) / 86400 > $fresh_days) { continue; }

            $text = strtolower((string) ($e['text'] ?? ''));
            $term_match = ($key_term !== '' && strpos($text, $key_term) !== false);
            $link_match = (in_array((int) ($e['source_id'] ?? 0), $source_ids, true) && !empty($source_ids))
                       || (in_array((int) ($e['signal_id'] ?? 0), $signal_ids, true) && !empty($signal_ids));
            // A structural source/signal link supports on its own; a term match must
            // also be backed by credible (>= min) confidence.
            if ($link_match || ($term_match && $credible)) { $matched[] = $eid; }
        }
        if (!empty($matched)) { return ['supported' => true, 'matched_evidence_ids' => array_values(array_filter($matched)), 'reason' => 'matched']; }
        return ['supported' => false, 'matched_evidence_ids' => [], 'reason' => 'no_matching_evidence'];
    }

    /** Aggregate evidence stats; never returns raw evidence text or secrets. */
    public static function summarize_evidence(array $evidence_records): array {
        $n = count($evidence_records);
        $conf_sum = 0.0; $conf_n = 0; $providers = []; $types = []; $latest = 0; $strong = 0;
        foreach ($evidence_records as $e) {
            // 'Strong' = a CONFIRMED, non-weak item: explicit confidence_score >= MIN.
            // An explicit 0 (no support) or a missing score is NOT strong, so has_strong
            // is a real evidence-first gate (deep-debug fix).
            if (isset($e['confidence_score']) && is_numeric($e['confidence_score'])) {
                $conf_sum += (float) $e['confidence_score']; $conf_n++;
                if ((float) $e['confidence_score'] >= self::DEFAULT_MIN_CONFIDENCE) { $strong++; }
            }
            $t = strtolower((string) ($e['evidence_type'] ?? '')); if ($t !== '') { $types[$t] = ($types[$t] ?? 0) + 1; }
            $p = strtolower((string) ($e['metadata']['source_provider'] ?? '')); if ($p !== '') { $providers[$p] = true; }
            foreach (['observed_at', 'created_at'] as $col) { if (!empty($e[$col])) { $ts = strtotime((string) $e[$col] . ' UTC'); if ($ts) { $latest = max($latest, $ts); } } }
        }
        $avg = $conf_n > 0 ? $conf_sum / $conf_n : 0.0;
        return [
            'count'           => $n,
            'avg_confidence'  => round($avg, 2),
            'types'           => $types,
            'providers'       => count($providers),
            'has_strong'      => ($n > 0 && $strong > 0),
            'weak_confidence' => ($conf_n > 0 && $avg < self::DEFAULT_MIN_CONFIDENCE),
            'stale'           => ($latest > 0 && (time() - $latest) / 86400 > self::DEFAULT_FRESHNESS_DAYS),
            'latest_at'       => $latest > 0 ? gmdate('Y-m-d H:i:s', $latest) : '',
        ];
    }

    /** Deterministic gaps for an insight. */
    public static function get_evidence_gaps(array $insight, array $context = []): array {
        $evidence = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $evidence_ids = is_array($insight['evidence_ids'] ?? null) ? $insight['evidence_ids'] : [];
        $count = max(count($evidence), count($evidence_ids));
        $gaps = [];
        if ($count === 0) { $gaps[] = 'no_evidence'; return $gaps; }
        $sum = self::summarize_evidence($evidence);
        if ($sum['weak_confidence']) { $gaps[] = 'low_evidence_confidence'; }
        if ($sum['stale']) { $gaps[] = 'stale_evidence'; }
        $linked_source = false;
        foreach ($evidence as $e) { if ((int) ($e['source_id'] ?? 0) > 0) { $linked_source = true; break; } }
        if (!$linked_source) { $gaps[] = 'evidence_missing_source_link'; }
        return $gaps;
    }

    private static function key_term_from_title(string $title): string {
        // Strip a leading "Trend: " prefix and any "(classification)" suffix, take the core term.
        $t = preg_replace('/^\s*trend:\s*/i', '', $title);
        $t = preg_replace('/\s*\([^)]*\)\s*$/', '', $t);
        return strtolower(trim($t));
    }
}
