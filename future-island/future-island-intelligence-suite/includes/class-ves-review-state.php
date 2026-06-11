<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Review_State — Phase 7B-C. Canonical review/readiness state tokens shared by
 * Signal Room, Signal Report, Memory page, Prompt Package preview, and the Brief/
 * Draft workbenches. Pure (no DB), escaped badge HTML, deterministic. Never invents
 * a backend status — unknown input maps to the explicit "unknown" token.
 */
final class VES_Review_State {

    /** state => [label, class, description, severity]. severity: ok|info|warn|block|muted */
    const STATES = [
        'candidate'    => ['Candidate', 'candidate', 'Proposed; not trusted until approved.', 'info'],
        'needs_review' => ['Needs review', 'needs_review', 'Awaiting human review.', 'warn'],
        'approved'     => ['Approved', 'approved', 'Human-approved.', 'ok'],
        'rejected'     => ['Rejected', 'rejected', 'Rejected in review.', 'muted'],
        'archived'     => ['Archived', 'archived', 'Archived; diagnostic only.', 'muted'],
        'expired'      => ['Expired', 'expired', 'Past expiry; not used.', 'muted'],
        'blocked'      => ['Blocked', 'blocked', 'Cannot proceed.', 'block'],
        'warning'      => ['Warning', 'warning', 'Proceed with caution.', 'warn'],
        'ready'        => ['Ready', 'ready', 'Ready for the next step.', 'ok'],
        'disabled'     => ['Disabled', 'disabled', 'Feature disabled.', 'muted'],
        'missing'      => ['Missing', 'missing', 'Required data missing.', 'block'],
        'recorded'     => ['Recorded', 'recorded', 'Recorded.', 'ok'],
        'unknown'      => ['Unknown', 'disabled', 'Status not available.', 'muted'],
    ];

    public static function normalize($state) {
        $s = is_scalar($state) ? strtolower(trim((string) $state)) : '';
        $alias = ['proposed' => 'needs_review', 'draft' => 'needs_review', 'reviewed' => 'approved', 'active' => 'approved', 'pinned' => 'approved', 'superseded' => 'archived', 'none' => 'unknown', '' => 'unknown'];
        if (isset($alias[$s])) { $s = $alias[$s]; }
        return isset(self::STATES[$s]) ? $s : 'unknown';
    }

    public static function meta($state) {
        $s = self::normalize($state);
        $m = self::STATES[$s];
        return ['state' => $s, 'label' => $m[0], 'class' => 'fi-status-badge fiis-badge-' . $m[1], 'description' => $m[2], 'severity' => $m[3]];
    }

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }
    private static function ea($s) { return function_exists('esc_attr') ? esc_attr((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /** Escaped badge HTML for a review/readiness state. */
    public static function badge($state, $override_label = null) {
        $m = self::meta($state);
        $label = $override_label !== null ? (string) $override_label : $m['label'];
        return '<span class="' . self::ea($m['class']) . '" title="' . self::ea($m['description']) . '">' . self::e($label) . '</span>';
    }
}
