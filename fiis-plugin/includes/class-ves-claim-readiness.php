<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Claim_Readiness — deterministic, rule-based claim/recommendation readiness.
 *
 * MAJOR UPGRADE SPRINT 2, Part A + Part D. Given an evidence bundle (an array of
 * already-normalized evidence/context records, ideally carrying the Sprint 1
 * evidence-quality metadata), it decides whether a claim is sufficiently
 * supported to be stated, and how strongly. Like VES_Evidence_Quality and
 * VES_Confidence, this is a pure DERIVATION layer:
 *
 *   - It never mutates its input (works on local copies / reads only).
 *   - It never writes to the database, options, or transients.
 *   - It makes NO AI / network call (purely rule-based on present fields).
 *   - It does NOT change relevance_score, confidence_score, thresholds, schema,
 *     prompts, evidence_refs, or report behavior.
 *   - Every field it reads is optional; missing fields become limitations.
 *
 * assess() output contract (always all five keys):
 *   - claim_readiness:         ready | cautious | hypothesis_only | unsupported
 *   - claim_strength_score:    int 0..100
 *   - claim_limitations:       string[] (machine-readable, possibly empty)
 *   - recommended_language:    assertive | cautious | exploratory | avoid
 *   - minimum_required_action: can_state | state_with_limitations
 *                              | frame_as_hypothesis | do_not_state
 *
 * classify_recommendation() output contract (Part D):
 *   - recommendation_support:  evidence_backed | partially_supported
 *                              | speculative | unsupported
 *   - claim_readiness, claim_strength_score, recommendation_limitations
 */
final class VES_Claim_Readiness {

    /** Canonical readiness tiers, strongest -> weakest. */
    public static function tiers(): array {
        return ['ready', 'cautious', 'hypothesis_only', 'unsupported'];
    }

    /** Canonical recommended-language vocabulary. */
    public static function languages(): array {
        return ['assertive', 'cautious', 'exploratory', 'avoid'];
    }

    /** Canonical minimum-required-action vocabulary. */
    public static function actions(): array {
        return ['can_state', 'state_with_limitations', 'frame_as_hypothesis', 'do_not_state'];
    }

    /** Canonical recommendation-support vocabulary (Part D). */
    public static function recommendation_states(): array {
        return ['evidence_backed', 'partially_supported', 'speculative', 'unsupported'];
    }

    /** Canonical machine-readable limitation vocabulary. */
    public static function limitations_vocabulary(): array {
        return [
            'insufficient_evidence',
            'single_source_only',
            'weak_evidence_quality',
            'noisy_evidence_present',
            'metric_only_support',
            'opinion_only_support',
            'missing_evidence_refs',
            'low_source_diversity',
            'stale_or_missing_dates',
            'unsupported_recommendation',
        ];
    }

    /** Readiness tier rank for monotonic cap operations. */
    private static function rank(string $tier): int {
        switch ($tier) {
            case 'ready':           return 3;
            case 'cautious':        return 2;
            case 'hypothesis_only': return 1;
            default:                return 0; // unsupported
        }
    }

    /** Downgrade-only cap: never upgrades a readiness tier. */
    private static function cap(string $tier, string $ceiling): string {
        return self::rank($tier) > self::rank($ceiling) ? $ceiling : $tier;
    }

    /**
     * Assess whether a claim is supported by an evidence bundle.
     *
     * @param mixed $bundle     Array of evidence/context records (non-arrays safe).
     * @param array $claim_meta Optional. Supports 'claim_type' to distinguish
     *                          factual/strategic vs metric/signal vs perception.
     * @return array{claim_readiness:string,claim_strength_score:int,claim_limitations:array,recommended_language:string,minimum_required_action:string}
     */
    public static function assess($bundle, array $claim_meta = []): array {
        $records = self::normalize_bundle($bundle);
        $claim_type = self::claim_type($claim_meta);
        $is_perception_claim = in_array($claim_type, ['perception', 'audience', 'sentiment', 'objection', 'reception'], true);
        $is_metric_claim     = in_array($claim_type, ['metric', 'signal', 'trend', 'engagement'], true);

        // ── Tally evidence by derived quality tier / kind ────────────────────
        $strong = 0; $usable = 0; $weak = 0; $noisy = 0; $insufficient = 0;
        $sources = [];
        $has_refs = false;
        $has_date = false;
        $support_kinds = []; // kinds among supporting (strong/usable/weak) records

        foreach ($records as $record) {
            $quality = self::quality_for($record);
            $tier = $quality['evidence_quality_tier'];
            $kind = $quality['evidence_kind'];

            if ($tier === 'noisy') { $noisy++; continue; }
            if ($tier === 'insufficient') { $insufficient++; continue; }

            if ($tier === 'strong')      { $strong++; }
            elseif ($tier === 'usable')  { $usable++; }
            else                         { $weak++; } // 'weak'

            $support_kinds[$kind] = true;

            $sid = self::source_identity($record);
            if ($sid !== '') { $sources[$sid] = true; }
            if (self::record_has_refs($record)) { $has_refs = true; }
            if (self::record_has_date($record, $quality)) { $has_date = true; }
        }

        $usable_strong   = $strong + $usable;             // genuinely usable evidence
        $total_support   = $strong + $usable + $weak;     // any non-noisy support
        $distinct_sources = count($sources);

        // ── Base readiness decision ──────────────────────────────────────────
        if ($total_support === 0) {
            $readiness = 'unsupported';
        } elseif ($usable_strong === 0) {
            $readiness = 'hypothesis_only';               // only weak evidence
        } elseif ($distinct_sources <= 1) {
            $readiness = 'cautious';                       // single-source caps at cautious
        } else {
            $readiness = ($strong >= 1) ? 'ready' : 'cautious';
        }

        // ── Limitations (honest, machine-readable) ───────────────────────────
        $limitations = [];
        if ($total_support === 0) {
            $limitations[] = 'insufficient_evidence';
        } else {
            if ($usable_strong === 0)        { $limitations[] = 'weak_evidence_quality'; }
            if ($distinct_sources <= 1)      { $limitations[] = 'single_source_only'; $limitations[] = 'low_source_diversity'; }
            if (!$has_refs)                  { $limitations[] = 'missing_evidence_refs'; }
            if (!$has_date)                  { $limitations[] = 'stale_or_missing_dates'; }
        }
        if ($noisy > 0) { $limitations[] = 'noisy_evidence_present'; }

        // ── Caps from noise (downgrade-only) ─────────────────────────────────
        if ($noisy > 0) { $readiness = self::cap($readiness, 'cautious'); }

        // ── Kind-based caps for broad/strategic claims ───────────────────────
        $only_metric  = $total_support > 0 && self::only_kind($support_kinds, ['metric']);
        $only_opinion = $total_support > 0 && self::only_kind($support_kinds, ['opinion']);

        if ($only_metric && !$is_metric_claim) {
            // Metric-only evidence may support a metric/signal claim, but cannot
            // alone carry a broad strategic/factual claim.
            $readiness = self::cap($readiness, 'cautious');
            $limitations[] = 'metric_only_support';
        }
        if ($only_opinion && !$is_perception_claim) {
            // Opinion/social comments may support an audience-perception claim,
            // but cannot alone carry a factual/market claim.
            $readiness = self::cap($readiness, 'hypothesis_only');
            $limitations[] = 'opinion_only_support';
        }

        $score = self::strength_score($strong, $usable, $weak, $noisy, $distinct_sources, $has_refs, $has_date, $readiness);

        return [
            'claim_readiness'         => $readiness,
            'claim_strength_score'    => $score,
            'claim_limitations'       => array_values(array_unique($limitations)),
            'recommended_language'    => self::language_for($readiness),
            'minimum_required_action' => self::action_for($readiness),
        ];
    }

    /**
     * Mark a recommendation's evidence backing (Part D). Reuses assess().
     *
     * @param mixed $bundle
     * @param array $claim_meta
     * @return array{recommendation_support:string,claim_readiness:string,claim_strength_score:int,recommendation_limitations:array}
     */
    public static function classify_recommendation($bundle, array $claim_meta = []): array {
        $assessment = self::assess($bundle, $claim_meta);
        $support = self::recommendation_support_for($assessment['claim_readiness']);
        $limitations = $assessment['claim_limitations'];
        if ($support === 'unsupported') { $limitations[] = 'unsupported_recommendation'; }
        return [
            'recommendation_support'     => $support,
            'claim_readiness'            => $assessment['claim_readiness'],
            'claim_strength_score'       => $assessment['claim_strength_score'],
            'recommendation_limitations' => array_values(array_unique($limitations)),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function normalize_bundle($bundle): array {
        if (!is_array($bundle)) { return []; }
        // Accept either a list of records or a single record map.
        if (self::is_single_record($bundle)) { return [$bundle]; }
        $out = [];
        foreach ($bundle as $item) {
            if (is_array($item)) { $out[] = $item; }
        }
        return $out;
    }

    /** A map with evidence-ish scalar keys is treated as one record, not a list. */
    private static function is_single_record(array $bundle): bool {
        if ($bundle === []) { return false; }
        foreach (array_keys($bundle) as $key) {
            if (!is_string($key)) { return false; } // list-like => a bundle
        }
        return true;
    }

    private static function claim_type(array $claim_meta): string {
        $raw = $claim_meta['claim_type'] ?? ($claim_meta['type'] ?? 'general');
        return strtolower(trim((string) $raw));
    }

    /**
     * Use Sprint 1 evidence-quality metadata when already present on the record;
     * otherwise derive it via VES_Evidence_Quality if available; otherwise treat
     * the record conservatively as insufficient/unknown.
     */
    private static function quality_for(array $record): array {
        $tier = isset($record['evidence_quality_tier']) ? (string) $record['evidence_quality_tier'] : '';
        $kind = isset($record['evidence_kind']) ? (string) $record['evidence_kind'] : '';
        if ($tier !== '' && in_array($tier, ['strong', 'usable', 'weak', 'noisy', 'insufficient'], true)) {
            return [
                'evidence_quality_tier' => $tier,
                'evidence_kind'         => $kind !== '' ? $kind : 'unknown',
                'evidence_limitations'  => isset($record['evidence_limitations']) && is_array($record['evidence_limitations']) ? $record['evidence_limitations'] : [],
            ];
        }
        if (class_exists('VES_Evidence_Quality')) {
            return VES_Evidence_Quality::classify($record);
        }
        return ['evidence_quality_tier' => 'insufficient', 'evidence_kind' => 'unknown', 'evidence_limitations' => []];
    }

    /** Stable per-record source identity for diversity counting. */
    private static function source_identity(array $record): string {
        foreach (['source', 'platform', 'source_type', 'source_family', 'channel'] as $key) {
            if (!empty($record[$key]) && is_scalar($record[$key])) {
                return strtolower(trim((string) $record[$key]));
            }
        }
        // Fall back to the URL host so two links from the same domain are not
        // counted as independent sources.
        foreach (['url', 'link', 'permalink', 'source_url'] as $key) {
            if (!empty($record[$key]) && is_string($record[$key])) {
                $host = function_exists('wp_parse_url')
                    ? wp_parse_url($record[$key], PHP_URL_HOST)
                    : parse_url($record[$key], PHP_URL_HOST);
                if (is_string($host) && $host !== '') { return 'host:' . strtolower($host); }
            }
        }
        return '';
    }

    private static function record_has_refs(array $record): bool {
        $refs = $record['evidence_refs'] ?? null;
        return is_array($refs) && !empty(array_filter($refs, static function ($r) {
            return is_scalar($r) && trim((string) $r) !== '';
        }));
    }

    private static function record_has_date(array $record, array $quality): bool {
        // Raw date field present on the (possibly uncompacted) record.
        foreach (['published_at', 'date', 'created_at', 'createdAt', 'timestamp', 'captured_at'] as $key) {
            if (!empty($record[$key]) && is_scalar($record[$key])) { return true; }
        }
        // Otherwise fall back to Sprint 1 quality limitations: a classifier that
        // ran on full data records 'missing_date' iff no date was present. This
        // lets compacted context records (which drop raw date fields) still be
        // judged correctly.
        $limitations = $quality['evidence_limitations'] ?? null;
        if (is_array($limitations)) {
            return !in_array('missing_date', $limitations, true);
        }
        return false;
    }

    private static function only_kind(array $support_kinds, array $kinds): bool {
        if ($support_kinds === []) { return false; }
        foreach (array_keys($support_kinds) as $present) {
            if (!in_array($present, $kinds, true)) { return false; }
        }
        return true;
    }

    private static function language_for(string $readiness): string {
        switch ($readiness) {
            case 'ready':           return 'assertive';
            case 'cautious':        return 'cautious';
            case 'hypothesis_only': return 'exploratory';
            default:                return 'avoid';
        }
    }

    private static function action_for(string $readiness): string {
        switch ($readiness) {
            case 'ready':           return 'can_state';
            case 'cautious':        return 'state_with_limitations';
            case 'hypothesis_only': return 'frame_as_hypothesis';
            default:                return 'do_not_state';
        }
    }

    private static function recommendation_support_for(string $readiness): string {
        switch ($readiness) {
            case 'ready':           return 'evidence_backed';
            case 'cautious':        return 'partially_supported';
            case 'hypothesis_only': return 'speculative';
            default:                return 'unsupported';
        }
    }

    /**
     * Deterministic 0..100 strength score, then banded so it stays consistent
     * with the readiness tier (strong multi-source => high; unsupported => low).
     */
    private static function strength_score(int $strong, int $usable, int $weak, int $noisy, int $distinct_sources, bool $has_refs, bool $has_date, string $readiness): int {
        $score = 0;
        $score += $strong * 34;
        $score += $usable * 20;
        $score += $weak * 8;
        if ($distinct_sources >= 3)      { $score += 26; }
        elseif ($distinct_sources === 2) { $score += 16; }
        if ($has_refs) { $score += 10; }
        if ($has_date) { $score += 6; }
        if ($noisy > 0) { $score -= 14; }
        if (!$has_refs && ($strong + $usable + $weak) > 0) { $score -= 8; }

        $score = max(0, min(100, $score));

        // Band the score into the readiness tier for monotonic consistency.
        switch ($readiness) {
            case 'ready':           return max(70, min(100, $score));
            case 'cautious':        return max(40, min(75, $score));
            case 'hypothesis_only': return max(10, min(45, $score));
            default:                return min(15, $score); // unsupported
        }
    }
}
