<?php
if (!defined('ABSPATH')) { exit; }

/**
 * VES_Workbench — Phase 8A. Human-reviewed Brief & Draft workbenches. READ-ONLY
 * inspection + dry-run package preview + Evidence Binder + DISABLED review controls
 * (no safe backend transition handler exists yet, so controls render disabled with
 * an honest reason). NO AI generation button, no Generate/Publish/Auto-approve. All
 * output escaped. PHP 7.4 compatible.
 */
final class VES_Workbench {

    private static function e($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s, ENT_QUOTES); }

    /** Output guidance slots — placeholders only, never AI-generated claims. */
    const DRAFT_SLOTS = ['hook', 'body', 'cta', 'claims_to_avoid', 'terms_to_use', 'evidence_notes'];

    public static function render_brief($args = []) {
        $ws = max(0, (int) ($args['workspace_id'] ?? 0));
        $insight_id = (int) ($args['insight_id'] ?? 0);
        $h = '<div class="fi-workbench fi-brief-workbench ves-wrap">';
        $h .= '<div class="fi-breadcrumb">' . self::e('Future Island · Workbench · Brief') . '</div>';
        $h .= '<h1>' . self::e('Brief Workbench') . '</h1>';
        $h .= '<p class="fiis-sr-sub">' . self::e('Inspect an approved Insight and review its Brief. Read-only. Evidence first; no AI generation here.') . '</p>';

        if ($insight_id <= 0) { return $h . self::target_prompt('insight_id') . '</div>'; }
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'get_insight')) {
            return $h . self::error('Intelligence store unavailable.') . '</div>';
        }
        $insight = VES_Intelligence_Store::get_insight($insight_id);
        if (!is_array($insight) || empty($insight['id'])) { return $h . self::error('Insight not found.') . '</div>'; }
        if ($ws > 0 && class_exists('VES_Workspace_Guard') && VES_Workspace_Guard::assert_object_in_workspace('insight', $insight, $ws) !== true) { return $h . self::error('Insight is outside this workspace.') . '</div>'; }
        if ($ws > 0 && !class_exists('VES_Workspace_Guard') && (int) ($insight['workspace_id'] ?? 0) !== $ws) { return $h . self::error('Insight is outside this workspace.') . '</div>'; }

        // 1. Target summary
        $status = (string) ($insight['status'] ?? 'unknown');
        $h .= '<section class="fi-readiness-panel"><h3>' . self::e('Target') . '</h3>';
        $h .= '<p class="fi-meta-line">' . self::e('insight') . ' <code>#' . self::e((string) (int) $insight['id']) . '</code> · '
            . self::e('status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($status) : self::e($status)) . '</p>';
        $h .= '<p>' . self::e(self::clip((string) ($insight['title'] ?? ($insight['summary'] ?? '')), 240)) . '</p></section>';

        // 2. Evidence Binder
        if (class_exists('VES_Evidence_Binder')) { $h .= VES_Evidence_Binder::render_html($insight); }

        // 3. Brief preview (deterministic builder, no AI)
        $h .= '<section class="fi-readiness-panel"><h3>' . self::e('Brief preview') . '</h3>';
        if (class_exists('VES_Insight_Brief_Builder') && method_exists('VES_Insight_Brief_Builder', 'build_brief_from_insight')) {
            $payload = VES_Insight_Brief_Builder::build_brief_from_insight($insight_id);
            if (function_exists('is_wp_error') && is_wp_error($payload)) {
                $h .= '<p class="fi-empty-state">' . (class_exists('VES_Review_State') ? VES_Review_State::badge('blocked') : '') . ' '
                    . self::e($payload->get_error_code() === 'ves_brief_no_evidence' ? 'Blocked: this insight has no evidence. A brief requires evidence.' : 'Brief cannot be built for this insight yet.') . '</p>';
            } elseif (is_array($payload)) {
                $h .= '<p class="fi-meta-line">' . self::e('objective') . ': ' . self::e(self::clip((string) ($payload['objective'] ?? ''), 200)) . '</p>';
                $h .= '<p class="fi-meta-line">' . self::e('key message') . ': ' . self::e(self::clip((string) ($payload['key_message'] ?? ''), 240)) . '</p>';
            }
        } else {
            $h .= '<p class="fi-empty-state">' . self::e('Brief builder unavailable.') . '</p>';
        }
        $h .= '</section>';

        // 4. Prompt Package Preview (6C, no AI)
        $h .= self::package_preview_section($ws, 'brief_generation', 'insight', $insight_id);

        // 5. Disabled review controls (no safe transition handler exists yet)
        $h .= self::review_rail('brief');
        $h .= self::actions_note();
        $h .= '</div>';
        return $h;
    }

    public static function render_draft($args = []) {
        $ws = max(0, (int) ($args['workspace_id'] ?? 0));
        $brief_id = (int) ($args['brief_id'] ?? 0);
        $h = '<div class="fi-workbench fi-draft-workbench ves-wrap">';
        $h .= '<div class="fi-breadcrumb">' . self::e('Future Island · Workbench · Draft') . '</div>';
        $h .= '<h1>' . self::e('Draft Workbench') . '</h1>';
        $h .= '<p class="fiis-sr-sub">' . self::e('Inspect an approved/ready Brief and review its Draft. Read-only. No AI generation here.') . '</p>';

        if ($brief_id <= 0) { return $h . self::target_prompt('brief_id') . '</div>'; }
        if (!class_exists('VES_Intelligence_Store') || !method_exists('VES_Intelligence_Store', 'get_brief')) {
            return $h . self::error('Intelligence store unavailable.') . '</div>';
        }
        $brief = VES_Intelligence_Store::get_brief($brief_id);
        if (!is_array($brief) || empty($brief['id'])) { return $h . self::error('Brief not found.') . '</div>'; }
        if ($ws > 0 && class_exists('VES_Workspace_Guard') && VES_Workspace_Guard::assert_object_in_workspace('brief', $brief, $ws) !== true) { return $h . self::error('Brief is outside this workspace.') . '</div>'; }
        if ($ws > 0 && !class_exists('VES_Workspace_Guard') && (int) ($brief['workspace_id'] ?? 0) !== $ws) { return $h . self::error('Brief is outside this workspace.') . '</div>'; }

        $status = strtolower((string) ($brief['status'] ?? 'draft'));
        $ready = in_array($status, ['approved', 'ready', 'reviewed'], true);

        // 1. Brief summary
        $h .= '<section class="fi-readiness-panel"><h3>' . self::e('Brief') . '</h3>';
        $h .= '<p class="fi-meta-line">' . self::e('brief') . ' <code>#' . self::e((string) (int) $brief['id']) . '</code> · '
            . self::e('status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($status) : self::e($status)) . '</p>';
        $h .= '<p>' . self::e(self::clip((string) ($brief['objective'] ?? ($brief['summary'] ?? '')), 240)) . '</p></section>';

        // 2. Draft readiness — non-approved brief BLOCKS draft
        $h .= '<section class="fi-readiness-panel"><h3>' . self::e('Draft readiness') . '</h3>';
        if (!$ready) {
            $h .= '<p>' . (class_exists('VES_Review_State') ? VES_Review_State::badge('blocked') : '') . ' '
                . self::e('Blocked: a draft requires an approved/ready brief. Approve the brief first.') . '</p>';
        } else {
            $h .= '<p>' . (class_exists('VES_Review_State') ? VES_Review_State::badge('ready') : '') . ' ' . self::e('Brief is approved/ready.') . '</p>';
        }
        $h .= '</section>';

        // 3. Evidence Binder (from the brief)
        if (class_exists('VES_Evidence_Binder')) { $h .= VES_Evidence_Binder::render_html($brief); }

        // 4. Output format guidance — placeholder slots only
        $h .= '<section class="fi-readiness-panel"><h3>' . self::e('Output slots (guidance — not generated)') . '</h3><ul class="fi-output-slots">';
        foreach (self::DRAFT_SLOTS as $slot) { $h .= '<li><code>' . self::e($slot) . '</code> <span class="fi-empty-state">' . self::e('—') . '</span></li>'; }
        $h .= '</ul></section>';

        // 5. Prompt Package Preview
        $h .= self::package_preview_section($ws, 'draft_generation', 'brief', $brief_id);

        // 6. Disabled review controls
        $h .= self::review_rail('draft');
        $h .= self::actions_note();
        $h .= '</div>';
        return $h;
    }

    private static function package_preview_section($ws, $use_case, $target_type, $target_id) {
        if (!class_exists('VES_Generation_Prompt_Package_Builder')) { return ''; }
        $pkg = VES_Generation_Prompt_Package_Builder::build(['workspace_id' => (int) $ws, 'use_case' => $use_case, 'target_type' => $target_type, 'target_id' => (int) $target_id]);
        $h = '<section class="fi-package-preview"><h3>' . self::e('Prompt Package Preview (dry-run)') . '</h3>';
        $h .= '<p class="fi-meta-line">' . self::e('build status') . ' ' . (class_exists('VES_Review_State') ? VES_Review_State::badge($pkg['build_status']) : self::e($pkg['build_status']))
            . ' · ' . self::e('blocking reason') . ': <code>' . self::e((string) $pkg['blocking_reason']) . '</code>'
            . ' · ' . self::e('contract') . ': <code>' . self::e((string) ($pkg['output_contract']['schema_key'] ?? '')) . '</code></p>';
        $h .= '<p class="fi-meta-line">' . self::e(sprintf('brand_context applied=%s reason=%s · provider_execution_allowed=%s',
            $pkg['brand_context']['applied'] ? 'yes' : 'no', (string) $pkg['brand_context']['reason'], $pkg['safety']['provider_execution_allowed'] ? 'yes' : 'no')) . '</p>';
        $h .= '<p class="fi-memory-policy"><strong>' . self::e('Memory is not evidence. No provider call. Human review required.') . '</strong></p>';
        $h .= '</section>';
        return $h;
    }

    /** Disabled review controls — honest reason; safe handlers do not exist yet. */
    private static function review_rail($object) {
        $reason = self::e('No reviewed-' . $object . ' transition handler is wired yet. Controls are disabled until a safe, nonce-protected handler exists.');
        $btns = ['Approve ' . $object, 'Reject ' . $object, 'Mark needs revision'];
        $h = '<section class="fi-review-rail"><h3>' . self::e('Review controls') . '</h3>';
        foreach ($btns as $b) {
            $h .= '<button type="button" class="button" disabled aria-disabled="true" title="' . $reason . '">' . self::e($b) . '</button> ';
        }
        $h .= '<p class="fi-empty-state">' . $reason . '</p></section>';
        return $h;
    }

    private static function actions_note() {
        return '<p class="fi-memory-policy"><strong>' . self::e('No AI generation, no auto-approval, no publishing.') . '</strong> '
            . self::e('Allowed: preview, view package, open evidence, review.') . '</p>';
    }

    private static function target_prompt($param) {
        return '<p class="fi-empty-state">' . self::e('Provide a ' . $param . ' query parameter to load a target. Invalid or missing targets show a safe message — no records are written.') . '</p>';
    }
    private static function error($msg) { return '<div class="notice notice-error inline"><p>' . self::e($msg) . '</p></div>'; }
    private static function clip($s, $max) { $s = (string) $s; return (strlen($s) > $max) ? (substr($s, 0, $max - 1) . '…') : $s; }
}
