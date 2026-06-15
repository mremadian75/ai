<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Confidence — unified confidence vocabulary mapper (additive, read-only).
 *
 * Phase 18 intelligence upgrade. This class derives a single canonical
 * confidence band from the heterogeneous confidence/relevance/evidence fields
 * that existing modules already produce (Brand Audit, Trend, Deep Trend Finder,
 * evidence-ref validation). It is a DERIVATION layer only:
 *
 *   - It never mutates its input (the original fields are returned under
 *     `native` verbatim).
 *   - It never writes to the database, options, or transients.
 *   - It never changes any existing scoring, threshold, schema, prompt, or
 *     report behavior.
 *   - It never RAISES confidence above what the source module already supports;
 *     the evidence-validation overlay can only cap/downgrade, never upgrade.
 *
 * The mapper is intentionally NOT wired into any product output in this phase.
 * It is a standalone, tested library that later phases can consume.
 */
final class VES_Confidence {

    /**
     * Canonical confidence bands, strongest → weakest, with metadata.
     * The numeric anchor is the normalized (0–1) lower bound of the band.
     */
    public static function bands(): array {
        return [
            'validated' => [
                'rank' => 7,
                'min_numeric' => 0.82,
                'definition' => 'Strong, multi-source, evidence-confirmed.',
                'allowed_action' => 'firm_recommendation',
                'client_facing' => true,
            ],
            'supported' => [
                'rank' => 6,
                'min_numeric' => 0.62,
                'definition' => 'Evidence-backed but not fully triangulated.',
                'allowed_action' => 'recommend_with_caveat',
                'client_facing' => true,
            ],
            'directional' => [
                'rank' => 5,
                'min_numeric' => 0.45,
                'definition' => 'Points a direction; partial evidence.',
                'allowed_action' => 'suggest_test',
                'client_facing' => true,
            ],
            'exploratory' => [
                'rank' => 4,
                'min_numeric' => 0.30,
                'definition' => 'Early signal; thin but real.',
                'allowed_action' => 'explore_only',
                'client_facing' => false,
            ],
            'hypothesis' => [
                'rank' => 3,
                'min_numeric' => 0.0,
                'definition' => 'Plausible claim not supported by current-run evidence.',
                'allowed_action' => 'hypothesis_only',
                'client_facing' => false,
            ],
            'insufficient' => [
                'rank' => 2,
                'min_numeric' => 0.0,
                'definition' => 'Not enough data to conclude.',
                'allowed_action' => 'collect_more_data',
                'client_facing' => false,
            ],
            'blocked' => [
                'rank' => 1,
                'min_numeric' => 0.0,
                'definition' => 'Cannot proceed (technical/gated/excluded).',
                'allowed_action' => 'none',
                'client_facing' => false,
            ],
        ];
    }

    /** Ordered band keys, strongest → weakest. */
    private static function order(): array {
        return ['validated', 'supported', 'directional', 'exploratory', 'hypothesis', 'insufficient', 'blocked'];
    }

    private static function is_band(string $band): bool {
        return array_key_exists($band, self::bands());
    }

    /** Numeric rank for a band (higher = stronger). Unknown bands rank lowest. */
    private static function rank(string $band): int {
        $bands = self::bands();
        return (int) ($bands[$band]['rank'] ?? 0);
    }

    /**
     * Return the WEAKER (lower-rank) of two bands. Used to enforce the
     * "overlay only downgrades" and "never raise above source" invariants.
     */
    private static function weaker(string $a, string $b): string {
        if (!self::is_band($a)) { return self::is_band($b) ? $b : 'insufficient'; }
        if (!self::is_band($b)) { return $a; }
        return self::rank($a) <= self::rank($b) ? $a : $b;
    }

    /**
     * Generic entry point. Delegates to the per-module wrapper.
     *
     * @param string $source_module brand_audit|trend|dtf|evidence_validation|generic
     * @param array  $input         the module's existing field bag (never mutated)
     */
    public static function normalize(string $source_module, array $input): array {
        switch ($source_module) {
            case 'brand_audit':         return self::from_brand_audit($input);
            case 'trend':               return self::from_trend($input);
            case 'dtf':                 return self::from_dtf($input);
            case 'evidence_validation': return self::from_evidence_validation($input);
            default:
                return self::build('insufficient', null, 'generic', $input,
                    'Unknown source module; defaulted to the safer lower-confidence band.');
        }
    }

    // ── Brand Audit ─────────────────────────────────────────────────────────
    public static function from_brand_audit(array $input): array {
        $label = isset($input['level']) ? (string) $input['level']
            : (isset($input['confidence_level']) ? (string) $input['confidence_level'] : '');
        $label = strtolower(trim($label));

        $label_map = [
            'validated'      => 'validated',
            'directional'    => 'directional',
            'exploratory'    => 'exploratory',
            'limited'        => 'insufficient',
            'technical_only' => 'blocked',
        ];

        $band = null;
        $numeric = null;
        $reason = '';

        if ($label !== '' && isset($label_map[$label])) {
            $band = $label_map[$label];
            $reason = "Brand Audit gate level '{$label}' → {$band}.";
            if (isset($input['confidence']) && is_numeric($input['confidence'])) {
                $numeric = self::clamp01((float) $input['confidence']);
            } elseif (isset($input['score']) && is_numeric($input['score'])) {
                // Gate score is 0–100; normalize for display only (does not change band).
                $numeric = self::clamp01(((float) $input['score']) / 100.0);
            }
        } elseif (isset($input['confidence']) && is_numeric($input['confidence'])) {
            // Per-item numeric confidence (0–1) without a gate label.
            $numeric = self::clamp01((float) $input['confidence']);
            $band = self::band_from_unit($numeric);
            $reason = "Brand Audit per-item confidence {$numeric} → {$band}.";
        } else {
            $band = 'insufficient';
            $reason = 'Brand Audit input had no recognizable level or numeric confidence; safer lower band chosen.';
        }

        return self::build($band, $numeric, 'brand_audit', $input, $reason);
    }

    // ── Trend ────────────────────────────────────────────────────────────────
    public static function from_trend(array $input): array {
        $mode = strtolower(trim((string) ($input['confidence_mode'] ?? '')));
        $mode_map = [
            'validated'             => 'validated',
            'directional'           => 'directional',
            'exploratory'           => 'exploratory',
            'insufficient_evidence' => 'insufficient',
        ];

        $mode_band = ($mode !== '' && isset($mode_map[$mode])) ? $mode_map[$mode] : null;

        $score_band = null;
        $numeric = null;
        if (isset($input['confidence_score']) && is_numeric($input['confidence_score'])) {
            $score = (float) $input['confidence_score']; // 1–10 scale
            $numeric = self::clamp01($score / 10.0);
            $score_band = self::band_from_ten($score);
        }

        if ($mode_band !== null && $score_band !== null) {
            // Score must NOT raise above a weaker mode (e.g. exploratory + 9 stays exploratory).
            $band = self::weaker($mode_band, $score_band);
            $reason = "Trend mode '{$mode}' (→{$mode_band}) capped against score band {$score_band} → {$band}.";
        } elseif ($mode_band !== null) {
            $band = $mode_band;
            $reason = "Trend confidence_mode '{$mode}' → {$band}.";
        } elseif ($score_band !== null) {
            $band = $score_band;
            $reason = "Trend confidence_score → {$band}.";
        } else {
            $band = 'insufficient';
            $reason = 'Trend input had no mode or score; safer lower band chosen.';
        }

        return self::build($band, $numeric, 'trend', $input, $reason);
    }

    // ── Deep Trend Finder ─────────────────────────────────────────────────────
    public static function from_dtf(array $input): array {
        $evidence_tier   = strtolower(trim((string) ($input['evidence_tier'] ?? '')));
        $recommended_use = strtolower(trim((string) ($input['recommended_use'] ?? '')));
        $marketing_use   = strtolower(trim((string) ($input['marketing_allowed_use'] ?? '')));
        $client_safe     = !empty($input['client_safe']);
        $is_relevant     = array_key_exists('is_relevant', $input) ? (bool) $input['is_relevant'] : null;
        // Raw score is intentionally NOT used to grant supported/validated.
        $numeric = (isset($input['score']) && is_numeric($input['score']))
            ? self::clamp01(((float) $input['score']) / 100.0) : null;

        $band = 'insufficient';
        $reason = '';

        if ($marketing_use === 'excluded') {
            $band = 'blocked';
            $reason = 'DTF marketing_allowed_use=excluded → blocked.';
        } elseif ($recommended_use === 'hide' || $evidence_tier === 'noise') {
            $band = 'insufficient';
            $reason = 'DTF recommended_use=hide / evidence_tier=noise → insufficient.';
        } elseif ($evidence_tier === 'strong' && $client_safe) {
            $band = 'validated';
            $reason = 'DTF evidence_tier=strong + client_safe → validated.';
        } elseif ($evidence_tier === 'support' && $client_safe) {
            $band = 'supported';
            $reason = 'DTF evidence_tier=support + client_safe → supported.';
        } elseif ($marketing_use === 'directional_support') {
            $band = 'directional';
            $reason = 'DTF marketing_allowed_use=directional_support → directional.';
        } elseif ($evidence_tier === 'creative_only' || $recommended_use === 'maybe') {
            $band = 'exploratory';
            $reason = 'DTF evidence_tier=creative_only / recommended_use=maybe → exploratory.';
        } elseif ($evidence_tier === 'weak') {
            // Phase 14A finding: high-engagement generic items can be weak without a core
            // match. Never overstate. Weak + not client_safe → exploratory (or lower).
            $band = $client_safe ? 'exploratory' : 'exploratory';
            $reason = 'DTF evidence_tier=weak → exploratory (not overstated to supported).';
        } else {
            $band = 'insufficient';
            $reason = 'DTF fields did not establish supportable evidence; safer lower band chosen.';
        }

        // is_relevant=false must not yield a client-facing band above insufficient.
        if ($is_relevant === false) {
            $band = self::weaker($band, 'insufficient');
            $reason .= ' is_relevant=false caps to insufficient.';
        }

        // Raw score alone must never produce supported/validated. (Defensive: our
        // branch logic above already guarantees this, but enforce explicitly.)
        if (in_array($band, ['supported', 'validated'], true)
            && $evidence_tier !== 'strong' && $evidence_tier !== 'support') {
            $band = 'directional';
            $reason .= ' Downgraded: raw score alone cannot grant supported/validated.';
        }

        return self::build($band, $numeric, 'dtf', $input, $reason);
    }

    // ── Evidence-ref validation overlay ────────────────────────────────────────
    /**
     * Apply the evidence-ref validation outcome as a cap on a base result.
     *
     * @param array $input evidence-validation fields (status / valid refs / flags)
     * @param array $base  optional base mapper result to downgrade; if empty a
     *                     standalone result is returned.
     */
    public static function from_evidence_validation(array $input, array $base = []): array {
        $status = strtolower(trim((string) ($input['evidence_validation_status'] ?? $input['status'] ?? '')));
        $unsupported_flag = !empty($input['unsupported_by_evidence']);
        $valid_count = null;
        foreach (['evidence_ref_count', 'valid_refs', 'valid_ref_count'] as $k) {
            if (isset($input[$k]) && is_numeric($input[$k])) { $valid_count = (int) $input[$k]; break; }
        }
        if ($valid_count === null && isset($input['evidence_refs']) && is_array($input['evidence_refs'])) {
            $valid_count = count($input['evidence_refs']);
        }

        // Determine the cap band + evidence_status from the validation outcome.
        $cap = null;          // strongest band allowed after overlay
        $evidence_status = 'n/a';
        $reason = '';

        if ($status === 'error' || $status === 'blocked') {
            $cap = 'blocked';
            $evidence_status = 'unsupported';
            $reason = "Evidence validation status '{$status}' → cap blocked.";
        } elseif ($unsupported_flag || $status === 'unsupported' || $valid_count === 0) {
            $cap = 'hypothesis';
            $evidence_status = 'unsupported';
            $reason = 'No valid current-run evidence refs → cap hypothesis.';
        } elseif ($status === 'weak' || $valid_count === 1) {
            $cap = 'directional';
            $evidence_status = 'weak';
            $reason = 'Single valid evidence ref → cap directional.';
        } elseif ($status === 'supported' || ($valid_count !== null && $valid_count >= 2)) {
            $cap = null; // supported evidence imposes no cap
            $evidence_status = 'supported';
            $reason = 'Evidence supported (>=2 valid refs) → no downgrade.';
        } else {
            $cap = null;
            $evidence_status = 'n/a';
            $reason = 'No evidence-validation signal; no overlay applied.';
        }

        if (!empty($base) && isset($base['band'])) {
            $base_band = (string) $base['band'];
            $final = ($cap !== null) ? self::weaker($base_band, $cap) : $base_band;
            $out = $base;
            $out['band'] = $final;
            $out['evidence_status'] = $evidence_status;
            $out['allowed_action'] = self::action_for($final);
            $out['client_facing'] = self::client_facing_for($final);
            $out['numeric'] = self::reconcile_numeric($base['numeric'] ?? null, $final);
            $out['reason'] = trim((string) ($base['reason'] ?? '')) . ' [evidence overlay] ' . $reason;
            // limitations preserved from base; ensure array.
            $out['limitations'] = is_array($base['limitations'] ?? null) ? $base['limitations'] : self::limitations_from($input);
            return $out;
        }

        // Standalone: the cap band IS the result (or insufficient if no signal).
        $band = $cap ?? ($evidence_status === 'supported' ? 'supported' : 'insufficient');
        return self::build($band, null, 'evidence_validation', $input, $reason, $evidence_status);
    }

    // ── Numeric scale helpers ──────────────────────────────────────────────────
    private static function clamp01(float $v): float {
        if ($v < 0) { return 0.0; }
        if ($v > 1) { return 1.0; }
        return round($v, 4);
    }

    /** Map a normalized 0–1 unit confidence to a band (per-item numeric path). */
    private static function band_from_unit(float $n): string {
        if ($n >= 0.82) { return 'validated'; }
        if ($n >= 0.62) { return 'supported'; }
        if ($n >= 0.45) { return 'directional'; }
        if ($n >= 0.30) { return 'exploratory'; }
        if ($n > 0.0)   { return 'hypothesis'; }
        return 'insufficient'; // exactly 0 / null path handled by caller
    }

    /** Map a 1–10 trend score to a band. */
    private static function band_from_ten(float $s): string {
        if ($s >= 8) { return 'supported'; }
        if ($s >= 5) { return 'directional'; }
        if ($s >= 3) { return 'exploratory'; }
        if ($s > 0)  { return 'insufficient'; }
        return 'insufficient';
    }

    /** Keep displayed numeric consistent with a downgraded band (never inflate). */
    private static function reconcile_numeric($numeric, string $band) {
        if ($numeric === null || !is_numeric($numeric)) { return null; }
        $bands = self::bands();
        $ceiling = null;
        // Ceiling = lower bound of the band ABOVE the final band (i.e. cap numeric into band range).
        $order = self::order();
        $idx = array_search($band, $order, true);
        if ($idx !== false && $idx > 0) {
            $stronger = $order[$idx - 1];
            $ceiling = $bands[$stronger]['min_numeric'] ?? null;
        }
        $v = (float) $numeric;
        if ($ceiling !== null && $v >= $ceiling) {
            // numeric implies a stronger band than allowed; pull it just below the ceiling.
            $v = max(0.0, $ceiling - 0.01);
        }
        return self::clamp01($v);
    }

    // ── Output assembly ────────────────────────────────────────────────────────
    private static function action_for(string $band): string {
        $bands = self::bands();
        return (string) ($bands[$band]['allowed_action'] ?? 'none');
    }

    private static function client_facing_for(string $band): bool {
        $bands = self::bands();
        return (bool) ($bands[$band]['client_facing'] ?? false);
    }

    private static function limitations_from(array $input): array {
        foreach (['limitations', 'missing_data', 'blocks', 'data_limitations'] as $k) {
            if (isset($input[$k]) && is_array($input[$k])) {
                return array_values(array_filter(array_map('strval', $input[$k]), static function ($v) { return $v !== ''; }));
            }
        }
        return [];
    }

    /** Build the standard output shape. Input is preserved verbatim under `native`. */
    private static function build(string $band, $numeric, string $source_module, array $input, string $reason, string $evidence_status = 'n/a'): array {
        if (!self::is_band($band)) { $band = 'insufficient'; }
        // Reconcile the displayed numeric so it can never imply a band stronger than the
        // final band (e.g. a weak DTF row scoring 0.99, or an exploratory item carrying a
        // high native confidence). reconcile_numeric() is idempotent — values already
        // within the band's range (including those pre-reconciled by the evidence overlay)
        // pass through unchanged. The raw/native value stays available verbatim under `native`.
        $reconciled = ($numeric === null) ? null : self::reconcile_numeric(self::clamp01((float) $numeric), $band);
        return [
            'band'            => $band,
            'numeric'         => $reconciled,
            'source_module'   => $source_module,
            'native'          => $input,
            'evidence_status' => $evidence_status,
            'allowed_action'  => self::action_for($band),
            'client_facing'   => self::client_facing_for($band),
            'reason'          => $reason,
            'limitations'     => self::limitations_from($input),
        ];
    }
}
