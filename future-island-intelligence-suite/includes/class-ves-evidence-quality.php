<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Evidence_Quality — deterministic, rule-based evidence quality classifier.
 *
 * MAJOR UPGRADE SPRINT 1, Part B. Maps an already-normalized evidence/context
 * item into a small, internal, machine-readable quality metadata object. It is a
 * DERIVATION layer only, in the same spirit as VES_Confidence:
 *
 *   - It never mutates its input.
 *   - It never writes to the database, options, or transients.
 *   - It makes NO AI / network call (purely rule-based on present fields).
 *   - It does NOT change any existing relevance_score, confidence_score,
 *     threshold, schema, prompt, or report behavior.
 *   - It only READS fields that are already produced by the normalizers; every
 *     field is optional and missing fields are reported as limitations.
 *
 * Output contract (always all three keys):
 *   - evidence_quality_tier: strong | usable | weak | noisy | insufficient
 *   - evidence_kind:         fact | signal | metric | opinion | claim | unknown
 *   - evidence_limitations:  string[]  (machine-readable, possibly empty)
 */
final class VES_Evidence_Quality {

    /** Minimum text length (chars) before text counts as a real textual signal. */
    const MIN_TEXT_SIGNAL = 25;

    /** Canonical quality tiers, strongest → weakest. */
    public static function tiers(): array {
        return ['strong', 'usable', 'weak', 'noisy', 'insufficient'];
    }

    /** Canonical evidence kinds. */
    public static function kinds(): array {
        return ['fact', 'signal', 'metric', 'opinion', 'claim', 'unknown'];
    }

    /**
     * Classify a normalized evidence/context item.
     *
     * @param mixed $item Normalized item (array). Non-arrays are handled safely.
     * @return array{evidence_quality_tier:string,evidence_kind:string,evidence_limitations:array}
     */
    public static function classify($item): array {
        if (!is_array($item)) {
            return self::result('insufficient', 'unknown', ['missing_url', 'missing_date', 'missing_author', 'missing_metrics', 'low_text_signal']);
        }

        $url    = self::first_non_empty_string($item, ['url', 'link', 'permalink', 'source_url', 'ves_url']);
        $date   = self::first_non_empty_string($item, ['published_at', 'date', 'created_at', 'createdAt', 'timestamp', 'captured_at']);
        $author = self::first_non_empty_string($item, ['author', 'author_or_channel', 'ownerUsername', 'username', 'creator', 'channel']);
        $text   = self::first_non_empty_string($item, ['text', 'caption', 'caption_or_text', 'summary', 'raw_text', 'title', 'snippet', 'label']);

        $text_len    = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $has_text    = $text !== '';
        $strong_text = $has_text && $text_len >= self::MIN_TEXT_SIGNAL;
        $has_metrics = self::has_positive_metric($item);

        // Explicit signals (only when the source actually carries them).
        $negative_term_noise = self::has_negative_term_noise($item);
        $generic_noise       = self::is_generic_noise($item);
        $source_unverified   = self::is_source_unverified($item);
        $engagement_without_topic = self::is_engagement_without_topic($item, $has_metrics);

        // ── Limitations (honest, machine-readable) ──────────────────────────
        $limitations = [];
        if ($url === '')        { $limitations[] = 'missing_url'; }
        if ($date === '')       { $limitations[] = 'missing_date'; }
        if ($author === '')     { $limitations[] = 'missing_author'; }
        if (!$has_metrics)      { $limitations[] = 'missing_metrics'; }
        if (!$strong_text)      { $limitations[] = 'low_text_signal'; }
        if ($negative_term_noise) { $limitations[] = 'negative_term_noise'; }
        if ($source_unverified) { $limitations[] = 'source_unverified'; }
        if ($engagement_without_topic) { $limitations[] = 'engagement_without_topic'; }

        // ── Kind ────────────────────────────────────────────────────────────
        $kind = self::derive_kind($url, $date, $author, $text, $has_text, $strong_text, $has_metrics);

        // ── Tier ────────────────────────────────────────────────────────────
        $empty_record = !$has_text && !$has_metrics && $url === '' && $date === '' && $author === '';
        if ($empty_record) {
            $tier = 'insufficient';
        } elseif ($negative_term_noise || $generic_noise) {
            $tier = 'noisy';
        } else {
            $completeness = ($url !== '' ? 1 : 0)
                + ($date !== '' ? 1 : 0)
                + ($author !== '' ? 1 : 0)
                + ($strong_text ? 1 : 0)
                + ($has_metrics ? 1 : 0);
            if ($completeness >= 4) {
                $tier = 'strong';
            } elseif ($completeness === 3) {
                $tier = 'usable';
            } elseif ($completeness >= 1) {
                $tier = 'weak';
            } else {
                $tier = 'insufficient';
            }
            // Caps (downgrade-only; never upgrades).
            if ($engagement_without_topic && in_array($tier, ['strong', 'usable'], true)) {
                $tier = 'weak';
            }
            if ($source_unverified && $tier === 'strong') {
                $tier = 'usable';
            }
        }

        return self::result($tier, $kind, $limitations);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private static function result(string $tier, string $kind, array $limitations): array {
        return [
            'evidence_quality_tier' => $tier,
            'evidence_kind'         => $kind,
            'evidence_limitations'  => array_values(array_unique($limitations)),
        ];
    }

    private static function derive_kind($url, $date, $author, $text, $has_text, $strong_text, $has_metrics): string {
        if (!$has_text && !$has_metrics && $url === '') {
            return 'unknown';
        }
        if ($has_text && self::looks_like_opinion($text)) {
            return 'opinion';
        }
        if ($has_metrics && (!$has_text || !$strong_text)) {
            return 'metric';
        }
        if ($url !== '' && $date !== '' && $author !== '' && $has_text) {
            return 'fact';
        }
        if ($has_text) {
            return 'signal';
        }
        if ($has_metrics) {
            return 'metric';
        }
        return 'unknown';
    }

    /** Small, language-tolerant opinion lexicon (substring match on lowercased text). */
    private static function looks_like_opinion(string $text): bool {
        $needles = [
            'opinión', 'opinion', 'reseña', 'resena', 'review', 'i think', 'en mi opinión',
            'me parece', 'recomiendo', 'recommend', 'overrated', 'underrated',
            'no me gust', 'i love', 'i hate', 'best ever', 'worst', 'honest review',
        ];
        $hay = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach ($needles as $needle) {
            if (strpos($hay, $needle) !== false) { return true; }
        }
        return false;
    }

    private static function first_non_empty_string(array $item, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) { continue; }
            $value = $item[$key];
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') { return $trimmed; }
            } elseif (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        return '';
    }

    private static function has_positive_metric(array $item): bool {
        $metrics = $item['metrics'] ?? null;
        if (!is_array($metrics)) { return false; }
        foreach ($metrics as $value) {
            if (is_numeric($value) && (float) $value > 0) { return true; }
        }
        return false;
    }

    private static function has_negative_term_noise(array $item): bool {
        $reason = isset($item['discard_reason']) ? strtolower((string) $item['discard_reason']) : '';
        if (strpos($reason, 'negative_term') === 0) { return true; }
        return !empty($item['negative_term_noise']);
    }

    private static function is_generic_noise(array $item): bool {
        if (isset($item['evidence_type']) && sanitize_key_safe($item['evidence_type']) === 'noise') { return true; }
        if (isset($item['evidence_tier']) && sanitize_key_safe($item['evidence_tier']) === 'noise') { return true; }
        if (isset($item['relevance_tier']) && sanitize_key_safe($item['relevance_tier']) === 'noise') { return true; }
        if (isset($item['recommended_use']) && sanitize_key_safe($item['recommended_use']) === 'hide') { return true; }
        return false;
    }

    private static function is_source_unverified(array $item): bool {
        if (!empty($item['source_unverified'])) { return true; }
        $status = isset($item['source_status']) ? strtolower((string) $item['source_status']) : '';
        if (in_array($status, ['failed', 'timeout', 'timed-out', 'partial'], true)) { return true; }
        $flags = $item['quality_flags'] ?? ($item['quality_flags_json'] ?? null);
        if (is_array($flags) && in_array('source_not_fully_successful', $flags, true)) { return true; }
        return false;
    }

    /**
     * Engagement-without-topic is only asserted when the item explicitly carries
     * a topical-match signal that is empty/false AND it has engagement metrics.
     * This avoids mislabeling high-level records that simply do not carry these
     * keys (e.g. approved insights).
     */
    private static function is_engagement_without_topic(array $item, bool $has_metrics): bool {
        if (!$has_metrics) { return false; }
        if (array_key_exists('matched_core_keywords', $item) && empty($item['matched_core_keywords'])) { return true; }
        if (array_key_exists('is_relevant', $item) && $item['is_relevant'] === false) { return true; }
        return false;
    }
}

if (!function_exists('sanitize_key_safe')) {
    /**
     * Local, dependency-free key normalizer so VES_Evidence_Quality is testable
     * without the full WordPress runtime (mirrors sanitize_key semantics).
     */
    function sanitize_key_safe($value): string {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
    }
}
