<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Evidence_Binder — Phase 8A-C. Reusable, READ-ONLY evidence display for the
 * Signal Report, Brief/Draft Workbenches, and Prompt Package preview. It surfaces
 * evidence ids, available vs missing evidence, confidence, limitations, the evidence
 * policy, and source references — WITHOUT raw scraped blobs, secrets, false
 * confidence, or memory-as-evidence. Pure model + escaped render. PHP 7.4.
 */
final class VES_Evidence_Binder {

    /** Build a structured, sanitized evidence binder model from a record (insight/brief). */
    public static function model($record) {
        $record = is_array($record) ? $record : [];
        $ids = is_array($record['evidence_ids'] ?? null) ? array_values(array_filter(array_map('intval', $record['evidence_ids']))) : [];
        $meta = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        $confidence = '';
        foreach (['confidence', 'confidence_level'] as $k) {
            if (!empty($record[$k]) && is_scalar($record[$k])) { $confidence = (string) $record[$k]; break; }
            if (!empty($meta[$k]) && is_scalar($meta[$k])) { $confidence = (string) $meta[$k]; break; }
        }
        $status = (count($ids) === 0) ? 'missing' : (count($ids) === 1 ? 'limited' : 'strong');
        $limitations = [];
        foreach ((array) ($record['limitations'] ?? ($meta['limitations'] ?? [])) as $l) {
            if (is_string($l) && trim($l) !== '') { $limitations[] = self::redact($l); }
        }
        return [
            'evidence_ids' => $ids,
            'evidence_count' => count($ids),
            'evidence_status' => $status, // strong | limited | missing
            'confidence' => self::redact($confidence, 40),
            'limitations' => $limitations,
            'policy' => ['evidence_required_for_market_claims' => true, 'memory_is_not_evidence' => true],
            'source_refs' => self::source_refs($record),
        ];
    }

    private static function source_refs($record) {
        $out = [];
        foreach (['source_type', 'platform'] as $k) {
            if (!empty($record[$k]) && is_scalar($record[$k])) { $out[$k] = self::redact((string) $record[$k], 60); }
        }
        if (!empty($record['source_id'])) { $out['source_id'] = (int) $record['source_id']; }
        return $out;
    }

    private static function redact($t, $max = 240) {
        $t = (string) $t;
        if (class_exists('VES_Brand_Context_Service') && method_exists('VES_Brand_Context_Service', 'redact_sensitive_text')) { $t = VES_Brand_Context_Service::redact_sensitive_text($t); }
        // Local fallback so secrets are scrubbed even if the brand-context service is absent.
        $t = preg_replace('/\bsk-[A-Za-z0-9_\-]{8,}|\bBearer\s+[A-Za-z0-9._\-]{8,}|\b(api[_\-]?key|token|secret|password)\s*[=:]\s*\S{4,}/i', '[redacted]', $t);
        if (function_exists('wp_strip_all_tags')) { $t = wp_strip_all_tags($t); }
        return (strlen($t) > $max) ? (substr($t, 0, $max - 1) . '…') : $t;
    }

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /** Escaped Evidence Binder HTML. Memory is NEVER displayed here. */
    public static function render_html($record) {
        $m = self::model($record);
        $badge = class_exists('VES_Review_State') ? VES_Review_State::badge($m['evidence_status']) : self::e($m['evidence_status']);
        $h = '<section class="fi-evidence-binder"><h3>' . self::e('Evidence Binder') . '</h3>';
        $h .= '<p class="fi-meta-line">' . self::e('Evidence status') . ': ' . $badge
            . ' · ' . self::e('ids') . ': <strong>' . self::e((string) $m['evidence_count']) . '</strong>'
            . ' · ' . self::e('confidence') . ': <strong>' . self::e($m['confidence'] !== '' ? $m['confidence'] : 'unknown') . '</strong></p>';
        if ($m['evidence_count'] > 0) {
            $h .= '<div class="fi-evidence-ids">';
            foreach ($m['evidence_ids'] as $id) { $h .= '<code>ev#' . self::e((string) (int) $id) . '</code> '; }
            $h .= '</div>';
        } else {
            $h .= '<p class="fi-empty-state">' . self::e('No evidence attached. Market claims require evidence.') . '</p>';
        }
        if (!empty($m['limitations'])) {
            $h .= '<div class="fi-evidence-limits"><strong>' . self::e('Limitations') . ':</strong><ul>';
            foreach ($m['limitations'] as $l) { $h .= '<li>' . self::e($l) . '</li>'; }
            $h .= '</ul></div>';
        }
        $h .= '<p class="fi-memory-policy"><strong>' . self::e('Memory is not evidence.') . '</strong> '
            . self::e('Brand context guides tone and constraints only — never factual market claims.') . '</p>';
        $h .= '</section>';
        return $h;
    }
}
